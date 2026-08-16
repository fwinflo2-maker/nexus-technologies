<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Kyc\CountryCodes;
use Nexus\Kyc\KycRiskScorer;
use Nexus\Kyc\KycStatus;
use Nexus\Kyc\KycSubjectType;
use Nexus\Kyc\SumsubAdapter;
use Nexus\Services\KycService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests KYC/KYB Sumsub (§34).
 *
 * AUCUN appel réel à Sumsub : le transport HTTP est un test double.
 * Aucun secret réel n'est utilisé — uniquement des valeurs de test locales.
 */
final class SumsubKycTest extends TestCase
{
    private PDO $pdo;
    private int $userId;

    /** Secret de test, purement local (jamais une vraie clé Sumsub). */
    private const TEST_WEBHOOK_SECRET = 'test-webhook-secret-local-only';
    private const TEST_APP_TOKEN      = 'test-app-token-local-only';
    private const TEST_SECRET_KEY     = 'test-secret-key-local-only';

    protected function setUp(): void
    {
        putenv('SUMSUB_APP_TOKEN=' . self::TEST_APP_TOKEN);
        putenv('SUMSUB_SECRET_KEY=' . self::TEST_SECRET_KEY);
        putenv('SUMSUB_WEBHOOK_SECRET=' . self::TEST_WEBHOOK_SECRET);
        putenv('SUMSUB_ENVIRONMENT=sandbox');
        putenv('SUMSUB_LEVEL_NAME=test-kyc-level');
        putenv('SUMSUB_LEVEL_NAME_KYB=test-kyb-level');

        $this->pdo = Database::getConnection();
        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'kyc-test-%@nexus.test'");
        $this->pdo->exec("DELETE FROM kyc_webhook_events WHERE provider = 'sumsub'");

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES ('KYC Test', :email, 'x', 'personal', 'ACTIVE', 'none')"
        );
        $stmt->execute(['email' => 'kyc-test-' . bin2hex(random_bytes(4)) . '@nexus.test']);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->userId]);
        $this->pdo->exec("DELETE FROM kyc_webhook_events WHERE provider = 'sumsub'");
        foreach (['SUMSUB_APP_TOKEN','SUMSUB_SECRET_KEY','SUMSUB_WEBHOOK_SECRET',
                  'SUMSUB_ENVIRONMENT','SUMSUB_LEVEL_NAME','SUMSUB_LEVEL_NAME_KYB'] as $k) {
            putenv($k);
        }
    }

    /** Adaptateur avec transport simulé. */
    private function adapter(array $responses): SumsubAdapter
    {
        $calls = 0;
        return new SumsubAdapter(function (string $m, string $u, string $b, array $h) use ($responses, &$calls): array {
            $r = $responses[$calls] ?? end($responses);
            $calls++;
            return $r;
        });
    }

    // ── Applicant & session ────────────────────────────────────────────────

    public function test_create_applicant_retourne_identifiant(): void
    {
        $adapter = $this->adapter([['status' => 201, 'body' => json_encode(['id' => 'appl_123'])]]);

        $id = $adapter->createApplicant('42', KycSubjectType::INDIVIDUAL);

        self::assertSame('appl_123', $id);
    }

    public function test_create_session_retourne_token_court(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => json_encode(['token' => 'tkn_abc'])]]);

        $session = $adapter->createVerificationSession('42', KycSubjectType::INDIVIDUAL);

        self::assertSame('tkn_abc', $session['token']);
        self::assertGreaterThan(0, $session['expires_in']);
    }

    public function test_niveau_kyb_different_du_niveau_kyc(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);

        self::assertSame('test-kyc-level', $adapter->levelName(KycSubjectType::INDIVIDUAL));
        self::assertSame('test-kyb-level', $adapter->levelName(KycSubjectType::COMPANY));
    }

    public function test_niveau_non_configure_leve_une_erreur(): void
    {
        putenv('SUMSUB_LEVEL_NAME');
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);

        $this->expectException(\RuntimeException::class);
        $adapter->levelName(KycSubjectType::INDIVIDUAL);
    }

    public function test_appel_en_echec_leve_une_erreur_sans_secret(): void
    {
        $adapter = $this->adapter([['status' => 401, 'body' => 'unauthorized']]);

        try {
            $adapter->createApplicant('42', KycSubjectType::INDIVIDUAL);
            self::fail('Une erreur était attendue.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString(self::TEST_SECRET_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::TEST_APP_TOKEN, $e->getMessage());
        }
    }

    // ── Mapping de statuts (§34) ───────────────────────────────────────────

    public function test_mapping_kyc_verified(): void
    {
        self::assertSame(
            KycStatus::VERIFIED,
            SumsubAdapter::mapStatus('completed', ['reviewAnswer' => 'GREEN'])
        );
    }

    public function test_mapping_kyc_pending(): void
    {
        self::assertSame(KycStatus::PENDING, SumsubAdapter::mapStatus('pending', null));
        self::assertSame(KycStatus::PENDING, SumsubAdapter::mapStatus('queued', null));
    }

    public function test_mapping_kyc_rejected_final(): void
    {
        self::assertSame(
            KycStatus::REJECTED,
            SumsubAdapter::mapStatus('completed', ['reviewAnswer' => 'RED', 'reviewRejectType' => 'FINAL'])
        );
    }

    public function test_mapping_kyc_resubmission_si_retry(): void
    {
        self::assertSame(
            KycStatus::RESUBMISSION_REQUESTED,
            SumsubAdapter::mapStatus('completed', ['reviewAnswer' => 'RED', 'reviewRejectType' => 'RETRY'])
        );
    }

    public function test_statut_inconnu_jamais_interprete_comme_verifie(): void
    {
        foreach (['', 'weird', 'completed'] as $s) {
            $status = SumsubAdapter::mapStatus($s, null);
            self::assertFalse($status->isVerified(), "Le statut « {$s} » ne doit jamais valoir VERIFIED.");
        }
    }

    // ── Webhooks : signature (§25) ─────────────────────────────────────────

    private function signedHeaders(string $payload, string $secret = self::TEST_WEBHOOK_SECRET): array
    {
        return [
            'x-payload-digest'     => hash_hmac('sha256', $payload, $secret),
            'x-payload-digest-alg' => 'HMAC_SHA256_HEX',
        ];
    }

    public function test_webhook_signature_valide_acceptee(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $payload = json_encode(['applicantId' => 'a1', 'reviewStatus' => 'completed']);

        self::assertTrue($adapter->verifyWebhookSignature($payload, $this->signedHeaders($payload)));
    }

    public function test_webhook_signature_invalide_refusee(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $payload = json_encode(['applicantId' => 'a1']);

        self::assertFalse($adapter->verifyWebhookSignature($payload, [
            'x-payload-digest' => 'deadbeef',
        ]));
    }

    public function test_webhook_signe_avec_mauvais_secret_refuse(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $payload = json_encode(['applicantId' => 'a1']);

        self::assertFalse(
            $adapter->verifyWebhookSignature($payload, $this->signedHeaders($payload, 'mauvais-secret'))
        );
    }

    public function test_webhook_sans_signature_refuse(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);

        self::assertFalse($adapter->verifyWebhookSignature('{}', []));
    }

    public function test_webhook_payload_modifie_refuse(): void
    {
        $adapter  = $this->adapter([['status' => 200, 'body' => '{}']]);
        $original = json_encode(['applicantId' => 'a1', 'reviewStatus' => 'pending']);
        $headers  = $this->signedHeaders($original);

        // Un attaquant tente de forcer un statut vérifié.
        $falsifie = json_encode(['applicantId' => 'a1', 'reviewStatus' => 'completed']);

        self::assertFalse($adapter->verifyWebhookSignature($falsifie, $headers));
    }

    public function test_algorithme_de_digest_inconnu_refuse(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $payload = '{}';

        self::assertFalse($adapter->verifyWebhookSignature($payload, [
            'x-payload-digest'     => hash_hmac('sha256', $payload, self::TEST_WEBHOOK_SECRET),
            'x-payload-digest-alg' => 'ALGO_INVENTE',
        ]));
    }

    public function test_sans_secret_configure_aucun_webhook_accepte(): void
    {
        putenv('SUMSUB_WEBHOOK_SECRET');
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $payload = '{}';

        self::assertFalse($adapter->verifyWebhookSignature($payload, [
            'x-payload-digest' => hash_hmac('sha256', $payload, ''),
        ]));
    }

    // ── Webhooks : idempotence (§24) ───────────────────────────────────────

    public function test_webhook_traite_une_seule_fois_rejeu_ignore(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $this->seedVerification('appl_idem');

        $payload = json_encode([
            'applicantId'    => 'appl_idem',
            'externalUserId' => (string) $this->userId,
            'correlationId'  => 'evt-unique-1',
            'reviewStatus'   => 'completed',
            'reviewResult'   => ['reviewAnswer' => 'GREEN'],
        ]);
        $event = $adapter->parseWebhook($payload);

        $first  = KycService::handleVerifiedWebhook($this->pdo, $event);
        $second = KycService::handleVerifiedWebhook($this->pdo, $event);

        self::assertTrue($first['processed']);
        self::assertFalse($first['duplicate']);
        self::assertFalse($second['processed'], 'Un rejeu ne doit pas être retraité.');
        self::assertTrue($second['duplicate']);
    }

    public function test_meme_event_id_dans_deux_environnements_reste_distinct(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $this->seedVerification('appl_env');

        $payload = json_encode([
            'applicantId'   => 'appl_env',
            'correlationId' => 'evt-shared',
            'reviewStatus'  => 'pending',
        ]);

        $sandboxEvent = $adapter->parseWebhook($payload);
        $first = KycService::handleVerifiedWebhook($this->pdo, $sandboxEvent);

        putenv('SUMSUB_ENVIRONMENT=production');
        $prodAdapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $prodEvent   = $prodAdapter->parseWebhook($payload);
        $second      = KycService::handleVerifiedWebhook($this->pdo, $prodEvent);

        self::assertTrue($first['processed']);
        self::assertTrue($second['processed'], 'La clé d\'idempotence inclut l\'environnement.');
        self::assertNotSame($sandboxEvent->idempotencyKey(), $prodEvent->idempotencyKey());
    }

    public function test_webhook_verifie_met_a_jour_le_niveau_kyc(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $this->seedVerification('appl_green');

        $event = $adapter->parseWebhook(json_encode([
            'applicantId'   => 'appl_green',
            'correlationId' => 'evt-green',
            'reviewStatus'  => 'completed',
            'reviewResult'  => ['reviewAnswer' => 'GREEN'],
        ]));
        KycService::handleVerifiedWebhook($this->pdo, $event);

        $stmt = $this->pdo->prepare('SELECT kyc_level, kyc_verified_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->userId]);
        $user = $stmt->fetch();

        self::assertSame('standard', $user['kyc_level']);
        self::assertNotNull($user['kyc_verified_at']);
    }

    public function test_webhook_pending_n_eleve_jamais_le_niveau_kyc(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $this->seedVerification('appl_pending');

        $event = $adapter->parseWebhook(json_encode([
            'applicantId'   => 'appl_pending',
            'correlationId' => 'evt-pending',
            'reviewStatus'  => 'pending',
        ]));
        KycService::handleVerifiedWebhook($this->pdo, $event);

        $stmt = $this->pdo->prepare('SELECT kyc_level FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->userId]);

        self::assertSame('none', (string) $stmt->fetchColumn());
    }

    public function test_kyb_company_reconnu_comme_entreprise(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);

        $event = $adapter->parseWebhook(json_encode([
            'applicantId'   => 'appl_kyb',
            'correlationId' => 'evt-kyb',
            'applicantType' => 'company',
            'reviewStatus'  => 'completed',
            'reviewResult'  => ['reviewAnswer' => 'GREEN'],
        ]));

        self::assertSame(KycSubjectType::COMPANY, $event->subjectType);
        self::assertTrue($event->status->isVerified());
    }

    /**
     * Un webhook KYB (subject_type=company) projette le flag DISTINCT
     * `users.kyb_status` et NE touche PAS au `kyc_level` individuel.
     */
    public function test_webhook_kyb_met_a_jour_kyb_status_distinct(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $env = strtolower((string) (getenv('SUMSUB_ENVIRONMENT') ?: 'sandbox'));
        $this->pdo->prepare(
            'INSERT INTO kyc_verifications (user_id, provider, environment, subject_type, applicant_id, status)
             VALUES (:uid, :p, :e, :t, :aid, :st)'
        )->execute([
            'uid' => $this->userId,
            'p'   => 'sumsub',
            'e'   => $env,
            't'   => 'company',
            'aid' => 'appl_kyb_proj',
            'st'  => 'pending',
        ]);

        $event = $adapter->parseWebhook(json_encode([
            'applicantId'   => 'appl_kyb_proj',
            'correlationId' => 'evt-kyb-proj',
            'applicantType' => 'company',
            'reviewStatus'  => 'completed',
            'reviewResult'  => ['reviewAnswer' => 'GREEN'],
        ]));
        self::assertSame(KycSubjectType::COMPANY, $event->subjectType);
        KycService::handleVerifiedWebhook($this->pdo, $event);

        $stmt = $this->pdo->prepare(
            'SELECT kyb_status, kyb_verified_at, kyc_level FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => $this->userId]);
        $user = $stmt->fetch();

        self::assertSame('verified', $user['kyb_status'], 'Le flag KYB doit être élevé.');
        self::assertNotNull($user['kyb_verified_at']);
        self::assertSame('none', $user['kyc_level'], 'Le KYB ne doit pas élever le KYC individuel.');
    }

    public function test_audit_de_l_evenement_ne_contient_aucun_secret(): void
    {
        $adapter = $this->adapter([['status' => 200, 'body' => '{}']]);
        $event   = $adapter->parseWebhook(json_encode([
            'applicantId'   => 'appl_audit',
            'correlationId' => 'evt-audit',
            'reviewStatus'  => 'pending',
        ]));

        $audit = json_encode($event->toAuditArray());

        self::assertStringNotContainsString(self::TEST_WEBHOOK_SECRET, $audit);
        self::assertStringNotContainsString(self::TEST_SECRET_KEY, $audit);
        self::assertStringNotContainsString(self::TEST_APP_TOKEN, $audit);
    }

    // ── KYB : infos entreprise envoyées à Sumsub (§37, doc officielle) ──────

    public function test_create_applicant_company_envoie_les_infos_entreprise(): void
    {
        $captured = null;
        $adapter = new SumsubAdapter(function (string $m, string $u, string $b, array $h) use (&$captured): array {
            $captured = ['method' => $m, 'body' => json_decode($b, true)];
            return ['status' => 201, 'body' => json_encode(['id' => 'appl_kyb_1'])];
        });

        $id = $adapter->createApplicant('42', KycSubjectType::COMPANY, [
            'company_name'        => 'Nexus Corp SARL',
            'registration_number' => 'RCS 123 456 789',
            'country'             => 'FR',
            'email'               => 'finance@nexus.test',
        ]);

        self::assertSame('appl_kyb_1', $id);
        self::assertSame('POST', $captured['method']);

        $payload      = $captured['body'];
        $companyInfo  = $payload['fixedInfo']['companyInfo'] ?? [];

        self::assertSame('company', $payload['type'], 'Le sujet doit être une entreprise.');
        self::assertSame('Nexus Corp SARL', $companyInfo['companyName'] ?? null);
        self::assertSame('RCS 123 456 789', $companyInfo['registrationNumber'] ?? null);
        self::assertSame('FRA', $companyInfo['country'] ?? null, 'Le pays doit être converti en alpha-3.');
    }

    public function test_create_applicant_company_omet_le_pays_inconnu(): void
    {
        $captured = null;
        $adapter = new SumsubAdapter(function (string $m, string $u, string $b, array $h) use (&$captured): array {
            $captured = json_decode($b, true);
            return ['status' => 201, 'body' => json_encode(['id' => 'appl_kyb_2'])];
        });

        $adapter->createApplicant('42', KycSubjectType::COMPANY, [
            'company_name' => 'Unknown Co',
            'country'      => 'ZZ', // code inexistant
        ]);

        $companyInfo = $captured['fixedInfo']['companyInfo'] ?? [];
        self::assertArrayNotHasKey('country', $companyInfo, 'Un code inconnu ne doit jamais être deviné (§37).');
        self::assertSame('Unknown Co', $companyInfo['companyName'] ?? null);
    }

    public function test_create_applicant_individual_n_envoie_pas_de_company_info(): void
    {
        $captured = null;
        $adapter = new SumsubAdapter(function (string $m, string $u, string $b, array $h) use (&$captured): array {
            $captured = json_decode($b, true);
            return ['status' => 201, 'body' => json_encode(['id' => 'appl_kyc_1'])];
        });

        $adapter->createApplicant('42', KycSubjectType::INDIVIDUAL, ['email' => 'a@b.test']);

        self::assertArrayNotHasKey('fixedInfo', $captured);
        self::assertArrayNotHasKey('type', $captured, 'Pas de type company pour un individu.');
    }

    // ── Codes pays (alpha-2 → alpha-3) ─────────────────────────────────────

    public function test_conversion_alpha2_vers_alpha3(): void
    {
        self::assertSame('FRA', CountryCodes::alpha2ToAlpha3('FR'));
        self::assertSame('GBR', CountryCodes::alpha2ToAlpha3('gb'));
        self::assertSame('CMR', CountryCodes::alpha2ToAlpha3('CM'));
        self::assertSame('USA', CountryCodes::alpha2ToAlpha3('US'));
    }

    public function test_conversion_code_inconnu_retourne_null(): void
    {
        self::assertNull(CountryCodes::alpha2ToAlpha3('ZZ'));
        self::assertNull(CountryCodes::alpha2ToAlpha3(''));
        self::assertNull(CountryCodes::alpha2ToAlpha3(null));
    }

    // ── Niveau de risque KYB (approche basée sur le risque) ─────────────────

    public function test_risque_high_pour_juridiction_sous_sanctions(): void
    {
        self::assertSame(
            KycRiskScorer::HIGH,
            KycRiskScorer::assess(['country_of_residence' => 'KP', 'industry' => 'Commerce'])
        );
        self::assertSame(
            KycRiskScorer::HIGH,
            KycRiskScorer::assess(['country_of_residence' => 'IR', 'industry' => ''])
        );
    }

    public function test_risque_high_pour_secteur_sensible(): void
    {
        self::assertSame(
            KycRiskScorer::HIGH,
            KycRiskScorer::assess(['country_of_residence' => 'FR', 'industry' => 'Échange de crypto-monnaies'])
        );
        self::assertSame(
            KycRiskScorer::HIGH,
            KycRiskScorer::assess(['country_of_residence' => 'DE', 'industry' => 'Forex / trading'])
        );
    }

    public function test_risque_medium_pour_juridiction_surveillee(): void
    {
        self::assertSame(
            KycRiskScorer::MEDIUM,
            KycRiskScorer::assess(['country_of_residence' => 'SN', 'industry' => 'Agroalimentaire'])
        );
    }

    public function test_risque_low_par_defaut(): void
    {
        self::assertSame(
            KycRiskScorer::LOW,
            KycRiskScorer::assess(['country_of_residence' => 'FR', 'industry' => 'Édition de logiciels'])
        );
        self::assertSame(
            KycRiskScorer::LOW,
            KycRiskScorer::assess(['country_of_residence' => '', 'industry' => ''])
        );
    }

    public function test_persist_risk_level_ecrit_le_niveau_en_base(): void
    {
        $user = ['country_of_residence' => 'KP', 'industry' => 'Commerce'];

        KycService::persistRiskLevel($this->pdo, $this->userId, $user);

        $stmt = $this->pdo->prepare('SELECT risk_level FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->userId]);
        self::assertSame(KycRiskScorer::HIGH, (string) $stmt->fetchColumn());
    }

    /** Crée un dossier de vérification rattaché à l'utilisateur de test. */
    private function seedVerification(string $applicantId): void
    {
        $env = strtolower((string) (getenv('SUMSUB_ENVIRONMENT') ?: 'sandbox'));
        $this->pdo->prepare(
            'INSERT INTO kyc_verifications (user_id, provider, environment, subject_type, applicant_id, status)
             VALUES (:uid, :p, :e, :t, :aid, :st)
             ON DUPLICATE KEY UPDATE applicant_id = VALUES(applicant_id)'
        )->execute([
            'uid' => $this->userId,
            'p'   => 'sumsub',
            'e'   => $env,
            't'   => 'individual',
            'aid' => $applicantId,
            'st'  => 'pending',
        ]);
    }
}
