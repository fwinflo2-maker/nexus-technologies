<?php

declare(strict_types=1);

/**
 * Seed de démonstration du dashboard NEXUS.
 *
 * Génère, pour chaque utilisateur sans transaction :
 *  - les wallets USD / GBP / USDT / USDC manquants (avec soldes de démo) ;
 *  - des soldes par état (pending / in_transit / settlement) sur EUR et XAF ;
 *  - un historique de transactions réaliste sur les ~150 derniers jours
 *    (alimente les KPIs, le graphique d'activité et l'activité récente).
 *
 * Idempotent : un utilisateur possédant déjà des transactions est ignoré.
 *
 * Usage : php database/seed_dashboard.php
 */

require __DIR__ . '/../config/env.php';
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/database.php';

// --- Taux de conversion (identiques à src/Core/Currency.php) ------------------
$rateToRef = [
    'EUR'  => 1.0,
    'USD'  => 0.92,
    'GBP'  => 1.17,
    'XAF'  => 1.0 / 655.957,
    'USDT' => 0.92,
    'USDC' => 0.92,
];
$rateToXaf = [
    'EUR'  => 655.957,
    'USD'  => 603.0,
    'GBP'  => 767.0,
    'XAF'  => 1.0,
    'USDT' => 603.0,
    'USDC' => 603.0,
];

// --- Wallets supplémentaires (démo) -------------------------------------------
$extraWallets = [
    ['currency' => 'USD',  'balance' => '1200.00'],
    ['currency' => 'GBP',  'balance' => '500.00'],
    ['currency' => 'USDT', 'balance' => '1200.00'],
    ['currency' => 'USDC', 'balance' => '500.00'],
];

// --- Pools de génération -------------------------------------------------------
$labelsSend = [
    'Envoi Mobile Money', 'Paiement fournisseur', 'Virement international',
    'Envoi vers banque', 'Paiement facture', 'Envoi frais de scolarité',
];
$labelsReceive = [
    'Réception SEPA', 'Reçu — Mobile Money', 'Virement entrant',
    'Remboursement', 'Paiement reçu', 'Revenus freelance',
];
$labelsFx = [
    'Conversion FX EUR → USD', 'Conversion FX EUR → XAF', 'Conversion FX EUR → GBP',
    'Conversion FX EUR → USDT', 'Conversion EUR → USD',
];
$providers = ['pawaPay', 'Swan', 'Thunes', 'Currencycloud', 'Swift', 'Orange Money'];

$statuses = ['completed', 'completed', 'completed', 'completed', 'completed',
             'completed', 'completed', 'completed', 'processing', 'processing',
             'pending', 'failed', 'cancelled'];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    $users = $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll();
    $seededUsers = 0;

    foreach ($users as $user) {
        $userId = (int) $user['id'];

        // Déjà seedé → on ignore (script idempotent).
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :uid');
        $countStmt->execute(['uid' => $userId]);
        if ((int) $countStmt->fetchColumn() > 0) {
            continue;
        }

        // --- Wallets manquants ------------------------------------------------
        $walletInsert = $pdo->prepare(
            'INSERT IGNORE INTO wallets (user_id, currency, balance, available_balance)
             VALUES (:uid, :currency, :balance, :available)'
        );
        foreach ($extraWallets as $wallet) {
            $walletInsert->execute([
                'uid'       => $userId,
                'currency'  => $wallet['currency'],
                'balance'   => $wallet['balance'],
                'available' => $wallet['balance'],
            ]);
        }

        // --- Soldes par état sur les wallets existants ------------------------
        $pdo->prepare(
            'UPDATE wallets
             SET pending_balance = 60.00, in_transit_balance = 20.00, settlement_balance = 20.00,
                 available_balance = balance - 100.00
             WHERE user_id = :uid AND currency = :cur'
        )->execute(['uid' => $userId, 'cur' => 'EUR']);
        $pdo->prepare(
            'UPDATE wallets
             SET pending_balance = 25000.00, in_transit_balance = 10000.00, settlement_balance = 15000.00,
                 available_balance = balance - 50000.00
             WHERE user_id = :uid AND currency = :cur'
        )->execute(['uid' => $userId, 'cur' => 'XAF']);

        // --- Transactions -----------------------------------------------------
        $insertTx = $pdo->prepare(
            'INSERT INTO transactions
                (user_id, type, direction, label, description, amount, currency,
                 amount_ref, ref_currency, amount_xaf, fee, fee_currency,
                 status, provider, destination, execution_time_seconds, created_at)
             VALUES
                (:uid, :type, :direction, :label, :description, :amount, :currency,
                 :amount_ref, :ref_currency, :amount_xaf, :fee, :fee_currency,
                 :status, :provider, :destination, :exec_time, :created_at)'
        );

        $now = time();
        $txCount = 0;

        // Lot 1 : 8 transactions très récentes (dernières 72 h) — alimente
        // l'activité récente avec des statuts variés.
        for ($i = 0; $i < 8; $i++) {
            seedOne($insertTx, $userId, $now - mt_rand(3600, 3 * 86400), true);
            $txCount++;
        }

        // Lot 2 : ~30 transactions sur le mois courant + les 29 jours précédents.
        for ($i = 0; $i < 30; $i++) {
            seedOne($insertTx, $userId, $now - mt_rand(0, 30 * 86400));
            $txCount++;
        }

        // Lot 3 : ~90 transactions entre 31 et 150 jours (graphique 12 mois).
        for ($i = 0; $i < 90; $i++) {
            seedOne($insertTx, $userId, $now - mt_rand(31 * 86400, 150 * 86400));
            $txCount++;
        }

        echo "Utilisateur #{$userId} : {$txCount} transactions seedées.\n";
        $seededUsers++;
    }

    echo "Terminé. {$seededUsers} utilisateur(s) seedé(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[SEED] Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Insère une transaction de démonstration.
 *
 * @param \PDOStatement $stmt Requête préparée d'insertion.
 */
