<?php

declare(strict_types=1);

/**
 * Front controller de l'API NEXUS.
 *
 * Charge la configuration, enregistre les routes puis exécute le routeur.
 * Toutes les routes publiques sont préfixées /api (ex. GET /api/health).
 */

use Nexus\Controllers\AccountController;
use Nexus\Controllers\AuthController;
use Nexus\Controllers\DashboardController;
use Nexus\Controllers\IntentController;
use Nexus\Controllers\NotificationController;
use Nexus\Controllers\ProviderCredentialController;
use Nexus\Controllers\QuoteController;
use Nexus\Controllers\TransferController;
use Nexus\Controllers\UserController;
use Nexus\Controllers\WalletController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\Router;

// --- Configuration globale --------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');   // Le JSON ne doit jamais être corrompu.
date_default_timezone_set('UTC');

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/config/env.php';
require BASE_PATH . '/config/app.php';
require BASE_PATH . '/config/constants.php';
require BASE_PATH . '/config/database.php';

// --- Autoloader simple (Nexus\Foo\Bar -> src/Foo/Bar.php) --------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'Nexus\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Les erreurs PHP (warnings/notices) deviennent des exceptions interceptables.
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// --- Fail-closed : identifiants DB requis hors développement -------------------
if (!defined('DB_USER') || !defined('DB_PASS') || !defined('JWT_SECRET')) {
    error_log('[NEXUS API] Configuration incomplète : DB_USER/DB_PASS/JWT_SECRET requis hors environnement de développement.');
    Response::serverError();
}

// --- CORS : liste d'origines autorisées ----------------------------------------
header_remove('X-Powered-By');   // Ne pas divulguer la version de PHP.

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = APP_ORIGINS;
if (APP_ENV === 'development') {
    // Origines du frontend Vite en développement.
    $allowedOrigins = array_merge($allowedOrigins, ['http://localhost:5173', 'http://127.0.0.1:5173']);
}

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Enregistrement des routes -------------------------------------------------
$router = new Router();

// GET /api/health — vérifie que la connexion PDO est opérationnelle.
$router->get('/health', static function (Request $request): void {
    $connected = Database::ping();

    Response::success([
        'status'    => $connected ? 'ok' : 'error',
        'db'        => $connected ? 'connected' : 'unavailable',
        'timestamp' => date(DATE_ATOM),
    ]);
});

// --- Authentification ----------------------------------------------------------
$router->post('/register', [AuthController::class, 'register']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/google', [AuthController::class, 'google']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/me', [AuthController::class, 'me']);

// --- Dashboard (protégé) ------------------------------------------------------
$router->get('/dashboard/summary', [DashboardController::class, 'summary']);
$router->get('/dashboard/activity', [DashboardController::class, 'activity']);

// --- Notifications (protégé) --------------------------------------------------
$router->get('/notifications', [NotificationController::class, 'list']);
$router->get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
$router->post('/notifications/{id}/read', [NotificationController::class, 'readOne']);
$router->post('/notifications/read-all', [NotificationController::class, 'readAll']);

// --- Wallet (protégé) ---------------------------------------------------------
$router->get('/wallets', [WalletController::class, 'index']);
$router->get('/wallets/rates', [WalletController::class, 'rates']);
$router->get('/wallets/{currency}/transactions', [WalletController::class, 'transactions']);

// --- Wallet : Hold Lifecycle (protégé) ---------------------------------------
$router->get('/wallets/holds', [WalletController::class, 'pendingHolds']);
$router->post('/wallets/hold', [WalletController::class, 'hold']);
$router->post('/wallets/hold/capture', [WalletController::class, 'captureHold']);
$router->post('/wallets/hold/release', [WalletController::class, 'releaseHold']);

// --- Accounts : Sources & Destinations (protégé) ----------------------------
$router->get('/accounts', [AccountController::class, 'index']);
$router->post('/accounts', [AccountController::class, 'create']);
$router->put('/accounts/{id}', [AccountController::class, 'update']);
$router->delete('/accounts/{id}', [AccountController::class, 'delete']);
$router->post('/accounts/{id}/default', [AccountController::class, 'setDefault']);
$router->get('/accounts/operators', [AccountController::class, 'listOperators']);
$router->get('/accounts/networks', [AccountController::class, 'listNetworks']);
$router->get('/accounts/authorized-origins', [AccountController::class, 'authorizedOrigins']);

// --- Providers : catalogue + credentials chiffrées (protégé) ---------------
$router->get('/providers', [ProviderCredentialController::class, 'catalog']);
$router->get('/providers/credentials', [ProviderCredentialController::class, 'list']);
$router->put('/providers/{slug}/credentials', [ProviderCredentialController::class, 'upsert']);
$router->delete('/providers/{slug}/credentials', [ProviderCredentialController::class, 'delete']);
$router->post('/providers/{slug}/test', [ProviderCredentialController::class, 'test']);

// --- Intent Engine : couverture pays/modes/devises pour /send (protégé) ----
$router->get('/intent/countries', [IntentController::class, 'countries']);
$router->get('/intent/authorized-origins', [IntentController::class, 'authorizedOrigins']);

// --- Quote & Routing Engine : devises multi-providers (protégé) ----------
$router->post('/quotes', [QuoteController::class, 'create']);
$router->get('/quotes/{id}', [QuoteController::class, 'get']);

// --- Transfer Execution : saga réelle (protégé) -----------------------------
$router->post('/transfers', [TransferController::class, 'execute']);
$router->get('/transfers', [TransferController::class, 'index']);
$router->get('/transfers/{id}', [TransferController::class, 'show']);

// --- User Profile (protégé) ----------------------------------------------------
$router->get('/users/me', [UserController::class, 'me']);
$router->put('/users/me', [UserController::class, 'updateProfile']);
$router->put('/users/me/password', [UserController::class, 'updatePassword']);
$router->get('/users/me/sessions', [UserController::class, 'sessions']);
$router->delete('/users/me/sessions/{id}', [UserController::class, 'revokeSession']);

// --- Exécution ------------------------------------------------------------------
try {
    $request = new Request();
    $router->dispatch($request);
} catch (HttpException $e) {
    Response::error($e->getMessage(), $e->statusCode(), $e->errorCode());
} catch (Throwable $e) {
    // Détail technique en log serveur uniquement, jamais au client.
    error_log('[NEXUS API] ' . $e->getMessage());
    Response::serverError();
}
