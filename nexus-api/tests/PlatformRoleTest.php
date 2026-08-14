<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\ControlCenterController;
use Nexus\Controllers\ProviderCredentialController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use Nexus\Execution\PlatformRole;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 7 — PRIVILÈGE D'EXPLOITATION (Superadmin / personnel Nexus).
 *
 * Faille corrigée ici (CRITICAL, reproduite en HTTP réel avant correctif) :
 * l'administration des credentials providers était gardée par
 * `account_type === 'business'`. Or ce champ est choisi librement par
 * l'utilisateur au moment de l'inscription :
 *
 *     POST /register { account_type: "business" }              → 200 + jeton
 *     PUT  /providers/stripe/credentials
 *          { environment: "production", secret_key: "sk_live_…" }  → 200
 *
 * N'importe qui pouvait donc injecter une credential de PRODUCTION.
 *
 * Ces tests verrouillent la séparation entre :
 *     account_type   → qui est le CLIENT
 *     platform_role  → qui EXPLOITE la plateforme
 */
final class PlatformRoleTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        Response::enableTestMode(true);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        unset($_SERVER['HTTP_AUTHORIZATION']);

        foreach ($this->created as $uid) {
            $this->pdo->prepare('DELETE FROM provider_credentials WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        }
        $this->created = [];
    }

    /** Crée un utilisateur et authentifie la requête suivante en son nom. */
    private function actor(string $accountType, string $platformRole): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, platform_role, country_of_residence)
             VALUES (:n, :e, :p, :a, :r, :c)'
        );
        $stmt->execute([
            'n' => 'Acteur ' . $platformRole,
            'e' => 'role_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            'a' => $accountType,
            'r' => $platformRole,
            'c' => 'CG',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->created[] = $id;

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \Nexus\Auth\Jwt::encode([
            'sub' => $id, 'iat' => time(), 'exp' => time() + 3600,
        ]);

        return $id;
    }

    /** @return array{status:int,code:?string} */
    private function callUpsert(): array
    {
        $request = new Request([
            'environment' => 'production',
            'secret_key'  => 'sk_live_TENTATIVE_INJECTION',
        ]);
        $request->setParams(['slug' => 'stripe']);

        try {
            ProviderCredentialController::upsert($request);

            return ['status' => 0, 'code' => null];
        } catch (ResponseSent $sent) {
            $body = json_decode($sent->body(), true);

            return [
                'status' => $sent->statusCode(),
                'code'   => is_array($body) ? ($body['code'] ?? ($body['error']['code'] ?? null)) : null,
            ];
        } catch (HttpException $e) {
            return ['status' => $e->statusCode(), 'code' => $e->errorCode()];
        }
    }

    // ══ 1. L'EXPLOIT EST FERMÉ ═════════════════════════════════════════════

    /**
     * Le scénario exact reproduit avant correctif : un compte business
     * ordinaire (auto-inscrit) tente d'écrire une credential de production.
     */
    public function test_a_self_registered_business_account_cannot_write_credentials(): void
    {
        $this->actor('business', PlatformRole::USER);

        $res = $this->callUpsert();

        $this->assertSame(403, $res['status'], 'Un client business ne doit pas administrer les providers.');
        $this->assertSame(PlatformRole::ERROR_CODE, $res['code']);

        // Rien n'a été écrit.
        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM provider_credentials WHERE credentials_enc LIKE '%TENTATIVE_INJECTION%'"
        )->fetchColumn();
        $this->assertSame(0, (int) $count);
    }

    public function test_a_personal_account_cannot_write_credentials(): void
    {
        $this->actor('personal', PlatformRole::USER);

        $res = $this->callUpsert();
        $this->assertSame(403, $res['status']);
        $this->assertSame(PlatformRole::ERROR_CODE, $res['code']);
    }

    // ══ 2. LE RÔLE LÉGITIME FONCTIONNE ═════════════════════════════════════

    /**
     * Un refus universel passerait pour une protection correcte. Ce test
     * prouve que le superadmin, lui, peut administrer.
     */
    public function test_a_superadmin_can_write_credentials(): void
    {
        $this->actor('personal', PlatformRole::SUPERADMIN);

        $res = $this->callUpsert();

        $this->assertNotSame(403, $res['status'], 'Le superadmin doit pouvoir administrer les credentials.');
        $this->assertNotSame(PlatformRole::ERROR_CODE, $res['code']);
    }

    /** Le rôle métier dédié y a également droit. */
    public function test_a_provider_engineer_can_write_credentials(): void
    {
        $this->actor('personal', PlatformRole::PROVIDER_ENGINEER);

        $res = $this->callUpsert();
        $this->assertNotSame(403, $res['status']);
    }

    /** Mais pas les autres rôles internes : le privilège reste granulaire. */
    public function test_other_internal_roles_cannot_write_credentials(): void
    {
        foreach ([PlatformRole::SUPPORT_OPERATOR, PlatformRole::QA_ENGINEER, PlatformRole::AI_AGENT] as $role) {
            $this->actor('personal', $role);

            $res = $this->callUpsert();
            $this->assertSame(
                403,
                $res['status'],
                sprintf('Le rôle « %s » ne doit pas administrer les credentials.', $role)
            );
        }
    }

    // ══ 3. CONTROL CENTER ══════════════════════════════════════════════════

    public function test_control_center_is_closed_to_ordinary_business_accounts(): void
    {
        $this->actor('business', PlatformRole::USER);

        try {
            ControlCenterController::providers(new Request([]));
            $this->fail('Le Control Center ne doit pas être ouvert à un client business.');
        } catch (ResponseSent $sent) {
            $this->assertSame(403, $sent->statusCode());
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode());
            $this->assertSame(PlatformRole::ERROR_CODE, $e->errorCode());
        }
    }

    public function test_control_center_is_open_to_operators(): void
    {
        $this->actor('personal', PlatformRole::SRE_OPERATOR);

        try {
            ControlCenterController::providers(new Request([]));
            $this->fail('Le contrôleur doit émettre une réponse.');
        } catch (ResponseSent $sent) {
            $this->assertSame(200, $sent->statusCode(), 'Un opérateur doit pouvoir consulter le Control Center.');
        }
    }

    // ══ 4. AUCUNE AUTO-PROMOTION ═══════════════════════════════════════════

    /**
     * Le privilège ne doit pas pouvoir être obtenu par le champ d'inscription
     * ni par une mise à jour de profil : la promotion se fait en base, par un
     * administrateur.
     */
    public function test_platform_role_cannot_be_granted_through_the_api(): void
    {
        $sources = [
            'src/Controllers/AuthController.php',
            'src/Controllers/UserController.php',
        ];

        foreach ($sources as $relative) {
            $code = (string) file_get_contents(__DIR__ . '/../' . $relative);

            $this->assertDoesNotMatchRegularExpression(
                '/(INSERT INTO users[^;]*platform_role|UPDATE users SET[^;]*platform_role)/s',
                $code,
                sprintf(
                    '%s ne doit jamais écrire platform_role : ce serait un chemin d\'escalade de privilèges.',
                    $relative
                )
            );
        }
    }

    // ══ 5. DENY BY DEFAULT ═════════════════════════════════════════════════

    public function test_unknown_or_missing_role_grants_nothing(): void
    {
        $this->assertSame(PlatformRole::USER, PlatformRole::of(null));
        $this->assertSame(PlatformRole::USER, PlatformRole::of([]));
        $this->assertSame(PlatformRole::USER, PlatformRole::of(['platform_role' => 'root']));
        $this->assertSame(PlatformRole::USER, PlatformRole::of(['platform_role' => '']));

        $this->assertFalse(PlatformRole::canAdministerCredentials(['platform_role' => 'root']));
        $this->assertFalse(PlatformRole::canViewOperations(null));
        $this->assertFalse(PlatformRole::canRunMaintenance(['platform_role' => 'user']));

        // Une capacité inconnue est refusée, jamais accordée par défaut.
        $this->expectException(HttpException::class);
        PlatformRole::require(['platform_role' => PlatformRole::SUPERADMIN], 'capacite_inexistante');
    }
}
