<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Presence\PresenceService;
use MoodSwings\Repository\SessionRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PresenceServiceTest extends TestCase
{
    private PDO $pdo;
    private PresenceService $presence;

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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
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

        $this->pdo = $pdo;
        $this->presence = new PresenceService(new SessionRepository());
    }

    private function insertUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at)
             VALUES (:username, :email, 'hash', NOW())"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com"]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertSession(int $userId, string $lastSeenAt, string $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (user_id, token_hash, last_seen_at, expires_at)
             VALUES (:user_id, :token_hash, :last_seen_at, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => bin2hex(random_bytes(32)),
            'last_seen_at' => $lastSeenAt,
            'expires_at' => $expiresAt,
        ]);
    }

    public function testUserWithRecentSessionIsOnline(): void
    {
        $userId = $this->insertUser('alice');
        $this->insertSession($userId, date('Y-m-d H:i:s', time() - 5), date('Y-m-d H:i:s', time() + 86400));

        $statuses = $this->presence->statusesFor([$userId => true]);

        self::assertSame('online', $statuses[$userId]);
    }

    public function testUserWithStaleSessionIsOffline(): void
    {
        $userId = $this->insertUser('bob');
        // Well past PresenceService::ONLINE_THRESHOLD_SECONDS (120).
        $this->insertSession($userId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 86400));

        $statuses = $this->presence->statusesFor([$userId => true]);

        self::assertSame('offline', $statuses[$userId]);
    }

    public function testUserWithNoSessionAtAllIsOffline(): void
    {
        $userId = $this->insertUser('carol');

        $statuses = $this->presence->statusesFor([$userId => true]);

        self::assertSame('offline', $statuses[$userId]);
    }

    public function testExpiredSessionDoesNotCountAsOnlineEvenWithRecentLastSeenAt(): void
    {
        $userId = $this->insertUser('dave');
        // last_seen_at is recent, but the session itself already expired --
        // e.g. an old device logged out/expired shortly after its last
        // real request. Only currently-valid sessions should count.
        $this->insertSession($userId, date('Y-m-d H:i:s', time() - 5), date('Y-m-d H:i:s', time() - 1));

        $statuses = $this->presence->statusesFor([$userId => true]);

        self::assertSame('offline', $statuses[$userId]);
    }

    public function testMultipleSessionsUseTheMostRecentLastSeenAt(): void
    {
        $userId = $this->insertUser('erin');
        // An old, stale session plus a fresh one on a second device --
        // MAX(last_seen_at) across both should win, reading as online.
        $this->insertSession($userId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 86400));
        $this->insertSession($userId, date('Y-m-d H:i:s', time() - 5), date('Y-m-d H:i:s', time() + 86400));

        $statuses = $this->presence->statusesFor([$userId => true]);

        self::assertSame('online', $statuses[$userId]);
    }

    public function testOptedOutUserIsHiddenRegardlessOfSessionActivity(): void
    {
        $userId = $this->insertUser('frank');
        $this->insertSession($userId, date('Y-m-d H:i:s', time() - 5), date('Y-m-d H:i:s', time() + 86400));

        $statuses = $this->presence->statusesFor([$userId => false]);

        self::assertSame('hidden', $statuses[$userId]);
    }

    public function testStatusesForHandlesMultipleUsersInOneCall(): void
    {
        $onlineUserId = $this->insertUser('gina');
        $this->insertSession($onlineUserId, date('Y-m-d H:i:s', time() - 5), date('Y-m-d H:i:s', time() + 86400));

        $offlineUserId = $this->insertUser('henry');

        $hiddenUserId = $this->insertUser('iris');

        $statuses = $this->presence->statusesFor([
            $onlineUserId => true,
            $offlineUserId => true,
            $hiddenUserId => false,
        ]);

        self::assertSame('online', $statuses[$onlineUserId]);
        self::assertSame('offline', $statuses[$offlineUserId]);
        self::assertSame('hidden', $statuses[$hiddenUserId]);
    }
}
