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
 *
 * Reported live: outside a DM, BOTH environments' commands are visible
 * at once to anyone who's linked their account to both (a real risk
 * given they're the same person testing dev alongside using prod) --
 * Discord has no built-in way to tell two USER-installed apps' identically-
 * named commands apart in the picker beyond a small app-name label most
 * people would never notice before tapping the wrong one. DISCORD_COMMAND_NAME
 * (optional, defaults to 'moodswings') lets dev register under a visibly
 * different name instead -- same DEV_-prefixed-secret-into-unprefixed-
 * .env-key convention every other per-environment Discord config value
 * already uses (see deploy-dev.yml/deploy.yml's own DEV_DISCORD_COMMAND_NAME/
 * DISCORD_COMMAND_NAME), so this script itself never needs to know which
 * environment it's running in -- it just registers whatever name that
 * environment's own .env happens to carry, 'moodswings' if nothing
 * overrides it (unset for prod today, matching its own desired name
 * exactly).
 */
$applicationId = Config::get('DISCORD_CLIENT_ID', '');
$botToken = Config::get('DISCORD_BOT_TOKEN', '');
$commandName = Config::get('DISCORD_COMMAND_NAME', 'moodswings');

if ($applicationId === '' || $botToken === '') {
    fwrite(STDERR, "DISCORD_CLIENT_ID and DISCORD_BOT_TOKEN must both be configured in .env first.\n");
    exit(1);
}

$commands = [
    [
        'name' => $commandName,
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
    echo "Registered " . count(json_decode((string) $response->getBody(), true)) . " command(s) as /{$commandName}.\n";
} catch (GuzzleException $e) {
    fwrite(STDERR, 'Discord rejected the command registration: ' . $e->getMessage() . "\n");
    exit(1);
}
