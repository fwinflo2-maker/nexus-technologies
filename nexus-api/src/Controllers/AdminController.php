<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Execution\ExecutionContext;
use Nexus\Execution\PlatformRole;

/**
 * NEXUS SUPER ADMIN — administration des comptes internes & Connect.
 *
 * Réservé au SUPERADMIN (le seul rôle habilité à créer/gérer les employés
 * internes et les comptes Nexus Connect B2B/API). Chaque action est tracée
 * dans `audit_logs`. Le backend est l'autorité : aucun frontend ne contourne
 * ces vérifications.
 */
final class AdminController
{
    private const EMPLOYEE_INVITE_TTL = 1800;
    /** Rôles internes autorisés à être attribués à un employé. */
    private const ALLOWED_EMPLOYEE_ROLES = [
        'operations_manager',
        'treasury_manager',
        'compliance_officer',
        'risk_analyst',
        'provider_manager',
        'customer_support',
        'security_admin',
        'technical_admin',
        'business_manager',
        'superadmin',
    ];

    private static function authorize(Request $request): array
    {
        $request = AuthMiddleware::handle($request);
        $user = $request->attribute('user');
        PlatformRole::require($user, 'superadmin');
        return $user;
    }

    /** @return array{0: array<string,mixed>, 1: \Nexus\Execution\ExecutionEnvironment} */
    private static function authorizeEnvironment(Request $request): array
    {
        $user = self::authorize($request);
        return [$user, ExecutionContext::fromRequest($request, $user)->environment];
    }

