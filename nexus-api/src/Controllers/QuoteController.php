<?php

declare(strict_types=1);

namespace Nexus\Controllers;

use Nexus\Auth\AuthMiddleware;
use Nexus\Core\Currency;
use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Execution\ExecutionContext;
use Nexus\Providers\ProviderConfig;
use Nexus\Services\CapabilityEngine;
use Nexus\Services\FundingSourceEngine;
use Nexus\Services\IntentParser;
use Nexus\Services\PolicyEngine;
use Nexus\Services\QuoteEngine;
use Nexus\Services\RoutingEngine;

/**
 * Quote Controller — endpoints pour le moteur de devis & routing.
 *
 *  - POST /api/quotes       → soumet une intention et retourne les 3 routes
 *  - GET  /api/quotes/:id   → récupère une quote et le temps restant
 *
 * Pipeline complet :
 *   Validation origine → Intent Parser → Capability Engine → Quote Engine
 *   → Routing Engine → Policy Engine
 *
 * La validation d'origine est effectuée AVANT le pipeline :
 *   - Vérification KYC + sources de financement vérifiées
 *   - Refus (403) si l'origine n'est pas autorisée
 *   - Le Routing Engine ne reçoit que des origines déjà autorisées
 *
 * La quote est persistée en base avec un TTL de 5 minutes.
 * L'expiration est vérifiée côté serveur à chaque consultation
 * et lors de la confirmation (POST /api/execute — étape 3.3).
 */
final class QuoteController
{
    /** Durée de vie d'une quote en secondes (5 minutes). */
    private const QUOTE_TTL_SECONDS = 300;

    /** Longueur du préfixe ID (UUID court). */
    private const ID_PREFIX = 'NX';

    /**
     * POST /api/quotes
     *
     * Reçoit l'intention normalisée du formulaire /send, exécute le
     * pipeline complet et retourne les 3 routes proposées.
     *
     * Validation d'origine AVANT le pipeline :
     *   - L'origine doit correspondre à une source de financement vérifiée
     *   - Sinon → HTTP 403 avec message explicite
     *
     * @body{amount, sourceCurrency, originCountry, destCountry, destCurrency, receivingMethod, objective}
     */
    public static function create(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];

        // Une quote est une décision financière : elle appartient à un
        // environnement, fixé ici et porté par la ligne.
        $context = ExecutionContext::fromRequest($request, $user);

        // ── 0. Validation de l'origine des fonds ──────────────────────
        // Cette étape est CRITIQUE : elle garantit que l'origine provient
        // d'une source de financement réellement vérifiée et autorisée.
        // NE JAMAIS faire confiance à la valeur envoyée par le frontend.
        $originCountry = strtoupper(trim((string) ($request->input('originCountry', ''))));

        if ($originCountry === '') {
            Response::badRequest('Le pays d\'origine des fonds est requis.');
        }

        // Le Super Admin peut valider un envoi depuis n'importe quel pays
        // (sans KYC ni source vérifiée) pour vérifier en temps réel l'envoi
        // d'un utilisateur d'un pays quelconque.
        $isSuperAdmin = ($user['platform_role'] ?? '') === 'superadmin';
        $originCheck = FundingSourceEngine::validateOrigin($userId, $user, $originCountry, $isSuperAdmin);

        if (!$originCheck['authorized']) {
            Response::forbidden(
                $originCheck['reason']
                    ?? 'Cette origine de transfert n\'est pas disponible pour votre compte.'
            );
        }

        // ── 1. Intent Parser : validation / normalisation ────────
        $intent = IntentParser::parse($request->body());
        $intent['originCountry'] = $originCountry;

        // ── 2. Capability Engine : providers éligibles ───────────
        // Le Super Admin accède à toutes les routes possibles (sans restriction
        // de pays/corridor), depuis n'importe où.
        $providers = CapabilityEngine::findEligible($intent, $context->environment, $isSuperAdmin);

        // ── 3. Policy Engine : vérification avant quotes ────────
        // Conversion du montant source en EUR pour comparer aux plafonds
        // Les taux signifient « 1 EUR = X unités de devise »
        // Pour convertir VERS EUR : on DIVISE par le taux
        $sourceToEur = self::rateToEur($intent['sourceCurrency']);
        $amountRef   = $intent['amount'] / $sourceToEur;

        PolicyEngine::evaluate($user, $intent, $amountRef, $context->environment);

        // ── 4. Quote Engine : calcul des quotes par provider ────
        $quoteId = self::generateQuoteId();
        $quotes  = [];

        foreach ($providers as $provider) {
            try {
                $quotes[] = QuoteEngine::quote($intent, $provider, $quoteId, $context->environment);
            } catch (\Nexus\Services\QuoteRateUnavailable $e) {
                // Aucun taux réel pour cette paire : on refuse de coter plutôt
                // que d'annoncer un montant reçu sans fondement (§12).
                Response::error(
                    $e->getMessage(),
                    503,
                    \Nexus\Services\QuoteRateUnavailable::ERROR_CODE
                );
                return;
            }
        }

        // ── 5. Routing Engine : classement et top 3 ─────────────
        $routes = RoutingEngine::rank(
            $quotes,
            $intent['objective'],
            $quoteId,
            $intent['amount'],
            $intent['sourceCurrency'],
            $intent['destCurrency'],
            $intent['receivingMethod'],
        );

        // ── 6. Construction du payload de réponse ───────────────
        $now = time();
        $expiresAt = gmdate('Y-m-d H:i:s', $now + self::QUOTE_TTL_SECONDS);

