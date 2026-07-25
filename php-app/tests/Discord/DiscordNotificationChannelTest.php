<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Discord;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MoodSwings\Discord\DiscordNotificationChannel;
use MoodSwings\Repository\DiscordAccountRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DiscordNotificationChannelTest extends TestCase
{
    private PDO $pdo;
    private DiscordAccountRepository $accounts;

    private const PAYLOAD = [
        'title' => "It's your turn",
        'body' => 'Game #7 is waiting on your move.',
        'url' => '/game/?id=7',
        'tag' => 'game-7-turn',
    ];

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

    protected function tearDown(): void
    {
        putenv('DISCORD_BOT_TOKEN');
        putenv('SITE_URL');
        putenv('APP_URL');
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

    /** @param array<int, Response|ConnectException> $responses */
    private function channelWithMockHttp(array $responses, ?array &$history = null): DiscordNotificationChannel
    {
        $historyContainer = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($historyContainer));
        $client = new Client(['handler' => $stack]);

        // $history is populated after each real request the client sends
        // -- assigned by reference so callers can inspect it after send()
        // returns, since the container itself is only ever appended to
        // during the request, never handed back directly.
        $history = &$historyContainer;

        return new DiscordNotificationChannel($this->accounts, $client);
    }

    public function testSendIsANoOpWhenBotTokenIsNotConfigured(): void
    {
        $userId = $this->insertUser('no-bot-token');
        $this->accounts->link($userId, '111', 'someuser');
        putenv('DISCORD_BOT_TOKEN=');

        // An empty response queue means any actual HTTP call here would
        // throw (MockHandler has nothing left to serve) -- so reaching
        // the assertion at all proves no network call was attempted.
        $channel = $this->channelWithMockHttp([]);

        self::assertFalse($channel->send($userId, self::PAYLOAD));
    }

    public function testSendIsANoOpWhenTheUserHasNoLinkedDiscordAccount(): void
    {
        $userId = $this->insertUser('not-linked');
        putenv('DISCORD_BOT_TOKEN=test-bot-token');

        $channel = $this->channelWithMockHttp([]);

        self::assertFalse($channel->send($userId, self::PAYLOAD));
    }

    public function testSendOpensADmChannelAndPostsTheMessage(): void
    {
        $userId = $this->insertUser('linked-user');
        $this->accounts->link($userId, '555666777', 'realuser');
        putenv('DISCORD_BOT_TOKEN=test-bot-token');
        putenv('SITE_URL=https://moodswings.example.com');

        $channel = $this->channelWithMockHttp([
            new Response(200, [], json_encode(['id' => 'dm-channel-1'])),
            new Response(200, [], json_encode(['id' => 'message-1'])),
        ], $history);

        self::assertTrue($channel->send($userId, self::PAYLOAD));

        self::assertCount(2, $history);

        $channelRequest = $history[0]['request'];
        self::assertSame('https://discord.com/api/v10/users/@me/channels', (string) $channelRequest->getUri());
        self::assertSame('Bot test-bot-token', $channelRequest->getHeaderLine('Authorization'));
        self::assertSame(
            ['recipient_id' => '555666777'],
            json_decode((string) $channelRequest->getBody(), true)
        );

        $messageRequest = $history[1]['request'];
        self::assertSame('https://discord.com/api/v10/channels/dm-channel-1/messages', (string) $messageRequest->getUri());
        $messageBody = json_decode((string) $messageRequest->getBody(), true);
        self::assertStringContainsString("It's your turn", $messageBody['content']);
        self::assertStringContainsString('Game #7 is waiting on your move.', $messageBody['content']);
        self::assertStringContainsString('https://moodswings.example.com/game/?id=7', $messageBody['content']);
    }

    public function testSendReturnsFalseWhenTheChannelOpenResponseHasNoId(): void
    {
        $userId = $this->insertUser('malformed-channel-response');
        $this->accounts->link($userId, '1', 'u');
        putenv('DISCORD_BOT_TOKEN=test-bot-token');

        // Only one response queued -- if send() incorrectly tried to post
        // the message anyway, MockHandler would throw for the missing
        // second response instead of this test's own assertion failing.
        $channel = $this->channelWithMockHttp([
            new Response(200, [], json_encode(['no_id_here' => true])),
        ]);

        self::assertFalse($channel->send($userId, self::PAYLOAD));
    }

    public function testSendReturnsFalseWhenDiscordIsUnreachable(): void
    {
        $userId = $this->insertUser('discord-unreachable');
        $this->accounts->link($userId, '1', 'u');
        putenv('DISCORD_BOT_TOKEN=test-bot-token');

        $channel = $this->channelWithMockHttp([
            new ConnectException('connection refused', new Request('POST', 'https://discord.com/api/v10/users/@me/channels')),
        ]);

        self::assertFalse($channel->send($userId, self::PAYLOAD));
    }
}
