<?php

declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database;
use Nexus\Core\HttpException;
use Nexus\Execution\ExecutionEnvironment;
use Nexus\Providers\ProviderConfig;

/**
 * Policy & Risk Engine — vérifie la conformité avant d'autoriser un transfert.
 *
 * Étape 5 du pipeline. Contrôles :
 *   1. Statut du compte (PENDING → refus transfert)
 *   2. Plafonds mensuels selon vérification + type de compte
 *      (non vérifié personnel : 1 000 EUR ; non vérifié entreprise : 2 000 EUR ;
 *       vérifié : barème KYC standard / advanced)
 *   3. Sanctions (déléguées à SanctionsScreening — jamais simulées)
 *   4. Seuils réglementaires (KYC documentaire recommandé au-delà de 1000 EUR)
 *   5. Disponibilité du wallet (fonds suffisants)
 *
 * Retourne un verdict : APPROVED | DECLINED | REVIEW_REQUIRED.
 * Si DECLINED, lève une HttpException avec la raison.
 *
 * HONNÊTETÉ DU VERDICT (§37)
 * ──────────────────────────
 * Le verdict rend compte de ce qui a RÉELLEMENT été vérifié. Un contrôle qui
 * n'a pas pu s'exécuter n'est jamais compté comme passé : le détail porte
 * `sanctions_screened => false` et le message le dit explicitement. La
 * formule « Tous les contrôles de conformité sont passés » n'est employée
 * que lorsque c'est vrai.
 */
final class PolicyEngine
{
    /** Plafond mensuel (EUR) — compte personnel non vérifié (KYC none/basic). */
    private const UNVERIFIED_PERSONAL_LIMIT = '1000.00';

    /** Plafond mensuel (EUR) — compte entreprise non vérifié (KYB ≠ verified). */
    private const UNVERIFIED_BUSINESS_LIMIT = '2000.00';

    /** Plafonds mensuels par niveau KYC une fois le compte vérifié (EUR). */
    private const KYC_LIMITS = [
        'none'     => '1000.00',   // aligné plafond non vérifié personnel
        'basic'    => '1000.00',   // règlement UE 2015/847
        'standard' => '2000.00',   // KYC documentaire
        'advanced' => '10000.00',  // due diligence renforcée
    ];

    /** Seuil KYC documentaire recommandé (montant EUR). */
    private const KYC_REQUIRED_THRESHOLD = '1000.00';

    /** Statuts qui bloquent les transferts. */
    private const BLOCKED_STATUSES = ['PENDING', 'SUSPENDED', 'CLOSED'];

    /** Motifs de blocage par statut. */
    private const BLOCK_REASONS = [
        'PENDING'   => 'Votre compte est en attente de vérification. Complétez votre KYC pour effectuer des transferts.',
        'SUSPENDED' => 'Votre compte est temporairement suspendu. Contactez le support NEXUS.',
        'CLOSED'    => 'Votre compte a été fermé.',
    ];

    private function __construct() {}

    /**
     * Compte considéré vérifié pour les plafonds.
     * Personnel : KYC standard/advanced. Entreprise : KYB verified.
     */
    public static function isVerified(array $user): bool
    {
        $accountType = (string) ($user['account_type'] ?? 'personal');
        if ($accountType === 'business') {
            return (($user['kyb_status'] ?? 'none') === 'verified');
        }
        $kyc = (string) ($user['kyc_level'] ?? 'none');
        return in_array($kyc, ['standard', 'advanced'], true);
    }

