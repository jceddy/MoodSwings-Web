#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;

// Meant to run once a day via cron -- issue #84's "clean up old
// completed/abandoned games". Two passes, in order: first, permanently
// delete every 'completed' game whose last activity is more than 7 days
// old (nothing left to look at once an outcome is settled and stale);
// then, force-complete any remaining game (still
// 'waiting'/'in_progress'/'abandoned') whose last activity is ALSO more
// than 7 days old, so it stops cluttering the main lobby (see "Past
// games" in ../README.md) -- it'll be picked up and deleted by the first
// pass on some future run once it's been stale for long enough in turn.
// See GameService::deleteStaleCompletedGames()/expireStaleActiveGames()
// for the exact staleness definition and what each pass actually does.
// Example crontab line (once daily, 3am):
//   0 3 * * * /usr/bin/php /path/to/php-app/bin/expire_and_delete_stale_games.php >> /var/log/moodswings-game-cleanup.log 2>&1

$registry = DefaultEffectRegistry::build();
$userDecklists = new UserDecklistService(
    new UserDecklistRepository(),
    new FriendshipService(new UserRepository(), new FriendshipRepository()),
);
$games = new GameService(
    new BoardStateRepository($registry),
    new MoodPlayService($registry),
    new RoundScorer(),
    $userDecklists,
    new ReplayStateBuilder($registry),
);

$deleted = $games->deleteStaleCompletedGames();
$expired = $games->expireStaleActiveGames();

echo "Deleted {$deleted} stale completed game(s); expired {$expired} stale active game(s).\n";
