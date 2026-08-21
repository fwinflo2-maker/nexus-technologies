<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\PolicyEngine;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Exposition des limites d'envoi mensuelles (PolicyEngine::limitsFor).
 *
 * Le frontend (dashboard, /send) affiche le plafond réel, la consommation
 * et le restant depuis l'API — jamais une valeur codée en dur côté client.
 *
 * Alignement plafonds (PolicyEngine) :
 *   - personnel non vérifié (none/basic) → 1 000 EUR/mois
 *   - entreprise non vérifiée (KYB ≠ verified) → 2 000 EUR/mois
 *   - standard vérifié → 2 000 EUR/mois
 *   - advanced vérifié → 10 000 EUR/mois
 *
 * Base utilisée : nexus_test (isolée, JAMAIS nexus).
 */
final class PolicyLimitsExposureTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Les tests PolicyLimitsExposureTest doivent utiliser nexus_test uniquement.');
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->userIds !== []) {
                $ph = implode(',', array_fill(0, count($this->userIds), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[PolicyLimitsExposureTest::tearDown] ' . $e->getMessage() . PHP_EOL);
        }
    }

    private function createUser(string $kyc, string $type = 'personal', string $kyb = 'none'): int
    {
        $suffix = (string) (++self::$counter) . '_' . bin2hex(random_bytes(3));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level, kyb_status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Limits ' . $suffix, 'limits_' . $suffix . '@nexus.test', '', $type, 'ACTIVE', $kyc, $kyb]);
        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function userRow(int $id, string $kyc, string $type = 'personal', string $kyb = 'none'): array
    {
        return [
            'id'           => $id,
            'kyc_level'    => $kyc,
            'account_type' => $type,
            'kyb_status'   => $kyb,
        ];
    }

    public function test_plafonds_mensuels_alignes_normes_eu(): void
    {
        $expected = [
            'none'     => 1000.0,
            'basic'    => 1000.0,
            'standard' => 2000.0,
            'advanced' => 10000.0,
        ];

        foreach ($expected as $kyc => $limit) {
            $uid = $this->createUser($kyc);
            $limits = PolicyEngine::limitsFor($this->userRow($uid, $kyc));
            self::assertSame($limit, $limits['monthly_limit_eur'], "Plafond mensuel {$kyc}.");
            self::assertSame($kyc, $limits['kyc_level']);
            self::assertSame(1000.0, $limits['kyc_required_threshold_eur']);
        }
    }

    public function test_entreprise_non_verifiee_plafond_2000(): void
    {
        $uid = $this->createUser('none', 'business', 'none');
        $limits = PolicyEngine::limitsFor($this->userRow($uid, 'none', 'business', 'none'));
        self::assertSame(2000.0, $limits['monthly_limit_eur']);
        self::assertFalse($limits['verified']);
    }

    public function test_entreprise_verifiee_advanced_plafond_10000(): void
    {
        $uid = $this->createUser('advanced', 'business', 'verified');
        $limits = PolicyEngine::limitsFor($this->userRow($uid, 'advanced', 'business', 'verified'));
        self::assertSame(10000.0, $limits['monthly_limit_eur']);
        self::assertTrue($limits['verified']);
    }

    public function test_verifie_seulement_standard_et_advanced_pour_particulier(): void
    {
        foreach (['none', 'basic'] as $kyc) {
            $uid = $this->createUser($kyc);
            $limits = PolicyEngine::limitsFor($this->userRow($uid, $kyc));
            self::assertFalse($limits['verified'], "{$kyc} ne doit PAS être considéré vérifié.");
        }
        foreach (['standard', 'advanced'] as $kyc) {
            $uid = $this->createUser($kyc);
            $limits = PolicyEngine::limitsFor($this->userRow($uid, $kyc));
            self::assertTrue($limits['verified'], "{$kyc} doit être considéré vérifié.");
        }
    }

    public function test_entreprise_verifiee_seulement_avec_kyb_verified(): void
    {
        $uid = $this->createUser('advanced', 'business', 'none');
        self::assertFalse(
            PolicyEngine::limitsFor($this->userRow($uid, 'advanced', 'business', 'none'))['verified'],
            'Une entreprise sans KYB verified est considérée non vérifiée.'
        );

        $uid2 = $this->createUser('advanced', 'business', 'verified');
        self::assertTrue(
            PolicyEngine::limitsFor($this->userRow($uid2, 'advanced', 'business', 'verified'))['verified'],
            'Une entreprise KYB verified est vérifiée.'
        );
    }

    public function test_restant_mensuel_est_coherent(): void
    {
        $uid = $this->createUser('standard');
        $limits = PolicyEngine::limitsFor($this->userRow($uid, 'standard'));

        // remaining = limit − used, jamais négatif, et cohérent avec la somme.
        self::assertSame(
            round($limits['monthly_limit_eur'] - $limits['monthly_used_eur'], 2),
            $limits['monthly_remaining_eur']
        );
        self::assertGreaterThanOrEqual(0.0, $limits['monthly_remaining_eur']);
    }
}
