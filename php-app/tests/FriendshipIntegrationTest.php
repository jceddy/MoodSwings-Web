<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Auth\AuthService;
use MoodSwings\Friends\CannotFriendSelfException;
use MoodSwings\Friends\FriendshipAlreadyExistsException;
use MoodSwings\Friends\FriendshipNotFoundException;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Friends\NotAuthorizedToRespondException;
use MoodSwings\Friends\UserNotFoundException;
use MoodSwings\Repository\EmailVerificationRepository;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\SessionRepository;
use MoodSwings\Repository\UserRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class FriendshipIntegrationTest extends TestCase
{
    private FriendshipService $friendships;
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
        $pdo->exec('TRUNCATE TABLE friendships');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->auth = new AuthService(new UserRepository(), new SessionRepository(), new EmailVerificationRepository(), 0);
        $this->friendships = new FriendshipService(new UserRepository(), new FriendshipRepository());
    }

    private function createUser(string $username): int
    {
        $result = $this->auth->register($username, "{$username}@example.com", 'correcthorsebattery', null);

        return (int) $result['user']['id'];
    }

    public function testSendInviteByUsername(): void
    {
        $aliceId = $this->createUser('alice');
        $this->createUser('bob');

        $target = $this->friendships->sendInvite($aliceId, 'bob');

        self::assertSame('bob', $target['username']);
    }

    public function testSendInviteByEmail(): void
    {
        $aliceId = $this->createUser('alice');
        $this->createUser('bob');

        $target = $this->friendships->sendInvite($aliceId, 'bob@example.com');

        self::assertSame('bob', $target['username']);
    }

    public function testSendInviteToUnknownUserFails(): void
    {
        $aliceId = $this->createUser('alice');

        $this->expectException(UserNotFoundException::class);
        $this->friendships->sendInvite($aliceId, 'nobody');
    }

    public function testSendInviteToSelfFails(): void
    {
        $aliceId = $this->createUser('alice');

        $this->expectException(CannotFriendSelfException::class);
        $this->friendships->sendInvite($aliceId, 'alice');
    }

    public function testDuplicatePendingInviteFails(): void
    {
        $aliceId = $this->createUser('alice');
        $this->createUser('bob');

        $this->friendships->sendInvite($aliceId, 'bob');

        $this->expectException(FriendshipAlreadyExistsException::class);
        $this->friendships->sendInvite($aliceId, 'bob');
    }

    public function testAcceptCreatesMutualFriendship(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->friendships->sendInvite($aliceId, 'bob');
        $this->friendships->respondToInvite($bobId, $aliceId, 'accept');

        $aliceFriends = $this->friendships->listFriends($aliceId);
        $bobFriends = $this->friendships->listFriends($bobId);

        self::assertCount(1, $aliceFriends);
        self::assertSame('bob', $aliceFriends[0]['friend_username']);
        self::assertCount(1, $bobFriends);
        self::assertSame('alice', $bobFriends[0]['friend_username']);
    }

    public function testRemoveFriendDeletesAcceptedFriendshipForBoth(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->friendships->sendInvite($aliceId, 'bob');
        $this->friendships->respondToInvite($bobId, $aliceId, 'accept');

        $this->friendships->removeFriend($bobId, $aliceId);

        self::assertCount(0, $this->friendships->listFriends($aliceId));
        self::assertCount(0, $this->friendships->listFriends($bobId));

        // Not punitive either -- a new invite should be allowed afterward.
        $target = $this->friendships->sendInvite($aliceId, 'bob');
        self::assertSame('bob', $target['username']);
    }

    public function testRemoveFriendFailsWhenNotFriends(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->expectException(FriendshipNotFoundException::class);
        $this->friendships->removeFriend($aliceId, $bobId);
    }

    public function testRemoveFriendFailsForPendingInviteNotYetAccepted(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->friendships->sendInvite($aliceId, 'bob');

        $this->expectException(FriendshipNotFoundException::class);
        $this->friendships->removeFriend($aliceId, $bobId);
    }

    public function testDeclineRemovesTheInviteAndAllowsReinviting(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->friendships->sendInvite($aliceId, 'bob');
        $this->friendships->respondToInvite($bobId, $aliceId, 'decline');

        self::assertCount(0, $this->friendships->listFriends($aliceId));
        self::assertCount(0, $this->friendships->listIncomingInvites($bobId));

        // Declining isn't punitive -- a new invite should be allowed.
        $target = $this->friendships->sendInvite($aliceId, 'bob');
        self::assertSame('bob', $target['username']);
    }

    public function testBlockPreventsFutureInvitesFromThatUser(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->friendships->sendInvite($aliceId, 'bob');
        $this->friendships->respondToInvite($bobId, $aliceId, 'block');

        $this->expectException(FriendshipAlreadyExistsException::class);
        $this->friendships->sendInvite($aliceId, 'bob');
    }

    public function testSenderCannotRespondToTheirOwnInvite(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->friendships->sendInvite($aliceId, 'bob');

        $this->expectException(NotAuthorizedToRespondException::class);
        $this->friendships->respondToInvite($aliceId, $bobId, 'accept');
    }

    public function testRespondingToNonexistentInviteFails(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->expectException(FriendshipNotFoundException::class);
        $this->friendships->respondToInvite($bobId, $aliceId, 'accept');
    }

    public function testInvalidActionFails(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->friendships->sendInvite($aliceId, 'bob');

        $this->expectException(\InvalidArgumentException::class);
        $this->friendships->respondToInvite($bobId, $aliceId, 'not-a-real-action');
    }

    public function testIncomingAndOutgoingInviteLists(): void
    {
        $aliceId = $this->createUser('alice');
        $bobId = $this->createUser('bob');

        $this->friendships->sendInvite($aliceId, 'bob');

        $aliceOutgoing = $this->friendships->listOutgoingInvites($aliceId);
        $aliceIncoming = $this->friendships->listIncomingInvites($aliceId);
        $bobIncoming = $this->friendships->listIncomingInvites($bobId);
        $bobOutgoing = $this->friendships->listOutgoingInvites($bobId);

        self::assertCount(1, $aliceOutgoing);
        self::assertSame('bob', $aliceOutgoing[0]['other_username']);
        self::assertCount(0, $aliceIncoming);

        self::assertCount(1, $bobIncoming);
        self::assertSame('alice', $bobIncoming[0]['other_username']);
        self::assertCount(0, $bobOutgoing);
    }
}
