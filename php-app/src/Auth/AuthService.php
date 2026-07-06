<?php

declare(strict_types=1);

namespace MoodSwings\Auth;

use DateTimeImmutable;
use MoodSwings\Repository\SessionRepository;
use MoodSwings\Repository\UserRepository;
use PDOException;

final class AuthService
{
    public const COOKIE_NAME = 'session_token';
    public const SESSION_TTL_DAYS = 30;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
    ) {
    }

    public function register(string $username, string $password): array
    {
        $username = trim($username);

        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $username)) {
            throw new \InvalidArgumentException(
                'Username must be 3-32 characters (letters, numbers, "_", "-").'
            );
        }

        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new \InvalidArgumentException('Password must be between 8 and 72 characters.');
        }

        if ($this->users->findByUsername($username) !== null) {
            throw new DuplicateUsernameException("Username \"{$username}\" is already taken.");
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        try {
            return $this->users->create($username, $hash);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DuplicateUsernameException("Username \"{$username}\" is already taken.");
            }
            throw $e;
        }
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
     * @return array{user: array{id: int, username: string}, expiresAt: DateTimeImmutable}|null
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
            'user' => ['id' => (int) $session['user_id'], 'username' => $session['username']],
            'expiresAt' => $expiresAt,
        ];
    }
}
