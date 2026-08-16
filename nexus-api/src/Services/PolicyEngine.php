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
 *   2. Plafonds par niveau KYC (LIMITED : 200 EUR/mois, STANDARD : 2000, etc.)
 *   3. Sanctions (déléguées à SanctionsScreening — jamais simulées)
 *   4. Seuils réglementaires (KYC requis au-delà de 1000 EUR)
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
    /** Plafonds mensuels par niveau KYC (EUR). */
    private const KYC_LIMITS = [
        'none'     => 200.0,
        'basic'    => 500.0,
        'standard' => 2000.0,
        'advanced' => 10000.0,
    ];

    /** Seuil KYC requis pour le transfert (montant EUR au-delà duquel KYC obligatoire). */
    private const KYC_REQUIRED_THRESHOLD = 1000.0;

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
        float $amountRef,
        ?ExecutionEnvironment $environment = null
    ): array {
        $environment ??= ExecutionEnvironment::fromString(ProviderConfig::defaultEnvironment());

        $status   = $user['status'];
        $kycLevel = $user['kyc_level'];
        $userId   = (int) $user['id'];
        $amount   = (float) $intent['amount'];

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
            $details['wallet_available'] = $available;
            if ($available < $amount) {
                return self::declined(
                    sprintf('Solde disponible insuffisant : %.2f %s.', $available, $intent['sourceCurrency']),
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

        // ── 1ter. KYB obligatoire pour les comptes Business ────
        // Une entreprise doit être vérifiée (Sumsub subject_type=company)
        // avant d'effectuer des paiements. Seul `kyb_status=verified` autorise
        // les opérations ; aucun autre statut n'est toléré (§37).
        if (($user['account_type'] ?? 'personal') === 'business'
            && ($user['kyb_status'] ?? 'none') !== 'verified') {
            return self::declined(
                'Votre entreprise doit être vérifiée (KYB) avant d\'effectuer des paiements. '
                . 'Complétez la vérification d\'entreprise (Sumsub) dans votre espace.',
                [
                    'kyb_required' => true,
                    'kyb_status'   => $user['kyb_status'] ?? 'none',
                ]
            );
        }

        // ── 2. Plafonds KYC ─────────────────────────────────────
        $monthlyLimit = self::KYC_LIMITS[$kycLevel] ?? self::KYC_LIMITS['basic'];
        $monthlyTotal = self::getMonthlyTotal($userId, $intent['sourceCurrency']);

        $newTotal = $monthlyTotal + $amountRef;
        $details['monthly_limit']     = $monthlyLimit;
        $details['monthly_used']      = round($monthlyTotal, 2);
        $details['monthly_remaining'] = round(max(0, $monthlyLimit - $monthlyTotal), 2);

        if ($newTotal > $monthlyLimit) {
            $remaining = max(0, $monthlyLimit - $monthlyTotal);
            $reason = "Plafond mensuel de {$monthlyLimit} EUR (niveau KYC : {$kycLevel}) " .
                      "presque atteint. Il vous reste {$remaining} EUR ce mois-ci. " .
                      "Relevez votre niveau KYC pour augmenter vos limites.";
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

        // ── 4. KYC requis au-delà du seuil ──────────────────────
        if ($amountRef > self::KYC_REQUIRED_THRESHOLD && in_array($kycLevel, ['none', 'basic'], true)) {
            $decision = 'REVIEW_REQUIRED';
            $reason   = "Montant de {$amountRef} EUR nécessite un niveau KYC standard ou supérieur.";
            $details['kyc_required'] = 'standard';
        }

        // ── 5. Disponibilité du wallet ──────────────────────────
        $available = self::getWalletAvailable($userId, $intent['sourceCurrency']);
        $details['wallet_available'] = $available;

        if ($available < $amount) {
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
     * Calcule le total des transferts mensuels de l'utilisateur (en EUR).
     */
    private static function getMonthlyTotal(int $userId, string $currency): float
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_ref), 0)
             FROM transactions
             WHERE user_id = :uid
               AND type = 'send'
               AND direction = 'out'
               AND status NOT IN ('cancelled', 'failed')
               AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        );
        $stmt->execute(['uid' => $userId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Récupère le solde disponible d'un wallet pour une devise donnée.
     */
    private static function getWalletAvailable(int $userId, string $currency): float
    {
        $wallet = WalletService::getWallet($userId, $currency);
        if ($wallet === null) {
            return 0.0;
        }
        $availableInfo = WalletService::getAvailable($wallet['id']);
        return (float) $availableInfo['available_balance'];
    }

    /**
     * Déclenche une exception HttpException avec le motif de refus.
     */
    private static function declined(string $reason, array $details): never
    {
        throw new HttpException(403, $reason, 'POLICY_DECLINED');
    }
}
