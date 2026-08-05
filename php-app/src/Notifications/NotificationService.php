<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

use MoodSwings\Repository\NotificationCooldownRepository;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\QueuedNotificationRepository;

/**
 * Fans a notification out to every configured NotificationChannel
 * (PushNotificationChannel, Discord\DiscordNotificationChannel) --
 * formerly PushNotificationService, back when browser push was the only
 * channel that existed (issue #108's first pass). The "notify me
 * when..." preferences (notify_your_turn/notify_friend_request/
 * notify_game_finished) and the 5-minute cooldown/queue below apply once
 * per event, to every linked channel at once, rather than each channel
 * separately deciding whether to bother -- a player who's linked both
 * gets at most one round of notifications (one push, one Discord DM) per
 * (user, scope) every 5 minutes, not one per channel.
 *
 * Every public notifyXxx() method is a fire-and-forget best effort: a
 * channel being unreachable, unconfigured, or the user having nothing
 * linked for it must never fail the request that triggered it (sending
 * an invite, finishing a game, taking a turn) -- so every failure is
 * swallowed here rather than thrown.
 *
 * notify() also enforces a 5-minute cooldown (COOLDOWN_SECONDS, see
 * NotificationCooldownRepository), scoped per (user, NotificationScope)
 * rather than globally per user -- a player active in several games at
 * once can still get more than one round of notifications within 5
 * minutes overall, but never more than one within 5 minutes about any
 * one specific game (see NotificationScope's own docblock). Rather than
 * simply dropping a notification that arrives during its scope's
 * cooldown, it's queued instead (QueuedNotificationRepository, one row
 * per (user, scope), replacing whatever was queued for that same scope
 * before) and delivered later by flushQueuedNotifications() -- see
 * bin/send_queued_notifications.php, meant to run every ~15 minutes via
 * cron. GameService::clearQueuedNotificationForGamePlayer()/
 * clearQueuedForGame()/clearQueuedFriendRequest() delete a queued row
 * early if the player takes the action it would have reminded them about
 * before the flush ever runs.
 *
 * A user can opt out of this cooldown/queue behavior entirely via their
 * own disable_cooldown preference (migration 0051, off by default) --
 * with it on, notify() delivers every eligible event immediately instead
 * of throttling to one per scope every 5 minutes, for someone who'd
 * rather see everything as it happens than have a burst of activity
 * collapsed down to one delayed summary.
 */
final class NotificationService
{
    private const COOLDOWN_SECONDS = 300;

    /** @param array<int, NotificationChannel> $channels */
    public function __construct(
        private readonly NotificationPreferenceRepository $preferences,
        private readonly QueuedNotificationRepository $queuedNotifications,
        private readonly NotificationCooldownRepository $cooldowns,
        private readonly array $channels,
    ) {
    }

    /**
     * $tag distinguishes GameService's several "waiting on you" cases for
     * the same game (an ordinary turn advance, a Compulsion-style pending
     * decision, a team turn_order/draw_recipient decision, closed_team's
     * pregame card pass, a draft match's first-player-choice freeze) so
     * the OS never collapses two different ones into a single
     * notification -- see GameService::notifyGamePlayersItsYourTurn().
     * The cooldown/queue scope is coarser than $tag though -- every one of
     * these, plus notifyGameFinished() below, shares one scope per game
     * (NotificationScope::forGame()), not one per tag.
     */
    public function notifyYourTurn(int $userId, int $gameId, string $body, string $tag = 'turn'): void
    {
        $this->notify($userId, NotificationScope::forGame($gameId), 'notify_your_turn', [
            'title' => "It's your turn",
            'body' => $body,
            'url' => "/game/?id={$gameId}",
            'tag' => "game-{$gameId}-{$tag}",
        ]);
    }

    public function notifyFriendRequest(int $userId, string $requesterUsername): void
    {
        $this->notify($userId, NotificationScope::FRIEND_REQUEST, 'notify_friend_request', [
            'title' => 'Friend request',
            'body' => "{$requesterUsername} wants to be your friend.",
            // There's no standalone /friends/ page -- the friends UI is a
            // <dialog> inside the lobby (web-static/game/index.html) --
            // so this has to land on the lobby itself and ask it to open
            // that dialog, the same way ?spectate_game_id already asks
            // the lobby to jump straight into spectator mode. See
            // game.js's own handling of 'open_friends' near the bottom of
            // its startup IIFE.
            'url' => '/game/?open_friends=1',
            'tag' => 'friend-request',
        ]);
    }

    public function notifyGameFinished(int $userId, int $gameId, string $resultSummary): void
    {
        $this->notify($userId, NotificationScope::forGame($gameId), 'notify_game_finished', [
            'title' => 'Game finished',
            'body' => $resultSummary,
            'url' => "/game/?id={$gameId}",
            'tag' => "game-{$gameId}-finished",
        ]);
    }

    /**
     * In-game chat (issue #109) -- shares NotificationScope::forGame()
     * with notifyYourTurn()/notifyGameFinished() above rather than its
     * own scope, the same way those two already share one cooldown/queue
     * bucket per game instead of one each: a player active in a game
     * gets at most one round of notifications about it every 5 minutes
     * regardless of whether it's their turn, the game just ended, or a
     * new message arrived, not a separate 5-minute allowance per kind of
     * event. $tag still distinguishes it in the OS notification list the
     * same way notifyYourTurn()'s own per-case tags do.
     */
    public function notifyNewChatMessage(int $userId, int $gameId, string $senderUsername, string $messagePreview): void
    {
        $this->notify($userId, NotificationScope::forGame($gameId), 'notify_chat_message', [
            'title' => "{$senderUsername} says...",
            'body' => $messagePreview,
            'url' => "/game/?id={$gameId}",
            'tag' => "game-{$gameId}-chat",
        ]);
    }

