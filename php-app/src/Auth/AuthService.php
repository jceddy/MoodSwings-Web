<?php

declare(strict_types=1);

namespace MoodSwings\Auth;

use DateTimeImmutable;
use MoodSwings\Repository\EmailVerificationRepository;
use MoodSwings\Repository\SessionRepository;
use MoodSwings\Repository\UserRepository;
use PDOException;

final class AuthService
{
    public const COOKIE_NAME = 'session_token';
    public const SESSION_TTL_DAYS = 30;
    public const EMAIL_VERIFICATION_TTL_HOURS = 24;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly EmailVerificationRepository $emailVerifications,
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
     * @return array{user: array{id: int, username: string, email: string, phone_number: ?string}, expiresAt: DateTimeImmutable}|null
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
            ],
            'expiresAt' => $expiresAt,
        ];
    }
}
