<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Tests du DashboardController - Phase C3.
 *
 * VÃ©rifie que l'intÃ©gration de WalletService dans DashboardController::summary()
 * conserve le contrat HTTP et les calculs agrÃ©gÃ©s.
 *
 * Base utilisÃ©e : nexus_test (isolÃ©e, JAMAIS nexus).
 */
final class DashboardControllerTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds: list<int>, walletIds: list<int>} */
    private array $created = [
        'userIds'   => [],
        'walletIds' => [],
    ];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail('Les tests DashboardControllerTest doivent utiliser nexus_test uniquement.');
        }
        $this->created = ['userIds' => [], 'walletIds' => []];
    }

    protected function tearDown(): void
    {
        try {
            if (!empty($this->created['walletIds'])) {
                $ph   = implode(',', array_fill(0, count($this->created['walletIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM wallets WHERE id IN ($ph)");
                $stmt->execute($this->created['walletIds']);
            }
            if (!empty($this->created['userIds'])) {
                $ph   = implode(',', array_fill(0, count($this->created['userIds']), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM users WHERE id IN ($ph)");
                $stmt->execute($this->created['userIds']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[DashboardControllerTest::tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    private function uniqueSuffix(): string
    {
        self::$counter++;
        return sprintf('%d_%d_%s', time(), self::$counter, bin2hex(random_bytes(3)));
    }

    private function createUser(string $suffix): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, account_type, auth_provider, status, kyc_level)
             VALUES (:name, :email, :phone, :pwd, :type, :provider, :status, :kyc)'
        );
        $stmt->execute([
            'name'     => 'DashCtrlTest ' . $suffix,
            'email'    => 'dct_' . $suffix . '@nexus-test.local',
            'phone'    => '',
            'pwd'      => '',
            'type'     => 'personal',
            'provider' => 'local',
            'status'   => 'ACTIVE',
            'kyc'      => 'none',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['userIds'][] = $id;
        return $id;
    }

    private function createWallet(int $userId, string $currency, string $balance, string $hold = '0.00'): int
    {
        $available = bcsub($balance, $hold, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance, pending_balance, in_transit_balance, settlement_balance)
             VALUES (:uid, :cur, :bal, :avail, :hold, "0.00", "0.00", "0.00")'
        );
        $stmt->execute([
            'uid'     => $userId,
            'cur'     => $currency,
            'bal'     => $balance,
            'avail'   => $available,
            'hold'    => $hold,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['walletIds'][] = $id;
        return $id;
    }

    private function runController(string $action, int $userId, array $wallets = [], ?string $token = null): array
    {
        // Le runner Dashboard n'a pas de paramètre devise : ligne générée, non utilisée.
        $currency = '';

        $tmpFile = sys_get_temp_dir() . '/dash_ctrl_' . uniqid() . '.json';
        $runnerFile = sys_get_temp_dir() . '/dash_ctrl_runner_' . uniqid() . '.php';

        $walletsJson = json_encode($wallets);

        $phpCode = '<?php' . "\n";
        $phpCode .= 'require_once getcwd() . \'/vendor/autoload.php\';' . "\n\n";
        $phpCode .= 'use Nexus\Core\Database;' . "\n";
        $phpCode .= 'use Nexus\Controllers\DashboardController;' . "\n\n";
        $phpCode .= 'define("DB_HOST", ' . var_export(DB_HOST, true) . ');' . "\n";
        $phpCode .= 'define("DB_PORT", ' . var_export(DB_PORT, true) . ');' . "\n";
        $phpCode .= 'define("DB_NAME", ' . var_export(DB_NAME, true) . ');' . "\n";
        $phpCode .= 'define("DB_USER", ' . var_export(DB_USER, true) . ');' . "\n";
        $phpCode .= 'define("DB_PASS", ' . var_export(DB_PASS, true) . ');' . "\n";
        $phpCode .= 'define("DB_CHARSET", "utf8mb4");' . "\n";
        $phpCode .= 'define("APP_ENV", "development");' . "\n";
        $phpCode .= 'define("JWT_SECRET", "nexus-dev-secret-change-me");' . "\n";
        $phpCode .= 'define("JWT_TTL", 86400);' . "\n\n";
        $phpCode .= '$userId = ' . $userId . ';' . "\n";
        $phpCode .= '$action = \'' . $action . '\';' . "\n";
        $phpCode .= '$tmpFile = \'' . $tmpFile . '\';' . "\n";
        $phpCode .= '$currency = \'' . $currency . '\';' . "\n";
        $phpCode .= '$customToken = ' . ($token === null ? 'null' : var_export($token, true)) . ';' . "\n\n";
        $phpCode .= '$pdo = Database::getConnection();' . "\n\n";
        $phpCode .= '$wallets = json_decode(\'' . str_replace("'", "\\'", $walletsJson) . '\', true) ?: [];' . "\n";
        $phpCode .= 'foreach ($wallets as $w) {' . "\n";
        $phpCode .= '    $balance = $w[\'balance\'] ?? \'0.00\';' . "\n";
        $phpCode .= '    $hold = $w[\'hold\'] ?? \'0.00\';' . "\n";
        $phpCode .= '    $available = bcsub($balance, $hold, 2);' . "\n";
        $phpCode .= '    $stmt = $pdo->prepare(\'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance, pending_balance, in_transit_balance, settlement_balance) VALUES (:uid, :cur, :bal, :avail, :hold, "0.00", "0.00", "0.00") ON DUPLICATE KEY UPDATE balance=:bal, available_balance=:avail, hold_balance=:hold\');' . "\n";
        $phpCode .= '    $stmt->execute([\'uid\' => $userId, \'cur\' => $w[\'currency\'], \'bal\' => $balance, \'avail\' => $available, \'hold\' => $hold]);' . "\n";
        $phpCode .= '}' . "\n\n";
        $phpCode .= '$payload = [\'sub\' => $userId, \'iat\' => time(), \'exp\' => time() + JWT_TTL, \'jti\' => bin2hex(random_bytes(16))];' . "\n";
        $phpCode .= '$header = [\'alg\' => \'HS256\', \'typ\' => \'JWT\'];' . "\n";
        $phpCode .= '$secret = JWT_SECRET;' . "\n";
        $phpCode .= '$b64 = static function (string $d): string { return rtrim(strtr(base64_encode($d), \'+/\', \'-_\'), \'=\'); };' . "\n";
        $phpCode .= '$token = $b64(json_encode($header)) . \'.\' . $b64(json_encode($payload)) . \'.\' . $b64(hash_hmac(\'sha256\', $b64(json_encode($header)) . \'.\' . $b64(json_encode($payload)), $secret, true));' . "\n\n";
        $phpCode .= '$_SERVER[\'REQUEST_METHOD\'] = \'GET\';' . "\n";
        $phpCode .= '$_SERVER[\'REQUEST_URI\'] = \'/api/dashboard/summary\';' . "\n";
        $phpCode .= '$_SERVER[\'HTTP_AUTHORIZATION\'] = \'Bearer \' . ($customToken ?? $token);' . "\n";
        $phpCode .= '$_SERVER[\'CONTENT_TYPE\'] = \'application/json\';' . "\n";
        $phpCode .= '$_GET = []; $_POST = [];' . "\n\n";
        $phpCode .= '$start = microtime(true); $status = 200; $out = \'\';' . "\n";
        $phpCode .= 'ob_start();' . "\n";
        $phpCode .= 'register_shutdown_function(function () use ($tmpFile, &$status, &$out, $start) {' . "\n";
        $phpCode .= '    $buffered = ob_get_clean();' . "\n";
        $phpCode .= '    if ($buffered !== false && $buffered !== \'\') { $out = $buffered; }' . "\n";
        $phpCode .= '    file_put_contents($tmpFile, json_encode([\'status\' => $status, \'output\' => $out, \'elapsed\' => microtime(true) - $start]));' . "\n";
        $phpCode .= '});' . "\n\n";
        $phpCode .= 'try {' . "\n";
        $phpCode .= '    $request = new \\Nexus\\Core\\Request();' . "\n";
        $phpCode .= '    DashboardController::summary($request);' . "\n";
        $phpCode .= '} catch (\\Throwable $e) {' . "\n";
        $phpCode .= '    if ($e instanceof \\Nexus\\Core\\HttpException) {' . "\n";
        $phpCode .= '        $status = $e->statusCode();' . "\n";
        $phpCode .= '        $out = json_encode([\'success\' => false, \'error\' => $e->getMessage(), \'code\' => $e->errorCode()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' . "\n";
        $phpCode .= '    } else {' . "\n";
        $phpCode .= '        $status = 500;' . "\n";
        $phpCode .= '        $out = json_encode([\'success\' => false, \'error\' => $e->getMessage(), \'code\' => \'INTERNAL_ERROR\'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' . "\n";
        $phpCode .= '    }' . "\n";
        $phpCode .= '}' . "\n";

        file_put_contents($runnerFile, $phpCode);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runnerFile) . ' 2>&1';
        exec($cmd, $execOutput, $returnCode);

        $content = @file_get_contents($tmpFile);
        @unlink($tmpFile);
        @unlink($runnerFile);

        if ($content === false) {
            $this->fail('Runner Ã©chouÃ©. Sortie: ' . implode("\n", $execOutput));
        }

        $result = json_decode($content, true);
        return $result;
    }

    public function test_summary_retourne_la_grille_complete_de_6_devises(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $result = $this->runController('summary', $u);
        $this->assertSame(200, $result['status']);
        $data = json_decode($result['output'], true);
        $this->assertCount(6, $data['data']['wallets']);
    }

    public function test_summary_devises_sans_wallet_sont_a_zero(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $result = $this->runController('summary', $u);
        $data = json_decode($result['output'], true);
        foreach ($data['data']['wallets'] as $wallet) {
            $this->assertSame(0.0, $wallet['balance']);
        }
    }

    public function test_summary_retourne_les_bonnes_devises_et_totaux(): void
    {
        // Source FX réelle : 1 EUR = 1.087 USD (table fx_rates_cache).
        $stmt = $this->pdo->prepare(
            'INSERT INTO fx_rates_cache (base_currency, quote_currency, rate, spread_pct, source, fetched_at, expires_at)
             VALUES (:b, :q, :r, :s, :src, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))'
        );
        $stmt->execute([
            'b'   => 'EUR',
            'q'   => 'USD',
            'r'   => '1.08700000',
            's'   => '0.0000',
            'src' => 'fx_provider_test',
        ]);
        $fxRow = (int) $this->pdo->lastInsertId();

        $u = $this->createUser($this->uniqueSuffix());
        $this->createWallet($u, 'EUR', '100.00');
        $this->createWallet($u, 'USD', '100.00'); // 0.92 EUR

        $result = $this->runController('summary', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $byCurrency = [];
        foreach ($response['wallets'] as $wallet) {
            $byCurrency[$wallet['currency']] = $wallet;
        }

        $this->assertSame(100.0, $byCurrency['EUR']['balance']);
        $this->assertSame(100.0, $byCurrency['USD']['balance']);
        $this->assertSame(192.0, $response['totals']['total_ref']);

        // Nettoyage du taux de test.
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE id = ?')->execute([$fxRow]);
    }

    public function test_summary_available_balance_est_balance_moins_hold(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->createWallet($u, 'EUR', '500.00', '120.00');

        $result = $this->runController('summary', $u);
        $data = json_decode($result['output'], true);
        $eur = $data['data']['wallets'][0];
        $this->assertSame(380.0, $eur['available']);
    }

    public function test_summary_structure_json_compatible(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $result = $this->runController('summary', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $this->assertArrayHasKey('ref_currency', $response);
        $this->assertArrayHasKey('user', $response);
        $this->assertArrayHasKey('wallets', $response);
        $this->assertArrayHasKey('totals', $response);
        $this->assertArrayHasKey('kpis', $response);
        $this->assertArrayHasKey('recent', $response);
        $this->assertArrayHasKey('banner', $response);
    }

    public function test_summary_sans_token_retourne_401(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $result = $this->runController('summary', $u, [], 'invalid-token');
        $this->assertSame(401, $result['status']);
    }

    public function test_confirmation_base_nexus_test(): void
    {
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->assertSame('nexus_test', $dbName);
    }
}
