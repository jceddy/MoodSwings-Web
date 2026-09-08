#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Discord\DiscordNotificationChannel;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Notifications\NotificationService;
use MoodSwings\Notifications\PushNotificationChannel;
use MoodSwings\Repository\DiscordAccountRepository;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\NotificationCooldownRepository;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\PushSubscriptionRepository;
use MoodSwings\Repository\QueuedNotificationRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\ChaosDefaultEffectRegistry;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;

// Meant to run every minute or so via cron -- reported live: "add a way
// for a bot finishing its turn to advance to the next turn without
// requiring a physical browser refresh somewhere - mostly this is so
// notifications can be generated when it is the human player's turn."
// Every other GameService::advanceAutomatedTurns() call site only ever
// runs as a side effect of some client's own HTTP request against that
// specific game (a human's own play/pass/etc., or GET /games/state's own
// poll timer) -- a bot's turn (or an all-bot team decision) landing in a
// game nobody currently has open has no such request left to ride along
// on, so it just sits there, unresolved, and the human waiting on it
// never gets an "it's your turn" push/Discord notification until they
// happen to check back on their own. See
// GameService::advanceAutomatedTurnsForAllActiveGames()'s own docblock
// for the full reasoning and why this needs no locking of its own.
//
// $notifications is wired up here (unlike expire_and_delete_stale_games.php's
// own GameService, which never needs to notify anyone) the exact same way
// public/index.php's real request-serving construction does -- see that
// file's own top-level $notifications setup -- since this script's whole
// point is letting a bot's turn actually reach the
// NotificationService::notifyYourTurn() call already sitting inside
// GameService's own turn-advance code, not just mutate game state with
// nobody told. ChaosDefaultEffectRegistry is wired in the same
// bin/run_bot_search.php already does, since any bot-seated game could in
// principle be a Chaos Draft one.
// Example crontab line (every minute):
//   * * * * * /usr/bin/php /path/to/php-app/bin/advance_automated_turns.php >> /var/log/moodswings-automated-turns.log 2>&1

$notifications = new NotificationService(
    new NotificationPreferenceRepository(),
    new QueuedNotificationRepository(),
    new NotificationCooldownRepository(),
    [
        new PushNotificationChannel(new PushSubscriptionRepository()),
        new DiscordNotificationChannel(new DiscordAccountRepository()),
    ]
);

$gameRegistry = DefaultEffectRegistry::build();
$chaosRegistry = ChaosDefaultEffectRegistry::build();
$userDecklists = new UserDecklistService(
    new UserDecklistRepository(),
    new FriendshipService(new UserRepository(), new FriendshipRepository()),
);
$games = new GameService(
    new BoardStateRepository($gameRegistry, $chaosRegistry),
    new MoodPlayService($gameRegistry, $chaosRegistry),
    new RoundScorer(),
    $userDecklists,
    new ReplayStateBuilder($gameRegistry),
    notifications: $notifications,
    chaosRegistry: $chaosRegistry,
);

$advanced = $games->advanceAutomatedTurnsForAllActiveGames();

echo "Advanced {$advanced} game(s) with something automated pending.\n";
