<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\Jwt;
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
 * BOUCLE 10 — TOUTE LA SURFACE CREDENTIALS, PAS SEULEMENT L'ÉCRITURE.
 *
 * La boucle 7 avait fermé `upsert` et `delete`. Trois points d'entrée étaient
 * restés ouverts à n'importe quel compte authentifié :
 *
 *   GET  /providers/credentials     inventaire des providers configurés
 *   POST /providers/{slug}/test     déclenche une connexion sortante et
 *                                   MODIFIE `status` / `last_tested_at`
 *   GET  /providers/status          état agrégé
 *
 * L'inventaire n'est pas anodin : savoir quels providers sont configurés, et
 * dans quel environnement, décrit l'infrastructure de Nexus et sa maturité
 * d'intégration. Quant à `test`, ce n'est pas une lecture — il écrit.
 *
 * Ces tests existent parce que deux mutations ont SURVÉCU : retirer la garde
 * de `list()` ou de `test()` ne cassait aucun test. Une protection que rien
 * ne vérifie n'est pas une protection.
 */
final class CredentialSurfaceTest extends TestCase
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

        $this->pdo->exec(
            "DELETE FROM provider_credentials
              WHERE user_id IS NULL AND provider_slug = 'stripe'"
        );

        foreach ($this->created as $uid) {
            $this->pdo->prepare('DELETE FROM provider_credentials WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$uid]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        }
        $this->created = [];
    }

    private function actor(string $accountType, string $platformRole): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence, platform_role, status)
             VALUES (:n, :e, :p, :t, :c, :r, :s)'
        );
        $stmt->execute([
            'n' => 'Surface ' . $platformRole,
            'e' => 'surf_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => $accountType,
            'c' => 'CG',
            'r' => $platformRole,
            's' => 'ACTIVE',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->created[] = $id;

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Jwt::encode([
            'sub' => $id, 'iat' => time(), 'exp' => time() + 3600,
        ]);

        return $id;
    }

    /**
     * @return array{status:int,code:?string,body:string}
     */
    private function call(string $method, array $body = []): array
    {
        $request = new Request($body);
        $request->setParams(['slug' => 'stripe']);

        try {
            ProviderCredentialController::$method($request);

            return ['status' => 200, 'code' => null, 'body' => ''];
        } catch (ResponseSent $sent) {
            $decoded = json_decode($sent->body(), true);

            return [
                'status' => $sent->statusCode(),
                'code'   => is_array($decoded) ? ($decoded['code'] ?? null) : null,
                'body'   => $sent->body(),
            ];
        } catch (HttpException $e) {
            return ['status' => $e->statusCode(), 'code' => $e->errorCode(), 'body' => ''];
        }
    }

    // ══ 1. L'INVENTAIRE EST UNE INFORMATION D'EXPLOITATION ════════════════

    public function test_a_client_cannot_list_provider_credentials(): void
    {
        $this->actor('business', PlatformRole::USER);

        $res = $this->call('list');

        $this->assertSame(403, $res['status'], 'L\'inventaire des providers configurés n\'est pas public.');
        $this->assertSame(PlatformRole::ERROR_CODE, $res['code']);
    }

    public function test_an_operations_role_can_list_provider_credentials(): void
    {
        $this->actor('personal', PlatformRole::SUPPORT_OPERATOR);

        $res = $this->call('list');

        $this->assertSame(200, $res['status'], 'Le personnel d\'exploitation doit pouvoir consulter l\'inventaire.');
    }

    // ══ 2. TESTER UNE CREDENTIAL EST UNE ÉCRITURE ═════════════════════════

    /**
     * `test` déclenche une connexion sortante depuis l'infrastructure Nexus
     * et met à jour `status`, `last_tested_at`, `last_error`. Le laisser
     * ouvert offrirait à n'importe qui un déclencheur de trafic sortant et un
     * moyen de modifier l'état affiché aux opérateurs.
     */
    public function test_a_client_cannot_trigger_a_credential_test(): void
    {
        $this->actor('business', PlatformRole::USER);

        $res = $this->call('test', ['environment' => 'sandbox']);

        $this->assertSame(403, $res['status']);
        $this->assertSame(PlatformRole::ERROR_CODE, $res['code']);
    }

    /**
     * Un rôle de simple lecture ne doit pas pouvoir déclencher le test :
     * consulter n'est pas agir.
     */
    public function test_a_read_only_operator_cannot_trigger_a_credential_test(): void
    {
        $this->actor('personal', PlatformRole::SUPPORT_OPERATOR);

        $res = $this->call('test', ['environment' => 'sandbox']);

        $this->assertSame(
            403,
            $res['status'],
            'Support = lecture. Déclencher un test modifie l\'état et sort du réseau.'
        );
    }

    /**
     * Le pendant positif : sans lui, une garde qui refuse TOUT LE MONDE
     * passerait pour correcte.
     */
    public function test_a_provider_engineer_can_trigger_a_credential_test(): void
    {
        $this->actor('personal', PlatformRole::PROVIDER_ENGINEER);

        $res = $this->call('test', ['environment' => 'sandbox']);

        $this->assertNotSame(
            403,
            $res['status'],
            'Le rôle habilité aux credentials doit pouvoir tester la connectivité.'
        );
        $this->assertNotSame(PlatformRole::ERROR_CODE, $res['code']);
    }

    // ══ 3. AUCUNE RÉPONSE NE RÉVÈLE UN SECRET ═════════════════════════════

    /**
     * L'inventaire dit `configured` / `has_credentials` — jamais la valeur.
     */
    public function test_the_inventory_never_exposes_a_secret_value(): void
    {
        $this->actor('personal', PlatformRole::SUPERADMIN);

        // Credential de plateforme avec une valeur reconnaissable.
        \Nexus\Services\ProviderCredentialService::upsertPlatform(
            $this->pdo,
            'stripe',
            'sandbox',
            ['secret_key' => 'sk_test_VALEUR_QUI_NE_DOIT_PAS_SORTIR'],
            'sandbox_only',
            $this->created[0]
        );

        $res = $this->call('list');

        $this->assertSame(200, $res['status']);
        $this->assertStringNotContainsString(
            'sk_test_VALEUR_QUI_NE_DOIT_PAS_SORTIR',
            $res['body'],
            'L\'inventaire ne doit JAMAIS contenir la valeur d\'un secret.'
        );
        $this->assertStringNotContainsString('credentials_enc', $res['body']);
    }
}
