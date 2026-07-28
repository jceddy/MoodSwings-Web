<?php

declare(strict_types=1);

namespace MoodSwings\Presence;

use MoodSwings\Repository\SessionRepository;

/**
 * Online/presence indicator (issue #110). "Online" is derived cheaply
 * from sessions.last_seen_at -- already touched on every authenticated
 * request (see AuthService::currentUser()/SessionRepository::touch()) --
 * rather than a new heartbeat/websocket signal: a user counts as online
 * if any of their currently-valid sessions were active within the last
 * ONLINE_THRESHOLD_SECONDS. This is coarser than "has an open tab right
 * now" but needs no new infrastructure, and both the lobby and the board
 * already poll every 4 seconds while a page is open, so it tracks
 * genuinely active use closely in practice.
 *
 * A user can opt out of sharing this signal entirely (users.share_presence,
 * see the User info page) -- that reads as a third, distinct 'hidden'
 * status to everyone else, never silently folded into 'offline', so a
 * viewer can tell "this person turned presence off" apart from "this
 * person just isn't active right now."
 */
final class PresenceService
{
    private const ONLINE_THRESHOLD_SECONDS = 120;

    public function __construct(
        private readonly SessionRepository $sessions,
    ) {
    }

    /**
     * @param array<int, bool> $sharePresenceByUserId user_id => whether that
     *        user currently allows their presence to be shown at all
     *        (users.share_presence) -- callers already have this from
     *        whatever query fetched the users in question (they already
     *        join `users`), so it's passed in rather than re-fetched here.
     * @return array<int, string> user_id => 'online'|'offline'|'hidden'
     */
    public function statusesFor(array $sharePresenceByUserId): array
    {
        $visibleUserIds = array_keys(array_filter($sharePresenceByUserId));
        $lastSeenAt = $visibleUserIds === [] ? [] : $this->sessions->lastSeenAtForUsers($visibleUserIds);
        $now = time();

        $statuses = [];
        foreach ($sharePresenceByUserId as $userId => $sharePresence) {
            if (!$sharePresence) {
                $statuses[$userId] = 'hidden';
                continue;
            }

            $lastSeen = $lastSeenAt[$userId] ?? null;
            $statuses[$userId] = $lastSeen !== null && ($now - strtotime($lastSeen)) <= self::ONLINE_THRESHOLD_SECONDS
                ? 'online'
                : 'offline';
        }

        return $statuses;
    }
}
