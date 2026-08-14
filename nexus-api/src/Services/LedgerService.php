<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Execution\ExecutionContext;
use Nexus\Providers\ProviderConfig;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * LedgerService — source de vérité comptable du Wallet NEXUS.
 *
 * Toute opération financière DOIT passer par ce service. Aucun code métier
 * ne doit modifier directement `wallets.balance` ou `wallets.available_balance`.
 *
 * Règles critiques :
 *   - Double-entrée : chaque mouvement produit au minimum un pair
 *     (debit + credit) lié par un `operation_id` (UUID v4).
 *   - Atomicité : `beginTransaction()` + `commit()` / `rollBack()`.
 *   - Verrouillage : `SELECT ... FOR UPDATE` sur chaque wallet lu avant
 *     modification de son solde. Empêche les race conditions.
 *   - Soldes négatifs interdits : vérification avant débit, exception
 *     levée si `balance < amount`.
 *   - Précision : tous les montants du ledger sont `DECIMAL(20,8)` et
 *     manipulés en chaîne pour éviter les erreurs float.
 *   - Traçabilité : chaque opération crée une entrée `wallet_operations`
 *     (status = initiated) et des écritures `ledger_entries` (debit/credit)
 *     dans la même transaction.
 *
 * Conventions :
 *   - `amount` est TOUJOURS positif. Le sens est porté par `entry_type`
 *     (`debit` = sortie pour ce wallet, `credit` = entrée pour ce wallet).
 *   - `balance_after` est le solde du wallet APRÈS application de l'écriture.
 *   - `sequence` ordonne les écritures au sein d'une même opération.
 *
 * Hors périmètre (implémenté dans des services séparés) :
 *   - Idempotence → `IdempotencyService` (Phase B3)
 *   - Taux de change → `FXService` (Phase D). LedgerService ne calcule
 *     JAMAIS le FX : il ne fait que persister `fx_rate` / `fx_source`
 *     fournis par l'appelant dans `wallet_operations`.
 *   - Lecture agrégée des soldes → `WalletService` (Phase B2)
 */
final class LedgerService
{
    /**
     * Longueur de la colonne `wallets.currency` (vérification runtime).
     * Les codes ISO 4217 font 3 caractères, les cryptos 3-5 (USDT, USDC).
     */
    private const CURRENCY_MAX_LEN = 5;

    /** Longueur maximale de la colonne `wallet_operations.id` (VARCHAR(36)). */
    private const OPERATION_ID_MAX_LEN = 36;

    private function __construct()
    {
        // Classe utilitaire : méthodes statiques uniquement.
    }

