<?php

declare(strict_types=1);

/**
 * Expiration worker des holds (Phase G+).
 *
 * Usage CLI :
 *   php scripts/expire_holds.php
 *
 * Recherche les opérations `wallet_operations` :
 *   - type   = 'hold'
 *   - status = 'pending'
 *   - expires_at <= NOW()
 *
 * Puis libère CHAQUE hold en réutilisant `WalletService::releaseHold()`
 * (source unique de vérité métier) avec une clé d'idempotence déterministe
 * `expire-hold-{operation_id}`. Deux workers concurrents qui sélectionnent le
 * même hold produisent donc UN SEUL effet comptable.
 *
 * Sortie (stats exploitables) :
 *   Expired: X
 *   Skipped: Y
 *   Errors: Z
 *
 * Convention de sortie :
 *   - code 0 : succès (même avec des holds skipped, c'est un comportement attendu)
 *   - code 1 : une ou plusieurs erreurs inattendues ont été rencontrées
 *
 * Une erreur sur un hold ne bloque pas le traitement des autres holds.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');   // CLI : on veut voir les erreurs.
date_default_timezone_set('UTC');

define('BASE_PATH', dirname(__DIR__));

// --- Bootstrap identique au front controller --------------------------------
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/config/env.php';
require BASE_PATH . '/config/app.php';
require BASE_PATH . '/config/constants.php';
require BASE_PATH . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nexus\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Nexus\Core\Database;
use Nexus\Services\WalletService;

$stats = ['expired' => 0, 'skipped' => 0, 'errors' => 0];
$errors = [];

try {
    $pdo = Database::getConnection();

    // Sélection des holds arrivés à expiration.
    // Le verrouillage réel est assuré par WalletService::releaseHold()
    // (SELECT ... FOR UPDATE + IdempotencyService + checks de statut).
    $stmt = $pdo->query(
        "SELECT id, user_id
         FROM wallet_operations
         WHERE type = 'hold'
           AND status = 'pending'
           AND expires_at IS NOT NULL
           AND expires_at <= NOW()"
    );

    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($candidates as $candidate) {
        $operationId = (string) $candidate['id'];
        $userId      = (int) $candidate['user_id'];
        $idemKey     = 'expire-hold-' . $operationId;

        try {
            // Vérifications préalables explicites (tolérance concurrence) :
            // le hold doit exister, appartenir à l'utilisateur, être pending
            // et effectivement expiré. Toute dérive est traitée comme skipped
            // sans effet comptable.
            $check = $pdo->prepare(
                "SELECT type, status, expires_at
                 FROM wallet_operations
                 WHERE id = ? AND user_id = ? AND type = 'hold' AND status = 'pending'
                 FOR UPDATE"
            );
            $check->execute([$operationId, $userId]);
            $row = $check->fetch();

            if ($row === false) {
                $stats['skipped']++;
                continue;
            }

            // Le hold a pu être libéré par un autre worker entre la sélection
            // initiale et le lock : on n'applique aucun effet comptable.
            if ($row['status'] !== 'pending') {
                $stats['skipped']++;
                continue;
            }

            $expiresAt = $row['expires_at'];
            if ($expiresAt === null || strtotime((string) $expiresAt) > time()) {
                $stats['skipped']++;
                continue;
            }

            // Libération réutilisant le service métier (idempotent par clé
            // déterministe `expire-hold-{operation_id}`).
            $result = WalletService::releaseHold($operationId, $userId, $idemKey);

            if (($result['status'] ?? '') === 'cancelled') {
                $stats['expired']++;
            } else {
                $stats['skipped']++;
            }
        } catch (RuntimeException $e) {
            // Une RuntimeException signifie généralement qu'un autre worker a
            // déjà libéré ce hold (replay idempotent "Opération déjà en cours"
            // ou statut déjà modifié) : aucun effet comptable, on compte skipped.
            $isConcurrency = str_contains($e->getMessage(), 'déjà')
                || str_contains($e->getMessage(), 'cancelled')
                || str_contains($e->getMessage(), 'n\'est plus');

            if ($isConcurrency) {
                $stats['skipped']++;
                continue;
            }

            // Erreur métier inattendue : on la compte et on continue.
            $stats['errors']++;
            $errors[] = sprintf('hold=%s: %s', $operationId, $e->getMessage());
        } catch (Throwable $e) {
            $stats['errors']++;
            $errors[] = sprintf('hold=%s: %s', $operationId, $e->getMessage());
        }
    }
} catch (Throwable $e) {
    // Erreur globale : jamais masquée silencieusement.
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}

// --- Rapport -----------------------------------------------------------------
echo 'Expired: ' . $stats['expired'] . PHP_EOL;
echo 'Skipped: ' . $stats['skipped'] . PHP_EOL;
echo 'Errors: ' . $stats['errors'] . PHP_EOL;

foreach ($errors as $error) {
    fwrite(STDERR, 'ERROR: ' . $error . PHP_EOL);
}

exit($stats['errors'] > 0 ? 1 : 0);
