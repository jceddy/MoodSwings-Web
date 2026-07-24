<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MoodSwings\Config;
use MoodSwings\Repository\DiscordAccountRepository;
use MoodSwings\Repository\DiscordOAuthStateRepository;

/**
 * "Connect Discord" -- the standard OAuth2 authorization-code flow,
 * `identify` scope only (just enough to learn the player's Discord user
 * id/username; never enough to act as them). No `bot` or
 * `applications.commands` scope is requested here -- the Application is
 * registered in the Developer Portal as installable directly to a user's
 * own account ("User Install"), which is what actually lets the bot DM a
 * linked player later without sharing a server with them; that
 * installation happens as a side effect of this same consent screen, not
 * as a separate scope this code has to ask for.
 *
 * Every outbound call after linking (DiscordNotificationService) uses the
 * Application's own bot token, never the access token this flow obtains --
 * so, unlike a typical "Connect" integration, the token exchange response
 * is read once for the account id/username and then discarded entirely;
 * nothing from it is persisted (see migration 0050's own docblock).
 */
final class DiscordOAuthService
{
    private const STATE_TTL_MINUTES = 10;
    private const AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';
    private const TOKEN_URL = 'https://discord.com/api/v10/oauth2/token';
    private const IDENTIFY_URL = 'https://discord.com/api/v10/users/@me';

    public function __construct(
        private readonly DiscordAccountRepository $accounts,
        private readonly DiscordOAuthStateRepository $states,
        private readonly Client $http = new Client(),
    ) {
    }

    public function buildAuthorizeUrl(int $userId): string
    {
        $state = bin2hex(random_bytes(32));
        $this->states->create(hash('sha256', $state), $userId, new DateTimeImmutable('+' . self::STATE_TTL_MINUTES . ' minutes'));

        $params = [
            'client_id' => Config::get('DISCORD_CLIENT_ID', ''),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'identify',
            'state' => $state,
            'prompt' => 'consent',
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * @return array{discord_user_id: string, discord_username: string}
     * @throws DiscordLinkException
     */
    public function handleCallback(int $expectedUserId, string $code, string $state): array
    {
        $stateUserId = $this->states->consumeValid(hash('sha256', $state));
        if ($stateUserId === null) {
            throw new DiscordLinkException('This Discord connection link has expired or was already used -- try connecting again.');
        }
        if ($stateUserId !== $expectedUserId) {
            // The state was valid, but for a different logged-in user than
            // is completing the callback (e.g. a stale link opened in a
            // browser now signed into a different account) -- never link
            // it to the wrong one.
            throw new DiscordLinkException('This Discord connection link was issued for a different account.');
        }

        try {
            $tokenResponse = $this->http->post(self::TOKEN_URL, [
                'form_params' => [
                    'client_id' => Config::get('DISCORD_CLIENT_ID', ''),
                    'client_secret' => Config::get('DISCORD_CLIENT_SECRET', ''),
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri(),
                ],
            ]);
            $tokenBody = json_decode((string) $tokenResponse->getBody(), true);
            $accessToken = $tokenBody['access_token'] ?? null;
            if (!is_string($accessToken) || $accessToken === '') {
                throw new DiscordLinkException("Discord didn't return an access token.");
            }

            $identifyResponse = $this->http->get(self::IDENTIFY_URL, [
                'headers' => ['Authorization' => "Bearer {$accessToken}"],
            ]);
            $identity = json_decode((string) $identifyResponse->getBody(), true);
        } catch (GuzzleException $e) {
            throw new DiscordLinkException('Discord rejected the connection request -- try connecting again.', previous: $e);
        }

        $discordUserId = $identity['id'] ?? null;
        $discordUsername = $identity['username'] ?? null;
        if (!is_string($discordUserId) || !is_string($discordUsername)) {
            throw new DiscordLinkException("Discord didn't return a valid account identity.");
        }

        $this->accounts->link($expectedUserId, $discordUserId, $discordUsername);

        return ['discord_user_id' => $discordUserId, 'discord_username' => $discordUsername];
    }

    private function redirectUri(): string
    {
        return rtrim((string) Config::get('APP_URL', ''), '/') . '/discord/oauth/callback';
    }
}