    /**
     * Plafond mensuel EUR selon type de compte et état de vérification.
     */
    public static function resolveMonthlyLimit(array $user): string
    {
        $accountType = (string) ($user['account_type'] ?? 'personal');
        $kyc = (string) ($user['kyc_level'] ?? 'none');

        if ($accountType === 'business') {
            if (!self::isVerified($user)) {
                return self::UNVERIFIED_BUSINESS_LIMIT;
            }
            // Entreprise vérifiée : barème KYC, plancher 2 000 EUR.
            $limit = self::KYC_LIMITS[$kyc] ?? self::KYC_LIMITS['standard'];
            if (bccomp($limit, self::UNVERIFIED_BUSINESS_LIMIT, 8) < 0) {
                return self::UNVERIFIED_BUSINESS_LIMIT;
            }
            return $limit;
        }

        if (!self::isVerified($user)) {
            return self::UNVERIFIED_PERSONAL_LIMIT;
        }

        return self::KYC_LIMITS[$kyc] ?? self::KYC_LIMITS['standard'];
    }

    /**
     * Vérifie toutes les politiques pour une intention donnée.
     *
     * @param array{id: int, status: string, kyc_level: string, account_type: string} $user
     * @param array{amount: float, sourceCurrency: string} $intent
     * @param float $amountRef Montant converti en EUR (pour comparaison aux plafonds).
     * @param ExecutionEnvironment|null $environment Environnement d'exécution.
     *        Détermine l'arbitrage d'un filtrage de sanctions indisponible.
     *        `null` retombe sur l'environnement par défaut du déploiement —
     *        jamais sur « sandbox » en dur, sans quoi un appelant oublieux
     *        contournerait le blocage prévu en production.
     *
     * @return array{decision: string, reason: string, details: array<string, mixed>}
     *
     * @throws HttpException 403 si la transaction est refusée.
     */
    public static function evaluate(
        array $user,
        array $intent,
        float|string $amountRef,
        ?ExecutionEnvironment $environment = null
    ): array {
        $environment ??= ExecutionEnvironment::fromString(ProviderConfig::defaultEnvironment());

        $status   = $user['status'];
        $kycLevel = $user['kyc_level'];
        $userId   = (int) $user['id'];
        $amount   = bcadd((string) $intent['amount'], '0', 8);
        $amountRefDecimal = bcadd((string) $amountRef, '0', 8);

        $details = [];
        $decision = 'APPROVED';
        $reason   = '';

        // ── 1. Statut du compte ──────────────────────────────────
        if (in_array($status, self::BLOCKED_STATUSES, true)) {
            $reason = self::BLOCK_REASONS[$status] ?? 'Compte non autorisé pour les transferts.';
            return self::declined($reason, ['status' => $status]);
        }

        // ── 1bis. EXEMPTION SUPER ADMIN ──────────────────────────
        // Le Super Admin peut envoyer sans restriction de plafond KYC ni de
        // filtrage de corridor/sanctions : il a accès à toutes les routes
        // possibles depuis n'importe où. On conserve uniquement le contrôle
        // de disponibilité du wallet (sinon aucune exécution n'est possible)
        // et le statut du compte.
        $isSuperAdmin = ($user['platform_role'] ?? '') === 'superadmin';
        if ($isSuperAdmin) {
            $details['superadmin_exempt'] = true;

            // Disponibilité du wallet (toujours vérifiée).
            $available = self::getWalletAvailable($userId, $intent['sourceCurrency']);
            $details['wallet_available'] = (float) $available;
            if (bccomp($available, $amount, 8) < 0) {
                return self::declined(
                    sprintf('Solde disponible insuffisant : %.2f %s.', (float) $available, $intent['sourceCurrency']),
                    $details
                );
            }

            $details['status'] = $status;
            $details['kyc_level'] = $kycLevel;
            return [
                'decision' => 'APPROVED',
                'reason'   => 'Super Admin — envoi sans restriction (toutes routes autorisées).',
                'details'  => $details,
            ];
        }

        // ── 2. Plafonds (vérification + type de compte) ─────────
        // Les entreprises non vérifiées (KYB) restent autorisées dans la
        // limite UNVERIFIED_BUSINESS_LIMIT — plus de blocage total.
        $monthlyLimit = self::resolveMonthlyLimit($user);
        $verified = self::isVerified($user);
        $monthlyTotal = self::getMonthlyTotal($userId, $intent['sourceCurrency']);

        $newTotal = bcadd($monthlyTotal, $amountRefDecimal, 8);
        $remainingDecimal = bcsub($monthlyLimit, $monthlyTotal, 8);
        if (bccomp($remainingDecimal, '0', 8) < 0) {
            $remainingDecimal = '0';
        }
        $details['monthly_limit']     = (float) $monthlyLimit;
        $details['monthly_used']      = (float) bcadd($monthlyTotal, '0.005', 2);
        $details['monthly_remaining'] = (float) bcadd($remainingDecimal, '0.005', 2);
        $details['verified']          = $verified;
        $details['account_type']      = (string) ($user['account_type'] ?? 'personal');

        if (bccomp($newTotal, $monthlyLimit, 8) > 0) {
            $remaining = $remainingDecimal;
            $tierLabel = $verified ? "niveau KYC : {$kycLevel}" : (
                (($user['account_type'] ?? 'personal') === 'business')
                    ? 'entreprise non vérifiée'
                    : 'compte non vérifié'
            );
            $reason = "Plafond mensuel de {$monthlyLimit} EUR ({$tierLabel}) " .
                      "presque atteint. Il vous reste {$remaining} EUR ce mois-ci. " .
                      'Complétez la vérification pour augmenter vos limites.';
            return self::declined($reason, $details);
        }

        // ── 3. Sanctions ────────────────────────────────────────
        // Délégué à SanctionsScreening, qui distingue trois états :
        // CLEARED (filtré, rien trouvé), HIT (refus) et UNAVAILABLE (aucune
        // source configurée → le contrôle n'a PAS eu lieu). UNAVAILABLE n'est
        // jamais assimilé à CLEARED.
        $screening = SanctionsScreening::screenCountry((string) ($intent['destCountry'] ?? ''));
        $details['sanctions_status']   = $screening['status'];
        $details['sanctions_screened'] = $screening['screened'];

        if ($screening['status'] === SanctionsScreening::HIT) {
            return self::declined(
                'Transaction bloquée pour raison réglementaire.',
                array_merge($details, ['sanction' => true])
            );
        }

        if ($screening['status'] === SanctionsScreening::UNAVAILABLE) {
            // Production : refus. Autoriser un mouvement d'argent réel sans
            // avoir filtré les sanctions serait exactement le faux succès que
            // la règle d'honnêteté interdit.
            if (SanctionsScreening::unavailableBlocks($environment)) {
                return self::declined(
                    'Filtrage des sanctions indisponible : la transaction ne peut pas être '
                    . 'autorisée en production tant que le contrôle réglementaire n\'est pas configuré.',
                    array_merge($details, ['sanctions_unavailable' => true])
                );
            }

            // Sandbox : on laisse passer (aucun argent réel) mais le verdict
            // porte la mention du contrôle manquant.
            $decision = 'REVIEW_REQUIRED';
            $reason   = 'Filtrage des sanctions non configuré : ce contrôle réglementaire '
                      . 'n\'a pas été effectué (sandbox).';
        }

        // ── 4. KYC documentaire recommandé au-delà du seuil ─────
        if (bccomp($amountRefDecimal, self::KYC_REQUIRED_THRESHOLD, 8) > 0
            && !$verified
            && ($user['account_type'] ?? 'personal') !== 'business') {
            // Personnel non vérifié : au-delà de 1000 EUR → REVIEW (le plafond
            // mensuel 1000 bloque déjà le dépassement dur).
            $decision = 'REVIEW_REQUIRED';
            $reason   = "Montant de {$amountRef} EUR nécessite un niveau KYC standard ou supérieur.";
            $details['kyc_required'] = 'standard';
        }

        // ── 5. Disponibilité du wallet ──────────────────────────
        $available = self::getWalletAvailable($userId, $intent['sourceCurrency']);
        $details['wallet_available'] = (float) $available;

        if (bccomp($available, $amount, 8) < 0) {
            return self::declined(
                "Fonds insuffisants. Solde disponible : {$available} {$intent['sourceCurrency']}.",
                array_merge($details, ['wallet_shortage' => true])
            );
        }

        // Le message par défaut n'est employé que si TOUS les contrôles ont
        // réellement eu lieu. Sinon `$reason` a déjà été renseigné plus haut
        // avec la mention du contrôle manquant.
        return [
            'decision' => $decision,
            'reason'   => $reason ?: 'Tous les contrôles de conformité sont passés.',
            'details'  => $details,
        ];
    }

