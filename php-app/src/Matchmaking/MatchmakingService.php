<?php

declare(strict_types=1);

namespace MoodSwings\Matchmaking;

use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\OpenGameListingRepository;
use MoodSwings\Repository\UserRepository;

/**
 * Issue #116, first cut: an open lobby a player can post a game to
 * instead of naming specific friend opponents (see POST /games' own
 * opponent_user_ids), and any other discoverable, non-blocked player can
 * browse and join. Scoped to exactly-two-player games -- 'duel', or
 * 'draft' played 2-player -- see the 0198 migration's own docblock for
 * why 'standard'/team formats are deferred.
 */
final class MatchmakingService
{
    /**
     * format values an open listing may use. 'standard' (variable 2+
     * player, no fixed roster size) and the team formats (need a full
     * known 4-player roster, including a chosen partner, before anything
     * can start) are deferred -- see the 0198 migration's own docblock.
     */
    private const ALLOWED_FORMATS = ['duel', 'draft'];

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
     *   which are known, or meaningful, until a real second player joins)
     */
    public function postOpenGame(int $userId, array $createGameParams): int
    {
        $user = $this->users->findById($userId);

        if ($user === null || !(bool) $user['matchmaking_discoverable']) {
            throw new NotDiscoverableException('You must enable "discoverable for open games" in your settings before posting an open game.');
        }

        $format = (string) ($createGameParams['format'] ?? 'standard');

        if (!in_array($format, self::ALLOWED_FORMATS, true)) {
            throw new GameStateException('Open lobby games only support the "duel"/"draft" formats for now');
        }

        return $this->listings->create($userId, $createGameParams);
    }

    public function listOpenGames(int $viewerUserId): array
    {
        return $this->listings->listOpenFor($viewerUserId);
    }

    public function listMyOpenGames(int $userId): array
    {
        return $this->listings->listOpenCreatedBy($userId);
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
     * @return int the newly-created game's id
     */
    public function joinOpenGame(int $joiningUserId, int $listingId): int
    {
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

        $params = $listing['create_game_params'];

        try {
            $gameId = $this->games->createGame(
                $creatorUserId,
                [$creatorUserId, $joiningUserId],
                (string) ($params['format'] ?? 'standard'),
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
                false,
                $params['rotisserie_draft_pool_source'] ?? null,
                $params['rotisserie_draft_custom_pool_text'] ?? null,
                (int) ($params['rotisserie_draft_cutoff_count'] ?? 14),
                $params['tiered_rotisserie_draft_mode'] ?? null,
                $params['tiered_rotisserie_draft_tiers'] ?? null,
                false,
            );
        } catch (GameStateException $e) {
            // The creator's own choices turned out not to be valid
            // together (e.g. a saved decklist that's since been deleted)
            // -- retiring the listing rather than leaving something
            // permanently broken joinable for the next person to hit the
            // same failure.
            $this->listings->markCancelled($listingId);

            throw $e;
        }

        $this->listings->markClaimed($listingId, $joiningUserId, $gameId);

        return $gameId;
    }
}
