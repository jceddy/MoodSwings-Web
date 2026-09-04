<?php

declare(strict_types=1);

namespace MoodSwings\Matchmaking;

use MoodSwings\Database\Connection;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\OpenGameListingRepository;
use MoodSwings\Repository\UserRepository;

/**
 * Issue #116: an open lobby a player can post a game to instead of
 * naming specific friend opponents (see POST /games' own
 * opponent_user_ids), and any other discoverable, non-blocked player can
 * browse and join. Supports every format GameService::createGame()
 * itself does -- 'duel' (always 2 players), 'draft'/'standard' (the
 * creator picks an exact target of 2-4 at posting time), and
 * 'team'/'closed_team' (always exactly 4, teams assigned randomly once
 * full -- see joinOpenGame()'s own docblock for why).
 */
final class MatchmakingService
{
    private const ALLOWED_FORMATS = ['duel', 'draft', 'standard', 'team', 'closed_team'];

    public function __construct(
        private readonly OpenGameListingRepository $listings,
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
        private readonly GameService $games,
    ) {
    }

    /**
     * @param array $createGameParams the same named-argument shape
     *   GameService::createGame() itself takes, minus
     *   createdByUserId/userIds/partnerUserId/randomTeams/bot_* (none of
     *   which are known, or meaningful, until real players actually
     *   join -- team formats in particular never get a partner choice
     *   here at all, since one can't be known ahead of a stranger
     *   joining; see joinOpenGame())
     * @param int $targetPlayerCount how many total seats (including the
     *   creator) this listing needs before the game is created -- forced
     *   to the only legal value for 'duel' (2) and 'team'/'closed_team'
     *   (4) regardless of what's passed; only 'draft'/'standard' actually
     *   let the creator choose (2-4). $createGameParams' own
     *   'best_of_three' (issue #90 follow-up) is silently a no-op for
     *   'standard' unless this ends up exactly 2 -- see createGame()'s own
     *   docblock for why.
     */
    public function postOpenGame(int $userId, array $createGameParams, int $targetPlayerCount): int
    {
        $user = $this->users->findById($userId);

        if ($user === null || !(bool) $user['matchmaking_discoverable']) {
            throw new NotDiscoverableException('You must enable "discoverable for open games" in your settings before posting an open game.');
        }

        $format = (string) ($createGameParams['format'] ?? 'standard');

        if (!in_array($format, self::ALLOWED_FORMATS, true)) {
            throw new GameStateException("Open lobby games don't support the \"{$format}\" format.");
        }

        // Same "custom card/effect formats" opt-in (users.allow_custom_content)
        // GameService::createGame() itself enforces for every seated player --
        // checked here against the creator alone, since nobody else is known
        // yet. Without this, a non-opted-in creator could post a Chaos Draft
        // listing that then has to be hidden from every other non-opted-in
        // browser (see listOpenGames()) and would still blow up in
        // createGame() the moment someone finally joined it.
        if ((string) ($createGameParams['deck_type'] ?? 'structure') === 'chaos_draft' && !(bool) $user['allow_custom_content']) {
            throw new GameStateException(
                'Chaos Draft uses custom, fan-made card effects -- opt in first via Settings > "Allow custom card/effect formats" before posting one to the open lobby.'
            );
        }

        $targetPlayerCount = match ($format) {
            'duel' => 2,
            'team', 'closed_team' => 4,
            default => $targetPlayerCount,
        };

        if ($targetPlayerCount < 2 || $targetPlayerCount > 4) {
            throw new GameStateException('An open game needs between 2 and 4 total players.');
        }

        return $this->listings->create($userId, $createGameParams, $targetPlayerCount);
    }

