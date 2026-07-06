<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Auth\AuthService;
use MoodSwings\Auth\DuplicateEmailException;
use MoodSwings\Auth\DuplicateUsernameException;
use MoodSwings\Auth\EmailNotVerifiedException;
use MoodSwings\Auth\InvalidCredentialsException;
use MoodSwings\Auth\InvalidVerificationTokenException;
use MoodSwings\Repository\EmailVerificationRepository;
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
        $pdo->exec('TRUNCATE TABLE email_verifications');
        $pdo->exec('TRUNCATE TABLE sessions');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->auth = new AuthService(new UserRepository(), new SessionRepository(), new EmailVerificationRepository());
    }

    /**
     * Registers and immediately verifies a user, returning the registration result.
     */
    private function registerAndVerify(string $username, string $password = 'correcthorsebattery', ?string $phoneNumber = null): array
    {
        $result = $this->auth->register($username, "{$username}@example.com", $password, $phoneNumber);
        $this->auth->verifyEmail($result['verificationToken']);

        return $result;
    }

    public function testRegisterCreatesUnverifiedUser(): void
    {
        $result = $this->auth->register('alice', 'alice@example.com', 'supersecret', null);

        self::assertSame('alice', $result['user']['username']);
        self::assertSame('alice@example.com', $result['user']['email']);
        self::assertNull($result['user']['email_verified_at']);
        self::assertNotEmpty($result['verificationToken']);
    }

    public function testRegisterAcceptsOptionalPhoneNumber(): void
    {
        $result = $this->auth->register('alicep', 'alicep@example.com', 'supersecret', '+1 (555) 123-4567');

        self::assertSame('+1 (555) 123-4567', $result['user']['phone_number']);
    }

    public function testRegisterRejectsDuplicateUsername(): void
    {
        $this->auth->register('bob', 'bob@example.com', 'supersecret', null);

        $this->expectException(DuplicateUsernameException::class);
        $this->auth->register('bob', 'bob2@example.com', 'anotherpassword', null);
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $this->auth->register('bob2', 'shared@example.com', 'supersecret', null);

        $this->expectException(DuplicateEmailException::class);
        $this->auth->register('bob3', 'shared@example.com', 'anotherpassword', null);
    }

    public function testRegisterRejectsShortPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->auth->register('carol', 'carol@example.com', 'short', null);
    }

    public function testRegisterRejectsInvalidUsername(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->auth->register('a b!', 'carol2@example.com', 'supersecret', null);
    }

    public function testRegisterRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->auth->register('carol3', 'not-an-email', 'supersecret', null);
    }

    public function testRegisterRejectsInvalidPhoneNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->auth->register('carol4', 'carol4@example.com', 'supersecret', 'not a phone number!!');
    }

    public function testLoginBeforeVerificationFails(): void
    {
        $this->auth->register('unverified', 'unverified@example.com', 'correcthorsebattery', null);

        $this->expectException(EmailNotVerifiedException::class);
        $this->auth->login('unverified', 'correcthorsebattery', null, null);
    }

    public function testVerifyEmailWithInvalidTokenFails(): void
    {
        $this->expectException(InvalidVerificationTokenException::class);
        $this->auth->verifyEmail(bin2hex(random_bytes(32)));
    }

    public function testLoginWithValidCredentialsCreatesSessionAfterVerification(): void
    {
        $this->registerAndVerify('dave');

        $result = $this->auth->login('dave', 'correcthorsebattery', '127.0.0.1', 'phpunit');

        self::assertSame('dave', $result['user']['username']);
        self::assertNotEmpty($result['token']);

        $current = $this->auth->currentUser($result['token']);
        self::assertNotNull($current);
        self::assertSame('dave', $current['user']['username']);
        self::assertSame('dave@example.com', $current['user']['email']);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $this->registerAndVerify('erin');

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
        $this->registerAndVerify('frank');
        $result = $this->auth->login('frank', 'correcthorsebattery', null, null);

        $this->auth->logout($result['token']);

        self::assertNull($this->auth->currentUser($result['token']));
    }

    public function testCurrentUserRejectsUnknownToken(): void
    {
        self::assertNull($this->auth->currentUser(bin2hex(random_bytes(32))));
    }

    public function testCancelRegistrationDeletesUser(): void
    {
        $result = $this->auth->register('gina', 'gina@example.com', 'supersecret', null);

        $this->auth->cancelRegistration((int) $result['user']['id']);

        // The username and email are free again after cancellation.
        $reregistered = $this->auth->register('gina', 'gina@example.com', 'anotherpassword', null);
        self::assertSame('gina', $reregistered['user']['username']);
    }
}
