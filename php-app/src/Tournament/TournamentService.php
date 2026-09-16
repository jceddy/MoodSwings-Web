<?php

declare(strict_types=1);

namespace MoodSwings\Tournament;

use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\TournamentMatchRepository;
use MoodSwings\Repository\TournamentParticipantRepository;
use MoodSwings\Repository\TournamentRepository;
use MoodSwings\Repository\UserRepository;

/**
 * Issue #91: a tournament system on top of the existing single-game/
 * best-of-three/draft match machinery -- see database/migrations/0334's
 * own docblock for the schema this works against, and
 * TournamentBracketBuilder for the pure bracket-shape math this class
 * turns into real rows and real games.
 *
 * Scoped to 1v1 matchups only for v1: every tournament match uses
 * exactly 2 seats (format 'duel', or 'draft'/'standard' played
 * 2-player), the same restriction open_game_listings' own first cut
 * (migration 0198) placed on itself for exactly the same reason -- team
 * formats need a full known roster (a chosen partner) that has no
 * natural meaning against a lone bracket opponent.
 *
 * Double elimination accepts any participant count >= 4, same as single
 * elimination -- a non-power-of-two field needs byes in the LOSERS
 * bracket too, not just the winners bracket (a winners-bracket bye
 * produces no loser to drop down at all), which
 * TournamentBracketBuilder::buildDoubleElimination() handles by
 * computing, structurally, exactly how many real entrants (0, 1, or 2)
 * reach every losers-bracket slot; see that method's own docblock for
 * the exact recursion. A slot with only 1 real entrant is a genuine bye
 * once that lone participant actually arrives (advanceInto() below
 * resolves it the moment its one and only inbound edge fires, rather
 * than waiting forever for a second slot that will never fill); a slot
 * with 0 is never even created.
 */
final class TournamentService implements TournamentMatchObserver
{
    private const BRACKET_TYPES = ['single_elimination', 'double_elimination', 'swiss'];
    private const REGISTRATION_MODES = ['invite_only', 'open'];

    /** Formats a tournament match may use -- always exactly 2 players, so the team formats are excluded. */
    private const ALLOWED_FORMATS = ['duel', 'draft', 'standard'];

    public function __construct(
        private readonly TournamentRepository $tournaments,
        private readonly TournamentParticipantRepository $participants,
        private readonly TournamentMatchRepository $matches,
        private readonly TournamentBracketBuilder $bracketBuilder,
        private readonly GameService $games,
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
    ) {
    }

    /**
     * @param array $matchParams the same named-argument shape
     *   GameService::createGame() itself takes, minus createdByUserId/
     *   userIds/partnerUserId/randomTeams/bot_* -- identical in spirit
     *   to open_game_listings.create_game_params, just fixed once for
     *   the whole event. 'format' must be one of self::ALLOWED_FORMATS.
     * @param int[] $inviteUserIds only meaningful for registrationMode
     *   'invite_only' -- seated as 'invited' rows the invitee still has
     *   to accept (see acceptInvite()); ignored for 'open'.
     */
    public function createTournament(
        int $createdByUserId,
        string $name,
        string $bracketType,
        string $registrationMode,
        array $matchParams,
        ?int $swissRoundCount,
        int $minParticipants,
        ?int $maxParticipants,
        array $inviteUserIds = [],
    ): int {
        if (!in_array($bracketType, self::BRACKET_TYPES, true)) {
            throw new TournamentStateException("Unknown tournament bracket type \"{$bracketType}\"");
        }
        if (!in_array($registrationMode, self::REGISTRATION_MODES, true)) {
            throw new TournamentStateException("Unknown tournament registration mode \"{$registrationMode}\"");
        }
        if (trim($name) === '') {
            throw new TournamentStateException('A tournament needs a name');
        }
        $format = (string) ($matchParams['format'] ?? 'standard');
        if (!in_array($format, self::ALLOWED_FORMATS, true)) {
            throw new TournamentStateException("Tournament matches don't support the \"{$format}\" format");
        }
        if ($registrationMode === 'open') {
            if ($maxParticipants === null) {
                throw new TournamentStateException('An open-registration tournament needs a maximum participant count');
            }
            // Same discoverability gate MatchmakingService::postOpenGame()
            // already requires of an open-lobby listing's own creator --
            // without it, listOpenFor()'s own matchmaking_discoverable
            // filter would silently make this tournament unjoinable by
            // anyone, with no indication why.
            $creator = $this->users->findById($createdByUserId);
            if ($creator === null || !(bool) $creator['matchmaking_discoverable']) {
                throw new TournamentStateException('You must enable "Discoverable for open games" in Settings before creating an open-registration tournament.');
            }
        }
        if ($minParticipants < 2) {
            throw new TournamentStateException('A tournament needs at least 2 participants');
        }
        if ($bracketType === 'double_elimination' && $minParticipants < 4) {
            $minParticipants = 4;
        }
        if ($maxParticipants !== null && $maxParticipants < $minParticipants) {
            throw new TournamentStateException('max_participants cannot be less than min_participants');
        }

        $tournamentId = $this->tournaments->create(
            $createdByUserId,
            trim($name),
            $bracketType,
            $registrationMode,
            $matchParams,
            $swissRoundCount,
            $minParticipants,
            $maxParticipants,
        );

        $this->participants->add($tournamentId, $createdByUserId, 'joined');

        if ($registrationMode === 'invite_only') {
            foreach (array_unique($inviteUserIds) as $inviteeUserId) {
                if ($inviteeUserId === $createdByUserId) {
                    continue;
                }
                if ($this->users->findById($inviteeUserId) === null) {
                    throw new TournamentStateException("No such user id {$inviteeUserId}");
                }
                $this->participants->add($tournamentId, $inviteeUserId, 'invited');
            }
        }

        return $tournamentId;
    }

