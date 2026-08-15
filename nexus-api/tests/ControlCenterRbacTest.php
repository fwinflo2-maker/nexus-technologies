<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\HttpException;
use Nexus\Execution\PlatformRole;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 16 — GRANULARITÉ RBAC DU CONTROL CENTER.
 *
 * LE DÉFAUT
 * ─────────
 * Une capacité unique, `operations`, gardait TOUTES les surfaces du Control
 * Center. Elle est portée par 9 rôles — dont `support_operator` et
 * `qa_engineer`. Conséquence vérifiée en HTTP réel :
 *
 *     support_operator → GET /control/credentials  200
 *     support_operator → GET /control/audit        200
 *     qa_engineer      → GET /control/credentials  200
 *
 * Aucune valeur de secret ne fuitait (vérifié : les `sk_test_...` observés
 * dans la réponse sont des PLACEHOLDERS de formulaire, pas des credentials).
 * Le problème est ailleurs :
 *
 *   - l'inventaire des credentials est un plan de l'infrastructure de
 *     paiement : quels providers sont actifs, dans quel environnement,
 *     configurés depuis quand. Cela révèle les corridors et les dépendances
 *     externes de Nexus ;
 *   - le journal d'audit couvre TOUS les comptes et TOUT le personnel. Le
 *     lire, c'est observer le travail de ses collègues autant que celui des
 *     clients.
 *
 * Ni l'un ni l'autre n'est le métier du support ou de la QA. Le principe du
 * moindre privilège ne s'arrête pas à la frontière client/plateforme : il
 * s'applique AUSSI entre employés.
 *
 * CE QUI N'A PAS CHANGÉ
 * ─────────────────────
 * `operations` reste large pour la lecture d'exploitation (overview, statut
 * des providers, webhooks, KYC) : diagnostiquer n'est pas modifier, et
 * restreindre ces surfaces empêcherait le support de faire son travail.
 */
final class ControlCenterRbacTest extends TestCase
{
    /**
     * Matrice attendue : rôle → capacité → autorisé ?
     *
     * Écrite explicitement plutôt que dérivée du code : un test qui
     * recalcule la règle depuis l'implémentation ne teste rien.
     *
     * @return array<string, array<string, bool>>
     */
    private function expectedMatrix(): array
    {
        return [
            //                        operations  credential_inventory  audit_read  credentials  maintenance
            'superadmin'         => ['operations' => true,  'credential_inventory' => true,  'audit_read' => true,  'credentials' => true,  'maintenance' => true],
            'provider_engineer'  => ['operations' => true,  'credential_inventory' => true,  'audit_read' => false, 'credentials' => true,  'maintenance' => false],
            'security_engineer'  => ['operations' => true,  'credential_inventory' => true,  'audit_read' => true,  'credentials' => false, 'maintenance' => false],
            'sre_operator'       => ['operations' => true,  'credential_inventory' => true,  'audit_read' => false, 'credentials' => false, 'maintenance' => true],
            'compliance_operator' => ['operations' => true, 'credential_inventory' => false, 'audit_read' => true,  'credentials' => false, 'maintenance' => false],
            'support_operator'   => ['operations' => true,  'credential_inventory' => false, 'audit_read' => false, 'credentials' => false, 'maintenance' => false],
            'qa_engineer'        => ['operations' => true,  'credential_inventory' => false, 'audit_read' => false, 'credentials' => false, 'maintenance' => false],
            'backend_engineer'   => ['operations' => true,  'credential_inventory' => false, 'audit_read' => false, 'credentials' => false, 'maintenance' => false],
            'finance_operator'   => ['operations' => true,  'credential_inventory' => false, 'audit_read' => false, 'credentials' => false, 'maintenance' => false],
            // Le client : aucune capacité plateforme, jamais.
            'user'               => ['operations' => false, 'credential_inventory' => false, 'audit_read' => false, 'credentials' => false, 'maintenance' => false],
        ];
    }

    private function allows(string $role, string $capability): bool
    {
        try {
            PlatformRole::require(['id' => 1, 'platform_role' => $role], $capability);

            return true;
        } catch (HttpException $e) {
            $this->assertSame(403, $e->statusCode(), 'Un refus RBAC doit être un 403.');
            $this->assertSame(PlatformRole::ERROR_CODE, $e->errorCode());

            return false;
        }
    }

    public function test_the_full_capability_matrix_holds(): void
    {
        foreach ($this->expectedMatrix() as $role => $capabilities) {
            foreach ($capabilities as $capability => $expected) {
                $this->assertSame(
                    $expected,
                    $this->allows($role, $capability),
                    sprintf('Rôle « %s », capacité « %s ».', $role, $capability)
                );
            }
        }
    }

