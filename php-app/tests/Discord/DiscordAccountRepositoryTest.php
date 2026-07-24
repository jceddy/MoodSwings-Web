<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Discord;

use MoodSwings\Repository\DiscordAccountRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DiscordAccountRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DiscordAccountRepository $accounts;

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
        $pdo->exec('TRUNCATE TABLE discord_accounts');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;
        $this->accounts = new DiscordAccountRepository();
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

    public function testUnlinkedUserFindsNothing(): void
    {
        $userId = $this->insertUser('unlinked');

        self::assertNull($this->accounts->findByUserId($userId));
        self::assertNull($this->accounts->findUserIdByDiscordUserId('123456789'));
    }

    public function testLinkAndFindBothDirections(): void
    {
        $userId = $this->insertUser('linked');

        $this->accounts->link($userId, '111222333', 'somebody');

        self::assertSame(
            ['discord_user_id' => '111222333', 'discord_username' => 'somebody'],
            $this->accounts->findByUserId($userId)
        );
        self::assertSame($userId, $this->accounts->findUserIdByDiscordUserId('111222333'));
    }

    public function testRelinkingTheSameUserUpsertsRatherThanFailing(): void
    {
        $userId = $this->insertUser('relink');

        $this->accounts->link($userId, '111', 'old-name');
        $this->accounts->link($userId, '222', 'new-name');

        self::assertSame(
            ['discord_user_id' => '222', 'discord_username' => 'new-name'],
            $this->accounts->findByUserId($userId)
        );
        self::assertNull($this->accounts->findUserIdByDiscordUserId('111'));
        self::assertSame($userId, $this->accounts->findUserIdByDiscordUserId('222'));
    }

    public function testUnlinkRemovesTheLink(): void
    {
        $userId = $this->insertUser('to-unlink');
        $this->accounts->link($userId, '999', 'gone-soon');

        $this->accounts->unlink($userId);

        self::assertNull($this->accounts->findByUserId($userId));
        self::assertNull($this->accounts->findUserIdByDiscordUserId('999'));
    }
}