    // ──────────────────────────────────────────────────────────────────────
    // API publique
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Crédite un wallet (entrée de fonds).
     *
     * Produit :
     *   - 1 entrée `wallet_operations` (type = 'deposit' par défaut, ou $type)
     *   - 1 écriture `ledger_entries` (entry_type = 'credit')
     *   - Mise à jour de `wallets.balance` et `wallets.available_balance`
     *
     * Le wallet est verrouillé via `SELECT ... FOR UPDATE` avant la lecture.
     *
     * @param int                 $userId       Identifiant utilisateur (pour wallet_operations)
     * @param int                 $walletId     ID du wallet à créditer
     * @param string              $amount       Montant positif, en chaîne décimale (ex. "100.50")
     * @param string              $currency     Code devise (vérifié contre wallets.currency)
     * @param string              $type         Type d'opération (deposit|receive|welcome_bonus|refund|...)
     * @param string|null         $idempotencyKey Clé d'idempotence (obligatoire, NON contrôlée ici)
     * @param string|null         $description  Libellé métier
     * @param array<string,mixed>|null $metadata   Métadonnées JSON sérialisables
     *
     * @return string L'`operation_id` (UUID) partagé entre wallet_operations et ledger_entries.
     *
     * @throws RuntimeException Si le wallet n'existe pas, devise incorrecte,
     *                          montant invalide, ou erreur DB.
     */
    public static function credit(
        int $userId,
        int $walletId,
        string $amount,
        string $currency,
        string $type = 'deposit',
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?array $metadata = null,
        ?ExecutionContext $context = null
    ): string {
        self::assertPositiveAmount($amount);

        $pdo = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $current = self::lockAndLoadWallet($pdo, $walletId);
            self::assertCurrencyMatches($current, $currency);

            $operationId = self::generateUuid();
            $amountDec   = self::toDecimalString($amount);
            $newBalance  = self::addDecimal((string) $current['balance'], $amountDec);

            // Création de l'opération métier.
            self::insertWalletOperation(
                $pdo,
                $operationId,
                $userId,
                $type,
                'completed',
                null,
                null,
                null,
                $walletId,
                $currency,
                $amountDec,
                $description,
                $metadata,
                $idempotencyKey,
                null,
                null,
                $context
            );

            // Écriture ledger (credit).
            self::insertLedgerEntry(
                $pdo,
                $operationId,
                1,
                'credit',
                $walletId,
                $currency,
                $amountDec,
                $newBalance,
                $description,
                $type,
                $operationId,
                $metadata,
                $context?->environmentValue()
            );

            // Projection wallets.
            self::applyBalanceUpdate($pdo, $walletId, $current, $amountDec, /* isDebit */ false);

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $operationId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Débite un wallet (sortie de fonds).
     *
     * Vérifie que le solde est suffisant AVANT d'écrire. Lève une exception
     * `RuntimeException` si `balance < amount` (solde négatif interdit).
     *
     * Produit :
     *   - 1 entrée `wallet_operations` (type = 'withdrawal' par défaut, ou $type)
     *   - 1 écriture `ledger_entries` (entry_type = 'debit')
     *   - Mise à jour de `wallets.balance` et `wallets.available_balance`
     *
     * @param int                 $userId
     * @param int                 $walletId
     * @param string              $amount       Montant positif, en chaîne décimale.
     * @param string              $currency
     * @param string              $type         Type d'opération (withdrawal|send|fee|...)
     * @param string|null         $idempotencyKey
     * @param string|null         $description
     * @param array<string,mixed>|null $metadata
     *
     * @return string L'`operation_id` (UUID).
     *
     * @throws RuntimeException Si solde insuffisant, wallet inexistant, etc.
     */
    public static function debit(
        int $userId,
        int $walletId,
        string $amount,
        string $currency,
        string $type = 'withdrawal',
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?array $metadata = null,
        ?ExecutionContext $context = null
    ): string {
        self::assertPositiveAmount($amount);

        $pdo = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $current = self::lockAndLoadWallet($pdo, $walletId);
            self::assertCurrencyMatches($current, $currency);

            $amountDec = self::toDecimalString($amount);
            $currentBal = (string) $current['balance'];

            if (bccomp($currentBal, $amountDec, 8) < 0) {
                throw new RuntimeException(
                    sprintf(
                        'Solde insuffisant pour le débit (disponible: %s, demandé: %s, devise: %s).',
                        $currentBal,
                        $amountDec,
                        $currency
                    )
                );
            }

            $operationId = self::generateUuid();
            $newBalance  = self::subDecimal($currentBal, $amountDec);

            self::insertWalletOperation(
                $pdo,
                $operationId,
                $userId,
                $type,
                'completed',
                $walletId,
                $currency,
                $amountDec,
                null,
                null,
                null,
                $description,
                $metadata,
                $idempotencyKey,
                null,
                null,
                $context
            );

            self::insertLedgerEntry(
                $pdo,
                $operationId,
                1,
                'debit',
                $walletId,
                $currency,
                $amountDec,
                $newBalance,
                $description,
                $type,
                $operationId,
                $metadata,
                $context?->environmentValue()
            );

            self::applyBalanceUpdate($pdo, $walletId, $current, $amountDec, /* isDebit */ true);

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $operationId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Transfert entre deux wallets (potentiellement sur des devises différentes).
     *
     * Si `sourceCurrency === destCurrency`, le transfert est simple :
     *   - 1 écriture debit sur le wallet source
     *   - 1 écriture credit sur le wallet destination
     *   - 1 entrée wallet_operations
     *
     * Si les devises diffèrent, `$destAmount` doit être fourni en argument
     * (calcul FX par `FXService`, hors périmètre de ce service). Le service
     * de niveau supérieur (`WalletService::transferMultiCurrency`) est
     * responsable de la conversion.
     *
     * Important : pour un transfert, la SOMME des debits doit être égale à la
     * SOMME des credits dans la même devise. Pour deux devises différentes,
     * les écritures sont indépendantes (l'équilibre est porté par le taux FX
     * stocké dans `wallet_operations.fx_rate` et `metadata`).
     *
     * Ce service ne calcule JAMAIS de taux de change : `$fxRate` et
     * `$fxSource` sont uniquement PERSISTÉS dans `wallet_operations`.
     *
     * @param int                 $userId
     * @param int                 $sourceWalletId
     * @param int                 $destWalletId
     * @param string              $sourceAmount    Montant positif en devise source.
     * @param string              $sourceCurrency
     * @param string              $destAmount      Montant positif en devise destination.
     * @param string              $destCurrency
     * @param string              $type            Type d'opération (send|convert|...)
     * @param string|null         $idempotencyKey
     * @param string|null         $description
     * @param array<string,mixed>|null $metadata
     * @param string|null         $fxRate          Taux FX (décimal 8 chiffres) à persister, ou null.
     * @param string|null         $fxSource        Source du taux ('manual', 'identity', ...) à persister, ou null.
     * @param string|null         $operationId     UUID à utiliser (contrôle par la couche idempotence),
     *                                             ou null pour en générer un nouveau.
     *
     * @return string L'`operation_id` (UUID).
     *
     * @throws RuntimeException Si devises identiques et balance source insuffisante,
     *                          wallets inexistants, ou montants invalides.
     */
    public static function transfer(
        int $userId,
        int $sourceWalletId,
        int $destWalletId,
        string $sourceAmount,
        string $sourceCurrency,
        string $destAmount,
        string $destCurrency,
        string $type = 'send',
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?array $metadata = null,
        ?string $fxRate = null,
        ?string $fxSource = null,
        ?string $operationId = null,
        ?ExecutionContext $context = null
    ): string {
        self::assertPositiveAmount($sourceAmount);
        self::assertPositiveAmount($destAmount);

        if ($sourceWalletId === $destWalletId) {
            throw new RuntimeException('Les wallets source et destination doivent être distincts.');
        }

        $pdo = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            // Verrouillage dans un ordre déterministe (ID asc) pour éviter
            // les deadlocks lors de transferts concurrents entre mêmes wallets.
            [$firstId, $secondId] = $sourceWalletId < $destWalletId
                ? [$sourceWalletId, $destWalletId]
                : [$destWalletId, $sourceWalletId];

            $first  = self::lockAndLoadWallet($pdo, $firstId);
            $second = self::lockAndLoadWallet($pdo, $secondId);

            $source = $first['id'] === $sourceWalletId ? $first : $second;
            $dest   = $first['id'] === $destWalletId   ? $first : $second;

            self::assertCurrencyMatches($source, $sourceCurrency);
            self::assertCurrencyMatches($dest,   $destCurrency);

            $srcAmountDec = self::toDecimalString($sourceAmount);
            $dstAmountDec = self::toDecimalString($destAmount);

            // Vérification du solde DISPONIBLE source : aucun débit ne peut
            // excéder le solde disponible (balance - hold_balance), que les
            // devises soient identiques ou différentes.
            $sourceAvailable = (string) $source['available_balance'];
            if (bccomp($sourceAvailable, $srcAmountDec, 8) < 0) {
                throw new RuntimeException(
                    sprintf(
                        'Solde disponible insuffisant pour le transfert (disponible: %s, demandé: %s, devise: %s).',
                        $sourceAvailable,
                        $srcAmountDec,
                        $sourceCurrency
                    )
                );
            }

            $operationId ??= self::generateUuid();
            if (strlen($operationId) > self::OPERATION_ID_MAX_LEN) {
                throw new RuntimeException(
                    sprintf('operation_id trop long : %d caractères (max %d).', strlen($operationId), self::OPERATION_ID_MAX_LEN)
                );
            }

            $newSourceBal = self::subDecimal((string) $source['balance'], $srcAmountDec);
            $newDestBal   = self::addDecimal((string) $dest['balance'],   $dstAmountDec);

            // 1) wallet_operations (1 entrée, type = 'send' ou 'convert')
            self::insertWalletOperation(
                $pdo,
                $operationId,
                $userId,
                $type,
                'completed',
                $sourceWalletId,
                $sourceCurrency,
                $srcAmountDec,
                $destWalletId,
                $destCurrency,
                $dstAmountDec,
                $description,
                $metadata,
                $idempotencyKey,
                $fxRate,
                $fxSource,
                $context
            );

            // 2) ledger_entries : debit sur source (sequence 1)
            self::insertLedgerEntry(
                $pdo,
                $operationId,
                1,
                'debit',
                $sourceWalletId,
                $sourceCurrency,
                $srcAmountDec,
                $newSourceBal,
                $description,
                $type,
                $operationId,
                $metadata,
                $context?->environmentValue()
            );

            // 3) ledger_entries : credit sur destination (sequence 2)
            self::insertLedgerEntry(
                $pdo,
                $operationId,
                2,
                'credit',
                $destWalletId,
                $destCurrency,
                $dstAmountDec,
                $newDestBal,
                $description,
                $type,
                $operationId,
                $metadata,
                $context?->environmentValue()
            );

            // 4) Projection wallets : source
            self::applyBalanceUpdate($pdo, $sourceWalletId, $source, $srcAmountDec, /* isDebit */ true);
            // 5) Projection wallets : destination
            self::applyBalanceUpdate($pdo, $destWalletId, $dest, $dstAmountDec, /* isDebit */ false);

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $operationId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Comptabilise la capture d'un hold (débit définitif d'un montant réservé).
     *
     * Spécialisation du hold lifecycle (Phase F) :
     *   - le montant a DÉJÀ été retiré de `available_balance` lors de la
     *     création du hold (hold_balance) ;
     *   - la capture transforme cette réservation en débit définitif :
     *       balance          -= amount
     *       hold_balance     -= amount   (géré par WalletService, même transaction)
     *       available_balance = inchangé (le montant était déjà gelé)
     *   - contrairement à `debit()`, cette méthode N'INCRÉMENTE pas une
     *     nouvelle `wallet_operations` : elle réutilise l'`operation_id` du
     *     hold d'origine (sa transition pending → completed est gérée par
     *     `WalletService`).
     *
     * Produit :
     *   - 1 écriture `ledger_entries` (entry_type = 'debit') liée à
     *     l'`operation_id` du hold.
     *   - Mise à jour de `wallets.balance` (available_balance inchangé).
     *
     * @param int                 $walletId    Wallet sur lequel le hold avait été créé.
     * @param string              $amount      Montant capturé (positif, chaîne décimale).
     * @param string              $currency    Devise du wallet.
     * @param string              $operationId UUID de l'opération de hold d'origine.
     * @param string|null         $description Libellé métier.
     * @param array<string,mixed>|null $metadata Métadonnées JSON sérialisables.
     *
     * @throws RuntimeException Si wallet inexistant, devise incohérente,
     *                          solde insuffisant, ou erreur DB.
     */
    public static function recordHoldCapture(
        int $walletId,
        string $amount,
        string $currency,
        string $operationId,
        ?string $description = null,
        ?array $metadata = null
    ): void {
        self::assertPositiveAmount($amount);
        if ($operationId === '' || strlen($operationId) > self::OPERATION_ID_MAX_LEN) {
            throw new RuntimeException(
                sprintf('operation_id invalide pour la capture du hold : "%s".', $operationId)
            );
        }

        $pdo = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $current = self::lockAndLoadWallet($pdo, $walletId);
            self::assertCurrencyMatches($current, $currency);

            // §10/§13 — l'environnement de la capture est LU depuis
            // l'opération d'origine, jamais recalculé depuis la
            // configuration courante : un hold posé en sandbox se capture en
            // sandbox, même si le serveur a basculé entre-temps.
            $stmtEnv = $pdo->prepare('SELECT environment FROM wallet_operations WHERE id = :id LIMIT 1');
            $stmtEnv->execute(['id' => $operationId]);
            $sourceEnv = $stmtEnv->fetchColumn();
            $sourceEnv = is_string($sourceEnv) && $sourceEnv !== '' ? $sourceEnv : null;

            $amountDec  = self::toDecimalString($amount);
            $currentBal = (string) $current['balance'];

            // Garde-fou : le solde total doit couvrir le débit. Le contrôle
            // hold_balance >= amount est effectué par WalletService sous
            // verrou (même transaction).
            if (bccomp($currentBal, $amountDec, 8) < 0) {
                throw new RuntimeException(
                    sprintf(
                        'Solde insuffisant pour la capture du hold (disponible: %s, demandé: %s, devise: %s).',
                        $currentBal,
                        $amountDec,
                        $currency
                    )
                );
            }

            $newBalance = self::subDecimal($currentBal, $amountDec);

            // Écriture ledger : débit lié à l'operation_id du hold d'origine.
            self::insertLedgerEntry(
                $pdo,
                $operationId,
                1,
                'debit',
                $walletId,
                $currency,
                $amountDec,
                $newBalance,
                $description,
                'hold_capture',
                $operationId,
                $metadata,
                $sourceEnv
            );

            // Projection wallets : balance -= amount, available_balance inchangé
            // (le montant était déjà gelé dans hold_balance).
            self::applyBalanceUpdate($pdo, $walletId, $current, $amountDec, /* isDebit */ true, /* isHoldCapture */ true);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Vérifie qu'une opération est comptablement équilibrée.
     *
     * Calcule, pour l'`operation_id` donné, la somme des debits et la somme
     * des credits PAR DEVISE. Pour qu'une opération soit équilibrée :
     *   - Si une seule devise est impliquée : SUM(debit) === SUM(credit)
     *   - Si plusieurs devises sont impliquées : on ne peut PAS vérifier
     *     l'équilibre arithmétique (il faut le taux FX). Dans ce cas, on
     *     vérifie au minimum que les écritures existent par paires
     *     (au moins un debit ET au moins un credit).
     *
     * @param string $operationId UUID de l'opération.
     * @return bool true si l'opération est équilibrée, false sinon.
     */
    public static function verifyOperation(string $operationId): bool
    {
        $pdo = Database::getConnection();

        // Récupération groupée par devise.
        $stmt = $pdo->prepare(
            'SELECT wallet_currency,
                    SUM(CASE WHEN entry_type = \'debit\'  THEN amount ELSE 0 END) AS total_debit,
                    SUM(CASE WHEN entry_type = \'credit\' THEN amount ELSE 0 END) AS total_credit,
                    COUNT(*) AS entry_count
             FROM ledger_entries
             WHERE operation_id = :opid
             GROUP BY wallet_currency'
        );
        $stmt->execute(['opid' => $operationId]);
        $rows = $stmt->fetchAll();

        if (count($rows) === 0) {
            return false;
        }

        // Vérifie la présence d'au moins un debit et un credit globalement.
        $hasDebit  = false;
        $hasCredit = false;
        foreach ($rows as $row) {
            if ((float) $row['total_debit']  > 0) { $hasDebit  = true; }
            if ((float) $row['total_credit'] > 0) { $hasCredit = true; }
        }
        if (!$hasDebit || !$hasCredit) {
            return false;
        }

        // Si une seule devise : équilibre arithmétique strict.
        if (count($rows) === 1) {
            $row = $rows[0];
            return bccomp(
                (string) $row['total_debit'],
                (string) $row['total_credit'],
                8
            ) === 0;
        }

        // Multi-devises : on ne peut pas vérifier arithmétiquement, mais
        // on s'assure qu'aucune devise n'est en déséquilibre interne.
        // (Le solde par devise doit toujours être équilibré : un debit
        // dans une devise doit avoir son credit dans la même devise.)
        foreach ($rows as $row) {
            if (bccomp((string) $row['total_debit'], (string) $row['total_credit'], 8) !== 0) {
                return false;
            }
        }
        return true;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers privés
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Verrouille un wallet via `SELECT ... FOR UPDATE` et retourne ses données.
     * Lève une exception si le wallet n'existe pas.
     *
     * @return array<string,mixed> Ligne wallets (id, user_id, currency, balance, available_balance, hold_balance).
     */
    private static function lockAndLoadWallet(PDO $pdo, int $walletId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, currency, balance, available_balance, hold_balance
             FROM wallets
             WHERE id = :id
             FOR UPDATE'
        );
        $stmt->execute(['id' => $walletId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException(
                sprintf('Wallet introuvable (id=%d).', $walletId)
            );
        }
        return $row;
    }

    /**
     * Insère une entrée `wallet_operations`.
     *
     * @param string|null $fxRate    Taux FX à persister (null si non applicable).
     * @param string|null $fxSource  Source du taux ('manual', 'identity', ...), null si non applicable.
     */
    private static function insertWalletOperation(
        PDO $pdo,
        string $operationId,
        int $userId,
        string $type,
        string $status,
        ?int $sourceWalletId,
        ?string $sourceCurrency,
        ?string $sourceAmount,
        ?int $destWalletId,
        ?string $destCurrency,
        ?string $destAmount,
        ?string $description,
        ?array $metadata,
        ?string $idempotencyKey,
        ?string $fxRate = null,
        ?string $fxSource = null,
        ?ExecutionContext $context = null
    ): void {
        // Si pas de clé d'idempotence fournie, on en génère une déterministe
        // basée sur l'operationId pour respecter la contrainte UNIQUE.
        // L'idempotence réelle est gérée par IdempotencyService (Phase B3).
        $idemKey = $idempotencyKey !== null && $idempotencyKey !== ''
            ? $idempotencyKey
            : ('auto:' . $operationId);

        $metaJson = $metadata !== null
            ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $stmt = $pdo->prepare(
            'INSERT INTO wallet_operations
                (id, user_id, type, status,
                 source_wallet_id, source_currency, source_amount,
                 dest_wallet_id, dest_currency, dest_amount,
                 fee_amount, fee_currency,
                 fx_rate, fx_source,
                 description, metadata,
                 idempotency_key, environment, completed_at)
             VALUES
                (:id, :uid, :type, :status,
                 :src_wid, :src_cur, :src_amt,
                 :dst_wid, :dst_cur, :dst_amt,
                 0, NULL,
                 :fx_rate, :fx_source,
                 :desc, :meta,
                 :idem, :env, NOW())'
        );

        $stmt->execute([
            'id'       => $operationId,
            'uid'      => $userId,
            'type'     => $type,
            'status'   => $status,
            'src_wid'  => $sourceWalletId,
            'src_cur'  => $sourceCurrency,
            'src_amt'  => $sourceAmount,
            'dst_wid'  => $destWalletId,
            'dst_cur'  => $destCurrency,
            'dst_amt'  => $destAmount,
            'fx_rate'  => $fxRate,
            'fx_source'=> $fxSource,
            'desc'     => $description,
            'meta'     => $metaJson,
            'idem'     => $idemKey,
            // §10 — l'environnement de l'opération est fixé à sa création et
            // reste stable pendant tout son cycle de vie.
            'env'      => $context?->environmentValue() ?? ProviderConfig::defaultEnvironment(),
        ]);
    }

    /**
     * Insère une écriture `ledger_entries`.
     */
    private static function insertLedgerEntry(
        PDO $pdo,
        string $operationId,
        int $sequence,
        string $entryType,
        int $walletId,
        string $currency,
        string $amount,
        string $balanceAfter,
        ?string $description,
        ?string $referenceType,
        ?string $referenceId,
        ?array $metadata,
        ?string $environment = null
    ): void {
        $metaJson = $metadata !== null
            ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $stmt = $pdo->prepare(
            'INSERT INTO ledger_entries
                (operation_id, sequence, entry_type,
                 wallet_id, wallet_currency,
                 amount, balance_after,
                 description, reference_type, reference_id,
                 metadata, environment)
             VALUES
                (:opid, :seq, :etype,
                 :wid, :cur,
                 :amt, :bal,
                 :desc, :reftype, :refid,
                 :meta, :env)'
        );
        $stmt->execute([
            'opid'    => $operationId,
            'seq'     => $sequence,
            'etype'   => $entryType,
            'wid'     => $walletId,
            'cur'     => $currency,
            'amt'     => $amount,
            'bal'     => $balanceAfter,
            'desc'    => $description,
            'reftype' => $referenceType,
            'refid'   => $referenceId,
            'meta'    => $metaJson,
            // §13 — jamais le défaut global du serveur pour une écriture liée
            // à une opération existante : l'environnement est hérité de la
            // source, ou résolu par l'appelant.
            'env'     => $environment ?? ProviderConfig::defaultEnvironment(),
        ]);
    }

    /**
     * Met à jour `wallets.balance` et `wallets.available_balance` après
     * verrouillage préalable du wallet. Pour les credits, on met aussi à
     * jour `available_balance` (les fonds deviennent immédiatement
     * disponibles sauf si un hold les bloque — géré par `WalletService`).
     *
     * Note : `available_balance` est mis à jour identiquement à `balance`
     * ici. La logique de hold est externalisée dans `WalletService` (B2).
     */
    private static function applyBalanceUpdate(
        PDO $pdo,
        int $walletId,
        array $current,
        string $amountDec,
        bool $isDebit,
        bool $isHoldCapture = false
    ): void {
        $currentBal = (string) $current['balance'];
        $newBalance = $isDebit
            ? self::subDecimal($currentBal, $amountDec)
            : self::addDecimal($currentBal, $amountDec);

        // available_balance suit balance - hold_balance.
        // hold_balance n'est pas modifié par LedgerService (géré par WalletService).
        $currentHold = (string) $current['hold_balance'];
        
        if ($isHoldCapture) {
            // Lors d'une capture, on a déjà déduit le montant de available_balance lors du createHold.
            // balance diminue, hold_balance diminuera via WalletService.
            // available_balance = balance - hold_balance.
            // Comme balance et hold_balance diminuent du même montant, available_balance reste identique.
            $newAvailable = self::subDecimal($newBalance, self::subDecimal($currentHold, $amountDec));
        } else {
            $newAvailable = self::subDecimal($newBalance, $currentHold);
        }

        $stmt = $pdo->prepare(
            'UPDATE wallets
             SET balance = :bal,
                 available_balance = :avail
             WHERE id = :id'
        );
        $stmt->execute([
            'bal'   => $newBalance,
            'avail' => $newAvailable,
            'id'    => $walletId,
        ]);
    }

    /**
     * Vérifie que le montant est strictement positif et bien formé.
     */
    private static function assertPositiveAmount(string $amount): void
    {
        if ($amount === '' || !preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new RuntimeException(
                sprintf('Montant invalide : "%s". Format attendu : entier ou décimal positif.', $amount)
            );
        }
        if (bccomp($amount, '0', 8) <= 0) {
            throw new RuntimeException(
                sprintf('Le montant doit être strictement positif : "%s".', $amount)
            );
        }
    }

    /**
     * Vérifie que la devise passée en argument correspond à celle du wallet.
     */
    private static function assertCurrencyMatches(array $wallet, string $currency): void
    {
        if (strtoupper($wallet['currency']) !== strtoupper($currency)) {
            throw new RuntimeException(
                sprintf(
                    'Devise incohérente : wallet=%s, argument=%s.',
                    $wallet['currency'],
                    $currency
                )
            );
        }
        if (strlen($currency) > self::CURRENCY_MAX_LEN) {
            throw new RuntimeException(
                sprintf('Code devise trop long (max %d) : "%s".', self::CURRENCY_MAX_LEN, $currency)
            );
        }
    }

    /**
     * Normalise un montant en chaîne décimale avec 8 décimales (échelle ledger).
     */
    private static function toDecimalString(string $amount): string
    {
        // bcadd avec 8 décimales pour normaliser.
        return bcadd($amount, '0', 8);
    }

    /**
     * Addition décimale (échelle 8).
     */
    private static function addDecimal(string $a, string $b): string
    {
        return bcadd($a, $b, 8);
    }

    /**
     * Soustraction décimale (échelle 8).
     */
    private static function subDecimal(string $a, string $b): string
    {
        return bcsub($a, $b, 8);
    }

    /**
     * Génère un UUID v4 (sans dépendance externe).
     */
    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
