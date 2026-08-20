<?php

declare(strict_types=1);

/**
 * Front controller de l'API NEXUS.
 *
 * Charge la configuration, enregistre les routes puis exécute le routeur.
 * Toutes les routes publiques sont préfixées /api (ex. GET /api/health).
 */

use Nexus\Controllers\AccountController;
use Nexus\Controllers\AdminController;
use Nexus\Controllers\AuthController;
use Nexus\Controllers\BeneficiaryController;
use Nexus\Controllers\BusinessController;
use Nexus\Controllers\DashboardController;
use Nexus\Controllers\FundingController;
use Nexus\Controllers\IntentController;
use Nexus\Controllers\ControlCenterController;
use Nexus\Controllers\MaintenanceController;
use Nexus\Controllers\KycController;
use Nexus\Controllers\NotificationController;
use Nexus\Controllers\PaymentController;
use Nexus\Controllers\ProviderCredentialController;
use Nexus\Controllers\ProviderWebhookController;
use Nexus\Controllers\QuoteController;
use Nexus\Controllers\ReconciliationController;
use Nexus\Controllers\StaffChatController;
use Nexus\Controllers\SupportController;
use Nexus\Controllers\TeamController;
use Nexus\Controllers\TransferController;
use Nexus\Controllers\UserController;
use Nexus\Controllers\VirtualCardController;
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
$router->post('/logout', [AuthController::class, 'logout']);
$router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/auth/reset-password', [AuthController::class, 'resetPassword']);
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
$router->post('/wallets/topup', [WalletController::class, 'topUp']);

// funding : webhook public HMAC + routes authentifiées (proposals / collect).
$router->post('/funding/deposit', [FundingController::class, 'deposit']);
$router->post('/funding/intents', [FundingController::class, 'createIntent']);
$router->get('/funding/proposals', [FundingController::class, 'proposals']);
$router->get('/funding/payment-methods', [FundingController::class, 'paymentMethods']);
$router->post('/funding/collect', [FundingController::class, 'collect']);

// --- Wallet : Hold Lifecycle (protégé) ---------------------------------------
$router->get('/wallets/holds', [WalletController::class, 'pendingHolds']);
// Conversion entre deux wallets du même utilisateur. Relie l'interface au
// moteur transferMultiCurrency, jusqu'ici appelé uniquement par les tests :
// le bouton « Convertir » simulait un succès sans mouvement d'argent.
$router->post('/wallets/convert', [WalletController::class, 'convert']);
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
$router->get('/providers/status', [ProviderCredentialController::class, 'status']);
$router->get('/providers/credentials', [ProviderCredentialController::class, 'list']);
$router->put('/providers/{slug}/credentials', [ProviderCredentialController::class, 'upsert']);
$router->delete('/providers/{slug}/credentials', [ProviderCredentialController::class, 'delete']);
$router->post('/providers/{slug}/test', [ProviderCredentialController::class, 'test']);
// webhook provider : route PUBLIQUE — authentifiée par SIGNATURE HMAC (§13).
$router->post('/providers/webhook/{slug}', [ProviderWebhookController::class, 'handle']);

// --- NEXUS CONTROL CENTER : plan de contrôle de l'infrastructure -----------
// Accès restreint côté SERVEUR (l'UI n'est jamais une couche de sécurité).
$router->get('/control/access', [ControlCenterController::class, 'access']);
$router->get('/control/staff/dashboard', [ControlCenterController::class, 'staffDashboard']);
$router->post('/control/staff/action', [ControlCenterController::class, 'staffAction']);
$router->get('/control/staff/directory', [StaffChatController::class, 'directory']);
$router->get('/control/staff/chats', [StaffChatController::class, 'chats']);
$router->post('/control/staff/chats', [StaffChatController::class, 'createChat']);
$router->get('/control/staff/chats/{id}/messages', [StaffChatController::class, 'messages']);
$router->post('/control/staff/chats/{id}/messages', [StaffChatController::class, 'sendMessage']);
$router->post('/control/staff/chats/{id}/read', [StaffChatController::class, 'markRead']);
$router->get('/control/overview', [ControlCenterController::class, 'overview']);
$router->get('/control/providers', [ControlCenterController::class, 'providers']);
$router->get('/control/providers/{slug}', [ControlCenterController::class, 'providerDetail']);
$router->get('/control/credentials', [ControlCenterController::class, 'credentials']);
$router->get('/control/public-keys', [ControlCenterController::class, 'publicKeys']);
$router->get('/control/kyc', [ControlCenterController::class, 'kyc']);
$router->post('/control/kyc/override', [ControlCenterController::class, 'kycOverride']);
$router->get('/control/webhooks', [ControlCenterController::class, 'webhooks']);
$router->get('/control/audit', [ControlCenterController::class, 'audit']);
$router->get('/control/clients', [ControlCenterController::class, 'clients']);
$router->get('/control/clients/linked', [ControlCenterController::class, 'linkedClients']);
$router->get('/control/clients/{id}', [ControlCenterController::class, 'clientDetail']);
$router->post('/control/clients/{id}/status', [ControlCenterController::class, 'clientStatus']);

