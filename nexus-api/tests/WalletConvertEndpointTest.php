<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Auth\Jwt;
use Nexus\Controllers\WalletController;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Core\ResponseSent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 11 — LA CONVERSION DEVIENT RÉELLE.
 *
 * LE DÉFAUT
 * ─────────
 * `WalletService::transferMultiCurrency()` est complet et couvert par des
 * dizaines de tests… mais AUCUNE route ne l'exposait. Côté interface,
 * « Convertir » exécutait :
 *
 *     setTimeout(() => { setConverting(false); setAmount(''); }, 2000)
 *
 * L'utilisateur voyait une conversion réussie. Aucun argent ne bougeait,
 * aucune écriture comptable n'était produite. C'est un faux succès : la
 * catégorie de défaut la plus grave sur un système financier, parce qu'elle
 * ne laisse aucune trace d'erreur.
 *
 * CE QUE VÉRIFIENT CES TESTS
 * ──────────────────────────
 * Que la route exécute le VRAI moteur — donc que les soldes bougent
 * réellement — et qu'elle n'affaiblit aucune des protections déjà en place :
 * isolation multi-tenant, environnement explicite, idempotence, et « un refus
 * ne produit aucune mutation financière ».
 */
final class WalletConvertEndpointTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $created = [];
    private int $userId = 0;
    private int $eurWallet = 0;
    private int $usdWallet = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::getConnection();
        Response::enableTestMode(true);

        $this->userId    = $this->createUser();
        $this->eurWallet = $this->createWallet($this->userId, 'EUR', '1000.00');
        $this->usdWallet = $this->createWallet($this->userId, 'USD', '0.00');

        // Source FX réelle (table fx_rates_cache) : les conversions EUR→USD
        // de ces tests exigent un taux configuré — plus aucun repli manuel.
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE base_currency = :b AND quote_currency = :q')
            ->execute(['b' => 'EUR', 'q' => 'USD']);
        $this->pdo->prepare(
            'INSERT INTO fx_rates_cache (base_currency, quote_currency, rate, spread_pct, source, fetched_at, expires_at)
             VALUES (:b, :q, :r, :s, :src, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))'
        )->execute([
            'b'   => 'EUR',
            'q'   => 'USD',
            'r'   => '1.08700000',
            's'   => '0.0000',
            'src' => 'fx_provider_test',
        ]);

        $this->authenticateAs($this->userId);
    }

    protected function tearDown(): void
    {
        Response::enableTestMode(false);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_NEXUS_ENVIRONMENT']);
        // Taux de test retiré (isolation entre tests).
        $this->pdo->prepare('DELETE FROM fx_rates_cache WHERE source = :s')
            ->execute(['s' => 'fx_provider_test']);

        foreach ($this->created as $uid) {
            // `ledger_entries` n'a pas de `user_id` : il se rattache à
            // l'opération, pas au compte. Il est purgé via wallet_id.
            $this->pdo->prepare(
                'DELETE FROM ledger_entries WHERE wallet_id IN (SELECT id FROM wallets WHERE user_id = ?)'
            )->execute([$uid]);

            foreach (['wallet_operations', 'idempotency_keys', 'transactions', 'wallets', 'audit_logs'] as $t) {
                $this->pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$uid]);
            }
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        }
        $this->created = [];
    }

    private function createUser(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, account_type, country_of_residence, status)
             VALUES (:n, :e, :p, :t, :c, :s)'
        );
        $stmt->execute([
            'n' => 'Convert Probe',
            'e' => 'conv_' . bin2hex(random_bytes(6)) . '@nexus.test',
            'p' => password_hash('x', PASSWORD_BCRYPT),
            't' => 'personal',
            'c' => 'CG',
            's' => 'ACTIVE',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->created[] = $id;

        return $id;
    }

    private function createWallet(int $userId, string $currency, string $balance): int
    {
        $stmt = $this->pdo->prepare(
            // Deux placeholders DISTINCTS : PDO en émulation refuse un
            // placeholder nommé réutilisé (HY093).
            'INSERT INTO wallets (user_id, currency, balance, available_balance, hold_balance)
             VALUES (:u, :c, :bal, :avail, 0)'
        );
        $stmt->execute(['u' => $userId, 'c' => $currency, 'bal' => $balance, 'avail' => $balance]);

        return (int) $this->pdo->lastInsertId();
    }

    private function authenticateAs(int $userId): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Jwt::encode([
            'sub' => $userId, 'iat' => time(), 'exp' => time() + 3600,
        ]);
    }

    /** @return array{status:int,code:?string,data:array<string,mixed>} */
    private function convert(array $body): array
    {
        try {
            WalletController::convert(new Request($body));

            return ['status' => 0, 'code' => null, 'data' => []];
        } catch (ResponseSent $sent) {
            $decoded = json_decode($sent->body(), true);

            return [
                'status' => $sent->statusCode(),
                'code'   => is_array($decoded) ? ($decoded['code'] ?? null) : null,
                'data'   => is_array($decoded) ? ($decoded['data'] ?? []) : [],
            ];
        } catch (HttpException $e) {
            return ['status' => $e->statusCode(), 'code' => $e->errorCode(), 'data' => []];
        }
    }

    private function balance(int $walletId): string
    {
        $stmt = $this->pdo->prepare('SELECT balance FROM wallets WHERE id = ?');
        $stmt->execute([$walletId]);

        return (string) $stmt->fetchColumn();
    }

    private function countOperations(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM wallet_operations WHERE user_id = ?');
        $stmt->execute([$this->userId]);

        return (int) $stmt->fetchColumn();
    }

    // ══ 1. L'ARGENT BOUGE VRAIMENT ════════════════════════════════════════

    /**
     * Le test central : après conversion, les DEUX soldes ont changé.
     *
     * C'est précisément ce que la simulation frontend ne faisait pas.
     */
    public function test_a_conversion_actually_moves_money(): void
    {
        $res = $this->convert([
            'amount'           => '100.00',
            'source_currency'  => 'EUR',
            'dest_currency'    => 'USD',
        ]);

        $this->assertSame(200, $res['status'], 'La conversion nominale doit aboutir.');

        $this->assertSame(
            '900.00',
            $this->balance($this->eurWallet),
            'Le wallet source doit être débité — sinon la conversion est un faux succès.'
        );
        $this->assertGreaterThan(
            0.0,
            (float) $this->balance($this->usdWallet),
            'Le wallet destination doit être crédité.'
        );
        $this->assertGreaterThan(0, $this->countOperations(), 'Une opération doit être enregistrée.');
    }

    // ══ 2. ISOLATION MULTI-TENANT ═════════════════════════════════════════

    /**
     * Les wallets d'autrui sont HORS D'ATTEINTE, par conception.
     *
     * La route n'accepte pas d'identifiant de wallet : l'utilisateur désigne
     * une DEVISE, et le serveur résout le wallet correspondant à partir du
     * JETON. Il n'existe donc aucun paramètre par lequel désigner le wallet
     * d'un tiers — la classe entière de vulnérabilité (IDOR / BOLA) disparaît
     * au lieu d'être filtrée.
     *
     * Ce test le prouve : une victime possède un wallet EUR bien fourni ;
     * l'attaquant convertit « EUR → USD » et ne touche QUE ses propres fonds.
     *
     * (Une première version de la route acceptait `source_wallet_id` : elle a
     * répondu HTTP 200 en débitant le wallet de la victime. Le contrôle de
     * propriété a été ajouté dans transferMultiCurrency, PUIS le paramètre a
     * été supprimé — défense en profondeur : le moteur refuse, et l'API ne
     * permet même pas de formuler la demande.)
     */
    public function test_another_users_wallet_cannot_be_targeted_at_all(): void
    {
        $victim       = $this->createUser();
        $victimWallet = $this->createWallet($victim, 'EUR', '500.00');
        $victimBefore = $this->balance($victimWallet);

        $this->authenticateAs($this->userId); // l'attaquant

        $res = $this->convert([
            'amount'          => '100.00',
            'source_currency' => 'EUR',
            'dest_currency'   => 'USD',
        ]);

        // La conversion aboutit — mais sur les fonds de l'ATTAQUANT.
        $this->assertSame(200, $res['status']);
        $this->assertSame(
            $victimBefore,
            $this->balance($victimWallet),
            'Le wallet de la victime ne doit pas être touché : il est inatteignable.'
        );
        $this->assertSame(
            '900.00',
            $this->balance($this->eurWallet),
            'Seul le wallet de l\'appelant est débité.'
        );
    }

    /**
     * Le moteur lui-même refuse un wallet qui n'appartient pas à
     * l'utilisateur, même appelé directement.
     *
     * La route ne permet plus de formuler la demande, mais
     * `transferMultiCurrency()` reste appelable par d'autres chemins (jobs,
     * futures routes, maintenance). La protection doit vivre DANS le moteur,
     * pas seulement dans le contrôleur — sinon la prochaine route
     * réintroduira la faille.
     */
    public function test_the_engine_itself_refuses_a_foreign_wallet(): void
    {
        $victim       = $this->createUser();
        $victimWallet = $this->createWallet($victim, 'EUR', '500.00');
        $before       = $this->balance($victimWallet);

        $refused = false;
        try {
            \Nexus\Services\WalletService::transferMultiCurrency(
                new \Nexus\Models\TransferRequest(
                    userId:         $this->userId,   // l'attaquant
                    sourceWalletId: $victimWallet,   // le wallet de la victime
                    destWalletId:   $this->usdWallet,
                    sourceAmount:   '100.00',
                    sourceCurrency: 'EUR',
                    destCurrency:   'USD',
                    type:           'convert'
                )
            );
        } catch (HttpException $e) {
            $refused = true;
            $this->assertSame(404, $e->statusCode());
            $this->assertSame('WALLET_NOT_FOUND', $e->errorCode());
        } catch (\RuntimeException $e) {
            $refused = true;
        }

        $this->assertTrue($refused, 'Le moteur doit refuser un wallet étranger.');
        $this->assertSame($before, $this->balance($victimWallet), 'Aucun débit sur la victime.');
    }

    /**
     * L'autre sens, tout aussi important : on ne pousse pas d'argent vers le
     * wallet d'un tiers.
     *
     * Créditer autrui paraît inoffensif — ça ne l'est pas. C'est une écriture
     * non autorisée sur un compte qui n'est pas le sien : dépôt forcé,
     * gonflement artificiel d'un solde, contournement des règles de
     * provenance des fonds.
     *
     * (Une mutation supprimant ce contrôle a d'abord survécu : seul le wallet
     * source était testé.)
     */
    public function test_the_engine_refuses_to_credit_a_foreign_wallet(): void
    {
        $third       = $this->createUser();
        $thirdWallet = $this->createWallet($third, 'USD', '0.00');
        $before      = $this->balance($thirdWallet);

        $refused = false;
        try {
            \Nexus\Services\WalletService::transferMultiCurrency(
                new \Nexus\Models\TransferRequest(
                    userId:         $this->userId,
                    sourceWalletId: $this->eurWallet,
                    destWalletId:   $thirdWallet,    // wallet d'un tiers
                    sourceAmount:   '100.00',
                    sourceCurrency: 'EUR',
                    destCurrency:   'USD',
                    type:           'convert'
                )
            );
        } catch (HttpException | \RuntimeException $e) {
            $refused = true;
        }

        $this->assertTrue($refused, 'Créditer le wallet d\'un tiers doit être refusé.');
        $this->assertSame($before, $this->balance($thirdWallet), 'Le tiers ne doit pas être crédité.');
        $this->assertSame('1000.00', $this->balance($this->eurWallet), 'Et la source ne doit pas être débitée.');
    }

    // ══ 3. UN REFUS NE PRODUIT AUCUNE MUTATION ════════════════════════════

    public function test_an_insufficient_balance_leaves_everything_untouched(): void
    {
        $beforeSrc = $this->balance($this->eurWallet);
        $beforeDst = $this->balance($this->usdWallet);
        $beforeOps = $this->countOperations();

        $res = $this->convert([
            'amount'           => '999999.00',
            'source_currency'  => 'EUR',
            'dest_currency'    => 'USD',
        ]);

        $this->assertNotSame(200, $res['status']);
        $this->assertSame($beforeSrc, $this->balance($this->eurWallet));
        $this->assertSame($beforeDst, $this->balance($this->usdWallet));
        $this->assertSame($beforeOps, $this->countOperations(), 'Un refus ne doit produire aucune opération.');
    }

    /**
     * Convertir un wallet vers lui-même n'a pas de sens et doit être refusé
     * AVANT toute écriture : sans ce contrôle, le moteur verrouillerait deux
     * fois la même ligne.
     */
    public function test_converting_a_currency_into_itself_is_refused(): void
    {
        $res = $this->convert([
            'amount'           => '10.00',
            'source_currency'  => 'EUR',
            'dest_currency'    => 'EUR',
        ]);

        $this->assertSame(422, $res['status']);
        // La route raisonne en DEVISES : deux devises identiques désignent
        // forcément le même wallet. Le refus intervient donc plus tôt, sur un
        // motif plus clair pour l'utilisateur.
        $this->assertSame('SAME_CURRENCY', $res['code']);
        $this->assertSame('1000.00', $this->balance($this->eurWallet));
    }

    public function test_a_non_positive_amount_is_refused(): void
    {
        foreach (['0', '-10.00', 'abc', ''] as $bad) {
            $res = $this->convert([
                'amount'           => $bad,
                'source_currency'  => 'EUR',
                'dest_currency'    => 'USD',
            ]);

            $this->assertSame(422, $res['status'], sprintf('Montant « %s » doit être refusé.', $bad));
            $this->assertSame('1000.00', $this->balance($this->eurWallet));
        }
    }

    // ══ 4. IDEMPOTENCE ════════════════════════════════════════════════════

    /**
     * Deux appels avec la même clé ne doivent pas convertir deux fois : un
     * double-clic ou un retry réseau ne doit pas doubler le mouvement.
     */
    public function test_the_same_idempotency_key_does_not_convert_twice(): void
    {
        $payload = [
            'amount'           => '50.00',
            'source_currency'  => 'EUR',
            'dest_currency'    => 'USD',
            'idempotency_key'  => 'convert-' . bin2hex(random_bytes(6)),
        ];

        $first  = $this->convert($payload);
        $after  = $this->balance($this->eurWallet);
        $second = $this->convert($payload);

        $this->assertSame(200, $first['status']);
        $this->assertSame(
            $after,
            $this->balance($this->eurWallet),
            'Le rejeu d\'une clé d\'idempotence ne doit pas débiter une seconde fois.'
        );
        $this->assertSame('950.00', $after);
        $this->assertNotSame(0, $second['status']);
    }

    // ══ 5. ENVIRONNEMENT ══════════════════════════════════════════════════

    /**
     * La route est un chemin financier : elle ne doit pas laisser choisir un
     * environnement invalide, ni exécuter en production sans autorisation.
     */
    public function test_an_invalid_environment_is_refused(): void
    {
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'bogus';

        $res = $this->convert([
            'amount'           => '10.00',
            'source_currency'  => 'EUR',
            'dest_currency'    => 'USD',
        ]);

        $this->assertSame(400, $res['status']);
        $this->assertSame('ENVIRONMENT_INVALID', $res['code']);
        $this->assertSame('1000.00', $this->balance($this->eurWallet));
    }

    public function test_production_is_refused_without_explicit_authorization(): void
    {
        $_SERVER['HTTP_X_NEXUS_ENVIRONMENT'] = 'production';

        $res = $this->convert([
            'amount'           => '10.00',
            'source_currency'  => 'EUR',
            'dest_currency'    => 'USD',
        ]);

        $this->assertSame(403, $res['status']);
        $this->assertSame('ENVIRONMENT_NOT_ALLOWED', $res['code']);
        $this->assertSame('1000.00', $this->balance($this->eurWallet), 'Aucun mouvement en cas de refus.');
    }
}
