<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
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
                    e.department, e.role, e.permissions, e.status, e.last_login_at, e.created_at
             FROM employees e
             JOIN users u ON u.id = e.user_id
             ORDER BY e.created_at DESC'
        );
        $rows = array_map(static function (array $r): array {
            $perms = $r['permissions'] !== null ? json_decode((string) $r['permissions'], true) : null;
            $r['permissions'] = is_array($perms) ? $perms : null;
            return $r;
        }, $stmt->fetchAll());

        Response::success(['items' => $rows, 'total' => count($rows)]);
    }

    /**
     * POST /api/control/employees — crée un employé interne.
     *
     * Workflow : Super Admin → créé l'employé (lien vers un compte users) →
     * attribue role/department/permissions. Le mot de passe n'est jamais
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
        $permissions = $request->input('permissions');

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
                $pdo->prepare('UPDATE users SET platform_role = :role WHERE id = :id')
                    ->execute(['role' => $role, 'id' => $uid]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO employees (user_id, department, role, permissions, status)
                 VALUES (:user_id, :department, :role, :permissions, :status)'
            );
            $stmt->execute([
                'user_id'     => $uid,
                'department'  => $department !== '' ? $department : null,
                'role'        => $role,
                'permissions' => is_array($permissions) ? json_encode($permissions) : null,
                'status'      => 'invited',
            ]);
            $employeeId = (int) $pdo->lastInsertId();

            self::audit($pdo, (int) $user['id'], 'EMPLOYEE_CREATED', 'employees', $employeeId, [
                'email' => $email, 'role' => $role, 'department' => $department,
            ]);

            $pdo->commit();
            Response::success(['id' => $employeeId, 'user_id' => $uid, 'status' => 'invited'], 201);
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

    /** PUT /api/control/employees/{id} — met à jour rôle/département/permissions. */
    public static function updateEmployee(Request $request): void
    {
        $user = self::authorize($request);
        $pdo = Database::getConnection();
        $id = (int) $request->param('id', '0');

        $role = (string) $request->input('role', '');
        $department = $request->input('department');
        $permissions = $request->input('permissions');

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
        if ($permissions !== null) {
            $updates[] = 'permissions = :permissions';
            $params[':permissions'] = is_array($permissions) ? json_encode($permissions) : null;
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
}
