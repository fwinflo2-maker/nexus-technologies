<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Tests du endpoint GET /api/wallets/holds — Phase G+.
 *
 * Vérifie :
 *   - la forme de la réponse (montants en strings décimales, expires_at,
 *     remaining_seconds) ;
 *   - l'isolation utilisateur : User A ne voit jamais les holds de User B.
 *
 * Base utilisée : nexus_test (isolée, JAMAIS nexus).
 * Les tests HTTP utilisent un runner en processus séparé car
 * Response::success() appelle exit().
 */
final class WalletHoldPendingApiTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds:list<int>, walletIds:list<int>, operationIds:list<string>} */
    private array $created = ['userIds' => [], 'walletIds' => [], 'operationIds' => []];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Refus de tourner contre la base "' . $dbName . '".');
        }
        // Ensure a clean state for holds – remove any leftover hold operations.
        $this->pdo->exec("DELETE FROM wallet_operations WHERE type = 'hold'");
        // Also clean any ledger entries that might reference those operations.
        $this->pdo->exec('DELETE FROM ledger_entries WHERE operation_id NOT IN (SELECT id FROM wallet_operations)');
        $this->created = ['userIds' => [], 'walletIds' => [], 'operationIds' => []];
    }

    protected function tearDown(): void
    {
        try {
            if (!empty($this->created['operationIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['operationIds']), '?'));
                $this->pdo->prepare("DELETE FROM ledger_entries WHERE operation_id IN ($ph)")->execute($this->created['operationIds']);
                $this->pdo->prepare("DELETE FROM wallet_operations WHERE id IN ($ph)")->execute($this->created['operationIds']);
            }
            if (!empty($this->created['walletIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['walletIds']), '?'));
                $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)")->execute($this->created['walletIds']);
            }
            if (!empty($this->created['userIds'])) {
                $ph = implode(',', array_fill(0, count($this->created['userIds']), '?'));
                $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($this->created['userIds']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[WalletHoldPendingApiTest::tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    private function createUser(): int
    {
        $suffix = (string) (++self::$counter) . '_' . bin2hex(random_bytes(3));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, status, kyc_level)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['PendingApi ' . $suffix, 'pendingapi_' . $suffix . '@nexus.test', 'hash', 'personal', 'ACTIVE', 'none']);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $id;
        return $id;
    }

    private function createWallet(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance)
             VALUES (?, \'EUR\', ?, ?, ?)'
        );
        $stmt->execute([$userId, '100.00', '70.00', '30.00']);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['walletIds'][] = $id;
        return $id;
    }

    /** Crée un hold directement en SQL (opération pending, expires_at futur). */
    private function createPendingHold(int $userId, int $walletId, string $amount, string $currency = 'EUR'): string
    {
        $id = 'pendingapi-' . bin2hex(random_bytes(8));
        $stmt = $this->pdo->prepare(
            "INSERT INTO wallet_operations
                (id, user_id, type, status, source_wallet_id, source_currency,
                 source_amount, idempotency_key, expires_at)
             VALUES (?, ?, 'hold', 'pending', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"
        );
        $stmt->execute([$id, $userId, $walletId, $currency, $amount, 'auto:' . $id]);
        $this->created['operationIds'][] = $id;
        return $id;
    }

    private function runPendingHolds(int $userId, string $status = 'pending', ?string $tokenOverride = null): array
    {
        $tmpFile    = sys_get_temp_dir() . '/pending_holds_' . uniqid() . '.json';
        $runnerFile = sys_get_temp_dir() . '/pending_holds_runner_' . uniqid() . '.php';

        $customTokenLiteral = var_export($tokenOverride, true);

        // Constantes DB héritées du bootstrap PHPUnit (surchargées par l'environnement)
        // au lieu d'être codées en dur sur root/'' — portable hors XAMPP.
        $dbHost = var_export(DB_HOST, true);
        $dbPort = var_export(DB_PORT, true);
        $dbName = var_export(DB_NAME, true);
        $dbUser = var_export(DB_USER, true);
        $dbPass = var_export(DB_PASS, true);

        $runnerCode = <<<PHP
<?php
require_once getcwd() . '/vendor/autoload.php';

use Nexus\Core\Database;
use Nexus\Controllers\WalletController;
use Nexus\Core\Request;

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
define('DB_CHARSET', 'utf8mb4');
define('APP_ENV', 'development');
define('JWT_SECRET', 'nexus-dev-secret-change-me');
define('JWT_TTL', 86400);

\$userId = $userId;
\$status = '$status';
\$tmpFile = '$tmpFile';
\$customToken = $customTokenLiteral;

\$payload = ['sub' => \$userId, 'iat' => time(), 'exp' => time() + JWT_TTL, 'jti' => bin2hex(random_bytes(16))];
\$header = ['alg' => 'HS256', 'typ' => 'JWT'];
\$secret = JWT_SECRET;
\$b64 = static function (string \$d): string { return rtrim(strtr(base64_encode(\$d), '+/', '-_'), '='); };
\$headerB64 = \$b64(json_encode(\$header));
\$payloadB64 = \$b64(json_encode(\$payload));
\$sig = hash_hmac('sha256', \$headerB64 . '.' . \$payloadB64, \$secret, true);
\$token = \$headerB64 . '.' . \$payloadB64 . '.' . \$b64(\$sig);

// null → token valide généré ci-dessus ; autre chaîne → utilisée telle quelle.
\$effectiveToken = \$customToken ?? \$token;

\$_SERVER['REQUEST_METHOD']     = 'GET';
\$_SERVER['REQUEST_URI']        = '/api/wallets/holds?status=' . \$status;
\$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \$effectiveToken;
\$_GET  = ['status' => \$status];
\$_POST = [];

\$statusOut = 200;
\$out = '';
ob_start();
register_shutdown_function(function () use (\$tmpFile, &\$statusOut, &\$out): void {
    \$buffered = ob_get_clean();
    if (\$buffered !== false && \$buffered !== '') { \$out = \$buffered; }
    file_put_contents(\$tmpFile, json_encode(['status' => \$statusOut, 'output' => \$out]));
});

try {
    \$request = new Request();
    WalletController::pendingHolds(\$request);
} catch (\\Nexus\\Core\\HttpException \$e) {
    \$statusOut = \$e->statusCode();
    \$out = json_encode(['success' => false, 'error' => \$e->getMessage(), 'code' => \$e->errorCode()]);
} catch (\\Throwable \$e) {
    \$statusOut = 500;
    \$out = json_encode(['success' => false, 'error' => \$e->getMessage()]);
}
PHP;

        file_put_contents($runnerFile, $runnerCode);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runnerFile) . ' 2>&1';
        exec($cmd, $execOutput, $returnCode);

        $content = @file_get_contents($tmpFile);
        @unlink($tmpFile);
        @unlink($runnerFile);

        if ($content === false) {
            $this->fail(
                'Runner échoué. Code: ' . $returnCode . "\n"
                . 'Sortie: ' . implode("\n", $execOutput)
            );
        }

        $result = json_decode($content, true);
        if (!is_array($result) || !isset($result['status'])) {
            $this->fail('Résultat runner invalide: ' . $content);
        }

        return $result;
    }

    public function testPendingHoldsReturnsWellFormedHold(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u);
        $op  = $this->createPendingHold($u, $wid, '100.00000000');

        $result = $this->runPendingHolds($u);
        $this->assertSame(200, $result['status']);

        $data = json_decode($result['output'], true);
        $this->assertNotNull($data, 'La réponse doit être un JSON valide.');
        $this->assertTrue($data['success'] ?? false);
        $this->assertArrayHasKey('data', $data);

        $holds = $data['data']['holds'] ?? null;
        $this->assertIsArray($holds);
        $this->assertCount(1, $holds);

        $hold = $holds[0];
        $this->assertSame($op, $hold['operation_id']);
        $this->assertSame($wid, $hold['wallet_id']);
        $this->assertSame('100.00000000', $hold['amount'], 'Le montant doit rester une string décimale 8 dp.');
        $this->assertSame('EUR', $hold['currency']);
        $this->assertSame('pending', $hold['status']);
        $this->assertArrayHasKey('created_at', $hold);
        $this->assertNotNull($hold['expires_at'], 'expires_at doit être présent.');
        $this->assertIsInt($hold['remaining_seconds']);
        $this->assertGreaterThan(0, $hold['remaining_seconds']);
    }

    public function testUserAOnlySeesOwnHolds(): void
    {
        // User A : 2 holds. User B : 1 hold.
        $uA   = $this->createUser();
        $wA   = $this->createWallet($uA);
        $opA1 = $this->createPendingHold($uA, $wA, '10.00000000');
        $opA2 = $this->createPendingHold($uA, $wA, '20.00000000');

        $uB = $this->createUser();
        $wB = $this->createWallet($uB);
        $this->createPendingHold($uB, $wB, '50.00000000');

        // User A → voit uniquement ses 2 holds.
        $resultA = $this->runPendingHolds($uA);
        $dataA = json_decode($resultA['output'], true);
        $idsA = array_column($dataA['data']['holds'] ?? [], 'operation_id');
        sort($idsA);

        $expectedA = [$opA1, $opA2];
        sort($expectedA);

        $this->assertSame($expectedA, $idsA, 'User A ne doit voir que ses propres holds.');

        // User B → voit uniquement son hold, jamais ceux de User A.
        $resultB = $this->runPendingHolds($uB);
        $dataB = json_decode($resultB['output'], true);
        $idsB = array_column($dataB['data']['holds'] ?? [], 'operation_id');

        $this->assertCount(1, $idsB);
        $this->assertNotContains($opA1, $idsB, 'User B ne doit jamais voir le hold de User A.');
        $this->assertNotContains($opA2, $idsB, 'User B ne doit jamais voir le hold de User A.');
    }

    public function testStatusFilterCompleted(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u);

        // Hold pending + hold completed.
        $this->createPendingHold($u, $wid, '10.00000000');
        $opCompleted = 'pendingapi-completed-' . bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            "INSERT INTO wallet_operations
                (id, user_id, type, status, source_wallet_id, source_currency,
                 source_amount, idempotency_key, expires_at)
             VALUES (?, ?, 'hold', 'completed', ?, 'EUR', ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"
        );
        $stmt->execute([$opCompleted, $u, $wid, '5.00000000', 'auto:' . $opCompleted]);
        $this->created['operationIds'][] = $opCompleted;

        $result = $this->runPendingHolds($u, 'completed');
        $data = json_decode($result['output'], true);
        $ids = array_column($data['data']['holds'] ?? [], 'operation_id');

        $this->assertSame([$opCompleted], $ids, 'Le filtre status=completed doit être respecté.');
    }

    public function testPendingHoldsWithoutTokenReturns401(): void
    {
        $u   = $this->createUser();
        $wid = $this->createWallet($u);
        $this->createPendingHold($u, $wid, '10.00000000');

        $result = $this->runPendingHolds($u, 'pending', 'invalid-token');
        $this->assertSame(401, $result['status'], 'HTTP 401 attendu pour token invalide.');
    }
}
