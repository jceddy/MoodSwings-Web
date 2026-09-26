#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MoodSwings\Config;

/**
 * Registers (or re-registers -- PUT is a full overwrite, safe to rerun
 * any time this command's own definition changes) the `/moodswings`
 * slash command Discord's own Interactions Endpoint
 * (DiscordInteractionsService, DiscordGameCommandService) answers --
 * issue #233. Run once per environment (dev/prod each have their own
 * Discord Application -- see php-app/README.md's "Discord" section on
 * why) after DISCORD_CLIENT_ID/DISCORD_BOT_TOKEN are configured:
 *
 *   php bin/register_discord_commands.php
 *
 * `integration_types: [1]` (USER_INSTALL) and every context
 * (GUILD/BOT_DM/PRIVATE_CHANNEL) mirror this Application's own
 * user-install-only setup (see DiscordOAuthService's own docblock -- no
 * `bot`/`applications.commands` GUILD_INSTALL scope is requested
 * anywhere else either), so a linked player can run this from a DM with
 * the bot, a server, or a group DM, without the bot ever needing to be
 * added to a server on its own.
 */
$applicationId = Config::get('DISCORD_CLIENT_ID', '');
$botToken = Config::get('DISCORD_BOT_TOKEN', '');

if ($applicationId === '' || $botToken === '') {
    fwrite(STDERR, "DISCORD_CLIENT_ID and DISCORD_BOT_TOKEN must both be configured in .env first.\n");
    exit(1);
}

$commands = [
    [
        'name' => 'moodswings',
        'type' => 1,
        'description' => 'View and play your active Traditional MoodSwings game(s)',
        'integration_types' => [1],
        'contexts' => [0, 1, 2],
    ],
];

try {
    $response = (new Client())->put("https://discord.com/api/v10/applications/{$applicationId}/commands", [
        'headers' => ['Authorization' => "Bot {$botToken}"],
        'json' => $commands,
    ]);
    echo "Registered " . count(json_decode((string) $response->getBody(), true)) . " command(s).\n";
} catch (GuzzleException $e) {
    fwrite(STDERR, 'Discord rejected the command registration: ' . $e->getMessage() . "\n");
    exit(1);
}
