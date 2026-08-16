<?php

declare(strict_types=1);

namespace Nexus\Kyc;

use RuntimeException;

/**
 * SumsubAdapter — implémentation KycProvider pour Sumsub (§20).
 *
 * SOURCE : documentation officielle Sumsub
 *   - Authentification : https://docs.sumsub.com/reference/authentication
 *   - Access token     : https://docs.sumsub.com/reference/generate-access-token
 *   - Review status    : https://docs.sumsub.com/reference/get-applicant-review-status
 *
 * Authentification (documentée) — trois en-têtes sur CHAQUE requête :
 *   X-App-Token      : app token généré dans le dashboard
 *   X-App-Access-Ts  : timestamp Unix en SECONDES (UTC)
 *   X-App-Access-Sig : HMAC-SHA256 hex minuscule, clé = secret key,
 *                      sur la concaténation  ts + METHOD + path(+query) + body
 *
 * Webhooks (documentés) :
 *   x-payload-digest     : HMAC du corps BRUT
 *   x-payload-digest-alg : algorithme (par défaut HMAC_SHA256_HEX)
 *
 * SÉCURITÉ :
 *  - aucun secret n'est écrit en base, en Git, ni dans un log ;
 *  - la clé secrète ne quitte jamais le backend : le frontend ne reçoit
 *    qu'un access token à durée de vie courte ;
 *  - la vérification de signature utilise hash_equals (temps constant) ;
 *  - le statut client (WebSDK) n'est JAMAIS une preuve : seule la source
 *    serveur (webhook vérifié / appel API) fait foi.
 */
final class SumsubAdapter implements KycProvider
{
    public const SLUG = 'sumsub';

    /** Algorithme de signature par défaut des webhooks Sumsub. */
    private const DEFAULT_DIGEST_ALG = 'HMAC_SHA256_HEX';

    /** Algorithmes de digest supportés (documentés par Sumsub). */
    private const DIGEST_ALGOS = [
        'HMAC_SHA1_HEX'   => 'sha1',
        'HMAC_SHA256_HEX' => 'sha256',
        'HMAC_SHA512_HEX' => 'sha512',
    ];

    /** Transport HTTP injectable — permet de tester sans appel réseau réel (§34). */
    private $transport;

    /**
     * @param null|callable(string,string,string,array):array{status:int,body:string} $transport
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function environment(): string
    {
        $raw = strtolower(trim((string) (getenv('SUMSUB_ENVIRONMENT') ?: '')));
        return $raw === 'production' ? 'production' : 'sandbox';
    }

    /** URL de base de l'API Sumsub. */
    public function baseUrl(): string
    {
        $url = trim((string) (getenv('SUMSUB_BASE_URL') ?: ''));
        return $url !== '' ? rtrim($url, '/') : 'https://api.sumsub.com';
    }

    /** Niveau de vérification pour un type de sujet (§22 : KYB ≠ KYC). */
    public function levelName(KycSubjectType $type): string
    {
        $key = $type->isCompany() ? 'SUMSUB_LEVEL_NAME_KYB' : 'SUMSUB_LEVEL_NAME';
        $level = trim((string) (getenv($key) ?: ''));
        if ($level !== '') {
            return $level;
        }
        // Pas de valeur inventée : on signale une configuration incomplète.
        throw new RuntimeException(sprintf(
            'Niveau de vérification Sumsub non configuré (%s manquant).',
            $key
        ));
    }

    public function isConfigured(): bool
    {
        return $this->appToken() !== null && $this->secretKey() !== null;
    }

    private function appToken(): ?string
    {
        $v = trim((string) (getenv('SUMSUB_APP_TOKEN') ?: ''));
        return $v !== '' ? $v : null;
    }

    private function secretKey(): ?string
    {
        $v = trim((string) (getenv('SUMSUB_SECRET_KEY') ?: ''));
        return $v !== '' ? $v : null;
    }

    private function webhookSecret(): ?string
    {
        $v = trim((string) (getenv('SUMSUB_WEBHOOK_SECRET') ?: ''));
        return $v !== '' ? $v : null;
    }

    /**
     * Signature d'une requête API (documentation Sumsub).
     *
     * Chaîne signée : ts + METHOD + path + body
     * Le path DOIT inclure la query string exactement telle qu'envoyée.
     */
    public function signRequest(string $method, string $path, string $body, int $timestamp): string
    {
        $secret = $this->secretKey();
        if ($secret === null) {
            throw new RuntimeException('SUMSUB_SECRET_KEY non configuré.');
        }
        return hash_hmac('sha256', $timestamp . strtoupper($method) . $path . $body, $secret);
    }