    public function invite(int $tournamentId, int $inviterUserId, int $inviteeUserId): void
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireCreator($tournament, $inviterUserId);
        $this->requireRegistrationOpen($tournament);
        if ($tournament['registration_mode'] !== 'invite_only') {
            throw new TournamentStateException('Only an invite-only tournament can invite specific players');
        }
        if ($this->participants->findForUser($tournamentId, $inviteeUserId) !== null) {
            throw new TournamentStateException('That user is already invited or joined');
        }
        if ($this->users->findById($inviteeUserId) === null) {
            throw new TournamentStateException("No such user id {$inviteeUserId}");
        }

        $this->participants->add($tournamentId, $inviteeUserId, 'invited');
    }

    public function acceptInvite(int $tournamentId, int $userId): void
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireRegistrationOpen($tournament);
        $participant = $this->participants->findForUser($tournamentId, $userId);
        if ($participant === null || $participant['status'] !== 'invited') {
            throw new TournamentStateException('You have no pending invite to this tournament');
        }
        $this->assertRoomAvailable($tournament);

        $this->participants->updateStatus((int) $participant['id'], 'joined');
    }

    public function declineInvite(int $tournamentId, int $userId): void
    {
        $participant = $this->participants->findForUser($tournamentId, $userId);
        if ($participant === null || $participant['status'] !== 'invited') {
            throw new TournamentStateException('You have no pending invite to this tournament');
        }

        $this->participants->updateStatus((int) $participant['id'], 'declined');
    }

    public function joinOpenTournament(int $tournamentId, int $userId): void
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireRegistrationOpen($tournament);
        if ($tournament['registration_mode'] !== 'open') {
            throw new TournamentStateException('This tournament is invite-only');
        }
        if ($this->participants->findForUser($tournamentId, $userId) !== null) {
            throw new TournamentStateException('You have already joined this tournament');
        }
        $creator = $this->users->findById((int) $tournament['created_by_user_id']);
        if ($creator === null || !(bool) $creator['matchmaking_discoverable']) {
            throw new TournamentStateException('This tournament is no longer open to new joiners');
        }
        $friendship = $this->friendships->findByPair((int) $tournament['created_by_user_id'], $userId);
        if ($friendship !== null && $friendship['status'] === 'blocked') {
            throw new NotAuthorizedForTournamentException('You cannot join this tournament');
        }
        $this->assertRoomAvailable($tournament);

        $this->participants->add($tournamentId, $userId, 'joined');
    }

    public function withdraw(int $tournamentId, int $userId): void
    {
        $tournament = $this->requireTournament($tournamentId);
        if ($tournament['status'] !== 'registration') {
            throw new TournamentStateException('You can only withdraw before the tournament has started');
        }
        $participant = $this->participants->findForUser($tournamentId, $userId);
        if ($participant === null) {
            throw new TournamentStateException('You are not part of this tournament');
        }

        $this->participants->updateStatus((int) $participant['id'], 'withdrawn');
    }

    public function cancelTournament(int $tournamentId, int $requestingUserId): void
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireCreator($tournament, $requestingUserId);
        if ($tournament['status'] === 'completed' || $tournament['status'] === 'cancelled') {
            throw new TournamentStateException('This tournament has already finished');
        }

        $this->tournaments->markCancelled($tournamentId);
    }

    public function startTournament(int $tournamentId, int $requestingUserId): void
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireCreator($tournament, $requestingUserId);
        $this->requireRegistrationOpen($tournament);

        $joined = array_values(array_filter(
            $this->participants->listForTournament($tournamentId),
            static fn (array $p): bool => $p['status'] === 'joined'
        ));
        $count = count($joined);
        if ($count < (int) $tournament['min_participants']) {
            throw new TournamentStateException("This tournament needs at least {$tournament['min_participants']} joined participants to start (has {$count})");
        }

        // Random seeding: nothing about registration/invite-accept order
        // should predict bracket strength.
        shuffle($joined);
        foreach ($joined as $seedIndex => $participant) {
            $this->participants->setSeed((int) $participant['id'], $seedIndex + 1);
        }
        $seedToParticipantId = [];
        foreach ($joined as $seedIndex => $participant) {
            $seedToParticipantId[$seedIndex + 1] = (int) $participant['id'];
        }

        $this->tournaments->markStarted($tournamentId);

        match ($tournament['bracket_type']) {
            'swiss' => $this->startSwiss($tournament, $seedToParticipantId),
            default => $this->materializeEliminationBracket($tournament, $seedToParticipantId),
        };
    }

    private function materializeEliminationBracket(array $tournament, array $seedToParticipantId): void
    {
        $tournamentId = (int) $tournament['id'];
        $plan = $tournament['bracket_type'] === 'double_elimination'
            ? $this->bracketBuilder->buildDoubleElimination(count($seedToParticipantId))
            : $this->bracketBuilder->buildSingleElimination(count($seedToParticipantId));

        // Phase 1: insert every round and every match, recording each
        // (bracket, round, slot) coordinate's real inserted id so phase 2
        // can translate the plan's own coordinate-based advance edges
        // into real foreign keys.
        $matchIdByCoordinate = [];
        $matchRowById = [];
        foreach ($plan['rounds'] as $round) {
            $roundId = $this->matches->createRound($tournamentId, $round['bracket'], $round['round_number']);
            foreach ($round['matches'] as $match) {
                $participant1Id = $round['round_number'] === 1 && $match['seed1'] !== null ? $seedToParticipantId[$match['seed1']] : null;
                $participant2Id = $round['round_number'] === 1 && $match['seed2'] !== null ? $seedToParticipantId[$match['seed2']] : null;

                $matchId = $this->matches->createMatch($tournamentId, $roundId, $match['slot'], $participant1Id, $participant2Id, 'pending');
                $coordinate = "{$round['bracket']}:{$round['round_number']}:{$match['slot']}";
                $matchIdByCoordinate[$coordinate] = $matchId;
                $matchRowById[$matchId] = ['participant1_id' => $participant1Id, 'participant2_id' => $participant2Id];
            }
        }

        // Phase 2: wire advance targets (a match may have both a winner
        // edge and, for double elimination's winners bracket, a loser
        // edge -- combine both into one UPDATE per match).
        $targets = [];
        foreach ($plan['advances'] as $advance) {
            $fromKey = "{$advance['from']['bracket']}:{$advance['from']['round_number']}:{$advance['from']['slot']}";
            $toKey = "{$advance['to']['bracket']}:{$advance['to']['round_number']}:{$advance['to']['slot']}";
            $fromMatchId = $matchIdByCoordinate[$fromKey];
            $toMatchId = $matchIdByCoordinate[$toKey];
            $targets[$fromMatchId][$advance['on']] = ['match_id' => $toMatchId, 'slot' => $advance['to']['player']];
        }
        foreach ($targets as $fromMatchId => $target) {
            $this->matches->setAdvanceTargets(
                $fromMatchId,
                $target['winner']['match_id'] ?? null,
                $target['winner']['slot'] ?? null,
                $target['loser']['match_id'] ?? null,
                $target['loser']['slot'] ?? null,
            );
        }

        // Phase 3: resolve round-1 byes (winners-bracket round 1 for
        // both single and double elimination -- the only round whose
        // participants were assigned directly above rather than via an
        // advance edge) and create every round-1 game that already has
        // both participants. Iterated in $plan['rounds']' own insertion
        // order (winners round 1 first), so by the time this loop
        // reaches any losers-bracket match, every winners-bracket
        // round-1 bye above it has already cascaded through
        // resolveMatchResult()/advanceInto() and filled whatever losers
        // slot it feeds -- a losers-bracket match's OWN entry in
        // $matchRowById is deliberately left at its stale phase-1
        // snapshot (participant1_id/participant2_id null, since losers
        // matches never get seeds directly), so this loop correctly
        // does nothing more for one that's already been resolved (or
        // already had its game started) by that cascade.
        foreach ($matchRowById as $matchId => $row) {
            if ($row['participant1_id'] !== null && $row['participant2_id'] === null) {
                $this->resolveMatchResult($tournamentId, $this->matches->find($matchId), (int) $row['participant1_id'], isBye: true);
            } elseif ($row['participant1_id'] === null && $row['participant2_id'] !== null) {
                $this->resolveMatchResult($tournamentId, $this->matches->find($matchId), (int) $row['participant2_id'], isBye: true);
            } elseif ($row['participant1_id'] !== null && $row['participant2_id'] !== null) {
                $this->startMatchGame($tournament, $this->matches->find($matchId));
            }
        }
    }

    private function startSwiss(array $tournament, array $seedToParticipantId): void
    {
        $participantIds = array_values($seedToParticipantId);
        $wins = array_fill_keys($participantIds, 0);

        $pairing = $this->bracketBuilder->swissPairings($wins, alreadyPlayed: []);
        $this->createSwissRound($tournament, 1, $pairing);
    }

    private function createSwissRound(array $tournament, int $roundNumber, array $pairing): void
    {
        $tournamentId = (int) $tournament['id'];
        $roundId = $this->matches->createRound($tournamentId, 'swiss', $roundNumber);

        $slot = 1;
        foreach ($pairing['pairs'] as [$p1, $p2]) {
            $matchId = $this->matches->createMatch($tournamentId, $roundId, $slot, $p1, $p2, 'pending');
            $slot++;
            $this->startMatchGame($tournament, $this->matches->find($matchId));
        }
        if ($pairing['bye'] !== null) {
            $matchId = $this->matches->createMatch($tournamentId, $roundId, $slot, $pairing['bye'], null, 'pending');
            $this->matches->markBye($matchId, $pairing['bye']);
        }
    }

    /** Actually calls GameService::createGame() for a tournament_matches row that now has both participants, and records the resulting game. */
    private function startMatchGame(array $tournament, array $tournamentMatch): void
    {
        $participant1 = $this->participants->find((int) $tournamentMatch['participant1_id']);
        $participant2 = $this->participants->find((int) $tournamentMatch['participant2_id']);
        $user1Id = (int) $participant1['user_id'];
        $user2Id = (int) $participant2['user_id'];
        $params = $tournament['match_params'];
        $format = (string) ($params['format'] ?? 'standard');

        try {
            $gameId = $this->games->createGame(
                createdByUserId: $user1Id,
                userIds: [$user1Id, $user2Id],
                format: $format,
                winsNeeded: (int) ($params['wins_needed'] ?? 3),
                deckType: (string) ($params['deck_type'] ?? 'structure'),
                decklistText: $params['decklist_text'] ?? null,
                duelDeckRules: $params['duel_deck_rules'] ?? null,
                quickDraftPoolSource: $params['quick_draft_pool_source'] ?? null,
                quickDraftCustomPoolText: $params['quick_draft_custom_pool_text'] ?? null,
                winstonDraftPoolSource: $params['winston_draft_pool_source'] ?? null,
                winstonDraftCustomPoolText: $params['winston_draft_custom_pool_text'] ?? null,
                gridDraftPoolSource: $params['grid_draft_pool_source'] ?? null,
                gridDraftCustomPoolText: $params['grid_draft_custom_pool_text'] ?? null,
                defaultSelectionsMode: (bool) ($params['default_selections_mode'] ?? false),
                rotisserieDraftPoolSource: $params['rotisserie_draft_pool_source'] ?? null,
                rotisserieDraftCustomPoolText: $params['rotisserie_draft_custom_pool_text'] ?? null,
                rotisserieDraftCutoffCount: (int) ($params['rotisserie_draft_cutoff_count'] ?? 14),
                tieredRotisserieDraftMode: $params['tiered_rotisserie_draft_mode'] ?? null,
                tieredRotisserieDraftTiers: $params['tiered_rotisserie_draft_tiers'] ?? null,
                bestOfThree: (bool) ($params['best_of_three'] ?? false),
                allowSideboarding: (bool) ($params['allow_sideboarding'] ?? false),
                diagnosticMode: (bool) ($params['diagnostic_mode'] ?? false),
                timeoutMinutes: isset($params['timeout_minutes']) ? (int) $params['timeout_minutes'] : null,
                timeoutAction: isset($params['timeout_action']) ? (string) $params['timeout_action'] : null,
                totalTimeLimitMinutes: isset($params['total_time_limit_minutes']) ? (int) $params['total_time_limit_minutes'] : null,
                synchronousMode: (bool) ($params['synchronous_mode'] ?? false),
            );
        } catch (GameStateException $e) {
            throw new TournamentStateException("Couldn't start a tournament match between the fixed match settings and these two players: {$e->getMessage()}", previous: $e);
        }

        $wrapperIds = $this->games->gameMatchWrapperIds($gameId);
        $this->matches->markGameCreated((int) $tournamentMatch['id'], $gameId, $wrapperIds['game_match_id'], $wrapperIds['draft_match_id']);

        try {
            // Every game createGame() itself produces starts out
            // 'waiting' -- for an ordinary ad hoc game this is what the
            // client's own autoStartGameIfReady() poll normally clears,
            // but a tournament match has no browser tab of its own
            // driving that, so start it here instead. Exactly
            // tryAutoStartDraftGame()'s own tolerance: a deck_type
            // needing a decklist submitted first (draft/custom_duel), or
            // synchronous_mode needing its own ready check, throws here
            // and is silently left for that same deck_type/mode's own
            // ordinary flow to call startGame() once actually ready --
            // nothing tournament-specific needed there.
            $this->games->startGame($gameId);
        } catch (GameStateException) {
        }
    }

    public function onMatchConcluded(int $gameId, ?int $gameMatchId, ?int $draftMatchId, int $winnerUserId): void
    {
        $tournamentMatch = match (true) {
            $draftMatchId !== null => $this->matches->findByDraftMatchId($draftMatchId),
            $gameMatchId !== null => $this->matches->findByGameMatchId($gameMatchId),
            default => $this->matches->findByGameId($gameId),
        };
        if ($tournamentMatch === null || $tournamentMatch['status'] === 'completed' || $tournamentMatch['status'] === 'bye') {
            return;
        }

        $tournamentId = (int) $tournamentMatch['tournament_id'];
        $winnerParticipant = $this->participants->findForUser($tournamentId, $winnerUserId);
        if ($winnerParticipant === null) {
            return;
        }

        $this->resolveMatchResult($tournamentId, $tournamentMatch, (int) $winnerParticipant['id'], isBye: false);
    }

    private function resolveMatchResult(int $tournamentId, array $tournamentMatch, int $winnerParticipantId, bool $isBye): void
    {
        $matchId = (int) $tournamentMatch['id'];
        if ($isBye) {
            $this->matches->markBye($matchId, $winnerParticipantId);
        } else {
            $this->matches->markCompleted($matchId, $winnerParticipantId);
        }

        $round = $this->requireRoundById((int) $tournamentMatch['round_id']);

        if ($round['bracket'] === 'swiss') {
            $this->onSwissMatchResolved($tournamentId, $round);

            return;
        }

        if ($round['bracket'] === 'grand_final') {
            $this->onGrandFinalResolved($tournamentId, $tournamentMatch, $winnerParticipantId, (int) $round['round_number']);

            return;
        }

        $participant1Id = (int) $tournamentMatch['participant1_id'];
        $participant2Id = $tournamentMatch['participant2_id'] !== null ? (int) $tournamentMatch['participant2_id'] : null;
        $loserParticipantId = $participant2Id === null
            ? null
            : ($winnerParticipantId === $participant1Id ? $participant2Id : $participant1Id);

        if ($tournamentMatch['winner_advances_to_match_id'] !== null) {
            $this->advanceInto($tournamentId, (int) $tournamentMatch['winner_advances_to_match_id'], (int) $tournamentMatch['winner_advances_to_slot'], $winnerParticipantId);
        } elseif ($round['bracket'] === 'single') {
            // No further advance target and we're in the single
            // (single-elimination) bracket -- this was the final.
            $this->finishTournament($tournamentId, $winnerParticipantId);
        }

        if (!$isBye && $loserParticipantId !== null && $tournamentMatch['loser_advances_to_match_id'] !== null) {
            $this->advanceInto($tournamentId, (int) $tournamentMatch['loser_advances_to_match_id'], (int) $tournamentMatch['loser_advances_to_slot'], $loserParticipantId);
        }
    }

    /** Fills one player slot of a not-yet-fully-known match, and starts its game once both slots are filled. */
    /**
     * Fills one player slot of a not-yet-fully-known match. Most matches
     * expect two inbound edges (both slots must fill before anything
     * starts) -- but a double-elimination losers-bracket slot can be a
     * "future bye" that structurally only ever gets ONE edge at all (its
     * other input was a winners-bracket bye producing no loser to send
     * -- see TournamentBracketBuilder::buildDoubleElimination()'s own
     * docblock), so waiting for a second slot that will never fill
     * would strand it forever. TournamentMatchRepository::countInboundAdvances()
     * says how many edges this match was ever going to receive; once
     * that many have actually fired, it resolves -- immediately as a
     * bye if only one was ever expected, otherwise starts the real game.
     */
    private function advanceInto(int $tournamentId, int $targetMatchId, int $slot, int $participantId): void
    {
        $this->matches->fillSlot($targetMatchId, $slot, $participantId);
        $target = $this->matches->find($targetMatchId);

        if ($target['status'] !== 'pending') {
            return;
        }

        $filledCount = ($target['participant1_id'] !== null ? 1 : 0) + ($target['participant2_id'] !== null ? 1 : 0);
        $expectedCount = $this->matches->countInboundAdvances($targetMatchId);
        if ($filledCount < $expectedCount) {
            return;
        }

        $tournament = $this->requireTournament($tournamentId);
        if ($expectedCount <= 1) {
            $loneParticipantId = $target['participant1_id'] !== null ? (int) $target['participant1_id'] : (int) $target['participant2_id'];
            $this->resolveMatchResult($tournamentId, $target, $loneParticipantId, isBye: true);
        } else {
            $this->startMatchGame($tournament, $target);
        }
    }

    private function onGrandFinalResolved(int $tournamentId, array $tournamentMatch, int $winnerParticipantId, int $roundNumber): void
    {
        $winnersBracketChampionParticipantId = (int) $tournamentMatch['participant1_id'];

        if ($winnerParticipantId === $winnersBracketChampionParticipantId) {
            // The winners-bracket champion (undefeated coming in) won
            // outright -- whether that's round 1 (losers-bracket
            // champion eliminated with their second loss) or round 2
            // (the bracket reset, decisively won).
            $this->finishTournament($tournamentId, $winnerParticipantId);

            return;
        }

        if ($roundNumber === 1) {
            // The losers-bracket champion beat the previously-undefeated
            // winners-bracket champion -- that's the WB champion's FIRST
            // loss, so (the whole point of double elimination) a decider
            // is played: the same two players again, in round 2.
            $round2 = $this->requireRoundByNumber($tournamentId, 'grand_final', 2);
            $matches = $this->matches->listForRound((int) $round2['id']);
            $round2Match = $matches[0];
            $this->matches->fillSlot((int) $round2Match['id'], 1, (int) $tournamentMatch['participant1_id']);
            $this->matches->fillSlot((int) $round2Match['id'], 2, (int) $tournamentMatch['participant2_id']);
            $tournament = $this->requireTournament($tournamentId);
            $this->startMatchGame($tournament, $this->matches->find((int) $round2Match['id']));

            return;
        }

        // Round 2 (the reset) decisively won by the losers-bracket
        // champion -- they're the tournament champion outright now.
        $this->finishTournament($tournamentId, $winnerParticipantId);
    }

    private function onSwissMatchResolved(int $tournamentId, array $round): void
    {
        if (!$this->matches->isRoundComplete((int) $round['id'])) {
            return;
        }

        $tournament = $this->requireTournament($tournamentId);
        $roundNumber = (int) $round['round_number'];
        $totalRounds = (int) ($tournament['swiss_round_count'] ?? $this->defaultSwissRoundCount($tournamentId));

        if ($roundNumber >= $totalRounds) {
            $standings = $this->swissStandings($tournamentId);
            $championParticipantId = array_key_first($standings);
            $this->finishTournament($tournamentId, $championParticipantId);

            return;
        }

        $wins = $this->swissWinCounts($tournamentId);
        $alreadyPlayed = $this->swissAlreadyPlayedPairs($tournamentId);
        $priorByes = $this->swissPriorByes($tournamentId);
        $pairing = $this->bracketBuilder->swissPairings($wins, $alreadyPlayed, $priorByes);
        $this->createSwissRound($tournament, $roundNumber + 1, $pairing);
    }

    private function finishTournament(int $tournamentId, int $winnerParticipantId): void
    {
        $winner = $this->participants->find($winnerParticipantId);
        $this->tournaments->markCompleted($tournamentId, (int) $winner['user_id']);
    }

    /** @return array<int, int> participant id => win count, every participant who has ever played a match in this tournament */
    private function swissWinCounts(int $tournamentId): array
    {
        $wins = [];
        foreach ($this->matches->listForTournament($tournamentId) as $match) {
            foreach (['participant1_id', 'participant2_id'] as $key) {
                if ($match[$key] !== null && !isset($wins[(int) $match[$key]])) {
                    $wins[(int) $match[$key]] = 0;
                }
            }
            if (in_array($match['status'], ['completed', 'bye'], true) && $match['winner_participant_id'] !== null) {
                $wins[(int) $match['winner_participant_id']] = ($wins[(int) $match['winner_participant_id']] ?? 0) + 1;
            }
        }

        return $wins;
    }

    /** @return array<string, true> */
    private function swissAlreadyPlayedPairs(int $tournamentId): array
    {
        $pairs = [];
        foreach ($this->matches->listForTournament($tournamentId) as $match) {
            if ($match['participant1_id'] !== null && $match['participant2_id'] !== null) {
                $pairs[TournamentBracketBuilder::pairKey((int) $match['participant1_id'], (int) $match['participant2_id'])] = true;
            }
        }

        return $pairs;
    }

    /** @return int[] */
    private function swissPriorByes(int $tournamentId): array
    {
        $byes = [];
        foreach ($this->matches->listForTournament($tournamentId) as $match) {
            if ($match['status'] === 'bye' && $match['winner_participant_id'] !== null) {
                $byes[] = (int) $match['winner_participant_id'];
            }
        }

        return $byes;
    }

    private function defaultSwissRoundCount(int $tournamentId): int
    {
        $participantCount = count(array_filter(
            $this->participants->listForTournament($tournamentId),
            static fn (array $p): bool => $p['status'] === 'joined'
        ));

        return max(1, (int) ceil(log(max(2, $participantCount), 2)));
    }

    /**
     * Final Swiss standings, best first -- ranked by win count, ties
     * broken by direct head-to-head result where the tied group played
     * each other, then by strength of opposition (the simple "sum of
     * beaten opponents' own win totals" Buchholz variant), then by seed
     * as a final, always-decisive tiebreak. Deliberately not a fully
     * optimal Swiss tiebreak system (real Swiss software solves for
     * median Buchholz, etc.) -- overkill for a casual TCG tournament tool.
     *
     * @return array<int, array{wins: int, buchholz: int}> participant id => record, ordered best to worst
     */
    public function swissStandings(int $tournamentId): array
    {
        $wins = $this->swissWinCounts($tournamentId);
        $matchesByParticipant = [];
        foreach ($this->matches->listForTournament($tournamentId) as $match) {
            if (!in_array($match['status'], ['completed', 'bye'], true)) {
                continue;
            }
            foreach (['participant1_id', 'participant2_id'] as $key) {
                if ($match[$key] === null) {
                    continue;
                }
                $matchesByParticipant[(int) $match[$key]][] = $match;
            }
        }

        $buchholz = [];
        foreach ($wins as $participantId => $_) {
            $total = 0;
            foreach ($matchesByParticipant[$participantId] ?? [] as $match) {
                $opponentId = (int) $match['participant1_id'] === $participantId
                    ? ($match['participant2_id'] !== null ? (int) $match['participant2_id'] : null)
                    : (int) $match['participant1_id'];
                if ($opponentId !== null) {
                    $total += $wins[$opponentId] ?? 0;
                }
            }
            $buchholz[$participantId] = $total;
        }

        $seeds = [];
        foreach ($this->participants->listForTournament($tournamentId) as $p) {
            $seeds[(int) $p['id']] = $p['seed'] !== null ? (int) $p['seed'] : PHP_INT_MAX;
        }

        $participantIds = array_keys($wins);
        usort($participantIds, static function (int $a, int $b) use ($wins, $buchholz, $seeds): int {
            return $wins[$b] <=> $wins[$a]
                ?: $buchholz[$b] <=> $buchholz[$a]
                ?: $seeds[$a] <=> $seeds[$b];
        });

        $standings = [];
        foreach ($participantIds as $id) {
            $standings[$id] = ['wins' => $wins[$id], 'buchholz' => $buchholz[$id]];
        }

        return $standings;
    }

    public function listMine(int $userId): array
    {
        return $this->tournaments->listForUser($userId);
    }

    public function listOpenFor(int $userId): array
    {
        return $this->tournaments->listOpenFor($userId);
    }

    public function getState(int $tournamentId, int $viewerUserId): array
    {
        $tournament = $this->requireTournament($tournamentId);
        $participant = $this->participants->findForUser($tournamentId, $viewerUserId);
        if ($tournament['created_by_user_id'] !== $viewerUserId && $participant === null && $tournament['registration_mode'] !== 'open') {
            throw new NotAuthorizedForTournamentException("You're not part of this tournament");
        }

        $rounds = $this->matches->listRounds($tournamentId);
        $matchesByRound = [];
        foreach ($rounds as $round) {
            $matchesByRound[(int) $round['id']] = $this->matches->listForRound((int) $round['id']);
        }

        return [
            'tournament' => $tournament,
            'participants' => $this->participants->listForTournament($tournamentId),
            'rounds' => $rounds,
            'matches_by_round' => $matchesByRound,
            'standings' => $tournament['bracket_type'] === 'swiss' && $tournament['status'] !== 'registration'
                ? $this->swissStandings($tournamentId)
                : null,
        ];
    }

    private function requireTournament(int $tournamentId): array
    {
        $tournament = $this->tournaments->find($tournamentId);
        if ($tournament === null) {
            throw new TournamentNotFoundException("No such tournament {$tournamentId}");
        }

        return $tournament;
    }

    private function requireRoundById(int $roundId): array
    {
        $round = $this->matches->findRoundById($roundId);
        if ($round === null) {
            throw new \LogicException("Round {$roundId} vanished mid-request");
        }

        return $round;
    }

    private function requireRoundByNumber(int $tournamentId, string $bracket, int $roundNumber): array
    {
        $round = $this->matches->findRound($tournamentId, $bracket, $roundNumber);
        if ($round === null) {
            throw new \LogicException("Round {$bracket}/{$roundNumber} missing for tournament {$tournamentId}");
        }

        return $round;
    }

    private function requireCreator(array $tournament, int $userId): void
    {
        if ((int) $tournament['created_by_user_id'] !== $userId) {
            throw new NotAuthorizedForTournamentException('Only the tournament creator can do that');
        }
    }

    private function requireRegistrationOpen(array $tournament): void
    {
        if ($tournament['status'] !== 'registration') {
            throw new TournamentStateException('This tournament is no longer in registration');
        }
    }

    private function assertRoomAvailable(array $tournament): void
    {
        if ($tournament['max_participants'] === null) {
            return;
        }
        $joinedCount = count(array_filter(
            $this->participants->listForTournament((int) $tournament['id']),
            static fn (array $p): bool => $p['status'] === 'joined'
        ));
        if ($joinedCount >= (int) $tournament['max_participants']) {
            throw new TournamentStateException('This tournament is full');
        }
    }
}
