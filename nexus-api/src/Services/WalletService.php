<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * WalletService — couche de lecture/projection et gestion des wallets.
 *
 * Fournit un accès cohérent aux données de la table `wallets` sans dupliquer
 * la logique comptable du LedgerService. Respecte la règle métier :
 *   available_balance = balance - hold_balance
 *
 * Ne modifie jamais directement les soldes ; la source de vérité reste le ledger.
 */
use Nexus\Models\FXRate;
use Nexus\Models\TransferRequest;
use Nexus\Models\TransferResult;
use Nexus\Services\FXService;
use Nexus\Services\IdempotencyService;

final class WalletService
{
    /** Devises supportées (alignées avec Currency::ALL). */
    private const SUPPORTED_CURRENCIES = ['EUR', 'USD', 'GBP', 'XAF', 'USDT', 'USDC'];

    /**
     * Tolérance d'arrondi de la projection `wallets.hold_balance` (DECIMAL(20,2)).
     *
     * La projection est arrondie au centime (2 dp) alors que la source de vérité
     * (`wallet_operations.source_amount`) est stockée à 8 dp. Un hold dont le
     * montant possède plus de 2 décimales peut donc être arrondi vers le bas
     * (ex : 1.12345678 → 1.12). L'écart maximal introduit par cet arrondi est
     * d'un demi-centime (0.005). Le garde-fou de capture/libération compare donc
     * `hold_balance` à `amount - 0.005` pour ne pas rejeter un hold valide.
     */
    private const HOLD_PROJECTION_TOLERANCE = '0.005';

    private function __construct()
    {
        // Utilitaire statique uniquement.
    }

