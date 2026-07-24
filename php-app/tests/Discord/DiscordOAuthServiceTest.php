<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Discord;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MoodSwings\Discord\DiscordLinkException;
use MoodSwings\Discord\DiscordOAuthService;
use MoodSwings\Repository\DiscordAccountRepository;
use MoodSwings\Repository\DiscordOAuthStateRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DiscordOAuthServiceTest extends TestCase
{
    private PDO $pdo;
    private DiscordAccountRepository $accounts;
    private DiscordOAuthStateRepository $states;

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
        $pdo->exec('TRUNCATE TABLE discord_oauth_states');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");
        putenv('APP_URL=https://moodswings.example.com');
        putenv('DISCORD_CLIENT_ID=test-client-id');
        putenv('DISCORD_CLIENT_SECRET=test-client-secret');

        $this->pdo = $pdo;
        $this->accounts = new DiscordAccountRepository();
        $this->states = new DiscordOAuthStateRepository();
    }

    protected function tearDown(): void
    {
        putenv('APP_URL');
        putenv('DISCORD_CLIENT_ID');
        putenv('DISCORD_CLIENT_SECRET');
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
    private function serviceWithMockHttp(array $responses): DiscordOAuthService
    {
        $mock = new MockHandler($responses);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        return new DiscordOAuthService($this->accounts, $this->states, $client);
    }

    private function extractStateFromAuthorizeUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    public function testBuildAuthorizeUrlPointsAtDiscordWithExpectedParams(): void
    {
        $userId = $this->insertUser('authorize-url');
        $service = $this->serviceWithMockHttp([]);

        $url = $service->buildAuthorizeUrl($userId);

        self::assertStringStartsWith('https://discord.com/oauth2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('test-client-id', $query['client_id']);
        self::assertSame('https://moodswings.example.com/discord/oauth/callback', $query['redirect_uri']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('identify', $query['scope']);
        self::assertNotEmpty($query['state']);
    }

    public function testSuccessfulCallbackLinksTheAccount(): void
    {
        $userId = $this->insertUser('callback-success');
        $service = $this->serviceWithMockHttp([
            new Response(200, [], json_encode(['access_token' => 'fake-access-token'])),
            new Response(200, [], json_encode(['id' => '555666777', 'username' => 'realuser'])),
        ]);

        $state = $this->extractStateFromAuthorizeUrl($service->buildAuthorizeUrl($userId));

        $result = $service->handleCallback($userId, 'some-code', $state);

        self::assertSame(['discord_user_id' => '555666777', 'discord_username' => 'realuser'], $result);
        self::assertSame(
            ['discord_user_id' => '555666777', 'discord_username' => 'realuser'],
            $this->accounts->findByUserId($userId)
        );
    }

    public function testStateIsSingleUse(): void
    {
        $userId = $this->insertUser('single-use');
        $service = $this->serviceWithMockHttp([
            new Response(200, [], json_encode(['access_token' => 'tok'])),
            new Response(200, [], json_encode(['id' => '1', 'username' => 'u'])),
        ]);
        $state = $this->extractStateFromAuthorizeUrl($service->buildAuthorizeUrl($userId));

        $service->handleCallback($userId, 'code', $state);

        $this->expectException(DiscordLinkException::class);
        $service->handleCallback($userId, 'code-again', $state);
    }

    public function testUnknownStateIsRejected(): void
    {
        $userId = $this->insertUser('unknown-state');
        $service = $this->serviceWithMockHttp([]);

        $this->expectException(DiscordLinkException::class);
        $service->handleCallback($userId, 'code', 'never-issued-state');
    }

    public function testStateIssuedForADifferentUserIsRejected(): void
    {
        $userA = $this->insertUser('user-a');
        $userB = $this->insertUser('user-b');
        $service = $this->serviceWithMockHttp([]);

        $state = $this->extractStateFromAuthorizeUrl($service->buildAuthorizeUrl($userA));

        $this->expectException(DiscordLinkException::class);
        $service->handleCallback($userB, 'code', $state);
    }

    public function testDiscordRejectingTheTokenExchangeSurfacesAsALinkException(): void
    {
        $userId = $this->insertUser('token-exchange-fails');
        $service = $this->serviceWithMockHttp([
            new ConnectException('connection refused', new Request('POST', 'https://discord.com/api/v10/oauth2/token')),
        ]);
        $state = $this->extractStateFromAuthorizeUrl($service->buildAuthorizeUrl($userId));

        $this->expectException(DiscordLinkException::class);
        $service->handleCallback($userId, 'code', $state);
    }
}
