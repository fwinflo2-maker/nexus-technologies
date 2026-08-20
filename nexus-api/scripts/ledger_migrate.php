<?php

declare(strict_types=1);

/**
 * MIGRATION HISTORIQUE DU LEDGER — Phase 2 (backfill) + Phase 3 (ouverture).
 *
 * Transforme l'existant vers le General Ledger SANS réécrire l'histoire :
 *
 *   Phase 2 — backfill :
 *     - account_code = 'USER_POSITION.{devise}' sur les écritures portant un
 *       wallet (dérivation déterministe, aucune conjecture) ;
 *     - is_legacy = 1 (écritures antérieures à la bascule, hors calculs GL
 *       courants, conservées pour l'audit) ;
 *     - migrated_at = NOW() (trace d'audit).
 *
 *   Phase 3 — postings d'ouverture :
 *     - pour chaque wallet dont le solde est non nul (et sans hold en vol) :
 *       DEBIT  SUSPENSE.{devise}  (contrepartie externe non encore identifiée)
 *       CREDIT USER_POSITION.{devise} (position utilisateur, balance_after)
 *     - JAMAIS de provider inventé : les fonds historiques sont provisionnés
 *       en SUSPENSE, jamais présentés comme détenus chez un partenaire.
 *
 * Idempotent (ne re-crée jamais une ouverture existante) et RÉVERSIBLE :
 *   --reverse  supprime les postings d'ouverture créés par ce script.
 *   --dry-run  affiche ce qui serait fait, ne modifie rien.
 *
 * Usage :
 *   php scripts/ledger_migrate.php [--dry-run|--reverse] [--env sandbox|production]
 * Env : LEDGER_DB_NAME / LEDGER_DB_USER / LEDGER_DB_PASS / LEDGER_DB_HOST
 *       (défauts : nexus / nexus / nexus_dev_pw / 127.0.0.1)
 */

$dbName = getenv('LEDGER_DB_NAME') ?: 'nexus';
$dbUser = getenv('LEDGER_DB_USER') ?: 'nexus';
$dbPass = getenv('LEDGER_DB_PASS') ?: 'nexus_dev_pw';
$dbHost = getenv('LEDGER_DB_HOST') ?: '127.0.0.1';

