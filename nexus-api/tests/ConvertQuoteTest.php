<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\Jwt;
use Nexus\Controllers\QuoteController;
use Nexus\Controllers\WalletController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 16 — LA CONVERSION PASSE PAR UNE QUOTE AU TAUX GARANTI.
 *
 * LE DÉFAUT
 * ─────────
 * L'interface /convert affichait un taux (« quote ») via POST /api/quotes
 * (pipeline provider), puis exécutait POST /api/wallets/convert SANS lien
 * avec cette quote : le taux re-résolu au moment de l'exécution pouvait
 * différer de celui affiché, et la route affichée (routes[0]) n'était jamais
 * exécutée. La « garantie » d'un taux était purement décorative.
 *
 * LA CORRECTION
 * ─────────────
 * POST /api/quotes/convert produit une quote du rail INTERNE (wallet→wallet,
 * aucun provider requis) au taux RÉEL (QuotePricing → fx_rates_cache), et
 * POST /api/wallets/convert accepte `quote_id` : l'exécution honore le taux
 * VERROUILLÉ de la quote, puis la marque EXECUTED. Taux vu = taux appliqué.
 */
final class ConvertQuoteTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $created = [];
    private int $userId = 0;
    private int $eurWallet = 0;
    private int $usdWallet = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        Response::enableTestMode(true);

        $this->userId    = $this->createUser();
        $this->eurWallet = $this->createWallet($this->userId, 'EUR', '1000.00');
        $this->usdWallet = $this->createWallet($this->userId, 'USD', '0.00');

        $this->seedRate('EUR', 'USD', '1.08700000');
        $this->authenticateAs($this->userId);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_NEXUS_ENVIRONMENT']);
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE source = :s')
            ->execute(['s' => 'fx_provider_test']);

        foreach ($this->created as $uid) {
            $this->pdo->prepare(
                'DELETE FROM ledger_entries WHERE wallet_id IN (SELECT id FROM wallets WHERE user_id = ?)'
            )->execute([$uid]);

            foreach (['wallet_operations', 'idempotency_keys', 'transactions', 'wallets', 'audit_logs'] as $t) {
                $this->pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$uid]);
            }
            // `quotes` est supprimé par la FK ON DELETE CASCADE vers users.
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        }
        $this->created = [];
    }

    private function createUser(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence, status)
             VALUES (:n, :e, :p, :t, :c, :s)'
        );
        $stmt->execute([
            'n' => 'Convert Quote Probe',
            'e' => 'cq_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
            's' => 'ACTIVE',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->created[] = $id;

        return $id;
    }

    private function createWallet(int $userId, string $currency, string $balance): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance)
             VALUES (:u, :c, :bal, :avail, 0)'
        );
        $stmt->execute(['u' => $userId, 'c' => $currency, 'bal' => $balance, 'avail' => $balance]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedRate(string $base, string $quote, string $rate): void
    {
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE base_currency = :b AND quote_currency = :q')
            ->execute(['b' => $base, 'q' => $quote]);
        $this->pdo->prepare(
            'INSERT INTO fx_rates_cache (base_currency, quote_currency, rate, spread_pct, source, fetched_at, expires_at)
             VALUES (:b, :q, :r, :s, :src, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))'
        )->execute([
            'b'   => $base,
            'q'   => $quote,
            'r'   => $rate,
            's'   => '0.0000',
            'src' => 'fx_provider_test',
        ]);
    }

    private function authenticateAs(int $userId): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Jwt::encode([
            'sub' => $userId, 'iat' => time(), 'exp' => time() + 3600,
        ]);
    }

    /** @return array{status:int,code:?string,data:array<string,mixed>} */
    private function request(string $controller, string $method, array $body): array
    {
        try {
            $controller::$method(new Request($body));

            return ['status' => 0, 'code' => null, 'data' => []];
        } catch (ResponseSent $sent) {
            $decoded = json_decode($sent->body(), true);

            return [
                'status' => $sent->statusCode(),
                'code'   => is_array($decoded) ? ($decoded['code'] ?? null) : null,
                'data'   => is_array($decoded) ? ($decoded['data'] ?? []) : [],
            ];
        } catch (HttpException $e) {
            return ['status' => $e->statusCode(), 'code' => $e->errorCode(), 'data' => []];
        }
    }

    private function quote(array $body): array
    {
        return $this->request(QuoteController::class, 'createConvert', $body);
    }

    private function convert(array $body): array
    {
        return $this->request(WalletController::class, 'convert', $body);
    }

    private function balance(int $walletId): string
    {
        $stmt = $this->pdo->prepare('SELECT balance FROM wallets WHERE id = ?');
        $stmt->execute([$walletId]);

        return (string) $stmt->fetchColumn();
    }

    private function quoteStatus(string $quoteId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM quotes WHERE id = ?');
        $stmt->execute([$quoteId]);

        $status = $stmt->fetchColumn();

        return is_string($status) ? $status : null;
    }

    // ══ 1. LA QUOTE INTERNE RETOURNE UN TAUX RÉEL ET UNE ROUTE INT ═════════

    public function test_convert_quote_returns_real_rate_and_internal_route(): void
    {
        $res = $this->quote([
            'amount'         => '100.00',
            'sourceCurrency' => 'EUR',
            'destCurrency'   => 'USD',
        ]);

        $this->assertSame(200, $res['status'], 'Une quote avec taux réel doit aboutir.');
        $this->assertNotEmpty($res['data']['id'] ?? '', 'La quote doit avoir un identifiant.');
        $this->assertNotEmpty($res['data']['expires_at'] ?? '', 'La quote doit avoir une expiration.');

        $routes = $res['data']['routes'] ?? [];
        $this->assertCount(1, $routes, 'Le rail interne produit UNE seule route.');
        $route = $routes[0];
        $this->assertSame('INT', $route['id'] ?? null, "La route interne s'appelle INT.");
        $this->assertSame('nexus_internal', $route['providerSlug'] ?? null, 'Pas de provider externe.');
        $this->assertSame('1.08700000', $route['locked_rate'] ?? null, 'Le taux verrouillé est le taux réel.');
        $this->assertSame(0.0, (float) ($route['feesNum'] ?? -1), 'Frais interne nuls (le spread couvre la marge).');
        $this->assertTrue($route['recommended'] ?? false, 'Route interne recommandée par construction.');
    }

    // ══ 2. PAS DE TAUX RÉEL → FX_UNAVAILABLE, AUCUNE QUOTE INVENTÉE ════════

    public function test_convert_quote_refused_without_real_rate(): void
    {
        // Aucun taux EUR → GBP en cache : la paire n'est pas cotée.
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE base_currency = :b AND quote_currency = :q')
            ->execute(['b' => 'EUR', 'q' => 'GBP']);

        $res = $this->quote([
            'amount'         => '100.00',
            'sourceCurrency' => 'EUR',
            'destCurrency'   => 'GBP',
        ]);

        $this->assertSame(503, $res['status'], 'Sans taux réel, la quote est refusée.');
        $this->assertSame('FX_RATE_UNAVAILABLE', $res['code'], 'Code FX normalisé.');
    }

    // ══ 3. L'EXÉCUTION HONORE LE TAUX VERROUILLÉ (taux vu = taux appliqué) ══

    public function test_execution_honors_the_locked_quote_rate(): void
    {
        $q = $this->quote([
            'amount'         => '100.00',
            'sourceCurrency' => 'EUR',
            'destCurrency'   => 'USD',
        ]);
        $quoteId = $q['data']['id'];

        // Le marché « bouge » entre la quote et l'exécution : le taux courant
        // passe de 1.0870 à 1.5000. L'exécution avec quote_id doit appliquer
        // le taux ANCIEN (celui affiché à l'utilisateur).
        $this->seedRate('EUR', 'USD', '1.50000000');

        $res = $this->convert([
            'amount'          => '100.00',
            'source_currency' => 'EUR',
            'dest_currency'   => 'USD',
            'quote_id'        => $quoteId,
            'route_id'        => 'INT',
        ]);

        $this->assertSame(200, $res['status'], 'La conversion au taux garanti doit aboutir.');
        $conversion = $res['data']['conversion'] ?? [];
        $this->assertSame('1.08700000', (string) ($conversion['fx_rate'] ?? ''), 'Le taux exécuté est celui de la quote.');
        $this->assertSame('convert_quote', (string) ($conversion['fx_source'] ?? ''), 'Source FX = quote de conversion.');

        $this->assertSame('900.00', $this->balance($this->eurWallet), 'Wallet source débité de 100 EUR.');
        $this->assertSame(
            '108.70',
            $this->balance($this->usdWallet),
            'Wallet destination crédité au taux VERROUILLÉ (108.70), pas au taux courant (150.00).'
        );
    }

    // ══ 4. LA QUOTE N'EST EXÉCUTABLE QU'UNE FOIS ════════════════════════════

    public function test_quote_is_executed_once_then_refused(): void
    {
        $q = $this->quote([
            'amount'         => '100.00',
            'sourceCurrency' => 'EUR',
            'destCurrency'   => 'USD',
        ]);
        $quoteId = $q['data']['id'];

        $first = $this->convert([
            'amount'          => '100.00',
            'source_currency' => 'EUR',
            'dest_currency'   => 'USD',
            'quote_id'        => $quoteId,
        ]);
        $this->assertSame(200, $first['status']);
        $this->assertSame('EXECUTED', $this->quoteStatus($quoteId), 'La quote est marquée EXECUTED.');

        $second = $this->convert([
            'amount'          => '100.00',
            'source_currency' => 'EUR',
            'dest_currency'   => 'USD',
            'quote_id'        => $quoteId,
        ]);
        $this->assertSame(409, $second['status']);
        $this->assertSame('QUOTE_ALREADY_EXECUTED', $second['code']);
        $this->assertSame('900.00', $this->balance($this->eurWallet), 'Aucun double débit.');
    }

    // ══ 5. QUOTE EXPIRÉE → REFUS, AUCUNE CONVERSION ═════════════════════════

    public function test_expired_quote_is_refused_without_mutation(): void
    {
        $q = $this->quote([
            'amount'         => '100.00',
            'sourceCurrency' => 'EUR',
            'destCurrency'   => 'USD',
        ]);
        $quoteId = $q['data']['id'];

        // Expire la quote en base.
        $this->pdo->prepare('UPDATE quotes SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id')
            ->execute(['id' => $quoteId]);

        $res = $this->convert([
            'amount'          => '100.00',
            'source_currency' => 'EUR',
            'dest_currency'   => 'USD',
            'quote_id'        => $quoteId,
        ]);

        $this->assertSame(409, $res['status']);
        $this->assertSame('QUOTE_EXPIRED', $res['code']);
        $this->assertSame('EXPIRED', $this->quoteStatus($quoteId), 'La quote expirée est marquée EXPIRED.');
        $this->assertSame('1000.00', $this->balance($this->eurWallet), 'Aucune mutation sur une quote expirée.');
    }

    // ══ 6. INCOHÉRENCE QUOTE / REQUÊTE → 422 QUOTE_MISMATCH ════════════════

    public function test_quote_mismatch_is_refused(): void
    {
        $q = $this->quote([
            'amount'         => '100.00',
            'sourceCurrency' => 'EUR',
            'destCurrency'   => 'USD',
        ]);
        $quoteId = $q['data']['id'];

        // Montant différent de la quote.
        $res = $this->convert([
            'amount'          => '150.00',
            'source_currency' => 'EUR',
            'dest_currency'   => 'USD',
            'quote_id'        => $quoteId,
        ]);

        $this->assertSame(422, $res['status']);
        $this->assertSame('QUOTE_MISMATCH', $res['code']);
        $this->assertSame('1000.00', $this->balance($this->eurWallet));
    }

    // ══ 7. IDEMPOTENCE CONSERVÉE AVEC quote_id ═════════════════════════════

    public function test_idempotency_is_preserved_with_quote_id(): void
    {
        $q = $this->quote([
            'amount'         => '100.00',
            'sourceCurrency' => 'EUR',
            'destCurrency'   => 'USD',
        ]);
        $quoteId = $q['data']['id'];

        $body = [
            'amount'          => '100.00',
            'source_currency' => 'EUR',
            'dest_currency'   => 'USD',
            'quote_id'        => $quoteId,
            'idempotency_key' => 'cq-test-' . bin2hex(random_bytes(6)),
        ];

        $first  = $this->convert($body);
        $second = $this->convert($body);

        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status'], 'Le replay idempotent est un succès, pas une erreur.');
        $this->assertSame(
            $first['data']['conversion']['operation_id'] ?? null,
            $second['data']['conversion']['operation_id'] ?? null,
            'Le replay renvoie la MÊME opération.'
        );
        $this->assertSame('900.00', $this->balance($this->eurWallet), 'Débit unique malgré deux appels.');
    }
}
