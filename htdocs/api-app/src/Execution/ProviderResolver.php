<?php

declare(strict_types=1);

namespace Nexus\Execution;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Providers\ProviderAdapter;
use Nexus\Services\ProviderCatalog;
use Nexus\Providers\ProviderConfig;
use Nexus\Providers\ProviderEligibilityService;
use Nexus\Providers\ProviderRegistry;
use Nexus\Providers\ProviderRouteCandidate;
use Nexus\Providers\ProviderRouteScoring;
use Nexus\Services\ProviderCredentialService;
use Nexus\Services\ProviderHealthService;
use Nexus\Services\ProviderLatency;
use Nexus\Services\ProviderReliability;

/**
 * ProviderResolver — résolution multi-provider pour le routing (Milestone 2).
 *
 * INVERSION DE DÉPENDANCE FONDAMENTALE
 * ────────────────────────────────────
 * Le sens de la résolution est imposé :
 *
 *     contexte → environnement → credential          (CORRECT)
 *     credential disponible → environnement          (INTERDIT)
 */
final class ProviderResolver
{
    private function __construct()
    {
    }

    /**
     * Résout un adapter utilisable pour ce provider dans ce contexte.
     *
     * @throws HttpException 404 provider inconnu
     *                       409 provider non configuré POUR CET ENVIRONNEMENT
     */
    public static function resolve(string $slug, ExecutionContext $context): ProviderAdapter
    {
        if (!ProviderCatalog::exists($slug)) {
            throw new HttpException(404, 'Provider inconnu : ' . $slug . '.', 'PROVIDER_UNKNOWN');
        }

        $environment = $context->environmentValue();

        if (!self::hasCredentialFor($slug, $context)) {
            ExecutionAudit::recordDenied(
                'PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT',
                $context->actorUserId,
                $environment,
                ['provider' => $slug]
            );

            throw new HttpException(
                409,
                sprintf(
                    'Provider « %s » non configuré pour l\'environnement « %s ». '
                    . 'Aucun repli vers l\'autre environnement n\'est effectué.',
                    $slug,
                    $environment
                ),
                'PROVIDER_NOT_CONFIGURED_FOR_ENVIRONMENT'
            );
        }

        return ProviderRegistry::get($slug);
    }

    /**
     * Recherche les providers éligibles pour une opération de transfert.
     *
     * @param array{
     *     amount: float,
     *     sourceCurrency: string,
     *     sourceCountry?: string,
     *     destCountry: string,
     *     destCurrency: string,
     *     receivingMethod: string,
     *     operation?: string
     * } $intent
     *
     * @return array{
     *     status: string,
     *     operation: string,
     *     candidates: list<array<string,mixed>>,
     *     selected: array<string,mixed>|null,
     *     eligible_providers: list<array<string,mixed>>
     * }
     */
    public static function resolveTransferRoute(
        array $intent,
        ExecutionContext $context,
        bool $allRoutes = false,
    ): array {
        $resolution = self::resolveProviders($intent, $context, $allRoutes);
        $eligible   = array_values(array_filter(
            $resolution['candidates'],
            static fn (array $candidate): bool => ($candidate['eligible'] ?? false) === true
        ));

        return [
            'status'             => $resolution['status'],
            'operation'          => (string) ($intent['operation'] ?? 'payout'),
            'candidates'         => $resolution['candidates'],
            'selected'           => $eligible[0] ?? null,
            'eligible_providers' => self::mapEligibleForQuoteEngine($eligible, $context),
        ];
    }

