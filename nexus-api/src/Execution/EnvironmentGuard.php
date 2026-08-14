<?php

declare(strict_types=1);

namespace Nexus\Execution;

use Nexus\Core\HttpException;

/**
 * EnvironmentGuard — la frontière d'environnement, en un seul endroit.
 *
 * RÈGLE APPLIQUÉE
 * ───────────────
 * Une opération financière ne traverse JAMAIS une frontière d'environnement :
 *
 *     quote.environment == wallet_operation.environment
 *                       == transaction.environment
 *                       == payment.environment
 *                       == ledger_entry.environment
 *
 * Toute divergence est un refus (409 ENVIRONMENT_MISMATCH), jamais une
 * correction. Réaligner silencieusement l'un sur l'autre reviendrait à
 * effacer la preuve qu'une incohérence a eu lieu — et, dans le mauvais sens,
 * à exécuter en argent réel une décision prise en test.
 *
 * L'ANTÉRIORITÉ FAIT AUTORITÉ
 * ───────────────────────────
 * Pour un objet déjà persisté (quote, opération, transaction, paiement),
 * c'est la valeur EN BASE qui fait foi, jamais la configuration courante du
 * serveur. Une transaction de production reste de production même si le
 * déploiement bascule ensuite en sandbox : l'histoire ne se recalcule pas.
 *
 * Ce garde est volontairement le point de passage UNIQUE : dupliquer la
 * comparaison dans chaque contrôleur produirait, tôt ou tard, une variante
 * qui oublie un cas.
 */
final class EnvironmentGuard
{
    public const ERROR_CODE = 'ENVIRONMENT_MISMATCH';

    private function __construct()
    {
    }

    /**
     * Vérifie qu'un objet persisté appartient bien à l'environnement du
     * contexte d'exécution courant.
     *
     * @param string           $persisted Valeur lue en base ('' = inconnue).
     * @param ExecutionContext $context   Contexte déjà résolu et autorisé.
     * @param string           $subject   Objet concerné, pour le message.
     *
     * @throws HttpException 409 en cas de divergence.
     */
    public static function assertMatches(string $persisted, ExecutionContext $context, string $subject): void
    {
        $persisted = trim($persisted);

        // Une ligne antérieure à la colonne n'a pas d'environnement connu.
        // La rejeter bloquerait des données historiques légitimes ; la
        // « corriger » inventerait une information. On laisse passer, la
        // valeur par défaut prudente du schéma ayant déjà tranché.
        if ($persisted === '') {
            return;
        }

        if ($persisted === $context->environmentValue()) {
            return;
        }

        ExecutionAudit::recordDenied(
            self::ERROR_CODE,
            $context->actorUserId,
            $context->environmentValue(),
            ['subject' => $subject, 'persisted_environment' => $persisted]
        );

        throw new HttpException(
            409,
            sprintf(
                '%s appartient à l\'environnement « %s » et ne peut pas être utilisé dans une exécution « %s ».',
                $subject,
                $persisted,
                $context->environmentValue()
            ),
            self::ERROR_CODE
        );
    }

    /**
     * Vérifie que deux objets d'un même cycle financier partagent leur
     * environnement (ex. opération source ↔ écriture ledger).
     *
     * @throws HttpException 409 en cas de divergence.
     */
    public static function assertSameEnvironment(
        string $left,
        string $right,
        string $leftSubject,
        string $rightSubject
    ): void {
        $left  = trim($left);
        $right = trim($right);

        if ($left === '' || $right === '' || $left === $right) {
            return;
        }

        ExecutionAudit::recordDenied(
            self::ERROR_CODE,
            null,
            null,
            [
                'left'  => $leftSubject,  'left_environment'  => $left,
                'right' => $rightSubject, 'right_environment' => $right,
            ]
        );

        throw new HttpException(
            409,
            sprintf(
                'Incohérence d\'environnement : %s est « %s » alors que %s est « %s ». '
                . 'Une opération financière ne peut pas franchir cette frontière.',
                $leftSubject,
                $left,
                $rightSubject,
                $right
            ),
            self::ERROR_CODE
        );
    }
}