    /**
     * Chaos Draft listings are additionally filtered out here for any
     * viewer who hasn't opted into "custom card/effect formats" (users.
     * allow_custom_content) -- otherwise they'd see (and could try to
     * join) a format whose fan-made effects they explicitly haven't
     * agreed to see, and joining would fail anyway once
     * GameService::createGame() itself re-checks every seated player
     * (see joinOpenGame()'s own mirrored check).
     */
    public function listOpenGames(int $viewerUserId): array
    {
        $listings = $this->listings->listOpenFor($viewerUserId);

        $viewer = $this->users->findById($viewerUserId);
        if ($viewer !== null && (bool) $viewer['allow_custom_content']) {
            return $listings;
        }

        return array_values(array_filter(
            $listings,
            static fn (array $listing): bool => ($listing['create_game_params']['deck_type'] ?? null) !== 'chaos_draft'
        ));
    }

    public function listMyOpenGames(int $userId): array
    {
        return $this->listings->listOpenCreatedBy($userId);
    }

    /** Listings $userId has joined but that are still waiting on more players -- see leaveOpenGame(). */
    public function listJoinedOpenGames(int $userId): array
    {
        return $this->listings->listJoinedBy($userId);
    }

    public function cancelOpenGame(int $userId, int $listingId): void
    {
        $listing = $this->listings->find($listingId);

        if ($listing === null || $listing['status'] !== 'open') {
            throw new OpenGameListingNotFoundException('No open listing found with that id.');
        }

        if ((int) $listing['created_by_user_id'] !== $userId) {
            throw new NotAuthorizedToCancelListingException('You can only cancel your own open game listing.');
        }

        $this->listings->markCancelled($listingId);
    }

    /**
     * Withdraws $userId's own earlier join from a listing that hasn't
     * started yet -- the only way to back out once committed, since
     * joining no longer immediately creates a game (a multi-seat
     * listing can sit "waiting for 1 more" for a while).
     */
    public function leaveOpenGame(int $userId, int $listingId): void
    {
        $listing = $this->listings->find($listingId);

        if ($listing === null || $listing['status'] !== 'open') {
            throw new OpenGameListingNotFoundException('No open listing found with that id.');
        }

        if (!in_array($userId, $this->listings->joinedUserIds($listingId), true)) {
            throw new OpenGameListingNotFoundException('You have not joined that listing.');
        }

        $this->listings->removeJoin($listingId, $userId);
    }

