<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

/**
 * Thrown by DiscordOAuthService::handleCallback() for anything that means
 * "don't link this account" -- an invalid/expired/already-consumed CSRF
 * state, or Discord itself rejecting the code exchange (expired code,
 * mismatched redirect_uri, revoked consent). The message is safe to show
 * the user as-is (no token/secret ever appears in it).
 */
final class DiscordLinkException extends \RuntimeException
{
}
