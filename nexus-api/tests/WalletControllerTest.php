<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Services\WalletService;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Tests du WalletController - Phase C2.
 *
 * Vérifie que l'intégration de WalletService dans WalletController
 * conserve le contrat HTTP existant.
 *
 * Base utilisée : nexus_test (isolée, JAMAIS nexus).
 * Stratégie d'isolation : chaque test crée ses propres fixtures
 * (utilisateur + wallets) identifiées par un suffixe unique.
 * Le tearDown supprime exactement les IDs créés par chaque test.
 *
 * Les tests HTTP utilisent @runInSeparateProcess car Response::success()
 * appelle exit().
 */
final class WalletControllerTest extends TestCase
{
    private static int $counter = 0;

    private PDO $pdo;

    /** @var array{userIds: list<int>, walletIds: list<int>} */
    private array $created = [
        'userIds'   => [],
        'walletIds' => [],
    ];

    // ---- setUp / tearDown --------------------------------------------------

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();

        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== 'nexus_test') {
            $this->fail(
                'Refus de tourner contre la base "' . $dbName . '". '
                . 'Les tests WalletControllerTest doivent utiliser nexus_test uniquement.'
            );
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
            fwrite(STDERR, '[WalletControllerTest::tearDown] cleanup error: ' . $e->getMessage() . PHP_EOL);
        }
    }

    // ---- Helpers fixtures --------------------------------------------------

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
            'name'     => 'WalletCtrlTest ' . $suffix,
            'email'    => 'wct_' . $suffix . '@nexus-test.local',
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

    private function createWallet(
        int    $userId,
        string $currency,
        string $balance,
        string $hold        = '0.00',
        string $pending     = '0.00',
        string $inTransit   = '0.00',
        string $settlement  = '0.00'
    ): int {
        $available = bcsub($balance, $hold, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets
                (user_id, currency, balance, available_balance, hold_balance,
                 pending_balance, in_transit_balance, settlement_balance)
             VALUES (:uid, :cur, :bal, :avail, :hold, :pend, :intrans, :settle)'
        );
        $stmt->execute([
            'uid'     => $userId,
            'cur'     => $currency,
            'bal'     => $balance,
            'avail'   => $available,
            'hold'    => $hold,
            'pend'    => $pending,
            'intrans' => $inTransit,
            'settle'  => $settlement,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created['walletIds'][] = $id;
        return $id;
    }

    /**
     * Génère un JWT valide pour l'utilisateur donné.
     */
    private function generateJwt(int $userId): string
    {
        $payload = [
            'sub' => $userId,
            'iat'  => time(),
            'exp'  => time() + 86400,
            'jti'  => bin2hex(random_bytes(16)),
        ];

        $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
        $secret  = 'nexus-dev-secret-change-me';

        $b64 = static function (string $data): string {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $headerB64  = $b64(json_encode($header));
        $payloadB64 = $b64(json_encode($payload));
        $sig        = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
        $sigB64     = $b64($sig);

        return $headerB64 . '.' . $payloadB64 . '.' . $sigB64;
    }

    /**
     * Exécute le contrôleur dans un processus séparé via un script runner.
     * Retourne le tableau JSON décodé de la réponse.
     *
     * @return array<string, mixed>
     */
    private function runController(string $action, int $userId, array $wallets = [], string $currency = '', ?string $token = null): array
    {
        $tmpFile = sys_get_temp_dir() . '/wallet_ctrl_' . uniqid() . '.json';
        $runnerFile = sys_get_temp_dir() . '/wallet_ctrl_runner_' . uniqid() . '.php';

        // Prépare les fixtures SQL
        $sqlInserts = '';
        foreach ($wallets as $w) {
            $balance   = $w['balance'] ?? '0.00';
            $hold      = $w['hold'] ?? '0.00';
            $pending   = $w['pending'] ?? '0.00';
            $inTransit = $w['in_transit'] ?? '0.00';
            $settlement = $w['settlement'] ?? '0.00';
            $available = bcsub($balance, $hold, 2);

            $sqlInserts .= sprintf(
                "INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance, pending_balance, in_transit_balance, settlement_balance) VALUES (%d, '%s', '%s', '%s', '%s', '%s', '%s', '%s') ON DUPLICATE KEY UPDATE balance = '%s', available_balance = '%s', hold_balance = '%s', pending_balance = '%s', in_transit_balance = '%s', settlement_balance = '%s';\n",
                $userId,
                $w['currency'],
                $balance,
                $available,
                $hold,
                $pending,
                $inTransit,
                $settlement,
                $balance,
                $available,
                $hold,
                $pending,
                $inTransit,
                $settlement
            );
        }

        $route = '/api/wallets';
        if ($action === 'rates') {
            $route = '/api/wallets/rates';
        } elseif ($action === 'transactions' && $currency !== '') {
            $route = '/api/wallets/' . strtolower($currency) . '/transactions';
        }

        // Valeur littérale injectée dans le runner. null → token valide généré plus bas ;
        // tout autre chaîne (ex. token invalide) est utilisée telle quelle.
        $customTokenLiteral = var_export($token, true);

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
\$action = '$action';
\$currency = '$currency';
\$customToken = $customTokenLiteral;
\$tmpFile = '$tmpFile';

\$pdo = Database::getConnection();

\$sql = <<<SQL
$sqlInserts
SQL;
if (trim(\$sql) !== '') {
    \$pdo->exec(\$sql);
}

\$payload = [
    'sub' => \$userId,
    'iat' => time(),
    'exp' => time() + JWT_TTL,
    'jti' => bin2hex(random_bytes(16)),
];
\$header = ['alg' => 'HS256', 'typ' => 'JWT'];
\$secret = JWT_SECRET;

\$b64 = static function (string \$d): string {
    return rtrim(strtr(base64_encode(\$d), '+/', '-_'), '=');
};

\$headerB64  = \$b64(json_encode(\$header));
\$payloadB64 = \$b64(json_encode(\$payload));
\$sig        = hash_hmac('sha256', \$headerB64 . '.' . \$payloadB64, \$secret, true);
\$sigB64     = \$b64(\$sig);
\$token      = \$headerB64 . '.' . \$payloadB64 . '.' . \$sigB64;

\$_SERVER['REQUEST_METHOD']     = 'GET';
\$_SERVER['REQUEST_URI']        = '$route';
\$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (\$customToken ?? \$token);
\$_SERVER['CONTENT_TYPE']       = 'application/json';
\$_GET  = [];
\$_POST = [];

\$start = microtime(true);
\$status = 200;
\$out = '';
ob_start();

register_shutdown_function(function () use (\$tmpFile, &\$status, &\$out, \$start): void {
    \$elapsed = microtime(true) - \$start;
    \$buffered = ob_get_clean();
    if (\$buffered !== false && \$buffered !== '') {
        \$out = \$buffered;
    }
    file_put_contents(\$tmpFile, json_encode([
        'status' => \$status,
        'output' => \$out,
        'elapsed' => \$elapsed,
    ]));
});

try {
    \$request = new \\Nexus\\Core\\Request();
    if (\$action === 'rates') {
        WalletController::rates(\$request);
    } elseif (\$action === 'transactions') {
        \$request->setParams(['currency' => \$currency]);
        WalletController::transactions(\$request);
    } else {
        WalletController::index(\$request);
    }
} catch (\\Throwable \$e) {
    if (\$e instanceof \\Nexus\\Core\\HttpException) {
        \$status = \$e->statusCode();
        \$out = json_encode([
            'success' => false,
            'error' => \$e->getMessage(),
            'code' => \$e->errorCode(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        \$status = 500;
        \$out = json_encode([
            'success' => false,
            'error' => 'Erreur interne: ' . \$e->getMessage(),
            'code' => 'INTERNAL_ERROR',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
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

    // ---- Tests : cas nominal -----------------------------------------------

    public function test_index_retourne_la_grille_complete_de_6_devises(): void
    {
        $u = $this->createUser($this->uniqueSuffix());

        $result = $this->runController('index', $u);
        $this->assertSame(200, $result['status'], 'HTTP 200 attendu.');

        $data = json_decode($result['output'], true);
        $this->assertNotNull($data, 'La réponse doit être un JSON valide.');
        $this->assertTrue($data['success'], 'success=true attendu.');
        $this->assertArrayHasKey('data', $data);

        $response = $data['data'];
        $this->assertArrayHasKey('ref_currency', $response);
        $this->assertSame('EUR', $response['ref_currency']);
        $this->assertArrayHasKey('totals', $response);
        $this->assertArrayHasKey('wallets', $response);
        $this->assertCount(6, $response['wallets'], 'La grille doit contenir exactement 6 devises.');
    }

    public function test_index_devises_sans_wallet_sont_a_zero(): void
    {
        $u = $this->createUser($this->uniqueSuffix());

        $result = $this->runController('index', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $currencies = array_column($response['wallets'], 'currency');
        $this->assertSame(['EUR', 'USD', 'GBP', 'XAF', 'USDT', 'USDC'], $currencies);

        foreach ($response['wallets'] as $wallet) {
            $this->assertSame(0.0, $wallet['balance']);
            $this->assertSame(0.0, $wallet['available']);
            $this->assertSame(0.0, $wallet['pending']);
            $this->assertSame(0.0, $wallet['in_transit']);
            $this->assertSame(0.0, $wallet['settlement']);
            $this->assertFalse($wallet['has_funds']);

            // Sans source FX configurée, l'équivalent EUR d'un wallet est
            // « indisponible » (null) — jamais une valeur inventée (§9).
            // Seule l'EUR bénéficie de l'identité (1 EUR = 1 EUR).
            if ($wallet['currency'] === 'EUR') {
                $this->assertSame(0.0, $wallet['ref_equivalent']);
            } else {
                $this->assertNull($wallet['ref_equivalent']);
            }
        }

        $this->assertSame(0.0, $response['totals']['total_ref']);
        $this->assertSame(0.0, $response['totals']['available_ref']);
    }

    public function test_index_retourne_les_bonnes_devises(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->createWallet($u, 'EUR', '1500.00', '200.00', '100.00', '50.00', '25.00');
        $this->createWallet($u, 'XAF', '100000.00');
        $this->createWallet($u, 'USDT', '250.00', '30.00');

        $result = $this->runController('index', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $byCurrency = [];
        foreach ($response['wallets'] as $wallet) {
            $byCurrency[$wallet['currency']] = $wallet;
        }

        $this->assertSame(1500.0, $byCurrency['EUR']['balance']);
        $this->assertSame(1300.0, $byCurrency['EUR']['available']);
        $this->assertSame(100.0,  $byCurrency['EUR']['pending']);
        $this->assertSame(50.0,   $byCurrency['EUR']['in_transit']);
        $this->assertSame(25.0,   $byCurrency['EUR']['settlement']);
        $this->assertTrue($byCurrency['EUR']['has_funds']);

        $this->assertSame(100000.0, $byCurrency['XAF']['balance']);
        $this->assertSame(100000.0, $byCurrency['XAF']['available']);
        $this->assertSame(0.0,      $byCurrency['XAF']['pending']);
        $this->assertSame(0.0,      $byCurrency['XAF']['in_transit']);
        $this->assertSame(0.0,      $byCurrency['XAF']['settlement']);
        $this->assertTrue($byCurrency['XAF']['has_funds']);

        $this->assertSame(250.0, $byCurrency['USDT']['balance']);
        $this->assertSame(220.0, $byCurrency['USDT']['available']);
        $this->assertSame(0.0,   $byCurrency['USDT']['pending']);
        $this->assertSame(0.0,   $byCurrency['USDT']['in_transit']);
        $this->assertSame(0.0,   $byCurrency['USDT']['settlement']);
        $this->assertTrue($byCurrency['USDT']['has_funds']);

        foreach (['USD', 'GBP', 'USDC'] as $missingCurrency) {
            $this->assertSame(0.0, $byCurrency[$missingCurrency]['balance']);
            $this->assertSame(0.0, $byCurrency[$missingCurrency]['available']);
            $this->assertFalse($byCurrency[$missingCurrency]['has_funds']);
        }
    }

    // ---- Tests : available_balance -----------------------------------------

    public function test_index_available_balance_est_balance_moins_hold(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->createWallet($u, 'EUR', '500.00', '120.00');

        $result = $this->runController('index', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $eur = $response['wallets'][0];
        $this->assertSame('EUR', $eur['currency']);
        $this->assertSame(500.0, $eur['balance']);
        $this->assertSame(380.0, $eur['available']);
    }

    public function test_index_sans_hold_retourne_available_egal_a_balance(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->createWallet($u, 'EUR', '1000.00');

        $result = $this->runController('index', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $eur = $response['wallets'][0];
        $this->assertSame(1000.0, $eur['balance']);
        $this->assertSame(1000.0, $eur['available']);
    }

    // ---- Tests : ref_equivalent et totals ----------------------------------

    public function test_index_calcule_ref_equivalent_en_eur(): void
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
        $this->createWallet($u, 'USD', '100.00');

        $result = $this->runController('index', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $byCurrency = [];
        foreach ($response['wallets'] as $wallet) {
            $byCurrency[$wallet['currency']] = $wallet;
        }

        $this->assertSame(100.0, $byCurrency['EUR']['ref_equivalent']);
        $this->assertSame(92.0,  $byCurrency['USD']['ref_equivalent']);
        $this->assertSame(192.0, $response['totals']['total_ref']);
        $this->assertSame(192.0, $response['totals']['available_ref']);

        // Nettoyage du taux de test.
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE id = ?')->execute([$fxRow]);
    }

    public function test_index_currencies_with_funds(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->createWallet($u, 'EUR', '100.00');
        $this->createWallet($u, 'USD', '0.00');
        $this->createWallet($u, 'GBP', '50.00');

        $result = $this->runController('index', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $this->assertSame(2, $response['totals']['with_funds']);
        $this->assertSame(6, $response['totals']['currencies']);
    }

    // ---- Tests : isolation utilisateur -------------------------------------

    public function test_index_ne_renvoie_pas_les_wallets_d_un_autre_utilisateur(): void
    {
        $uA = $this->createUser($this->uniqueSuffix());
        $uB = $this->createUser($this->uniqueSuffix());

        $this->createWallet($uA, 'EUR', '999.99');
        $this->createWallet($uB, 'EUR', '1.00');

        $resultA = $this->runController('index', $uA);
        $resultB = $this->runController('index', $uB);

        $dataA = json_decode($resultA['output'], true);
        $dataB = json_decode($resultB['output'], true);

        $this->assertSame(999.99, $dataA['data']['wallets'][0]['balance']);
        $this->assertSame(1.00, $dataB['data']['wallets'][0]['balance']);
    }

    // ---- Tests : plusieurs devises -----------------------------------------

    public function test_index_avec_plusieurs_devises_remplit_la_grille_complete(): void
    {
        $u = $this->createUser($this->uniqueSuffix());

        $this->createWallet($u, 'EUR',  '100.00');
        $this->createWallet($u, 'USD',  '200.00');
        $this->createWallet($u, 'GBP',  '300.00');
        $this->createWallet($u, 'XAF',  '10000.00');
        $this->createWallet($u, 'USDT', '500.00');
        $this->createWallet($u, 'USDC', '600.00');

        $result = $this->runController('index', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $this->assertCount(6, $response['wallets']);

        $byCurrency = [];
        foreach ($response['wallets'] as $wallet) {
            $byCurrency[$wallet['currency']] = $wallet;
        }

        $this->assertSame(100.0, $byCurrency['EUR']['balance']);
        $this->assertSame(200.0, $byCurrency['USD']['balance']);
        $this->assertSame(300.0, $byCurrency['GBP']['balance']);
        $this->assertSame(10000.0, $byCurrency['XAF']['balance']);
        $this->assertSame(500.0, $byCurrency['USDT']['balance']);
        $this->assertSame(600.0, $byCurrency['USDC']['balance']);

        foreach ($response['wallets'] as $wallet) {
            $this->assertTrue($wallet['has_funds']);
        }
    }

    // ---- Tests : structure JSON --------------------------------------------

    public function test_index_structure_json_compatible(): void
    {
        $u = $this->createUser($this->uniqueSuffix());
        $this->createWallet($u, 'EUR', '250.00', '50.00');

        $result = $this->runController('index', $u);
        $data = json_decode($result['output'], true);
        $response = $data['data'];

        $this->assertArrayHasKey('ref_currency', $response);
        $this->assertArrayHasKey('totals', $response);
        $this->assertArrayHasKey('wallets', $response);

        $totals = $response['totals'];
        $this->assertArrayHasKey('ref_currency', $totals);
        $this->assertArrayHasKey('total_ref', $totals);
        $this->assertArrayHasKey('available_ref', $totals);
        $this->assertArrayHasKey('pending_ref', $totals);
        $this->assertArrayHasKey('in_transit_ref', $totals);
        $this->assertArrayHasKey('settlement_ref', $totals);
        $this->assertArrayHasKey('currencies', $totals);
        $this->assertArrayHasKey('with_funds', $totals);

        $wallet = $response['wallets'][0];
        $this->assertArrayHasKey('currency', $wallet);
        $this->assertArrayHasKey('balance', $wallet);
        $this->assertArrayHasKey('available', $wallet);
        $this->assertArrayHasKey('pending', $wallet);
        $this->assertArrayHasKey('in_transit', $wallet);
        $this->assertArrayHasKey('settlement', $wallet);
        $this->assertArrayHasKey('ref_equivalent', $wallet);
        $this->assertArrayHasKey('has_funds', $wallet);

        $this->assertIsString($wallet['currency']);
        $this->assertIsFloat($wallet['balance']);
        $this->assertIsFloat($wallet['available']);
        $this->assertIsFloat($wallet['pending']);
        $this->assertIsFloat($wallet['in_transit']);
        $this->assertIsFloat($wallet['settlement']);
        $this->assertIsFloat($wallet['ref_equivalent']);
        $this->assertIsBool($wallet['has_funds']);
    }

    // ---- Tests : authentification ------------------------------------------

    public function test_index_sans_token_retourne_401(): void
    {
        $u = $this->createUser($this->uniqueSuffix());

        // Exécute avec un token invalide (explicite) pour obtenir un 401.
        $result = $this->runController('index', $u, [], '', 'invalid-token');
        $this->assertSame(401, $result['status'], 'HTTP 401 attendu pour token invalide.');
    }

    // ---- Tests : confirmation base nexus_test ------------------------------

    public function test_confirmation_base_nexus_test(): void
    {
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->assertSame('nexus_test', $dbName, 'La base active doit etre nexus_test.');
        $this->assertNotSame('nexus',   $dbName, 'La base ne doit jamais etre nexus (prod/dev).');
    }
}