    /**
     * @return array{status: 'waiting', joined_count: int, target_player_count: int}|array{status: 'started', game_id: int}
     */
    public function joinOpenGame(int $joiningUserId, int $listingId): array
    {
        return $this->withListingLock($listingId, function () use ($joiningUserId, $listingId): array {
            $listing = $this->listings->find($listingId);

            if ($listing === null || $listing['status'] !== 'open') {
                throw new OpenGameListingNotFoundException('No open listing found with that id.');
            }

            $creatorUserId = (int) $listing['created_by_user_id'];

            if ($creatorUserId === $joiningUserId) {
                throw new OpenGameListingNotFoundException('You cannot join your own open game listing.');
            }

            // Deliberately reported the same as "not found" rather than
            // "you're blocked" -- same reasoning as FriendshipService's own
            // generic block-response message.
            $friendship = $this->friendships->findByPair($creatorUserId, $joiningUserId);

            if ($friendship !== null && $friendship['status'] === 'blocked') {
                throw new OpenGameListingNotFoundException('No open listing found with that id.');
            }

            // Same opt-in listOpenGames() already filters Chaos Draft
            // listings on -- enforced again here since a listing could in
            // principle still be joined directly by id (bypassing the
            // browse filter), and a rejection here is far cleaner than
            // letting the join through only to have createGame() blow up
            // and cancel the whole listing out from under everyone else
            // once the roster happens to fill.
            if (($listing['create_game_params']['deck_type'] ?? null) === 'chaos_draft') {
                $joiningUser = $this->users->findById($joiningUserId);
                if ($joiningUser === null || !(bool) $joiningUser['allow_custom_content']) {
                    throw new GameStateException(
                        'Chaos Draft uses custom, fan-made card effects -- opt in first via Settings > "Allow custom card/effect formats" before joining one.'
                    );
                }
            }

            $joinedUserIds = $this->listings->joinedUserIds($listingId);

            if (in_array($joiningUserId, $joinedUserIds, true)) {
                throw new AlreadyJoinedListingException('You have already joined this open game listing.');
            }

            $rosterUserIds = [$creatorUserId, ...$joinedUserIds, $joiningUserId];
            $targetPlayerCount = (int) $listing['target_player_count'];

            if (count($rosterUserIds) < $targetPlayerCount) {
                $this->listings->addJoin($listingId, $joiningUserId);

                return [
                    'status' => 'waiting',
                    'joined_count' => count($rosterUserIds) - 1,
                    'target_player_count' => $targetPlayerCount,
                ];
            }

            // This join completes the roster -- create the real game now,
            // seating the creator plus every recorded joiner. Team
            // formats never know a partner ahead of time from strangers
            // (the maintainer's own choice over letting the creator
            // hand-pick one, or pure join-order pairing), so this always
            // passes randomTeams: true instead of ever reading a
            // partner_user_id from create_game_params -- there never is
            // one there in the first place, since openGameCreateParamsFromRequestBody()
            // (index.php) never even accepts one for an open listing.
            $params = $listing['create_game_params'];
            $format = (string) ($params['format'] ?? 'standard');
            $isTeamFormat = in_array($format, ['team', 'closed_team'], true);

            try {
                $gameId = $this->games->createGame(
                    $creatorUserId,
                    $rosterUserIds,
                    $format,
                    (int) ($params['wins_needed'] ?? 3),
                    (string) ($params['deck_type'] ?? 'structure'),
                    $params['decklist_text'] ?? null,
                    $params['duel_deck_rules'] ?? null,
                    null,
                    $params['quick_draft_pool_source'] ?? null,
                    $params['quick_draft_custom_pool_text'] ?? null,
                    $params['winston_draft_pool_source'] ?? null,
                    $params['winston_draft_custom_pool_text'] ?? null,
                    $params['grid_draft_pool_source'] ?? null,
                    $params['grid_draft_custom_pool_text'] ?? null,
                    $params['saved_decklist_id'] ?? null,
                    (bool) ($params['default_selections_mode'] ?? false),
                    null,
                    null,
                    $isTeamFormat,
                    $params['rotisserie_draft_pool_source'] ?? null,
                    $params['rotisserie_draft_custom_pool_text'] ?? null,
                    (int) ($params['rotisserie_draft_cutoff_count'] ?? 14),
                    $params['tiered_rotisserie_draft_mode'] ?? null,
                    $params['tiered_rotisserie_draft_tiers'] ?? null,
                    false,
                    (bool) ($params['best_of_three'] ?? false),
                    (bool) ($params['allow_sideboarding'] ?? false),
                );
            } catch (GameStateException $e) {
                // The creator's own choices turned out not to be valid
                // together (e.g. a saved decklist that's since been
                // deleted) -- retiring the listing rather than leaving
                // something permanently broken joinable for the next
                // person to hit the same failure.
                $this->listings->markCancelled($listingId);

                throw $e;
            }

            $this->listings->addJoin($listingId, $joiningUserId);
            $this->listings->markClaimed($listingId, $joiningUserId, $gameId);

            return ['status' => 'started', 'game_id' => $gameId];
        });
    }

    /**
     * Mirrors GameService::withGameLock()'s own advisory-lock pattern,
     * scoped to one listing instead of one game -- keeps "count how many
     * have joined, maybe create the game" atomic across two people
     * clicking Join on the same last-open seat at the same moment. Without
     * it both could see the roster one seat short and both proceed to
     * create a game, overfilling it.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withListingLock(int $listingId, callable $fn): mixed
    {
        $pdo = Connection::get();
        $lockName = "moodswings_open_game_listing:{$listingId}";

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$lockName, 10]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new GameStateException('This listing is busy -- try again');
        }

        try {
            return $fn();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }
}
