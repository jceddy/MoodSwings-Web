<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

use MoodSwings\Config;

/**
 * Handles Discord's Interactions Endpoint protocol -- every slash
 * command/button/modal interaction Discord ever sends this app arrives as
 * a single HTTP POST here (`public/index.php`'s own `/discord/interactions`
 * route), never over a persistent gateway/WebSocket connection. That's
 * what lets this run as an ordinary request in the same Apache/PHP
 * process model as the rest of `php-app/`, with no separate long-running
 * bot process to deploy or keep alive.
 *
 * Every request MUST be signature-verified before its body is trusted --
 * Discord signs each one with the Application's private key (Ed25519),
 * checkable against the Public Key from the Developer Portal
 * (DISCORD_PUBLIC_KEY) using `sodium_crypto_sign_verify_detached()`. A
 * request that fails verification must be rejected with 401 before any of
 * its JSON is even parsed -- see `verify()`'s own docblock.
 *
 * The only interaction type handled so far is PING (type 1, Discord's own
 * one-time "is this URL alive and does it verify correctly" check, sent
 * the moment the Interactions Endpoint URL is saved in the Developer
 * Portal) -- answered with a bare PONG (type 1). Slash commands/buttons
 * (issue #233's own "play the game via Discord" territory) aren't
 * registered yet, so no APPLICATION_COMMAND (type 2) interaction is ever
 * actually sent here today; handle() still switches on `type` rather than
 * assuming PING, so adding a real command later is a new case, not a
 * rewrite.
 */
final class DiscordInteractionsService
{
    private const TYPE_PING = 1;
    private const TYPE_PONG = 1;

    /**
     * Ed25519 signature check over the exact raw request body Discord
     * sent, using the two headers it always includes:
     * `X-Signature-Ed25519` (the signature itself, hex-encoded) and
     * `X-Signature-Timestamp` (concatenated with the body before
     * verifying -- this is Discord's own protocol, not this app's
     * choice). Returns false for a missing header, a malformed hex
     * signature, or a signature that doesn't verify -- the caller must
     * treat every false the same way (401, body never parsed) rather than
     * distinguishing why, so a malformed request can't be used to probe
     * which failure mode it hit.
     */
    public function verify(string $rawBody, ?string $signatureHex, ?string $timestamp): bool
    {
        if ($signatureHex === null || $timestamp === null) {
            $this->logError('Rejected interaction: missing X-Signature-Ed25519/X-Signature-Timestamp header');
            return false;
        }

        $publicKeyHex = Config::get('DISCORD_PUBLIC_KEY', '');
        if ($publicKeyHex === '') {
            $this->logError('Rejected interaction: DISCORD_PUBLIC_KEY is not configured');
            return false;
        }

        $signature = @hex2bin($signatureHex);
        $publicKey = @hex2bin($publicKeyHex);
        if ($signature === false || $publicKey === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            $this->logError('Rejected interaction: malformed signature or public key hex');
            return false;
        }

        // \Throwable, not just \SodiumException -- a hosting environment
        // without ext-sodium available would otherwise surface as an
        // uncaught \Error ("Call to undefined function
        // sodium_crypto_sign_verify_detached()") instead of a clean 401,
        // and with no trace of why in any log this class controls.
        try {
            $verified = sodium_crypto_sign_verify_detached($signature, $timestamp . $rawBody, $publicKey);
        } catch (\Throwable $e) {
            $this->logError('Rejected interaction: signature verification threw -- ' . $e->getMessage());
            return false;
        }

        if (!$verified) {
            $this->logError('Rejected interaction: signature did not verify against DISCORD_PUBLIC_KEY');
        }

        return $verified;
    }

    /**
     * $payload is the already-decoded interaction body (only ever called
     * after verify() passes) -- returns the raw array to respond with as
     * JSON, whatever the interaction type. `handle()` never throws for a
     * recognized type; an interaction type this pass doesn't handle yet
     * still needs *some* response (Discord requires one within 3 seconds
     * of every interaction it sends, PING included), so it falls back to
     * a bare PONG-shaped acknowledgement rather than erroring.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $type = $payload['type'] ?? null;

        if ($type === self::TYPE_PING) {
            return ['type' => self::TYPE_PONG];
        }

        // No command is registered yet (see this class's own docblock),
        // so nothing else is expected to arrive -- acknowledged the same
        // shape as PING rather than left unanswered.
        return ['type' => self::TYPE_PONG];
    }

    /**
     * Same convention as PushNotificationChannel::logError() -- a
     * dedicated log file rather than the general PHP error log, so a
     * rejected interaction (the Developer Portal's own verification
     * check, a forged request, a misconfigured DISCORD_PUBLIC_KEY) is
     * actually diagnosable from the deployed site without shell access to
     * the server's own PHP error log.
     */
    private function logError(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$message}\n";
        error_log($line, 3, dirname(__DIR__) . '/discord-errors.log');
    }
}