// --- Super Admin : employés internes + comptes Nexus Connect ---
$router->get('/control/employees', [AdminController::class, 'employees']);
$router->post('/control/employees', [AdminController::class, 'createEmployee']);
$router->post('/control/employees/{id}/invite', [AdminController::class, 'inviteEmployee']);
$router->put('/control/employees/{id}', [AdminController::class, 'updateEmployee']);
$router->patch('/control/employees/{id}/status', [AdminController::class, 'setEmployeeStatus']);
$router->get('/control/connect/accounts', [AdminController::class, 'connectAccounts']);
$router->post('/control/connect/accounts', [AdminController::class, 'createConnectAccount']);
$router->get('/admin/overview', [AdminController::class, 'overview']);
$router->get('/admin/transactions', [AdminController::class, 'transactions']);
$router->get('/admin/operations', [AdminController::class, 'operations']);
$router->get('/admin/risk', [AdminController::class, 'risk']);
$router->get('/admin/technical', [AdminController::class, 'technical']);

// Maintenance d'exploitation : le diagnostic est en lecture seule (capacité
// « operations »), la reprise modifie des paiements réels (capacité
// « maintenance » + confirmation explicite).
$router->get('/control/maintenance/stuck-payments', [MaintenanceController::class, 'stuckPayments']);
$router->post('/control/maintenance/recover-payments', [MaintenanceController::class, 'recoverPayments']);

// --- KYC / KYB : vérification d'identité (Sumsub) --------------------------
// status/session : protégés (JWT).
$router->get('/kyc/status', [KycController::class, 'status']);
$router->post('/kyc/session', [KycController::class, 'session']);
// webhook : route PUBLIQUE — l'authentification se fait par SIGNATURE HMAC,
// pas par JWT (le provider n'a pas de session utilisateur).
$router->post('/kyc/webhook', [KycController::class, 'webhook']);

// --- Intent Engine : couverture pays/modes/devises pour /send (protégé) ----
$router->get('/intent/countries', [IntentController::class, 'countries']);
$router->get('/intent/authorized-origins', [IntentController::class, 'authorizedOrigins']);

// --- Quote & Routing Engine : devises multi-providers (protégé) ----------
$router->post('/quotes', [QuoteController::class, 'create']);
$router->post('/quotes/convert', [QuoteController::class, 'createConvert']);
$router->get('/quotes/{id}', [QuoteController::class, 'get']);

// --- Transfer Execution : saga réelle (protégé) -----------------------------
$router->post('/transfers', [TransferController::class, 'execute']);
$router->get('/transfers', [TransferController::class, 'index']);
$router->get('/transfers/{id}', [TransferController::class, 'show']);

// --- Business : bénéficiaires (protégé) -------------------------------------
$router->get('/beneficiaries', [BeneficiaryController::class, 'index']);
$router->post('/beneficiaries', [BeneficiaryController::class, 'create']);
$router->put('/beneficiaries/{id}', [BeneficiaryController::class, 'update']);
$router->post('/beneficiaries/{id}/deactivate', [BeneficiaryController::class, 'deactivate']);
$router->post('/beneficiaries/{id}/activate', [BeneficiaryController::class, 'activate']);
$router->post('/beneficiaries/{id}/verify', [BeneficiaryController::class, 'verify']);

// --- Business : paiements + approbations (protégé) --------------------------
$router->get('/payments', [PaymentController::class, 'index']);
$router->post('/payments', [PaymentController::class, 'create']);
$router->get('/payments/{id}', [PaymentController::class, 'show']);
$router->post('/payments/{id}/submit', [PaymentController::class, 'submit']);
$router->post('/payments/{id}/approve', [PaymentController::class, 'approve']);
$router->post('/payments/{id}/reject', [PaymentController::class, 'reject']);
$router->post('/payments/{id}/execute', [PaymentController::class, 'execute']);
$router->post('/payments/{id}/cancel', [PaymentController::class, 'cancel']);

// --- Business : équipe & rôles (protégé) ------------------------------------
$router->get('/team', [TeamController::class, 'index']);
$router->post('/team', [TeamController::class, 'add']);
$router->put('/team/{id}', [TeamController::class, 'update']);
$router->delete('/team/{id}', [TeamController::class, 'remove']);

// --- Business : rapprochement (protégé) -------------------------------------
// --- Support chat (tickets / conversations) --------------------------------
$router->post('/support/bot', [SupportController::class, 'bot']);
$router->post('/support/attachments', [SupportController::class, 'uploadAttachment']);
$router->get('/support/unread', [SupportController::class, 'unread']);
$router->get('/support/conversations', [SupportController::class, 'conversations']);
$router->post('/support/conversations', [SupportController::class, 'createConversation']);
$router->get('/support/conversations/{id}/messages', [SupportController::class, 'messages']);
$router->post('/support/conversations/{id}/messages', [SupportController::class, 'sendMessage']);
$router->patch('/support/conversations/{id}/status', [SupportController::class, 'setStatus']);

$router->get('/reconciliation', [ReconciliationController::class, 'index']);
$router->post('/reconciliation', [ReconciliationController::class, 'upsert']);
$router->post('/reconciliation/{id}/resolve', [ReconciliationController::class, 'resolve']);

// --- Business : console financière (protégé) --------------------------------
$router->get('/business/overview', [BusinessController::class, 'overview']);
$router->get('/business/treasury', [BusinessController::class, 'treasury']);
$router->get('/business/analytics', [BusinessController::class, 'analytics']);

// --- User Profile (protégé) ----------------------------------------------------
$router->get('/users/me', [UserController::class, 'me']);
$router->put('/users/me', [UserController::class, 'updateProfile']);
$router->put('/users/me/password', [UserController::class, 'updatePassword']);
$router->get('/users/me/sessions', [UserController::class, 'sessions']);
$router->delete('/users/me/sessions/{id}', [UserController::class, 'revokeSession']);

// Cartes virtuelles — demandes (émission réelle via card_issuing ultérieure)
$router->get('/cards', [VirtualCardController::class, 'index']);
$router->post('/cards', [VirtualCardController::class, 'create']);

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
