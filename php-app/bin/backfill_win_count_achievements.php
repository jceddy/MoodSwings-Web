#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MoodSwings\Achievements\AchievementService;
use MoodSwings\Database\Connection;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;

// Reported live: "for some of the achievements that track win counts, would
// it be possible to base them off your statistics page (for people who have
// been playing before the addition)" -- lets the handful of win/loss/game-
// count achievements that predate the achievements system itself (migration
// 0357) reflect a returning player's REAL history instead of starting from
// zero the moment achievements shipped, using whatever running totals
// already happened to survive: user_lifetime_stats.game_wins/game_losses
// (issue #106, itself live since migration 0042 -- long before achievements
// existed), plus the live friendships/user_decklists tables (also
// long-lived, never pruned). See AchievementService::
// backfillWinCountProgressFromLifetimeStats()'s own docblock for exactly
// which achievements this covers.
//
// Deliberately NOT attempted here: every other count-based achievement
// (per-format wins, per-deck-type wins, color-majority wins, Mythic Hunter,
// Practice Makes Perfect, Grand Slam, Rematch, Marathon Session, Default
// Setting) depends on facts that only ever lived on individual `games`
// rows, which GameService::deleteStaleCompletedGames() permanently deletes
// 7 days after completion -- by the time achievements shipped and stayed
// live across many later deploys, every game still in the table has
// already been counted by the live per-completion hooks, so there is no
// surviving pre-achievement history left to recover for those.
//
// Deliberately silent: AchievementService is constructed with no
// NotificationService below, so every setProgressLevel() call's own
// onUnlocked() -> notifyAchievementUnlocked() is a no-op (see the `?->`
// throughout AchievementService) -- a returning player retroactively
// qualifying for several achievements at once shouldn't trigger a wall of
// "Achievement unlocked!" toasts/pushes for something that already
// happened, possibly years ago; their Achievements page simply shows the
// correct state next time they open it.
//
// A one-off maintenance script, not a cron job -- run it once by hand after
// deploying:
//   php bin/backfill_win_count_achievements.php
// Safe to run more than once, and safe to run against every registered
// user unconditionally: setProgressLevel()'s own GREATEST()-based merge
// means this can only ever raise an achievement's progress toward its
// target, never lower whatever's already been live-tracked -- for anyone
// who started playing AFTER achievements shipped, their own live progress
// already equals their all-time total, so backfilling is a harmless no-op
// for them.

$pdo = Connection::get();
$achievements = new AchievementService();
$friendships = new FriendshipService(new UserRepository(), new FriendshipRepository());
$decklists = new UserDecklistRepository();

$userIds = array_map(intval(...), $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN));
$statsStmt = $pdo->prepare('SELECT game_wins, game_losses FROM user_lifetime_stats WHERE user_id = :user_id');

foreach ($userIds as $userId) {
    $statsStmt->execute(['user_id' => $userId]);
    $stats = $statsStmt->fetch();
    $gameWins = $stats !== false ? (int) $stats['game_wins'] : 0;
    $gameLosses = $stats !== false ? (int) $stats['game_losses'] : 0;

    $achievements->backfillWinCountProgressFromLifetimeStats(
        $userId,
        $gameWins,
        $gameLosses,
        count($friendships->listFriends($userId)),
        count($decklists->listForUser($userId)),
    );
}

echo 'Backfilled win-count achievement progress for ' . count($userIds) . " user(s).\n";
