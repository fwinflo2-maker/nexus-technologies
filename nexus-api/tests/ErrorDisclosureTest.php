<?php

declare(strict_types=1);

namespace Nexus\Tests;

use PHPUnit\Framework\TestCase;

/**
 * BOUCLE 9 — FUITE D'INTERNES PAR LES MESSAGES D'ERREUR.
 *
 * LE DÉFAUT
 * ─────────
 * Plusieurs contrôleurs terminaient par :
 *
 *     } catch (\Throwable $e) {
 *         Response::error($e->getMessage(), 400);
 *     }
 *
 * Deux problèmes distincts, souvent confondus :
 *
 * 1. FUITE — `$e->getMessage()` d'une exception NON MAÎTRISÉE est un message
 *    interne. Sur une erreur PDO, il ressemble à :
 *
 *      SQLSTATE[42S22]: Column not found: 1054 Unknown column
 *      'colonne_inexistante' in 'SELECT'
 *
 *    On y lit le SGBD, la structure des tables, le nom des colonnes. C'est de
 *    la reconnaissance offerte gratuitement à un attaquant, sur les routes
 *    les plus sensibles (holds, soldes, capture, release).
 *
 * 2. MENSONGE DE STATUT — un `400` déclare « votre requête est invalide ».
 *    Une panne serveur, une table absente ou une base injoignable ne sont pas
 *    la faute du client. Le client réessaie une requête pourtant correcte, et
 *    la supervision ne voit aucune erreur 5xx alors que le service est cassé.
 *
 * CE QUI N'EST PAS LE DÉFAUT
 * ──────────────────────────
 * `HttpException` est bien rattrapé AVANT, avec son statut et son code. Les
 * refus métier (403 FORBIDDEN_PLATFORM_ROLE, 409 ENVIRONMENT_MISMATCH,
 * 404 WALLET_NOT_FOUND…) n'étaient donc pas masqués. Ce test verrouille ce
 * point, pour que la correction du `Throwable` ne les écrase jamais.
 *
 * Ces tests portent sur le SOURCE : ils décrivent une règle de codage que la
 * revue humaine oublie, et empêchent la réintroduction du motif.
 */
final class ErrorDisclosureTest extends TestCase
{
    /** Contrôleurs manipulant des données financières ou sensibles. */
    private const CONTROLLERS = [
        'WalletController',
        'PaymentController',
        'AccountController',
        'AuthController',
        'ProviderCredentialController',
        'TransferController',
        'QuoteController',
    ];

    private function source(string $controller): ?string
    {
        $path = __DIR__ . '/../src/Controllers/' . $controller . '.php';

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Aucun contrôleur ne doit renvoyer au client le message d'une exception
     * non maîtrisée.
     */
    public function test_no_controller_returns_a_raw_exception_message(): void
    {
        $offenders = [];

        foreach (self::CONTROLLERS as $controller) {
            $src = $this->source($controller);
            if ($src === null) {
                continue;
            }

            $lines = explode("\n", $src);
            $inGenericCatch = false;

            foreach ($lines as $i => $line) {
                if (preg_match('/catch\s*\(\s*\\\\?Throwable/', $line)) {
                    $inGenericCatch = true;
                    continue;
                }

                if ($inGenericCatch) {
                    // Le message brut ne doit jamais atteindre la réponse.
                    if (preg_match('/Response::(error|success|serverError)\s*\([^;]*\$e->getMessage\(\)/', $line)) {
                        $offenders[] = sprintf('%s:%d', $controller, $i + 1);
                    }
                    if (str_contains($line, '}')) {
                        $inGenericCatch = false;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Message d'exception interne renvoyé au client (fuite de structure) :\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * Une exception non maîtrisée est une panne serveur : 5xx, jamais 4xx.
     */
    public function test_unhandled_exceptions_are_not_reported_as_client_errors(): void
    {
        $offenders = [];

        foreach (self::CONTROLLERS as $controller) {
            $src = $this->source($controller);
            if ($src === null) {
                continue;
            }

            $lines = explode("\n", $src);
            $inGenericCatch = false;

            foreach ($lines as $i => $line) {
                if (preg_match('/catch\s*\(\s*\\\\?Throwable/', $line)) {
                    $inGenericCatch = true;
                    continue;
                }

                if ($inGenericCatch) {
                    if (preg_match('/Response::error\s*\(.*,\s*(4\d\d)\s*[,)]/', $line, $m)) {
                        $offenders[] = sprintf('%s:%d (HTTP %s)', $controller, $i + 1, $m[1]);
                    }
                    if (str_contains($line, '}')) {
                        $inGenericCatch = false;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Panne serveur déclarée comme erreur client :\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * VERROU DE NON-RÉGRESSION.
     *
     * `HttpException` doit rester rattrapé AVANT `Throwable`. Inverser les
     * deux blocs transformerait silencieusement chaque refus métier (403, 409,
     * 404, 410) en erreur générique : la sémantique de toute l'API
     * s'effondrerait sans qu'aucun test fonctionnel ne bronche.
     */
    public function test_http_exceptions_are_always_caught_before_generic_ones(): void
    {
        foreach (self::CONTROLLERS as $controller) {
            $src = $this->source($controller);
            if ($src === null) {
                continue;
            }

            $posHttp    = strpos($src, 'catch (HttpException');
            $posGeneric = strpos($src, 'catch (\Throwable');
            if ($posGeneric === false) {
                $posGeneric = strpos($src, 'catch (Throwable');
            }

            if ($posHttp === false || $posGeneric === false) {
                continue;
            }

            $this->assertLessThan(
                $posGeneric,
                $posHttp,
                sprintf(
                    '%s : HttpException doit être rattrapé avant Throwable, sinon les refus métier '
                    . '(403/404/409/410) deviennent des erreurs génériques.',
                    $controller
                )
            );
        }
    }

    /**
     * Les messages destinés au client ne doivent pas comporter de vocabulaire
     * de moteur de base de données.
     */
    public function test_no_database_vocabulary_reaches_the_client(): void
    {
        $needles   = ['SQLSTATE', 'PDOException', 'mysql:host', 'Unknown column', 'Base table'];
        $offenders = [];

        foreach (self::CONTROLLERS as $controller) {
            $src = $this->source($controller);
            if ($src === null) {
                continue;
            }

            foreach (explode("\n", $src) as $i => $line) {
                if (!str_contains($line, 'Response::error') && !str_contains($line, 'Response::serverError')) {
                    continue;
                }
                foreach ($needles as $needle) {
                    if (str_contains($line, $needle)) {
                        $offenders[] = sprintf('%s:%d (%s)', $controller, $i + 1, $needle);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Vocabulaire SGBD dans une réponse :\n  " . implode("\n  ", $offenders));
    }
}
