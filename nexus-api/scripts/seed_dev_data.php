<?php

declare(strict_types=1);

/**
 * Seed de données de développement pour le dashboard Super Admin (cockpit).
 *
 * Génère des séries temporelles réalistes (14 jours) de transactions, audit,
 * opérations, KYC et employés — stockées réellement en base et servies par
 * les endpoints réels (aucune donnée hardcodée côté frontend).
 *
 * Usage : php scripts/seed_dev_data.php
 */

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=nexus;charset=utf8mb4',
    'nexus',
    'nexus_dev_pw',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pw  = password_hash('password123', PASSWORD_DEFAULT);
$now = date('Y-m-d H:i:s');
mt_srand(20260816); // reproductible

// ---------------------------------------------------------------------------
// Réinitialisation idempotente
// ---------------------------------------------------------------------------
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo->exec("DELETE FROM wallet_operations; DELETE FROM transactions; DELETE FROM wallets;
            DELETE FROM kyc_verifications; DELETE FROM audit_logs; DELETE FROM employees;
            DELETE FROM connect_accounts; DELETE FROM provider_credentials; DELETE FROM users;");
$pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

// ---------------------------------------------------------------------------
// Utilisateurs
//   id1 = SUPER ADMIN dédié (compte personal, PAS business)
//   id5 = compte Business pur (business@example.com)
// ---------------------------------------------------------------------------
$users = [
    // id1 — Super Admin dédié (compte personal, rôle superadmin)
    [1, 'Amina Diallo', 'admin@nexus-tech.io', '+33612345678', 'personal', 'superadmin', 'ACTIVE', 'advanced',
        'FR', '1986-04-12', 'female', 'Paris', '75008', '12 Avenue Montaigne',
        null, null, null, null, null, null],
    [2, 'Marc Lefèvre', 'auth2@example.com', '+33623456789', 'personal', 'user', 'ACTIVE', 'standard',
        'FR', '1990-11-03', 'male', 'Lyon', '69002', '8 Rue de la République', null, null, null, null, null, null],
    [3, 'Sophie Martin', 'test@example.com', '+33634567890', 'personal', 'compliance_officer', 'ACTIVE', 'standard',
        'FR', '1988-07-21', 'female', 'Marseille', '13001', '5 La Canebière', null, null, null, null, null, null],
    [4, 'Jean Dupont', 'jean.dupont@example.com', '+33745678901', 'personal', 'user', 'ACTIVE', 'basic',
        'CM', '1992-02-15', 'male', 'Douala', '00237', '45 Rue de la Pépinière', null, null, null, null, null, null],
    // id5 — compte Business pur (mode business)
    [5, 'Nexus Technologies SAS', 'business@example.com', '+33656789012', 'business', 'user', 'ACTIVE', 'advanced',
        'FR', null, null, 'Paris', '75008', '12 Avenue Montaigne',
        'Nexus Technologies SAS', 'SAS', 'RCS PARIS 921 884 512', 'Fintech', '51-200', 'https://nexus-tech.io'],
    [6, 'Acme Import-Export', 'contact@acme.example.com', '+33667890123', 'business', 'user', 'PENDING', 'basic',
        'FR', null, null, 'Bordeaux', '33000', '3 Quai de Bacalan',
        'ACME SARL', 'SARL', 'RCS BORDEAUX 884 512 903', 'Import / Export', '1-10', 'https://acme.example.com'],
    // Employés internes supplémentaires (comptes users liés à la table employees)
    [7, 'Karim Bensaid', 'ops@nexus-tech.io', '+33678901234', 'business', 'operations_manager', 'ACTIVE', 'none',
        'FR', '1984-09-30', 'male', 'Paris', '75009', '15 Rue Lafayette', null, null, null, null, null, null],
    [8, 'Léa Moreau', 'risk@nexus-tech.io', '+33689012345', 'business', 'risk_analyst', 'ACTIVE', 'none',
        'FR', '1991-01-17', 'female', 'Paris', '75002', '2 Rue de la Paix', null, null, null, null, null, null],
];

$ustmt = $pdo->prepare(
    "INSERT INTO users
      (id, full_name, email, phone, password_hash, account_type, platform_role, auth_provider, status,
       kyc_level, country_of_residence, birth_date, gender, city, postal_code, address,
       company_name, legal_form, company_registration_number, industry, company_size, website,
       kyc_verified_at, created_at, updated_at)
     VALUES
      (:id, :full_name, :email, :phone, :password_hash, :account_type, :platform_role, 'local', :status,
       :kyc_level, :country_of_residence, :birth_date, :gender, :city, :postal_code, :address,
       :company_name, :legal_form, :company_registration_number, :industry, :company_size, :website,
       :kyc_verified_at, :created_at, :updated_at)"
);
foreach ($users as $u) {
    [$id, $full, $email, $phone, $type, $role, $status, $kyc, $country, $bdate, $gender, $city, $postal, $addr,
        $comp, $form, $rccm, $ind, $size, $site] = $u;
    $ustmt->execute([
        'id' => $id, 'full_name' => $full, 'email' => $email, 'phone' => $phone,
        'password_hash' => $pw, 'account_type' => $type, 'platform_role' => $role, 'status' => $status,
        'kyc_level' => $kyc, 'country_of_residence' => $country, 'birth_date' => $bdate, 'gender' => $gender,
        'city' => $city, 'postal_code' => $postal, 'address' => $addr,
        'company_name' => $comp, 'legal_form' => $form, 'company_registration_number' => $rccm,
        'industry' => $ind, 'company_size' => $size, 'website' => $site,
        'kyc_verified_at' => (in_array($kyc, ['none', 'basic'], true) ? null : $now),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    echo "user id{$id}: {$full}\n";
}

// ---------------------------------------------------------------------------
// Wallets
// ---------------------------------------------------------------------------
$wallets = [
    [1, 'EUR', 2457890.00], [1, 'USD', 1120300.50], [1, 'XAF', 0.00],   // Super Admin
    [2, 'EUR', 12450.75], [2, 'XAF', 1540000.00],
    [3, 'EUR', 3220.00], [3, 'USD', 8900.00],
    [4, 'EUR', 540.00], [4, 'XAF', 3200000.00],
    [5, 'EUR', 58400.00], [5, 'XAF', 2000000.00],                        // Business pur
];
$wstmt = $pdo->prepare(
    "INSERT INTO wallets (user_id, currency, balance, available_balance, pending_balance,
                          in_transit_balance, settlement_balance, hold_balance, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)"
);
foreach ($wallets as $w) {
    $wstmt->execute([$w[0], $w[1], $w[2], $w[2], 0, 0, 0, 0, $now, $now]);
}
echo "wallets: " . count($wallets) . "\n";

// ---------------------------------------------------------------------------
// Transactions — série temporelle 14 jours (~130)
// ---------------------------------------------------------------------------
$statusPool = array_merge(array_fill(0, 78, 'completed'), array_fill(0, 10, 'failed'),
    array_fill(0, 7, 'processing'), array_fill(0, 5, 'pending'));
$typePool   = array_merge(array_fill(0, 55, 'send'), array_fill(0, 25, 'receive'),
    array_fill(0, 12, 'fx'), array_fill(0, 8, 'convert'));
$providerPool = ['wise', 'stripe', 'pawapay', 'nium', 'swift'];
$currencies = ['EUR' => 650.0, 'USD' => 600.0, 'XAF' => 1.0];
$labels = ['Paiement fournisseur', 'Virement SEPA', 'Envoi mobile money', 'Achat devises',
    'Remboursement', 'Salaires', 'Facture #2026', 'Dépôt reçu', 'Conversion EUR→XAF',
    'Virement famille', 'Settlement', 'Frais de licence'];

$tstmt = $pdo->prepare(
    "INSERT INTO transactions (user_id, type, direction, label, description, amount, currency,
                               amount_ref, ref_currency, amount_xaf, dest_amount, dest_currency, fx_rate,
                               fee, fee_currency, status, provider, environment, destination,
                               execution_time_seconds, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
$txCount = 0;
for ($day = 14; $day >= 0; $day--) {
    $n = mt_rand(4, 11); // transactions ce jour-là
    for ($i = 0; $i < $n; $i++) {
        $uid    = mt_rand(2, 5);
        $type   = $typePool[mt_rand(0, count($typePool) - 1)];
        $cur    = $currencies[array_rand($currencies)];
        $rate   = $cur === 1.0 ? 1.0 : $cur;
        $amount = round(mt_rand(500, 250000) / 100 * (mt_rand(50, 2000) / 100), 2);
        $amount = $cur === 'XAF' ? round(mt_rand(10000, 5000000), 0) : round(mt_rand(10, 9000), 2);
        // amount_ref = équivalent EUR (jamais recopier un montant XAF tel quel).
        $amountRef = match ($cur) {
            'EUR' => $amount,
            'USD' => round($amount / 1.08, 2),
            'XAF' => round($amount / 655.957, 2),
            default => round($amount, 2),
        };
        $status = $statusPool[mt_rand(0, count($statusPool) - 1)];
        $prov   = $providerPool[mt_rand(0, 4)];
        $dir    = $type === 'receive' ? 'in' : ($type === 'fx' ? 'fx' : 'out');
        $dt     = date('Y-m-d H:i:s', strtotime("-$day days") + mt_rand(0, 86000));
        $tstmt->execute([
            $uid, $type, $dir, $labels[mt_rand(0, count($labels) - 1)],
            'TX-' . strtoupper(substr(md5((string)mt_rand()), 0, 10)), $amount, $cur,
            $amountRef, 'EUR', round($amount * ($cur === 'XAF' ? 1.0 : ($cur === 'EUR' ? 655.957 : 600.0)), 2), $amount, $cur, $rate,
            0.5, $cur, $status, $prov, 'production', 'N/A',
            $status === 'completed' ? mt_rand(1, 8) : null, $dt, $dt,
        ]);
        $txCount++;
    }
}
echo "transactions: {$txCount}\n";

// ---------------------------------------------------------------------------
// Audit log — 14 jours (~120)
// ---------------------------------------------------------------------------
$actions = ['auth.login', 'auth.register', 'auth.logout', 'kyc.approve', 'kyc.reject', 'kyc.pending',
    'settings.update', 'employee.invite', 'employee.role_change', 'provider.test', 'provider.configure',
    'risk.alert', 'support.ticket', 'security.login_alert', 'treasury.settlement'];
$entities = ['users', 'auth', 'kyc', 'settings', 'employees', 'providers', 'transactions', 'risk', 'connect'];
$astmt = $pdo->prepare(
    "INSERT INTO audit_logs (user_id, action, entity_type, environment, metadata, ip_address, created_at)
     VALUES (?,?,?,?,?,?,?)"
);
$auditCount = 0;
for ($day = 14; $day >= 0; $day--) {
    $n = mt_rand(4, 10);
    for ($i = 0; $i < $n; $i++) {
        $action = $actions[mt_rand(0, count($actions) - 1)];
        $entity = $entities[mt_rand(0, count($entities) - 1)];
        $uid    = mt_rand(1, 7);
        $dt     = date('Y-m-d H:i:s', strtotime("-$day days") + mt_rand(0, 86000));
        $astmt->execute([$uid, $action, $entity, 'production',
            json_encode(['ip' => '10.0.' . mt_rand(1, 5) . '.' . mt_rand(2, 250)]),
            '10.0.' . mt_rand(1, 5) . '.' . mt_rand(2, 250), $dt]);
        $auditCount++;
    }
}
echo "audit_logs: {$auditCount}\n";

// ---------------------------------------------------------------------------
// KYC verifications
// ---------------------------------------------------------------------------
$kycs = [
    [2, 'sumsub', 'production', 'individual', 'appl_1001', 'standard', 'verified', null],
    [3, 'sumsub', 'production', 'individual', 'appl_1002', 'standard', 'verified', null],
    [4, 'sumsub', 'sandbox', 'individual', 'appl_1003', 'basic', 'pending', 'Selfie en attente'],
    [6, 'sumsub', 'sandbox', 'company', 'appl_1004', 'basic', 'pending', 'Documents entreprise en attente'],
    [1, 'sumsub', 'production', 'individual', 'appl_1005', 'advanced', 'verified', null],
    [7, 'sumsub', 'production', 'individual', 'appl_1006', 'basic', 'resubmission_requested', 'Document illisible'],
    [8, 'sumsub', 'production', 'individual', 'appl_1007', 'standard', 'verified', null],
];
$kstmt = $pdo->prepare(
    "INSERT INTO kyc_verifications (user_id, provider, environment, subject_type, applicant_id,
                                    level_name, status, reason, reviewed_at, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
);
foreach ($kycs as $k) {
    $kstmt->execute([$k[0], $k[1], $k[2], $k[3], $k[4], $k[5], $k[6], $k[7],
        (in_array($k[6], ['verified', 'rejected', 'resubmission_requested'], true) ? $now : null), $now, $now]);
}
echo "kyc_verifications: " . count($kycs) . "\n";

// ---------------------------------------------------------------------------
// Employés internes
// ---------------------------------------------------------------------------
$emps = [
    [3, 'Compliance', 'compliance_officer', '["compliance"]', 'active'],
    [7, 'Operations', 'operations_manager', '["operations"]', 'active'],
    [8, 'Risk', 'risk_analyst', '["risk"]', 'active'],
];
$estmt = $pdo->prepare(
    "INSERT INTO employees (user_id, department, role, permissions, status, last_login_at, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?)"
);
foreach ($emps as $e) {
    $estmt->execute([$e[0], $e[1], $e[2], $e[3], $e[4], $now, $now, $now]);
}
echo "employees: " . count($emps) . "\n";

// ---------------------------------------------------------------------------
// Provider credentials (insérées par seed_provider_credentials.php)
// ---------------------------------------------------------------------------
$pdo->exec("DELETE FROM provider_credentials");

// ---------------------------------------------------------------------------
// Connect accounts
// ---------------------------------------------------------------------------
$connects = [
    ['FinPulse API', 'dev@finpulse.io', 'active', 'sandbox', 'FR', 'nxpk_live_0001'],
    ['SwiftBridge', 'ops@swiftbridge.com', 'pending', 'production', 'GB', 'nxpk_live_0002'],
    ['KasiPay', 'ops@kasipay.cm', 'active', 'production', 'CM', 'nxpk_live_0003'],
];
$cstmt = $pdo->prepare(
    "INSERT INTO connect_accounts (company_name, email, status, environment, country, api_key_prefix,
                                   api_key_hash, webhook_url, webhook_secret_enc, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
);
foreach ($connects as $c) {
    $cstmt->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $c[5], hash('sha256', $c[5]),
        'https://webhook.example.com/cb', 'ENC[whsec]', $now, $now]);
}
echo "connect_accounts: " . count($connects) . "\n";

echo "\nSeed terminé.\n";