$args = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $args, true);
$reverse = in_array('--reverse', $args, true);
$env = 'sandbox';
foreach ($args as $i => $a) {
    if ($a === '--env' && isset($args[$i + 1])) {
        $env = strtolower($args[$i + 1]);
    }
}
if (!in_array($env, ['sandbox', 'production'], true)) {
    fwrite(STDERR, "Environnement invalide : $env\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$dbHost};port=3306;dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "Base : {$dbName} — " . ($dryRun ? 'DRY RUN (aucune modification)' : ($reverse ? 'REVERSE' : 'MIGRATION')) . " — env {$env}\n\n";

// ════════════════════════════════════════════════════════════════════════
// REVERSE — suppression des postings d'ouverture (jamais des lignes legacy)
// ════════════════════════════════════════════════════════════════════════
if ($reverse) {
    $rows = $pdo->query("SELECT id, source_wallet_id FROM wallet_operations WHERE type = 'opening_balance'")->fetchAll();
    if ($rows === []) {
        echo "Aucun posting d'ouverture à supprimer.\n";
        exit(0);
    }
    echo count($rows) . " posting(s) d'ouverture trouvé(s) :\n";
    foreach ($rows as $r) {
        echo "  - {$r['id']} (wallet {$r['source_wallet_id']})\n";
        if (!$dryRun) {
            // fk_ledger_operation_env (ON DELETE CASCADE) supprime les legs.
            $pdo->prepare('DELETE FROM wallet_operations WHERE id = :id')->execute(['id' => $r['id']]);
        }
    }
    if (!$dryRun) {
        echo "\nPostings d'ouverture supprimés. Les lignes legacy (is_legacy=1) sont intactes.\n";
    }
    exit(0);
}

// ════════════════════════════════════════════════════════════════════════
// PHASE 2 — Backfill : account_code + is_legacy + migrated_at
// ════════════════════════════════════════════════════════════════════════
$legacy = $pdo->query(
    "SELECT COUNT(*) FROM ledger_entries WHERE is_legacy = 0 AND account_code IS NULL"
)->fetchColumn();
echo "Phase 2 — backfill : {$legacy} écriture(s) existante(s) à marquer legacy.\n";

if (!$dryRun && $legacy > 0) {
    $pdo->prepare(
        "UPDATE ledger_entries
            SET account_code = CONCAT('USER_POSITION.', wallet_currency),
                is_legacy    = 1,
                migrated_at  = NOW()
          WHERE is_legacy = 0 AND account_code IS NULL AND wallet_id IS NOT NULL"
    )->execute();
    // Écritures sans wallet (normalement inexistantes avant la bascule) :
    // marquées legacy sans compte dérivable (à examiner, jamais inventé).
    $orphans = $pdo->prepare(
        "UPDATE ledger_entries
            SET is_legacy   = 1,
                migrated_at = NOW()
          WHERE is_legacy = 0 AND account_code IS NULL AND wallet_id IS NULL"
    );
    $orphans->execute();
    echo "  -> backfill terminé (wallet legs : USER_POSITION.{devise}).\n";
}

// ════════════════════════════════════════════════════════════════════════
// PHASE 3 — Postings d'ouverture par wallet
// ════════════════════════════════════════════════════════════════════════
$stmt = $pdo->query(
    "SELECT w.id, w.user_id, w.currency, w.balance, w.hold_balance
       FROM wallets w
      WHERE w.balance <> 0
      ORDER BY w.id"
);
$wallets = $stmt->fetchAll();

$done = 0; $skippedHold = 0; $skippedZero = 0; $skippedExists = 0; $total = '0';
foreach ($wallets as $w) {
    if (bccomp((string) $w['hold_balance'], '0', 8) !== 0) {
        echo "  SKIP wallet {$w['id']} : hold en vol ({$w['hold_balance']}) — à régler avant ouverture.\n";
        $skippedHold++;
        continue;
    }
    // Idempotence : ouverture déjà présente ?
    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM wallet_operations WHERE type = 'opening_balance' AND source_wallet_id = :wid"
    );
    $exists->execute(['wid' => $w['id']]);
    if ((int) $exists->fetchColumn() > 0) {
        $skippedExists++;
        continue;
    }

    $operationId = 'OPEN-' . $w['id'] . '-' . date('Ymd');
    if (strlen($operationId) > 36) {
        fwrite(STDERR, "operation_id trop long pour le wallet {$w['id']} : $operationId\n");
        exit(1);
    }
    $amount = bcadd((string) $w['balance'], '0', 8);
    $total  = bcadd($total, $amount, 8);
    $done++;

    echo sprintf(
        "  + OPEN wallet %d (%s) : DEBIT SUSPENSE.%s %s / CREDIT USER_POSITION.%s %s\n",
        $w['id'], $w['currency'], $w['currency'], $amount, $w['currency'], $amount
    );

    if ($dryRun) {
        continue;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO wallet_operations
                (id, user_id, type, status, environment, source_wallet_id, source_currency,
                 source_amount, fee_amount, idempotency_key, description, completed_at)
             VALUES (:id, :uid, 'opening_balance', 'completed', :env, :wid, :cur,
                     :amt, 0, :idem, 'Ouverture comptable — migration du modèle financier', NOW())"
        )->execute([
            'id'   => $operationId,
            'uid'  => (int) $w['user_id'],
            'env'  => $env,
            'wid'  => (int) $w['id'],
            'cur'  => $w['currency'],
            'amt'  => $amount,
            'idem' => 'opening:' . $w['id'],
        ]);

        $pdo->prepare(
            "INSERT INTO ledger_entries
                (operation_id, sequence, entry_type, account_code, wallet_id, wallet_currency,
                 amount, balance_after, description, reference_type, reference_id, metadata, environment)
             VALUES (:op, 1, 'debit', :accSuspense, NULL, :cur, :amt, NULL,
                     'Ouverture comptable — contrepartie externe à identifier', 'opening_balance', :wid,
                     :metaSuspense, :env)"
        )->execute([
            'op'          => $operationId,
            'accSuspense' => 'SUSPENSE.' . $w['currency'],
            'cur'         => $w['currency'],
            'amt'         => $amount,
            'wid'         => (string) $w['id'],
            'metaSuspense'=> json_encode(['kind' => 'migration_opening', 'wallet_id' => (int) $w['id']], JSON_UNESCAPED_UNICODE),
            'env'         => $env,
        ]);

        $pdo->prepare(
            "INSERT INTO ledger_entries
                (operation_id, sequence, entry_type, account_code, wallet_id, wallet_currency,
                 amount, balance_after, description, reference_type, reference_id, metadata, environment)
             VALUES (:op, 2, 'credit', :accUser, :wid, :cur, :amt, :bal,
                     'Ouverture comptable — position utilisateur', 'opening_balance', :wid,
                     :metaUser, :env)"
        )->execute([
            'op'       => $operationId,
            'accUser'  => 'USER_POSITION.' . $w['currency'],
            'wid'      => (int) $w['id'],
            'cur'      => $w['currency'],
            'amt'      => $amount,
            'bal'      => $amount,
            'metaUser' => json_encode(['kind' => 'migration_opening', 'wallet_id' => (int) $w['id']], JSON_UNESCAPED_UNICODE),
            'env'      => $env,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "ERREUR wallet {$w['id']} : " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\n=== RÉSUMÉ ===\n";
echo "Wallets traités : {$done} (total ouvert : {$total})\n";
echo "Skipped (solde nul) : " . $skippedZero . " | (hold en vol) : {$skippedHold} | (ouverture existante) : {$skippedExists}\n";
if (!$dryRun) {
    echo "Vérification : Σ wallets == Σ USER_POSITION ?\n";
    $walletSum = $pdo->query(
        "SELECT SUM(balance) FROM wallets WHERE balance <> 0"
    )->fetchColumn();
    // Le GL courant (post-bascule) exclut les écritures legacy : les lignes
    // historiques sont archivées (is_legacy=1), seules les écritures GL
    // (ouverture + nouvelles opérations) font foi pour la projection.
    $userSum = $pdo->query(
        "SELECT COALESCE(SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END)
                      - SUM(CASE WHEN entry_type = 'debit'  THEN amount ELSE 0 END), 0)
           FROM ledger_entries WHERE account_code LIKE 'USER_POSITION.%' AND is_legacy = 0"
    )->fetchColumn();
    $legacyNet = $pdo->query(
        "SELECT COALESCE(SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END)
                      - SUM(CASE WHEN entry_type = 'debit'  THEN amount ELSE 0 END), 0)
           FROM ledger_entries WHERE is_legacy = 1"
    )->fetchColumn();
    echo "  Σ wallets       = {$walletSum}\n";
    echo "  Σ USER_POSITION (GL courant) = {$userSum}\n";
    echo "  Queue legacy (is_legacy=1)   = {$legacyNet} (archive, hors GL courant)\n";
    echo (bccomp((string) $walletSum, (string) $userSum, 8) === 0 ? "  ✔ ÉQUILIBRE\n" : "  ✘ ÉCART — investigation requise\n");
}