    /**
     * Récupère un wallet pour un utilisateur et une devise.
     *
     * @param int    $userId   ID utilisateur (>0)
     * @param string $currency Code devise (ex: 'EUR', 'USD')
     *
     * @return array<string,mixed>|null Tableau wallet (id, user_id, currency, balance,
     *         available_balance, hold_balance, pending_balance, in_transit_balance,
     *         settlement_balance, created_at, updated_at) ou null si absent.
     */
    public static function getWallet(int $userId, string $currency): ?array
    {
        self::assertUserId($userId);
        self::assertCurrency($currency);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, user_id, currency, balance, available_balance, hold_balance,
                    pending_balance, in_transit_balance, settlement_balance,
                    created_at, updated_at
             FROM wallets
             WHERE user_id = :uid AND currency = :cur
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'cur' => $currency]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return self::formatWallet($row);
    }

    /**
     * Récupère tous les wallets d'un utilisateur.
     *
     * @param int $userId ID utilisateur (>0)
     *
     * @return array<int,array<string,mixed>> Liste de wallets formatés.
     */
    public static function getAllWallets(int $userId): array
    {
        self::assertUserId($userId);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, user_id, currency, balance, available_balance, hold_balance,
                    pending_balance, in_transit_balance, settlement_balance,
                    created_at, updated_at
             FROM wallets
             WHERE user_id = :uid
             ORDER BY currency'
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        $wallets = [];
        foreach ($rows as $row) {
            $wallets[] = self::formatWallet($row);
        }

        return $wallets;
    }

    /**
     * Récupère les soldes agrégés (alias de getAllWallets pour compatibilité).
     *
     * @param int $userId ID utilisateur (>0)
     *
     * @return array<int,array<string,mixed>> Même structure que getAllWallets.
     */
    public static function getAllBalances(int $userId): array
    {
        return self::getAllWallets($userId);
    }

    /**
     * Crée un hold (réservation de fonds) sur un wallet.
     *
     * Le montant est déplacé de available_balance vers hold_balance.
     * Aucune écriture ledger n'est créée.
     *
     * @param int    $userId
     * @param int    $walletId
     * @param string $amount
     * @param string $currency
     * @param string|null $idempotencyKey
     * @param string|null $description
     * @param array<string,mixed>|null $metadata
     *
     * @return array{operation_id: string, status: string}
     * @throws RuntimeException Si solde insuffisant ou erreur validation.
     */
    public static function createHold(
        int $userId,
        int $walletId,
        string $amount,
        string $currency,
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?array $metadata = null
    ): array {
        self::assertUserId($userId);
        self::assertWalletId($walletId);
        self::assertAmount($amount);
        self::assertCurrency($currency);

        $useIdempotency = $idempotencyKey !== null && $idempotencyKey !== '';
        if ($useIdempotency) {
            $cached = IdempotencyService::check($idempotencyKey, $userId);
            if ($cached !== null && $cached['status'] === 'completed') {
                // Replay : réponse précédente, aucune nouvelle écriture.
                return [
                    'operation_id' => (string) $cached['operation_id'],
                    'status'       => (string) ($cached['response_json']['status'] ?? 'pending'),
                ];
            }
            if ($cached !== null && $cached['status'] === 'error') {
                throw new RuntimeException(
                    (string) ($cached['response_json']['error'] ?? 'Opération précédente en échec.')
                );
            }
            if ($cached !== null) {
                throw new RuntimeException('Opération déjà en cours');
            }
        }

        $operationId = self::generateUuid();

        // Réservation de la clé (status = processing) avant toute écriture.
        if ($useIdempotency) {
            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId);
            if (!$state['created']) {
                if ($state['status'] === 'completed') {
                    return [
                        'operation_id' => (string) $state['operation_id'],
                        'status'       => (string) ($state['response_json']['status'] ?? 'pending'),
                    ];
                }
                if ($state['status'] === 'error') {
                    throw new RuntimeException(
                        (string) ($state['response_json']['error'] ?? 'Opération précédente en échec.')
                    );
                }
                throw new RuntimeException('Opération déjà en cours');
            }
        }

        $pdo = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // 1. Lock et chargement du wallet
            $stmt = $pdo->prepare('SELECT * FROM wallets WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $walletId]);
            $wallet = $stmt->fetch();

            if (!$wallet) {
                throw new RuntimeException("Wallet introuvable (id=$walletId).");
            }

            if (strtoupper($wallet['currency']) !== strtoupper($currency)) {
                throw new RuntimeException("Devise incohérente.");
            }

            // 2. Vérification solde disponible
            $available = (string)$wallet['available_balance'];
            $amountDec = bcadd($amount, '0', 8);
            if (bccomp($available, $amountDec, 8) < 0) {
                throw new RuntimeException("Solde disponible insuffisant pour le hold.");
            }

            // 3. Mise à jour projections
            $newHold = bcadd((string)$wallet['hold_balance'], $amountDec, 8);
            $newAvail = bcsub($available, $amountDec, 8);

            $upd = $pdo->prepare('UPDATE wallets SET hold_balance = :hold, available_balance = :avail WHERE id = :id');
            $upd->execute(['hold' => $newHold, 'avail' => $newAvail, 'id' => $walletId]);

            // 4. Set expiration (default 30 minutes) and create operation
            $ttl = getenv('HOLD_TTL_SECONDS');
            if ($ttl === false || $ttl === '' || !ctype_digit($ttl)) {
                $ttl = 1800; // 30 minutes
            }
            $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . (int)$ttl . ' seconds')->format('Y-m-d H:i:s');
            $stmtOp = $pdo->prepare(
                'INSERT INTO wallet_operations (id, user_id, type, status, source_wallet_id, source_currency, source_amount, description, metadata, idempotency_key, expires_at)
                 VALUES (:id, :uid, \'hold\', \'pending\', :swid, :scur, :samt, :desc, :meta, :idem, :expires_at)'
            );
            $stmtOp->execute([
                'id' => $operationId,
                'uid' => $userId,
                'swid' => $walletId,
                'scur' => $currency,
                'samt' => $amountDec,
                'desc' => $description,
                'meta' => $metadata ? json_encode($metadata) : null,
                'idem' => $idempotencyKey ?? ('auto:' . $operationId),
                'expires_at' => $expiresAt,
            ]);

            if ($useIdempotency) {
                IdempotencyService::complete($idempotencyKey, $userId, ['operation_id' => $operationId, 'status' => 'pending'], $operationId);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['operation_id' => $operationId, 'status' => 'pending'];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // L'échec est mémorisé sur la clé (status=error) pour les replays.
            if ($useIdempotency) {
                try {
                    IdempotencyService::fail($idempotencyKey, $userId, $e->getMessage(), $operationId);
                } catch (Throwable $ignore) {
                    // Ne masque jamais l'erreur d'origine.
                }
            }
            throw $e;
        }
    }

    /**
     * Capture un hold existant (le transforme en débit définitif).
     *
     * @param string $operationId UUID de l'opération de hold.
     * @param int    $userId      ID utilisateur pour vérification et idempotence.
     * @param string|null $idempotencyKey
     *
     * @return array{operation_id: string, status: string}
     * @throws RuntimeException Si l'opération n'est pas un hold pending ou solde insuffisant.
     */
    public static function captureHold(string $operationId, int $userId, ?string $idempotencyKey = null): array
    {
        self::assertUserId($userId);

        $useIdempotency = $idempotencyKey !== null && $idempotencyKey !== '';
        if ($useIdempotency) {
            $cached = IdempotencyService::check($idempotencyKey, $userId);
            if ($cached !== null && $cached['status'] === 'completed') {
                // Replay : réponse précédente, aucune nouvelle écriture.
                return [
                    'operation_id' => (string) $cached['operation_id'],
                    'status'       => 'completed',
                ];
            }
            if ($cached !== null && $cached['status'] === 'error') {
                throw new RuntimeException(
                    (string) ($cached['response_json']['error'] ?? 'Opération précédente en échec.')
                );
            }
            if ($cached !== null) {
                throw new RuntimeException('Opération déjà en cours');
            }
        }

        // Réservation de la clé (status = processing) avant toute écriture.
        if ($useIdempotency) {
            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId);
            if (!$state['created']) {
                if ($state['status'] === 'completed') {
                    return ['operation_id' => $operationId, 'status' => 'completed'];
                }
                if ($state['status'] === 'error') {
                    throw new RuntimeException(
                        (string) ($state['response_json']['error'] ?? 'Opération précédente en échec.')
                    );
                }
                throw new RuntimeException('Opération déjà en cours');
            }
        }

        $pdo = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // 1. Lock et validation de l'opération
            $stmtOp = $pdo->prepare('SELECT * FROM wallet_operations WHERE id = :id FOR UPDATE');
            $stmtOp->execute(['id' => $operationId]);
            $op = $stmtOp->fetch();

            if (!$op) {
                throw new RuntimeException("Opération introuvable.");
            }
            if ($op['user_id'] !== $userId) {
                throw new RuntimeException("Opération n'appartient pas à l'utilisateur.");
            }
            if ($op['type'] !== 'hold') {
                throw new RuntimeException("L'opération n'est pas un hold.");
            }
            if ($op['status'] !== 'pending') {
                throw new RuntimeException("Le hold n'est plus dans un état capturable (status=" . $op['status'] . ").");
            }

            $walletId = (int)$op['source_wallet_id'];
            $amount    = (string)$op['source_amount'];
            $currency  = (string)$op['source_currency'];

            // 2. Lock et validation du wallet
            $stmtW = $pdo->prepare('SELECT * FROM wallets WHERE id = :id FOR UPDATE');
            $stmtW->execute(['id' => $walletId]);
            $wallet = $stmtW->fetch();

            if (!$wallet) {
                throw new RuntimeException("Wallet associé introuvable.");
            }

            // Garde-fou : la projection hold_balance (2 dp) peut être arrondie
            // vers le bas par rapport au montant exact (8 dp) ; on tolère
            // l'écart d'arrondi maximal (0.005) défini par HOLD_PROJECTION_TOLERANCE.
            $minHoldForCapture = bcsub($amount, self::HOLD_PROJECTION_TOLERANCE, 8);
            if (bccomp((string)$wallet['hold_balance'], $minHoldForCapture, 8) < 0) {
                throw new RuntimeException("Hold balance insuffisante pour la capture.");
            }

            // 3. Comptabilisation financière via LedgerService (débit définitif).
            // Le montant était déjà gelé : balance -= amount, available inchangé.
            LedgerService::recordHoldCapture(
                walletId: $walletId,
                amount: $amount,
                currency: $currency,
                operationId: $operationId,
                description: 'Capture de hold',
                metadata: $op['metadata'] !== null ? json_decode((string) $op['metadata'], true) : null
            );

            // 4. Mise à jour hold_balance (le débit balance a été appliqué par LedgerService)
            $newHold = bcsub((string)$wallet['hold_balance'], $amount, 8);
            $upd = $pdo->prepare('UPDATE wallets SET hold_balance = :hold WHERE id = :id');
            $upd->execute(['hold' => $newHold, 'id' => $walletId]);

            // 5. Mise à jour opération
            $updOp = $pdo->prepare('UPDATE wallet_operations SET status = \'completed\', completed_at = NOW() WHERE id = :id');
            $updOp->execute(['id' => $operationId]);

            if ($useIdempotency) {
                IdempotencyService::complete($idempotencyKey, $userId, ['operation_id' => $operationId, 'status' => 'completed'], $operationId);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['operation_id' => $operationId, 'status' => 'completed'];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // L'échec est mémorisé sur la clé (status=error) pour les replays.
            if ($useIdempotency) {
                try {
                    IdempotencyService::fail($idempotencyKey, $userId, $e->getMessage(), $operationId);
                } catch (Throwable $ignore) {
                    // Ne masque jamais l'erreur d'origine.
                }
            }
            throw $e;
        }
    }

    /**
     * Libère un hold existant (rend les fonds disponibles).
     *
     * @param string $operationId UUID de l'opération de hold.
     * @param int    $userId      ID utilisateur pour vérification et idempotence.
     * @param string|null $idempotencyKey
     *
     * @return array{operation_id: string, status: string}
     * @throws RuntimeException Si l'opération n'est pas un hold pending ou solde insuffisant.
     */
    public static function releaseHold(string $operationId, int $userId, ?string $idempotencyKey = null): array
    {
        self::assertUserId($userId);

        $useIdempotency = $idempotencyKey !== null && $idempotencyKey !== '';
        if ($useIdempotency) {
            $cached = IdempotencyService::check($idempotencyKey, $userId);
            if ($cached !== null && $cached['status'] === 'completed') {
                // Replay : réponse précédente, aucune nouvelle écriture.
                return [
                    'operation_id' => (string) $cached['operation_id'],
                    'status'       => 'cancelled',
                ];
            }
            if ($cached !== null && $cached['status'] === 'error') {
                throw new RuntimeException(
                    (string) ($cached['response_json']['error'] ?? 'Opération précédente en échec.')
                );
            }
            if ($cached !== null) {
                throw new RuntimeException('Opération déjà en cours');
            }
        }

        // Réservation de la clé (status = processing) avant toute écriture.
        if ($useIdempotency) {
            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId);
            if (!$state['created']) {
                if ($state['status'] === 'completed') {
                    return ['operation_id' => $operationId, 'status' => 'cancelled'];
                }
                if ($state['status'] === 'error') {
                    throw new RuntimeException(
                        (string) ($state['response_json']['error'] ?? 'Opération précédente en échec.')
                    );
                }
                throw new RuntimeException('Opération déjà en cours');
            }
        }

        $pdo = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // 1. Lock et validation de l'opération
            $stmtOp = $pdo->prepare('SELECT * FROM wallet_operations WHERE id = :id FOR UPDATE');
            $stmtOp->execute(['id' => $operationId]);
            $op = $stmtOp->fetch();

            if (!$op) {
                throw new RuntimeException("Opération introuvable.");
            }
            if ($op['user_id'] !== $userId) {
                throw new RuntimeException("Opération n'appartient pas à l'utilisateur.");
            }
            if ($op['type'] !== 'hold') {
                throw new RuntimeException("L'opération n'est pas un hold.");
            }
            if ($op['status'] !== 'pending') {
                throw new RuntimeException("Le hold n'est plus dans un état libérable (status=" . $op['status'] . ").");
            }

            $walletId = (int)$op['source_wallet_id'];
            $amount    = (string)$op['source_amount'];

            // 2. Lock et validation du wallet
            $stmtW = $pdo->prepare('SELECT * FROM wallets WHERE id = :id FOR UPDATE');
            $stmtW->execute(['id' => $walletId]);
            $wallet = $stmtW->fetch();

            if (!$wallet) {
                throw new RuntimeException("Wallet associé introuvable.");
            }

            // Garde-fou : même logique que la capture — la projection 2 dp peut
            // être arrondie vers le bas par rapport au montant exact (8 dp).
            $minHoldForRelease = bcsub($amount, self::HOLD_PROJECTION_TOLERANCE, 8);
            if (bccomp((string)$wallet['hold_balance'], $minHoldForRelease, 8) < 0) {
                throw new RuntimeException("Hold balance insuffisante pour la libération.");
            }

            // 3. Mise à jour projections (Pas de ledger entry pour une release)
            $newHold = bcsub((string)$wallet['hold_balance'], $amount, 8);
            $newAvail = bcadd((string)$wallet['available_balance'], $amount, 8);

            $upd = $pdo->prepare('UPDATE wallets SET hold_balance = :hold, available_balance = :avail WHERE id = :id');
            $upd->execute(['hold' => $newHold, 'avail' => $newAvail, 'id' => $walletId]);

            // 4. Mise à jour opération
            $updOp = $pdo->prepare('UPDATE wallet_operations SET status = \'cancelled\' WHERE id = :id');
            $updOp->execute(['id' => $operationId]);

            if ($useIdempotency) {
                IdempotencyService::complete($idempotencyKey, $userId, ['operation_id' => $operationId, 'status' => 'cancelled'], $operationId);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['operation_id' => $operationId, 'status' => 'cancelled'];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // L'échec est mémorisé sur la clé (status=error) pour les replays.
            if ($useIdempotency) {
                try {
                    IdempotencyService::fail($idempotencyKey, $userId, $e->getMessage(), $operationId);
                } catch (Throwable $ignore) {
                    // Ne masque jamais l'erreur d'origine.
                }
            }
            throw $e;
        }
    }

    /**
     * Calcule et retourne le solde disponible pour un wallet donné.
     *
     * @param int $walletId ID wallet (>0)
     *
     * @return array<string,mixed> Clés : wallet_id, currency, balance, hold_balance, available_balance.
     */
    public static function getAvailable(int $walletId): array
    {
        self::assertWalletId($walletId);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, currency, balance, hold_balance
             FROM wallets
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $walletId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException(sprintf('Wallet introuvable (id=%d).', $walletId));
        }

        $balance       = (string) $row['balance'];
        $holdBalance   = (string) $row['hold_balance'];
        $available     = self::subDecimal($balance, $holdBalance);

        return [
            'wallet_id'      => (int) $row['id'],
            'currency'       => (string) $row['currency'],
            'balance'        => $balance,
            'hold_balance'   => $holdBalance,
            'available_balance' => $available,
        ];
    }

    /**
     * Garantit l'existence d'un wallet pour un utilisateur/devise.
     * Idempotent : respecte la contrainte UNIQUE uq_wallets_user_currency.
     *
     * @param int    $userId   ID utilisateur (>0)
     * @param string $currency Code devise (ex: 'EUR')
     *
     * @return array<string,mixed> Wallet créé ou existant (formaté).
     */
    public static function ensureWallet(int $userId, string $currency): array
    {
        self::assertUserId($userId);
        self::assertCurrency($currency);

        $pdo = Database::getConnection();

        // Tentative d'insertion idempotente via INSERT ... ON DUPLICATE KEY UPDATE
        $stmt = $pdo->prepare(
            'INSERT INTO wallets (user_id, currency, balance, available_balance,
                                 pending_balance, in_transit_balance, settlement_balance, hold_balance)
             VALUES (:uid, :cur, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $stmt->execute(['uid' => $userId, 'cur' => $currency]);

        $walletId = (int) $pdo->lastInsertId();
        if ($walletId === 0) {
            throw new RuntimeException(
                sprintf('Impossible de créer/retrouver wallet (user_id=%d, currency=%s).', $userId, $currency)
            );
        }

        $wallet = self::getWallet($userId, $currency);
        if ($wallet === null) {
            throw new RuntimeException(
                sprintf('Wallet introuvable après création (user_id=%d, currency=%s).', $userId, $currency)
            );
        }

        return $wallet;
    }

    /**
     * Effectue un transfert entre deux wallets, avec gestion des devises différentes.
     *
     * Orchestration (Phase D, Option B) :
     *   1. validation des montants, devises et wallets ;
     *   2. idempotence via IdempotencyService (check/start/complete/fail) ;
     *   3. résolution du taux via FXService (FXService → FXRateCache → ManualRateProvider) ;
     *   4. calcul du montant destination en BCMath, arrondi HALF_UP à 8 décimales ;
     *   5. UNE seule wallet_operations + DEUX ledger_entries (debit/credit)
     *      liées au même operation_id, via LedgerService::transfer() ;
     *   6. persistance de fx_rate / fx_source dans wallet_operations ;
     *   7. transaction atomique (LedgerService + IdempotencyService::complete
     *      participent à la même transaction PDO) ;
     *   8. retour d'un TransferResult sérialisable.
     *
     * Devises identiques : taux identité (1.00000000, source 'identity').
     * Devises différentes : taux résolu par FXService.
     *
     * @param TransferRequest $req Requête de transfert (DTO).
     *
     * @return TransferResult Résultat (operation_id, montants, taux, status).
     *
     * @throws RuntimeException Validation, devise incohérente, solde disponible
     *                          insuffisant, paire FX inconnue, clé d'idempotence
     *                          déjà en cours ou en erreur, erreur DB.
     */
    public static function transferMultiCurrency(TransferRequest $req): TransferResult
    {
        $userId         = $req->getUserId();
        $sourceWalletId = $req->getSourceWalletId();
        $destWalletId   = $req->getDestWalletId();
        $sourceAmount   = $req->getSourceAmount();
        $sourceCurrency = $req->getSourceCurrency();
        $destCurrency   = $req->getDestCurrency();
        $type           = $req->getType();
        $idempotencyKey = $req->getIdempotencyKey();
        $description    = $req->getDescription();
        $metadata       = $req->getMetadata();

        // ──────────────────────────────────────────────────────────────────
        // 1. Validation
        // ──────────────────────────────────────────────────────────────────
        self::assertUserId($userId);
        self::assertWalletId($sourceWalletId);
        self::assertWalletId($destWalletId);
        if ($sourceWalletId === $destWalletId) {
            throw new RuntimeException('Les wallets source et destination doivent être distincts.');
        }
        self::assertAmount($sourceAmount);
        self::assertCurrency($sourceCurrency);
        self::assertCurrency($destCurrency);

        $sourceWallet = self::loadWalletById($sourceWalletId);
        $destWallet   = self::loadWalletById($destWalletId);

        if (strtoupper((string) $sourceWallet['currency']) !== strtoupper($sourceCurrency)) {
            throw new RuntimeException(
                sprintf(
                    'Devise incohérente pour le wallet source : wallet=%s, demandé=%s.',
                    $sourceWallet['currency'],
                    $sourceCurrency
                )
            );
        }
        if (strtoupper((string) $destWallet['currency']) !== strtoupper($destCurrency)) {
            throw new RuntimeException(
                sprintf(
                    'Devise incohérente pour le wallet destination : wallet=%s, demandé=%s.',
                    $destWallet['currency'],
                    $destCurrency
                )
            );
        }

        // ──────────────────────────────────────────────────────────────────
        // 2. Idempotence — check
        // ──────────────────────────────────────────────────────────────────
        $useIdempotency = $idempotencyKey !== null && $idempotencyKey !== '';
        if ($useIdempotency) {
            $cached = IdempotencyService::check($idempotencyKey, $userId);
            if ($cached !== null) {
                if ($cached['status'] === 'completed') {
                    // Replay : réponse précédente, aucune nouvelle écriture.
                    return TransferResult::fromArray($cached['response_json']);
                }
                if ($cached['status'] === 'error') {
                    throw new RuntimeException(
                        (string) ($cached['response_json']['error'] ?? 'Opération précédente en échec.')
                    );
                }
                throw new RuntimeException('Opération déjà en cours');
            }
        }

        // ──────────────────────────────────────────────────────────────────
        // 3. Résolution du taux FX
        // ──────────────────────────────────────────────────────────────────
        if (strtoupper($sourceCurrency) === strtoupper($destCurrency)) {
            // Devises identiques : taux identité, aucune résolution nécessaire.
            $rate = new FXRate(
                $sourceCurrency,
                $destCurrency,
                '1.00000000',
                '0.0000',
                $req->getFxSource() ?? 'identity',
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+24 hours')
            );
            $destAmount = $sourceAmount;
        } else {
            $rate       = FXService::resolve($sourceCurrency, $destCurrency);
            $destAmount = FXService::convert($sourceAmount, $rate);
        }
        $fxRate   = $rate->getRate();
        $fxSource = $rate->getSource();

        // ──────────────────────────────────────────────────────────────────
        // 4. operation_id unique (généré AVANT start() pour être stocké
        //    dans idempotency_keys.operation_id ET wallet_operations.id)
        // ──────────────────────────────────────────────────────────────────
        $operationId = self::generateUuid();

        // ──────────────────────────────────────────────────────────────────
        // 5. Idempotence — start (réservation de la clé)
        // ──────────────────────────────────────────────────────────────────
        if ($useIdempotency) {
            $state = IdempotencyService::start($idempotencyKey, $userId, $operationId);
            if (!$state['created']) {
                // Collision (requête concurrente ou état préexistant).
                if ($state['status'] === 'completed') {
                    return TransferResult::fromArray($state['response_json']);
                }
                if ($state['status'] === 'error') {
                    throw new RuntimeException(
                        (string) ($state['response_json']['error'] ?? 'Opération précédente en échec.')
                    );
                }
                throw new RuntimeException('Opération déjà en cours');
            }
        }

        // ──────────────────────────────────────────────────────────────────
        // 6. Exécution atomique : LedgerService + complete() dans UNE transaction
        // ──────────────────────────────────────────────────────────────────
        $pdo             = Database::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $operationId = LedgerService::transfer(
                $userId,
                $sourceWalletId,
                $destWalletId,
                $sourceAmount,
                $sourceCurrency,
                $destAmount,
                $destCurrency,
                $type,
                $idempotencyKey,
                $description,
                $metadata,
                $fxRate,
                $fxSource,
                $operationId
            );

            $result = new TransferResult(
                $operationId,
                $sourceAmount,
                $destAmount,
                $fxRate,
                $fxSource,
                $description,
                $metadata,
                'completed'
            );

            // La réponse de la première exécution est mise en cache pour les
            // futurs replays (même transaction : tout est annulé ensemble si
            // complete() échoue).
            if ($useIdempotency) {
                IdempotencyService::complete($idempotencyKey, $userId, $result->toArray(), $operationId);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            // Rollback complet : wallet_operations, ledger_entries, soldes et
            // éventuelle mise à jour idempotence sont annulés.
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // L'échec est mémorisé sur la clé (status=error) pour les replays.
            if ($useIdempotency) {
                try {
                    IdempotencyService::fail($idempotencyKey, $userId, $e->getMessage(), $operationId);
                } catch (Throwable $ignore) {
                    // Ne masque jamais l'erreur d'origine.
                }
            }
            throw $e;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers internes

    /**
     * Formate une ligne brute de la table wallets en tableau standardisé.
     */
    private static function formatWallet(array $row): array
    {
        return [
            'id'                 => (int) $row['id'],
            'user_id'            => (int) $row['user_id'],
            'currency'           => (string) $row['currency'],
            'balance'            => (string) $row['balance'],
            'available_balance'  => (string) $row['available_balance'],
            'hold_balance'       => (string) $row['hold_balance'],
            'pending_balance'    => (string) $row['pending_balance'],
            'in_transit_balance' => (string) $row['in_transit_balance'],
            'settlement_balance' => (string) $row['settlement_balance'],
            'created_at'         => (string) $row['created_at'],
            'updated_at'         => (string) $row['updated_at'],
        ];
    }

    /**
     * Soustraction décimale précise (chaînes).
     */
    private static function subDecimal(string $a, string $b): string
    {
        return bcsub($a, $b, 2);
    }

    /**
     * Validation user_id > 0.
     */
    private static function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException(sprintf('user_id invalide : %d (doit être > 0).', $userId));
        }
    }

    /**
     * Validation wallet_id > 0.
     */
    private static function assertWalletId(int $walletId): void
    {
        if ($walletId <= 0) {
            throw new RuntimeException(sprintf('wallet_id invalide : %d (doit être > 0).', $walletId));
        }
    }

    /**
     * Validation code devise supporté (3-5 caractères majuscules).
     */
    private static function assertCurrency(string $currency): void
    {
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new RuntimeException(
                sprintf('Devise non supportée : %s. Supportées : %s.', $currency, implode(', ', self::SUPPORTED_CURRENCIES))
            );
        }
    }

    /**
     * Validation montant : strictement positif et format décimal valide.
     */
    private static function assertAmount(string $amount): void
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
     * Charge un wallet par ID (lecture simple, sans verrou).
     *
     * @return array<string,mixed> Ligne wallets (id, user_id, currency, balance,
     *                             available_balance, hold_balance).
     */
    private static function loadWalletById(int $walletId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, user_id, currency, balance, available_balance, hold_balance
             FROM wallets
             WHERE id = :id
             LIMIT 1'
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
     * Génère un UUID v4 (même algorithme que LedgerService, sans dépendance).
     */
    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}