<?php

declare(strict_types=1);

namespace Nexus\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests pour les login scopes (personal, business, admin)
 */
final class LoginScopeTest extends TestCase
{
    /**
     * Test de validation du scope personal
     */
    public function testPersonalScopeValidation(): void
    {
        // Personal user avec account_type=personal et platform_role=user -> autorisé
        $this->assertTrue(self::validateLoginScope('personal', 'user', 'personal'));
        
        // Personal user avec platform_role=superadmin -> refusé
        $this->assertFalse(self::validateLoginScope('personal', 'superadmin', 'personal'));
        
        // Business user -> refusé
        $this->assertFalse(self::validateLoginScope('business', 'user', 'personal'));
    }

    /**
     * Test de validation du scope business
     */
    public function testBusinessScopeValidation(): void
    {
        // Business user avec account_type=business et platform_role=user -> autorisé
        $this->assertTrue(self::validateLoginScope('business', 'user', 'business'));
        
        // Business user avec platform_role=superadmin -> refusé
        $this->assertFalse(self::validateLoginScope('business', 'superadmin', 'business'));
        
        // Personal user -> refusé
        $this->assertFalse(self::validateLoginScope('personal', 'user', 'business'));
    }

    /**
     * Test de validation du scope admin
     */
    public function testAdminScopeValidation(): void
    {
        // Superadmin -> autorisé
        $this->assertTrue(self::validateLoginScope('personal', 'superadmin', 'admin'));
        $this->assertTrue(self::validateLoginScope('business', 'superadmin', 'admin'));
        
        // User normal (personal ou business) -> refusé
        $this->assertFalse(self::validateLoginScope('personal', 'user', 'admin'));
        $this->assertFalse(self::validateLoginScope('business', 'user', 'admin'));
    }

    /**
     * Helper pour tester la logique de validation
     */
    private static function validateLoginScope(string $accountType, string $platformRole, string $scope): bool
    {
        return match ($scope) {
            'personal' => $accountType === 'personal' && $platformRole !== 'superadmin',
            'business' => $accountType === 'business' && $platformRole !== 'superadmin',
            'admin'    => $platformRole === 'superadmin',
            default    => false,
        };
    }
}
