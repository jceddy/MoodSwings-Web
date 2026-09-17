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
use MoodSwings\Repository\TournamentMatchRepository;
use MoodSwings\Repository\TournamentParticipantRepository;
use MoodSwings\Repository\TournamentPodRepository;
use MoodSwings\Repository\TournamentRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use MoodSwings\Tournament\BoosterDraftPodBuilder;
use MoodSwings\Tournament\BoosterPackBuilder;
use MoodSwings\Tournament\GridDraftPodBuilder;
use MoodSwings\Tournament\TournamentBracketBuilder;
use MoodSwings\Tournament\TournamentService;

// Meant to run once a day via cron -- issue #84's "clean up old
// completed/abandoned games". Three passes, in order: first, permanently
// delete every 'completed' game whose last activity is more than 7 days
// old (nothing left to look at once an outcome is settled and stale);
// then, force-complete any remaining game (still
// 'waiting'/'in_progress'/'abandoned') whose last activity is ALSO more
// than 7 days old, so it stops cluttering the main lobby (see "Past
// games" in ../README.md) -- it'll be picked up and deleted by the first
// pass on some future run once it's been stale for long enough in turn.
// See GameService::deleteStaleCompletedGames()/expireStaleActiveGames()
// for the exact staleness definition and what each pass actually does.
//
// Third (reported live: "make sure tournaments get cleaned up after
// being cancelled/completed for a week"): permanently deletes every
// tournament that's been 'completed'/'cancelled' for more than 7 days
// too -- see TournamentRepository::deleteStale()'s own docblock for
// exactly what that cascades to (and, just as importantly, what it
// deliberately leaves alone). Independent of the two game passes above
// -- a tournament's own matches' underlying games are cleaned up on
// their own staleness clock, whichever of the three runs gets there
// first.
//
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

$tournaments = new TournamentService(
    new TournamentRepository(),
    new TournamentParticipantRepository(),
    new TournamentMatchRepository(),
    new TournamentBracketBuilder(),
    $games,
    new UserRepository(),
    new FriendshipRepository(),
    new TournamentPodRepository(),
    new BoosterPackBuilder(),
    new BoosterDraftPodBuilder(),
    new GridDraftPodBuilder(),
);

$deleted = $games->deleteStaleCompletedGames();
$expired = $games->expireStaleActiveGames();
$tournamentsDeleted = $tournaments->deleteStaleTournaments();

echo "Deleted {$deleted} stale completed game(s); expired {$expired} stale active game(s); deleted {$tournamentsDeleted} stale tournament(s).\n";