    /**
     * Exposition des plafonds pour le frontend (dashboard / send).
     *
     * @param array{id:int,kyc_level:string,account_type?:string,kyb_status?:string} $user
     * @return array{
     *   kyc_level:string,
     *   monthly_limit_eur:float,
     *   monthly_used_eur:float,
     *   monthly_remaining_eur:float,
     *   kyc_required_threshold_eur:float,
     *   verified:bool
     * }
     */
    public static function limitsFor(array $user): array
    {
        $kyc = (string) ($user['kyc_level'] ?? 'none');
        $limit = self::resolveMonthlyLimit($user);
        $used = self::getMonthlyTotal((int) $user['id'], 'EUR');
        $remaining = bcsub($limit, $used, 8);
        if (bccomp($remaining, '0', 8) < 0) {
            $remaining = '0';
        }

        return [
            'kyc_level'                   => $kyc,
            'monthly_limit_eur'           => (float) $limit,
            'monthly_used_eur'            => (float) bcadd($used, '0.005', 2),
            'monthly_remaining_eur'       => (float) bcadd($remaining, '0.005', 2),
            'kyc_required_threshold_eur'  => (float) self::KYC_REQUIRED_THRESHOLD,
            'verified'                    => self::isVerified($user),
            'account_type'                => (string) ($user['account_type'] ?? 'personal'),
        ];
    }

