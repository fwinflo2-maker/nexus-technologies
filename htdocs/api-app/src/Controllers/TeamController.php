<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Services\BusinessService;

/**
 * TeamController — équipe & rôles (RBAC) d'un compte Business.
 * Le propriétaire (owner) et les admins gèrent les membres.
 */
final class TeamController
{
    /** GET /api/team?business_id= */
    public static function index(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->query('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin', 'finance_manager', 'accountant', 'operator', 'viewer'], 'consulter l\'équipe');

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT m.id, m.member_user_id, m.role, m.status, m.created_at,
                    u.full_name, u.email
             FROM team_members m
             JOIN users u ON u.id = m.member_user_id
             WHERE m.business_user_id = :bid
             ORDER BY m.created_at ASC'
        );
        $stmt->execute(['bid' => $bid]);

        $members = array_map(static function (array $row): array {
            return [
                'id'          => (int) $row['id'],
                'user_id'     => (int) $row['member_user_id'],
                'full_name'   => (string) $row['full_name'],
                'email'       => (string) $row['email'],
                'role'        => (string) $row['role'],
                'status'      => (string) $row['status'],
                'created_at'  => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll());

        Response::success(['items' => $members, 'roles' => BusinessService::ROLES]);
    }

    /** POST /api/team — ajoute un membre existant avec un rôle. */
    public static function add(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin'], 'gérer l\'équipe');

        $email = strtolower(trim((string) $request->input('email', '')));
        $role  = strtolower(trim((string) $request->input('role', 'viewer')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::badRequest('Email invalide.');
        }
        if (!in_array($role, BusinessService::ROLES, true) || $role === 'owner') {
            Response::badRequest('Rôle invalide (owner, admin, finance_manager, accountant, operator, viewer — sauf owner).');
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $memberId = (int) $stmt->fetchColumn();
        if ($memberId <= 0) {
            Response::badRequest('Aucun utilisateur NEXUS ne correspond à cet email.');
        }
        if ($memberId === $bid) {
            Response::badRequest('Le propriétaire ne peut pas être ajouté comme membre.');
        }

        try {
            $ins = $pdo->prepare(
                "INSERT INTO team_members (business_user_id, member_user_id, role, status)
                 VALUES (:bid, :mid, :role, 'active')"
            );
            $ins->execute(['bid' => $bid, 'mid' => $memberId, 'role' => $role]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::conflict('Cet utilisateur est déjà membre de l\'équipe.');
            }
            throw $e;
        }

        Response::success(['id' => (int) $pdo->lastInsertId()], 201);
    }

    /** PUT /api/team/{id} — change le rôle d'un membre. */
    public static function update(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin'], 'gérer l\'équipe');

        $id   = (int) $request->param('id', '0');
        $role = strtolower(trim((string) $request->input('role', '')));

        if (!in_array($role, BusinessService::ROLES, true) || $role === 'owner') {
            Response::badRequest('Rôle invalide.');
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE team_members SET role = :role WHERE id = :id AND business_user_id = :bid');
        $stmt->execute(['role' => $role, 'id' => $id, 'bid' => $bid]);

        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'Membre introuvable.', 'TEAM_MEMBER_NOT_FOUND');
        }

        Response::success(['updated' => true]);
    }

    /** DELETE /api/team/{id} — retire un membre. */
    public static function remove(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $actor   = $request->attribute('user');
        $bid     = BusinessService::resolveBusinessUserId($actor, $request->input('business_id'));
        BusinessService::requireRole($bid, (int) $actor['id'], ['owner', 'admin'], 'gérer l\'équipe');

        $id  = (int) $request->param('id', '0');
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('DELETE FROM team_members WHERE id = :id AND business_user_id = :bid');
        $stmt->execute(['id' => $id, 'bid' => $bid]);

        Response::success(['deleted' => true]);
    }
}