function seedOne(\PDOStatement $stmt, int $userId, int $timestamp, bool $recent = false): void
{
    global $labelsSend, $labelsReceive, $labelsFx, $providers, $statuses, $rateToRef, $rateToXaf;

    $now = time();
    $isRecent = $recent || $timestamp > $now - 3 * 86400;

    // Le dernier lot récent favorise les statuts « en cours ».
    if ($isRecent && mt_rand(0, 3) === 0) {
        $status = mt_rand(0, 1) === 0 ? 'processing' : 'pending';
    } else {
        $status = $statuses[array_rand($statuses)];
    }

    $roll = mt_rand(1, 100);
    if ($roll <= 55) {
        $type      = 'send';
        $direction = 'out';
        $label     = $labelsSend[array_rand($labelsSend)];
        $currency  = ['EUR', 'EUR', 'EUR', 'XAF', 'USD'][array_rand(['EUR', 'EUR', 'EUR', 'XAF', 'USD'])];
        $amount    = mt_rand(50, 800);
        $dest      = mt_rand(0, 1) === 0
            ? 'Congo — +242 06 ' . mt_rand(100000, 999999)
            : 'Bénéficiaire ' . mt_rand(1000, 9999);
        $desc      = 'Envoi vers destination';
    } elseif ($roll <= 85) {
        $type      = 'receive';
        $direction = 'in';
        $label     = $labelsReceive[array_rand($labelsReceive)];
        $currency  = ['EUR', 'EUR', 'XAF', 'GBP'][array_rand(['EUR', 'EUR', 'XAF', 'GBP'])];
        $amount    = mt_rand(100, 1500);
        $dest      = 'Virement entrant';
        $desc      = 'Crédit sur le wallet';
    } else {
        $type      = 'fx';
        $direction = 'fx';
        $label     = $labelsFx[array_rand($labelsFx)];
        $currency  = 'EUR';
        $amount    = mt_rand(200, 2000);
        $dest      = 'Taux de change NEXUS';
        $desc      = 'Conversion multi-devises';
    }

    $amountRef = round($amount * $rateToRef[$currency], 2);
    $amountXaf = round($amount * $rateToXaf[$currency], 2);

    // Frais : 0.2–2.5 % selon le rail.
    $feePct    = in_array($currency, ['USDT', 'USDC'], true) ? 0.001 : mt_rand(2, 25) / 1000;
    $fee       = round($amount * $feePct, 2);
    $feeCur    = in_array($currency, ['USDT', 'USDC'], true) ? 'USDT' : $currency;

    $execTime  = $status === 'completed' ? mt_rand(30, 900) : null;

    $stmt->execute([
        'uid'           => $userId,
        'type'          => $type,
        'direction'     => $direction,
        'label'         => $label,
        'description'   => $desc,
        'amount'        => (string) $amount,
        'currency'      => $currency,
        'amount_ref'    => (string) $amountRef,
        'ref_currency'  => 'EUR',
        'amount_xaf'    => (string) $amountXaf,
        'fee'           => (string) $fee,
        'fee_currency'  => $feeCur,
        'status'        => $status,
        'provider'      => $providers[array_rand($providers)],
        'destination'   => $dest,
        'exec_time'     => $execTime,
        'created_at'    => gmdate('Y-m-d H:i:s', $timestamp),
    ]);
}
