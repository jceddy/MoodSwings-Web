<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Auth\AuthService;
use MoodSwings\Auth\DuplicateUsernameException;
use MoodSwings\Auth\InvalidCredentialsException;
use MoodSwings\Repository\SessionRepository;
use MoodSwings\Repository\UserRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class AuthIntegrationTest extends TestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $name = getenv('TEST_DB_NAME') ?: 'moodswings_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('No test MySQL database available: ' . $e->getMessage());
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE sessions');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->auth = new AuthService(new UserRepository(), new SessionRepository());
    }

    public function testRegisterCreatesUser(): void
    {
        $user = $this->auth->register('alice', 'supersecret');

        self::assertSame('alice', $user['username']);
        self::assertArrayHasKey('id', $user);
    }

    public function testRegisterRejectsDuplicateUsername(): void
    {
        $this->auth->register('bob', 'supersecret');

        $this->expectException(DuplicateUsernameException::class);
        $this->auth->register('bob', 'anotherpassword');
    }

    public function testRegisterRejectsShortPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->auth->register('carol', 'short');
    }

    public function testRegisterRejectsInvalidUsername(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->auth->register('a b!', 'supersecret');
    }

    public function testLoginWithValidCredentialsCreatesSession(): void
    {
        $this->auth->register('dave', 'correcthorsebattery');

        $result = $this->auth->login('dave', 'correcthorsebattery', '127.0.0.1', 'phpunit');

        self::assertSame('dave', $result['user']['username']);
        self::assertNotEmpty($result['token']);

        $current = $this->auth->currentUser($result['token']);
        self::assertNotNull($current);
        self::assertSame('dave', $current['user']['username']);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $this->auth->register('erin', 'correcthorsebattery');

        $this->expectException(InvalidCredentialsException::class);
        $this->auth->login('erin', 'wrongpassword', null, null);
    }

    public function testLoginWithUnknownUsernameFails(): void
    {
        $this->expectException(InvalidCredentialsException::class);
        $this->auth->login('nobody', 'whatever', null, null);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $this->auth->register('frank', 'correcthorsebattery');
        $result = $this->auth->login('frank', 'correcthorsebattery', null, null);

        $this->auth->logout($result['token']);

        self::assertNull($this->auth->currentUser($result['token']));
    }

    public function testCurrentUserRejectsUnknownToken(): void
    {
        self::assertNull($this->auth->currentUser(bin2hex(random_bytes(32))));
    }
}
