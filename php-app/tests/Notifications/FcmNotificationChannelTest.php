<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Notifications;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MoodSwings\Notifications\FcmAccessTokenProvider;
use MoodSwings\Notifications\FcmNotificationChannel;
use MoodSwings\Repository\DevicePushTokenRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class FcmNotificationChannelTest extends TestCase
{
    private PDO $pdo;
    private DevicePushTokenRepository $tokens;
    private string $workDir;

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
        $pdo->exec('TRUNCATE TABLE device_push_tokens');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;
        $this->tokens = new DevicePushTokenRepository();
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fcm-test-' . bin2hex(random_bytes(4));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        putenv('FCM_SERVICE_ACCOUNT_FILE');
        putenv('FCM_PROJECT_ID');
        if (isset($this->workDir) && is_dir($this->workDir)) {
            foreach (glob($this->workDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->workDir);
        }
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

    /** Writes a service-account key file with a freshly generated RSA key. */
    private function serviceAccountFile(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $path = $this->workDir . '/service-account.json';
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'mood-test',
            'client_email' => 'fcm@mood-test.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]));

        return $path;
    }

    /**
     * One Guzzle client serves both the token exchange and the FCM calls, so the queued responses are in the order
     * they'll be asked for: the OAuth token first, then one per device.
     *
     * @param array<int, Response> $responses
     */
    private function channel(array $responses, ?array &$history = null): FcmNotificationChannel
    {
        $historyContainer = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($historyContainer));
        $client = new Client(['handler' => $stack]);
        $history = &$historyContainer;

        $provider = new FcmAccessTokenProvider($this->serviceAccountFile(), $client, $this->workDir);

        return new FcmNotificationChannel($this->tokens, $provider, $client);
    }

    private static function tokenResponse(): Response
    {
        return new Response(200, [], json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600]));
    }

    public function testSendIsANoOpWhenNothingIsConfigured(): void
    {
        $userId = $this->insertUser('unconfigured');
        $this->tokens->save($userId, 'android', 'token-1');
        putenv('FCM_SERVICE_ACCOUNT_FILE=');

        self::assertFalse((new FcmNotificationChannel($this->tokens))->send($userId, self::PAYLOAD));
    }

    public function testSendIsANoOpForAUserWithNoRegisteredDevice(): void
    {
        $userId = $this->insertUser('no-devices');

        // An empty response queue: any HTTP call at all would throw, so reaching the assertion proves none was made.
        self::assertFalse($this->channel([])->send($userId, self::PAYLOAD));
    }

    public function testSendPostsToEveryDeviceWithABearerToken(): void
    {
        $userId = $this->insertUser('two-phones');
        $this->tokens->save($userId, 'android', 'token-a');
        $this->tokens->save($userId, 'android', 'token-b');

        $channel = $this->channel([self::tokenResponse(), new Response(200, [], '{}'), new Response(200, [], '{}')], $history);

        self::assertTrue($channel->send($userId, self::PAYLOAD));

        self::assertCount(3, $history);
        self::assertSame('https://oauth2.googleapis.com/token', (string) $history[0]['request']->getUri());
        $send = $history[1]['request'];
        self::assertSame('https://fcm.googleapis.com/v1/projects/mood-test/messages:send', (string) $send->getUri());
        self::assertSame('Bearer ya29.test', $send->getHeaderLine('Authorization'));
        $message = json_decode((string) $send->getBody(), true)['message'];
        self::assertSame('token-a', $message['token']);
        self::assertSame("It's your turn", $message['notification']['title']);
        self::assertSame('Game #7 is waiting on your move.', $message['notification']['body']);
        self::assertSame(['url' => '/game/?id=7', 'tag' => 'game-7-turn'], $message['data']);
        self::assertSame('game-7-turn', $message['android']['notification']['tag']);
        self::assertSame('token-b', json_decode((string) $history[2]['request']->getBody(), true)['message']['token']);
    }

    public function testADeadTokenIsDeleted_ButOtherFailuresKeepIt(): void
    {
        $userId = $this->insertUser('stale');
        $this->tokens->save($userId, 'android', 'token-dead');
        $this->tokens->save($userId, 'android', 'token-flaky');

        $channel = $this->channel([
            self::tokenResponse(),
            new Response(404, [], json_encode(['error' => ['status' => 'UNREGISTERED']])),
            new Response(500, [], json_encode(['error' => ['status' => 'INTERNAL']])),
        ]);

        self::assertTrue($channel->send($userId, self::PAYLOAD));

        $remaining = array_column($this->tokens->listForUser($userId), 'token');
        self::assertSame(['token-flaky'], $remaining);
    }

    public function testAMalformedMessageErrorDoesNotDeleteTheToken(): void
    {
        $userId = $this->insertUser('bad-payload');
        $this->tokens->save($userId, 'android', 'token-good');

        $channel = $this->channel([
            self::tokenResponse(),
            new Response(400, [], json_encode(['error' => ['status' => 'INVALID_ARGUMENT']])),
        ]);

        $channel->send($userId, self::PAYLOAD);

        self::assertCount(1, $this->tokens->listForUser($userId));
    }

    public function testTheAccessTokenIsReusedUntilItExpires(): void
    {
        $userId = $this->insertUser('cached');
        $this->tokens->save($userId, 'android', 'token-1');

        $channel = $this->channel([self::tokenResponse(), new Response(200, [], '{}'), new Response(200, [], '{}')], $history);
        $channel->send($userId, self::PAYLOAD);
        $channel->send($userId, self::PAYLOAD);

        $tokenCalls = array_filter($history, static fn ($entry) => (string) $entry['request']->getUri() === 'https://oauth2.googleapis.com/token');
        self::assertCount(1, $tokenCalls);
    }

    public function testTheTokenExchangeIsSignedWithRs256(): void
    {
        $userId = $this->insertUser('jwt');
        $this->tokens->save($userId, 'android', 'token-1');

        $channel = $this->channel([self::tokenResponse(), new Response(200, [], '{}')], $history);
        $channel->send($userId, self::PAYLOAD);

        parse_str((string) $history[0]['request']->getBody(), $form);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        [$header, $claims] = array_map(
            static fn (string $part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true),
            array_slice(explode('.', $form['assertion']), 0, 2)
        );
        self::assertSame('RS256', $header['alg']);
        self::assertSame('fcm@mood-test.iam.gserviceaccount.com', $claims['iss']);
        self::assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);
    }

    public function testRegisteringATokenAgainMovesItToTheNewUser(): void
    {
        $first = $this->insertUser('first');
        $second = $this->insertUser('second');

        $this->tokens->save($first, 'android', 'shared-install');
        $this->tokens->save($second, 'android', 'shared-install');

        self::assertSame([], $this->tokens->listForUser($first));
        self::assertCount(1, $this->tokens->listForUser($second));
    }

    public function testOnlyTheOwnersRegistrationCanBeRemoved(): void
    {
        $owner = $this->insertUser('owner');
        $other = $this->insertUser('other');
        $this->tokens->save($owner, 'android', 'owned-token');

        $this->tokens->deleteByToken($other, 'owned-token');
        self::assertCount(1, $this->tokens->listForUser($owner));

        $this->tokens->deleteByToken($owner, 'owned-token');
        self::assertSame([], $this->tokens->listForUser($owner));
    }
}
