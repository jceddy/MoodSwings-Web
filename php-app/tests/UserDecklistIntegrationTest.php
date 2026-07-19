<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Deck\DecklistNotFoundException;
use MoodSwings\Deck\DecklistValidationException;
use MoodSwings\Deck\NotAuthorizedToAccessDecklistException;
use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class UserDecklistIntegrationTest extends TestCase
{
    private PDO $pdo;
    private UserDecklistService $decklists;
    private FriendshipService $friendships;

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
        $pdo->exec('TRUNCATE TABLE user_decklists');
        $pdo->exec('TRUNCATE TABLE friendships');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;
        $this->friendships = new FriendshipService(new UserRepository(), new FriendshipRepository());
        $this->decklists = new UserDecklistService(new UserDecklistRepository(), $this->friendships);
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

    private function befriend(int $userIdA, int $userIdB): void
    {
        [$low, $high] = $userIdA < $userIdB ? [$userIdA, $userIdB] : [$userIdB, $userIdA];
        $this->pdo->prepare(
            "INSERT INTO friendships (user_low_id, user_high_id, status, action_user_id) VALUES (:low, :high, 'accepted', :low)"
        )->execute(['low' => $low, 'high' => $high]);
    }

    public function testCreateFromTextParsesAndStores(): void
    {
        $userId = $this->insertUser('alice');

        $id = $this->decklists->create($userId, 'My Deck', "1 Charity\n1 Dignity", null, null, 'private');

        $view = $this->decklists->view($userId, $id);
        self::assertSame('My Deck', $view['name']);
        self::assertCount(2, $view['cards']);
    }

    public function testCreateFromCardIdsStoresDirectly(): void
    {
        $userId = $this->insertUser('alice');

        $id = $this->decklists->create($userId, 'My Deck', null, [3, 4], null, 'private');

        $view = $this->decklists->view($userId, $id);
        self::assertCount(2, $view['cards']);
        self::assertSame([], $view['sideboard_cards']);
    }

    public function testCreateWithSideboardCardIds(): void
    {
        $userId = $this->insertUser('alice');

        $id = $this->decklists->create($userId, 'My Deck', null, [3, 4], [5], 'private');

        $view = $this->decklists->view($userId, $id);
        self::assertCount(2, $view['cards']);
        self::assertCount(1, $view['sideboard_cards']);
    }

    public function testCreateRejectsUnknownCardId(): void
    {
        $userId = $this->insertUser('alice');

        $this->expectException(DecklistValidationException::class);
        $this->decklists->create($userId, 'My Deck', null, [999999], null, 'private');
    }

    public function testCreateRejectsEmptyName(): void
    {
        $userId = $this->insertUser('alice');

        $this->expectException(DecklistValidationException::class);
        $this->decklists->create($userId, '   ', null, [3], null, 'private');
    }

    public function testCreateRejectsInvalidVisibility(): void
    {
        $userId = $this->insertUser('alice');

        $this->expectException(\InvalidArgumentException::class);
        $this->decklists->create($userId, 'My Deck', null, [3], null, 'public');
    }

    public function testUpdateChangesNameCardsAndVisibility(): void
    {
        $userId = $this->insertUser('alice');
        $id = $this->decklists->create($userId, 'Old Name', null, [3], null, 'private');

        $this->decklists->update($userId, $id, 'New Name', null, [3, 4, 5], null, 'friends');

        $view = $this->decklists->view($userId, $id);
        self::assertSame('New Name', $view['name']);
        self::assertCount(3, $view['cards']);
        self::assertSame('friends', $view['visibility']);
    }

    public function testUpdateRejectsNonOwner(): void
    {
        $ownerId = $this->insertUser('alice');
        $otherId = $this->insertUser('bob');
        $id = $this->decklists->create($ownerId, 'My Deck', null, [3], null, 'private');

        $this->expectException(NotAuthorizedToAccessDecklistException::class);
        $this->decklists->update($otherId, $id, 'Hijacked', null, [3], null, 'private');
    }

    public function testUpdateRejectsUnknownId(): void
    {
        $userId = $this->insertUser('alice');

        $this->expectException(DecklistNotFoundException::class);
        $this->decklists->update($userId, 999999, 'My Deck', null, [3], null, 'private');
    }

    public function testDeleteRemovesRow(): void
    {
        $userId = $this->insertUser('alice');
        $id = $this->decklists->create($userId, 'My Deck', null, [3], null, 'private');

        $this->decklists->delete($userId, $id);

        $this->expectException(DecklistNotFoundException::class);
        $this->decklists->view($userId, $id);
    }

    public function testDeleteRejectsNonOwner(): void
    {
        $ownerId = $this->insertUser('alice');
        $otherId = $this->insertUser('bob');
        $id = $this->decklists->create($ownerId, 'My Deck', null, [3], null, 'private');

        $this->expectException(NotAuthorizedToAccessDecklistException::class);
        $this->decklists->delete($otherId, $id);
    }

    public function testListForViewerReturnsOwnDecks(): void
    {
        $userId = $this->insertUser('alice');
        $this->decklists->create($userId, 'Deck One', null, [3], null, 'private');
        $this->decklists->create($userId, 'Deck Two', null, [4], null, 'friends');

        $result = $this->decklists->listForViewer($userId);

        self::assertCount(2, $result['own']);
        self::assertSame([], $result['friends']);
    }

    public function testListForViewerOmitsPrivateDecksFromFriends(): void
    {
        $ownerId = $this->insertUser('alice');
        $viewerId = $this->insertUser('bob');
        $this->befriend($ownerId, $viewerId);
        $this->decklists->create($ownerId, 'Private Deck', null, [3], null, 'private');

        $result = $this->decklists->listForViewer($viewerId);

        self::assertSame([], $result['friends']);
    }

    public function testListForViewerIncludesFriendsVisibleDecksGroupedByFriend(): void
    {
        $ownerId = $this->insertUser('alice');
        $viewerId = $this->insertUser('bob');
        $this->befriend($ownerId, $viewerId);
        $this->decklists->create($ownerId, 'Shared Deck', null, [3], null, 'friends');

        $result = $this->decklists->listForViewer($viewerId);

        self::assertCount(1, $result['friends']);
        self::assertSame('alice', $result['friends'][0]['friend_username']);
        self::assertCount(1, $result['friends'][0]['decklists']);
    }

    public function testListForViewerOmitsFriendsWithNoSharedDecks(): void
    {
        $ownerId = $this->insertUser('alice');
        $viewerId = $this->insertUser('bob');
        $this->befriend($ownerId, $viewerId);
        $this->decklists->create($ownerId, 'Private Deck', null, [3], null, 'private');

        $result = $this->decklists->listForViewer($viewerId);

        self::assertSame([], $result['friends']);
    }

    public function testViewAllowsOwner(): void
    {
        $userId = $this->insertUser('alice');
        $id = $this->decklists->create($userId, 'My Deck', null, [3], null, 'private');

        $view = $this->decklists->view($userId, $id);
        self::assertSame('My Deck', $view['name']);
    }

    public function testViewAllowsAcceptedFriendOfAFriendsVisibleDeck(): void
    {
        $ownerId = $this->insertUser('alice');
        $friendId = $this->insertUser('bob');
        $this->befriend($ownerId, $friendId);
        $id = $this->decklists->create($ownerId, 'Shared Deck', null, [3], null, 'friends');

        $view = $this->decklists->view($friendId, $id);
        self::assertSame('Shared Deck', $view['name']);
    }

    public function testViewRejectsNonFriendOfAFriendsVisibleDeck(): void
    {
        $ownerId = $this->insertUser('alice');
        $strangerId = $this->insertUser('carol');
        $id = $this->decklists->create($ownerId, 'Shared Deck', null, [3], null, 'friends');

        $this->expectException(NotAuthorizedToAccessDecklistException::class);
        $this->decklists->view($strangerId, $id);
    }

    public function testViewRejectsPrivateDeckFromAnyoneButTheOwner(): void
    {
        $ownerId = $this->insertUser('alice');
        $friendId = $this->insertUser('bob');
        $this->befriend($ownerId, $friendId);
        $id = $this->decklists->create($ownerId, 'Private Deck', null, [3], null, 'private');

        $this->expectException(NotAuthorizedToAccessDecklistException::class);
        $this->decklists->view($friendId, $id);
    }

    public function testCardIdsForUseOmitsSideboard(): void
    {
        $userId = $this->insertUser('alice');
        $id = $this->decklists->create($userId, 'My Deck', null, [3, 4], [5], 'private');

        $result = $this->decklists->cardIdsForUse($userId, $id);

        self::assertSame([3, 4], $result['cardIds']);
        self::assertArrayNotHasKey('sideboardCardIds', $result);
    }
}
