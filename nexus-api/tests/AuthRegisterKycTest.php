<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Controllers\AuthController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * KYC — validation de la date de naissance à l'inscription.
 *
 * La vérification d'identité (Sumsub) exige une date de naissance valide et la
 * majorité. On protège la règle de validation côté serveur, indépendamment du
 * formulaire (défense en profondeur).
 */
final class AuthRegisterKycTest extends TestCase
{
    /** Appelle la méthode privée AuthController::validateBirthDate. */
    private function validate(string $birthDate): ?string
    {
        $method = new ReflectionMethod(AuthController::class, 'validateBirthDate');
        $method->setAccessible(true);

        /** @var string|null */
        return $method->invoke(null, $birthDate);
    }

    public function test_date_valide_majeure_acceptee(): void
    {
        self::assertNull($this->validate('1990-05-14'));
    }

    public function test_date_manquante_refusee(): void
    {
        self::assertNotNull($this->validate(''));
        self::assertStringContainsString('requise', $this->validate('') ?? '');
    }

    public function test_format_invalide_refuse(): void
    {
        self::assertNotNull($this->validate('14/05/1990'));
        self::assertNotNull($this->validate('1990-13-40'));
        self::assertNotNull($this->validate('not-a-date'));
    }

    public function test_date_future_refusee(): void
    {
        $future = (new \DateTimeImmutable('+1 year'))->format('Y-m-d');
        self::assertNotNull($this->validate($future));
    }

    public function test_mineur_refuse(): void
    {
        // 17 ans aujourd'hui.
        $minor = (new \DateTimeImmutable('-17 years'))->format('Y-m-d');
        self::assertNotNull($this->validate($minor));
        self::assertStringContainsString('18 ans', $this->validate($minor) ?? '');
    }

    public function test_limite_18_ans_exactement(): void
    {
        // Né exactement il y a 18 ans : accepté à la limite.
        $adult = (new \DateTimeImmutable('-18 years'))->format('Y-m-d');
        self::assertNull($this->validate($adult));
    }
}