    private static function audit(\PDO $pdo, int $adminId, string $action, ?string $entityType, ?int $entityId, array $metadata): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata)
             VALUES (:user_id, :action, :entity_type, :entity_id, :metadata)'
        );
        $stmt->execute([
            'user_id'     => $adminId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'metadata'    => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** GET /api/control/employees — liste des employés internes. */
    public static function employees(Request $request): void
    {
        $user = self::authorize($request);
        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            'SELECT e.id, e.user_id, u.full_name, u.email, u.status AS user_status,
                    e.department, u.platform_role AS role, e.status, e.last_login_at, e.created_at
             FROM employees e
             JOIN users u ON u.id = e.user_id
             ORDER BY e.created_at DESC'
        );
        $rows = array_map(static function (array $r): array {
            $r['authorization_model'] = 'platform_role';
            return $r;
        }, $stmt->fetchAll());

        Response::success(['items' => $rows, 'total' => count($rows)]);
    }

    /**
     * POST /api/control/employees — crée un employé interne.
     *
     * Workflow : Super Admin → créé l'employé (lien vers un compte users) →
     * attribue role/department. L'autorisation est exclusivement dérivée de
     * users.platform_role ; employees.permissions est un champ historique
     * non lu et non écrit. Le mot de passe n'est jamais
     * transmis ici : l'utilisateur est créé via le flux standard d'activation.
     */
    public static function createEmployee(Request $request): void
    {
        $user = self::authorize($request);
        $pdo = Database::getConnection();

        $fullName = trim((string) $request->input('full_name', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $role = (string) $request->input('role', 'operations_manager');
        $department = trim((string) $request->input('department', ''));

        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::badRequest('Nom et email valide requis.');
        }
        if (!in_array($role, self::ALLOWED_EMPLOYEE_ROLES, true)) {
            Response::badRequest('Rôle employé invalide.');
        }

        try {
            $pdo->beginTransaction();

            // Crée (ou retrouve) le compte users rattaché à l'employé.
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $uid = $stmt->fetchColumn();

            if ($uid === false) {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, email, password_hash, account_type, platform_role, status, kyc_level)
                     VALUES (:full_name, :email, :password_hash, :account_type, :platform_role, :status, :kyc_level)'
                );
                $stmt->execute([
                    'full_name'     => $fullName,
                    'email'         => $email,
                    'password_hash' => '', // activation ultérieure — jamais de mot de passe en clair
                    'account_type'  => 'personal',
                    'platform_role' => $role,
                    'status'        => 'PENDING',
                    'kyc_level'     => 'none',
                ]);
                $uid = (int) $pdo->lastInsertId();
            } else {
                $uid = (int) $uid;
                $existing = $pdo->prepare(
                    'SELECT platform_role FROM users WHERE id = :id LIMIT 1'
                );
                $existing->execute(['id' => $uid]);
                $currentRole = (string) ($existing->fetchColumn() ?: 'user');
                // Refus : promouvoir silencieusement un client (ou changer un
                // employé existant) via createEmployee. Création = nouvel
                // utilisateur uniquement ; promotion = flux dédié.
                if ($currentRole === 'user' || $currentRole === '') {
                    throw new \RuntimeException(
                        'EMPLOYEE_PROMOTE_FORBIDDEN: un compte client existant ne peut pas être promu via createEmployee.'
                    );
                }
                if ($role === 'superadmin' && $currentRole !== 'superadmin') {
                    throw new \RuntimeException(
                        'EMPLOYEE_SUPERADMIN_FORBIDDEN: l\'attribution de superadmin exige un flux dédié.'
                    );
                }
                $pdo->prepare('UPDATE users SET platform_role = :role WHERE id = :id')
                    ->execute(['role' => $role, 'id' => $uid]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO employees (user_id, department, role, permissions, status)
                 VALUES (:user_id, :department, :role, NULL, :status)'
            );
            $stmt->execute([
                'user_id'     => $uid,
                'department'  => $department !== '' ? $department : null,
                'role'        => $role,
                'status'      => 'invited',
            ]);
            $employeeId = (int) $pdo->lastInsertId();

            self::audit($pdo, (int) $user['id'], 'EMPLOYEE_CREATED', 'employees', $employeeId, [
                'email' => $email, 'role' => $role, 'department' => $department,
            ]);

            $pdo->commit();
            Response::success(['id' => $employeeId, 'user_id' => $uid, 'status' => 'invited'], 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_starts_with($e->getMessage(), 'EMPLOYEE_PROMOTE_FORBIDDEN')) {
                Response::conflict('Impossible de promouvoir un compte client existant via createEmployee. Créez un nouvel email employé.');
            }
            if (str_starts_with($e->getMessage(), 'EMPLOYEE_SUPERADMIN_FORBIDDEN')) {
                Response::forbidden('Attribution de superadmin refusée via createEmployee.');
            }
            throw $e;
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                Response::conflict('Cet employé existe déjà.');
            }
            throw $e;
        }
    }

    /**
     * POST /api/control/employees/{id}/invite — génère un lien d'activation.
     *
     * Le secret brut n'est jamais persisté. Il est retourné uniquement en
     * développement afin de permettre le parcours sans service de messagerie;
     * en production il doit être remis par le canal d'invitation configuré.
     */
    public static function inviteEmployee(Request $request): void
    {
        $actor = self::authorize($request);
        $pdo = Database::getConnection();
        $id = (int) $request->param('id', '0');
        if ($id <= 0) {
            Response::badRequest('Identifiant employé invalide.');
        }

        $employee = $pdo->prepare(
            'SELECT e.user_id, e.status, u.email FROM employees e JOIN users u ON u.id = e.user_id WHERE e.id = :id LIMIT 1'
        );
        $employee->execute(['id' => $id]);
        $row = $employee->fetch();
        if ($row === false) {
            Response::notFound('Employé introuvable.');
        }
        if ((string) $row['status'] === 'disabled') {
            Response::conflict('Un employé désactivé ne peut pas être invité.');
        }

        $token = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', time() + self::EMPLOYEE_INVITE_TTL);
        $pdo->beginTransaction();
        try {
            // Une seule invitation active par utilisateur : une réémission
            // invalide de manière atomique le lien précédent.
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')
                ->execute(['user_id' => (int) $row['user_id']]);
            $pdo->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
                 VALUES (:user_id, :token_hash, :expires_at, :created_at)'
            )->execute([
                'user_id' => (int) $row['user_id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expires,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            self::audit($pdo, (int) $actor['id'], 'EMPLOYEE_INVITED', 'employees', $id, [
                'user_id' => (int) $row['user_id'],
                'email' => (string) $row['email'],
                'expires_at' => $expires,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $isDevelopment = defined('APP_ENV') && APP_ENV === 'development';
        Response::success([
            'employee_id' => $id,
            'expires_in' => self::EMPLOYEE_INVITE_TTL,
            'reset_token' => $isDevelopment ? $token : null,
            'reset_url' => $isDevelopment ? '/forgot-password?token=' . $token : null,
            'delivery' => $isDevelopment ? 'DEVELOPMENT_MANUAL_DELIVERY' : 'PENDING_EMAIL_DELIVERY',
        ]);
    }

    /** PATCH /api/control/employees/{id}/status — active/désactive un employé. */
    public static function setEmployeeStatus(Request $request): void
    {
        $user = self::authorize($request);
        $pdo = Database::getConnection();
        $id = (int) $request->param('id', '0');
        $status = (string) $request->input('status', '');

        if (!in_array($status, ['active', 'invited', 'disabled'], true)) {
            Response::badRequest('Statut invalide.');
        }

        $stmt = $pdo->prepare('UPDATE employees SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);

        // Synchronise le statut du compte users rattaché.
        $emp = $pdo->prepare('SELECT user_id FROM employees WHERE id = :id');
        $emp->execute(['id' => $id]);
        $uid = $emp->fetchColumn();
        if ($uid !== false) {
            $pdo->prepare('UPDATE users SET status = :s WHERE id = :id')
                ->execute(['s' => $status === 'active' ? 'ACTIVE' : ($status === 'disabled' ? 'SUSPENDED' : 'PENDING'), 'id' => (int) $uid]);
        }

        self::audit($pdo, (int) $user['id'], strtoupper('EMPLOYEE_' . $status), 'employees', $id, ['status' => $status]);

        Response::success(['id' => $id, 'status' => $status]);
    }

    /** PUT /api/control/employees/{id} — met à jour rôle/département. */
    public static function updateEmployee(Request $request): void
    {
        $user = self::authorize($request);
        $pdo = Database::getConnection();
        $id = (int) $request->param('id', '0');

        $role = (string) $request->input('role', '');
        $department = $request->input('department');

        $updates = [];
        $params = [':id' => $id];

        if ($role !== '') {
            if (!in_array($role, self::ALLOWED_EMPLOYEE_ROLES, true)) {
                Response::badRequest('Rôle invalide.');
            }
            $updates[] = 'role = :role';
            $params[':role'] = $role;
        }
        if ($department !== null) {
            $updates[] = 'department = :department';
            $params[':department'] = $department !== '' ? $department : null;
        }
        if (empty($updates)) {
            Response::badRequest('Aucune modification.');
        }

        $pdo->prepare('UPDATE employees SET ' . implode(', ', $updates) . ' WHERE id = :id')
            ->execute($params);

        // Synchronise platform_role si le rôle a changé.
        if ($role !== '') {
            $emp = $pdo->prepare('SELECT user_id FROM employees WHERE id = :id');
            $emp->execute(['id' => $id]);
            $uid = $emp->fetchColumn();
            if ($uid !== false) {
                $pdo->prepare('UPDATE users SET platform_role = :role WHERE id = :id')
                    ->execute(['role' => $role, 'id' => (int) $uid]);
            }
        }

        self::audit($pdo, (int) $user['id'], 'ROLE_CHANGED', 'employees', $id, ['role' => $role]);

        Response::success(['id' => $id, 'updated' => true]);
    }

    /** GET /api/control/connect/accounts — liste des comptes Nexus Connect. */
    public static function connectAccounts(Request $request): void
    {
        self::authorize($request);
        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            'SELECT id, user_id, company_name, email, status, environment,
                    api_key_prefix, webhook_url, country, created_at, updated_at
             FROM connect_accounts
             ORDER BY created_at DESC'
        );
        // Le secret webhook et le hash de clé ne sont JAMAIS renvoyés.
        Response::success(['items' => $stmt->fetchAll(), 'total' => $stmt->rowCount()]);
    }

    /** POST /api/control/connect/accounts — crée un compte Connect. */
    public static function createConnectAccount(Request $request): void
    {
        $user = self::authorize($request);
        $pdo = Database::getConnection();

        $company = trim((string) $request->input('company_name', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $country = strtoupper(trim((string) $request->input('country', '')));
        $environment = (string) $request->input('environment', 'sandbox');
        $userId = (int) $request->input('user_id', 0);

        if ($company === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::badRequest('Entreprise et email valide requis.');
        }
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            Response::badRequest('Environnement invalide.');
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO connect_accounts (user_id, company_name, email, status, environment, country)
                 VALUES (:user_id, :company_name, :email, :status, :environment, :country)'
            );
            $stmt->execute([
                'user_id'      => $userId > 0 ? $userId : null,
                'company_name' => $company,
                'email'        => $email,
                'status'       => 'active',
                'environment'  => $environment,
                'country'      => $country !== '' && strlen($country) === 2 ? $country : null,
            ]);
            $id = (int) $pdo->lastInsertId();

            self::audit($pdo, (int) $user['id'], 'CONNECT_ACCOUNT_CREATED', 'connect_accounts', $id, [
                'company' => $company, 'email' => $email, 'environment' => $environment,
            ]);

            Response::success(['id' => $id, 'status' => 'active'], 201);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::conflict('Ce compte Connect existe déjà.');
            }
            throw $e;
        }
    }

    /**
     * GET /api/admin/overview — tableau de bord Super Admin (données réelles).
     *
     * Agrège l'ensemble des activités de Nexus en un seul appel :
     * comptes, wallets, transactions, volumes, KYC, providers, audits.
     * Réservé au superadmin. Aucun secret n'est renvoyé.
     */
    public static function overview(Request $request): void
    {
        [, $environment] = self::authorizeEnvironment($request);
        $pdo = Database::getConnection();
        $env = $environment->value;

        // Comptes par type et statut.
        $accounts = [
            'total'     => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'personal'  => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE account_type='personal'")->fetchColumn(),
            'business'  => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE account_type='business'")->fetchColumn(),
            'active'    => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status='ACTIVE'")->fetchColumn(),
            'pending'   => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status='PENDING'")->fetchColumn(),
            'suspended' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status='SUSPENDED'")->fetchColumn(),
        ];
        $accounts['connect'] = (int) $pdo->query('SELECT COUNT(*) FROM connect_accounts')->fetchColumn();

        // Wallets & actifs.
        $wallets = (int) $pdo->query('SELECT COUNT(*) FROM wallets')->fetchColumn();
        $walletBal = $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN currency='EUR' THEN available_balance ELSE 0 END),0) eur,
                    COALESCE(SUM(CASE WHEN currency='USD' THEN available_balance ELSE 0 END),0) usd,
                    COALESCE(SUM(CASE WHEN currency='XAF' THEN available_balance ELSE 0 END),0) xaf
             FROM wallets"
        )->fetch();

        // Transactions & volumes.
        $txStmt = $pdo->prepare(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END),0) completed,
                    COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) failed,
                    COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) pending,
                    COALESCE(SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END),0) processing,
                    COALESCE(SUM(amount),0) volume
             FROM transactions WHERE environment = :environment"
        );
        $txStmt->execute(['environment' => $env]);
        $tx = $txStmt->fetch();
        $volumeStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_xaf),0) FROM transactions
             WHERE status='completed' AND environment = :environment"
        );
        $volumeStmt->execute(['environment' => $env]);
        $volumeXaf = (int) $volumeStmt->fetchColumn();

        // KYC.
        $kycStmt = $pdo->prepare(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(status IN ('pending','in_progress')),0) pending,
                    COALESCE(SUM(status='verified'),0) approved,
                    COALESCE(SUM(status='rejected'),0) rejected
             FROM kyc_verifications WHERE environment = :environment"
        );
        $kycStmt->execute(['environment' => $env]);
        $kycRow = $kycStmt->fetch() ?: [];
        $kyc = [
            'total'    => (int) ($kycRow['total'] ?? 0),
            'pending'  => (int) ($kycRow['pending'] ?? 0),
            'approved' => (int) ($kycRow['approved'] ?? 0),
            'rejected' => (int) ($kycRow['rejected'] ?? 0),
        ];

        // Providers.
        $providerStmt = $pdo->prepare(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(credentials_enc IS NOT NULL AND credentials_enc <> ''),0) configured
             FROM provider_credentials
             WHERE user_id IS NULL AND environment = :environment"
        );
        $providerStmt->execute(['environment' => $env]);
        $providerRow = $providerStmt->fetch() ?: [];
        $providers = [
            'total' => (int) ($providerRow['total'] ?? 0),
            'configured' => (int) ($providerRow['configured'] ?? 0),
        ];

        // Audit récent (activité).
        $recentAuditStmt = $pdo->prepare(
            'SELECT action, COUNT(*) AS count FROM audit_logs
             WHERE environment = :environment
             GROUP BY action ORDER BY count DESC LIMIT 8'
        );
        $recentAuditStmt->execute(['environment' => $env]);
        $recentAudit = $recentAuditStmt->fetchAll();

        // Série temporelle (14 jours) pour les graphiques : transactions + volume (EUR).
        $series = self::dailySeries($pdo, 14, $env);

        // Répartition par statut de transaction (donut).
        $statusStmt = $pdo->prepare(
            'SELECT status, COUNT(*) AS n FROM transactions
             WHERE environment = :environment GROUP BY status'
        );
        $statusStmt->execute(['environment' => $env]);
        $statusBreakdown = $statusStmt->fetchAll();
        $statusBreakdown = array_map(static fn (array $r) => ['status' => $r['status'], 'count' => (int) $r['n']], $statusBreakdown);

        // Répartition par provider (top providers).
        $providerTopStmt = $pdo->prepare(
            'SELECT provider, COUNT(*) AS n FROM transactions
             WHERE provider IS NOT NULL AND environment = :environment
             GROUP BY provider ORDER BY n DESC LIMIT 8'
        );
        $providerTopStmt->execute(['environment' => $env]);
        $providerTop = $providerTopStmt->fetchAll();
        $providerTop = array_map(static fn (array $r) => ['provider' => $r['provider'], 'count' => (int) $r['n']], $providerTop);

        Response::success([
            'accounts'   => $accounts,
            'environment' => $env,
            'wallets'    => $wallets,
            'assets'     => ['EUR' => $walletBal['eur'], 'USD' => $walletBal['usd'], 'XAF' => $walletBal['xaf']],
            'assets_basis' => 'available_balance',
            'assets_scope' => 'shared_wallet_projection',
            'transactions' => [
                'total' => (int) $tx['total'], 'completed' => (int) $tx['completed'],
                'failed' => (int) $tx['failed'], 'pending' => (int) $tx['pending'],
                'processing' => (int) $tx['processing'], 'volume_xaf' => (int) $volumeXaf,
            ],
            'kyc'        => $kyc,
            'providers'  => $providers,
            'recent_activity' => $recentAudit,
            'series' => [
                'transactions' => $series['transactions'],
                'volume_eur'   => $series['volume_eur'],
                'audit'        => $series['audit'],
            ],
            'status_breakdown' => $statusBreakdown,
            'provider_top'     => $providerTop,
            'generated_at' => gmdate(DATE_ATOM),
        ]);
    }

    /**
     * Série temporelle quotidienne sur N derniers jours (transactions, volume EUR, audit).
     *
     * @return array{transactions: array<int,array{date:string,count:int}>, volume_eur: array<int,array{date:string,volume:float}>, audit: array<int,array{date:string,count:int}>}
     */
    private static function dailySeries(\PDO $pdo, int $days, string $environment): array
    {
        $since = date('Y-m-d', strtotime("-{$days} days"));

        $txDays = $pdo->prepare(
            'SELECT DATE(created_at) d, COUNT(*) n, COALESCE(SUM(amount_ref),0) v
             FROM transactions
             WHERE created_at >= :since AND environment = :environment
             GROUP BY DATE(created_at) ORDER BY d ASC'
        );
        $txDays->execute(['since' => $since . ' 00:00:00', 'environment' => $environment]);
        $txMap = [];
        foreach ($txDays->fetchAll() as $r) {
            $txMap[$r['d']] = ['count' => (int) $r['n'], 'volume' => (float) $r['v']];
        }

        $auditDays = $pdo->prepare(
            'SELECT DATE(created_at) d, COUNT(*) n FROM audit_logs
             WHERE created_at >= :since AND environment = :environment
             GROUP BY DATE(created_at) ORDER BY d ASC'
        );
        $auditDays->execute(['since' => $since . ' 00:00:00', 'environment' => $environment]);
        $auditMap = [];
        foreach ($auditDays->fetchAll() as $r) {
            $auditMap[$r['d']] = (int) $r['n'];
        }

        $transactions = [];
        $volumeEur = [];
        $audit = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $transactions[] = ['date' => $date, 'count' => $txMap[$date]['count'] ?? 0];
            $volumeEur[]    = ['date' => $date, 'volume' => round($txMap[$date]['volume'] ?? 0.0, 2)];
            $audit[]        = ['date' => $date, 'count' => $auditMap[$date] ?? 0];
        }

        return ['transactions' => $transactions, 'volume_eur' => $volumeEur, 'audit' => $audit];
    }

    /** GET /api/admin/transactions — liste détaillée des transactions avec filtres. */
    public static function transactions(Request $request): void
    {
        [, $environment] = self::authorizeEnvironment($request);
        $pdo = Database::getConnection();
        $q   = $request->query();

        $where = ['t.environment = :environment'];
        $params = ['environment' => $environment->value];
        foreach (['status' => 'status', 'currency' => 'currency', 'type' => 'type', 'provider' => 'provider'] as $k => $col) {
            if (($v = trim((string) ($q[$k] ?? ''))) !== '') {
                $where[] = "t.{$col} = :{$k}";
                $params[$k] = $v;
            }
        }
        if (($search = trim((string) ($q['q'] ?? ''))) !== '') {
            $where[] = '(t.label LIKE :q OR t.description LIKE :q OR u.full_name LIKE :q OR u.email LIKE :q)';
            $params['q'] = "%{$search}%";
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $per  = min(50, max(10, (int) ($q['per'] ?? 25)));
        $offset = ($page - 1) * $per;

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = $pdo->prepare("SELECT COUNT(*) c FROM transactions t LEFT JOIN users u ON u.id = t.user_id {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT t.id, t.type, t.direction, t.label, t.description, t.amount, t.currency,
                    t.amount_xaf, t.dest_amount, t.dest_currency, t.fee, t.status, t.provider,
                    t.environment, t.execution_time_seconds, t.created_at,
                    u.full_name AS user_name, u.email AS user_email, u.account_type
             FROM transactions t
             LEFT JOIN users u ON u.id = t.user_id
             {$whereSql}
             ORDER BY t.created_at DESC
             LIMIT {$per} OFFSET {$offset}"
        );
        $stmt->execute($params);

        Response::success([
            'environment' => $environment->value,
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per' => $per,
            'pages' => max(1, (int) ceil($total / $per)),
        ]);
    }

    /** GET /api/admin/operations — file d'exécution (transactions non terminales). */
    public static function operations(Request $request): void
    {
        [, $environment] = self::authorizeEnvironment($request);
        $pdo = Database::getConnection();
        $env = $environment->value;

        $queueStmt = $pdo->prepare(
            "SELECT t.id, t.type, t.label, t.amount, t.currency, t.status, t.provider,
                    t.environment, t.execution_time_seconds, t.created_at,
                    u.full_name AS user_name, u.email AS user_email
             FROM transactions t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE t.environment = :environment AND t.status IN ('pending','processing')
             ORDER BY t.created_at DESC
             LIMIT 100"
        );
        $queueStmt->execute(['environment' => $env]);
        $queue = $queueStmt->fetchAll();

        $countStmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(status='pending'),0) pending,
                COALESCE(SUM(status='processing'),0) processing,
                COALESCE(SUM(status='completed'),0) completed,
                COALESCE(SUM(status='failed'),0) failed
             FROM transactions WHERE environment = :environment"
        );
        $countStmt->execute(['environment' => $env]);
        $countRow = $countStmt->fetch() ?: [];
        $counters = array_map('intval', $countRow);
        // Durée moyenne d'exécution des opérations terminées.
        $avgStmt = $pdo->prepare(
            "SELECT COALESCE(AVG(execution_time_seconds),0) FROM transactions
             WHERE environment = :environment AND status='completed'
               AND execution_time_seconds IS NOT NULL"
        );
        $avgStmt->execute(['environment' => $env]);
        $avgSecs = $avgStmt->fetchColumn();

        Response::success([
            'environment' => $env,
            'items' => $queue,
            'counters' => $counters,
            'avg_execution_seconds' => (float) $avgSecs,
        ]);
    }

    /** GET /api/admin/risk — indicateurs risque / fraude. */
    public static function risk(Request $request): void
    {
        [, $environment] = self::authorizeEnvironment($request);
        $pdo = Database::getConnection();
        $env = $environment->value;

        $txStmt = $pdo->prepare(
            "SELECT COUNT(*) total, COALESCE(SUM(status='failed'),0) failed
             FROM transactions WHERE environment = :environment"
        );
        $txStmt->execute(['environment' => $env]);
        $tx = $txStmt->fetch() ?: ['total' => 0, 'failed' => 0];
        $kycStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(status='rejected'),0) rejected,
                    COALESCE(SUM(status='resubmission_requested'),0) resubmission
             FROM kyc_verifications WHERE environment = :environment"
        );
        $kycStmt->execute(['environment' => $env]);
        $kyc = $kycStmt->fetch() ?: [];
        $risk = [
            'suspended_accounts' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status='SUSPENDED'")->fetchColumn(),
            'failed_transactions' => (int) $tx['failed'],
            'kyc_rejected' => (int) ($kyc['rejected'] ?? 0),
            'kyc_resubmission' => (int) ($kyc['resubmission'] ?? 0),
            'failed_rate' => 0.0,
        ];
        $tot = (int) $tx['total'];
        $risk['failed_rate'] = $tot > 0 ? round($risk['failed_transactions'] / $tot * 100, 1) : 0.0;

        // Transactions échouées récentes (à surveiller).
        $recentStmt = $pdo->prepare(
            "SELECT t.id, t.label, t.amount, t.currency, t.provider, t.created_at, u.email AS user_email
             FROM transactions t LEFT JOIN users u ON u.id = t.user_id
             WHERE t.environment = :environment AND t.status='failed'
             ORDER BY t.created_at DESC LIMIT 15"
        );
        $recentStmt->execute(['environment' => $env]);
        $recent = $recentStmt->fetchAll();

        // Par provider (taux d'échec).
        $providerStmt = $pdo->prepare(
            "SELECT provider,
                    COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) AS fails
             FROM transactions
             WHERE environment = :environment AND provider IS NOT NULL
             GROUP BY provider ORDER BY n DESC LIMIT 8"
        );
        $providerStmt->execute(['environment' => $env]);
        $byProvider = $providerStmt->fetchAll();
        $byProvider = array_map(static function (array $r): array {
            $r['n'] = (int) $r['n'];
            $r['fails'] = (int) $r['fails'];
            $r['fail_rate'] = $r['n'] > 0 ? round($r['fails'] / $r['n'] * 100, 1) : 0.0;
            return $r;
        }, $byProvider);

        Response::success([
            'environment' => $env,
            'risk' => $risk,
            'recent_failed' => $recent,
            'by_provider' => $byProvider,
        ]);
    }

    /** GET /api/admin/technical — santé des services & providers. */
    public static function technical(Request $request): void
    {
        [, $environment] = self::authorizeEnvironment($request);
        $pdo = Database::getConnection();

        // Connectivité DB.
        $dbOk = true;
        try {
            $pdo->query('SELECT 1')->fetchColumn();
        } catch (\Throwable) {
            $dbOk = false;
        }

        $providerStmt = $pdo->prepare(
            "SELECT provider_slug, environment,
                    CASE WHEN credentials_enc IS NOT NULL AND credentials_enc <> '' THEN 'configured' ELSE status END AS state,
                    last_tested_at, last_error
             FROM provider_credentials
             WHERE user_id IS NULL AND environment = :environment
             ORDER BY provider_slug"
        );
        $providerStmt->execute(['environment' => $environment->value]);
        $providers = $providerStmt->fetchAll();

        $services = [
            ['name' => 'API REST', 'status' => 'operational', 'latency_ms' => 0],
            ['name' => 'Base de données', 'status' => $dbOk ? 'operational' : 'down', 'latency_ms' => 0],
            ['name' => 'File de transactions', 'status' => 'operational', 'latency_ms' => 0],
            // Jamais « operational » sans credentials Sumsub vérifiées.
            ['name' => 'KYC (SumSub)', 'status' => 'NOT_VERIFIED', 'latency_ms' => 0],
        ];

        Response::success([
            'environment' => $environment->value,
            'services' => $services,
            'db_ok' => $dbOk,
            'providers' => $providers,
        ]);
    }
}
