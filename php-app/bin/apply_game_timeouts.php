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

// Issue #85's own sweep -- an opted-in game (games.timeout_minutes/
// timeout_action, set at creation) whose current turn holder or
// pending-decision target has been idle too long gets that turn/decision
// resolved on their behalf automatically, so nobody else at the table is
// stuck waiting on them indefinitely. See
// GameService::applyTimeoutsForAllActiveGames()/applyTimeoutToGame() for
// exactly what "too long" and "resolved on their behalf" mean.
//
// Meant to run every 15 minutes via cron -- reported live, the
// maintainer's own dev/production environments can't run anything more
// often than that, which is also why createGame() enforces a 30-minute
// floor on timeout_minutes itself (see migration 0324's own docblock):
// a shorter configured value could sit unnoticed for up to one whole
// extra cron interval past when it nominally elapsed.
//
// Wired up identically to bin/advance_automated_turns.php (a real
// NotificationService, ChaosDefaultEffectRegistry alongside the plain
// DefaultEffectRegistry) for the same reason that script needs it: an
// auto-played/skipped/resigned action here can just as easily hand the
// turn to a human who's owed an "it's your turn" notification as any
// other automated turn advance would, and any timeout-enabled game
// could in principle be a Chaos Draft one.
// Example crontab line (every 15 minutes):
//   */15 * * * * /usr/bin/php /path/to/php-app/bin/apply_game_timeouts.php >> /var/log/moodswings-game-timeouts.log 2>&1

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

$applied = $games->applyTimeoutsForAllActiveGames();

echo "Applied a time-out to {$applied} game(s).\n";
