<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Discord;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Discord\DiscordGameCommandService;
use MoodSwings\Discord\DiscordInteractionsService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Repository\DiscordAccountRepository;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PHPUnit\Framework\TestCase;

final class DiscordInteractionsServiceTest extends TestCase
{
    private DiscordInteractionsService $service;
    private string $publicKeyHex;
    private string $secretKey;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($keypair));

        putenv("DISCORD_PUBLIC_KEY={$this->publicKeyHex}");
        $this->service = new DiscordInteractionsService();
    }

    protected function tearDown(): void
    {
        putenv('DISCORD_PUBLIC_KEY');
    }

    private function sign(string $timestamp, string $body): string
    {
        return bin2hex(sodium_crypto_sign_detached($timestamp . $body, $this->secretKey));
    }

    public function testValidSignatureVerifies(): void
    {
        $timestamp = (string) time();
        $body = '{"type":1}';
        $signature = $this->sign($timestamp, $body);

        self::assertTrue($this->service->verify($body, $signature, $timestamp));
    }

    public function testWrongBodyFailsVerification(): void
    {
        $timestamp = (string) time();
        $signature = $this->sign($timestamp, '{"type":1}');

        self::assertFalse($this->service->verify('{"type":2}', $signature, $timestamp));
    }

    public function testWrongTimestampFailsVerification(): void
    {
        $body = '{"type":1}';
        $signature = $this->sign('1000', $body);

        self::assertFalse($this->service->verify($body, $signature, '1001'));
    }

    public function testSignedWithDifferentKeyFailsVerification(): void
    {
        $otherKeypair = sodium_crypto_sign_keypair();
        $timestamp = (string) time();
        $body = '{"type":1}';
        $signature = bin2hex(sodium_crypto_sign_detached($timestamp . $body, sodium_crypto_sign_secretkey($otherKeypair)));

        self::assertFalse($this->service->verify($body, $signature, $timestamp));
    }

    public function testMissingSignatureHeaderFailsVerification(): void
    {
        self::assertFalse($this->service->verify('{"type":1}', null, (string) time()));
    }

    public function testMissingTimestampHeaderFailsVerification(): void
    {
        $signature = $this->sign((string) time(), '{"type":1}');

        self::assertFalse($this->service->verify('{"type":1}', $signature, null));
    }

    public function testMalformedHexSignatureFailsVerificationRatherThanThrowing(): void
    {
        self::assertFalse($this->service->verify('{"type":1}', 'not-hex!!', (string) time()));
    }

    public function testWrongLengthSignatureFailsVerificationRatherThanThrowing(): void
    {
        self::assertFalse($this->service->verify('{"type":1}', bin2hex('too-short'), (string) time()));
    }

    public function testMissingPublicKeyConfigFailsVerification(): void
    {
        putenv('DISCORD_PUBLIC_KEY');
        $service = new DiscordInteractionsService();
        $timestamp = (string) time();
        $signature = $this->sign($timestamp, '{"type":1}');

        self::assertFalse($service->verify('{"type":1}', $signature, $timestamp));
    }

    public function testPingRespondsWithPong(): void
    {
        self::assertSame(['type' => 1], $this->service->handle(['type' => 1]));
    }

    public function testUnrecognizedTypeStillReceivesAResponse(): void
    {
        self::assertSame(['type' => 1], $this->service->handle(['type' => 2]));
    }

    /**
     * Issue #233: once a DiscordGameCommandService is actually configured
     * (unlike $this->service above, built with none), APPLICATION_COMMAND
     * (type 2) and MESSAGE_COMPONENT (type 3) delegate to it instead of
     * falling back to a bare PONG. Neither payload here carries a
     * 'user'/'member' id, so DiscordGameCommandService::resolveUserId()
     * returns null before ever touching the database -- exactly what lets
     * this stay a fast, DB-free unit test while still proving the actual
     * dispatch wiring (not just that SOME response comes back).
     */
    private function gameCommandsBackedService(): DiscordInteractionsService
    {
        $registry = DefaultEffectRegistry::build();
        $userDecklists = new UserDecklistService(
            new UserDecklistRepository(),
            new FriendshipService(new UserRepository(), new FriendshipRepository()),
        );
        $games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            $userDecklists,
            new ReplayStateBuilder($registry),
            spawnAutomatedTurnRecheckProcesses: false,
        );

        return new DiscordInteractionsService(
            new DiscordGameCommandService($games, new BoardStateRepository($registry), new DiscordAccountRepository())
        );
    }

    public function testApplicationCommandDispatchesToGameCommands(): void
    {
        $response = $this->gameCommandsBackedService()->handle(['type' => 2, 'data' => ['name' => 'moodswings']]);

        self::assertSame(4, $response['type']);
        self::assertStringContainsString("isn't linked", $response['data']['content']);
    }

    public function testMessageComponentDispatchesToGameCommands(): void
    {
        $response = $this->gameCommandsBackedService()->handle(['type' => 3, 'data' => ['custom_id' => 'ms:view:1', 'values' => []]]);

        self::assertSame(7, $response['type']);
        self::assertStringContainsString("isn't linked", $response['data']['content']);
    }
}