    public function createApplicant(string $externalUserId, KycSubjectType $type, array $profile = []): string
    {
        $level = $this->levelName($type);
        $path  = '/resources/applicants?levelName=' . rawurlencode($level);

        // Aucune donnée sensible envoyée : uniquement l'identifiant externe et
        // d'éventuels champs de pré-remplissage non sensibles.
        $payload = ['externalUserId' => $externalUserId];
        if (isset($profile['email']) && is_string($profile['email'])) {
            $payload['email'] = $profile['email'];
        }
        if (isset($profile['phone']) && is_string($profile['phone'])) {
            $payload['phone'] = $profile['phone'];
        }
        if ($type->isCompany()) {
            // KYB : le sujet est une entreprise. On fournit les données
            // d'identification de la société (voir doc officielle :
            // docs.sumsub.com/reference/autokyb-api-examples) pour permettre
            // le contrôle registre. Sans companyName / registrationNumber /
            // country, la vérification d'entreprise ne peut pas aboutir.
            $payload['type'] = 'company';

            $companyInfo = [];
            if (isset($profile['company_name']) && is_string($profile['company_name']) && $profile['company_name'] !== '') {
                $companyInfo['companyName'] = $profile['company_name'];
            }
            if (isset($profile['registration_number']) && is_string($profile['registration_number']) && $profile['registration_number'] !== '') {
                $companyInfo['registrationNumber'] = $profile['registration_number'];
            }

            // Le pays doit être fourni en alpha-3 (GBR, FRA, DEU…). Nexus le
            // stocke en alpha-2 : conversion via CountryCodes, omis si inconnu
            // (jamais de code deviné — §37).
            $countryAlpha3 = CountryCodes::alpha2ToAlpha3(
                isset($profile['country']) && is_string($profile['country']) ? $profile['country'] : null
            );
            if ($countryAlpha3 !== null) {
                $companyInfo['country'] = $countryAlpha3;
            }

            if ($companyInfo !== []) {
                $payload['fixedInfo'] = ['companyInfo' => $companyInfo];
            }
        }

        $response = $this->request('POST', $path, $payload);
        $id = $response['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('Réponse Sumsub invalide : identifiant d\'applicant absent.');
        }
        return $id;
    }

    public function createVerificationSession(string $externalUserId, KycSubjectType $type): array
    {
        $level = $this->levelName($type);
        $ttl   = (int) (getenv('SUMSUB_TOKEN_TTL') ?: 600);

        $response = $this->request('POST', '/resources/accessTokens/sdk', [
            'userId'    => $externalUserId,
            'levelName' => $level,
            'ttlInSecs' => $ttl,
        ]);

        $token = $response['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Réponse Sumsub invalide : access token absent.');
        }

        // Seule valeur transmissible au frontend : un token court, mono-applicant.
        return ['token' => $token, 'expires_in' => $ttl];
    }

    public function getApplicantStatus(string $applicantId): KycStatus
    {
        return $this->getVerificationStatus($applicantId)['status'];
    }

    public function getVerificationStatus(string $applicantId): array
    {
        $path     = '/resources/applicants/' . rawurlencode($applicantId) . '/status';
        $response = $this->request('GET', $path, null);

        return [
            'status'      => self::mapStatus(
                (string) ($response['reviewStatus'] ?? ''),
                is_array($response['reviewResult'] ?? null) ? $response['reviewResult'] : null
            ),
            'reason'      => self::extractReason($response['reviewResult'] ?? null),
            'reviewed_at' => isset($response['reviewDate']) && is_string($response['reviewDate'])
                ? $response['reviewDate']
                : null,
        ];
    }

    /**
     * Traduit le vocabulaire Sumsub en KycStatus Nexus.
     *
     * Documentation : reviewStatus ∈ {init, pending, prechecked, queued,
     * completed, onHold, awaitingService, awaitingUser}. L'avis (GREEN/RED)
     * n'est significatif QUE lorsque reviewStatus = completed.
     */
    public static function mapStatus(string $reviewStatus, ?array $reviewResult): KycStatus
    {
        $status = strtolower(trim($reviewStatus));

        if ($status === 'completed') {
            $answer = strtoupper(trim((string) ($reviewResult['reviewAnswer'] ?? '')));
            if ($answer === 'GREEN') {
                return KycStatus::VERIFIED;
            }
            if ($answer === 'RED') {
                // RETRY = refus temporaire (resoumission possible),
                // FINAL  = refus définitif.
                $type = strtoupper(trim((string) ($reviewResult['reviewRejectType'] ?? '')));
                return $type === 'RETRY'
                    ? KycStatus::RESUBMISSION_REQUESTED
                    : KycStatus::REJECTED;
            }
            // completed sans avis exploitable : surtout ne pas supposer un succès.
            return KycStatus::PENDING;
        }

        return match ($status) {
            'init'                             => KycStatus::IN_PROGRESS,
            'pending', 'prechecked', 'queued'  => KycStatus::PENDING,
            'onhold', 'awaitingservice',
            'awaitinguser'                     => KycStatus::ON_HOLD,
            // Statut inconnu : jamais interprété comme vérifié.
            default                            => KycStatus::NOT_STARTED,
        };
    }

    private static function extractReason(mixed $reviewResult): ?string
    {
        if (!is_array($reviewResult)) {
            return null;
        }
        // moderationComment est le seul commentaire affichable à l'utilisateur.
        $comment = $reviewResult['moderationComment'] ?? null;
        if (is_string($comment) && $comment !== '') {
            return $comment;
        }
        $labels = $reviewResult['rejectLabels'] ?? null;
        if (is_array($labels) && $labels !== []) {
            return implode(', ', array_filter($labels, 'is_string'));
        }
        return null;
    }

    /**
     * Vérifie la signature d'un webhook Sumsub (§25).
     *
     * Le digest porte sur le corps BRUT : ne jamais re-sérialiser le JSON
     * avant vérification, la moindre différence d'espace casse le HMAC.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $secret = $this->webhookSecret();
        if ($secret === null) {
            // Pas de secret configuré → on refuse. Jamais d'acceptation par défaut.
            return false;
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = is_array($value) ? (string) reset($value) : (string) $value;
        }

        $digest = $normalized['x-payload-digest'] ?? '';
        if ($digest === '') {
            return false;
        }

        $algName = strtoupper(trim($normalized['x-payload-digest-alg'] ?? self::DEFAULT_DIGEST_ALG));
        $algo    = self::DIGEST_ALGOS[$algName] ?? null;
        if ($algo === null) {
            // Algorithme non reconnu → refus (pas de repli permissif).
            return false;
        }

        $expected = hash_hmac($algo, $rawPayload, $secret);

        // Comparaison à temps constant (§25).
        return hash_equals($expected, strtolower(trim($digest)));
    }

    public function parseWebhook(string $rawPayload): KycWebhookEvent
    {
        $data = json_decode($rawPayload, true);
        if (!is_array($data)) {
            throw new RuntimeException('Payload de webhook Sumsub illisible.');
        }

        $applicantId = (string) ($data['applicantId'] ?? '');
        $externalId  = (string) ($data['externalUserId'] ?? '');

        // Identifiant d'événement : Sumsub fournit un identifiant de notification.
        // À défaut, on construit une clé déterministe et stable (jamais aléatoire,
        // sinon l'idempotence serait inopérante).
        $eventId = (string) ($data['correlationId'] ?? $data['notificationId'] ?? '');
        if ($eventId === '') {
            $eventId = hash('sha256', $applicantId . '|' . (string) ($data['type'] ?? '')
                . '|' . (string) ($data['reviewStatus'] ?? '') . '|' . (string) ($data['createdAtMs'] ?? ''));
        }

        $type = (string) ($data['applicantType'] ?? '') === 'company'
            ? KycSubjectType::COMPANY
            : KycSubjectType::INDIVIDUAL;

        $reviewResult = is_array($data['reviewResult'] ?? null) ? $data['reviewResult'] : null;
        $status       = self::mapStatus((string) ($data['reviewStatus'] ?? ''), $reviewResult);

        return new KycWebhookEvent(
            provider: self::SLUG,
            environment: $this->environment(),
            eventId: $eventId,
            applicantId: $applicantId,
            externalUserId: $externalId,
            subjectType: $type,
            status: $status,
            reason: self::extractReason($reviewResult),
            occurredAt: isset($data['createdAt']) && is_string($data['createdAt']) ? $data['createdAt'] : null,
        );
    }

    /**
     * Exécute une requête signée vers l'API Sumsub.
     *
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Sumsub non configuré (SUMSUB_APP_TOKEN / SUMSUB_SECRET_KEY manquants).');
        }

        $encoded   = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = $this->signRequest($method, $path, $encoded, $timestamp);

        $headers = [
            'X-App-Token: ' . (string) $this->appToken(),
            'X-App-Access-Ts: ' . $timestamp,
            'X-App-Access-Sig: ' . $signature,
            'Content-Type: application/json',
        ];

        $result = $this->transport !== null
            ? ($this->transport)($method, $this->baseUrl() . $path, $encoded, $headers)
            : $this->httpCall($method, $this->baseUrl() . $path, $encoded, $headers);

        $status = (int) ($result['status'] ?? 0);
        $raw    = (string) ($result['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            // Le message d'erreur ne doit jamais contenir de secret.
            throw new RuntimeException(sprintf('Appel Sumsub en échec (HTTP %d).', $status));
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{status:int,body:string} */
    private function httpCall(string $method, string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossible d\'initialiser la requête HTTP.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($raw) ? $raw : ''];
    }
}
