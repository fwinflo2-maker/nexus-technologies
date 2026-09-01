<?php

declare(strict_types=1);

namespace Nexus\Kyc;

/**
 * Nature du sujet vérifié (§21, §22).
 *
 * KYB n'est PAS un KYC avec un drapeau : une entreprise implique la
 * vérification de la société, de ses représentants et de ses bénéficiaires
 * effectifs. Les deux parcours sont donc distincts jusque dans le niveau
 * de vérification demandé au provider.
 */
enum KycSubjectType: string
{
    /** Personne physique — KYC. */
    case INDIVIDUAL = 'individual';

    /** Entreprise — KYB (company + representatives + beneficial owners). */
    case COMPANY = 'company';

    public function isCompany(): bool
    {
        return $this === self::COMPANY;
    }
}
