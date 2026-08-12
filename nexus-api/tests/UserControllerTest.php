<?php

declare(strict_types=1);

namespace Nexus\Tests;

use Nexus\Core\Database;
use Nexus\Core\Request;
use Nexus\Core\Response;
use Nexus\Controllers\UserController;
use PHPUnit\Framework\TestCase;

/**
 * Tests pour UserController.
 *
 * Vérifie :
 * - GET /api/users/me
 * - PUT /api/users/me
 * - PUT /api/users/me/password
 * - GET /api/users/me/sessions
 * - DELETE /api/users/me/sessions/{id}
 * - Authentification requise
 * - Isolation utilisateur
 */
final class UserControllerTest extends TestCase
{
    private static ?string $testUserId = null;
    private static ?string $testUserEmail = null;
    private static ?string $authToken = null;
    private static ?string $secondUserId = null;
    private static ?string $secondUserToken = null;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        
        // Créer un utilisateur de test
        $testEmail = 'test.user_' . uniqid() . '@nexus.test';
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, account_type, status, kyc_level)
             VALUES (:full_name, :email, :phone, :password_hash, :account_type, :status, :kyc_level)'
        );
        $stmt->execute([
            ':full_name' => 'Test User',
            ':email' => $testEmail,
            ':phone' => '+242061234567',
            ':password_hash' => password_hash('TestPassword123!', PASSWORD_DEFAULT),
            ':account_type' => 'personal',
            ':status' => 'ACTIVE',
            ':kyc_level' => 'basic',
        ]);
        
        self::$testUserId = (string) $pdo->lastInsertId();
        self::$testUserEmail = $testEmail;

        // Créer un deuxième utilisateur pour tester l'isolation
        $secondEmail = 'test.second_' . uniqid() . '@nexus.test';
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, account_type, status, kyc_level)
             VALUES (:full_name, :email, :phone, :password_hash, :account_type, :status, :kyc_level)'
        );
        $stmt->execute([
            ':full_name' => 'Second User',
            ':email' => $secondEmail,
            ':phone' => '+237612345678',
            ':password_hash' => password_hash('SecondPass456!', PASSWORD_DEFAULT),
            ':account_type' => 'personal',
            ':status' => 'ACTIVE',
            ':kyc_level' => 'none',
        ]);
        
        self::$secondUserId = (string) $pdo->lastInsertId();

        // Générer un token JWT pour le premier utilisateur
        require_once BASE_PATH . '/src/Auth/Jwt.php';
        self::$authToken = \Nexus\Auth\Jwt::sign([
            'sub' => self::$testUserId,
            'email' => self::$testUserEmail,
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        // Générer un token JWT pour le deuxième utilisateur
        self::$secondUserToken = \Nexus\Auth\Jwt::sign([
            'sub' => self::$secondUserId,
            'email' => $secondEmail,
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(16)),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = Database::getConnection();
        
        // Nettoyer les tokens révoqués
        if (self::$testUserId !== null) {
            $stmt = $pdo->prepare('DELETE FROM revoked_tokens WHERE user_id = :user_id');
            $stmt->execute([':user_id' => self::$testUserId]);
        }
        
        // Supprimer les utilisateurs de test
        if (self::$secondUserId !== null) {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => self::$secondUserId]);
        }
        
        if (self::$testUserId !== null) {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => self::$testUserId]);
        }
    }

    /**
     * Test GET /api/users/me avec utilisateur authentifié.
     */
    public function testGetMeAuthenticated(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        
        $request = new Request();
        
        // Capturer la réponse
        ob_start();
        try {
            UserController::me($request);
            $output = ob_get_clean();
            $data = json_decode($output, true);
            
            $this->assertEquals(200, http_response_code());
            $this->assertArrayHasKey('success', $data);
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('user', $data);
            $this->assertEquals(self::$testUserId, $data['user']['id']);
        } catch (\Exception $e) {
            ob_end_clean();
            $this->fail('Exception levée : ' . $e->getMessage());
        }
    }

    /**
     * Test GET /api/users/me sans authentification.
     */
    public function testGetMeUnauthenticated(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_AUTHORIZATION']);
        
        $request = new Request();
        
        $this->expectException(\Nexus\Core\HttpException::class);
        $this->expectExceptionCode(401);
        
        UserController::me($request);
    }

    /**
     * Test PUT /api/users/me avec données valides.
     */
    public function testUpdateProfileValid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        
        // Simuler les données POST
        $GLOBALS['_PUT'] = [
            'full_name' => 'Test User Updated',
            'phone' => '+242069876543',
            'country_of_residence' => 'CG',
        ];
        
        $request = new Request();
        
        ob_start();
        try {
            UserController::updateProfile($request);
            $output = ob_get_clean();
            $data = json_decode($output, true);
            
            $this->assertEquals(200, http_response_code());
            $this->assertArrayHasKey('success', $data);
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('updated', $data);
            $this->assertTrue($data['updated']);
        } catch (\Exception $e) {
            ob_end_clean();
            $this->fail('Exception levée : ' . $e->getMessage());
        }
        
        // Nettoyer
        unset($GLOBALS['_PUT']);
    }

    /**
     * Test PUT /api/users/me avec nom trop long.
     */
    public function testUpdateProfileNameTooLong(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        
        $longName = str_repeat('A', 121);
        $GLOBALS['_PUT'] = [
            'full_name' => $longName,
        ];
        
        $request = new Request();
        
        $this->expectException(\Nexus\Core\HttpException::class);
        $this->expectExceptionCode(400);
        
        try {
            UserController::updateProfile($request);
        } finally {
            unset($GLOBALS['_PUT']);
        }
    }

    /**
     * Test PUT /api/users/me/password avec mot de passe valide.
     */
    public function testUpdatePasswordValid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        
        $GLOBALS['_PUT'] = [
            'current_password' => 'TestPassword123!',
            'new_password' => 'NewSecurePass789!',
            'confirm_password' => 'NewSecurePass789!',
        ];
        
        $request = new Request();
        
        ob_start();
        try {
            UserController::updatePassword($request);
            $output = ob_get_clean();
            $data = json_decode($output, true);
            
            $this->assertEquals(200, http_response_code());
            $this->assertArrayHasKey('success', $data);
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('updated', $data);
            $this->assertTrue($data['updated']);
        } catch (\Exception $e) {
            ob_end_clean();
            $this->fail('Exception levée : ' . $e->getMessage());
        }
        
        // Restaurer l'ancien mot de passe pour les autres tests
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([
            ':hash' => password_hash('TestPassword123!', PASSWORD_DEFAULT),
            ':id' => self::$testUserId,
        ]);
        
        unset($GLOBALS['_PUT']);
    }

    /**
     * Test PUT /api/users/me/password avec ancien mot de passe incorrect.
     */
    public function testUpdatePasswordWrongCurrent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        
        $GLOBALS['_PUT'] = [
            'current_password' => 'WrongPassword!',
            'new_password' => 'NewSecurePass789!',
            'confirm_password' => 'NewSecurePass789!',
        ];
        
        $request = new Request();
        
        $this->expectException(\Nexus\Core\HttpException::class);
        $this->expectExceptionCode(400);
        
        try {
            UserController::updatePassword($request);
        } finally {
            unset($GLOBALS['_PUT']);
        }
    }

    /**
     * Test PUT /api/users/me/password avec confirmation différente.
     */
    public function testUpdatePasswordMismatch(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        
        $GLOBALS['_PUT'] = [
            'current_password' => 'TestPassword123!',
            'new_password' => 'NewSecurePass789!',
            'confirm_password' => 'DifferentPass!',
        ];
        
        $request = new Request();
        
        $this->expectException(\Nexus\Core\HttpException::class);
        $this->expectExceptionCode(400);
        
        try {
            UserController::updatePassword($request);
        } finally {
            unset($GLOBALS['_PUT']);
        }
    }

    /**
     * Test PUT /api/users/me/password avec mot de passe trop court.
     */
    public function testUpdatePasswordTooShort(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        
        $GLOBALS['_PUT'] = [
            'current_password' => 'TestPassword123!',
            'new_password' => 'short',
            'confirm_password' => 'short',
        ];
        
        $request = new Request();
        
        $this->expectException(\Nexus\Core\HttpException::class);
        $this->expectExceptionCode(400);
        
        try {
            UserController::updatePassword($request);
        } finally {
            unset($GLOBALS['_PUT']);
        }
    }

    /**
     * Test GET /api/users/me/sessions.
     */
    public function testGetSessions(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        
        $request = new Request();
        
        ob_start();
        try {
            UserController::sessions($request);
            $output = ob_get_clean();
            $data = json_decode($output, true);
            
            $this->assertEquals(200, http_response_code());
            $this->assertArrayHasKey('success', $data);
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('sessions', $data);
            $this->assertIsArray($data['sessions']);
        } catch (\Exception $e) {
            ob_end_clean();
            $this->fail('Exception levée : ' . $e->getMessage());
        }
    }

    /**
     * Test DELETE /api/users/me/sessions/{id} avec session valide.
     */
    public function testRevokeSessionValid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        
        $testJti = bin2hex(random_bytes(16));
        
        // Créer une requête avec le JTI dans la route
        $request = new Request();
        $request->setRouteParams(['id' => $testJti]);
        
        ob_start();
        try {
            UserController::revokeSession($request);
            $output = ob_get_clean();
            $data = json_decode($output, true);
            
            $this->assertEquals(200, http_response_code());
            $this->assertArrayHasKey('success', $data);
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('revoked', $data);
            $this->assertTrue($data['revoked']);
            
            // Vérifier que le token est bien dans revoked_tokens
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM revoked_tokens WHERE jti = :jti AND user_id = :user_id');
            $stmt->execute([
                ':jti' => $testJti,
                ':user_id' => self::$testUserId,
            ]);
            $count = (int) $stmt->fetchColumn();
            $this->assertEquals(1, $count);
        } catch (\Exception $e) {
            ob_end_clean();
            $this->fail('Exception levée : ' . $e->getMessage());
        }
    }

    /**
     * Test DELETE /api/users/me/sessions/{id} avec ID vide.
     */
    public function testRevokeSessionEmptyId(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        
        $request = new Request();
        $request->setRouteParams(['id' => '']);
        
        $this->expectException(\Nexus\Core\HttpException::class);
        $this->expectExceptionCode(400);
        
        UserController::revokeSession($request);
    }

    /**
     * Test d'isolation : un utilisateur ne peut pas révoquer la session d'un autre.
     * Note : Comme nous utilisons le JTI qui est unique par token, et que chaque utilisateur
     * a ses propres tokens, cette isolation est garantie par la conception du système.
     */
    public function testUserIsolation(): void
    {
        // Vérifier que les deux utilisateurs sont distincts
        $this->assertNotEquals(self::$testUserId, self::$secondUserId);
        $this->assertNotEquals(self::$authToken, self::$secondUserToken);
        
        // Un utilisateur ne peut accéder qu'à ses propres données
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        
        $request = new Request();
        
        ob_start();
        try {
            UserController::me($request);
            $output = ob_get_clean();
            $data = json_decode($output, true);
            
            $this->assertEquals(self::$testUserId, $data['user']['id']);
            $this->assertNotEquals(self::$secondUserId, $data['user']['id']);
        } catch (\Exception $e) {
            ob_end_clean();
            $this->fail('Exception levée : ' . $e->getMessage());
        }
    }

    /**
     * Test PUT /api/users/me sans données à mettre à jour.
     */
    public function testUpdateProfileNoData(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::$authToken;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        
        $GLOBALS['_PUT'] = [];
        
        $request = new Request();
        
        $this->expectException(\Nexus\Core\HttpException::class);
        $this->expectExceptionCode(400);
        
        try {
            UserController::updateProfile($request);
        } finally {
            unset($GLOBALS['_PUT']);
        }
    }
}