    /**
     * Le cœur du correctif : ces deux rôles gardent leur travail, mais plus
     * l'infrastructure ni la surveillance.
     */
    public function test_support_and_qa_lost_credential_inventory_and_audit(): void
    {
        foreach (['support_operator', 'qa_engineer'] as $role) {
            $this->assertTrue(
                $this->allows($role, 'operations'),
                sprintf('%s doit conserver la lecture d\'exploitation.', $role)
            );
            $this->assertFalse(
                $this->allows($role, 'credential_inventory'),
                sprintf('%s ne doit pas voir le plan de l\'infrastructure de paiement.', $role)
            );
            $this->assertFalse(
                $this->allows($role, 'audit_read'),
                sprintf('%s ne doit pas lire le journal de tous les comptes.', $role)
            );
        }
    }

    /**
     * Une capacité inconnue doit être refusée (deny by default).
     *
     * Sans cela, une faute de frappe dans un contrôleur — `'audit-read'` au
     * lieu de `'audit_read'` — ouvrirait la surface au lieu de la fermer.
     */
    public function test_an_unknown_capability_is_denied_for_everyone(): void
    {
        foreach (['superadmin', 'support_operator', 'user'] as $role) {
            $this->assertFalse(
                $this->allows($role, 'capacite_inexistante'),
                sprintf('Une capacité inconnue doit être refusée, même à %s.', $role)
            );
        }
    }

    /**
     * Un rôle inconnu (valeur corrompue, rôle supprimé, injection) ne confère
     * aucun privilège.
     */
    public function test_an_unknown_role_gets_nothing(): void
    {
        foreach (['operations', 'credential_inventory', 'audit_read', 'credentials', 'maintenance'] as $capability) {
            $this->assertFalse(
                $this->allows('root', $capability),
                sprintf('Un rôle inconnu ne doit pas obtenir « %s ».', $capability)
            );
        }
    }

    /**
     * Le CÂBLAGE du contrôleur, pas seulement la logique de PlatformRole.
     *
     * Une mutation a survécu ici : remettre `PlatformRole::require($user,
     * 'operations')` en dur dans `authorize()` ne cassait aucun test, alors
     * que cela annule tout le correctif. Les tests précédents vérifiaient la
     * table des capacités — pas le fait que le contrôleur la CONSULTE.
     *
     * C'est exactement le bug rencontré pendant cette boucle : les appels
     * passaient bien `'audit_read'`, mais `authorize()` ignorait son
     * paramètre. Le code semblait juste à la lecture ; seul l'appel HTTP
     * réel a révélé les 200.
     */
    public function test_the_controller_actually_forwards_the_requested_capability(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Controllers/ControlCenterController.php');
        $this->assertIsString($source);

        // La signature doit accepter une capacité…
        $this->assertMatchesRegularExpression(
            '/private static function authorize\(\s*Request \$request,\s*string \$capability/',
            $source,
            'authorize() doit accepter la capacité en paramètre.'
        );

        // …et la TRANSMETTRE, pas la remplacer par une constante.
        $this->assertStringContainsString(
            'PlatformRole::require($user, $capability);',
            $source,
            'authorize() doit transmettre la capacité reçue, jamais une valeur en dur.'
        );

        $this->assertDoesNotMatchRegularExpression(
            // Guillemets SIMPLES : en double quote, PHP interpolerait $user
            // et le motif ne correspondrait plus à rien.
            '/PlatformRole::require\(\$user, \'(operations|audit_read|credential_inventory)\'\)/',
            $source,
            'Aucune capacité ne doit être codée en dur dans authorize().'
        );
    }

    /**
     * Les deux portes vers le MÊME inventaire doivent exiger la MÊME clé.
     *
     * `GET /providers/credentials` et `GET /control/credentials` exposent la
     * même information. Si l'une est plus permissive, elle annule l'autre —
     * et la protection la plus stricte devient décorative.
     */
    public function test_both_credential_inventory_endpoints_require_the_same_capability(): void
    {
        $controlCenter = file_get_contents(__DIR__ . '/../src/Controllers/ControlCenterController.php');
        $providerCtrl  = file_get_contents(__DIR__ . '/../src/Controllers/ProviderCredentialController.php');

        $this->assertIsString($controlCenter);
        $this->assertIsString($providerCtrl);

        $this->assertStringContainsString("authorize(\$request, 'credential_inventory')", $controlCenter);
        $this->assertStringContainsString("'credential_inventory'", $providerCtrl);
    }
}
