<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Auth\AuthService;
use MoodSwings\Auth\DuplicateEmailException;
use MoodSwings\Auth\DuplicateUsernameException;
use MoodSwings\Auth\EmailNotVerifiedException;
use MoodSwings\Auth\InvalidCredentialsException;
use MoodSwings\Auth\InvalidPasswordResetTokenException;
use MoodSwings\Auth\InvalidVerificationTokenException;
use MoodSwings\Repository\EmailVerificationRepository;
use MoodSwings\Repository\PasswordResetRepository;
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
        $pdo->exec('TRUNCATE TABLE password_resets');
        $pdo->exec('TRUNCATE TABLE sessions');
        $pdo->exec('TRUNCATE TABLE friendships');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        // resendMinIntervalSeconds: 0 so tests can resend immediately after
        // registering; the default cooldown is covered by a dedicated test.
        $this->auth = new AuthService(
            new UserRepository(),
            new SessionRepository(),
            new EmailVerificationRepository(),
            new PasswordResetRepository(),
            0
        );
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

    /**
     * Online/presence indicator (issue #110): share_presence defaults to
     * true (shared) for every user, and currentUser()'s own user object
     * reflects a later opt-out immediately -- the User info page reads
     * this to initialize its toggle without a separate fetch.
     */
    public function testCurrentUserSharePresenceDefaultsTrueAndReflectsUpdates(): void
    {
        $registered = $this->registerAndVerify('lucy');
        $result = $this->auth->login('lucy', 'correcthorsebattery', null, null);

        $current = $this->auth->currentUser($result['token']);
        self::assertTrue($current['user']['share_presence']);

        (new UserRepository())->setSharePresence((int) $registered['user']['id'], false);

        $currentAfterOptOut = $this->auth->currentUser($result['token']);
        self::assertFalse($currentAfterOptOut['user']['share_presence']);
    }

    /**
     * "Default selections mode" as a personal preference (Settings
     * dialog's "Game defaults" section) -- defaults to false (unchecked)
     * for every user, and currentUser()'s own user object reflects a
     * later update immediately, same pattern share_presence's own test
     * above covers.
     */
    public function testCurrentUserDefaultSelectionsModePreferenceDefaultsFalseAndReflectsUpdates(): void
    {
        $registered = $this->registerAndVerify('mallory');
        $result = $this->auth->login('mallory', 'correcthorsebattery', null, null);

        $current = $this->auth->currentUser($result['token']);
        self::assertFalse($current['user']['default_selections_mode_preference']);

        (new UserRepository())->setDefaultSelectionsModePreference((int) $registered['user']['id'], true);

        $currentAfterOptIn = $this->auth->currentUser($result['token']);
        self::assertTrue($currentAfterOptIn['user']['default_selections_mode_preference']);
    }

    /**
     * "Board layout" (issue #417) as a personal preference (Settings
     * dialog's "Display" section) -- defaults to 'above_play_area' for
     * every user, and currentUser()'s own user object reflects a later
     * update immediately, same pattern share_presence's/default selections
     * mode's own tests above cover.
     */
    public function testCurrentUserBoardLayoutPreferenceDefaultsAbovePlayAreaAndReflectsUpdates(): void
    {
        $registered = $this->registerAndVerify('nadia');
        $result = $this->auth->login('nadia', 'correcthorsebattery', null, null);

        $current = $this->auth->currentUser($result['token']);
        self::assertSame('above_play_area', $current['user']['board_layout_preference']);

        (new UserRepository())->setBoardLayoutPreference((int) $registered['user']['id'], 'below_hand');

        $currentAfterOptIn = $this->auth->currentUser($result['token']);
        self::assertSame('below_hand', $currentAfterOptIn['user']['board_layout_preference']);
    }

    public function testResendVerificationIssuesNewTokenAndRevokesOld(): void
    {
        $registered = $this->auth->register('henry', 'henry@example.com', 'correcthorsebattery', null);

        $resend = $this->auth->resendVerificationEmail('henry@example.com');

        self::assertNotNull($resend);
        self::assertNotSame($registered['verificationToken'], $resend['verificationToken']);

        $this->expectException(InvalidVerificationTokenException::class);
        $this->auth->verifyEmail($registered['verificationToken']);
    }

    public function testResendVerificationNewTokenVerifiesSuccessfully(): void
    {
        $this->auth->register('iris', 'iris@example.com', 'correcthorsebattery', null);
        $resend = $this->auth->resendVerificationEmail('iris@example.com');

        $user = $this->auth->verifyEmail($resend['verificationToken']);
        self::assertSame('iris', $user['username']);
    }

    public function testResendVerificationForUnknownEmailReturnsNull(): void
    {
        self::assertNull($this->auth->resendVerificationEmail('nobody@example.com'));
    }

    public function testResendVerificationForAlreadyVerifiedUserReturnsNull(): void
    {
        $this->registerAndVerify('jack');

        self::assertNull($this->auth->resendVerificationEmail('jack@example.com'));
    }

    public function testResendVerificationRespectsDefaultCooldown(): void
    {
        // Uses the real default cooldown (unlike $this->auth, which disables
        // it above for test convenience) to confirm production behavior.
        $auth = new AuthService(
            new UserRepository(),
            new SessionRepository(),
            new EmailVerificationRepository(),
            new PasswordResetRepository()
        );
        $auth->register('kevin', 'kevin@example.com', 'correcthorsebattery', null);

        self::assertNull($auth->resendVerificationEmail('kevin@example.com'));
    }

    public function testCancelRegistrationDeletesUser(): void
    {
        $result = $this->auth->register('gina', 'gina@example.com', 'supersecret', null);

        $this->auth->cancelRegistration((int) $result['user']['id']);

        // The username and email are free again after cancellation.
        $reregistered = $this->auth->register('gina', 'gina@example.com', 'anotherpassword', null);
        self::assertSame('gina', $reregistered['user']['username']);
    }

    public function testRequestPasswordResetSendsTokenForKnownEmail(): void
    {
        $this->registerAndVerify('molly');

        $result = $this->auth->requestPasswordReset('molly@example.com');

        self::assertNotNull($result);
        self::assertSame('molly', $result['user']['username']);
        self::assertNotEmpty($result['resetToken']);
    }

    public function testRequestPasswordResetWorksForUnverifiedUser(): void
    {
        $this->auth->register('nora', 'nora@example.com', 'correcthorsebattery', null);

        self::assertNotNull($this->auth->requestPasswordReset('nora@example.com'));
    }

    public function testRequestPasswordResetReturnsNullForUnknownEmail(): void
    {
        self::assertNull($this->auth->requestPasswordReset('nobody@example.com'));
    }

    public function testRequestPasswordResetRespectsDefaultCooldown(): void
    {
        // Uses the real default cooldown (unlike $this->auth, which disables
        // it above for test convenience) to confirm production behavior.
        $auth = new AuthService(
            new UserRepository(),
            new SessionRepository(),
            new EmailVerificationRepository(),
            new PasswordResetRepository()
        );
        $auth->register('oscar', 'oscar@example.com', 'correcthorsebattery', null);

        self::assertNotNull($auth->requestPasswordReset('oscar@example.com'));
        self::assertNull($auth->requestPasswordReset('oscar@example.com'));
    }

    public function testResetPasswordUpdatesHashAndConsumesToken(): void
    {
        $this->registerAndVerify('penny');
        $reset = $this->auth->requestPasswordReset('penny@example.com');

        $user = $this->auth->resetPassword($reset['resetToken'], 'brandnewpassword');
        self::assertSame('penny', $user['username']);

        $login = $this->auth->login('penny', 'brandnewpassword', null, null);
        self::assertSame('penny', $login['user']['username']);

        $this->expectException(InvalidPasswordResetTokenException::class);
        $this->auth->resetPassword($reset['resetToken'], 'anotherpassword');
    }

    public function testResetPasswordRejectsInvalidToken(): void
    {
        $this->expectException(InvalidPasswordResetTokenException::class);
        $this->auth->resetPassword(bin2hex(random_bytes(32)), 'brandnewpassword');
    }

    public function testResetPasswordRejectsShortPassword(): void
    {
        $this->registerAndVerify('quinn');
        $reset = $this->auth->requestPasswordReset('quinn@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->auth->resetPassword($reset['resetToken'], 'short');
    }

    public function testResetPasswordInvalidatesExistingSessions(): void
    {
        $this->registerAndVerify('rachel');
        $login = $this->auth->login('rachel', 'correcthorsebattery', null, null);
        self::assertNotNull($this->auth->currentUser($login['token']));

        $reset = $this->auth->requestPasswordReset('rachel@example.com');
        $this->auth->resetPassword($reset['resetToken'], 'brandnewpassword');

        self::assertNull($this->auth->currentUser($login['token']));
    }
}