    /**
     * @param array{
     *     amount: float,
     *     sourceCurrency: string,
     *     sourceCountry?: string,
     *     destCountry: string,
     *     destCurrency: string,
     *     receivingMethod: string,
     *     operation?: string
     * } $intent
     *
     * @return array{
     *     status: string,
     *     candidates: list<array<string,mixed>>
     * }
     */
    public static function resolveProviders(
        array $intent,
        ExecutionContext $context,
        bool $allRoutes = false,
    ): array {
        $intent['operation'] ??= 'payout';
        $candidates = [];

        foreach (ProviderCatalog::all() as $slug => $provider) {
            $evaluation = ProviderEligibilityService::evaluate($slug, $intent, $context, $allRoutes);
            $health     = self::healthConnectionFor($slug, $context);
            $score      = ProviderRouteScoring::scoreFor($evaluation, $health);
            $candidate  = ProviderRouteCandidate::fromEvaluation(
                $slug,
                $provider,
                $intent,
                $evaluation,
                $score,
                $health,
            );
            $candidates[] = $candidate->toArray();
        }

        $ranked = ProviderRouteScoring::rank(
            array_map(
                static fn (array $row): ProviderRouteCandidate => new ProviderRouteCandidate(
                    (string) $row['provider'],
                    (string) $row['operation'],
                    (string) $row['source_currency'],
                    (string) $row['destination_currency'],
                    (string) $row['source_country'],
                    (string) $row['destination_country'],
                    (string) $row['channel'],
                    (bool) $row['eligible'],
                    isset($row['estimated_fee']) ? (float) $row['estimated_fee'] : null,
                    isset($row['estimated_fx']) ? (float) $row['estimated_fx'] : null,
                    isset($row['estimated_delivery']) ? (int) $row['estimated_delivery'] : null,
                    (string) $row['provider_health'],
                    (int) $row['score'],
                    (array) $row['reasons'],
                ),
                $candidates
            )
        );

        $rankedArrays = array_map(static fn (ProviderRouteCandidate $c): array => $c->toArray(), $ranked);
        $eligible     = array_values(array_filter(
            $rankedArrays,
            static fn (array $row): bool => ($row['eligible'] ?? false) === true
        ));

        return [
            'status'     => $eligible === [] ? 'NO_ELIGIBLE_PROVIDER' : 'OK',
            'candidates' => $rankedArrays,
        ];
    }

    /**
     * Une credential existe-t-elle pour CE provider dans CET environnement ?
     */
    public static function hasCredentialFor(string $slug, ExecutionContext $context): bool
    {
        $provider    = ProviderCatalog::get($slug);
        $environment = $context->environmentValue();

        if ($provider === null) {
            return false;
        }

        $required  = [];
        foreach (($provider['credentials'] ?? []) as $field) {
            if (($field['required'] ?? false) === true) {
                $required[] = (string) $field['key'];
            }
        }

        if ($required !== []) {
            $allPresent = true;
            foreach ($required as $key) {
                if (ProviderConfig::credential($slug, $key, $environment) === null) {
                    $allPresent = false;
                    break;
                }
            }
            if ($allPresent) {
                return true;
            }
        }

        $row = ProviderCredentialService::findEffectiveRow(
            Database::getConnection(),
            $context->subjectUserId,
            $slug,
            $environment
        );

        return $row !== null && ($row['credentials_enc'] ?? null) !== null;
    }

    /**
     * @param list<string> $slugs
     * @return list<string>
     */
    public static function usableSlugs(array $slugs, ExecutionContext $context): array
    {
        $usable = [];
        foreach ($slugs as $slug) {
            if (self::hasCredentialFor($slug, $context)) {
                $usable[] = $slug;
            }
        }

        return $usable;
    }

    /**
     * @param list<array<string,mixed>> $eligibleCandidates
     * @return list<array<string,mixed>>
     */
    private static function mapEligibleForQuoteEngine(array $eligibleCandidates, ExecutionContext $context): array
    {
        $out = [];
        foreach ($eligibleCandidates as $candidate) {
            $slug = (string) ($candidate['provider'] ?? '');
            if ($slug === '') {
                continue;
            }
            $provider = ProviderCatalog::get($slug);
            if ($provider === null) {
                continue;
            }

            $reliability = ProviderReliability::forProvider($slug, $context->environment);
            $latency     = ProviderLatency::forProvider($slug, $context->environment);

            $out[] = [
                'slug'               => $slug,
                'name'               => $provider['name'],
                'category'           => $provider['category'],
                'reliability'        => $reliability['score'],
                'reliability_status' => $reliability['status'],
                'reliability_obs'    => $reliability['observations'],
                'delay_seconds'      => $latency['seconds'],
                'delay_status'       => $latency['status'],
                'delay_obs'          => $latency['observations'],
                'delay_p90_seconds'  => $latency['p90_seconds'],
                'method_type'        => (string) ($candidate['channel'] ?? ''),
                'route_score'        => (int) ($candidate['score'] ?? 0),
                'route_reasons'      => $candidate['reasons'] ?? [],
            ];
        }

        return $out;
    }

    private static function healthConnectionFor(string $slug, ExecutionContext $context): string
    {
        try {
            $pdo    = Database::getConnection();
            $health = ProviderHealthService::healthFor($pdo, $slug, $context->environmentValue());
            return strtoupper((string) ($health['connection'] ?? 'NOT_CONFIGURED'));
        } catch (\Throwable) {
            return 'NOT_CONFIGURED';
        }
    }
}
