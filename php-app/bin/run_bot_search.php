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

// Issue #419's own Tactical Bot tier -- the detached background process
// GameService::launchTacticalBotSearchJob() spawns (via exec(), never run
// directly by a human) once it's a Tactical Bot's own turn to play.
// Deliberately just a thin CLI wrapper around GameService::
// runTacticalBotSearchJob() -- every actual decision (launch/poll/stale
// detection/fallback) lives there, in the one codebase-wide place that
// logic is unit- and integration-tested, not duplicated here.
//
// Mirrors expire_and_delete_stale_games.php's own standalone-script
// bootstrap pattern (a fresh autoloader require, no HTTP request/session
// context at all), but wires ChaosDefaultEffectRegistry -- like
// public/index.php's own real request-serving construction does -- since
// a Tactical Bot's own game could in principle be any bot-supported
// format/deck_type, not just a non-Chaos one.
//
// $notifications is wired up the same way (found live while fixing a
// related gap in advance_automated_turns.php -- see that script's own
// docblock): runTacticalBotSearchJob() calls playMood()/pass() directly,
// which is exactly where GameService's own "it's your turn" notification
// fires for whoever the search hands the turn to next -- without a real
// NotificationService here, every completed search silently skipped that
// notification (the constructor's own $notifications default is null),
// the same "constructed without notifications" gap this file used to
// share with expire_and_delete_stale_games.php's own GameService, which
// genuinely never needs to notify anyone.
$jobId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: run_bot_search.php <job_id>\n");
    exit(1);
}

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

$games->runTacticalBotSearchJob($jobId);
