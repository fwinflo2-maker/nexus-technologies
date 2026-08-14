<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Services\BusinessService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Contrôle d'accès aux espaces Business.
 *
 * Vérifie que la résolution de l'espace ciblé est une DÉCISION
 * D'AUTORISATION, pas une validation de formulaire :
 *
 *   - un acteur sans espace Business reçoit 403, jamais 400 ;
 *   - un compte Business ne peut cibler que le sien ;
 *   - l'appartenance réelle (team_members) reste exigée en aval.
 */
final class BusinessAccessControlTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $createdUsers = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $id) {
            $this->pdo->prepare('DELETE FROM team_members WHERE business_user_id = :i OR member_user_id = :i2')
                ->execute(['i' => $id, 'i2' => $id]);
            $this->pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
        }
        $this->createdUsers = [];
    }

    private function createUser(string $type): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence)
             VALUES (:n, :e, :p, :a, :c)'
        );
        $stmt->execute([
            'n' => 'Access Test',
            'e' => 'access-' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('Nexus#2026Test', PASSWORD_BCRYPT),
            'a' => $type,
            'c' => 'CG',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->createdUsers[] = $id;

        return $id;
    }

    /**
     * Un compte personnel sans `business_id` n'a pas fait une erreur de
     * saisie : il n'a aucun espace Business. La réponse doit être 403.
     *
     * Un 400 laisserait entendre qu'il suffit d'ajouter un paramètre pour
     * obtenir l'accès, et distinguerait de l'extérieur « paramètre manquant »
     * de « accès refusé ».
     */
    public function test_actor_without_business_space_is_forbidden_not_bad_request(): void
    {
        $actor = ['id' => $this->createUser('personal'), 'account_type' => 'personal'];

        try {
            BusinessService::resolveBusinessUserId($actor, null);
            $this->fail('Un acteur sans espace Business doit être refusé.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode(), 'Doit être une décision d\'autorisation, pas une validation.');
            $this->assertSame('FORBIDDEN_NO_BUSINESS_CONTEXT', $e->errorCode());
        }
    }

    /** Idem avec une valeur vide ou nulle explicitement fournie. */
    public function test_empty_business_id_is_also_forbidden(): void
    {
        $actor = ['id' => $this->createUser('personal'), 'account_type' => 'personal'];

        foreach (['', '0', 0, null] as $value) {
            try {
                BusinessService::resolveBusinessUserId($actor, $value);
                $this->fail('Valeur vide : accès refusé attendu.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->statusCode());
                $this->assertSame('FORBIDDEN_NO_BUSINESS_CONTEXT', $e->errorCode());
            }
        }
    }

    /** Un compte Business opère sur son propre espace. */
    public function test_business_account_resolves_to_its_own_space(): void
    {
        $id    = $this->createUser('business');
        $actor = ['id' => $id, 'account_type' => 'business'];

        $this->assertSame($id, BusinessService::resolveBusinessUserId($actor, null));
        $this->assertSame($id, BusinessService::resolveBusinessUserId($actor, $id));
    }

    /** Un compte Business ne peut pas cibler l'espace d'un autre. */
    public function test_business_account_cannot_target_another_space(): void
    {
        $mine   = $this->createUser('business');
        $theirs = $this->createUser('business');

        try {
            BusinessService::resolveBusinessUserId(['id' => $mine, 'account_type' => 'business'], $theirs);
            $this->fail('Le cross-tenant doit être refusé.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode());
            $this->assertSame('FORBIDDEN_CROSS_BUSINESS', $e->errorCode());
        }
    }

    /**
     * Un acteur non-membre qui CIBLE un espace passe la résolution, mais est
     * arrêté par le contrôle de rôle : l'isolation tenant ne repose donc pas
     * sur la seule résolution d'identifiant.
     */
    public function test_non_member_targeting_a_space_is_blocked_by_role_check(): void
    {
        $business = $this->createUser('business');
        $outsider = $this->createUser('personal');

        // La résolution rend l'identifiant demandé…
        $resolved = BusinessService::resolveBusinessUserId(
            ['id' => $outsider, 'account_type' => 'personal'],
            $business
        );
        $this->assertSame($business, $resolved);

        // …mais l'acteur n'a aucun rôle sur cet espace.
        $this->assertSame('none', BusinessService::roleOf($business, $outsider));

        try {
            BusinessService::requireRole($business, $outsider, ['owner', 'admin'], 'consulter');
            $this->fail('Un non-membre ne doit pas franchir le contrôle de rôle.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode());
            $this->assertSame('FORBIDDEN_ROLE', $e->errorCode());
        }
    }

    /** Un membre actif de l'équipe obtient bien son rôle. */
    public function test_active_team_member_gets_its_role(): void
    {
        $business = $this->createUser('business');
        $member   = $this->createUser('personal');

        $this->pdo->prepare(
            "INSERT INTO team_members (business_user_id, member_user_id, role, status)
             VALUES (:b, :m, 'accountant', 'active')"
        )->execute(['b' => $business, 'm' => $member]);

        $this->assertSame('accountant', BusinessService::roleOf($business, $member));

        // Le rôle donne accès à ses propres actions…
        BusinessService::requireRole($business, $member, ['accountant'], 'réconcilier');
        $this->addToAssertionCount(1);

        // …et pas à celles des autres.
        $this->expectException(HttpException::class);
        BusinessService::requireRole($business, $member, ['owner'], 'gérer l\'équipe');
    }

    /** Un membre désactivé perd son rôle. */
    public function test_disabled_member_loses_its_role(): void
    {
        $business = $this->createUser('business');
        $member   = $this->createUser('personal');

        $this->pdo->prepare(
            "INSERT INTO team_members (business_user_id, member_user_id, role, status)
             VALUES (:b, :m, 'admin', 'disabled')"
        )->execute(['b' => $business, 'm' => $member]);

        $this->assertSame('none', BusinessService::roleOf($business, $member));
    }
}
