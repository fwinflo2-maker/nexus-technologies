<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 14 — HYGIÈNE DU JOURNAL D'AUDIT.
 *
 * CONTEXTE
 * ────────
 * Six écrivains alimentent `audit_logs` : ExecutionAudit (l'autorité),
 * AuthController, UserController, ProviderCredentialController,
 * MaintenanceController et PaymentRecoveryService.
 *
 * Audit réel de ces six écrivains : aucun ne journalise actuellement de
 * secret. Quatre n'appellent pourtant PAS `SecretRedactor` — la sécurité
 * repose donc uniquement sur la discipline de chaque appelant. C'est une
 * garantie fragile : elle tient tant que personne n'ajoute un champ.
 *
 * CE QUE CES TESTS PROTÈGENT
 * ──────────────────────────
 * 1. Un test STRUCTUREL qui échoue si un secret atteint la table, quel que
 *    soit l'écrivain — y compris un écrivain qui n'existe pas encore.
 * 2. La qualité de la trace : un journal illisible ne vaut pas mieux que pas
 *    de journal. `profile_updated` enregistrait `{"fields":[0]}` — index d'un
 *    tableau de fragments SQL — au lieu des noms de champs modifiés.
 *
 * CE QU'ILS NE PRÉTENDENT PAS FAIRE
 * ─────────────────────────────────
 * Ils ne remplacent pas une autorité d'audit unique. Unifier les six
 * écrivains derrière `ExecutionAudit` est une refonte à part entière : sa
 * signature actuelle est spécialisée dans les décisions d'environnement
 * (`ExecutionContext` obligatoire), et `auth.*` n'appartient légitimement à
 * aucun environnement. Forcer un environnement sur un événement
 * d'authentification serait inventer une donnée fausse.
 */
final class AuditLogHygieneTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Motifs de secrets réels, empruntés aux conventions des providers
     * intégrés (Stripe, pawaPay, Wise, Sumsub…).
     *
     * @return list<array{0:string,1:string}>
     */
    private function secretPatterns(): array
    {
        return [
            ['%sk_live_%',       'clé secrète Stripe live'],
            ['%sk_test_%',       'clé secrète Stripe test'],
            ['%rk_live_%',       'clé restreinte Stripe'],
            ['%whsec_%',         'secret de signature webhook'],
            ['%-----BEGIN%',     'clé privée PEM'],
            ['%"password"%',     'mot de passe en clair'],
            ['%"secret_key"%',   'champ secret_key'],
            ['%"api_secret"%',   'champ api_secret'],
            ['%"private_key"%',  'champ private_key'],
        ];
    }

    /**
     * Aucun secret ne doit se trouver dans `metadata`, quel que soit
     * l'écrivain qui l'a produit.
     *
     * Ce test est un FILET STRUCTUREL : il ne connaît pas les écrivains, il
     * inspecte le résultat. Un nouvel écrivain négligent le fait tomber sans
     * qu'on ait pensé à l'ajouter à une liste.
     */
    public function test_no_secret_pattern_reaches_the_audit_metadata(): void
    {
        foreach ($this->secretPatterns() as [$pattern, $label]) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE metadata LIKE :p');
            $stmt->execute(['p' => $pattern]);

            $this->assertSame(
                0,
                (int) $stmt->fetchColumn(),
                sprintf('Un secret (%s) a atteint audit_logs.metadata.', $label)
            );
        }
    }

    /**
     * Le champ `action` non plus : c'est une varchar(50) souvent construite
     * par concaténation, donc un endroit plausible pour une fuite accidentelle.
     */
    public function test_no_secret_pattern_reaches_the_action_column(): void
    {
        foreach ($this->secretPatterns() as [$pattern, $label]) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action LIKE :p');
            $stmt->execute(['p' => $pattern]);

            $this->assertSame(0, (int) $stmt->fetchColumn(), sprintf('Secret (%s) dans action.', $label));
        }
    }

    /**
     * La trace doit rester EXPLOITABLE.
     *
     * `UserController::updateProfile()` journalisait
     * `['fields' => array_keys($updates)]` où `$updates` contient des
     * fragments SQL (`'full_name = :full_name'`). Le résultat était
     * `{"fields":[0,1,2]}` : impossible de savoir quels champs avaient changé.
     *
     * Une trace fausse est pire qu'une trace absente : elle donne l'illusion
     * de la traçabilité lors d'une investigation.
     */
    public function test_profile_update_records_field_names_not_indices(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Controllers/UserController.php');
        $this->assertIsString($source);

        $this->assertStringNotContainsString(
            "'fields' => array_keys(\$updates)",
            $source,
            'Les noms de champs doivent être journalisés, pas les index des fragments SQL.'
        );

        $this->assertStringContainsString(
            "'fields' => \$changedFields",
            $source,
            'La liste des champs modifiés doit être construite explicitement.'
        );
    }

    /**
     * Le mot de passe ne doit JAMAIS figurer dans la charge utile de
     * `password_changed` — même haché.
     *
     * Journaliser un hash reste une fuite : il est attaquable hors ligne, et
     * le journal a une durée de rétention plus longue que la table `users`.
     */
    public function test_password_change_logs_no_payload(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Controllers/UserController.php');
        $this->assertIsString($source);

        $this->assertStringContainsString(
            "self::audit(\$userId, 'password_changed', null, null, [], \$request)",
            $source,
            'Le changement de mot de passe doit être journalisé avec une charge utile VIDE.'
        );
    }
}