        // ── 7. Persistance en base (statut QUOTED) ──────────────
        self::persistQuote(
            $quoteId,
            (int) $user['id'],
            $intent,
            $routes,
            $expiresAt,
            $originCountry,
            $context,
        );

        Response::success([
            'id'         => $quoteId,
            'routes'     => $routes,
            'expires_at' => gmdate('c', $now + self::QUOTE_TTL_SECONDS),
            'ttl_seconds' => self::QUOTE_TTL_SECONDS,
            'intent'     => $intent,
            'created_at' => gmdate('c', $now),
        ]);
    }

    /**
     * GET /api/quotes/:id
     *
     * Récupère une quote existante et le temps restant avant expiration.
     * Si la quote est expirée, retourne un statut EXPIRED avec un
     * message invitant à relancer.
     */
    public static function get(Request $request): void
    {
        $request = AuthMiddleware::handle($request);
        $user    = $request->attribute('user');
        $userId  = (int) $user['id'];
        $quoteId = (string) $request->param('id', '');
        $context = ExecutionContext::fromRequest($request, $user);

        if ($quoteId === '') {
            Response::badRequest('Identifiant de quote requis.');
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, source_currency, origin_country, dest_country, dest_currency, receiving_method,
                    amount_sent, objective, routes_json, selected_route_id, status,
                    expires_at, created_at
             FROM quotes
             WHERE id = :id AND user_id = :uid AND environment = :env
             LIMIT 1'
        );
        // Une quote porte l'environnement de sa création : la relire depuis
        // l'autre environnement reviendrait à réutiliser un prix de test pour
        // un mouvement réel.
        $stmt->execute(['id' => $quoteId, 'uid' => $userId, 'env' => $context->environmentValue()]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::notFound('Quote introuvable.');
        }

        // Vérifier l'expiration
        $now = time();
        $expiresTs = strtotime($row['expires_at'] . ' UTC');
        $remaining = max(0, $expiresTs - $now);

        if ($remaining <= 0 && $row['status'] === 'QUOTED') {
            // Marquer comme expirée
            $pdo->prepare('UPDATE quotes SET status = \'EXPIRED\' WHERE id = :id')
                ->execute(['id' => $quoteId]);

            Response::success([
                'id'          => $quoteId,
                'status'      => 'EXPIRED',
                'remaining'   => 0,
                'expired'     => true,
                'message'     => 'Cette quote a expiré. Relancez une demande depuis /send.',
                'expires_at'  => $row['expires_at'],
                'created_at'  => self::toIso8601($row['created_at']),
            ]);
            return;
        }

        $routes = json_decode($row['routes_json'], true);

        Response::success([
            'id'               => $quoteId,
            'status'           => $row['status'],
            'routes'           => $routes,
            'remaining'        => $remaining,
            'expired'          => $remaining <= 0,
            'expires_at'       => self::toIso8601($row['expires_at']),
            'created_at'       => self::toIso8601($row['created_at']),
            'intent'           => [
                'amount'          => (float) $row['amount_sent'],
                'sourceCurrency'  => $row['source_currency'],
                'originCountry'   => $row['origin_country'] ?? null,
                'destCountry'     => $row['dest_country'],
                'destCurrency'    => $row['dest_currency'],
                'receivingMethod' => $row['receiving_method'],
                'objective'       => $row['objective'],
            ],
        ]);
    }

    // ── Helpers privés ──────────────────────────────────────────────

    /**
     * Génère un ID de quote unique : NX + timestamp hex + 4 caractères aléatoires.
     */
    private static function generateQuoteId(): string
    {
        $ts    = dechex(time());
        $rand  = strtoupper(bin2hex(random_bytes(2)));
        return self::ID_PREFIX . '-' . $ts . '-' . $rand;
    }

    /**
     * Persiste la quote en base (statut QUOTED, TTL 5 min).
     */
    private static function persistQuote(
        string $quoteId,
        int $userId,
        array $intent,
        array $routes,
        string $expiresAt,
        string $originCountry = '',
        ?ExecutionContext $context = null,
    ): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO quotes
                (id, user_id, source_currency, origin_country, dest_country, dest_currency,
                 receiving_method, amount_sent, objective, routes_json,
                 status, environment, expires_at)
             VALUES
                (:id, :uid, :src_cur, :origin_cc, :dest_cc, :dest_cur,
                 :method, :amount, :objective, :routes_json,
                 :status, :env, :expires_at)'
        );

        $stmt->execute([
            'id'          => $quoteId,
            'uid'         => $userId,
            'src_cur'     => $intent['sourceCurrency'],
            'origin_cc'   => $originCountry,
            'dest_cc'     => $intent['destCountry'],
            'dest_cur'    => $intent['destCurrency'],
            'method'      => $intent['receivingMethod'],
            'amount'      => $intent['amount'],
            'objective'   => $intent['objective'],
            'routes_json' => json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'      => 'QUOTED',
            'env'         => $context?->environmentValue() ?? ProviderConfig::defaultEnvironment(),
            'expires_at'  => $expiresAt,
        ]);
    }

    /**
     * Taux EUR vers une devise (cohérent avec QuoteEngine).
     */
    private static function rateToEur(string $currency): float
    {
        $rates = [
            'EUR' => 1.0, 'USD' => 1.0870, 'GBP' => 0.8550,
            'XAF' => 655.957, 'XOF' => 655.957,
            'USDT' => 1.0870, 'USDC' => 1.0870,
        ];
        return $rates[$currency] ?? 1.0;
    }

    /**
     * Convertit une date MySQL en ISO 8601 avec fuseau UTC.
     */
    private static function toIso8601(string $mysqlDatetime): string
    {
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? $mysqlDatetime : gmdate('c', $ts);
    }
}
