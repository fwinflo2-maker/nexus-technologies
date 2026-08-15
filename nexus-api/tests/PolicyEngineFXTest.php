<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Services\QuotePricing;
use Nexus\Services\QuoteRateUnavailable;
use Nexus\Services\QuoteService;
use Nexus\Services\ReferenceConverter;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Les plafonds réglementaires suivent-ils le taux réel ? (boucle 17)
 *
 * CE QUE CES TESTS PROTÈGENT
 * ──────────────────────────
 * `QuoteService` et `QuoteController` calculaient le montant comparé aux
 * PLAFONDS KYC à partir d'une table de taux écrite en dur :
 *
 *     'USD' => 1.0870   // puis $amountRef = $amount / $rate
 *
 * Vérifié en HTTP pendant l'audit : en faisant passer le taux réel de 1,10 à
 * 5,00 — un facteur 4,5 — le PolicyEngine rendait un verdict IDENTIQUE
 * (« il vous reste 750 EUR »). Un contrôle de sécurité insensible au taux
 * qu'il prétend appliquer ne protège rien : il suffisait qu'un taux réel
 * s'écarte de la constante pour qu'un plafond soit franchi ou bloqué à tort.
 *
 * Le montant de référence provient désormais de `ReferenceConverter`, donc de
 * `FXService` — la même source que le pricing.
 *
 * COUPLAGE
 * ────────
 * `PolicyEngine` ne dépend pas directement du FX : il reçoit un montant déjà
 * converti. La logique de conversion reste dans un seul composant, sans
 * duplication ni second système FX.
 */
final class PolicyEngineFXTest extends TestCase
{
    private const TEST_SOURCE = 'policy17';

    private PDO $pdo;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== 'nexus_test') {
            $this->fail('Refus de tourner hors de nexus_test.');
        }

        $this->purge();
        QuotePricing::resetCache();
    }

    protected function tearDown(): void
    {
        try {
            $this->purge();

            if ($this->userIds !== []) {
                $ph = implode(',', array_fill(0, count($this->userIds), '?'));
                $this->pdo->prepare("DELETE FROM wallets WHERE user_id IN ($ph)")->execute($this->userIds);
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->userIds);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[PolicyEngineFXTest] ' . $e->getMessage() . PHP_EOL);
        }

        QuotePricing::resetCache();
    }

    // ── Le montant de référence suit le taux ────────────────────────────────

    /**
     * LE test de la boucle : deux taux réels différents doivent produire deux
     * montants de référence différents.
     */
    public function test_le_montant_compare_au_plafond_suit_le_taux_reel(): void
    {
        // 1 USD = 0.20 EUR
        $this->seed('USD', 'EUR', '0.20000000', 'sandbox');
        $faible = ReferenceConverter::amountToEur(10000.0, 'USD', ExecutionEnvironment::SANDBOX);

        // 1 USD = 0.90 EUR
        $this->seed('USD', 'EUR', '0.90000000', 'sandbox');
        QuotePricing::resetCache();
        $eleve = ReferenceConverter::amountToEur(10000.0, 'USD', ExecutionEnvironment::SANDBOX);

        self::assertSame(2000.0, $faible);
        self::assertSame(9000.0, $eleve);
        self::assertNotSame(
            $faible,
            $eleve,
            'Le montant soumis au plafond doit varier avec le taux réel.'
        );
    }

    // ── Cas limites autour du plafond ───────────────────────────────────────

    /**
     * Plafond KYC « standard » = 2000 EUR/mois. Avec 1 USD = 0.20 EUR :
     *   9 995 USD = 1 999,00 EUR  → sous la limite
     *  10 000 USD = 2 000,00 EUR  → exactement à la limite
     *  10 005 USD = 2 001,00 EUR  → au-dessus
     */
    public function test_les_cas_limites_du_plafond_sont_calcules_sur_le_taux_reel(): void
    {
        $this->seed('USD', 'EUR', '0.20000000', 'sandbox');

        $sous   = ReferenceConverter::amountToEur(9995.0, 'USD', ExecutionEnvironment::SANDBOX);
        $pile   = ReferenceConverter::amountToEur(10000.0, 'USD', ExecutionEnvironment::SANDBOX);
        $dessus = ReferenceConverter::amountToEur(10005.0, 'USD', ExecutionEnvironment::SANDBOX);

        self::assertSame(1999.0, $sous);
        self::assertSame(2000.0, $pile);
        self::assertSame(2001.0, $dessus);

        $limit = 2000.0;
        self::assertLessThan($limit, $sous);
        self::assertSame($limit, $pile, 'À la limite exacte, le montant doit être strictement égal.');
        self::assertGreaterThan($limit, $dessus);
    }

    // ── Production ──────────────────────────────────────────────────────────

    public function test_la_production_calcule_le_plafond_sur_son_propre_taux(): void
    {
        $this->seed('USD', 'EUR', '0.20000000', 'sandbox');
        $this->seed('USD', 'EUR', '0.90000000', 'production');

        self::assertSame(2000.0, ReferenceConverter::amountToEur(10000.0, 'USD', ExecutionEnvironment::SANDBOX));
        self::assertSame(9000.0, ReferenceConverter::amountToEur(10000.0, 'USD', ExecutionEnvironment::PRODUCTION));
    }

    /**
     * Sans taux réel, le plafond ne peut pas être vérifié : la cotation est
     * refusée plutôt que contrôlée sur une constante de démonstration.
     */
    public function test_la_production_sans_taux_refuse_de_coter(): void
    {
        $userId = $this->createUser();

        $this->expectException(QuoteRateUnavailable::class);

        try {
            QuoteService::computeRoutes(
                $this->user($userId),
                $this->intent(1000.0, 'USD'),
                ExecutionEnvironment::PRODUCTION
            );
        } catch (QuoteRateUnavailable $e) {
            self::assertStringContainsString('plafond', strtolower($e->getMessage()));
            throw $e;
        }
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function createUser(): int
    {
        $suffix = bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (:n, :e, :p, :t, :s, :k)'
        );
        $stmt->execute([
            'n' => 'Policy FX ' . $suffix,
            'e' => 'policyfx_' . $suffix . '@nexus-test.local',
            'p' => '',
            't' => 'personal',
            's' => 'ACTIVE',
            'k' => 'standard',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->userIds[] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function user(int $userId): array
    {
        return [
            'id'           => $userId,
            'status'       => 'ACTIVE',
            'kyc_level'    => 'standard',
            'account_type' => 'personal',
        ];
    }

    /** @return array<string, mixed> */
    private function intent(float $amount, string $currency): array
    {
        return [
            'amount'          => $amount,
            'sourceCurrency'  => $currency,
            'destCountry'     => 'CM',
            'destCurrency'    => 'XAF',
            'receivingMethod' => 'mobile_money',
            'objective'       => 'optimized',
        ];
    }

    private function seed(string $base, string $quote, string $rate, string $environment): void
    {
        $this->pdo
            ->prepare('DELETE FROM fx_rates_cache WHERE base_currency = ? AND quote_currency = ? AND environment = ? AND source = ?')
            ->execute([$base, $quote, $environment, self::TEST_SOURCE]);

        $this->pdo->prepare(
            'INSERT INTO fx_rates_cache
                (base_currency, quote_currency, rate, spread_pct, source, environment, fetched_at, expires_at)
             VALUES (?, ?, ?, 0, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([$base, $quote, $rate, self::TEST_SOURCE, $environment]);

        QuotePricing::resetCache();
    }

    private function purge(): void
    {
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE source = ?')->execute([self::TEST_SOURCE]);
    }
}
