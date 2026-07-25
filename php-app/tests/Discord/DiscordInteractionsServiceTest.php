<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Discord;

use MoodSwings\Discord\DiscordInteractionsService;
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
}