    /**
     * Calcule le total des transferts mensuels de l'utilisateur (en EUR).
     *
     * Ne fait confiance à `amount_ref` que lorsqu'il est une conversion plausible :
     * le seed historique a parfois recopié `amount` (XAF) dans `amount_ref` (EUR),
     * ce qui gonflait le plafond (ex. 50 000 « EUR » pour un envoi XAF).
     */
    private static function getMonthlyTotal(int $userId, string $currency): string
    {
        $pdo = Database::getConnection();
        // Devises à forte nominalité : amount_ref EUR doit être << amount.
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(
                CASE
                  WHEN UPPER(currency) = 'EUR' THEN amount
                  WHEN UPPER(currency) IN ('XAF','XOF','GNF','UGX','RWF','TZS','NGN','CDF','ZMW','KES','GHS')
                       AND amount_ref > 0 AND amount_ref < amount THEN amount_ref
                  WHEN UPPER(currency) NOT IN ('XAF','XOF','GNF','UGX','RWF','TZS','NGN','CDF','ZMW','KES','GHS')
                       AND amount_ref > 0
                       AND UPPER(COALESCE(ref_currency, 'EUR')) = 'EUR' THEN amount_ref
                  ELSE 0
                END
             ), 0)
             FROM transactions
             WHERE user_id = :uid
               AND type = 'send'
               AND direction = 'out'
               AND status NOT IN ('cancelled', 'failed')
               AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        );
        $stmt->execute(['uid' => $userId]);
        return bcadd((string) $stmt->fetchColumn(), '0', 8);
    }

    /**
     * Récupère le solde disponible d'un wallet pour une devise donnée.
     */
    private static function getWalletAvailable(int $userId, string $currency): string
    {
        $wallet = WalletService::getWallet($userId, $currency);
        if ($wallet === null) {
            return '0.00000000';
        }
        $availableInfo = WalletService::getAvailable($wallet['id']);
        return bcadd((string) $availableInfo['available_balance'], '0', 8);
    }

    /**
     * Déclenche une exception HttpException avec le motif de refus.
     */
    private static function declined(string $reason, array $details): never
    {
        throw new HttpException(403, $reason, 'POLICY_DECLINED');
    }
}
