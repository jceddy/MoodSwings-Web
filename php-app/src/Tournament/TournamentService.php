<?php

declare(strict_types=1);

namespace MoodSwings\Tournament;

use MoodSwings\Achievements\AchievementService;
use MoodSwings\Game\CardCatalog;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\TournamentCastGrantRepository;
use MoodSwings\Repository\TournamentMatchRepository;
use MoodSwings\Repository\TournamentParticipantRepository;
use MoodSwings\Repository\TournamentPodRepository;
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

    /** How many cards a Booster Draft deck must have at minimum -- see submitCustomDuelDeck()'s own pool-membership check. */
    public const BOOSTER_DRAFT_MIN_DECK_SIZE = 12;

    /** How many cards a Grid Draft "Pod draft (once)" deck must have at minimum -- see submitCustomDuelDeck()'s own pool-membership check. Same floor as Booster Draft's, coincidentally -- GameService's own GRID_DRAFT_MIN_DECK_SIZE ordinary Grid Draft games use -- but a dedicated constant since the two are conceptually independent. */
    public const GRID_DRAFT_POD_MIN_DECK_SIZE = 12;

    public function __construct(
        private readonly TournamentRepository $tournaments,
        private readonly TournamentParticipantRepository $participants,
        private readonly TournamentMatchRepository $matches,
        private readonly TournamentBracketBuilder $bracketBuilder,
        private readonly GameService $games,
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
        private readonly TournamentPodRepository $pods,
        private readonly BoosterPackBuilder $boosterPackBuilder,
        private readonly BoosterDraftPodBuilder $podBuilder,
        private readonly GridDraftPodBuilder $gridDraftPodBuilder,
        private readonly AchievementService $achievements = new AchievementService(),
        private readonly TournamentCastGrantRepository $castGrants = new TournamentCastGrantRepository(),
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
     * @param ?string $creatorDecklistText the creator's own Power Duel
     *   decklist (issue reported live: "the deck submission should
     *   happen when the player joins the tournament") -- required, and
     *   validated before anything is created, whenever $matchParams
     *   resolves to deck_type 'custom_duel' (every 'duel'-format
     *   tournament except Booster Draft); ignored otherwise. See
     *   submitTournamentDeck()'s own docblock.
     * @param ?int $creatorSavedDecklistId an alternative to
     *   $creatorDecklistText, loading a previously-saved decklist.
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
        ?string $creatorDecklistText = null,
        ?int $creatorSavedDecklistId = null,
        // Reported live: "user(s) with spectator mode access need to be
        // selected when the tournament is created so that all users in
        // the tournament can see who is allowed to cast" -- each entry
        // is ['username' => string, 'reveal_hands' => bool], resolved
        // and inserted the same way grantCastAccess() would, once
        // $tournamentId actually exists. Free-text usernames (not a
        // friend-list picker like $inviteUserIds) since a caster is
        // often not a friend or participant at all -- see
        // grantCastAccess()'s own docblock for why.
        array $castGrants = [],
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
        // Every tournament match is always best-of-three now -- the New
        // Tournament dialog no longer offers a choice, and this override
        // makes that the actual server-side contract rather than merely
        // the frontend's own default; overwrites whatever the caller
        // sent. A no-op (silently ignored, not an error) for the
        // draft-family deck types (grid_draft/sealed_deck/booster_draft),
        // which already run their own best-of-three-at-2-players story
        // via GameService::draftGamesToWin() -- see createGame()'s own
        // $bestOfThree docblock.
        $matchParams['best_of_three'] = true;
        // Traditional always implies deck_type 'structure' now -- no
        // separate deck choice is offered for it any more (New Tournament
        // dialog docblock) -- and every match, every game, of the whole
        // event deals from the exact same randomly-generated Structure
        // deck rather than each getting its own, so it's generated
        // exactly once, right here, rather than per-match. Stored on
        // match_params itself (structure_deck_card_ids) alongside the
        // rest of this event's fixed settings, and passed back into
        // GameService::createGame() as $fixedCustomDeckCardIds -- see
        // startMatchGame()'s own docblock -- for every match this
        // tournament ever plays. Overwrites whatever deck_type the caller
        // sent; there is nothing left to choose for Traditional.
        if ($format === 'standard') {
            $matchParams['deck_type'] = 'structure';
            $matchParams['structure_deck_card_ids'] = $this->games->generateStructureDeckCardIds();
        }
        // Duel always implies "Power Duel" now (deck_type 'custom_duel'
        // under the "power" duel_deck_rules preset) for exactly the same
        // reason -- using custom decks is no longer one deck_type choice
        // among several for format 'duel', it's simply what a Duel
        // tournament is -- EXCEPT for Booster Draft's own
        // `deck_type: 'booster_draft'` sentinel, which is also
        // `format: 'duel'` under the hood (see startMatchGame()'s own
        // docblock) but is a completely different, already-fully-decided
        // format choice from a tournament creator's perspective, not
        // "Duel" with a deck_type left to override.
        if ($format === 'duel' && ($matchParams['deck_type'] ?? null) !== 'booster_draft') {
            $matchParams['deck_type'] = 'custom_duel';
            $matchParams['duel_deck_rules'] = ['preset' => 'power'];
        }
        // Grid Draft's third option (issue #91 follow-up, migration
        // 0337): every pod's own bracket, and the final pod's own
        // bracket that decides the champion, are always single
        // elimination regardless of whatever bracket_type was actually
        // chosen -- see startPodBracket()'s own docblock for why. Forced
        // here (rather than left as whatever the creator picked) so the
        // stored value matches what the tournament will actually do.
        if ($format === 'draft' && ($matchParams['deck_type'] ?? null) === 'grid_draft_pod_playoff') {
            $bracketType = 'single_elimination';
        }
        if ($registrationMode === 'open') {
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
        // Every tournament, regardless of registration mode or format,
        // is now capped to 4-16 joined participants (for now -- may be
        // relaxed later): the New Tournament dialog's min/max fields are
        // both required <select> lists of 4-16 rather than free-entry
        // numbers, so this is the actual server-side contract rather
        // than merely the frontend's own range. This also keeps Grid
        // Draft's "Pods with playoffs" option's own math simple: pods of
        // <=4 (GridDraftPodBuilder) means at most 4 pods for any allowed
        // participant count, so the final pod (one winner per pod) never
        // exceeds Grid Draft's own 4-drafter cap either.
        if ($minParticipants < 4 || $minParticipants > 16) {
            throw new TournamentStateException('min_participants must be between 4 and 16');
        }
        if ($maxParticipants === null || $maxParticipants < 4 || $maxParticipants > 16) {
            throw new TournamentStateException('max_participants must be between 4 and 16');
        }
        if ($maxParticipants < $minParticipants) {
            throw new TournamentStateException('max_participants cannot be less than min_participants');
        }
        // Resolved+validated before anything is created, same as every
        // other validation above -- a bad/missing creator decklist fails
        // the whole createTournament() call rather than leaving behind a
        // half-created tournament nobody can actually play in.
        $creatorDeck = $this->resolveJoinTimeDeck($matchParams, $createdByUserId, $creatorDecklistText, $creatorSavedDecklistId);

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

        $creatorParticipantId = $this->participants->add($tournamentId, $createdByUserId, 'joined');
        if ($creatorDeck !== null) {
            $this->participants->setDeck($creatorParticipantId, $creatorDeck['name'], $creatorDeck['cardIds'], $creatorDeck['sideboardCardIds']);
        }
        $this->achievements->onTournamentJoined($createdByUserId);

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

        // Tournament spectator mode follow-up (reported live: casters
        // selected at creation time) -- same grantCastAccess() rules
        // (resolve by username, reveal_hands defaults true), just applied
        // to a tournament that only now exists rather than an existing
        // one. Deduplicated by resolved user id (not raw username) so
        // two different-cased spellings of the same account, or the same
        // one listed twice, can't collide against castGrants' own unique
        // (tournament_id, user_id) constraint. The tournament's own
        // creator is silently skipped if listed here (they're always
        // already trusted -- see hasCastAccess()), the same "silently
        // skip yourself" convention the invite loop above uses.
        $grantedCastUserIds = [];
        foreach ($castGrants as $castGrant) {
            $granteeUsername = trim((string) ($castGrant['username'] ?? ''));
            if ($granteeUsername === '') {
                continue;
            }
            $grantee = $this->users->findByUsername($granteeUsername);
            if ($grantee === null) {
                throw new TournamentStateException("No such user \"{$granteeUsername}\"");
            }
            $granteeUserId = (int) $grantee['id'];
            if ($granteeUserId === $createdByUserId || isset($grantedCastUserIds[$granteeUserId])) {
                continue;
            }
            $revealHands = (bool) ($castGrant['reveal_hands'] ?? true);
            // Same hands-revealed-caster-can't-also-play rule as
            // grantCastAccess() -- checked here after the invite loop
            // above has already inserted its rows, so someone listed in
            // both inviteUserIds and castGrants (reveal_hands: true) is
            // caught the same way it would be for an existing tournament.
            if ($revealHands && $this->hasActiveParticipation($tournamentId, $granteeUserId)) {
                throw new TournamentStateException("\"{$granteeUsername}\" is a participant in this tournament and cannot also be granted hands-revealed cast access -- grant \"no hands\" access instead.");
            }
            $grantedCastUserIds[$granteeUserId] = true;
            $this->castGrants->add($tournamentId, $granteeUserId, $createdByUserId, $revealHands);
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
        // Reported live: a caster with hands revealed can see both
        // players' cards in every match -- letting them also play would
        // hand them a scouting advantage over every future opponent, not
        // just whoever they're personally seated against. See
        // hasFullHandsCastGrant()'s own docblock.
        if ($this->hasFullHandsCastGrant($tournamentId, $inviteeUserId)) {
            throw new TournamentStateException('That user has hands-revealed cast access to this tournament and cannot also be invited to play in it -- revoke their cast access first.');
        }

        $this->participants->add($tournamentId, $inviteeUserId, 'invited');
    }

    public function acceptInvite(int $tournamentId, int $userId, ?string $decklistText = null, ?int $savedDecklistId = null): void
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireRegistrationOpen($tournament);
        $participant = $this->participants->findForUser($tournamentId, $userId);
        if ($participant === null || $participant['status'] !== 'invited') {
            throw new TournamentStateException('You have no pending invite to this tournament');
        }
        $this->assertRoomAvailable($tournament);
        $deck = $this->resolveJoinTimeDeck($tournament['match_params'], $userId, $decklistText, $savedDecklistId);

        $this->participants->updateStatus((int) $participant['id'], 'joined');
        if ($deck !== null) {
            $this->participants->setDeck((int) $participant['id'], $deck['name'], $deck['cardIds'], $deck['sideboardCardIds']);
        }
        $this->achievements->onTournamentJoined($userId);
    }

    public function declineInvite(int $tournamentId, int $userId): void
    {
        $participant = $this->participants->findForUser($tournamentId, $userId);
        if ($participant === null || $participant['status'] !== 'invited') {
            throw new TournamentStateException('You have no pending invite to this tournament');
        }

        $this->participants->updateStatus((int) $participant['id'], 'declined');
    }

    /**
     * Tournament spectator mode (issue #238): the creator grants a user
     * -- a dedicated caster, not necessarily a participant at all --
     * live, hands-revealed viewing of this tournament's matches while
     * still in_progress. Deliberately its own grant, never the ordinary
     * spectate_code from issue #128 -- see
     * database/migrations/0370's own docblock for why. Idempotent-ish in
     * spirit but not silently so: re-granting someone who already has it
     * is a state error, the same "already invited or joined" shape
     * invite() above uses, rather than a no-op UPSERT, so a caller can't
     * mistake a duplicate click for confirmation nothing changed.
     *
     * $granteeUsername (rather than a user_id, unlike invite()'s own
     * $inviteeUserId) since granting cast access has no friend-list/
     * search-result picker to source an id from -- the organizer simply
     * types the caster's username, the same way FriendshipService::
     * sendInvite()'s own username_or_email is resolved server-side.
     *
     * $revealHands (reported live: a "no hands" option) -- true grants
     * the original issue #238 behavior (hands + pending-decision
     * internals revealed even while in_progress); false grants a caster
     * only the same public information a plain issue #128 spectator sees
     * for an in_progress game, just without needing a spectate_code or
     * friendship to get it. See GameService::getTournamentCastState()'s
     * own docblock for exactly what each mode does and doesn't reveal.
     *
     * @return array{id: int, username: string, reveal_hands: bool} the grantee, so a caller doesn't have to look them back up
     */
    public function grantCastAccess(int $tournamentId, int $granterUserId, string $granteeUsername, bool $revealHands = true): array
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireCreator($tournament, $granterUserId);

        $grantee = $this->users->findByUsername($granteeUsername);
        if ($grantee === null) {
            throw new TournamentStateException("No such user \"{$granteeUsername}\"");
        }
        $granteeUserId = (int) $grantee['id'];
        if ((int) $tournament['created_by_user_id'] === $granteeUserId) {
            throw new TournamentStateException('The tournament creator already has cast access');
        }
        if ($this->castGrants->find($tournamentId, $granteeUserId) !== null) {
            throw new TournamentStateException('That user already has cast access to this tournament');
        }
        // Reported live: a hands-revealed caster can't also play -- see
        // invite()'s own identical concern/docblock. "No hands" grants
        // are exempt since that caster never sees more than any plain
        // spectator would.
        if ($revealHands && $this->hasActiveParticipation($tournamentId, $granteeUserId)) {
            throw new TournamentStateException('That user is a participant in this tournament and cannot also be granted hands-revealed cast access -- remove them from the tournament first, or grant "no hands" access instead.');
        }

        $this->castGrants->add($tournamentId, $granteeUserId, $granterUserId, $revealHands);

        return ['id' => $granteeUserId, 'username' => $grantee['username'], 'reveal_hands' => $revealHands];
    }

    /** Tournament spectator mode (issue #238): the creator revokes a previously granted caster's access. Revoking a grant that doesn't exist is a harmless no-op, same as DELETE always is. */
    public function revokeCastAccess(int $tournamentId, int $granterUserId, int $granteeUserId): void
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireCreator($tournament, $granterUserId);

        $this->castGrants->remove($tournamentId, $granteeUserId);
    }

    /**
     * Tournament spectator mode (issue #238): the tournament's own roster
     * of everyone explicitly granted cast access (never includes the
     * creator themselves -- hasCastAccess() below always treats them as
     * trusted implicitly, so they'd never need to be listed here).
     * Reported live: "all users in the tournament can see who is allowed
     * to cast" -- viewable by the same audience getState() itself allows
     * (creator, participant, a granted caster, or anyone for an
     * open-registration tournament), not creator-only -- getState()
     * already embeds this same list as 'cast_grants' for its own
     * viewers, so this standalone method mainly exists for the creator's
     * own "Casters" management dialog to re-fetch just this after an
     * add/revoke.
     *
     * @return array[] {id, tournament_id, user_id, username, granted_by_user_id, reveal_hands, created_at}, oldest grant first
     */
    public function listCastGrants(int $tournamentId, int $requesterUserId): array
    {
        $tournament = $this->requireTournament($tournamentId);
        if (!$this->canViewTournament($tournament, $requesterUserId)) {
            throw new NotAuthorizedForTournamentException("You're not part of this tournament");
        }

        return $this->castGrants->listForTournament($tournamentId);
    }

    /**
     * Tournament spectator mode (issue #238): whether $userId may view
     * this tournament's still-in_progress matches at all as a trusted
     * caster (with or without hands revealed -- see castRevealsHands()
     * for which) -- either they created the tournament (always
     * implicitly trusted, the same way a game's own created_by_user_id
     * already gets creator-only fields elsewhere) or the creator
     * explicitly granted them access. Never throws -- a nonexistent
     * tournament simply has no one with cast access, the same "false,
     * not an error" shape GameService::tournamentIdForGame()'s own
     * caller relies on.
     */
    public function hasCastAccess(int $tournamentId, int $userId): bool
    {
        $tournament = $this->tournaments->find($tournamentId);
        if ($tournament === null) {
            return false;
        }
        if ((int) $tournament['created_by_user_id'] === $userId) {
            return true;
        }

        return $this->castGrants->find($tournamentId, $userId) !== null;
    }

    /**
     * Tournament spectator mode follow-up (reported live: a "no hands"
     * cast option): whether $userId's own cast access (hasCastAccess()
     * must already be true, or this is meaningless) reveals hands -- the
     * tournament's own creator is always fully trusted (true); a granted
     * caster gets whatever $revealHands grantCastAccess() stored for
     * them. False (not an error) for someone with no cast access at all,
     * matching hasCastAccess()'s own "false, not an error" shape --
     * callers are expected to check hasCastAccess() first regardless.
     */
    public function castRevealsHands(int $tournamentId, int $userId): bool
    {
        $tournament = $this->tournaments->find($tournamentId);
        if ($tournament === null) {
            return false;
        }
        if ((int) $tournament['created_by_user_id'] === $userId) {
            return true;
        }

        $grant = $this->castGrants->find($tournamentId, $userId);

        return $grant !== null && $grant['reveal_hands'];
    }

    /**
     * Tournament spectator mode (issue #238): the shared "who's allowed
     * to even load this tournament" rule getState() and listCastGrants()
     * both enforce -- the creator, an invited/joined participant, a
     * granted caster (hasCastAccess() already covers the creator too,
     * checked last since it's the only branch requiring an extra query),
     * or anyone at all once registration is 'open'.
     */
    private function canViewTournament(array $tournament, int $viewerUserId): bool
    {
        if ((int) $tournament['created_by_user_id'] === $viewerUserId) {
            return true;
        }
        if ($tournament['registration_mode'] === 'open') {
            return true;
        }
        if ($this->participants->findForUser((int) $tournament['id'], $viewerUserId) !== null) {
            return true;
        }

        return $this->hasCastAccess((int) $tournament['id'], $viewerUserId);
    }

    public function joinOpenTournament(int $tournamentId, int $userId, ?string $decklistText = null, ?int $savedDecklistId = null): void
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireRegistrationOpen($tournament);
        if ($tournament['registration_mode'] !== 'open') {
            throw new TournamentStateException('This tournament is invite-only');
        }
        // A 'withdrawn' row is the one exception to "you already have a
        // row here" -- rejoining after withdrawing is allowed (as long as
        // there's still room, checked below the same as anyone else),
        // unlike 'joined' (already in) or 'invited' (irrelevant here,
        // this is the open-registration path). Reuse the existing row
        // rather than inserting a second one for the same
        // (tournament_id, user_id) pair, which uq_tournament_participants_user
        // would reject outright.
        $existingParticipant = $this->participants->findForUser($tournamentId, $userId);
        if ($existingParticipant !== null && $existingParticipant['status'] !== 'withdrawn') {
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
        // Reported live: a hands-revealed caster can't also play -- see
        // invite()'s own identical check/docblock.
        if ($this->hasFullHandsCastGrant($tournamentId, $userId)) {
            throw new TournamentStateException('You have hands-revealed cast access to this tournament and cannot also join it as a player -- ask the organizer to revoke your cast access first.');
        }
        $this->assertRoomAvailable($tournament);
        $deck = $this->resolveJoinTimeDeck($tournament['match_params'], $userId, $decklistText, $savedDecklistId);

        if ($existingParticipant !== null) {
            $this->participants->updateStatus((int) $existingParticipant['id'], 'joined');
            $participantId = (int) $existingParticipant['id'];
        } else {
            $participantId = $this->participants->add($tournamentId, $userId, 'joined');
        }
        if ($deck !== null) {
            $this->participants->setDeck($participantId, $deck['name'], $deck['cardIds'], $deck['sideboardCardIds']);
        }
        $this->achievements->onTournamentJoined($userId);
    }

    /**
     * Power Duel's own join-time deck (issue reported live: "the deck
     * submission should happen when the player joins the tournament --
     * players use the same submitted deck for the entire tournament"),
     * resubmitted/edited standalone -- createTournament()/
     * joinOpenTournament()/acceptInvite() already require+store this
     * same deck atomically with joining (see resolveJoinTimeDeck()),
     * this is purely for changing your mind before the bracket locks:
     * only while still 'registration' (startTournament() locks every
     * participant's deck into the bracket it seeds, exactly the way an
     * ordinary custom_duel game's own deck locks once submitted for a
     * "locked" non-sideboarding match), and only for a tournament that
     * actually uses custom_duel decklists in the first place.
     */
    public function submitTournamentDeck(int $tournamentId, int $userId, ?string $decklistText, ?int $savedDecklistId = null): void
    {
        $tournament = $this->requireTournament($tournamentId);
        $this->requireRegistrationOpen($tournament);
        $participant = $this->participants->findForUser($tournamentId, $userId);
        if ($participant === null || $participant['status'] !== 'joined') {
            throw new TournamentStateException('You have not joined this tournament');
        }

        $deck = $this->resolveJoinTimeDeck($tournament['match_params'], $userId, $decklistText, $savedDecklistId);
        if ($deck === null) {
            throw new TournamentStateException('This tournament does not use custom duel decklists');
        }

        $this->participants->setDeck((int) $participant['id'], $deck['name'], $deck['cardIds'], $deck['sideboardCardIds']);
    }

    /**
     * Shared by createTournament()'s own creator auto-join,
     * joinOpenTournament(), acceptInvite(), and submitTournamentDeck() --
     * returns null (nothing to validate, nothing to store) for any
     * tournament whose match_params.deck_type isn't 'custom_duel' (every
     * format/deck_type besides Power Duel), so every join-time call site
     * can treat this uniformly regardless of tournament type. See
     * GameService::resolvePowerDuelTournamentDeck()'s own docblock for
     * the actual validation.
     *
     * @return array{name: ?string, cardIds: int[], sideboardCardIds: int[]|null}|null
     */
    private function resolveJoinTimeDeck(array $matchParams, int $userId, ?string $decklistText, ?int $savedDecklistId): ?array
    {
        if (($matchParams['deck_type'] ?? null) !== 'custom_duel') {
            return null;
        }

        return $this->games->resolvePowerDuelTournamentDeck(
            $userId,
            $decklistText,
            $savedDecklistId,
            (bool) ($matchParams['allow_sideboarding'] ?? false),
        );
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

    /**
     * Reported live: clean up tournaments a week after being cancelled/
     * completed -- see bin/expire_and_delete_stale_games.php (the
     * existing game/match cleanup cron this is folded into) and
     * TournamentRepository::deleteStale()'s own docblock for exactly
     * what gets deleted and why it's always safe to.
     *
     * @return int how many tournaments were deleted
     */
    public function deleteStaleTournaments(int $olderThanDays = 7): int
    {
        return $this->tournaments->deleteStale($olderThanDays);
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
        $this->achievements->onTournamentStarted((int) $tournament['created_by_user_id'], $count);

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

        if ($this->isBoosterDraft($tournament)) {
            $this->tournaments->markDrafting($tournamentId);
            $this->startBoosterDraftPods($tournamentId, array_values($seedToParticipantId));

            return;
        }

        if ($this->isGridDraftPod($tournament) || $this->isGridDraftPodPlayoff($tournament)) {
            $this->tournaments->markDrafting($tournamentId);
            $this->startGridDraftPods($tournamentId, array_values($seedToParticipantId), $tournament);

            return;
        }

        $this->tournaments->markStarted($tournamentId);
        $this->materializeBracket($tournament, $seedToParticipantId);
    }

    private function isBoosterDraft(array $tournament): bool
    {
        return (string) ($tournament['match_params']['deck_type'] ?? '') === 'booster_draft';
    }

    private function isGridDraftPod(array $tournament): bool
    {
        return (string) ($tournament['match_params']['deck_type'] ?? '') === 'grid_draft_pod';
    }

    /** Grid Draft's third option (issue #91 follow-up) -- see startPodBracket()'s own docblock. */
    private function isGridDraftPodPlayoff(array $tournament): bool
    {
        return (string) ($tournament['match_params']['deck_type'] ?? '') === 'grid_draft_pod_playoff';
    }

    private function materializeBracket(array $tournament, array $seedToParticipantId): void
    {
        match ($tournament['bracket_type']) {
            'swiss' => $this->startSwiss($tournament, $seedToParticipantId),
            default => $this->materializeEliminationBracket($tournament, $seedToParticipantId),
        };
    }

    /**
     * Booster Draft's own pre-bracket phase (issue #91 follow-up): splits
     * $participantIds into pods of <=8 (BoosterDraftPodBuilder::podSizes(),
     * as even as possible), assigns each a random seat_order within its
     * own pod -- nothing about seeding should predict who drafts with
     * whom, same rationale bracket seeding itself already follows -- and
     * deals every seat its own two 15-card boosters (BoosterPackBuilder),
     * one to pass left, one to pass right. The tournament sits in
     * 'drafting' status until every pod's own 15 rounds finish (see
     * pickBoosterDraftCard()/maybeFinishDrafting()), at which point the
     * real bracket/Swiss round 1 materializes, mixing players across
     * pods freely exactly like any other tournament.
     *
     * @param int[] $participantIds tournament_participants ids
     */
    private function startBoosterDraftPods(int $tournamentId, array $participantIds): void
    {
        $cardIdsByRarity = ['mythic' => [], 'rare' => [], 'uncommon' => [], 'common' => []];
        foreach (CardCatalog::load()['rowsById'] as $cardId => $row) {
            $cardIdsByRarity[$row['rarity']][] = $cardId;
        }

        shuffle($participantIds);
        $podSizes = $this->podBuilder->podSizes(count($participantIds));

        $offset = 0;
        foreach ($podSizes as $podIndex => $podSize) {
            $podId = $this->pods->createPod($tournamentId, $podIndex + 1);
            $podParticipantIds = [];
            for ($seat = 0; $seat < $podSize; $seat++) {
                $podParticipantIds[] = $this->pods->addParticipant($podId, $participantIds[$offset + $seat], $seat);
            }
            foreach ($podParticipantIds as $podParticipantId) {
                $this->pods->createBooster($podId, $podParticipantId, 'left', $this->boosterPackBuilder->buildBooster($cardIdsByRarity));
                $this->pods->createBooster($podId, $podParticipantId, 'right', $this->boosterPackBuilder->buildBooster($cardIdsByRarity));
            }
            $offset += $podSize;
        }
    }

    /**
     * Grid Draft's "Pod draft (once)" AND "Pods with playoffs" tournament
     * options (issue #91 follow-up) share this exact same pod-forming
     * step: splits $participantIds into pods of <=4
     * (GridDraftPodBuilder::podSizes(), as even as possible -- Grid
     * Draft's own drafting mechanic only supports up to 4 simultaneous
     * drafters at all), assigns each a random seat_order within its own
     * pod (same "nothing about seeding should predict who drafts with
     * whom" rationale startBoosterDraftPods() already follows), and
     * creates one ordinary Grid Draft game per pod (kind left at its own
     * 'regular' default either way), seating exactly that pod's own
     * members -- GameService::createGame() itself deals the grid
     * immediately (Grid Draft's own drafting phase begins as part of
     * game creation, not a later startGame() call), so there's nothing
     * further to do here. The tournament sits in 'drafting' status until
     * every pod's own game has every seat's deck submitted (see
     * GameService::submitDraftDeck()'s own onDraftDeckSubmitted() hook,
     * and this class's own implementation of it below) -- what happens
     * next is the one place the two options actually diverge: "Pod draft
     * (once)" materializes the real bracket/Swiss round 1 once every pod
     * is done, mixing players across pods freely, while "Pods with
     * playoffs" instead starts THIS pod's own bracket immediately
     * (startPodBracket()), independent of every other pod's own
     * progress.
     *
     * @param int[] $participantIds tournament_participants ids
     */
    private function startGridDraftPods(int $tournamentId, array $participantIds, array $tournament): void
    {
        $poolSource = (string) ($tournament['match_params']['grid_draft_pool_source'] ?? 'random_48');

        shuffle($participantIds);
        $podSizes = $this->gridDraftPodBuilder->podSizes(count($participantIds));

        $offset = 0;
        foreach ($podSizes as $podIndex => $podSize) {
            $podParticipantIds = array_slice($participantIds, $offset, $podSize);
            $offset += $podSize;

            $userIds = [];
            foreach ($podParticipantIds as $participantId) {
                $userIds[] = (int) $this->participants->find($participantId)['user_id'];
            }

            try {
                $gameId = $this->games->createGame(
                    createdByUserId: $userIds[0],
                    userIds: $userIds,
                    format: 'draft',
                    deckType: 'grid_draft',
                    gridDraftPoolSource: $poolSource,
                );
            } catch (GameStateException $e) {
                throw new TournamentStateException("Couldn't start a Grid Draft pod: {$e->getMessage()}", previous: $e);
            }

            $podId = $this->pods->createPod($tournamentId, $podIndex + 1, $gameId);
            foreach ($podParticipantIds as $seat => $participantId) {
                $this->pods->addParticipant($podId, $participantId, $seat);
            }
        }
    }

    /**
     * A pod participant's own pick for the pod's CURRENT round -- one
     * call per direction ('left'/'right') per round, both required
     * before that round advances (TournamentPodRepository::countPicksForRound()).
     * $cardId must still be present in whichever booster the circulation
     * math (BoosterDraftPodBuilder::openerSeatHeldBy()) currently places
     * at this seat. Once the whole pod's own round 15 completes, every
     * seat's 30 total picks are copied into
     * tournament_participants.draft_pool_card_ids and the pod is marked
     * 'completed' -- once every pod for this tournament is 'completed',
     * the bracket/Swiss actually materializes (maybeFinishDrafting()).
     */
    public function pickBoosterDraftCard(int $tournamentId, int $userId, string $direction, int $cardId): void
    {
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new TournamentStateException('direction must be "left" or "right"');
        }

        $tournament = $this->requireTournament($tournamentId);
        if ($tournament['status'] !== 'drafting') {
            throw new TournamentStateException('This tournament is not currently drafting');
        }

        $participant = $this->participants->findForUser($tournamentId, $userId);
        if ($participant === null) {
            throw new NotAuthorizedForTournamentException("You're not part of this tournament");
        }

        $podParticipant = $this->pods->findPodParticipantForParticipant((int) $participant['id']);
        if ($podParticipant === null) {
            throw new TournamentStateException("You're not seated in a Booster Draft pod");
        }

        $pod = $this->pods->findPod((int) $podParticipant['pod_id']);
        if ($pod === null || $pod['status'] !== 'drafting') {
            throw new TournamentStateException('Your pod has already finished drafting');
        }
        $round = (int) $pod['current_round'];

        if ($this->pods->hasPicked((int) $podParticipant['id'], $round, $direction)) {
            throw new TournamentStateException('You have already picked for this round');
        }

        $podSize = count($this->pods->listPodParticipants((int) $pod['id']));
        $openerSeat = BoosterDraftPodBuilder::openerSeatHeldBy((int) $podParticipant['seat_order'], $direction, $round, $podSize);
        $opener = $this->pods->findPodParticipantBySeat((int) $pod['id'], $openerSeat);
        $booster = $this->pods->findBooster((int) $opener['id'], $direction);

        $pickIndex = array_search($cardId, $booster['remaining_card_ids'], true);
        if ($pickIndex === false) {
            throw new TournamentStateException('That card is not available to pick from this booster');
        }

        $remaining = $booster['remaining_card_ids'];
        unset($remaining[$pickIndex]);
        $this->pods->updateBoosterRemainingCardIds((int) $booster['id'], array_values($remaining));
        $this->pods->recordPick((int) $podParticipant['id'], $round, $direction, $cardId);

        if ($this->pods->countPicksForRound((int) $pod['id'], $round) === $podSize * 2) {
            $this->advanceBoosterDraftPod($tournamentId, $pod);
        }
    }

    private function advanceBoosterDraftPod(int $tournamentId, array $pod): void
    {
        $round = (int) $pod['current_round'];
        if ($round < 15) {
            $this->pods->advanceRound((int) $pod['id'], $round + 1);

            return;
        }

        foreach ($this->pods->listPodParticipants((int) $pod['id']) as $podParticipant) {
            $picks = $this->pods->listPicksForParticipant((int) $podParticipant['id']);
            $this->participants->setDraftPoolCardIds((int) $podParticipant['participant_id'], $picks);
        }
        $this->pods->markCompleted((int) $pod['id']);
        $this->maybeFinishDrafting($tournamentId);
    }

    /**
     * A participant's own live Booster Draft pod state -- whichever of
     * their currently-held left/right boosters they haven't yet picked
     * from this round (null once picked, or once their pod is
     * 'completed'), plus their own drafted-so-far pool (accumulating
     * toward 30 total). Throws if they're not seated in any pod for this
     * tournament at all.
     *
     * @return array{pod_status: string, current_round: int, total_rounds: int, pod_size: int, drafted_card_ids: int[], left: ?int[], right: ?int[]}
     */
    public function getPodDraftState(int $tournamentId, int $userId): array
    {
        $participant = $this->participants->findForUser($tournamentId, $userId);
        if ($participant === null) {
            throw new NotAuthorizedForTournamentException("You're not part of this tournament");
        }

        $podParticipant = $this->pods->findPodParticipantForParticipant((int) $participant['id']);
        if ($podParticipant === null) {
            throw new TournamentStateException("You're not seated in a Booster Draft pod");
        }

        $pod = $this->pods->findPod((int) $podParticipant['pod_id']);
        $podSize = count($this->pods->listPodParticipants((int) $pod['id']));
        $round = (int) $pod['current_round'];

        $result = [
            'pod_status' => $pod['status'],
            'current_round' => $round,
            'total_rounds' => 15,
            'pod_size' => $podSize,
            'drafted_card_ids' => $this->pods->listPicksForParticipant((int) $podParticipant['id']),
            'left' => null,
            'right' => null,
        ];

        if ($pod['status'] === 'drafting') {
            foreach (['left', 'right'] as $direction) {
                if ($this->pods->hasPicked((int) $podParticipant['id'], $round, $direction)) {
                    continue;
                }
                $openerSeat = BoosterDraftPodBuilder::openerSeatHeldBy((int) $podParticipant['seat_order'], $direction, $round, $podSize);
                $opener = $this->pods->findPodParticipantBySeat((int) $pod['id'], $openerSeat);
                $booster = $this->pods->findBooster((int) $opener['id'], $direction);
                $result[$direction] = $booster['remaining_card_ids'];
            }
        }

        return $result;
    }

    /** Once every one of this tournament's pods has finished drafting, materializes the real bracket/Swiss round 1 -- see startBoosterDraftPods()'s own docblock. */
    private function maybeFinishDrafting(int $tournamentId): void
    {
        foreach ($this->pods->listPodsForTournament($tournamentId) as $pod) {
            if ($pod['status'] !== 'completed') {
                return;
            }
        }

        $tournament = $this->requireTournament($tournamentId);
        $seedToParticipantId = [];
        foreach ($this->participants->listForTournament($tournamentId) as $participant) {
            if ($participant['seed'] !== null) {
                $seedToParticipantId[(int) $participant['seed']] = (int) $participant['id'];
            }
        }
        ksort($seedToParticipantId);

        $this->tournaments->markInProgressAfterDrafting($tournamentId);
        $this->materializeBracket($tournament, $seedToParticipantId);
    }

    /**
     * $podId ties every round this materializes to one Grid Draft "Pods
     * with playoffs" pod's own bracket (see startPodBracket()'s own
     * docblock) -- null (the default) for a tournament's own single
     * shared bracket. A pod's own bracket is always single elimination
     * regardless of $tournament['bracket_type'] (forced here, not just
     * at creation time, since createTournament() only forces the STORED
     * value to match -- this is what actually decides which
     * TournamentBracketBuilder method runs).
     */
    private function materializeEliminationBracket(array $tournament, array $seedToParticipantId, ?int $podId = null): void
    {
        $tournamentId = (int) $tournament['id'];
        $bracketType = $podId !== null ? 'single_elimination' : $tournament['bracket_type'];
        $plan = $bracketType === 'double_elimination'
            ? $this->bracketBuilder->buildDoubleElimination(count($seedToParticipantId))
            : $this->bracketBuilder->buildSingleElimination(count($seedToParticipantId));

        // Phase 1: insert every round and every match, recording each
        // (bracket, round, slot) coordinate's real inserted id so phase 2
        // can translate the plan's own coordinate-based advance edges
        // into real foreign keys.
        $matchIdByCoordinate = [];
        $matchRowById = [];
        foreach ($plan['rounds'] as $round) {
            $roundId = $this->matches->createRound($tournamentId, $round['bracket'], $round['round_number'], $podId);
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
        $deckType = (string) ($params['deck_type'] ?? 'structure');
        $isBoosterDraft = $deckType === 'booster_draft';
        $isGridDraftPod = $deckType === 'grid_draft_pod';
        $isGridDraftPodPlayoff = $deckType === 'grid_draft_pod_playoff';
        // Booster Draft, Grid Draft's own "Pod draft (once)" option, AND
        // Grid Draft's own "Pods with playoffs" option (every one of a
        // pod's own bracket matches, not just the finals') all play
        // their real matches as ordinary 'custom_duel' games restricted
        // to each side's own tournament-drafted pool -- see the
        // $duelDeckRules/$perSeatAllowedCardIds docblock just below.
        $isPodDraft = $isBoosterDraft || $isGridDraftPod || $isGridDraftPodPlayoff;
        // See createTournament()'s own docblock -- Traditional's fixed,
        // once-per-tournament Structure deck (structure_deck_card_ids),
        // generated there rather than left for GameService to build a
        // fresh random one for every game the way an ordinary
        // non-tournament Traditional game still does.
        $isFixedStructureDeck = $deckType === 'structure' && isset($params['structure_deck_card_ids']);

        // Booster Draft's and Grid Draft's own "Pod draft (once)" option
        // are both tournament-only match_params.deck_type sentinels --
        // GameService has no idea what either means (there's no
        // algorithmic way to build "a subset of this specific player's
        // own drafted pool" from a bare deck_type string the way
        // structure/power/etc. do). Every actual match instead plays as
        // an ordinary 'custom_duel' game, restricted per seat to that
        // participant's own tournament_participants.draft_pool_card_ids
        // via $perSeatAllowedCardIds -- see GameService::createGame()'s
        // own docblock for that param, and submitCustomDuelDeck()'s own
        // pool-membership check.
        $duelDeckRules = match (true) {
            $isBoosterDraft => ['preset' => 'user_defined', 'min_cards' => self::BOOSTER_DRAFT_MIN_DECK_SIZE],
            $isGridDraftPod || $isGridDraftPodPlayoff => ['preset' => 'user_defined', 'min_cards' => self::GRID_DRAFT_POD_MIN_DECK_SIZE],
            default => $params['duel_deck_rules'] ?? null,
        };
        $perSeatAllowedCardIds = $isPodDraft
            ? [$user1Id => $participant1['draft_pool_card_ids'], $user2Id => $participant2['draft_pool_card_ids']]
            : null;

        try {
            $gameId = $this->games->createGame(
                createdByUserId: $user1Id,
                userIds: [$user1Id, $user2Id],
                format: $isPodDraft ? 'duel' : $format,
                winsNeeded: (int) ($params['wins_needed'] ?? 3),
                deckType: $isPodDraft ? 'custom_duel' : ($isFixedStructureDeck ? 'custom' : $deckType),
                decklistText: $params['decklist_text'] ?? null,
                duelDeckRules: $duelDeckRules,
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
                perSeatAllowedCardIds: $perSeatAllowedCardIds,
                fixedCustomDeckCardIds: $isFixedStructureDeck ? $params['structure_deck_card_ids'] : null,
            );
        } catch (GameStateException $e) {
            throw new TournamentStateException("Couldn't start a tournament match between the fixed match settings and these two players: {$e->getMessage()}", previous: $e);
        }

        $wrapperIds = $this->games->gameMatchWrapperIds($gameId);
        $this->matches->markGameCreated((int) $tournamentMatch['id'], $gameId, $wrapperIds['game_match_id'], $wrapperIds['draft_match_id']);

        // Power Duel's own join-time deck (issue reported live: "the
        // deck submission should happen when the player joins the
        // tournament -- players use the same submitted deck for the
        // entire tournament") -- carried forward onto this match's own
        // game 1 the moment it's created, straight from each
        // participant's own tournament_participants row (already
        // validated once, at join time, by resolveJoinTimeDeck()), so
        // startGame() below can succeed immediately instead of waiting
        // on a fresh per-match submission. $isPodDraft is excluded --
        // Booster/Grid Draft's own 'custom_duel' games always draw from
        // a per-tournament-match drafted pool, never a join-time deck
        // (tournament_participants.deck_card_ids stays null for them).
        // A legacy tournament predating this feature (joined before
        // migration 0345, deck_card_ids still null) is left untouched
        // here, falling back to the original per-game submission flow
        // exactly as before.
        if ($deckType === 'custom_duel' && !$isPodDraft) {
            foreach ([[$user1Id, $participant1], [$user2Id, $participant2]] as [$seatUserId, $seatParticipant]) {
                if ($seatParticipant['deck_card_ids'] !== null) {
                    $this->games->seedCustomDuelDeckFromTournament(
                        $gameId,
                        $seatUserId,
                        $seatParticipant['deck_name'],
                        $seatParticipant['deck_card_ids'],
                        $seatParticipant['deck_sideboard_card_ids'],
                    );
                }
            }
        }

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

    /**
     * Booster Draft's own persistent "current deck" (issue #91 follow-up)
     * -- see the interface's own docblock for when GameService actually
     * calls this (only ever for a seat carrying its own
     * custom_deck_allowed_card_ids restriction, i.e. a Booster Draft
     * tournament match). Resolved back to the right tournament_matches
     * row the exact same way onMatchConcluded() is, then to that match's
     * OWN participant matching $userId (never the opponent).
     */
    public function onCustomDuelDeckSubmitted(int $gameId, ?int $gameMatchId, int $gamePlayerId, int $userId, array $cardIds): void
    {
        $tournamentMatch = $gameMatchId !== null
            ? $this->matches->findByGameMatchId($gameMatchId)
            : $this->matches->findByGameId($gameId);
        if ($tournamentMatch === null) {
            return;
        }

        $participant = $this->participants->findForUser((int) $tournamentMatch['tournament_id'], $userId);
        if ($participant === null) {
            return;
        }

        $this->participants->setCurrentDeckCardIds((int) $participant['id'], $cardIds);
    }

    /**
     * Grid Draft's own "Pod draft (once)" AND "Pods with playoffs"
     * tournament options (issue #91 follow-up) -- see the interface's
     * own docblock for when GameService actually calls this. A no-op for
     * any draft match that isn't a Grid Draft pod's own backing game at
     * all (findPodByGameId() returns null for an ordinary ad hoc drafted
     * game), and for one whose pod already finished drafting (idempotent
     * against $everyoneSubmitted staying true on a later resubmission,
     * or two submissions racing -- whichever request's own query sees
     * the pod already past 'drafting' simply does nothing). Once every
     * seat's deck is in: copies each seat's own final drafted_card_ids
     * into tournament_participants.draft_pool_card_ids (the exact same
     * field Booster Draft's own pod-completion uses -- overwriting
     * whatever this participant's own pod-stage pool held before is
     * exactly right for the FINAL pod of a "Pods with playoffs"
     * tournament, since by the time the finals even start every one of
     * their own earlier pod-stage matches has already resolved) and
     * abandons the now-superfluous backing game (it only ever existed to
     * run the shared drafting UI -- see GameService::abandonDraftGame()'s
     * own docblock for why it's never actually played).
     *
     * What happens next is the one place the two options diverge: "Pod
     * draft (once)" marks the pod 'completed' outright and checks
     * whether every pod for this tournament is now done
     * (maybeFinishDrafting()) to materialize the one shared bracket;
     * "Pods with playoffs" has no shared bracket at all -- THIS pod
     * (regular or final, doesn't matter) instead starts playing its own
     * (startPodBracket()), entirely independent of every other pod's own
     * progress.
     */
    public function onDraftDeckSubmitted(int $gameId, int $draftMatchId, bool $everyoneSubmitted): void
    {
        if (!$everyoneSubmitted) {
            return;
        }

        $pod = $this->pods->findPodByGameId($gameId);
        if ($pod === null || $pod['status'] !== 'drafting') {
            return;
        }

        $draftedCardIdsByUserId = $this->games->draftedCardIdsByUserForDraftMatch($draftMatchId);
        $podParticipants = $this->pods->listPodParticipants((int) $pod['id']);
        foreach ($podParticipants as $podParticipant) {
            $participant = $this->participants->find((int) $podParticipant['participant_id']);
            $userId = (int) $participant['user_id'];
            $this->participants->setDraftPoolCardIds((int) $podParticipant['participant_id'], $draftedCardIdsByUserId[$userId] ?? []);
        }
        $this->games->abandonDraftGame($gameId);

        $tournament = $this->requireTournament((int) $pod['tournament_id']);
        if ($this->isGridDraftPodPlayoff($tournament)) {
            $this->startPodBracket($tournament, $pod, $podParticipants);

            return;
        }

        $this->pods->markCompleted((int) $pod['id']);
        $this->maybeFinishDrafting((int) $pod['tournament_id']);
    }

    /**
     * Grid Draft's "Pods with playoffs" tournament option (issue #91
     * follow-up): once a pod (regular or final -- both play out their
     * own bracket identically) finishes drafting, its own seats play a
     * single-elimination bracket among themselves, restricted to their
     * own just-drafted pools exactly like "Pod draft (once)"'s own
     * shared bracket restricts every match (see startMatchGame()'s own
     * docblock -- $isGridDraftPodPlayoff there covers this too). Always
     * single elimination regardless of the tournament's own bracket_type
     * (createTournament() already forces the STORED value to match, but
     * materializeEliminationBracket() is what actually enforces it) --
     * pods only ever have 2-4 players, where double elimination/Swiss
     * add real complexity for no benefit. Seeded independently of the
     * top-level tournament seeding -- nothing about which pod someone
     * landed in, or their seat within it, should predict their own
     * pod's own bracket strength, same rationale every other random
     * seeding in this class already follows.
     */
    private function startPodBracket(array $tournament, array $pod, array $podParticipants): void
    {
        $this->pods->markPlaying((int) $pod['id']);

        $participantIds = array_map(static fn (array $pp): int => (int) $pp['participant_id'], $podParticipants);
        shuffle($participantIds);
        $seedToParticipantId = [];
        foreach ($participantIds as $index => $participantId) {
            $seedToParticipantId[$index + 1] = $participantId;
        }

        $this->materializeEliminationBracket($tournament, $seedToParticipantId, (int) $pod['id']);
    }

    /**
     * Grid Draft's "Pods with playoffs" tournament option (issue #91
     * follow-up): one pod's own bracket (regular or final) has just
     * decided its own winner -- see resolveMatchResult()'s own "which
     * bracket was this" branch for when this actually fires. The FINAL
     * pod's own winner is the tournament champion outright, nothing left
     * to decide. Otherwise this was one of the REGULAR pods formed at
     * tournament start: once every one of them has its own winner,
     * either that lone winner is the champion already (a tournament
     * small enough to fit in a single pod never needed a finals stage at
     * all) or every regular pod's own winner drafts together, once, in
     * one new FINAL pod (startGridDraftPodPlayoffFinals()) whose own
     * bracket -- always <=4 players, since a tournament is capped at 16
     * participants / pods of <=4 -- decides the tournament outright.
     */
    private function onPodBracketFinished(int $tournamentId, int $podId, int $winnerParticipantId): void
    {
        $this->pods->recordWinner($podId, $winnerParticipantId);
        $pod = $this->pods->findPod($podId);

        if ($pod['kind'] === 'final') {
            $this->finishTournament($tournamentId, $winnerParticipantId);

            return;
        }

        $regularPods = array_values(array_filter(
            $this->pods->listPodsForTournament($tournamentId),
            static fn (array $p): bool => $p['kind'] === 'regular'
        ));
        foreach ($regularPods as $regularPod) {
            if ($regularPod['status'] !== 'completed') {
                return;
            }
        }

        if (count($regularPods) === 1) {
            $this->finishTournament($tournamentId, (int) $regularPods[0]['winner_participant_id']);

            return;
        }

        $tournament = $this->requireTournament($tournamentId);
        $finalistParticipantIds = array_map(static fn (array $p): int => (int) $p['winner_participant_id'], $regularPods);
        $this->startGridDraftPodPlayoffFinals($tournamentId, $finalistParticipantIds, $tournament, count($regularPods) + 1);
    }

    /**
     * Grid Draft's "Pods with playoffs" tournament option (issue #91
     * follow-up): every regular pod's own winner drafts together, once,
     * in a single new FINAL pod (kind: 'final') -- mirrors
     * startGridDraftPods() almost exactly (one ordinary Grid Draft game,
     * seating every named participant, drafting begins immediately as
     * part of game creation), just seating a specific already-decided
     * roster instead of splitting a fresh field into multiple pods,
     * since there is only ever exactly one final pod. Once every one of
     * these finalists submits their own deck, onDraftDeckSubmitted()
     * starts this pod's own bracket (startPodBracket()) exactly like any
     * other pod's -- onPodBracketFinished() is what actually recognizes
     * kind: 'final' and ends the tournament there.
     *
     * @param int[] $finalistParticipantIds every regular pod's own winner
     */
    private function startGridDraftPodPlayoffFinals(int $tournamentId, array $finalistParticipantIds, array $tournament, int $podNumber): void
    {
        $poolSource = (string) ($tournament['match_params']['grid_draft_pool_source'] ?? 'random_48');

        shuffle($finalistParticipantIds);
        $userIds = [];
        foreach ($finalistParticipantIds as $participantId) {
            $userIds[] = (int) $this->participants->find($participantId)['user_id'];
        }

        try {
            $gameId = $this->games->createGame(
                createdByUserId: $userIds[0],
                userIds: $userIds,
                format: 'draft',
                deckType: 'grid_draft',
                gridDraftPoolSource: $poolSource,
            );
        } catch (GameStateException $e) {
            throw new TournamentStateException("Couldn't start the Grid Draft pod playoff finals: {$e->getMessage()}", previous: $e);
        }

        $podId = $this->pods->createPod($tournamentId, $podNumber, $gameId, kind: 'final');
        foreach ($finalistParticipantIds as $seat => $participantId) {
            $this->pods->addParticipant($podId, $participantId, $seat);
        }
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
            // (single-elimination) bracket -- this was the final. For
            // Grid Draft's "Pods with playoffs" (round['pod_id'] set --
            // see startPodBracket()'s own docblock), that decides one
            // pod's own winner, not necessarily the whole tournament's.
            if ($round['pod_id'] !== null) {
                $this->onPodBracketFinished($tournamentId, (int) $round['pod_id'], $winnerParticipantId);
            } else {
                $this->finishTournament($tournamentId, $winnerParticipantId);
            }
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
        $winnerUserId = (int) $winner['user_id'];
        $this->tournaments->markCompleted($tournamentId, $winnerUserId);

        $tournament = $this->tournaments->find($tournamentId);
        if ($tournament !== null) {
            $tournament['winner_user_id'] = $winnerUserId;
            $this->achievements->onTournamentCompleted($tournament);
        }
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
        // Tournament spectator mode (issue #238): a granted caster who
        // isn't otherwise a participant (e.g. a dedicated streamer) still
        // needs to be able to load this invite-only tournament's own
        // bracket to find the match they're casting -- canViewTournament()
        // already covers the creator too, so this is purely additive.
        if (!$this->canViewTournament($tournament, $viewerUserId)) {
            throw new NotAuthorizedForTournamentException("You're not part of this tournament");
        }
        $viewerHasCastAccess = $this->hasCastAccess($tournamentId, $viewerUserId);
        // Reported live: "all users in the tournament can see who is
        // allowed to cast" -- everyone who reaches this point (a
        // participant, the creator, a granted caster, or anyone for an
        // open-registration tournament) sees the same roster
        // listCastGrants() itself would return them, without a second
        // round-trip.
        $castGrants = $this->castGrants->listForTournament($tournamentId);

        $rounds = $this->matches->listRounds($tournamentId);
        $matchesByRound = [];
        foreach ($rounds as $round) {
            $matchesByRound[(int) $round['id']] = $this->matches->listForRound((int) $round['id']);
        }

        // Booster Draft's own draft_pool_card_ids/current_deck_card_ids
        // (issue #91 follow-up) are scrubbed from every participant
        // row except the viewer's own -- an opponent's drafted pool or
        // current deck is exactly the kind of scouting information a
        // real tournament wouldn't let you see ahead of playing them.
        $participants = array_map(
            fn (array $p): array => (int) $p['user_id'] === $viewerUserId ? $p : [...$p, 'draft_pool_card_ids' => null, 'current_deck_card_ids' => null],
            $this->participants->listForTournament($tournamentId),
        );

        return [
            'tournament' => $tournament,
            'participants' => $participants,
            'rounds' => $rounds,
            'matches_by_round' => $matchesByRound,
            'standings' => $tournament['bracket_type'] === 'swiss' && $tournament['status'] !== 'registration'
                ? $this->swissStandings($tournamentId)
                : null,
            'pods' => ($this->isBoosterDraft($tournament) || $this->isGridDraftPod($tournament) || $this->isGridDraftPodPlayoff($tournament)) ? $this->podsSummary($tournamentId) : null,
            // Tournament spectator mode (issue #238): lets the frontend
            // show "Cast" links on this tournament's in_progress matches
            // for a non-creator caster too, not just the creator (who can
            // already infer it from being the creator).
            'viewer_has_cast_access' => $viewerHasCastAccess,
            // Reported live: a "no hands" cast option -- null when the
            // viewer has no cast access at all (viewer_has_cast_access is
            // false); lets the frontend label its own "Cast" button
            // correctly ("Cast (reveal hands)" vs "Cast (public info
            // only)") without having to search $castGrants for the
            // viewer's own row itself, which wouldn't even find the
            // creator (never listed there -- see hasCastAccess()).
            'viewer_cast_reveals_hands' => $viewerHasCastAccess ? $this->castRevealsHands($tournamentId, $viewerUserId) : null,
            // {id, tournament_id, user_id, username, granted_by_user_id,
            // reveal_hands, created_at}[] -- see listCastGrants()'s own
            // docblock. Reported live: everyone in the tournament should
            // be able to see who's allowed to cast, not just the creator.
            'cast_grants' => $castGrants,
        ];
    }

    /**
     * @return array[] every pod's own status/round/seated participants,
     *     for the tournament view's "drafting" progress display. `game_id`
     *     is Grid Draft's own "Pod draft (once)"/"Pods with playoffs"
     *     pods' backing game (null for a Booster Draft pod, which has no
     *     single backing game) -- the frontend uses it to route
     *     "Continue drafting" to that ordinary game's own board rather
     *     than Booster Draft's own dedicated pod-draft dialog. `kind`
     *     ('regular'/'final') and `winner_username` are only ever
     *     meaningful for "Pods with playoffs" (always 'regular' and null
     *     respectively otherwise); `bracket_rounds` is that same option's
     *     own per-pod bracket (see TournamentMatchRepository::listRoundsForPod()),
     *     empty for every other pod -- the frontend renders it exactly
     *     like the tournament's own top-level bracket, just scoped to
     *     this pod's own seats.
     */
    private function podsSummary(int $tournamentId): array
    {
        $summary = [];
        foreach ($this->pods->listPodsForTournament($tournamentId) as $pod) {
            $podId = (int) $pod['id'];
            $seats = [];
            foreach ($this->pods->listPodParticipants($podId) as $podParticipant) {
                $participant = $this->participants->find((int) $podParticipant['participant_id']);
                $user = $this->users->findById((int) $participant['user_id']);
                $seats[] = [
                    'participant_id' => (int) $podParticipant['participant_id'],
                    'username' => $user['username'] ?? null,
                    'seat_order' => (int) $podParticipant['seat_order'],
                ];
            }

            $bracketRounds = [];
            foreach ($this->matches->listRoundsForPod($podId) as $round) {
                $bracketRounds[] = [
                    'id' => (int) $round['id'],
                    'bracket' => $round['bracket'],
                    'round_number' => (int) $round['round_number'],
                    'matches' => $this->matches->listForRound((int) $round['id']),
                ];
            }

            $winnerUsername = null;
            if ($pod['winner_participant_id'] !== null) {
                $winnerParticipant = $this->participants->find((int) $pod['winner_participant_id']);
                $winnerUsername = $this->users->findById((int) $winnerParticipant['user_id'])['username'] ?? null;
            }

            $summary[] = [
                'pod_number' => (int) $pod['pod_number'],
                'kind' => $pod['kind'],
                'status' => $pod['status'],
                'current_round' => (int) $pod['current_round'],
                'game_id' => $pod['game_id'] !== null ? (int) $pod['game_id'] : null,
                'seats' => $seats,
                'bracket_rounds' => $bracketRounds,
                'winner_username' => $winnerUsername,
            ];
        }

        return $summary;
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

    /**
     * Reported live: a caster granted hands-revealed access sees both
     * players' hands in every match of the tournament, not just their
     * own -- so if they were also allowed to play, they'd carry a
     * scouting advantage into every match they're seated in. A "no
     * hands" grant doesn't trigger this since it never reveals more
     * than a plain issue #128 spectator already sees.
     */
    private function hasFullHandsCastGrant(int $tournamentId, int $userId): bool
    {
        $grant = $this->castGrants->find($tournamentId, $userId);

        return $grant !== null && (bool) $grant['reveal_hands'];
    }

    /**
     * True while $userId still has a live claim on a tournament seat
     * ('invited' or 'joined'). A 'declined' or 'withdrawn' participant
     * is no longer in contention for a match, so they remain eligible
     * for a hands-revealed cast grant same as anyone who never joined.
     */
    private function hasActiveParticipation(int $tournamentId, int $userId): bool
    {
        $participant = $this->participants->findForUser($tournamentId, $userId);

        return $participant !== null && in_array($participant['status'], ['invited', 'joined'], true);
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
