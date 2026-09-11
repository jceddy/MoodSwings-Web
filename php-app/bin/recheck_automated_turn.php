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

// GameService::scheduleAutomatedTurnRecheck()'s own detached background
// process -- never run directly by a human. Reported live: "add a way
// for a bot finishing its turn to advance to the next turn without
// requiring a physical browser refresh somewhere - mostly this is so
// notifications can be generated when it is the human player's turn",
// followed by "is there a way to implement this without requiring a
// cron job? can whatever is in the CRON script just run when the bot
// gets to the end of its turn?" -- this IS that: rather than a periodic
// external sweep (bin/advance_automated_turns.php, which still works
// fine as an optional extra safety net but is no longer required),
// advanceAutomatedTurns() spawns exactly ONE of these every time it
// actually drives something, and this script's own eventual
// advanceAutomatedTurns() call spawns the NEXT link in the chain the
// same way, for as long as there's genuinely more to advance -- see
// scheduleAutomatedTurnRecheck()'s own docblock for the full reasoning,
// including why the chain is safe to run concurrently with live traffic
// and self-terminates with no bookkeeping of its own.
//
// Mirrors bin/run_bot_search.php's own thin-CLI-wrapper-around-GameService
// shape (including wiring a real NotificationService, for the exact same
// reason -- this script's whole point is letting a bot's turn actually
// reach the notification call already sitting inside GameService's own
// turn-advance code) and ChaosDefaultEffectRegistry construction, since
// any bot-seated game could in principle be a Chaos Draft one. Sleeps
// AUTOMATED_TURN_RECHECK_DELAY_SECONDS before doing anything -- a brief
// pause rather than an immediate re-check, since nothing else changes
// this game's own state in between without another player's own action.
$gameId = isset($argv[1]) ? (int) $argv[1] : 0;
$recheckChainDepth = isset($argv[2]) ? (int) $argv[2] : 0;
if ($gameId <= 0) {
    fwrite(STDERR, "Usage: recheck_automated_turn.php <game_id> [recheck_chain_depth]\n");
    exit(1);
}

sleep(GameService::AUTOMATED_TURN_RECHECK_DELAY_SECONDS);

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

$games->advanceAutomatedTurns($gameId, $recheckChainDepth);
