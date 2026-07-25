<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MoodSwings\Config;
use MoodSwings\Notifications\NotificationChannel;
use MoodSwings\Repository\DiscordAccountRepository;
use MoodSwings\SiteUrl;

/**
 * Sends a notification as a Discord DM, using the Application's own bot
 * token (DISCORD_BOT_TOKEN) -- never a player's own OAuth grant, which
 * DiscordOAuthService never even retains past the linking request (see
 * its own docblock). A user with no linked Discord account
 * (DiscordAccountRepository), or a deployment with no bot token
 * configured, is a no-op (false) -- same as PushNotificationChannel
 * returning false for a user with no push subscriptions, never treated
 * as a failure by NotificationService's shared cooldown/queue logic.
 *
 * Opening a DM is a two-step REST call every time (Discord has no
 * persistent-channel concept to cache here): POST /users/@me/channels
 * with the recipient's user id returns a DM channel id (idempotent --
 * calling it again for the same pair returns the same channel), then
 * POST /channels/{id}/messages sends into it.
 *
 * Discord has no notion of a relative in-app URL, unlike the browser
 * push payload's own 'url' (opened by the Service Worker directly
 * against this origin) -- so $payload['url'] is resolved against
 * SiteUrl::root() before being included in the DM.
 */
final class DiscordNotificationChannel implements NotificationChannel
{
    private const API_BASE = 'https://discord.com/api/v10';

    public function __construct(
        private readonly DiscordAccountRepository $accounts,
        private readonly Client $http = new Client(),
    ) {
    }

    public function send(int $userId, array $payload): bool
    {
        $botToken = Config::get('DISCORD_BOT_TOKEN', '');
        if ($botToken === '') {
            return false;
        }

        $account = $this->accounts->findByUserId($userId);
        if ($account === null) {
            return false;
        }

        try {
            $channelResponse = $this->http->post(self::API_BASE . '/users/@me/channels', [
                'headers' => ['Authorization' => "Bot {$botToken}"],
                'json' => ['recipient_id' => $account['discord_user_id']],
            ]);
            $channelId = json_decode((string) $channelResponse->getBody(), true)['id'] ?? null;
            if (!is_string($channelId) || $channelId === '') {
                $this->logError("Discord DM channel open returned no channel id (user {$userId})");
                return false;
            }

            $this->http->post(self::API_BASE . "/channels/{$channelId}/messages", [
                'headers' => ['Authorization' => "Bot {$botToken}"],
                'json' => ['content' => $this->renderMessage($payload)],
            ]);

            return true;
        } catch (GuzzleException $e) {
            $this->logError("Discord DM send failed (user {$userId}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    private function renderMessage(array $payload): string
    {
        return "**{$payload['title']}**\n{$payload['body']}\n" . SiteUrl::root() . $payload['url'];
    }

    /** Same convention as DiscordInteractionsService::logError() -- shared discord-errors.log. */
    private function logError(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$message}\n";
        error_log($line, 3, dirname(__DIR__) . '/discord-errors.log');
    }
}
