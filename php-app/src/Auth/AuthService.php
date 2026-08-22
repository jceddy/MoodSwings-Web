<?php

declare(strict_types=1);

namespace MoodSwings\Auth;

use DateTimeImmutable;
use MoodSwings\Repository\EmailVerificationRepository;
use MoodSwings\Repository\PasswordResetRepository;
use MoodSwings\Repository\SessionRepository;
use MoodSwings\Repository\UserRepository;
use PDOException;

final class AuthService
{
    public const COOKIE_NAME = 'session_token';
    public const SESSION_TTL_DAYS = 30;
    public const EMAIL_VERIFICATION_TTL_HOURS = 24;
    public const RESEND_MIN_INTERVAL_SECONDS = 60;
    public const PASSWORD_RESET_TTL_HOURS = 1;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly EmailVerificationRepository $emailVerifications,
        private readonly PasswordResetRepository $passwordResets,
        private readonly int $resendMinIntervalSeconds = self::RESEND_MIN_INTERVAL_SECONDS,
    ) {
    }

    /**
     * @return array{user: array, verificationToken: string}
     */
    public function register(string $username, string $email, string $password, ?string $phoneNumber): array
    {
        $username = trim($username);
        $email = trim($email);
        $phoneNumber = $phoneNumber !== null ? trim($phoneNumber) : null;
        if ($phoneNumber === '') {
            $phoneNumber = null;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $username)) {
            throw new \InvalidArgumentException(
                'Username must be 3-32 characters (letters, numbers, "_", "-").'
            );
        }

        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new \InvalidArgumentException('Password must be between 8 and 72 characters.');
        }

        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }

        if ($phoneNumber !== null && !preg_match('/^[0-9+()\-.\s]{7,20}$/', $phoneNumber)) {
            throw new \InvalidArgumentException('Phone number format is invalid.');
        }

        if ($this->users->findByUsername($username) !== null) {
            throw new DuplicateUsernameException("Username \"{$username}\" is already taken.");
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new DuplicateEmailException("An account with email \"{$email}\" already exists.");
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $user = $this->users->create($username, $email, $hash, $phoneNumber);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DuplicateUsernameException("Username \"{$username}\" or email \"{$email}\" is already taken.");
            }
            throw $e;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::EMAIL_VERIFICATION_TTL_HOURS . ' hours');
        $this->emailVerifications->create((int) $user['id'], hash('sha256', $token), $expiresAt);

        return ['user' => $user, 'verificationToken' => $token];
    }

    /**
     * Rolls back a registration whose verification email failed to send, so
     * the user isn't left with an unusable, unverifiable account.
     */
    public function cancelRegistration(int $userId): void
    {
        $this->users->delete($userId);
    }

    /**
     * Issues a fresh verification token for an unverified account, invalidating
     * any prior ones. Returns null when there's nothing to do (unknown email,
     * already verified, or a resend was requested too recently) so the caller
     * can respond identically in every case and avoid leaking account state.
     *
     * @return array{user: array, verificationToken: string}|null
     */
    public function resendVerificationEmail(string $email): ?array
    {
        $user = $this->users->findByEmail(trim($email));

        if ($user === null || $user['email_verified_at'] !== null) {
            return null;
        }

        $userId = (int) $user['id'];
        $lastSentAt = $this->emailVerifications->mostRecentCreatedAtForUser($userId);

        if ($lastSentAt !== null && (time() - $lastSentAt->getTimestamp()) < $this->resendMinIntervalSeconds) {
            return null;
        }

        $this->emailVerifications->deleteAllForUser($userId);

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::EMAIL_VERIFICATION_TTL_HOURS . ' hours');
        $this->emailVerifications->create($userId, hash('sha256', $token), $expiresAt);

        return ['user' => $user, 'verificationToken' => $token];
    }

    public function verifyEmail(string $token): array
    {
        $verification = $this->emailVerifications->findValidByTokenHash(hash('sha256', $token));

        if ($verification === null) {
            throw new InvalidVerificationTokenException('This verification link is invalid or has expired.');
        }

        $userId = (int) $verification['user_id'];
        $this->users->markEmailVerified($userId);
        $this->emailVerifications->deleteAllForUser($userId);

        return $this->users->findById($userId);
    }

    /**
     * Issues a password reset token for any known email, verified or not,
     * invalidating any prior ones. Returns null when there's nothing to do
     * (unknown email, or a request was made too recently) so the caller can
     * respond identically in every case and avoid leaking account state.
     *
     * @return array{user: array, resetToken: string}|null
     */
    public function requestPasswordReset(string $email): ?array
    {
        $user = $this->users->findByEmail(trim($email));

        if ($user === null) {
            return null;
        }

        $userId = (int) $user['id'];
        $lastSentAt = $this->passwordResets->mostRecentCreatedAtForUser($userId);

        if ($lastSentAt !== null && (time() - $lastSentAt->getTimestamp()) < $this->resendMinIntervalSeconds) {
            return null;
        }

        $this->passwordResets->deleteAllForUser($userId);

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::PASSWORD_RESET_TTL_HOURS . ' hours');
        $this->passwordResets->create($userId, hash('sha256', $token), $expiresAt);

        return ['user' => $user, 'resetToken' => $token];
    }

    /**
     * Consumes a password reset token, sets the new password, and logs the
     * user out everywhere by deleting all of their sessions -- a password
     * reset is also a signal that any existing session may be compromised.
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            throw new \InvalidArgumentException('Password must be between 8 and 72 characters.');
        }

        $userId = $this->passwordResets->consumeValid(hash('sha256', $token));

        if ($userId === null) {
            throw new InvalidPasswordResetTokenException('This password reset link is invalid or has expired.');
        }

        $this->users->updatePasswordHash($userId, password_hash($newPassword, PASSWORD_BCRYPT));
        $this->sessions->deleteAllForUser($userId);

        return $this->users->findById($userId);
    }

    /**
     * @return array{user: array, token: string, expiresAt: DateTimeImmutable}
     */
    public function login(string $username, string $password, ?string $ipAddress, ?string $userAgent): array
    {
        $user = $this->users->findByUsername($username);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw new InvalidCredentialsException('Invalid username or password.');
        }

        if ($user['email_verified_at'] === null) {
            throw new EmailNotVerifiedException('Please verify your email address before logging in.');
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::SESSION_TTL_DAYS . ' days');

        $this->sessions->create((int) $user['id'], hash('sha256', $token), $expiresAt, $ipAddress, $userAgent);

        return ['user' => $user, 'token' => $token, 'expiresAt' => $expiresAt];
    }

    public function logout(string $token): void
    {
        $this->sessions->deleteByTokenHash(hash('sha256', $token));
    }

    /**
     * @return array{user: array{id: int, username: string, email: string, phone_number: ?string, share_presence: bool, default_selections_mode_preference: bool, auto_pass_on_empty_hand: bool, auto_apply_scoring_bonuses: bool}, expiresAt: DateTimeImmutable}|null
     */
    public function currentUser(string $token): ?array
    {
        $session = $this->sessions->findValidByTokenHash(hash('sha256', $token));

        if ($session === null) {
            return null;
        }

        $expiresAt = new DateTimeImmutable('+' . self::SESSION_TTL_DAYS . ' days');
        $this->sessions->touch((int) $session['id'], $expiresAt);

        return [
            'user' => [
                'id' => (int) $session['user_id'],
                'username' => $session['username'],
                'email' => $session['email'],
                'phone_number' => $session['phone_number'],
                // Online/presence indicator (issue #110) -- this user's
                // own current opt-in/out of sharing their presence with
                // others; the User info page reads this to initialize its
                // toggle. See PresenceService for how a shared status is
                // actually computed for someone else's view.
                'share_presence' => (bool) $session['share_presence'],
                // "Default selections mode" as a personal preference
                // (Settings dialog's "Game defaults" section) -- this
                // user's own default for the New Game dialog's
                // default-selections-mode checkbox, distinct from
                // games.default_selections_mode (issue #274, the actual
                // per-game setting). See UserRepository::
                // setDefaultSelectionsModePreference().
                'default_selections_mode_preference' => (bool) $session['default_selections_mode_preference'],
                // "Auto-pass on empty hand" as a personal preference
                // (Settings dialog's "Game defaults" section) -- drives
                // GameService::advanceAutomatedTurns()'s own server-side
                // auto-pass for this user whenever it's their turn and
                // their hand is empty. See UserRepository::
                // setAutoPassOnEmptyHand().
                'auto_pass_on_empty_hand' => (bool) $session['auto_pass_on_empty_hand'],
                // "Auto-apply scoring bonuses" (issue #397) as a personal
                // preference (Settings dialog's "Game defaults" section)
                // -- drives GameService::advanceAutomatedTurns()'s own
                // server-side auto-apply of Enthusiasm's/Passion's
                // per-round scoring decision for this user, whenever the
                // obviously-correct answer is safe to apply (see
                // sneakinessPlayedThisRound()). See UserRepository::
                // setAutoApplyScoringBonuses().
                'auto_apply_scoring_bonuses' => (bool) $session['auto_apply_scoring_bonuses'],
            ],
            'expiresAt' => $expiresAt,
        ];
    }
}