    /** GameService::clearQueuedNotificationForGamePlayer()'s own passthrough -- see that method's docblock. */
    public function clearQueuedForGame(int $userId, int $gameId): void
    {
        $this->queuedNotifications->clearForGameIfMatches($userId, $gameId);
    }

    /** The `/friends/respond` route's own passthrough, mirroring clearQueuedForGame() for the one non-game scope. */
    public function clearQueuedFriendRequest(int $userId): void
    {
        $this->queuedNotifications->clearFriendRequestForUser($userId);
    }

    /**
     * bin/send_queued_notifications.php's own cron flush -- delivers
     * whatever's queued and old enough to flush, clearing each row as it
     * goes. "Old enough" means queued at least COOLDOWN_SECONDS ago
     * (see QueuedNotificationRepository::dueForFlush()'s own docblock) --
     * a row queued more recently is left alone for a later flush, so a
     * cron run landing moments after something was queued doesn't
     * deliver it before the player's had a fair chance to clear it
     * themselves by taking the action it was reminding them about.
     *
     * Otherwise bypasses the cooldown check entirely (that's the whole
     * point: this IS the delayed delivery), but still re-checks the
     * relevant preference (the user may have turned it off between
     * queueing and now) and still fans out to every configured channel
     * the same way an ordinary send does. Still stamps the scope's
     * cooldown forward on an actual delivery, so a live event for that
     * same scope immediately afterward doesn't turn right around and
     * re-queue on top of what was just delivered.
     *
     * @return int how many notifications were actually delivered (to at least one channel)
     */
    public function flushQueuedNotifications(): int
    {
        $sent = 0;

        foreach ($this->queuedNotifications->dueForFlush(self::COOLDOWN_SECONDS) as $row) {
            $userId = $row['user_id'];
            $scope = $row['scope'];

            // One row throwing (e.g. a stale WebPush call, a DB hiccup)
            // must not abort the flush for every other queued row behind
            // it in this same cron run.
            try {
                if ($this->preferences->forUser($userId)[$row['preference_key']]) {
                    $delivered = $this->deliver($userId, [
                        'title' => $row['title'],
                        'body' => $row['body'],
                        'url' => $row['url'],
                        'tag' => $row['tag'],
                    ]);

                    if ($delivered) {
                        $this->cooldowns->markNotified($userId, $scope);
                        $sent++;
                    }
                }

                $this->queuedNotifications->delete($userId, $scope);
            } catch (\Throwable $e) {
                $this->logError("Failed to flush queued notification (user {$userId}, scope {$scope}): " . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    private function notify(int $userId, string $scope, string $preferenceKey, array $payload): void
    {
        // The class docblock above promises every failure here is
        // swallowed, not thrown -- but nothing below actually enforced
        // that (a missing/out-of-date notification_* table would
        // otherwise propagate straight out of
        // notifyFriendRequest()/notifyYourTurn()/notifyGameFinished() and
        // fail the request that triggered it, e.g. sending a friend
        // invite). Catching here is what actually makes the guarantee
        // true -- each individual channel's own send() is also expected
        // to swallow its own failures (see NotificationChannel's own
        // docblock), so this is a backstop, not the primary defense.
        try {
            $preferences = $this->preferences->forUser($userId);
            if (!$preferences[$preferenceKey]) {
                $this->logError("Not notifying (user {$userId}, scope {$scope}): preference '{$preferenceKey}' is off");
                return;
            }

            // disable_cooldown (migration 0051) opts a user out of the
            // cooldown/queue fallback below entirely -- every notification
            // they're otherwise eligible for is delivered immediately, even
            // several in quick succession, rather than being throttled to
            // at most one per scope every 5 minutes. wasNotifiedRecently()
            // is skipped outright rather than checked-and-ignored, so a
            // user with this on never queues a notification behind
            // another one either.
            if (!$preferences['disable_cooldown'] && $this->cooldowns->wasNotifiedRecently($userId, $scope, self::COOLDOWN_SECONDS)) {
                $this->queuedNotifications->enqueue($userId, $scope, $preferenceKey, $payload);
                $this->logError("Queued notification instead of sending (user {$userId}, scope {$scope}): within the cooldown window");
                return;
            }

            // Only stamp the cooldown -- and clear anything queued for
            // this scope -- once at least one channel actually delivered.
            // Marking it beforehand (as this used to, back when there was
            // only one channel) would mean an event with nothing to
            // deliver to on ANY channel still silently burned the
            // 5-minute cooldown, demoting the *next* attempt to a queued
            // one instead of a live send.
            if ($this->deliver($userId, $payload)) {
                $this->cooldowns->markNotified($userId, $scope);
                $this->queuedNotifications->delete($userId, $scope);
            }
        } catch (\Throwable $e) {
            $this->logError("Failed to send notification (user {$userId}, scope {$scope}): " . $e->getMessage());
        }
    }

    /**
     * Attempts every configured channel independently -- one channel
     * throwing (rather than returning false, as NotificationChannel's own
     * contract asks) must not stop the others from being tried.
     *
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    private function deliver(int $userId, array $payload): bool
    {
        $delivered = false;

        foreach ($this->channels as $channel) {
            try {
                if ($channel->send($userId, $payload)) {
                    $delivered = true;
                }
            } catch (\Throwable $e) {
                $this->logError('Notification channel ' . $channel::class . " failed (user {$userId}): " . $e->getMessage());
            }
        }

        return $delivered;
    }

    /** Same convention as index.php's logMailError() -- a dedicated log file rather than the general PHP error log. */
    private function logError(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$message}\n";
        error_log($line, 3, dirname(__DIR__) . '/notification-errors.log');
    }
}
