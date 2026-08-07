<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Database\Connection;
use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Notifications\NotificationService;
use MoodSwings\Presence\PresenceService;
use MoodSwings\Repository\GameChatRepository;
use MoodSwings\Repository\GameNoteRepository;
use MoodSwings\Repository\SessionRepository;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\CardChoiceSchema;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\MoodInPlay;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\PlayResult;
use MoodSwings\Rules\RoundScorer;
use MoodSwings\Stats\CardStatsService;
use PDO;
use PDOException;
use Throwable;

/**
 * Wires the pure in-memory rules engine (src/Rules/) to the games/
 * game_players/game_rounds/game_round_scores/game_cards/game_events
 * tables: creating and starting games, resolving one play or pass at a
 * time, and advancing turns/rounds/game completion as they happen. Each
 * public method here is one request/response round trip -- there's no
 * process alive between them, so every bit of turn/round state that
 * matters has to already be in the database by the time a method returns.
 *
 * Round-end also resolves a handful of effectState-tagged hooks set by
 * cards played earlier in the round -- see applyScoreSwaps() (Sneakiness),
 * applyAfterScoringHooks() (Bashfulness/Betrayal/Recklessness/Gluttony/
 * Insecurity), hasSkipScoringMarker()/skipScoringAndAdvance() (Awe), and
 * consumeExtraWinMarker() (Corruption). Every fresh turn's play grants
 * also run through computeFreshGrants(), which layers in whatever
 * perpetual (Hope/Grace/Stubbornness) or one-shot banked
 * (Generosity/Joy) grants the upcoming player's board currently entitles
 * them to, on top of the usual unconditional (Hurt Feelings-aware) base.
 * updateRoundTurnState() also carries forward Vulnerability's
 * discardedThisRound flag every time turn state is written, the same way
 * it does pending_play_grants -- and scoreRoundAndAdvance() takes the
 * already-loaded BoardState from whichever play/pass ended the round
 * rather than reloading, since that flag only lives in memory until
 * these writes persist it.
 *
 * Deliberately out of scope for now: any HTTP/auth layer -- this takes
 * game_player ids directly and treats them as already-authorized.
 */
final class GameService
{
    private const STARTING_HAND_SIZE = 5;
    private const TOTAL_CARDS = 133;
    private const MIN_PLAYERS = 2;
    private const MAX_PLAYERS = 4;

    /**
     * Open Team Play (format 'team') is always exactly two teams of two,
     * seated adjacent to their own partner -- see "Open Team Play" in this
     * file's own docblock references and php-app/README.md. Unlike
     * standard's 2-4 range or duel's fixed 2, this is a single fixed count.
     */
    private const TEAM_PLAYER_COUNT = 4;

    /**
     * A team-format decklist needs the same 45-card minimum the built-in
     * 'structure' deck_type already happens to total (23 + 14 + 6 + 2 --
     * see STRUCTURE_DECK_RARITY_COUNTS) -- 'power' (15 cards) falls short
     * of this and is rejected outright for this format; a 'custom'
     * decklist already gets this exact number for free from
     * parseCustomDecklist()'s own "15 * (playerCount - 1)" formula at 4
     * players, so no separate check is needed for that deck_type.
     */
    private const MIN_TEAM_DECK_SIZE = 45;

    /** cards.rarity's own four values -- see DuelDeckRules. */
    private const RARITIES = ['common', 'uncommon', 'rare', 'mythic'];

    /**
     * The 'structure' deck_type's own card pool: a randomly-assembled,
     * singleton (no duplicates) 45-card deck matching a new physical box's
     * printed rarity distribution, rather than the full 133-card
     * one-of-everything pool ('one_of_each') that was the only option
     * before this existed. See buildStructureDeckCardIds().
     */
    private const STRUCTURE_DECK_RARITY_COUNTS = [
        'common' => 23,
        'uncommon' => 14,
        'rare' => 6,
        'mythic' => 2,
    ];

    /**
     * The 'power' deck_type's own non-Mythic card count -- see
     * buildPowerDeckCardIds(), which pairs this many random non-Mythic
     * cards with exactly one random Mythic (15 total).
     */
    private const POWER_DECK_NON_MYTHIC_COUNT = 14;

    /** The 'jceddys_75' deck_type's own five colors, one built independently per color -- see buildJceddys75DeckCardIds(). */
    private const JCEDDYS_75_DECK_COLORS = ['white', 'blue', 'black', 'red', 'green'];

    /**
     * Per-rarity card count and max-copies-per-distinct-card cap for one
     * color of a 'jceddys_75' deck -- 1 Mythic, 2 *different* Rares (a cap
     * of 1 forces that), 4 Uncommons (up to 2 copies of any one), and 8
     * Commons (up to 3 copies of any one): 15 cards per color, 75 total
     * across JCEDDYS_75_DECK_COLORS. See buildJceddys75DeckCardIds().
     */
    private const JCEDDYS_75_DECK_RARITY_SPEC = [
        'mythic' => ['count' => 1, 'max_copies' => 1],
        'rare' => ['count' => 2, 'max_copies' => 1],
        'uncommon' => ['count' => 4, 'max_copies' => 2],
        'common' => ['count' => 8, 'max_copies' => 3],
    ];

    /**
     * jceddy's 150 Card deck's own per-color spec -- used only for a
     * 4-player Quick Draft/Grid Draft 'jceddys_75' pool (the one player
     * count whose own target pool size, 96/90, exceeds jceddy's 75 Card
     * deck's 75 cards). Not a literal doubling of the 75-card pool (which
     * would just duplicate the same 75 card ids) -- its own themed
     * 150-card construction, every one of JCEDDYS_75_DECK_RARITY_SPEC's
     * count/max_copies pairs doubled: 2 Mythics, 4 *different* Rares, 8
     * Uncommons (up to 2 copies of any one), and 16 Commons (up to 3
     * copies of any one) per color -- 30 cards per color, 150 total across
     * JCEDDYS_75_DECK_COLORS (10 Mythics/20 Rares/40 Uncommons/80 Commons
     * overall). See buildJceddys150DeckCardIds().
     */
    private const JCEDDYS_150_DECK_RARITY_SPEC = [
        'mythic' => ['count' => 2, 'max_copies' => 1],
        'rare' => ['count' => 4, 'max_copies' => 1],
        'uncommon' => ['count' => 8, 'max_copies' => 2],
        'common' => ['count' => 16, 'max_copies' => 3],
    ];

    /**
     * Default for $gameLockTimeoutSeconds below: how long playMood()/
     * pass()/respondToDecision() wait to acquire a game's lock (see
     * withGameLock()) before giving up. Generous relative to how long a
     * single request actually takes -- this only matters when two
     * requests for the same game genuinely overlap (the same player's two
     * tabs, a double-click), which resolves in well under a second
     * normally; this is a backstop against a stuck/slow request, not a
     * number anyone should ever be expected to wait out. Overridable via
     * the constructor so tests can prove the lock is actually enforced
     * without a multi-second wait.
     */
    private const GAME_LOCK_TIMEOUT_SECONDS = 10;

    /**
     * Enthusiasm's/Passion's own scoring-time decisions -- see
     * RoundScorer's docblock for why these two (unlike Exhilaration/
     * Bliss) need an explicit answer rather than being applied
     * automatically. Distinguished from every other decision_type by
     * this list rather than a separate batch-level column, since a
     * scoring decision is otherwise stored the same way as a mid-play one
     * (see writeScoringDecisionBatch()).
     */
    private const ENTHUSIASM_DECISION_TYPE = 'enthusiasm_extra_score';
    private const PASSION_DECISION_TYPE = 'passion_score_opponent_mood';
    private const SCORING_DECISION_TYPES = [self::ENTHUSIASM_DECISION_TYPE, self::PASSION_DECISION_TYPE];

    /**
     * A player's own choice of which order their "after scoring" effects
     * resolve in, per the rules-committee ruling on Recklessness/Betrayal
     * (see applyAfterScoringHooks()'s own docblock): asked once scores are
     * final and it's known which cards' after-scoring effects will
     * actually fire, so -- like SCORING_DECISION_TYPES above -- this is a
     * distinct scoring-time decision, not a mid-play one, even though it's
     * stored via the exact same game_pending_decision_batches/decisions
     * machinery. Unlike Enthusiasm/Passion (asked BEFORE scores are
     * computed, since they feed RoundScorer::score() itself), this is
     * asked strictly AFTER -- see nextUnresolvedAfterScoringOrderDecision()
     * and determineRoundWinner().
     */
    private const AFTER_SCORING_ORDER_DECISION_TYPE = 'after_scoring_order';

    /**
     * Quick Draft (issue #88, format 'draft' only; multiplayer 2-4 player
     * support added for issue #189): a shared pool of quickDraftPoolTargetSize()
     * cards, drafted over quickDraftRounds() rounds. Each round, every
     * player is dealt their own quickDraftPileSize() pile; each of that
     * pile's quickDraftPileSize()/QUICK_DRAFT_KEEP_PER_STAGE "takes" is
     * made by a different player in turn (the pile's original owner first,
     * then whoever it's passed to, and so on) -- since quickDraftPileSize()
     * = 2 * playerCount + 2, that's exactly playerCount takes before only 2
     * cards (discarded) remain, meaning every pile completes exactly one
     * full lap of every seated player. See submitQuickDraftPick()'s own
     * docblock for the seat-rotation math this relies on, and
     * dealQuickDraftRound()/finalizeQuickDraft() for how a round's dealing
     * and a match's final drafted_card_ids are derived. Every player nets
     * 2 * playerCount cards per round, and quickDraftRounds() is chosen per
     * player count so every player clears at least a 16-card pool (2p: 4
     * rounds = 16; 3p: 3 rounds = 18; 4p: 2 rounds = 16) to build a
     * QUICK_DRAFT_MIN_DECK_SIZE-card deck from -- unlike the original
     * 2-player-only version, there's no fixed max deck size: a player's
     * deck can be as large as everything they actually drafted (see
     * submitDraftDeck()).
     *
     * quickDraftMinCustomPoolSize() is the floor for a 'custom' pool
     * (smaller than that and a full draft can't be dealt even with
     * dealQuickDraftRound()'s own discard-reshuffle top-up) -- one
     * structure-deck's worth (45) per 2 seated players, rounding up,
     * mirroring the "1 structure deck for 2 players, 2 for 3-4" pool
     * variation.
     */
    private const QUICK_DRAFT_POOL_SIZE_PER_PLAYER = 24;
    private const QUICK_DRAFT_MIN_CUSTOM_POOL_SIZE_PER_TWO_PLAYERS = 45;
    private const QUICK_DRAFT_KEEP_PER_STAGE = 2;
    private const QUICK_DRAFT_MIN_DECK_SIZE = 12;

    /**
     * Winston Draft (issue #89, format 'draft' alongside 'quick_draft';
     * multiplayer support issue #189): players take-or-pass on 3 growing
     * face-down piles, in seat-rotation turn order (2 players alternate,
     * 3-4 players cycle through every seat -- see initializeWinstonDraft()/
     * submitWinstonDraftPick()), dealt from a shared shuffled pool of
     * winstonDraftPoolTargetSize($playerCount) cards. The 2-player target
     * (45) matches the physical rules' own "Total number of cards
     * drafted: 45" (the same size as MSW's own Structure deck); 3-4
     * players scale up to 70/90 rather than a flat +15/player increment,
     * so every additional seat still gets a meaningfully bigger shared
     * pool to draft from over the same 3-pile structure. Unlike Quick
     * Draft's fixed 16-per-player result, the number of cards each player
     * ends up with here varies with how the draft unfolds, so there's no
     * WINSTON_MAX_DECK_SIZE -- only a floor (WINSTON_MIN_DECK_SIZE,
     * matching the physical rules' "must have a minimum of twelve
     * cards"). winstonDraftMinCustomPoolSize() equals the target size
     * itself -- unlike Quick Draft's 45-vs-48 gap, there's no
     * reshuffle-top-up mechanic to fall back on for an undersized pool,
     * since the physical rules already define "the deck runs out" as a
     * normal, expected event rather than something to avoid. A player who
     * ends up short of WINSTON_MIN_DECK_SIZE when the draft ends is
     * dropped from the match entirely (see finalizeWinstonDraft()) rather
     * than failing the whole match the way the original 2-player-only
     * "the other player automatically wins" rule did -- with 3-4 players,
     * one player's shortfall shouldn't cost everyone else their match.
     */
    private const WINSTON_MIN_DECK_SIZE = 12;

    /**
     * Winston Draft's own per-player-count pool target -- 45/70/90 for
     * 2/3/4 players (issue #189). Not a flat +15/player increment (that
     * would be 45/60/75); the explicit jump to 70 and 90 keeps every
     * additional seat meaningfully better-stocked, and specifically
     * ensures jceddy's 75 Card deck's own 75 cards still cover a 3-player
     * pool (70) without needing jceddy's 150 Card deck's own themed
     * 150-card pool -- that swap only ever triggers at exactly 4 players
     * (90 > 75), the same trigger point as Quick Draft's/Grid Draft's own
     * jceddys_75 handling (see buildDraftPool()).
     */
    private static function winstonDraftPoolTargetSize(int $playerCount): int
    {
        return match ($playerCount) {
            2 => 45,
            3 => 70,
            4 => 90,
            default => throw new GameStateException("Winston Draft does not support {$playerCount} players"),
        };
    }

    /** @see winstonDraftPoolTargetSize()'s own docblock for why this equals the target size itself. */
    private static function winstonDraftMinCustomPoolSize(int $playerCount): int
    {
        return self::winstonDraftPoolTargetSize($playerCount);
    }

    /**
     * Grid Draft (issue #188, format 'draft' alongside 'quick_draft'/
     * 'winston_draft'; multiplayer support issue #189; 4-player 4x4 grid
     * issue #189 follow-up): a shared pool, dealt gridDraftCardsPerRound()
     * cards at a time into an gridDraftGridSize() x gridDraftGridSize()
     * grid over gridDraftRounds() rounds -- a 3x3 grid over 6 rounds for
     * 2-3 players, or a 4x4 grid over exactly 4 rounds for 4 players
     * specifically (chosen so each of the 4 players gets to pick first in
     * exactly one round, rather than the uneven distribution 6 rounds
     * would give 4 players -- see gridDraftRounds()'s own docblock), every
     * round dealt from a completely fresh grid regardless of player
     * count. Each round, every seated player takes one turn (whoever's
     * first rotates every round, see initializeGridDraft()/
     * submitGridDraftPick()), taking an entire row or column -- always a
     * full line's worth of cards for a player whose turn falls among the
     * round's first playerCount-2 picks (the grid is refilled back to
     * full immediately after each of those, see
     * gridDraftCardsDrawnPerRound()'s own docblock), but possibly fewer
     * for the round's LAST TWO picks specifically, which never get
     * refilled -- the second (and, for 3-4 players, third/fourth) picker
     * may get fewer cards if their choice crosses a still-empty cell left
     * by an unrefilled earlier pick this same round, derived purely by
     * counting non-null cells, never a stored intersection flag.
     * Whatever's left in the grid once the round's last pick is made is
     * simply discarded, never reshuffled back into the pool. This
     * degenerates cleanly to the original 2-player mechanic (its own
     * "second pick might get only 2 cards" quirk): with only 2 players,
     * EVERY pick is among "the round's last two," so nothing ever
     * refills mid-round, exactly as it always has.
     *
     * Like Winston Draft, the number of cards each player ends up with
     * varies, so there's no max deck size, only GRID_DRAFT_MIN_DECK_SIZE.
     * GRID_DRAFT_MIN_CUSTOM_POOL_SIZE (see gridDraftMinCustomPoolSize())
     * equals the target pool size itself, for the same reason as
     * Winston's own -- no reshuffle-top-up mechanic exists to fall back
     * on for an undersized pool, so an insufficient pool is rejected
     * outright at draft-creation time instead.
     */
    private const GRID_DRAFT_MIN_DECK_SIZE = 12;

    /**
     * The grid's own side length: 4 (a 4x4, 16-cell grid) for exactly 4
     * players, 3 (the original 3x3, 9-cell grid) for 2-3 players. Also
     * doubles as gridDraftRefillBatchSize()'s own value, since a
     * row/column always has exactly this many cells.
     */
    private static function gridDraftGridSize(int $playerCount): int
    {
        return $playerCount === 4 ? 4 : 3;
    }

    /**
     * How many rounds Grid Draft plays: 4 for exactly 4 players (equal to
     * the player count, so seat rotation -- see submitGridDraftPick() --
     * gives each of the 4 players first pick in exactly one round), 6 for
     * 2-3 players (also an even multiple of the player count: 3 rounds
     * each for 2 players, 2 rounds each for 3).
     */
    private static function gridDraftRounds(int $playerCount): int
    {
        return $playerCount === 4 ? 4 : 6;
    }

    /** How many cells a freshly-dealt grid has: gridDraftGridSize()'s own square. */
    private static function gridDraftCardsPerRound(int $playerCount): int
    {
        return self::gridDraftGridSize($playerCount) ** 2;
    }

    /** A row/column's own length -- see gridDraftGridSize()'s own docblock. */
    private static function gridDraftRefillBatchSize(int $playerCount): int
    {
        return self::gridDraftGridSize($playerCount);
    }

    /**
     * How many cards Grid Draft draws from the pool over the course of
     * one full round for $playerCount seated players: the round's initial
     * gridDraftCardsPerRound() grid, plus one gridDraftRefillBatchSize()
     * refill for every pick except the round's last two (see the
     * GRID_DRAFT_* docblock above) -- 9 for 2 players (no refills at
     * all), 12 for 3 (one refill, after the first pick), 24 for 4 (two
     * refills of 4 cards each, after the first and second picks, against
     * the larger 4x4 grid).
     */
    private static function gridDraftCardsDrawnPerRound(int $playerCount): int
    {
        return self::gridDraftCardsPerRound($playerCount) + self::gridDraftRefillBatchSize($playerCount) * max($playerCount - 2, 0);
    }

    /** The full pool size Grid Draft needs for $playerCount players -- one gridDraftCardsDrawnPerRound() per round, for gridDraftRounds() rounds. 54/72/96 for 2/3/4 players. */
    private static function gridDraftPoolTargetSize(int $playerCount): int
    {
        return self::gridDraftCardsDrawnPerRound($playerCount) * self::gridDraftRounds($playerCount);
    }

    /** @see gridDraftRounds()'s own docblock -- no top-up mechanic, so the floor is the same as the actual target size. */
    private static function gridDraftMinCustomPoolSize(int $playerCount): int
    {
        return self::gridDraftPoolTargetSize($playerCount);
    }

    /**
     * Shared best-of-three threshold for every draft-based deck_type's own
     * match -- still the right number for every 2-player draft match
     * (Quick Draft, Winston Draft, Grid Draft alike). Quick Draft's 3-4
     * player matches are single-game instead (issue #189 -- the
     * draft_matches/draft_match_players wrapper is still reused, just with
     * an effective games-to-win of 1), so anything that needs this
     * threshold for a possibly-multiplayer match should go through
     * draftGamesToWin() rather than reading this constant directly.
     */
    private const DRAFT_GAMES_TO_WIN = 2;

    /** @see DRAFT_GAMES_TO_WIN's own docblock. */
    private function draftGamesToWin(int $playerCount): int
    {
        return $playerCount > 2 ? 1 : self::DRAFT_GAMES_TO_WIN;
    }

    /**
     * Quick Draft's own per-player-count constants -- see the
     * QUICK_DRAFT_* constants' shared docblock above for the mechanic
     * these implement.
     */
    private static function quickDraftPileSize(int $playerCount): int
    {
        return 2 * $playerCount + 2;
    }

    private static function quickDraftRounds(int $playerCount): int
    {
        return match ($playerCount) {
            2 => 4,
            3 => 3,
            4 => 2,
            default => throw new GameStateException("Quick Draft supports 2-4 players, not {$playerCount}"),
        };
    }

    private static function quickDraftPoolTargetSize(int $playerCount): int
    {
        return self::QUICK_DRAFT_POOL_SIZE_PER_PLAYER * $playerCount;
    }

    private static function quickDraftMinCustomPoolSize(int $playerCount): int
    {
        return (int) ceil($playerCount / 2) * self::QUICK_DRAFT_MIN_CUSTOM_POOL_SIZE_PER_TWO_PLAYERS;
    }

    /**
     * Which way a round's piles pass: +1 (to the next seat, "right") on odd
     * rounds, -1 ("left") on even rounds -- alternates every round per the
     * issue #189 spec. Meaningless (but harmless) for 2 players, where
     * "the one other seat" is the same regardless of direction.
     */
    private static function quickDraftPassDirection(int $roundNumber): int
    {
        return $roundNumber % 2 === 1 ? 1 : -1;
    }

    /** Positive-result modulo (PHP's % can return negative for a negative dividend). */
    private static function mod(int $dividend, int $divisor): int
    {
        return (($dividend % $divisor) + $divisor) % $divisor;
    }

    /** In-game notepad (issue #258) -- a note's max length, in characters. */
    private const MAX_NOTE_LENGTH = 20000;

    /**
     * In-game chat (issue #109) -- a single message's max length, in
     * characters. Much shorter than MAX_NOTE_LENGTH above: a note is a
     * private scratchpad meant to hold whatever a player wants to jot
     * down, a chat message is meant to be read live by another player
     * mid-game, closer to a text message than a document.
     */
    private const MAX_CHAT_MESSAGE_LENGTH = 500;

    /** @var array<int, array<int, string>> gameId => (card_id => name), memoized per instance by cardNamesFor() */
    private array $cardNamesByGame = [];

    /** @var array<int, array<int, string>> gameId => (game_player_id => username), memoized per instance by playerUsernamesFor() */
    private array $playerUsernamesByGame = [];

    public function __construct(
        private readonly BoardStateRepository $boardStates,
        private readonly MoodPlayService $plays,
        private readonly RoundScorer $scorer,
        private readonly UserDecklistService $userDecklists,
        private readonly ReplayStateBuilder $replay,
        private readonly int $gameLockTimeoutSeconds = self::GAME_LOCK_TIMEOUT_SECONDS,
        private readonly ?NotificationService $notifications = null,
        private readonly PresenceService $presence = new PresenceService(new SessionRepository()),
        private readonly GameNoteRepository $notes = new GameNoteRepository(),
        private readonly CardStatsService $cardStats = new CardStatsService(),
        private readonly GameChatRepository $chat = new GameChatRepository(),
    ) {
    }

    /**
     * @param int[] $userIds seat order follows array order for every format
     *        except 'team'/'closed_team', where seating is instead derived
     *        from $partnerUserId -- see seatOrderForTeamGame()/
     *        seatOrderForClosedTeamGame().
     * @param ?int $partnerUserId only meaningful (and required) when
     *        $format is 'team' or 'closed_team': which of $userIds (other
     *        than $createdByUserId) the creator pairs up with as their
     *        partner -- adjacent seating for 'team' (see "Open Team Play"
     *        in php-app/README.md), across-the-table seating for
     *        'closed_team' (see "Closed Team Play"). The other two players
     *        become the second team automatically.
     * @param ?array{preset?: string, min_cards?: int, rarity_limits?: array<string,int>, duplicate_limits?: array<string,int>, even_color_distribution_rarities?: string[]} $duelDeckRules
     *        only meaningful when $deckType is 'custom_duel' -- see resolveDuelDeckRules().
     * @param ?string $quickDraftPoolSource only meaningful (and required) when $deckType
     *        is 'quick_draft' -- one of 'random_48'/'structure'/'one_of_each'/'custom'/
     *        'saved_deck' (issue #290), see buildQuickDraftPool().
     * @param ?string $quickDraftCustomPoolText only meaningful when $quickDraftPoolSource
     *        is 'custom' -- a decklist-line pool of 45+ cards (same format as the 'custom'
     *        deck_type, see DecklistParser), no About/Sideboard sections expected.
     * @param ?string $winstonDraftPoolSource Winston Draft's (issue #89) own analog of
     *        $quickDraftPoolSource -- only meaningful (and required) when $deckType is
     *        'winston_draft', same pool-source options, see buildWinstonDraftPool().
     *        Kept as its own explicitly-named param rather than unified with
     *        $quickDraftPoolSource, matching this method's existing convention of a
     *        dedicated param set per deck_type (e.g. custom_duel's own 5 rules params).
     * @param ?string $winstonDraftCustomPoolText Winston Draft's own analog of
     *        $quickDraftCustomPoolText -- a decklist-line pool of 45+ cards, only
     *        meaningful when $winstonDraftPoolSource is 'custom'.
     * @param ?string $gridDraftPoolSource Grid Draft's (issue #188) own analog of
     *        $quickDraftPoolSource -- only meaningful (and required) when $deckType is
     *        'grid_draft', same pool-source options, see buildGridDraftPool().
     * @param ?string $gridDraftCustomPoolText Grid Draft's own analog of
     *        $quickDraftCustomPoolText -- a decklist-line pool of 54+ cards, only
     *        meaningful when $gridDraftPoolSource is 'custom'.
     * @param ?int $savedDecklistId one of $createdByUserId's own saved decklists
     *        (issue #92) to source cards from instead of freshly-pasted/uploaded
     *        text -- meaningful when $deckType is 'custom' (an alternative to
     *        $decklistText), or when any of $quickDraftPoolSource/
     *        $winstonDraftPoolSource/$gridDraftPoolSource is 'saved_deck' (issue
     *        #290, an alternative to that draft type's own *CustomPoolText).
     *        A single param shared across all four cases -- unlike the pool
     *        sources' own per-deck_type params, it's the same underlying
     *        "which saved decklist" concept regardless of which one is
     *        active, and only one deck_type is ever in effect per call.
     * @param bool $defaultSelectionsMode "default selections" mode (issue
     *        #274) -- a per-game toggle, not a personal preference, chosen
     *        once here and applying to every seated player for the whole
     *        game's lifetime, the same shape as $format/$deckType. Threaded
     *        straight into the games row with no validation of its own
     *        (a plain boolean, unlike $format/$deckType there's nothing to
     *        reject). See serializeCard()/serializePendingDecision()'s own
     *        docblocks for what it actually changes once the game is
     *        underway.
     */
    public function createGame(
        int $createdByUserId,
        array $userIds,
        string $format = 'standard',
        int $winsNeeded = 3,
        string $deckType = 'structure',
        ?string $decklistText = null,
        ?array $duelDeckRules = null,
        ?int $partnerUserId = null,
        ?string $quickDraftPoolSource = null,
        ?string $quickDraftCustomPoolText = null,
        ?string $winstonDraftPoolSource = null,
        ?string $winstonDraftCustomPoolText = null,
        ?string $gridDraftPoolSource = null,
        ?string $gridDraftCustomPoolText = null,
        ?int $savedDecklistId = null,
        bool $defaultSelectionsMode = false,
    ): int {
        if (count($userIds) > self::MAX_PLAYERS) {
            throw new GameStateException('A game cannot have more than ' . self::MAX_PLAYERS . ' players');
        }
        if (self::isDuelShapedFormat($format)) {
            // Quick Draft, Grid Draft, and Winston Draft all support 3-4
            // players now (issue #189) -- 'duel' itself stays locked to
            // exactly 2.
            if ($format === 'draft' && in_array($deckType, ['quick_draft', 'grid_draft', 'winston_draft'], true)) {
                if (count($userIds) < 2 || count($userIds) > 4) {
                    throw new GameStateException("A {$deckType} game must have 2-4 players");
                }
            } elseif (count($userIds) !== 2) {
                throw new GameStateException("A {$format} game must have exactly 2 players");
            }
        }
        if (self::isTeamFormat($format)) {
            if (count($userIds) !== self::TEAM_PLAYER_COUNT) {
                throw new GameStateException('A team game must have exactly ' . self::TEAM_PLAYER_COUNT . ' players');
            }
            if ($partnerUserId === null || $partnerUserId === $createdByUserId || !in_array($partnerUserId, $userIds, true)) {
                throw new GameStateException('A team game requires choosing one of your opponents as your partner');
            }
            if ($deckType === 'power') {
                throw new GameStateException('The "power" deck type is too small for Team Play\'s ' . self::MIN_TEAM_DECK_SIZE . '-card minimum -- choose a different deck type');
            }
        }

        $customDeckName = null;
        $customDeckCardIds = null;
        $duelRulesPreset = null;
        $duelMinCards = null;
        $duelRarityLimits = null;
        $duelDuplicateLimits = null;
        $duelEvenColorDistributionRarities = null;

        if ($format === 'draft' && !in_array($deckType, ['quick_draft', 'winston_draft', 'grid_draft'], true)) {
            throw new GameStateException('The "draft" format only supports the "quick_draft"/"winston_draft"/"grid_draft" deck types');
        }
        if (in_array($deckType, ['quick_draft', 'winston_draft', 'grid_draft'], true) && $format !== 'draft') {
            throw new GameStateException("The \"{$deckType}\" deck type is only supported for the \"draft\" format");
        }

        if ($deckType === 'custom') {
            if ($format === 'duel') {
                throw new GameStateException('Custom decklists are not supported for duel games -- use deck_type "custom_duel" instead');
            }

            if ($savedDecklistId !== null) {
                ['name' => $customDeckName, 'cardIds' => $customDeckCardIds] = $this->userDecklists->cardIdsForUse($createdByUserId, $savedDecklistId);
                $this->assertCustomDecklistCardCount($customDeckCardIds, count($userIds));
            } else {
                ['name' => $customDeckName, 'cardIds' => $customDeckCardIds] = $this->parseCustomDecklist($decklistText, count($userIds));
            }
        } elseif ($deckType === 'custom_duel') {
            if ($format !== 'duel') {
                throw new GameStateException('The "custom_duel" deck type is only supported for duel games');
            }

            $rules = $this->resolveDuelDeckRules($duelDeckRules);
            $duelRulesPreset = (string) ($duelDeckRules['preset'] ?? 'user_defined');
            $duelMinCards = $rules->minCards;
            $duelRarityLimits = $rules->rarityLimits;
            $duelDuplicateLimits = $rules->duplicateLimits;
            $duelEvenColorDistributionRarities = $rules->evenColorDistributionRarities;
        }

        // Built (and, for a 'custom' pool, fully validated) before the
        // transaction starts, same rationale as parseCustomDecklist()/
        // resolveDuelDeckRules() above -- a bad pool should fail loudly
        // before anything is written, not mid-transaction.
        $draftPoolSource = match ($deckType) {
            'quick_draft' => $quickDraftPoolSource,
            'winston_draft' => $winstonDraftPoolSource,
            'grid_draft' => $gridDraftPoolSource,
            default => null,
        };
        $draftPoolCardIds = match ($deckType) {
            'quick_draft' => $this->buildQuickDraftPool((string) $quickDraftPoolSource, $quickDraftCustomPoolText, $savedDecklistId, $createdByUserId, count($userIds)),
            'winston_draft' => $this->buildWinstonDraftPool((string) $winstonDraftPoolSource, $winstonDraftCustomPoolText, $savedDecklistId, $createdByUserId, count($userIds)),
            'grid_draft' => $this->buildGridDraftPool((string) $gridDraftPoolSource, $gridDraftCustomPoolText, $savedDecklistId, $createdByUserId, count($userIds)),
            default => null,
        };

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
            $draftMatchId = null;
            if ($draftPoolCardIds !== null) {
                $insertMatch = $pdo->prepare(
                    'INSERT INTO draft_matches (created_by_user_id, pool_source, pool_card_ids)
                     VALUES (:created_by, :pool_source, :pool_card_ids)'
                );
                $insertMatch->execute([
                    'created_by' => $createdByUserId,
                    'pool_source' => $draftPoolSource,
                    'pool_card_ids' => json_encode($draftPoolCardIds),
                ]);
                $draftMatchId = (int) $pdo->lastInsertId();
            }

            $insertGame = $pdo->prepare(
                "INSERT INTO games (
                    format, deck_type, custom_deck_name, custom_deck_card_ids,
                    custom_duel_rules_preset, custom_duel_min_cards, custom_duel_rarity_limits, custom_duel_duplicate_limits,
                    custom_duel_even_color_distribution_rarities, draft_match_id, match_game_number,
                    status, created_by_user_id, wins_needed, default_selections_mode
                 ) VALUES (
                    :format, :deck_type, :custom_deck_name, :custom_deck_card_ids,
                    :duel_rules_preset, :duel_min_cards, :duel_rarity_limits, :duel_duplicate_limits,
                    :duel_even_color_distribution_rarities, :draft_match_id, :match_game_number,
                    'waiting', :created_by, :wins_needed, :default_selections_mode
                 )"
            );
            $insertGame->execute([
                'format' => $format,
                'deck_type' => $deckType,
                'custom_deck_name' => $customDeckName,
                'custom_deck_card_ids' => $customDeckCardIds !== null ? json_encode($customDeckCardIds) : null,
                'duel_rules_preset' => $duelRulesPreset,
                'duel_min_cards' => $duelMinCards,
                'duel_rarity_limits' => $duelRarityLimits !== null ? json_encode($duelRarityLimits) : null,
                'duel_duplicate_limits' => $duelDuplicateLimits !== null ? json_encode($duelDuplicateLimits) : null,
                'duel_even_color_distribution_rarities' => $duelEvenColorDistributionRarities !== null ? json_encode($duelEvenColorDistributionRarities) : null,
                'draft_match_id' => $draftMatchId,
                'match_game_number' => $draftMatchId !== null ? 1 : null,
                'created_by' => $createdByUserId,
                'wins_needed' => $winsNeeded,
                'default_selections_mode' => $defaultSelectionsMode ? 1 : 0,
            ]);
            $gameId = (int) $pdo->lastInsertId();

            $insertPlayer = $pdo->prepare(
                'INSERT INTO game_players (game_id, user_id, seat_order, team_id) VALUES (:game_id, :user_id, :seat_order, :team_id)'
            );
            $seatedUserIds = match ($format) {
                'team' => $this->seatOrderForTeamGame($createdByUserId, (int) $partnerUserId, $userIds),
                'closed_team' => $this->seatOrderForClosedTeamGame($createdByUserId, (int) $partnerUserId, $userIds),
                default => array_values($userIds),
            };
            foreach ($seatedUserIds as $seatOrder => $userId) {
                $teamId = match ($format) {
                    'team' => (int) ($seatOrder >= 2),
                    'closed_team' => $seatOrder % 2,
                    default => null,
                };
                $insertPlayer->execute([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'seat_order' => $seatOrder,
                    'team_id' => $teamId,
                ]);
            }

            if ($draftMatchId !== null) {
                // Winston Draft's and Grid Draft's own drafted_card_ids both
                // start as an empty array, not NULL -- picks accumulate
                // incrementally from the first turn (see
                // initializeWinstonDraft()/submitWinstonDraftPick() and
                // initializeGridDraft()/submitGridDraftPick()), unlike Quick
                // Draft's own NULL-until-finalizeQuickDraft() convention.
                $insertMatchPlayer = $pdo->prepare(
                    'INSERT INTO draft_match_players (draft_match_id, user_id, drafted_card_ids) VALUES (:match_id, :user_id, :drafted_card_ids)'
                );
                $initialDraftedCardIds = in_array($deckType, ['winston_draft', 'grid_draft'], true) ? json_encode([]) : null;
                foreach ($seatedUserIds as $userId) {
                    $insertMatchPlayer->execute([
                        'match_id' => $draftMatchId,
                        'user_id' => $userId,
                        'drafted_card_ids' => $initialDraftedCardIds,
                    ]);
                }

                if ($deckType === 'quick_draft') {
                    $this->dealQuickDraftRound($gameId, $draftMatchId, 1, $draftPoolCardIds, array_values($seatedUserIds));
                } elseif ($deckType === 'winston_draft') {
                    $this->initializeWinstonDraft($gameId, $draftMatchId, $draftPoolCardIds, array_values($seatedUserIds));
                } elseif ($deckType === 'grid_draft') {
                    $this->initializeGridDraft($gameId, $draftMatchId, $draftPoolCardIds, array_values($seatedUserIds));
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $gameId;
    }

    /**
     * Reorders $userIds so partners sit adjacent -- creator (seat 0) next
     * to their chosen $partnerUserId (seat 1, team_id 0); the other two
     * players fill seats 2/3 (team_id 1) in whatever relative order they
     * were already in. The array's own index becomes seat_order, matching
     * every other format's "seat order follows array order" convention.
     *
     * @param int[] $userIds
     * @return int[]
     */
    private function seatOrderForTeamGame(int $createdByUserId, int $partnerUserId, array $userIds): array
    {
        $others = array_values(array_filter(
            $userIds,
            fn (int $userId): bool => $userId !== $createdByUserId && $userId !== $partnerUserId
        ));

        return [$createdByUserId, $partnerUserId, ...$others];
    }

    /**
     * Reorders $userIds so partners sit ACROSS from each other -- creator
     * (seat 0), then one of the other two opponents (seat 1), then the
     * chosen $partnerUserId (seat 2), then the last opponent (seat 3) --
     * so `team_id = seat_order % 2` pairs seats 0/2 and 1/3, exactly the
     * across-the-table seating "Closed Team Play" in php-app/README.md
     * calls for (unlike seatOrderForTeamGame()'s adjacent seats 0/1 vs
     * 2/3). The array's own index becomes seat_order, matching every other
     * format's "seat order follows array order" convention.
     *
     * @param int[] $userIds
     * @return int[]
     */
    private function seatOrderForClosedTeamGame(int $createdByUserId, int $partnerUserId, array $userIds): array
    {
        $others = array_values(array_filter(
            $userIds,
            fn (int $userId): bool => $userId !== $createdByUserId && $userId !== $partnerUserId
        ));

        return [$createdByUserId, $others[0], $partnerUserId, $others[1]];
    }

    /**
     * Whether $format is one of the two 4-player 2v2 team formats -- see
     * "Open Team Play"/"Closed Team Play" in php-app/README.md. Most of
     * createGame()'s validation, and GameService::finishScoringAndAdvance()'s/
     * skipScoringAndAdvance()'s own team-aggregated-scoring branch, apply
     * identically to both; only seating shape and turn-order mechanics
     * (see seatOrderForTeamGame()/seatOrderForClosedTeamGame() and
     * advanceTeamTurn()/applyClosedTeamLeaderDecision()) actually differ.
     */
    private static function isTeamFormat(string $format): bool
    {
        return $format === 'team' || $format === 'closed_team';
    }

    /**
     * 'draft' (issue #88's Quick Draft and any future live-drafting deck
     * types -- see the "Quick Draft" docblock above and
     * database/migrations/0028_add_draft_format.sql) reuses 'duel''s rules
     * engine completely unchanged -- same 2-player, separate-per-player-deck
     * shape (see BoardStateRepository::load()'s own $hasSeparateDecks
     * check) -- it only differs in which deck_type values it allows.
     */
    private static function isDuelShapedFormat(string $format): bool
    {
        return $format === 'duel' || $format === 'draft';
    }

    /**
     * Whether $deckType puts the whole table on one shared deck rather than
     * giving each player their own -- every deck_type except 'custom_duel'
     * and the three draft-based ones (quick_draft/winston_draft/grid_draft),
     * the same four deckCardIdsFor() itself refuses to build a deck for
     * (each reads its own per-player card ids from somewhere else instead
     * -- game_players.custom_deck_card_ids or draft_match_players.deck_card_ids).
     * Used by viewSharedDeck() (issue #197) to reject a request for a
     * deck_type with no single "the deck" to show.
     */
    private static function isSharedDeckType(string $deckType): bool
    {
        return !in_array($deckType, ['custom_duel', 'quick_draft', 'winston_draft', 'grid_draft'], true);
    }

    /**
     * @return array{idsByName: array<string,int>, rowsById: array<int, array{name:string, rarity:string, color:string}>}
     */
    private function loadCardCatalog(): array
    {
        return CardCatalog::load();
    }

    /**
     * Parses and fully validates a 'custom' deck_type's decklist text at
     * createGame() time (rather than re-parsing at startGame() time), so a
     * decklist error -- an unrecognized card, too few cards for the table
     * -- surfaces immediately instead of only once the game is started. The
     * minimum card count follows the same "15 per player, but the first
     * two players share the first 15" shape a physical two-player game
     * already needs: 15 for 2 players, 30 for 3, 45 for 4.
     *
     * @return array{name: ?string, cardIds: int[]}
     */
    private function parseCustomDecklist(?string $decklistText, int $playerCount): array
    {
        if ($decklistText === null || trim($decklistText) === '') {
            throw new GameStateException('A custom decklist is required when deck_type is "custom"');
        }

        $parsed = (new DecklistParser($this->loadCardCatalog()['idsByName']))->parse($decklistText);
        $this->assertCustomDecklistCardCount($parsed['cardIds'], $playerCount);

        return $parsed;
    }

    /**
     * Shared by parseCustomDecklist()'s text-parsing path and
     * createGame()'s saved-decklist path (issue #92) -- both need the
     * exact same minimum-card-count validation regardless of where the
     * card ids came from.
     *
     * @param int[] $cardIds
     */
    private function assertCustomDecklistCardCount(array $cardIds, int $playerCount): void
    {
        $minimumCards = 15 * ($playerCount - 1);
        if (count($cardIds) < $minimumCards) {
            throw new GameStateException(
                "The decklist has only " . count($cardIds) . " card(s), but at least {$minimumCards} are required for {$playerCount} players"
            );
        }
    }

    /**
     * Resolves createGame()'s own $duelDeckRules argument into an actual
     * DuelDeckRules instance -- either one of the three built-in presets
     * (DuelDeckRules::forPreset(), the values "locked in" verbatim
     * regardless of whatever min_cards/rarity_limits/duplicate_limits/
     * even_color_distribution_rarities the client also sent alongside the
     * preset name) or, for 'user_defined' (the default when no preset key
     * is given at all), the creator's own four rule values --
     * sanitizeRarityMap()/sanitizeRarityList() drop anything that isn't
     * one of cards.rarity's own four values, and DuelDeckRules's own
     * constructor enforces the minimum min_cards floor.
     *
     * @param ?array{preset?: string, min_cards?: int, rarity_limits?: array<string,int>, duplicate_limits?: array<string,int>, even_color_distribution_rarities?: string[]} $duelDeckRules
     */
    private function resolveDuelDeckRules(?array $duelDeckRules): DuelDeckRules
    {
        if ($duelDeckRules === null) {
            throw new GameStateException('Duel deck-building rules are required when deck_type is "custom_duel"');
        }

        $preset = (string) ($duelDeckRules['preset'] ?? 'user_defined');
        if ($preset !== 'user_defined') {
            return DuelDeckRules::forPreset($preset);
        }

        $minCards = (int) ($duelDeckRules['min_cards'] ?? 0);

        return new DuelDeckRules(
            $minCards,
            $this->sanitizeRarityMap($duelDeckRules['rarity_limits'] ?? null),
            $this->sanitizeRarityMap($duelDeckRules['duplicate_limits'] ?? null),
            $this->sanitizeRarityList($duelDeckRules['even_color_distribution_rarities'] ?? null),
        );
    }

    /**
     * Keeps only the entries of $map keyed by one of cards.rarity's own
     * four values, coercing each to an int -- a blank/missing/non-numeric
     * entry for a rarity is dropped entirely (meaning "no restriction"),
     * matching DuelDeckRules's own "missing key = unrestricted" contract,
     * rather than accidentally treating an empty form field as a literal
     * cap of 0.
     *
     * @return array<string,int>
     */
    private function sanitizeRarityMap(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }

        $sanitized = [];
        foreach (self::RARITIES as $rarity) {
            $value = $map[$rarity] ?? null;
            if ($value !== null && $value !== '') {
                $sanitized[$rarity] = (int) $value;
            }
        }

        return $sanitized;
    }

    /**
     * Keeps only the entries of $list that are one of cards.rarity's own
     * four values (in that fixed order, deduplicated) -- used for
     * DuelDeckRules's own $evenColorDistributionRarities, the same
     * "silently drop anything not recognized" approach sanitizeRarityMap()
     * takes for the other two rule maps.
     *
     * @return string[]
     */
    private function sanitizeRarityList(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }

        return array_values(array_intersect(self::RARITIES, $list));
    }

    /**
     * A 'custom_duel' game's own two players each call this -- while the
     * game is still 'waiting' -- to submit their own decklist (same
     * file/paste format as the 'custom' deck_type, see DecklistParser)
     * against the deck-building rules the creator locked in at
     * createGame() time (DuelDeckRules, stored on the games row).
     * Re-submitting before the game starts overwrites the previous
     * attempt outright -- there's no reason to keep a superseded one
     * around, and startGame() only ever reads the latest. startGame()
     * itself refuses to deal for this deck_type until both seats have a
     * non-null custom_deck_card_ids.
     */
    public function submitCustomDuelDeck(int $gameId, int $gamePlayerId, ?string $decklistText, ?int $savedDecklistId = null): void
    {
        $game = $this->fetchGame($gameId);
        if ($game['deck_type'] !== 'custom_duel') {
            throw new GameStateException("Game {$gameId} does not use custom duel decklists");
        }
        if ($game['status'] !== 'waiting') {
            throw new GameStateException("Game {$gameId} has already started -- decklists can no longer be submitted");
        }

        // Widened from "SELECT COUNT(*)" to also return user_id -- needed
        // to authorize a $savedDecklistId lookup below without adding a
        // new parameter to this method's own signature/callers.
        $ownerStmt = Connection::get()->prepare('SELECT user_id FROM game_players WHERE id = :id AND game_id = :game_id');
        $ownerStmt->execute(['id' => $gamePlayerId, 'game_id' => $gameId]);
        $ownerRow = $ownerStmt->fetch();
        if ($ownerRow === false) {
            throw new GameStateException("Player {$gamePlayerId} is not seated in game {$gameId}");
        }

        $catalog = $this->loadCardCatalog();

        if ($savedDecklistId !== null) {
            $parsed = $this->userDecklists->cardIdsForUse((int) $ownerRow['user_id'], $savedDecklistId);
        } else {
            if ($decklistText === null || trim($decklistText) === '') {
                throw new GameStateException('A decklist is required');
            }
            $parsed = (new DecklistParser($catalog['idsByName']))->parse($decklistText);
        }

        $rules = new DuelDeckRules(
            (int) $game['custom_duel_min_cards'],
            (array) json_decode((string) $game['custom_duel_rarity_limits'], true),
            (array) json_decode((string) $game['custom_duel_duplicate_limits'], true),
            (array) json_decode((string) $game['custom_duel_even_color_distribution_rarities'], true),
        );
        $rules->validate($parsed['cardIds'], $catalog['rowsById'], 'Your decklist');

        $update = Connection::get()->prepare(
            'UPDATE game_players SET custom_deck_name = :name, custom_deck_card_ids = :card_ids WHERE id = :id'
        );
        $update->execute([
            'name' => $parsed['name'],
            'card_ids' => json_encode($parsed['cardIds']),
            'id' => $gamePlayerId,
        ]);
    }

    /**
     * A 'custom_duel' game's own startGame()-time gate: every seated
     * player must have already called submitCustomDuelDeck() with a
     * valid decklist -- unlike every other deck_type, there's no
     * fallback deck for startGame() to generate if one hasn't.
     *
     * @param int[] $playerIds
     * @return array<int, int[]> game_player id => resolved catalog card ids
     */
    private function requireCustomDuelDecksSubmitted(int $gameId, array $playerIds): array
    {
        $stmt = Connection::get()->prepare('SELECT id, custom_deck_card_ids FROM game_players WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        $cardIdsByPlayer = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['custom_deck_card_ids'] !== null) {
                $cardIdsByPlayer[(int) $row['id']] = array_map(intval(...), json_decode((string) $row['custom_deck_card_ids'], true));
            }
        }

        if (array_diff($playerIds, array_keys($cardIdsByPlayer)) !== []) {
            throw new GameStateException("Game {$gameId} cannot start until every player has submitted a decklist");
        }

        return $cardIdsByPlayer;
    }

    /**
     * A 'quick_draft'/'winston_draft' game's own startGame()-time gate:
     * every seated player must have already called submitDraftDeck() (the
     * initial trim) for this specific game -- mirrors
     * requireCustomDuelDecksSubmitted(), but reads
     * draft_match_players.deck_card_ids, keyed by user_id, since that's
     * where a draft player's deck lives regardless of which draft variant
     * (see the draft_matches/draft_match_players docblocks in migration
     * 0027).
     *
     * @param int[] $playerIds
     * @return array<int, int[]> game_player id => resolved catalog card ids
     */
    private function requireDraftDecksSubmitted(int $gameId, array $playerIds): array
    {
        $game = $this->fetchGame($gameId);

        $stmt = Connection::get()->prepare(
            'SELECT gp.id AS game_player_id, dmp.deck_card_ids
             FROM game_players gp
             JOIN draft_match_players dmp ON dmp.draft_match_id = :match_id AND dmp.user_id = gp.user_id
             WHERE gp.game_id = :game_id'
        );
        $stmt->execute(['match_id' => (int) $game['draft_match_id'], 'game_id' => $gameId]);

        $cardIdsByPlayer = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['deck_card_ids'] !== null) {
                $cardIdsByPlayer[(int) $row['game_player_id']] = array_map(intval(...), json_decode((string) $row['deck_card_ids'], true));
            }
        }

        if (array_diff($playerIds, array_keys($cardIdsByPlayer)) !== []) {
            throw new GameStateException("Game {$gameId} cannot start until every player has submitted their drafted deck");
        }

        return $cardIdsByPlayer;
    }

    public function startGame(int $gameId): void
    {
        $game = $this->fetchGame($gameId);
        if ($game['status'] !== 'waiting') {
            throw new GameStateException("Game {$gameId} has already been started");
        }

        $playerIds = $this->seatOrder($gameId);
        if (count($playerIds) < self::MIN_PLAYERS) {
            throw new GameStateException("Game {$gameId} needs at least " . self::MIN_PLAYERS . ' players to start');
        }

        $customDuelDeckCardIds = $game['deck_type'] === 'custom_duel'
            ? $this->requireCustomDuelDecksSubmitted($gameId, $playerIds)
            : [];
        $draftDeckCardIds = in_array($game['deck_type'], ['quick_draft', 'winston_draft', 'grid_draft'], true)
            ? $this->requireDraftDecksSubmitted($gameId, $playerIds)
            : [];

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
            $insertCard = $pdo->prepare(
                'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, deck_position)
                 VALUES (:game_id, :card_id, :zone, :owner, :deck_position)'
            );

            // 'duel'/'draft' each give the player their OWN complete deck --
            // built, and shuffled, independently per player by the exact
            // same rules a normal single-player deck uses -- rather than
            // splitting one shared pool (createGame() already rejected any
            // duel-shaped game without exactly 2 players; see
            // BoardState::$hasSeparateDecks). The same catalog card can
            // therefore legitimately end up in both players' pools at
            // once; a card's identity within the game is its own
            // game_cards.id, not its catalog card_id -- see
            // BoardState::$catalogCardIdFor.
            if (self::isDuelShapedFormat($game['format'])) {
                foreach ($playerIds as $playerId) {
                    $playerCardIds = match ($game['deck_type']) {
                        'custom_duel' => $customDuelDeckCardIds[$playerId],
                        'quick_draft', 'winston_draft', 'grid_draft' => $draftDeckCardIds[$playerId],
                        default => $this->deckCardIdsFor($game),
                    };
                    shuffle($playerCardIds);

                    for ($i = 0; $i < self::STARTING_HAND_SIZE; $i++) {
                        $insertCard->execute([
                            'game_id' => $gameId,
                            'card_id' => array_shift($playerCardIds),
                            'zone' => 'hand',
                            'owner' => $playerId,
                            'deck_position' => null,
                        ]);
                    }

                    foreach (array_values($playerCardIds) as $position => $cardId) {
                        $insertCard->execute([
                            'game_id' => $gameId,
                            'card_id' => $cardId,
                            'zone' => 'deck',
                            'owner' => $playerId,
                            'deck_position' => $position,
                        ]);
                    }
                }
            } else {
                $cardIds = $this->deckCardIdsFor($game);
                shuffle($cardIds);

                foreach ($playerIds as $playerId) {
                    for ($i = 0; $i < self::STARTING_HAND_SIZE; $i++) {
                        $insertCard->execute([
                            'game_id' => $gameId,
                            'card_id' => array_shift($cardIds),
                            'zone' => 'hand',
                            'owner' => $playerId,
                            'deck_position' => null,
                        ]);
                    }
                }

                foreach (array_values($cardIds) as $position => $cardId) {
                    $insertCard->execute([
                        'game_id' => $gameId,
                        'card_id' => $cardId,
                        'zone' => 'deck',
                        'owner' => null,
                        'deck_position' => $position,
                    ]);
                }
            }

            // Fair 50/50 between the two teams for a team-format game too --
            // exactly 2 of the 4 seats belong to each team, so an ordinary
            // uniform pick over all 4 already picks a team fairly without
            // needing its own separate randomization step. Games 2/3 of a
            // best-of-three draft match are the one exception -- see
            // resolveFirstPlayerId()'s own docblock.
            $firstPlayerId = $this->resolveFirstPlayerId($game, $playerIds);

            if ($game['format'] === 'team') {
                // Which specific teammate actually takes the real first
                // turn is that team's own live choice (see "Open Team Play"
                // in php-app/README.md), not decided yet -- current_turn_game_player_id
                // stays NULL (freezing the round, same as any other
                // outstanding decision) until applyTurnOrderDecision()
                // resolves the game_team_decision created below.
                // first_game_player_id is still set to a real seat purely
                // so its TEAM is derivable later (e.g. for a scoring tie
                // going to whichever team played first) -- it's never
                // trusted as "the actual first player" for this format.
                $insertRound = $pdo->prepare(
                    "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
                     VALUES (:game_id, 1, :first_player, NULL, 0, :pending_play_grants, 'in_progress')"
                );
                $insertRound->execute([
                    'game_id' => $gameId,
                    'first_player' => $firstPlayerId,
                    'pending_play_grants' => json_encode([]),
                ]);
                $roundId = (int) $pdo->lastInsertId();

                $firstTeamId = $this->teamIdByGamePlayer($gameId)[$firstPlayerId];
                $this->createTeamDecision($gameId, $roundId, $firstTeamId, 'turn_order', $this->teamMembers($gameId, $firstTeamId));
            } elseif ($game['format'] === 'closed_team') {
                // Round 1's leader is simply randomized here -- no team
                // decision needed for it (see "Closed Team Play" in
                // php-app/README.md) -- but the round still starts frozen:
                // nobody may play until every player has completed this
                // format's own pregame blind card pass (see
                // submitInitialCardPass()), which unfreezes it, to
                // $firstPlayerId, once all 4 have.
                $insertRound = $pdo->prepare(
                    "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
                     VALUES (:game_id, 1, :first_player, NULL, 0, :pending_play_grants, 'in_progress')"
                );
                $insertRound->execute([
                    'game_id' => $gameId,
                    'first_player' => $firstPlayerId,
                    'pending_play_grants' => json_encode([]),
                ]);

                // "It's your turn" push notification (issue #108) for all
                // 4 players at once -- this pregame pass is required from
                // every seat before the round can unfreeze (see
                // submitInitialCardPass()), unlike the other frozen-round
                // cases below, which each wait on one specific player.
                $this->notifyGamePlayersItsYourTurn($gameId, $playerIds, "Game #{$gameId} needs your card pass before it can start.", 'initial-pass');
            } elseif (
                $game['draft_match_id'] !== null
                && $game['match_game_number'] !== null
                && (int) $game['match_game_number'] > 1
            ) {
                // Games 2/3 of a best-of-three draft match start frozen too
                // -- same reasoning as closed_team's own pregame freeze
                // above, but waiting on setPlayFirstNextMatchGame() instead:
                // per the game's own rules, the previous game's loser isn't
                // required to decide who goes first until they can see this
                // game's own opening hand (already dealt above), so nothing
                // can be fixed yet at game-start time. $firstPlayerId here
                // is resolveFirstPlayerId()'s own placeholder (the previous
                // winner) -- overwritten if the loser opts to go first
                // themselves instead.
                $insertRound = $pdo->prepare(
                    "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
                     VALUES (:game_id, 1, :first_player, NULL, 0, :pending_play_grants, 'in_progress')"
                );
                $insertRound->execute([
                    'game_id' => $gameId,
                    'first_player' => $firstPlayerId,
                    'pending_play_grants' => json_encode([]),
                ]);

                // "It's your turn" push notification (issue #108) for
                // whichever seat is the previous game's loser -- the only
                // player setPlayFirstNextMatchGame() actually lets act
                // (see that method's own docblock and
                // isAwaitingFirstPlayerChoiceFrom()).
                $previousWinnerUserId = $this->previousMatchGameWinnerUserId((int) $game['draft_match_id'], (int) $game['match_game_number']);
                if ($previousWinnerUserId !== null) {
                    $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
                    $seatsStmt = $pdo->prepare("SELECT id, user_id FROM game_players WHERE id IN ({$placeholders})");
                    $seatsStmt->execute($playerIds);
                    foreach ($seatsStmt->fetchAll() as $seatRow) {
                        if ((int) $seatRow['user_id'] !== $previousWinnerUserId) {
                            $this->notifyGamePlayersItsYourTurn($gameId, [(int) $seatRow['id']], "Game #{$gameId} needs you to choose who goes first.", 'first-player-choice');
                        }
                    }
                }
            } else {
                $insertRound = $pdo->prepare(
                    "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
                     VALUES (:game_id, 1, :first_player, :first_player_turn, 1, :pending_play_grants, 'in_progress')"
                );
                $insertRound->execute([
                    'game_id' => $gameId,
                    'first_player' => $firstPlayerId,
                    'first_player_turn' => $firstPlayerId,
                    'pending_play_grants' => json_encode([null]),
                ]);
                // $firstPlayerId isn't necessarily whoever just clicked
                // "Start game" -- resolveFirstPlayerId() can pick either
                // seat -- so the other player needs the same "your turn"
                // notification any later round handoff gets. Same gap as
                // finishScoringAndAdvance()'s own round-creation INSERT.
                $this->notifyItsYourTurn((int) $pdo->lastInsertId(), $firstPlayerId);
            }

            $updateGame = $pdo->prepare("UPDATE games SET status = 'in_progress', started_at = NOW() WHERE id = :game_id");
            $updateGame->execute(['game_id' => $gameId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * startGame()'s own first-player pick for round/game 1. Every game
     * still gets a uniform coin flip EXCEPT games 2/3 of a best-of-three
     * draft match (games.draft_match_id set, match_game_number > 1),
     * where it's instead the previous game's own winner -- purely a
     * PLACEHOLDER for that case, though: the round itself starts frozen
     * (see startGame()'s own 'else' branch) until the previous loser
     * decides, per the game's own rules, who actually goes first --
     * they don't have to decide until they can see this game's opening
     * hand, so nothing is fixed yet at the moment this runs. See
     * setPlayFirstNextMatchGame() for how (and when) that's resolved.
     *
     * @param array<string, mixed> $game
     * @param int[] $playerIds this game's own seats (game_players.id), seat_order ASC
     */
    private function resolveFirstPlayerId(array $game, array $playerIds): int
    {
        $matchGameNumber = $game['match_game_number'] !== null ? (int) $game['match_game_number'] : null;
        if ($game['draft_match_id'] === null || $matchGameNumber === null || $matchGameNumber <= 1) {
            return $playerIds[array_rand($playerIds)];
        }

        $winnerUserId = $this->previousMatchGameWinnerUserId((int) $game['draft_match_id'], $matchGameNumber);
        if ($winnerUserId !== null) {
            $pdo = Connection::get();
            $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
            $userStmt = $pdo->prepare("SELECT id, user_id FROM game_players WHERE id IN ({$placeholders})");
            $userStmt->execute($playerIds);
            foreach ($userStmt->fetchAll() as $row) {
                if ((int) $row['user_id'] === $winnerUserId) {
                    return (int) $row['id'];
                }
            }
        }

        // Should never happen (both seats always carry over unchanged from
        // the previous game -- see advanceDraftMatch()) -- a safety net
        // rather than a hard failure to start the game over it.
        return $playerIds[array_rand($playerIds)];
    }

    /**
     * The user_id of match_game_number $matchGameNumber - 1's own winner
     * within $draftMatchId, or null if that game can't be found (shouldn't
     * happen once $matchGameNumber > 1 -- advanceDraftMatch() always
     * creates the next game before the previous one's own winner is even
     * returned to the caller). resolveFirstPlayerId()'s own placeholder,
     * and setPlayFirstNextMatchGame()'s own default when the previous
     * game's loser opts to let them go first again.
     */
    private function previousMatchGameWinnerUserId(int $draftMatchId, int $matchGameNumber): ?int
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'SELECT gp.user_id FROM games g
             JOIN game_players gp ON gp.id = g.winner_game_player_id
             WHERE g.draft_match_id = :match_id AND g.match_game_number = :num'
        );
        $stmt->execute(['match_id' => $draftMatchId, 'num' => $matchGameNumber - 1]);
        $userId = $stmt->fetchColumn();

        return $userId !== false ? (int) $userId : null;
    }

    /**
     * getState()'s own 'first_player_decision' field -- only populated
     * while game N+1's own round 1 is still frozen awaiting
     * setPlayFirstNextMatchGame() (see that method and startGame()'s own
     * freeze for match games). 'you_are_previous_loser' gates whether
     * $viewerUserId's own client should offer the decision at all (only
     * the loser may actually call it); 'default_user_id' is who goes
     * first if they answer "no" (or never answer) -- the previous game's
     * own winner, mirroring resolveFirstPlayerId()'s own placeholder.
     * Both ids are user_ids -- matched against getState()'s own top-level
     * 'players' list for display.
     */
    private function firstPlayerDecisionStateFor(array $game, int $matchGameNumber, int $viewerUserId): array
    {
        $previousWinnerUserId = $this->previousMatchGameWinnerUserId((int) $game['draft_match_id'], $matchGameNumber);

        $seatsStmt = Connection::get()->prepare('SELECT user_id FROM game_players WHERE game_id = :game_id');
        $seatsStmt->execute(['game_id' => $game['id']]);
        $seatUserIds = array_map(intval(...), $seatsStmt->fetchAll(PDO::FETCH_COLUMN));

        $previousLoserUserId = $previousWinnerUserId !== null
            ? ($seatUserIds[0] === $previousWinnerUserId ? ($seatUserIds[1] ?? $seatUserIds[0]) : $seatUserIds[0])
            : null;

        return [
            'you_are_previous_loser' => $previousLoserUserId === $viewerUserId,
            'default_user_id' => $previousWinnerUserId,
        ];
    }

    /**
     * Resolves the "who goes first" freeze startGame() leaves games 2/3 of
     * a best-of-three draft match in (Quick Draft/Winston Draft/Grid
     * Draft) -- per the game's own rules, the previous game's loser isn't
     * required to decide before this game starts, only before anyone
     * actually plays, so they get to see this game's own opening hand
     * (already dealt by the time the round exists to freeze) before
     * deciding. $playFirst true sends the loser out first themselves;
     * false lets the previous winner go first again (the same result as
     * never answering at all -- see resolveFirstPlayerId()'s own
     * placeholder). Only the loser of the previous game may call this,
     * and only once -- the round unfreezes for both players the moment
     * either answer is given, so there's nothing left to change
     * afterward.
     */
    public function setPlayFirstNextMatchGame(int $gameId, int $userId, bool $playFirst): void
    {
        $this->withGameLock($gameId, function () use ($gameId, $userId, $playFirst): void {
            $game = $this->fetchGame($gameId);
            if (!in_array($game['deck_type'], ['quick_draft', 'winston_draft', 'grid_draft'], true) || $game['draft_match_id'] === null) {
                throw new GameStateException("Game {$gameId} is not a draft match game");
            }
            $matchGameNumber = $game['match_game_number'] !== null ? (int) $game['match_game_number'] : null;
            if ($matchGameNumber === null || $matchGameNumber <= 1) {
                throw new GameStateException('The first game of a match has no previous game to base this choice on');
            }
            if ($game['status'] !== 'in_progress') {
                throw new GameStateException("Game {$gameId} hasn't started yet -- who goes first isn't decided until you can see your opening hand");
            }

            $pdo = Connection::get();
            $roundStmt = $pdo->prepare("SELECT * FROM game_rounds WHERE game_id = :game_id AND round_number = 1");
            $roundStmt->execute(['game_id' => $gameId]);
            $round = $roundStmt->fetch();
            if ($round === false || $round['current_turn_game_player_id'] !== null) {
                throw new GameStateException('Who goes first in this game has already been decided');
            }

            $seatsStmt = $pdo->prepare('SELECT user_id FROM game_players WHERE game_id = :game_id');
            $seatsStmt->execute(['game_id' => $gameId]);
            $seatUserIds = array_map(intval(...), $seatsStmt->fetchAll(PDO::FETCH_COLUMN));

            $previousWinnerUserId = $this->previousMatchGameWinnerUserId((int) $game['draft_match_id'], $matchGameNumber);
            $previousLoserUserId = $previousWinnerUserId !== null
                ? ($seatUserIds[0] === $previousWinnerUserId ? ($seatUserIds[1] ?? $seatUserIds[0]) : $seatUserIds[0])
                : null;

            if ($previousLoserUserId === null || $userId !== $previousLoserUserId) {
                throw new GameStateException('Only the loser of the previous game can choose who goes first next');
            }

            // $round['first_game_player_id'] already holds resolveFirstPlayerId()'s
            // own placeholder -- the previous winner's seat in THIS game --
            // so a "false" answer needs no write there at all; only "true"
            // overwrites it with the loser's own seat.
            $chosenGamePlayerId = $playFirst
                ? $this->gamePlayerIdFor($gameId, $userId)
                : (int) $round['first_game_player_id'];

            if ($playFirst) {
                $pdo->prepare('UPDATE game_rounds SET first_game_player_id = :first_player WHERE id = :round_id')
                    ->execute(['first_player' => $chosenGamePlayerId, 'round_id' => $round['id']]);
            }

            $pdo->prepare('UPDATE games SET first_player_choice_user_id = :chosen WHERE id = :game_id')
                ->execute(['chosen' => $playFirst ? $userId : $previousWinnerUserId, 'game_id' => $gameId]);

            $state = $this->boardStates->load($gameId);
            $freshGrants = $this->computeFreshGrants($state, $chosenGamePlayerId, 1);
            $this->boardStates->save($gameId, $state);
            $this->updateRoundTurnState((int) $round['id'], $chosenGamePlayerId, $freshGrants, $state->discardedThisRound());

            $this->logEvent($gameId, (int) $round['id'], $chosenGamePlayerId, 'draft_match_first_player_decided', null, ['game_player_id' => $chosenGamePlayerId], $state);
        });

        $this->touchLastMoveAt($gameId);
        $this->notifications?->clearQueuedForGame($userId, $gameId);
    }

    /**
     * The card pool for one player's deck (or, for a non-duel game, the
     * whole table's shared deck) given the game's own deck_type -- shared
     * by both the duel and non-duel branches of startGame() so each only
     * has to say *what* to build it for, not *how*.
     *
     * @param array<string, mixed> $game
     * @return int[]
     */
    private function deckCardIdsFor(array $game): array
    {
        return match ($game['deck_type']) {
            'structure' => $this->buildStructureDeckCardIds(),
            'power' => $this->buildPowerDeckCardIds(),
            'jceddys_75' => $this->buildJceddys75DeckCardIds(),
            'custom' => $this->customDeckCardIds($game),
            // Each 'custom_duel' player's own deck lives on their
            // game_players row, not the games row this method reads --
            // startGame() reads it directly via requireCustomDuelDecksSubmitted()
            // and never reaches this method for that deck_type. A stray
            // call here would silently hand back a nonsense deck (there's
            // no single "the" custom_duel deck), so this fails loudly instead.
            'custom_duel' => throw new \LogicException('deckCardIdsFor() cannot build a "custom_duel" deck -- each duel player\'s own deck must be read via requireCustomDuelDecksSubmitted()'),
            // Each 'quick_draft' player's own deck lives on
            // draft_match_players.deck_card_ids, keyed by user_id, not by
            // anything this method's $game argument alone can resolve --
            // startGame() reads it directly via requireDraftDecksSubmitted()
            // and never reaches this method for that deck_type.
            'quick_draft' => throw new \LogicException('deckCardIdsFor() cannot build a "quick_draft" deck -- each duel player\'s own deck must be read via requireDraftDecksSubmitted()'),
            // Same reasoning as 'quick_draft' immediately above --
            // Winston Draft's own per-player deck lives on
            // draft_match_players.deck_card_ids too.
            'winston_draft' => throw new \LogicException('deckCardIdsFor() cannot build a "winston_draft" deck -- each duel player\'s own deck must be read via requireDraftDecksSubmitted()'),
            // Same reasoning as 'quick_draft'/'winston_draft' immediately
            // above -- Grid Draft's own per-player deck lives on
            // draft_match_players.deck_card_ids too.
            'grid_draft' => throw new \LogicException('deckCardIdsFor() cannot build a "grid_draft" deck -- each duel player\'s own deck must be read via requireDraftDecksSubmitted()'),
            default => range(1, self::TOTAL_CARDS), // 'one_of_each'
        };
    }

    /**
     * The 'custom' deck_type's card pool: the fully-resolved catalog card
     * ids createGame() already parsed and validated from the creator's
     * decklist text (see parseCustomDecklist()), persisted as
     * custom_deck_card_ids so it never needs re-parsing.
     *
     * @param array<string, mixed> $game
     * @return int[]
     */
    private function customDeckCardIds(array $game): array
    {
        return array_map(intval(...), json_decode((string) $game['custom_deck_card_ids'], true));
    }

    /**
     * Assembles a 'structure' deck_type's card pool: STRUCTURE_DECK_RARITY_COUNTS'
     * worth of cards per rarity, each drawn without replacement from every
     * card of that rarity in the catalog (so always singleton -- no card
     * id ever appears twice), chosen uniformly at random rather than
     * favoring any particular subset. The catalog currently has more of
     * every rarity than STRUCTURE_DECK_RARITY_COUNTS asks for (e.g. 48
     * common prints for the 23 this needs), so this never has to fall
     * back to allowing duplicates -- if a future catalog change ever left
     * a rarity short, array_rand() below would throw rather than silently
     * returning fewer cards than promised.
     *
     * @return int[]
     */
    private function buildStructureDeckCardIds(): array
    {
        $pdo = Connection::get();

        $cardIds = [];
        foreach (self::STRUCTURE_DECK_RARITY_COUNTS as $rarity => $count) {
            $stmt = $pdo->prepare('SELECT id FROM cards WHERE rarity = :rarity');
            $stmt->execute(['rarity' => $rarity]);
            $rarityCardIds = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));

            $chosenKeys = (array) array_rand($rarityCardIds, $count);
            foreach ($chosenKeys as $key) {
                $cardIds[] = $rarityCardIds[$key];
            }
        }

        return $cardIds;
    }

    /**
     * Assembles a 'power' deck_type's card pool: one random Mythic plus
     * POWER_DECK_NON_MYTHIC_COUNT cards drawn uniformly at random from
     * every non-Mythic card in the catalog (commons/uncommons/rares pooled
     * together, unlike buildStructureDeckCardIds()'s own per-rarity split
     * -- "power" only guarantees the single Mythic, not any particular mix
     * of the rest) -- a small, mythic-guaranteed deck for a faster,
     * higher-power game.
     *
     * @return int[]
     */
    private function buildPowerDeckCardIds(): array
    {
        $pdo = Connection::get();

        $mythicStmt = $pdo->prepare('SELECT id FROM cards WHERE rarity = :rarity');
        $mythicStmt->execute(['rarity' => 'mythic']);
        $mythicCardIds = array_map(intval(...), $mythicStmt->fetchAll(PDO::FETCH_COLUMN));
        $cardIds = [$mythicCardIds[array_rand($mythicCardIds)]];

        $nonMythicStmt = $pdo->prepare("SELECT id FROM cards WHERE rarity != 'mythic'");
        $nonMythicStmt->execute();
        $nonMythicCardIds = array_map(intval(...), $nonMythicStmt->fetchAll(PDO::FETCH_COLUMN));

        $chosenKeys = (array) array_rand($nonMythicCardIds, self::POWER_DECK_NON_MYTHIC_COUNT);
        foreach ($chosenKeys as $key) {
            $cardIds[] = $nonMythicCardIds[$key];
        }

        return $cardIds;
    }

    /**
     * Assembles a 'jceddys_75' deck_type's card pool: JCEDDYS_75_DECK_RARITY_SPEC's
     * counts and per-card copy caps, applied independently per color (unlike
     * buildStructureDeckCardIds()'s single pool spanning every color) --
     * 15 cards per color, 75 total. The catalog currently has at least 3
     * Mythics/6 Rares/8 Uncommons/9 Commons per color, comfortably above
     * what every cap here ever needs (e.g. 4 Uncommons at up to 2 copies
     * each only ever needs 2 distinct Uncommons to exist), so
     * randomCardIdsWithCopyLimit() below never has to fall back to fewer
     * cards than promised.
     *
     * @return int[]
     */
    private function buildJceddys75DeckCardIds(): array
    {
        $pdo = Connection::get();

        $cardIds = [];
        foreach (self::JCEDDYS_75_DECK_COLORS as $color) {
            foreach (self::JCEDDYS_75_DECK_RARITY_SPEC as $rarity => $spec) {
                $cardIds = [
                    ...$cardIds,
                    ...$this->randomCardIdsWithCopyLimit($pdo, $color, $rarity, $spec['count'], $spec['max_copies']),
                ];
            }
        }

        return $cardIds;
    }

    /**
     * Assembles jceddy's 150 Card deck's own 150-card pool --
     * JCEDDYS_150_DECK_RARITY_SPEC's own docblock covers why this exists
     * (a themed 4-player-sized analog of jceddys_75, not a literal
     * doubling of it); this mirrors buildJceddys75DeckCardIds()'s own
     * per-color assembly exactly, just against the doubled spec. The
     * catalog's smallest per-color rarity count (3 Mythics) comfortably
     * covers every cap here (2 distinct Mythics needed at most), so
     * randomCardIdsWithCopyLimit() below never has to fall back to fewer
     * cards than promised.
     *
     * @return int[]
     */
    private function buildJceddys150DeckCardIds(): array
    {
        $pdo = Connection::get();

        $cardIds = [];
        foreach (self::JCEDDYS_75_DECK_COLORS as $color) {
            foreach (self::JCEDDYS_150_DECK_RARITY_SPEC as $rarity => $spec) {
                $cardIds = [
                    ...$cardIds,
                    ...$this->randomCardIdsWithCopyLimit($pdo, $color, $rarity, $spec['count'], $spec['max_copies']),
                ];
            }
        }

        return $cardIds;
    }

    /**
     * $count random card ids matching $color/$rarity, allowing up to
     * $maxCopies repeats of any single id -- built by expanding that
     * color/rarity's own card pool into $maxCopies copies of each id,
     * shuffling, then taking the first $count, so no id can ever appear
     * more than $maxCopies times while every id still has an equal chance
     * of being picked. $maxCopies=1 (jceddys_75's Mythic/Rare slots)
     * degenerates to an ordinary without-replacement draw.
     *
     * @return int[]
     */
    private function randomCardIdsWithCopyLimit(PDO $pdo, string $color, string $rarity, int $count, int $maxCopies): array
    {
        $stmt = $pdo->prepare('SELECT id FROM cards WHERE color = :color AND rarity = :rarity');
        $stmt->execute(['color' => $color, 'rarity' => $rarity]);
        $poolCardIds = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));

        $expandedPool = array_merge(...array_fill(0, $maxCopies, $poolCardIds));
        shuffle($expandedPool);

        return array_slice($expandedPool, 0, $count);
    }

    // -- Quick Draft (issue #88) -------------------------------------------

    /**
     * Assembles a draft match's shared card pool, per $poolSource --
     * 'random_48' ($targetSize random *distinct* catalog cards, singleton
     * like buildStructureDeckCardIds()), 'structure' (reuses
     * buildStructureDeckCardIds() as-is -- its own 45-card pool, or two
     * copies concatenated together when $doubleStructureForMultiplayer is
     * true and $playerCount > 2 -- both Quick Draft and Winston Draft pass
     * this for 3-4 players, since a single 45-card copy falls short of
     * either format's own 3-4 player targets (72/96 and 70/90
     * respectively); Quick Draft's own discard-reshuffle top-up still
     * covers whatever gap remains after doubling (see
     * quickDraftMinCustomPoolSize()'s own docblock -- its "2 structure
     * decks for 3-4 players" floor is exactly what this doubling
     * produces), it just no longer has to cover the *entire* 3-4 player
     * shortfall starting from a single undoubled copy the way it
     * mistakenly did before. Grid Draft rejects 'structure' outright
     * instead, since it has no top-up mechanism of any kind), 'jceddys_75' (reuses
     * buildJceddys75DeckCardIds() as-is -- its own 75-card pool -- except
     * at exactly 4 players, where buildJceddys150DeckCardIds()'s own
     * 150-card pool is used instead, since 75 falls short of every
     * 4-player target this pool source is ever used for), 'one_of_each'
     * (the full TOTAL_CARDS catalog), or 'custom' (parseDraftCustomPool()).
     * Whatever the source produces, anything over $targetSize is randomly
     * truncated down to it -- "if more than 48 cards are in the pool, just
     * ignore the extra cards" (the repo owner's own words, for Quick
     * Draft's own 48 -- Winston Draft/Grid Draft reuse this exact same
     * logic with their own targets instead) -- so 'jceddys_75'/
     * 'jceddys_150's 75/150, a doubled 'structure's 90, 'one_of_each's
     * 133, and an oversized 'custom' pool all end up the same size as
     * 'random_48' before drafting ever starts. Shared by
     * buildQuickDraftPool()/buildWinstonDraftPool()/buildGridDraftPool()
     * below, parameterized only by target/minimum pool size, player
     * count, and the structure-doubling flag -- the pool assembly logic
     * itself has nothing else format-specific about it. Plus 'saved_deck'
     * (issue #290): reuses one of $requestingUserId's own saved decklists
     * (issue #92's UserDecklistService -- friends'-visibility decks
     * included, via the exact same authorization cardIdsForUse() already
     * enforces for the 'custom' deck_type's own $savedDecklistId use) as
     * the pool, preserving whatever per-card quantities it was saved
     * with -- same as 'custom' pool text's own quantities, just sourced
     * from a first-class saved decklist instead of raw pasted text. See
     * resolveSavedDeckDraftPool().
     *
     * @return int[]
     */
    private function buildDraftPool(string $poolSource, ?string $customPoolText, ?int $savedDecklistId, int $requestingUserId, int $targetSize, int $minCustomPoolSize, int $playerCount, bool $doubleStructureForMultiplayer = false): array
    {
        $cardIds = match ($poolSource) {
            'random_48' => $this->buildRandomDraftCardIds($targetSize),
            'structure' => $doubleStructureForMultiplayer && $playerCount > 2
                ? [...$this->buildStructureDeckCardIds(), ...$this->buildStructureDeckCardIds()]
                : $this->buildStructureDeckCardIds(),
            'jceddys_75' => $playerCount === 4 ? $this->buildJceddys150DeckCardIds() : $this->buildJceddys75DeckCardIds(),
            'one_of_each' => range(1, self::TOTAL_CARDS),
            'custom' => $this->parseDraftCustomPool($customPoolText, $minCustomPoolSize),
            'saved_deck' => $this->resolveSavedDeckDraftPool($requestingUserId, $savedDecklistId, $minCustomPoolSize),
            default => throw new GameStateException("Unknown pool source \"{$poolSource}\""),
        };

        if (count($cardIds) <= $targetSize) {
            return array_values($cardIds);
        }

        shuffle($cardIds);

        return array_slice($cardIds, 0, $targetSize);
    }

    /** @return int[] $targetSize distinct catalog card ids, chosen uniformly at random. */
    private function buildRandomDraftCardIds(int $targetSize): array
    {
        $allCardIds = range(1, self::TOTAL_CARDS);
        $chosenKeys = (array) array_rand($allCardIds, $targetSize);

        return array_map(fn (int $key): int => $allCardIds[$key], $chosenKeys);
    }

    /**
     * The 'custom' pool source's own decklist text -- same file/paste
     * format as the 'custom' deck_type (see DecklistParser), just with a
     * different minimum card count ($minCustomPoolSize rather than
     * parseCustomDecklist()'s player-count-scaled minimum) and no use for
     * whatever optional name DecklistParser's own "About" block might
     * parse out -- a draft pool isn't a named deck.
     *
     * @return int[]
     */
    private function parseDraftCustomPool(?string $poolText, int $minCustomPoolSize): array
    {
        if ($poolText === null || trim($poolText) === '') {
            throw new GameStateException('A custom pool decklist is required when the pool source is "custom"');
        }

        $parsed = (new DecklistParser($this->loadCardCatalog()['idsByName']))->parse($poolText);

        if (count($parsed['cardIds']) < $minCustomPoolSize) {
            throw new GameStateException(
                'The custom pool has only ' . count($parsed['cardIds']) . ' card(s), but at least '
                . $minCustomPoolSize . ' are required'
            );
        }

        return $parsed['cardIds'];
    }

    /**
     * The 'saved_deck' pool source (issue #290): the same
     * $userDecklists->cardIdsForUse() lookup the 'custom' deck_type's own
     * $savedDecklistId already uses for a whole game's deck, reused here
     * to source a draft's shared pool instead -- authorization (owner, or
     * an accepted friend for a 'friends'-visibility deck) and "not
     * found"/"not authorized" error handling are entirely
     * UserDecklistService's own, same as that flow. Quantities are
     * preserved exactly as saved, matching the 'custom' pool source's own
     * pasted-text quantities.
     *
     * @return int[]
     */
    private function resolveSavedDeckDraftPool(int $requestingUserId, ?int $savedDecklistId, int $minCustomPoolSize): array
    {
        if ($savedDecklistId === null) {
            throw new GameStateException('A saved decklist is required when the pool source is "saved_deck"');
        }

        $cardIds = $this->userDecklists->cardIdsForUse($requestingUserId, $savedDecklistId)['cardIds'];

        if (count($cardIds) < $minCustomPoolSize) {
            throw new GameStateException(
                'The saved decklist has only ' . count($cardIds) . ' card(s), but at least '
                . $minCustomPoolSize . ' are required'
            );
        }

        return $cardIds;
    }

    /**
     * @return int[] Quick Draft's own quickDraftPoolTargetSize($playerCount)-card
     *         pool -- see buildDraftPool(). `'structure'`'s 45-card pool is
     *         doubled for 3-4 players (matching quickDraftMinCustomPoolSize()'s
     *         own "2 structure decks for 3-4 players" floor) rather than
     *         left at a single copy -- a single 45-card copy falls well
     *         short of the 72/96-card targets even with
     *         dealQuickDraftRound()'s own discard-reshuffle top-up
     *         (nothing exists yet to reshuffle before the very first
     *         round is dealt, so an undersized starting pool leaves round
     *         1 itself short). The doubled 90-card pool is still short of
     *         the 4-player target (96) by 6 -- that residual gap is
     *         exactly what the reshuffle top-up is for, not the entire
     *         3-4 player shortfall. `'jceddys_75'` is the one pool source
     *         that never needs the reshuffle top-up at all: buildDraftPool()
     *         itself swaps in buildJceddys150DeckCardIds()'s own 150-card
     *         pool at exactly 4 players, comfortably covering that target
     *         outright.
     */
    private function buildQuickDraftPool(string $poolSource, ?string $customPoolText, ?int $savedDecklistId, int $requestingUserId, int $playerCount): array
    {
        return $this->buildDraftPool(
            $poolSource,
            $customPoolText,
            $savedDecklistId,
            $requestingUserId,
            self::quickDraftPoolTargetSize($playerCount),
            self::quickDraftMinCustomPoolSize($playerCount),
            $playerCount,
            doubleStructureForMultiplayer: true,
        );
    }

    /** @return int[] Winston Draft's own winstonDraftPoolTargetSize($playerCount)-card pool -- see buildDraftPool(). */
    private function buildWinstonDraftPool(string $poolSource, ?string $customPoolText, ?int $savedDecklistId, int $requestingUserId, int $playerCount): array
    {
        return $this->buildDraftPool(
            $poolSource,
            $customPoolText,
            $savedDecklistId,
            $requestingUserId,
            self::winstonDraftPoolTargetSize($playerCount),
            self::winstonDraftMinCustomPoolSize($playerCount),
            $playerCount,
            doubleStructureForMultiplayer: true,
        );
    }

    /**
     * @return int[] Grid Draft's own gridDraftPoolTargetSize($playerCount)-card
     *         pool -- see buildDraftPool(). Unlike Quick Draft (which tops
     *         up a short pool by reshuffling discards back in mid-draft)
     *         or Winston Draft (whose own 45-card target already matches
     *         the 'structure' pool exactly), Grid Draft has no mechanism
     *         at all for handling a pool short of the target --
     *         initializeGridDraft()/submitGridDraftPick() always deal
     *         exactly gridDraftCardsDrawnPerRound($playerCount) cards per
     *         round for exactly gridDraftRounds($playerCount) rounds. The 'structure'
     *         pool source (45 cards) is short of every player count's own
     *         target (54 at minimum, for 2 players), so it's rejected here
     *         rather than silently dealing a final round or two short of
     *         cards -- 'jceddys_75' doesn't need this rejection at 4
     *         players, since buildDraftPool() itself already swaps in the
     *         150-card 'jceddys_150' pool for that case.
     */
    private function buildGridDraftPool(string $poolSource, ?string $customPoolText, ?int $savedDecklistId, int $requestingUserId, int $playerCount): array
    {
        $targetSize = self::gridDraftPoolTargetSize($playerCount);

        $cardIds = $this->buildDraftPool($poolSource, $customPoolText, $savedDecklistId, $requestingUserId, $targetSize, self::gridDraftMinCustomPoolSize($playerCount), $playerCount);

        if (count($cardIds) < $targetSize) {
            throw new GameStateException(
                'The "' . $poolSource . '" pool source has only ' . count($cardIds)
                . " cards, but Grid Draft with {$playerCount} players requires exactly {$targetSize}"
            );
        }

        return array_values($cardIds);
    }

    /**
     * Subtracts $toRemove from $cardIds as MULTISETS: removes exactly one
     * matching instance per element of $toRemove, not every instance --
     * array_diff()/array_intersect() are unsafe for this because Quick
     * Draft pools/hands can legally contain duplicate catalog card ids (a
     * 'custom' pool may list "2 Charity"); those functions would silently
     * drop every matching value instead of just one, corrupting a
     * legitimate duplicate. Used for every pool/drawn/kept/passed/discarded
     * computation in this section.
     *
     * @param int[] $cardIds
     * @param int[] $toRemove
     * @return int[]
     */
    private function multisetSubtract(array $cardIds, array $toRemove): array
    {
        $remaining = array_values($cardIds);
        foreach ($toRemove as $cardId) {
            $key = array_search($cardId, $remaining, true);
            if ($key !== false) {
                unset($remaining[$key]);
            }
        }

        return array_values($remaining);
    }

    private function fetchDraftMatch(int $draftMatchId): array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM draft_matches WHERE id = :id');
        $stmt->execute(['id' => $draftMatchId]);
        $match = $stmt->fetch();

        if ($match === false) {
            throw new GameStateException("No such draft match {$draftMatchId}");
        }

        return $match;
    }

    /** @return int[] the match's 2 user ids, in a stable (insertion) order. */
    private function draftMatchUserIds(int $draftMatchId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT user_id FROM draft_match_players WHERE draft_match_id = :id ORDER BY id ASC'
        );
        $stmt->execute(['id' => $draftMatchId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int, array<int, int[]>> round_number => pile_owner_user_id => that pile's original dealt cards */
    private function loadDraftRoundPicks(int $draftMatchId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT round_number, user_id, drawn_card_ids FROM draft_round_picks WHERE draft_match_id = :id ORDER BY round_number ASC'
        );
        $stmt->execute(['id' => $draftMatchId]);

        $drawnByRound = [];
        foreach ($stmt->fetchAll() as $row) {
            $drawnByRound[(int) $row['round_number']][(int) $row['user_id']] =
                array_map(intval(...), json_decode((string) $row['drawn_card_ids'], true));
        }

        return $drawnByRound;
    }

    /**
     * @return array<int, array<int, array<int, array{holder_user_id:int, kept_card_ids:int[]}>>>
     *         round_number => pile_owner_user_id => stage_number => that stage's pick
     */
    private function loadDraftPileStagePicks(int $draftMatchId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT round_number, pile_owner_user_id, stage_number, holder_user_id, kept_card_ids
             FROM draft_pile_stage_picks WHERE draft_match_id = :id'
        );
        $stmt->execute(['id' => $draftMatchId]);

        $picks = [];
        foreach ($stmt->fetchAll() as $row) {
            $picks[(int) $row['round_number']][(int) $row['pile_owner_user_id']][(int) $row['stage_number']] = [
                'holder_user_id' => (int) $row['holder_user_id'],
                'kept_card_ids' => array_map(intval(...), json_decode((string) $row['kept_card_ids'], true)),
            ];
        }

        return $picks;
    }

    /**
     * Everything dealQuickDraftRound() needs to derive from the round picks
     * dealt so far -- every card any pile has ever been dealt
     * (allDrawnCardIds), and every card discarded by a pile that's
     * finished all of its stages this match (allDiscardedCardIds, used to
     * top a short pool back up -- see dealQuickDraftRound()). A pile's
     * discard is only knowable once every one of its playerCount stages
     * has a row (only then is "everything that was ever kept from it"
     * fully known) -- an in-progress pile contributes nothing to
     * allDiscardedCardIds yet.
     *
     * @param int[] $userIds the match's user ids (any count 2-4)
     * @return array{allDrawnCardIds: int[], allDiscardedCardIds: int[]}
     */
    private function draftDerivedState(int $draftMatchId, array $userIds): array
    {
        $playerCount = count($userIds);
        $drawnByRound = $this->loadDraftRoundPicks($draftMatchId);
        $stagePicksByRound = $this->loadDraftPileStagePicks($draftMatchId);

        $allDrawnCardIds = [];
        $allDiscardedCardIds = [];

        foreach ($drawnByRound as $roundNumber => $drawnByOwner) {
            foreach ($drawnByOwner as $drawn) {
                $allDrawnCardIds = [...$allDrawnCardIds, ...$drawn];
            }

            foreach ($drawnByOwner as $pileOwnerUserId => $drawn) {
                $stagesForPile = $stagePicksByRound[$roundNumber][$pileOwnerUserId] ?? [];
                if (count($stagesForPile) < $playerCount) {
                    continue;
                }

                $keptAcrossStages = [];
                foreach ($stagesForPile as $stage) {
                    $keptAcrossStages = [...$keptAcrossStages, ...$stage['kept_card_ids']];
                }
                $allDiscardedCardIds = [...$allDiscardedCardIds, ...$this->multisetSubtract($drawn, $keptAcrossStages)];
            }
        }

        return ['allDrawnCardIds' => $allDrawnCardIds, 'allDiscardedCardIds' => $allDiscardedCardIds];
    }

    /**
     * Deals $roundNumber for a Quick Draft match: every player draws their
     * own fresh quickDraftPileSize($playerCount)-card pile from whatever of
     * the pool hasn't been drawn in an earlier round. If the remaining
     * pool would be short of what this round needs (only possible for a
     * pool smaller than quickDraftPoolTargetSize($playerCount) -- the
     * 'structure'/'jceddys_75' sources, or an undersized 'custom' pool),
     * tops it back up first by randomly pulling enough already-discarded
     * cards (from any earlier round, any player) back in -- replicating
     * the physical game's own "reshuffle 3 discards back in before the
     * last draw" workaround for a 45-card box, generalized to whatever the
     * actual shortfall is. Called once from createGame() (round 1) and
     * once from submitQuickDraftPick() each time a round's final stage
     * fully resolves for every player (later rounds). Notifies every
     * player their new round is dealt and ready for their first pick --
     * see notifyDraftUsersItsYourTurn()'s own docblock.
     *
     * @param int[] $poolCardIds the match's full configured pool
     * @param int[] $userIds the match's user ids (any count 2-4)
     */
    private function dealQuickDraftRound(int $gameId, int $draftMatchId, int $roundNumber, array $poolCardIds, array $userIds): void
    {
        $pileSize = self::quickDraftPileSize(count($userIds));
        $derived = $this->draftDerivedState($draftMatchId, $userIds);
        $remainingPool = $this->multisetSubtract($poolCardIds, $derived['allDrawnCardIds']);

        $neededThisRound = count($userIds) * $pileSize;
        if (count($remainingPool) < $neededThisRound) {
            $shortfall = $neededThisRound - count($remainingPool);
            $discardPool = $derived['allDiscardedCardIds'];
            shuffle($discardPool);
            $remainingPool = [...$remainingPool, ...array_slice($discardPool, 0, min($shortfall, count($discardPool)))];
        }

        shuffle($remainingPool);

        $insert = Connection::get()->prepare(
            'INSERT INTO draft_round_picks (draft_match_id, user_id, round_number, drawn_card_ids)
             VALUES (:match_id, :user_id, :round, :drawn)'
        );
        foreach ($userIds as $userId) {
            $drawnCardIds = array_splice($remainingPool, 0, $pileSize);
            $insert->execute([
                'match_id' => $draftMatchId,
                'user_id' => $userId,
                'round' => $roundNumber,
                'drawn' => json_encode($drawnCardIds),
            ]);
        }

        $this->notifyDraftUsersItsYourTurn($gameId, $userIds, "Game #{$gameId}'s draft round {$roundNumber} is ready for your pick.", 'draft-pick');
    }

    /**
     * The final round's last stage resolving for every player ends the
     * draft itself: each player's final drafted_card_ids is the union of
     * every kept_card_ids they were ever the holder_user_id for, across
     * every stage of every round -- not just from piles they themselves
     * originally owned, since most of what a player nets over the draft
     * comes from piles passed to them by other seats. The match moves on
     * to 'deck_building' (the initial drafted-pool-to-deck trim -- see
     * submitDraftDeck()).
     *
     * @param int[] $userIds the match's user ids (any count 2-4)
     */
    private function finalizeQuickDraft(int $draftMatchId, array $userIds): void
    {
        $stagePicksByRound = $this->loadDraftPileStagePicks($draftMatchId);
        $pdo = Connection::get();

        $draftedCardIdsByUser = array_fill_keys($userIds, []);
        foreach ($stagePicksByRound as $pilesByOwner) {
            foreach ($pilesByOwner as $stages) {
                foreach ($stages as $stage) {
                    if (isset($draftedCardIdsByUser[$stage['holder_user_id']])) {
                        $draftedCardIdsByUser[$stage['holder_user_id']] = [
                            ...$draftedCardIdsByUser[$stage['holder_user_id']],
                            ...$stage['kept_card_ids'],
                        ];
                    }
                }
            }
        }

        foreach ($draftedCardIdsByUser as $userId => $draftedCardIds) {
            $pdo->prepare(
                'UPDATE draft_match_players SET drafted_card_ids = :ids WHERE draft_match_id = :match_id AND user_id = :user_id'
            )->execute([
                'ids' => json_encode($draftedCardIds),
                'match_id' => $draftMatchId,
                'user_id' => $userId,
            ]);
        }

        $pdo->prepare("UPDATE draft_matches SET status = 'deck_building' WHERE id = :id")
            ->execute(['id' => $draftMatchId]);
    }

    /**
     * One player's submission for one stage (1..playerCount) of a Quick
     * Draft round. Every round deals $playerCount piles (one per seated
     * player, quickDraftPileSize($playerCount) cards each); each pile is
     * then handled in $playerCount successive stages, one keep-
     * QUICK_DRAFT_KEEP_PER_STAGE-cards decision per stage, by a different
     * seat each time -- the pile's own original owner at stage 1, then
     * whoever it's passed to at stage 2, and so on, one full lap of every
     * seat before it runs out (see the QUICK_DRAFT_* constants' shared
     * docblock for why that's exactly $playerCount stages, no more or
     * fewer). Because every pile starts its lap at a different seat but
     * moves in lockstep, at any given stage number EVERY seated player is
     * simultaneously holding (and picking from) exactly one pile -- so
     * "stage" is a single round-wide counter, not something tracked
     * per-pile or per-player: a stage > 1 pick isn't available to anyone
     * until every pile has finished the previous stage (only then is
     * "which pile does each player now hold, and what's actually left in
     * it" determined for everyone at once) -- directly generalizing the
     * original 2-player 'draw'-then-'received' gate (received cards
     * weren't determined until both players had made their draw pick).
     *
     * Which pile a given player holds at a given stage, and conversely
     * which player holds a given pile at a given stage, is pure seat-index
     * arithmetic: with $userIds providing each player's seat index (their
     * array position) and quickDraftPassDirection($roundNumber) giving the
     * round's pass direction (+1/-1), the pile a player at seat $s holds
     * at stage $n was originally owned by whoever sits at seat
     * mod($s - ($n - 1) * $direction, $playerCount) -- i.e. walking
     * backwards $n - 1 steps (against the pass direction) from the
     * player's own seat lands on the pile's origin seat. Each stage is a
     * one-time, unrevisable submission (mirrors submitInitialCardPass()'s
     * own "your choice is locked the moment you submit it" contract) --
     * nobody can see what's in a pile until they're actually the one
     * holding it. Once every pile has finished stage $playerCount, the
     * round itself is done: this either deals the next round or, for the
     * match's final round (quickDraftRounds($playerCount)), finalizes the
     * draft (see finalizeQuickDraft()).
     *
     * @param int[] $cardIds exactly QUICK_DRAFT_KEEP_PER_STAGE catalog card ids to keep
     * @return array{stage_completed:int, round_advanced:bool, draft_completed:bool}
     */
    public function submitQuickDraftPick(int $gameId, int $userId, int $roundNumber, int $stageNumber, array $cardIds): array
    {
        $game = $this->fetchGame($gameId);
        if ($game['deck_type'] !== 'quick_draft' || $game['draft_match_id'] === null) {
            throw new GameStateException("Game {$gameId} is not a Quick Draft game");
        }
        $draftMatchId = (int) $game['draft_match_id'];

        $result = $this->withGameLock($gameId, function () use ($gameId, $draftMatchId, $userId, $roundNumber, $stageNumber, $cardIds): array {
            $match = $this->fetchDraftMatch($draftMatchId);
            if ($match['status'] !== 'drafting') {
                throw new GameStateException('This match is not currently drafting');
            }
            if ($roundNumber !== (int) $match['current_round']) {
                throw new GameStateException("Round {$roundNumber} is not this match's current draft round");
            }

            $userIds = $this->draftMatchUserIds($draftMatchId);
            $playerCount = count($userIds);
            if (!in_array($userId, $userIds, true)) {
                throw new GameStateException("User {$userId} is not part of this draft match");
            }
            if ($stageNumber < 1 || $stageNumber > $playerCount) {
                throw new GameStateException("stage must be between 1 and {$playerCount}");
            }

            $direction = self::quickDraftPassDirection($roundNumber);
            $seatIndex = array_search($userId, $userIds, true);
            $pileOwnerUserId = $userIds[self::mod($seatIndex - ($stageNumber - 1) * $direction, $playerCount)];

            $pdo = Connection::get();

            if ($stageNumber > 1) {
                $prevStageCountStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM draft_pile_stage_picks
                     WHERE draft_match_id = :match_id AND round_number = :round AND stage_number = :stage'
                );
                $prevStageCountStmt->execute(['match_id' => $draftMatchId, 'round' => $roundNumber, 'stage' => $stageNumber - 1]);
                if ((int) $prevStageCountStmt->fetchColumn() < $playerCount) {
                    throw new GameStateException("Round {$roundNumber}'s stage " . ($stageNumber - 1) . ' is not yet complete for every player');
                }
            }

            $existingStmt = $pdo->prepare(
                'SELECT id FROM draft_pile_stage_picks
                 WHERE draft_match_id = :match_id AND round_number = :round AND pile_owner_user_id = :owner AND stage_number = :stage'
            );
            $existingStmt->execute(['match_id' => $draftMatchId, 'round' => $roundNumber, 'owner' => $pileOwnerUserId, 'stage' => $stageNumber]);
            if ($existingStmt->fetchColumn() !== false) {
                throw new GameStateException("You've already made your pick for this round's stage {$stageNumber}");
            }

            $drawnStmt = $pdo->prepare(
                'SELECT drawn_card_ids FROM draft_round_picks WHERE draft_match_id = :match_id AND user_id = :owner AND round_number = :round'
            );
            $drawnStmt->execute(['match_id' => $draftMatchId, 'owner' => $pileOwnerUserId, 'round' => $roundNumber]);
            $drawnJson = $drawnStmt->fetchColumn();
            if ($drawnJson === false) {
                throw new GameStateException('No draft cards have been dealt for this round yet');
            }
            $availableCardIds = array_map(intval(...), json_decode((string) $drawnJson, true));

            if ($stageNumber > 1) {
                $priorStagesStmt = $pdo->prepare(
                    'SELECT kept_card_ids FROM draft_pile_stage_picks
                     WHERE draft_match_id = :match_id AND round_number = :round AND pile_owner_user_id = :owner AND stage_number < :stage'
                );
                $priorStagesStmt->execute(['match_id' => $draftMatchId, 'round' => $roundNumber, 'owner' => $pileOwnerUserId, 'stage' => $stageNumber]);
                foreach ($priorStagesStmt->fetchAll(PDO::FETCH_COLUMN) as $keptJson) {
                    $availableCardIds = $this->multisetSubtract($availableCardIds, array_map(intval(...), json_decode((string) $keptJson, true)));
                }
            }

            $cardIds = array_values(array_map(intval(...), $cardIds));
            if (count($cardIds) !== self::QUICK_DRAFT_KEEP_PER_STAGE) {
                throw new GameStateException('You must choose exactly ' . self::QUICK_DRAFT_KEEP_PER_STAGE . ' cards');
            }
            if ($this->multisetSubtract($cardIds, $availableCardIds) !== []) {
                throw new GameStateException('You can only keep cards that are actually in the pile you currently hold');
            }

            $pdo->prepare(
                'INSERT INTO draft_pile_stage_picks (draft_match_id, round_number, pile_owner_user_id, stage_number, holder_user_id, kept_card_ids, submitted_at)
                 VALUES (:match_id, :round, :owner, :stage, :holder, :kept, NOW())'
            )->execute([
                'match_id' => $draftMatchId,
                'round' => $roundNumber,
                'owner' => $pileOwnerUserId,
                'stage' => $stageNumber,
                'holder' => $userId,
                'kept' => json_encode($cardIds),
            ]);

            // Issue #315's Quick Draft pick-position signal -- written
            // live, right alongside the pick itself, rather than deferred
            // to game/match completion (see CardStatsService's own
            // docblock for why).
            $this->cardStats->recordQuickDraftPick($cardIds, $roundNumber, $stageNumber, $playerCount);

            $stageCountStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM draft_pile_stage_picks WHERE draft_match_id = :match_id AND round_number = :round AND stage_number = :stage'
            );
            $stageCountStmt->execute(['match_id' => $draftMatchId, 'round' => $roundNumber, 'stage' => $stageNumber]);
            $stageComplete = (int) $stageCountStmt->fetchColumn() >= $playerCount;

            if (!$stageComplete) {
                return ['stage_completed' => $stageNumber, 'round_advanced' => false, 'draft_completed' => false];
            }

            if ($stageNumber < $playerCount) {
                // Every pile just finished this stage -- everyone's next
                // pile (and what's actually left in it) is now determined,
                // same notification role as the original 2-player "both
                // submitted draw, received cards are ready" nudge.
                $this->notifyDraftUsersItsYourTurn($gameId, $userIds, "Game #{$gameId}'s draft round {$roundNumber} stage " . ($stageNumber + 1) . ' is ready for your pick.', 'draft-pick');

                return ['stage_completed' => $stageNumber, 'round_advanced' => false, 'draft_completed' => false];
            }

            // Every pile has finished all $playerCount stages -- the round itself is done.
            $totalRounds = self::quickDraftRounds($playerCount);
            if ($roundNumber >= $totalRounds) {
                $this->finalizeQuickDraft($draftMatchId, $userIds);
                $this->notifyDraftUsersItsYourTurn($gameId, $userIds, "Game #{$gameId}'s draft is complete -- submit your deck.", 'draft-deck');

                return ['stage_completed' => $stageNumber, 'round_advanced' => false, 'draft_completed' => true];
            }

            $poolCardIds = array_map(intval(...), json_decode((string) $match['pool_card_ids'], true));
            $this->dealQuickDraftRound($gameId, $draftMatchId, $roundNumber + 1, $poolCardIds, $userIds);
            $pdo->prepare('UPDATE draft_matches SET current_round = :round WHERE id = :id')
                ->execute(['round' => $roundNumber + 1, 'id' => $draftMatchId]);

            return ['stage_completed' => $stageNumber, 'round_advanced' => true, 'draft_completed' => false];
        });

        $this->touchLastMoveAt($gameId);

        return $result;
    }

    /**
     * Submits (or resubmits, sideboarding between the match's up-to-3
     * games) a draft player's own current deck -- chosen from their fixed
     * drafted_card_ids (never expanded or replaced -- only which of those
     * are IN the deck this game changes). Shared by Quick Draft, Winston
     * Draft, and Grid Draft: each has only a floor (QUICK_DRAFT_MIN_DECK_SIZE /
     * WINSTON_MIN_DECK_SIZE / GRID_DRAFT_MIN_DECK_SIZE) and no fixed
     * ceiling -- the max is simply however many cards that player actually
     * drafted (varies by how the draft unfolds for Winston/Grid Draft;
     * varies by player count for Quick Draft since issue #189's
     * multiplayer support -- 16 for 2p/4p, 18 for 3p -- deliberately not
     * capped below that: "smart players will always cut down to the
     * minimum, regardless"). The very first call (right after drafting
     * finishes) and every later sideboard call are the same operation
     * against the same 'deck_building' status -- there's no "first trim"
     * vs. "a sideboard" distinction worth making, since both just
     * overwrite deck_card_ids outright.
     *
     * @param int[] $deckCardIds
     */
    public function submitDraftDeck(int $gameId, int $userId, array $deckCardIds): void
    {
        $game = $this->fetchGame($gameId);
        if (!in_array($game['deck_type'], ['quick_draft', 'winston_draft', 'grid_draft'], true) || $game['draft_match_id'] === null) {
            throw new GameStateException("Game {$gameId} is not a draft game");
        }
        $draftMatchId = (int) $game['draft_match_id'];
        $minDeckSize = match ($game['deck_type']) {
            'quick_draft' => self::QUICK_DRAFT_MIN_DECK_SIZE,
            'winston_draft' => self::WINSTON_MIN_DECK_SIZE,
            'grid_draft' => self::GRID_DRAFT_MIN_DECK_SIZE,
        };
        $maxDeckSize = null;

        $this->withGameLock($gameId, function () use ($draftMatchId, $userId, $deckCardIds, $minDeckSize, $maxDeckSize): void {
            $match = $this->fetchDraftMatch($draftMatchId);
            if ($match['status'] !== 'deck_building') {
                throw new GameStateException('This match is not currently building/sideboarding a deck');
            }

            $pdo = Connection::get();
            $playerStmt = $pdo->prepare(
                'SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :match_id AND user_id = :user_id'
            );
            $playerStmt->execute(['match_id' => $draftMatchId, 'user_id' => $userId]);
            $draftedCardIdsJson = $playerStmt->fetchColumn();
            if ($draftedCardIdsJson === false || $draftedCardIdsJson === null) {
                throw new GameStateException("User {$userId} has no drafted cards in this match yet");
            }
            $draftedCardIds = array_map(intval(...), json_decode((string) $draftedCardIdsJson, true));
            $effectiveMaxDeckSize = $maxDeckSize ?? count($draftedCardIds);

            $deckCardIds = array_values(array_map(intval(...), $deckCardIds));
            $count = count($deckCardIds);
            if ($count < $minDeckSize || $count > $effectiveMaxDeckSize) {
                throw new GameStateException(
                    'Your deck must have between ' . $minDeckSize
                    . ' and ' . $effectiveMaxDeckSize . ' cards'
                );
            }
            if ($this->multisetSubtract($deckCardIds, $draftedCardIds) !== []) {
                throw new GameStateException('Your deck can only contain cards you drafted');
            }

            $pdo->prepare(
                'UPDATE draft_match_players SET deck_card_ids = :ids WHERE draft_match_id = :match_id AND user_id = :user_id'
            )->execute([
                'ids' => json_encode($deckCardIds),
                'match_id' => $draftMatchId,
                'user_id' => $userId,
            ]);
        });

        $this->touchLastMoveAt($gameId);
    }

    /**
     * A plain catalog-row view of $cardIds -- no BoardState, no
     * game_cards.id, for cards that haven't been dealt into a game yet (a
     * Quick Draft match's shared pool/pack/drafted cards). Shaped to
     * exactly the fields buildCardThumb()/openCardDetail() (web-static/js/game.js)
     * already read off a normal serializeCard() result, with every
     * in-play-only field defaulted to false/null, so those two functions
     * work completely unchanged against a card that was never actually
     * played.
     *
     * @param int[] $cardIds
     * @return array<int, array<string, mixed>>
     */
    private function serializeCatalogCards(array $cardIds): array
    {
        return CardCatalog::serialize($cardIds);
    }

    // -- Winston Draft (issue #89) ------------------------------------------

    /**
     * Deals Winston Draft's own opening state for a freshly-created match:
     * shuffles $poolCardIds, deals piles 1/2/3 one card each off the top,
     * and randomly picks who goes first (no precedent anywhere else in
     * this codebase for a creator-chosen first player -- matches Closed
     * Team Play's own randomized round-1 leader). Called once from
     * createGame(), exactly where dealQuickDraftRound() is called for
     * Quick Draft. Notifies whoever goes first that it's their pick --
     * see notifyDraftUsersItsYourTurn()'s own docblock.
     *
     * @param int[] $poolCardIds
     * @param int[] $userIds the match's user ids (any count 2-4)
     */
    private function initializeWinstonDraft(int $gameId, int $draftMatchId, array $poolCardIds, array $userIds): void
    {
        $pool = $poolCardIds;
        shuffle($pool);

        $pile1 = [array_shift($pool)];
        $pile2 = [array_shift($pool)];
        $pile3 = [array_shift($pool)];
        $firstPlayerUserId = $userIds[array_rand($userIds)];

        Connection::get()->prepare(
            'INSERT INTO draft_winston_state
                (draft_match_id, remaining_deck_card_ids, pile_1_card_ids, pile_2_card_ids, pile_3_card_ids, current_player_user_id, current_pile_number)
             VALUES (:match_id, :deck, :pile1, :pile2, :pile3, :current_player, 1)'
        )->execute([
            'match_id' => $draftMatchId,
            'deck' => json_encode(array_values($pool)),
            'pile1' => json_encode($pile1),
            'pile2' => json_encode($pile2),
            'pile3' => json_encode($pile3),
            'current_player' => $firstPlayerUserId,
        ]);

        $this->notifyDraftUsersItsYourTurn($gameId, [$firstPlayerUserId], "Game #{$gameId}'s Winston Draft is ready for your pick.", 'draft-pick');
    }

    /** Appends $newCardIds to $userId's own drafted_card_ids for this match -- Winston Draft's and Grid Draft's picks both accumulate incrementally, unlike Quick Draft's finalize-at-the-end. */
    private function appendDraftedCardIds(int $draftMatchId, int $userId, array $newCardIds): void
    {
        if ($newCardIds === []) {
            return;
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :match_id AND user_id = :user_id'
        );
        $stmt->execute(['match_id' => $draftMatchId, 'user_id' => $userId]);
        $existing = array_map(intval(...), json_decode((string) $stmt->fetchColumn(), true));

        $pdo->prepare(
            'UPDATE draft_match_players SET drafted_card_ids = :ids WHERE draft_match_id = :match_id AND user_id = :user_id'
        )->execute([
            'ids' => json_encode([...$existing, ...$newCardIds]),
            'match_id' => $draftMatchId,
            'user_id' => $userId,
        ]);
    }

    /**
     * One player's take/pass decision on Winston Draft's own currently
     * active pile -- see php-app/README.md's "Winston Draft" section for
     * the full mechanic. $action is 'take' (claim every card currently in
     * the active pile, appended straight to your own drafted_card_ids --
     * unlike Quick Draft, there's no separate finalize step; every pick
     * here is final and incremental the moment it's made) or 'pass' (the
     * active pile grows by 1 fresh card off the deck, if able, and the
     * look moves to the next pile -- declining pile 3 triggers a
     * mandatory, unrevisable draw off whatever's left of the deck AFTER
     * pile 3's own replenish, seen only by the acting player and never
     * revealed to any other seated player). Either action can end the
     * whole draft outright if it leaves the deck and all 3 piles
     * simultaneously empty -- checked after every mutation, not just
     * after a 'pass' on pile 3, since a 'take' when the deck's already
     * empty can exhaust everything mid-turn without ever reaching pile 3.
     * Turn order (issue #189) is a seat-index rotation
     * (`($seatIndex + 1) % $playerCount`) rather than a fixed 2-player
     * toggle -- mathematically identical for a 2-player match, so
     * existing 2-player games are unaffected.
     *
     * @return array{action_completed:string, turn_advanced:bool, draft_completed:bool}
     */
    public function submitWinstonDraftPick(int $gameId, int $userId, string $action): array
    {
        $game = $this->fetchGame($gameId);
        if ($game['deck_type'] !== 'winston_draft' || $game['draft_match_id'] === null) {
            throw new GameStateException("Game {$gameId} is not a Winston Draft game");
        }
        $draftMatchId = (int) $game['draft_match_id'];

        $result = $this->withGameLock($gameId, function () use ($gameId, $draftMatchId, $userId, $action): array {
            $match = $this->fetchDraftMatch($draftMatchId);
            if ($match['status'] !== 'drafting') {
                throw new GameStateException('This match is not currently drafting');
            }
            if (!in_array($action, ['take', 'pass'], true)) {
                throw new GameStateException('action must be "take" or "pass"');
            }

            $pdo = Connection::get();
            $stateStmt = $pdo->prepare('SELECT * FROM draft_winston_state WHERE draft_match_id = :id');
            $stateStmt->execute(['id' => $draftMatchId]);
            $state = $stateStmt->fetch();
            if ($state === false) {
                throw new GameStateException("No Winston Draft state for match {$draftMatchId}");
            }
            if ((int) $state['current_player_user_id'] !== $userId) {
                throw new GameStateException("It's not your turn to draft");
            }

            $deck = array_map(intval(...), json_decode((string) $state['remaining_deck_card_ids'], true));
            $piles = [
                1 => array_map(intval(...), json_decode((string) $state['pile_1_card_ids'], true)),
                2 => array_map(intval(...), json_decode((string) $state['pile_2_card_ids'], true)),
                3 => array_map(intval(...), json_decode((string) $state['pile_3_card_ids'], true)),
            ];
            $currentPileNumber = (int) $state['current_pile_number'];
            $lastDraftActionByUserId = (array) json_decode((string) $state['last_draft_action_by_user_id'], true);

            $userIds = $this->draftMatchUserIds($draftMatchId);
            $playerCount = count($userIds);
            $seatIndex = array_search($userId, $userIds, true);
            $nextSeatUserId = $userIds[($seatIndex + 1) % $playerCount];

            $turnEnds = false;
            $newlyDrafted = [];

            if ($action === 'take') {
                $newlyDrafted = $piles[$currentPileNumber];
                $piles[$currentPileNumber] = $deck !== [] ? [array_shift($deck)] : [];
                $turnEnds = true;
                // Keyed by user_id (not a fixed "player 1"/"player 2" slot)
                // so each player's own most recent action can be looked up
                // independently -- see winstonDraftDraftingStateFor()'s own
                // opponent_last_take_pile_number/opponent_last_drew_from_deck.
                $lastDraftActionByUserId[(string) $userId] = $currentPileNumber;
            } else {
                // 'pass' -- the active pile grows by 1 (if able) regardless of
                // whether we then move to the next pile or, for pile 3,
                // trigger the mandatory auto-draw below.
                if ($deck !== []) {
                    $piles[$currentPileNumber][] = array_shift($deck);
                }
                if ($currentPileNumber < 3) {
                    $currentPileNumber++;
                } else {
                    if ($deck !== []) {
                        $newlyDrafted[] = array_shift($deck);
                    }
                    $turnEnds = true;
                    // Declining pile 3 also ends the turn, just like a take --
                    // record it distinctly (the string "deck", never a valid
                    // pile number) so the opponent's view can tell "last took
                    // pile N" apart from "last declined everything and drew
                    // from the deck instead", rather than showing a stale
                    // pile number from several turns back.
                    $lastDraftActionByUserId[(string) $userId] = 'deck';
                }
            }

            $this->appendDraftedCardIds($draftMatchId, $userId, $newlyDrafted);

            // Issue #315's Winston Draft pick-position signal: $newlyDrafted
            // is either the whole pile just taken (pile size = its own
            // count -- a small pile means it was taken fresh, a large one
            // means it was passed around and grew first) or the single
            // forced deck-draw card from declining pile 3 (count 1, always
            // freshest, since nobody else has seen it) -- count($newlyDrafted)
            // covers both uniformly. Empty for a plain pass that doesn't
            // end the turn, hence the guard.
            if ($newlyDrafted !== []) {
                $this->cardStats->recordWinstonDraftPick($newlyDrafted, count($newlyDrafted));
            }

            $draftCompleted = $deck === [] && $piles[1] === [] && $piles[2] === [] && $piles[3] === [];

            if ($draftCompleted) {
                $pdo->prepare('DELETE FROM draft_winston_state WHERE draft_match_id = :id')->execute(['id' => $draftMatchId]);
                $this->finalizeWinstonDraft($gameId, $draftMatchId, $userIds);

                return ['action_completed' => $action, 'turn_advanced' => false, 'draft_completed' => true];
            }

            $nextPlayerUserId = $turnEnds ? $nextSeatUserId : $userId;
            $nextPileNumber = $turnEnds ? 1 : $currentPileNumber;

            $pdo->prepare(
                'UPDATE draft_winston_state
                 SET remaining_deck_card_ids = :deck, pile_1_card_ids = :pile1, pile_2_card_ids = :pile2, pile_3_card_ids = :pile3,
                     current_player_user_id = :current_player, current_pile_number = :current_pile,
                     last_draft_action_by_user_id = :last_action
                 WHERE draft_match_id = :match_id'
            )->execute([
                'deck' => json_encode(array_values($deck)),
                'pile1' => json_encode(array_values($piles[1])),
                'pile2' => json_encode(array_values($piles[2])),
                'pile3' => json_encode(array_values($piles[3])),
                'current_player' => $nextPlayerUserId,
                'current_pile' => $nextPileNumber,
                'last_action' => json_encode($lastDraftActionByUserId),
                'match_id' => $draftMatchId,
            ]);

            if ($turnEnds) {
                $this->notifyDraftUsersItsYourTurn($gameId, [$nextPlayerUserId], "Game #{$gameId}'s Winston Draft is ready for your pick.", 'draft-pick');
            }

            return ['action_completed' => $action, 'turn_advanced' => $turnEnds, 'draft_completed' => false];
        });

        $this->touchLastMoveAt($gameId);

        return $result;
    }

    /**
     * Winston Draft's own draft-completion step, called the instant the
     * shared deck and all 3 piles are simultaneously empty (see
     * submitWinstonDraftPick()). Unlike Quick Draft's finalizeQuickDraft(),
     * drafted_card_ids is already fully populated by now -- every pick was
     * written incrementally as it happened, so there's nothing left to
     * derive here.
     *
     * The physical rules are explicit that a player short of
     * WINSTON_MIN_DECK_SIZE total drafted cards automatically loses ("if
     * you don't have twelve cards, you will automatically lose any game,
     * so make sure you draft at least twelve"). For the original 2-player
     * match that meant the whole match completing immediately with the
     * other player as winner_user_id -- no games ever played. For 3-4
     * players (issue #189), one player's own shortfall shouldn't cost
     * everyone else their match: each short player is instead dropped
     * from the match/game entirely (removeShortWinstonDraftPlayer()),
     * as if they'd never been part of it -- no deck was ever submitted
     * for them, so there's nothing of theirs left in the game to clean
     * up beyond their own draft_match_players/game_players rows. The
     * survivors then proceed to deck_building as an ordinary
     * (N - short count)-player match, `draftGamesToWin()`/every other
     * multiplayer-aware helper already deriving their own player count
     * from draftMatchUserIds() fresh each time, so nothing needs to be
     * told about the drop explicitly. Two edge cases collapse back to
     * the original 2-player behavior exactly: if every player is short,
     * there's no one left to play at all (match ends, no winner); if
     * dropping the short players leaves exactly one player standing,
     * there's no match left to play either -- that one survivor simply
     * wins outright, the same as the original "the other player
     * automatically wins" rule.
     *
     * @param int[] $userIds the match's user ids (any count 2-4)
     */
    private function finalizeWinstonDraft(int $gameId, int $draftMatchId, array $userIds): void
    {
        $pdo = Connection::get();

        $countsStmt = $pdo->prepare('SELECT user_id, drafted_card_ids FROM draft_match_players WHERE draft_match_id = :id');
        $countsStmt->execute(['id' => $draftMatchId]);
        $draftedCounts = [];
        foreach ($countsStmt->fetchAll() as $row) {
            $draftedCounts[(int) $row['user_id']] = count(json_decode((string) $row['drafted_card_ids'], true));
        }

        $shortUserIds = array_keys(array_filter(
            $draftedCounts,
            fn (int $count): bool => $count < self::WINSTON_MIN_DECK_SIZE
        ));
        $survivingUserIds = array_values(array_diff($userIds, $shortUserIds));

        if ($survivingUserIds === []) {
            // Everyone came up short -- no one left to play at all.
            $this->abandonDraftMatch($gameId, $draftMatchId);

            return;
        }

        if (count($survivingUserIds) === 1) {
            // Only one player left standing -- there's no match left to
            // play, so they simply win outright (the original 2-player
            // "the other player automatically wins" rule, degenerated).
            // recordMatchCompletionStats() runs BEFORE the short players'
            // rows are removed below, so it still sees (and credits a
            // match_loss to) every one of them, exactly like the original
            // 2-player auto-loss always did.
            $winnerUserId = $survivingUserIds[0];

            $pdo->prepare(
                "UPDATE draft_matches SET status = 'completed', winner_user_id = :winner, completed_at = NOW() WHERE id = :id"
            )->execute(['winner' => $winnerUserId, 'id' => $draftMatchId]);
            $pdo->prepare(
                "UPDATE games SET status = 'abandoned' WHERE draft_match_id = :match_id AND match_game_number = 1"
            )->execute(['match_id' => $draftMatchId]);
            $this->recordMatchCompletionStats($draftMatchId, $winnerUserId);

            foreach ($shortUserIds as $shortUserId) {
                $this->removeDraftMatchPlayer($gameId, $draftMatchId, $shortUserId);
            }

            return;
        }

        // 2+ players survive -- each short player is dropped from the
        // match entirely (as if they'd never been part of it) with no
        // match_losses recorded for them, and the survivors proceed to
        // deck_building as their own (N - short count)-player match; only
        // ITS eventual real winner/losers get match stats recorded, via
        // advanceDraftMatch()'s own existing recordMatchCompletionStats()
        // call once that match actually completes.
        foreach ($shortUserIds as $shortUserId) {
            $this->removeDraftMatchPlayer($gameId, $draftMatchId, $shortUserId);
        }

        $pdo->prepare("UPDATE draft_matches SET status = 'deck_building' WHERE id = :id")->execute(['id' => $draftMatchId]);
        $this->notifyDraftUsersItsYourTurn($gameId, $survivingUserIds, "Game #{$gameId}'s draft is complete -- submit your deck.", 'draft-deck');
    }

    /**
     * Ends a draft match with no winner at all -- every seated
     * participant just walks away with nothing decided. Used both by
     * finalizeWinstonDraft() above (every player came up short of
     * WINSTON_MIN_DECK_SIZE) and by resignGame()'s own draft-phase branch
     * below (a mid-drafting resignation, which -- unlike a deck_building-
     * phase resignation -- can't gracefully continue without the
     * resigning player; see that method's own docblock for why). $gameId
     * is whichever of the match's own games rows is still 'waiting' right
     * now (match_game_number 1 for a fresh match, or a later game number
     * if this fires between games of an already-underway best-of-N draft
     * match) -- marking it 'abandoned' rather than deleting it preserves
     * it as a plain historical record, the same way expireStaleActiveGames()
     * leaves a force-ended game's row in place instead of removing it.
     */
    private function abandonDraftMatch(int $gameId, int $draftMatchId): void
    {
        Connection::get()->prepare(
            "UPDATE draft_matches SET status = 'completed', winner_user_id = NULL, completed_at = NOW() WHERE id = :id"
        )->execute(['id' => $draftMatchId]);
        Connection::get()->prepare(
            "UPDATE games SET status = 'abandoned' WHERE id = :game_id"
        )->execute(['game_id' => $gameId]);
    }

    /**
     * Drops a draft match player from the match entirely -- as if they'd
     * never been part of it. Called once per short player from
     * finalizeWinstonDraft() above (a player left short of
     * WINSTON_MIN_DECK_SIZE once Winston Draft ends) and from
     * resignGame()'s own draft-phase branch below (a deck_building-phase
     * resignation, with 2+ players surviving -- see that method's own
     * docblock). Both callers only ever reach this before the match's
     * current game has actually started (nothing has been dealt yet, so
     * there's no game_cards/game_rounds row anywhere referencing that
     * seat to clean up first, unlike resignGame()'s own mid-game removal
     * of an already-playing seat). Deletes their draft_match_players row
     * (so draftMatchUserIds()/every player-count-derived helper simply
     * never sees them again) and their game_players row for $gameId.
     * Leaving a gap in the remaining seats' own seat_order values is
     * harmless -- every seat_order-ordered query already just orders by
     * it rather than indexing arithmetically off of it.
     */
    private function removeDraftMatchPlayer(int $gameId, int $draftMatchId, int $userId): void
    {
        $pdo = Connection::get();
        $pdo->prepare('DELETE FROM draft_match_players WHERE draft_match_id = :match_id AND user_id = :user_id')
            ->execute(['match_id' => $draftMatchId, 'user_id' => $userId]);

        $gamePlayerId = $this->gamePlayerIdFor($gameId, $userId);
        if ($gamePlayerId !== null) {
            $pdo->prepare('DELETE FROM game_players WHERE id = :id')->execute(['id' => $gamePlayerId]);
        }
    }

    // -- Grid Draft (issue #188) ----------------------------------------

    /**
     * Deals Grid Draft's own opening state for a freshly-created match:
     * shuffles $poolCardIds, deals the first gridDraftCardsPerRound(count($userIds))
     * cards off the top into round 1's grid, and randomly picks who picks
     * first this round (rotates to the next seat every round thereafter --
     * see submitGridDraftPick()). Called once from createGame(), exactly
     * where initializeWinstonDraft()/dealQuickDraftRound() are called for
     * the other two draft deck_types. Notifies whoever picks first that
     * it's their pick -- see notifyDraftUsersItsYourTurn()'s own docblock.
     *
     * @param int[] $poolCardIds
     * @param int[] $userIds the match's user ids (any count 2-4)
     */
    private function initializeGridDraft(int $gameId, int $draftMatchId, array $poolCardIds, array $userIds): void
    {
        $pool = $poolCardIds;
        shuffle($pool);

        $grid = array_splice($pool, 0, self::gridDraftCardsPerRound(count($userIds)));
        $firstPickerUserId = $userIds[array_rand($userIds)];

        Connection::get()->prepare(
            'INSERT INTO draft_grid_state
                (draft_match_id, remaining_deck_card_ids, current_round, grid_card_ids, first_picker_user_id, current_turn_user_id, picks_this_round)
             VALUES (:match_id, :deck, 1, :grid, :first_picker, :first_picker, 0)'
        )->execute([
            'match_id' => $draftMatchId,
            'deck' => json_encode(array_values($pool)),
            'grid' => json_encode($grid),
            'first_picker' => $firstPickerUserId,
        ]);

        $this->notifyDraftUsersItsYourTurn($gameId, [$firstPickerUserId], "Game #{$gameId}'s Grid Draft is ready for your pick.", 'draft-pick');
    }

    /**
     * @return int[] the $gridSize cell indices (row-major, 0-based) making
     *         up $axis ('row' or 'column') number $index (0 to $gridSize-1)
     *         of a $gridSize x $gridSize grid.
     */
    private function gridDraftLineCells(string $axis, int $index, int $gridSize): array
    {
        $cells = [];
        for ($i = 0; $i < $gridSize; $i++) {
            $cells[] = $axis === 'row' ? $index * $gridSize + $i : $index + $i * $gridSize;
        }

        return $cells;
    }

    /**
     * One player's row-or-column pick against Grid Draft's own current
     * gridDraftGridSize($playerCount) x gridDraftGridSize($playerCount)
     * grid -- see php-app/README.md's "Grid Draft" section for the full
     * mechanic, and the gridDraft*() helper methods' shared docblock above
     * for the multiplayer refill rule this implements. $axis is 'row' or
     * 'column', $index is 0 to gridDraftGridSize($playerCount) - 1.
     *
     * A pick's own cardsTaken is always all of the line's cells UNLESS an
     * earlier, unrefilled pick this same round already emptied one or more
     * of them -- derived purely by counting non-null cells, never by
     * comparing axes/indices explicitly. A pick that would take 0 cards
     * (choosing a line every one of whose cells is already empty) is
     * rejected outright. picks_this_round tracks how many picks this
     * round has already seen (0 at the start of every round); the pick
     * that just completes it (raising the count to the match's own
     * player count) refills the cells it took only if this pick's own
     * 1-indexed position is at most (playerCount - 2) -- i.e. every pick
     * except the round's last two -- then, if this WAS the round's final
     * pick, either deals the next round's fresh grid (rotating who picks
     * first to the next seat) or, after round gridDraftRounds($playerCount),
     * ends the draft and moves the match to deck_building. The pool always
     * divides evenly by gridDraftCardsDrawnPerRound($playerCount), so
     * there's never a short-final-round case to handle.
     *
     * @return array{axis:string, index:int, cards_taken:int[], round_completed:bool, turn_advanced:bool, draft_completed:bool}
     */
    public function submitGridDraftPick(int $gameId, int $userId, string $axis, int $index): array
    {
        $game = $this->fetchGame($gameId);
        if ($game['deck_type'] !== 'grid_draft' || $game['draft_match_id'] === null) {
            throw new GameStateException("Game {$gameId} is not a Grid Draft game");
        }
        $draftMatchId = (int) $game['draft_match_id'];

        $result = $this->withGameLock($gameId, function () use ($gameId, $draftMatchId, $userId, $axis, $index): array {
            $match = $this->fetchDraftMatch($draftMatchId);
            if ($match['status'] !== 'drafting') {
                throw new GameStateException('This match is not currently drafting');
            }
            if (!in_array($axis, ['row', 'column'], true)) {
                throw new GameStateException('axis must be "row" or "column"');
            }

            $userIds = $this->draftMatchUserIds($draftMatchId);
            $playerCount = count($userIds);
            $gridSize = self::gridDraftGridSize($playerCount);

            if ($index < 0 || $index >= $gridSize) {
                throw new GameStateException("index must be between 0 and " . ($gridSize - 1));
            }

            $pdo = Connection::get();
            $stateStmt = $pdo->prepare('SELECT * FROM draft_grid_state WHERE draft_match_id = :id');
            $stateStmt->execute(['id' => $draftMatchId]);
            $state = $stateStmt->fetch();
            if ($state === false) {
                throw new GameStateException("No Grid Draft state for match {$draftMatchId}");
            }
            if ((int) $state['current_turn_user_id'] !== $userId) {
                throw new GameStateException("It's not your turn to draft");
            }

            $grid = json_decode((string) $state['grid_card_ids'], true);
            $cells = $this->gridDraftLineCells($axis, $index, $gridSize);
            $cardsTaken = [];
            $clearedCells = [];
            foreach ($cells as $cell) {
                if ($grid[$cell] !== null) {
                    $cardsTaken[] = (int) $grid[$cell];
                    $grid[$cell] = null;
                    $clearedCells[] = $cell;
                }
            }

            if ($cardsTaken === []) {
                throw new GameStateException('No cards remain in that row/column');
            }

            $this->appendDraftedCardIds($draftMatchId, $userId, $cardsTaken);

            // Issue #315's Grid Draft pick-position signal -- the round
            // this pick happened in, read before it's ever incremented
            // below.
            $this->cardStats->recordGridDraftPick($cardsTaken, (int) $state['current_round']);

            $picksThisRound = (int) $state['picks_this_round'] + 1;
            $remainingDeck = array_map(intval(...), json_decode((string) $state['remaining_deck_card_ids'], true));

            if ($picksThisRound <= max($playerCount - 2, 0)) {
                $refillCardIds = array_splice($remainingDeck, 0, count($clearedCells));
                foreach ($clearedCells as $i => $cell) {
                    $grid[$cell] = $refillCardIds[$i];
                }
            }

            if ($picksThisRound < $playerCount) {
                // Round isn't over yet -- hand the turn to the next seat.
                $seatIndex = array_search($userId, $userIds, true);
                $nextTurnUserId = $userIds[($seatIndex + 1) % $playerCount];

                $pdo->prepare(
                    'UPDATE draft_grid_state
                     SET grid_card_ids = :grid, remaining_deck_card_ids = :deck, current_turn_user_id = :next_turn, picks_this_round = :picks
                     WHERE draft_match_id = :match_id'
                )->execute([
                    'grid' => json_encode($grid),
                    'deck' => json_encode(array_values($remainingDeck)),
                    'next_turn' => $nextTurnUserId,
                    'picks' => $picksThisRound,
                    'match_id' => $draftMatchId,
                ]);

                $this->notifyDraftUsersItsYourTurn($gameId, [$nextTurnUserId], "Game #{$gameId}'s Grid Draft is ready for your pick.", 'draft-pick');

                return [
                    'axis' => $axis,
                    'index' => $index,
                    'cards_taken' => $cardsTaken,
                    'round_completed' => false,
                    'turn_advanced' => true,
                    'draft_completed' => false,
                ];
            }

            // Round's last pick -- the round is over; whatever's left in
            // $grid is simply discarded (never reshuffled back into the pool).
            $currentRound = (int) $state['current_round'];

            if ($currentRound >= self::gridDraftRounds($playerCount)) {
                $pdo->prepare('DELETE FROM draft_grid_state WHERE draft_match_id = :id')->execute(['id' => $draftMatchId]);
                $pdo->prepare("UPDATE draft_matches SET status = 'deck_building' WHERE id = :id")->execute(['id' => $draftMatchId]);
                $this->notifyDraftUsersItsYourTurn($gameId, $userIds, "Game #{$gameId}'s draft is complete -- submit your deck.", 'draft-deck');

                return [
                    'axis' => $axis,
                    'index' => $index,
                    'cards_taken' => $cardsTaken,
                    'round_completed' => true,
                    'turn_advanced' => false,
                    'draft_completed' => true,
                ];
            }

            $nextGrid = array_splice($remainingDeck, 0, self::gridDraftCardsPerRound($playerCount));
            $firstPickerSeatIndex = array_search((int) $state['first_picker_user_id'], $userIds, true);
            $nextFirstPickerUserId = $userIds[($firstPickerSeatIndex + 1) % $playerCount];

            $pdo->prepare(
                'UPDATE draft_grid_state
                 SET remaining_deck_card_ids = :deck, current_round = :round, grid_card_ids = :grid,
                     first_picker_user_id = :first_picker, current_turn_user_id = :first_picker, picks_this_round = 0
                 WHERE draft_match_id = :match_id'
            )->execute([
                'deck' => json_encode(array_values($remainingDeck)),
                'round' => $currentRound + 1,
                'grid' => json_encode($nextGrid),
                'first_picker' => $nextFirstPickerUserId,
                'match_id' => $draftMatchId,
            ]);

            $this->notifyDraftUsersItsYourTurn($gameId, [$nextFirstPickerUserId], "Game #{$gameId}'s draft round " . ($currentRound + 1) . ' is ready for your pick.', 'draft-pick');

            return [
                'axis' => $axis,
                'index' => $index,
                'cards_taken' => $cardsTaken,
                'round_completed' => true,
                'turn_advanced' => true,
                'draft_completed' => false,
            ];
        });

        $this->touchLastMoveAt($gameId);

        return $result;
    }

    /**
     * playMood()/pass()/respondToDecision() each load a BoardState, mutate
     * it in memory, and save it back across one or more separate SQL
     * transactions (see e.g. respondToDecision()'s own sequential
     * transactions for a chained scoring decision) -- individually atomic,
     * but with no protection against a second request for the *same game*
     * interleaving somewhere in between and silently clobbering the
     * first's changes when both eventually save (migration 0011 closed
     * this specific window for pending-decision batches, but the same gap
     * exists for board state generally). A MySQL named lock, held for the
     * caller's entire duration via $fn rather than scoped to any one SQL
     * transaction, serializes every actual mutation for a game without
     * requiring the three entry points' already-nontrivial internal
     * transaction structure to change at all. Keyed by game id, not round
     * id, since a round can complete and a new one begin mid-call
     * (scoreRoundAndAdvance()) -- only one game-wide lock is ever needed
     * regardless. Named locks are session-scoped, not transaction-scoped,
     * and MariaDB releases them automatically if a connection dies, so
     * there's no risk of a crashed request wedging a game forever.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withGameLock(int $gameId, callable $fn): mixed
    {
        $pdo = Connection::get();
        $lockName = "moodswings_game:{$gameId}";

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$lockName, $this->gameLockTimeoutSeconds]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new GameStateException("Game {$gameId} is busy with another action -- try again");
        }

        try {
            return $fn();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    /**
     * @param array<string, mixed> $choices
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int, pending_decision?: bool}
     */
    public function playMood(int $gameId, int $gamePlayerId, int $cardId, array $choices): array
    {
        $result = $this->withGameLock($gameId, function () use ($gameId, $gamePlayerId, $cardId, $choices): array {
            $round = $this->currentRound($gameId);
            $roundId = (int) $round['id'];
            $this->assertNoPendingDecision($roundId);

            $state = $this->boardStates->load($gameId);
            $playerChoices = new PlayerChoices($choices);
            $result = $this->plays->playMood($state, $gamePlayerId, $cardId, $playerChoices, $this->cardNamesFor($gameId));

            if ($result->isPending) {
                $pdo = Connection::get();
                $pdo->beginTransaction();

                try {
                    $this->boardStates->save($gameId, $state);
                    $this->updateRoundTurnState($roundId, $gamePlayerId, $state->pendingPlayGrants(), $state->discardedThisRound());
                    $this->writePendingBatch($gameId, $roundId, $gamePlayerId, $playerChoices, $result->invocationChoices, $result);
                    $this->logEvent($gameId, $roundId, $gamePlayerId, 'pending_decision_created', $cardId, $this->withPlayedFrom($state, $cardId, $choices), $state);

                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => true];
            }

            $this->logEvent($gameId, $roundId, $gamePlayerId, 'mood_played', $cardId, $this->withPlayedFrom($state, $cardId, $choices), $state);

            return $this->finishPlay($gameId, $round, $gamePlayerId, $state, $gamePlayerId);
        });

        $this->touchLastMoveAt($gameId);
        $this->clearQueuedNotificationForGamePlayer($gameId, $gamePlayerId);

        return $result;
    }

    /** @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int} */
    public function pass(int $gameId, int $gamePlayerId): array
    {
        $result = $this->withGameLock($gameId, function () use ($gameId, $gamePlayerId): array {
            $round = $this->currentRound($gameId);
            $this->assertNoPendingDecision((int) $round['id']);

            if ((int) $round['current_turn_game_player_id'] !== $gamePlayerId) {
                throw new GameStateException("It is not player {$gamePlayerId}'s turn");
            }

            $this->logEvent($gameId, (int) $round['id'], $gamePlayerId, 'turn_passed', null, []);

            return $this->advanceTurn($gameId, $round, $this->boardStates->load($gameId), $gamePlayerId);
        });

        $this->touchLastMoveAt($gameId);
        $this->clearQueuedNotificationForGamePlayer($gameId, $gamePlayerId);

        return $result;
    }

    /**
     * Lets a seated player give up instead of playing a game out.
     *
     * Team-format games (always exactly two opposing SIDES -- a 2v2 team
     * is atomic) and every 2-player format (duel, draft) immediately
     * complete the whole game crediting whoever's left -- the opposing
     * team via winner_team_id, or the sole remaining player otherwise --
     * exactly like a normal round-ending win, just without a round
     * actually being scored (the round in progress is abandoned instead).
     * 'standard' format uniquely supports 3-4 players though, and for
     * that case the game does NOT end: the resigning player is marked
     * out, their future turns are skipped (see advanceTurn()'s active-
     * player filtering), and they're permanently excluded from winning
     * any round or the game (see finishScoringAndAdvance()) -- everyone
     * else keeps playing to a normal wins_needed finish. That "continue
     * without them" case only ever reduces $activeIds' count by one at a
     * time as further players resign, until it eventually drops to 1 and
     * the next resignation completes the game the same way a 2-player
     * game's own resignation always has.
     *
     * Resigning while a decision is pending is disallowed (mirrors
     * playMood()/pass()'s own assertNoPendingDecision() gate) rather than
     * trying to reason about completing a game or reassigning a turn out
     * from under an outstanding Compulsion-style decision -- resolve the
     * decision first, then resign.
     *
     * A Quick/Winston/Grid Draft match's own game row never reaches
     * status='in_progress' until drafting AND deck-building are both
     * fully done (see startGame()'s requireDraftDecksSubmitted() gate),
     * so this method's own status='in_progress' requirement below can
     * never be satisfied while a draft match is still drafting or
     * building decks -- the very case this issue asks for. Rather than a
     * separate endpoint, this checks for that case FIRST and delegates
     * to resignFromDraftMatch() below, so the exact same "Resign" action
     * a player already knows works everywhere else in the app.
     *
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int}
     */
    public function resignGame(int $gameId, int $gamePlayerId): array
    {
        $result = $this->withGameLock($gameId, function () use ($gameId, $gamePlayerId): array {
            $game = $this->fetchGame($gameId);

            if (
                $game['status'] === 'waiting'
                && in_array($game['deck_type'], ['quick_draft', 'winston_draft', 'grid_draft'], true)
                && $game['draft_match_id'] !== null
            ) {
                return $this->resignFromDraftMatch($gameId, $gamePlayerId, (int) $game['draft_match_id']);
            }

            if ($game['status'] !== 'in_progress') {
                throw new GameStateException("Game {$gameId} is not in progress");
            }

            $stmt = Connection::get()->prepare('SELECT * FROM game_players WHERE id = :id AND game_id = :game_id');
            $stmt->execute(['id' => $gamePlayerId, 'game_id' => $gameId]);
            $player = $stmt->fetch();
            if ($player === false) {
                throw new GameStateException("Player {$gamePlayerId} is not seated in game {$gameId}");
            }
            if ($player['resigned_at'] !== null) {
                throw new GameStateException("Player {$gamePlayerId} has already resigned");
            }

            $round = $this->currentRound($gameId);
            $this->assertNoPendingDecision((int) $round['id']);

            Connection::get()->prepare('UPDATE game_players SET resigned_at = NOW() WHERE id = :id')
                ->execute(['id' => $gamePlayerId]);

            $activeIds = $this->activeGamePlayerIds($gameId);

            if (self::isTeamFormat($game['format']) || count($activeIds) < 2) {
                return $this->completeGameByResignation($gameId, $game, $round, $gamePlayerId, $activeIds);
            }

            // Only the "continue without them" path needs this -- the
            // immediate-completion path above ends the game outright, so
            // whatever's left in play just stands as the final board's
            // own historical record, same as any other completed game.
            $this->removeResignedPlayerCardsFromBoard($gameId, $gamePlayerId);

            return $this->skipTurnForResignedPlayer($gameId, $round, $gamePlayerId);
        });

        $this->touchLastMoveAt($gameId);
        $this->clearQueuedNotificationForGamePlayer($gameId, $gamePlayerId);

        return $result;
    }

    /**
     * resignGame()'s own draft-phase branch (issue #144's "resign from a
     * draft match, even during the draft portion") -- called from inside
     * that method's own withGameLock() closure, so this never takes its
     * own separate lock. What "resigning" means here splits on exactly
     * where the match currently is:
     *
     * - Still `'drafting'`: no graceful "drop this player, everyone else
     *   keeps going" is possible. Quick Draft's own pile-passing math
     *   (submitQuickDraftPick()'s $playerCount-dependent seat-index
     *   arithmetic) is fixed for the whole round the instant it's dealt,
     *   Winston/Grid Draft each have exactly one player's turn "in
     *   flight" at a time with no defined meaning for "hand their
     *   half-finished turn to someone else" -- so a mid-drafting
     *   resignation always ends the WHOLE match for every seated player,
     *   the same abandonDraftMatch() outcome finalizeWinstonDraft() uses
     *   when literally everyone came up short.
     * - `'deck_building'`: drafting itself already fully finished, so
     *   there's no shared pile/turn state left to disrupt -- each
     *   player's own deck submission is a completely independent
     *   operation. This is exactly finalizeWinstonDraft()'s own "a short
     *   player is dropped, survivors continue" scenario, so it's handled
     *   identically here: 2+ remaining players just continue as their
     *   own (N-1)-player match (removeDraftMatchPlayer()); exactly 1
     *   remaining player wins outright (recordMatchCompletionStats(),
     *   same as finalizeWinstonDraft()'s own 1-survivor branch); 0
     *   remaining (every other player had already resigned first)
     *   abandons the match the same way the 'drafting' branch above does.
     *
     * $gameId is deliberately used as-is (not hardcoded to
     * match_game_number=1 the way finalizeWinstonDraft()'s own two
     * inline callers of this pattern are) -- a resignation between games
     * of an already-underway best-of-N draft match (advanceDraftMatch()
     * resets status back to 'deck_building' and inserts a fresh 'waiting'
     * games row for the next match_game_number) needs to abandon THAT
     * specific game, not game 1's own long-since-completed row.
     *
     * @return array{round_scored: bool, game_completed: bool}
     */
    private function resignFromDraftMatch(int $gameId, int $gamePlayerId, int $draftMatchId): array
    {
        $match = $this->fetchDraftMatch($draftMatchId);
        if (!in_array($match['status'], ['drafting', 'deck_building'], true)) {
            throw new GameStateException("Game {$gameId}'s draft match has already finished");
        }

        $stmt = Connection::get()->prepare('SELECT * FROM game_players WHERE id = :id AND game_id = :game_id');
        $stmt->execute(['id' => $gamePlayerId, 'game_id' => $gameId]);
        $player = $stmt->fetch();
        if ($player === false) {
            throw new GameStateException("Player {$gamePlayerId} is not seated in game {$gameId}");
        }
        if ($player['resigned_at'] !== null) {
            throw new GameStateException("Player {$gamePlayerId} has already resigned");
        }
        $userId = (int) $player['user_id'];

        $userIds = $this->draftMatchUserIds($draftMatchId);
        if (!in_array($userId, $userIds, true)) {
            throw new GameStateException("User {$userId} is not part of draft match {$draftMatchId}");
        }

        Connection::get()->prepare('UPDATE game_players SET resigned_at = NOW() WHERE id = :id')
            ->execute(['id' => $gamePlayerId]);
        $this->logEvent($gameId, null, $gamePlayerId, 'draft_resigned', null, []);

        if ($match['status'] === 'drafting') {
            $this->abandonDraftMatch($gameId, $draftMatchId);

            return ['round_scored' => false, 'game_completed' => true];
        }

        $survivingUserIds = array_values(array_diff($userIds, [$userId]));

        if ($survivingUserIds === []) {
            $this->abandonDraftMatch($gameId, $draftMatchId);

            return ['round_scored' => false, 'game_completed' => true];
        }

        if (count($survivingUserIds) === 1) {
            $winnerUserId = $survivingUserIds[0];

            Connection::get()->prepare(
                "UPDATE draft_matches SET status = 'completed', winner_user_id = :winner, completed_at = NOW() WHERE id = :id"
            )->execute(['winner' => $winnerUserId, 'id' => $draftMatchId]);
            Connection::get()->prepare(
                "UPDATE games SET status = 'abandoned' WHERE id = :game_id"
            )->execute(['game_id' => $gameId]);
            $this->recordMatchCompletionStats($draftMatchId, $winnerUserId);
            $this->removeDraftMatchPlayer($gameId, $draftMatchId, $userId);

            return ['round_scored' => false, 'game_completed' => true];
        }

        $this->removeDraftMatchPlayer($gameId, $draftMatchId, $userId);

        return ['round_scored' => false, 'game_completed' => false];
    }

    /**
     * "All of that resigning player's cards leave play" -- the 'standard'
     * 3-4 player "continue without them" case is the only one where the
     * board keeps being played on by everyone else, so their moods can't
     * just sit there mid-game as if they were still in it (still
     * scoring, still targetable, still whatever a while-in-play effect
     * would otherwise do), and their hand can't stay sitting there either
     * (still visible to Confusion-style "reveal a card" effects that skip
     * them as a *giver* -- see activePlayerOrder() -- but would otherwise
     * still find their hand non-empty for a "does an opponent have cards"
     * check). Both go to the bottom of the resigning player's own deck
     * rather than the discard pile -- a resignation isn't a scoring event
     * for any of those cards, so it shouldn't feed the discard-pile-driven
     * effects (Altruism, Corruption, etc.) the way an ordinary discard
     * would. `moodsOwnedBy()`/`hand()` both already return a snapshot copy
     * (PHP array value semantics), so iterating either one is safe even
     * though `moveInPlayToBottomOfDeck()`/`moveHandToBottomOfDeck()`
     * mutate $state's own underlying maps as they go.
     */
    private function removeResignedPlayerCardsFromBoard(int $gameId, int $gamePlayerId): void
    {
        $state = $this->boardStates->load($gameId);

        $moodCardIds = array_keys($state->moodsOwnedBy($gamePlayerId));
        $handCardIds = $state->hand($gamePlayerId);
        if ($moodCardIds === [] && $handCardIds === []) {
            return;
        }

        foreach ($moodCardIds as $cardId) {
            $state->moveInPlayToBottomOfDeck($cardId);
        }
        foreach ($handCardIds as $cardId) {
            $state->moveHandToBottomOfDeck($gamePlayerId, $cardId);
        }

        $this->boardStates->save($gameId, $state);
    }

    /**
     * The immediate-completion resign path -- 2-player games of any
     * format, and team-format games regardless of how many active
     * players remain (a team is atomic; there's no "continue without
     * one teammate" concept the way there is for 'standard'). The round
     * in progress is abandoned rather than scored (see migration 0033's
     * own docblock for why 'abandoned' exists as a round status), so
     * currentRound() -- the one thing gating playMood()/pass() -- can
     * never find an 'in_progress' round for this game again.
     *
     * @param int[] $activeGamePlayerIds non-resigned seats, seat_order ASC (unused for team format)
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id: int}
     */
    private function completeGameByResignation(int $gameId, array $game, array $round, int $resigningGamePlayerId, array $activeGamePlayerIds): array
    {
        Connection::get()->prepare(
            "UPDATE game_rounds SET status = 'abandoned', current_turn_game_player_id = NULL, plays_remaining = 0, pending_play_grants = '[]' WHERE id = :round_id"
        )->execute(['round_id' => (int) $round['id']]);

        $winnerTeamId = null;
        if (self::isTeamFormat($game['format'])) {
            $resigningTeamId = $this->teamIdByGamePlayer($gameId)[$resigningGamePlayerId];
            $winnerTeamId = $resigningTeamId === 0 ? 1 : 0;
            // Lowest seat_order member of the winning team, matching
            // finishTeamScoringAndAdvance()'s own representative
            // convention -- winner_team_id (set below) stays the
            // authoritative record either way (see totalWinsForTeam()).
            $winnerGamePlayerId = $this->teamMembers($gameId, $winnerTeamId)[0];
        } else {
            $winnerGamePlayerId = $activeGamePlayerIds[0];
        }

        Connection::get()->prepare(
            "UPDATE games SET status = 'completed', winner_game_player_id = :winner, winner_team_id = :winner_team, completed_at = NOW() WHERE id = :game_id"
        )->execute(['winner' => $winnerGamePlayerId, 'winner_team' => $winnerTeamId, 'game_id' => $gameId]);
        $this->recordGameCompletionStats($gameId, $winnerGamePlayerId, $winnerTeamId, $resigningGamePlayerId);

        // A no-op for every non-draft game -- see its own docblock.
        $this->advanceDraftMatch($gameId, $winnerGamePlayerId);

        return ['round_scored' => false, 'game_completed' => true, 'winner_game_player_id' => $winnerGamePlayerId];
    }

    /**
     * The 'standard' 3-4 player "continue without them" resign path --
     * the game keeps going, so this only needs to hand the turn onward
     * if it was actually the resigning player's turn (advanceTurn()'s own
     * active-player filtering already keeps them from ever being handed
     * a future one). Mirrors pass()'s own turn_passed logging/advance
     * call -- resigning mid-turn forfeits whatever's left of it exactly
     * like an explicit pass would.
     *
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int}
     */
    private function skipTurnForResignedPlayer(int $gameId, array $round, int $resigningGamePlayerId): array
    {
        if ((int) $round['current_turn_game_player_id'] !== $resigningGamePlayerId) {
            return ['round_scored' => false, 'game_completed' => false];
        }

        $this->logEvent($gameId, (int) $round['id'], $resigningGamePlayerId, 'turn_passed', null, ['resigned' => true]);

        return $this->advanceTurn($gameId, $round, $this->boardStates->load($gameId), $resigningGamePlayerId);
    }

    /** @return int[] game_players.id for every seat that hasn't resigned, seat_order ASC */
    private function activeGamePlayerIds(int $gameId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id FROM game_players WHERE game_id = :game_id AND resigned_at IS NULL ORDER BY seat_order ASC'
        );
        $stmt->execute(['game_id' => $gameId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The second player's own request, resolving one row of an outstanding
     * pending-decision batch (see MoodPlayService::playMood()'s
     * RequiresOpponentDecision handling) -- e.g. Compulsion's target
     * choosing which hand card to hand over. $choices is a flat
     * `{fieldKey: value}` bag matching the one field the caller was shown
     * (GameService::getState()'s pending_decision.field).
     *
     * @param array<string, mixed> $choices
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int, pending_decision?: bool}
     */
    public function respondToDecision(int $gameId, int $gamePlayerId, array $choices): array
    {
        $result = $this->withGameLock($gameId, function () use ($gameId, $gamePlayerId, $choices): array {
            $round = $this->currentRound($gameId);
            $roundId = (int) $round['id'];

            $batchRow = $this->activePendingBatch($roundId);
            if ($batchRow === null) {
                throw new GameStateException("Game {$gameId} has no decision pending");
            }

            $decisionRow = $this->activePendingDecision((int) $batchRow['id']);
            if ($decisionRow === null || (int) $decisionRow['target_game_player_id'] !== $gamePlayerId) {
                throw new GameStateException("Player {$gamePlayerId} has no decision pending in game {$gameId}");
            }

            $field = json_decode((string) $decisionRow['field'], true);
            $answerKey = $field['key'];

            if (($field['required'] ?? false) === true && ($choices[$answerKey] ?? null) === null) {
                // Reject before ever writing this row -- otherwise a blank/
                // missing submission gets persisted as "resolved" with a
                // null answer, and the batch only surfaces the resulting
                // failure later, to whichever OTHER player's response
                // happens to complete it (resolvePendingDecisions() throws
                // for this row's own key, and that throw rolls back that
                // later player's own perfectly valid answer too -- see
                // respondToDecision()'s catch block below). Validating here
                // attributes the error to the player who actually caused it
                // and never lets a bad answer reach "resolved" at all.
                throw new InvalidChoiceException("Missing required choice '{$answerKey}'");
            }

            if ($decisionRow['decision_type'] === self::AFTER_SCORING_ORDER_DECISION_TYPE) {
                // The only valid answer is a reordering of exactly this
                // decision's own pending cards -- validated (and rejected
                // before ever reaching "resolved") the same way the
                // required-field check above already attributes a bad
                // submission to whoever actually made it, rather than
                // letting applyAfterScoringHooks() silently fall back to
                // the default order for a malformed one later.
                $expectedCardIds = array_column($field['cards'], 'card_id');
                sort($expectedCardIds);
                $submittedCardIds = array_map(intval(...), (array) ($choices[$answerKey] ?? []));
                sort($submittedCardIds);
                if ($submittedCardIds !== $expectedCardIds) {
                    throw new InvalidChoiceException("'{$answerKey}' must be exactly this decision's own pending cards, in some order");
                }
            }

            $pdo = Connection::get();
            $pdo->beginTransaction();

            try {
                $pdo->prepare('UPDATE game_pending_decisions SET answer = :answer, resolved_at = NOW() WHERE id = :id')
                    ->execute([
                        'answer' => json_encode([$answerKey => $choices[$answerKey] ?? null]),
                        'id' => $decisionRow['id'],
                    ]);

                $remainingStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM game_pending_decisions WHERE batch_id = :batch_id AND resolved_at IS NULL'
                );
                $remainingStmt->execute(['batch_id' => $batchRow['id']]);

                if (((int) $remainingStmt->fetchColumn()) > 0) {
                    // Other targets in this batch (e.g. Disillusionment's/
                    // Suspicion's remaining players) still haven't answered.
                    $pdo->commit();

                    return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => true];
                }

                $playedCardId = (int) $batchRow['played_card_id'];
                $pdo->prepare('UPDATE game_pending_decision_batches SET resolved_at = NOW() WHERE id = :id')
                    ->execute(['id' => $batchRow['id']]);

                if (in_array($decisionRow['decision_type'], self::SCORING_DECISION_TYPES, true) || $decisionRow['decision_type'] === self::AFTER_SCORING_ORDER_DECISION_TYPE) {
                    // Every scoring-time decision (see scoreRoundAndAdvance()/
                    // finishScoringAndAdvance()) resolves the same way,
                    // whether it's Enthusiasm's/Passion's own (asked BEFORE
                    // scores are computed) or an after-scoring order choice
                    // (asked AFTER) -- entirely differently from a mid-play
                    // one: no MoodPlayService chain to resume, just
                    // re-entering finishScoringAndAdvance() itself, which
                    // re-checks everything from scratch (both scoring
                    // decisions, via nextUnresolvedScoringDecision() below,
                    // and its own internal after-scoring-order check) and
                    // either pauses again on whatever's next or finishes for
                    // real. Recomputing $scoringDecisions here even when an
                    // order decision (never a scoring one) was just answered
                    // is harmless -- resolvedScoringDecisionBonuses() only
                    // ever reads what's already resolved, which by this
                    // point in the round can only be actual scoring
                    // decisions, if any. None of these decision types moves
                    // a card, so this branch's own event has no BoardState
                    // to fold card history from.
                    $this->logEvent($gameId, $roundId, $gamePlayerId, 'pending_decision_resolved', $playedCardId, $choices);

                    $state = $this->boardStates->load($gameId);
                    $turnOrder = $this->turnOrderForRound($gameId, $round);

                    $nextDecision = $this->nextUnresolvedScoringDecision($state, $roundId, $turnOrder);
                    if ($nextDecision !== null) {
                        $this->writeScoringDecisionBatch($gameId, $roundId, $state, $nextDecision);
                        $this->logEvent($gameId, $roundId, $nextDecision['ownerId'], 'pending_decision_created', $nextDecision['cardId'], ['scoring_trigger' => true], $state);

                        $pdo->commit();

                        return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => true];
                    }

                    $scoringDecisions = $this->resolvedScoringDecisionBonuses($state, $roundId);
                    $result = $this->finishScoringAndAdvance($gameId, $round, $turnOrder, $state, $scoringDecisions, $gamePlayerId);
                    $pdo->commit();

                    return $result;
                }

                $answers = $this->collectAnswers((int) $batchRow['id']);
                $state = $this->boardStates->load($gameId);
                $topLevelChoices = new PlayerChoices((array) json_decode((string) $batchRow['top_level_choices'], true));
                $invocationChoices = new PlayerChoices((array) json_decode((string) $batchRow['invocation_choices'], true));
                $initiatingPlayerId = (int) $batchRow['initiating_game_player_id'];
                $invocationSeq = (int) $batchRow['invocation_seq'];

                $result = $this->plays->resolvePendingDecisions(
                    $state,
                    $playedCardId,
                    $initiatingPlayerId,
                    $topLevelChoices,
                    $invocationChoices,
                    $invocationSeq,
                    $answers,
                );

                // Logged only now, after resolvePendingDecisions() has
                // actually run -- $choices here is just the last responder's
                // own answer (every other target's answer was already
                // written to game_pending_decisions when they responded, and
                // isn't otherwise repeated here), but $state now carries
                // every zone move the resolution itself just made (e.g.
                // Malice's color cascade discarding moods beyond the two the
                // acting player chose, or Disillusionment/Suspicion
                // resolving every remaining target at once) -- see
                // BoardState::consumeCardMoves(). Logging any earlier (as
                // this used to, right after the UPDATE above) would always
                // log an empty move list, since none of these moves have
                // happened yet at that point.
                //
                // 'initiating_game_player_id' rides along in $details
                // purely so describeEvent()'s own grants_created/grants_lost
                // segments can attribute a grant to the right player --
                // this event's own acting_game_player_id is $gamePlayerId,
                // the RESPONDER (e.g. Intimidation's target, who just
                // revealed a card), but any grant a card like Intimidation
                // creates here always belongs to whoever's turn is actually
                // active (the player who played the card in the first
                // place), which is $initiatingPlayerId, not the responder --
                // see describeEvent()'s own docblock for why $actor alone
                // isn't a safe default for this one event type.
                $this->logEvent(
                    $gameId,
                    $roundId,
                    $gamePlayerId,
                    'pending_decision_resolved',
                    $playedCardId,
                    [...$choices, 'initiating_game_player_id' => $initiatingPlayerId],
                    $state,
                );

                if ($result->isPending) {
                    // Resolving the last decision uncovered another one --
                    // e.g. a Duplicity repeat of this same card also needing a
                    // real opponent decision, or (now that Duplicity's repeat
                    // is itself a pending decision) the acting player's own
                    // "repeat again?" offer. $result->invocationChoices is
                    // exactly the choices bag that new pause's own invocation
                    // was given -- see PlayResult's own docblock for why this
                    // can't be re-derived from any fixed location in
                    // top_level_choices anymore. top_level_choices itself is
                    // carried forward unchanged, same as the original play.
                    // Already inside this method's own transaction, so this
                    // just writes rows -- no nested beginTransaction().
                    $this->boardStates->save($gameId, $state);
                    $this->updateRoundTurnState($roundId, $initiatingPlayerId, $state->pendingPlayGrants(), $state->discardedThisRound());
                    $this->writePendingBatch($gameId, $roundId, $initiatingPlayerId, $topLevelChoices, $result->invocationChoices, $result);
                    $this->logEvent($gameId, $roundId, $initiatingPlayerId, 'pending_decision_created', $playedCardId, $this->withPlayedFrom($state, $playedCardId, []), $state);

                    $pdo->commit();

                    return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => true];
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            // No closing 'mood_played' event here -- unlike playMood()'s own
            // immediate-resolution path (which has no earlier event of its
            // own for this play), every respondToDecision() call that
            // reaches this point already has a 'pending_decision_created'
            // event announcing the play and a 'pending_decision_resolved'
            // event (just logged above, right after resolvePendingDecisions()
            // itself ran) covering everything that actually happened --
            // that event's own $state was already fully drained of
            // card_moves/ownership_changes/revealed_card_ids by the time
            // this point is reached, so a second 'mood_played' entry here
            // would only ever repeat "played {$cardName} ({$choiceSummary})",
            // a second time, with nothing new to say.
            return $this->finishPlay($gameId, $round, $initiatingPlayerId, $state, $gamePlayerId);
        });

        $this->touchLastMoveAt($gameId);
        $this->clearQueuedNotificationForGamePlayer($gameId, $gamePlayerId);

        return $result;
    }

    /**
     * 'closed_team's own pregame mechanic -- see "Closed Team Play" in
     * php-app/README.md: every player must pass exactly 2 of their
     * starting hand cards to their teammate, face down, before round 1
     * can begin. $cardIds must be exactly 2 distinct cards currently in
     * $gamePlayerId's own hand. Inserting this player's own row locks
     * their choice in immediately (it's never revisited), which is what
     * actually makes the exchange blind: the moment their teammate's own
     * row already exists too, this same call applies BOTH cards' transfer
     * right here -- so by the time a player can see anything about what
     * they received, their own choice was already committed. Once all 4
     * players (both teams) have submitted, round 1's already-chosen
     * first_game_player_id (set randomly by startGame()) is unfrozen the
     * same way any other frozen round is.
     *
     * @param int[] $cardIds
     * @return array{round_scored: bool, game_completed: bool, pending_decision: bool}
     */
    public function submitInitialCardPass(int $gameId, int $gamePlayerId, array $cardIds): array
    {
        $result = $this->withGameLock($gameId, function () use ($gameId, $gamePlayerId, $cardIds): array {
            $game = $this->fetchGame($gameId);
            if ($game['format'] !== 'closed_team') {
                throw new GameStateException("Game {$gameId} isn't a Closed Team Play game");
            }

            $cardIds = array_values(array_unique(array_map(intval(...), $cardIds)));
            if (count($cardIds) !== 2) {
                throw new GameStateException('You must pass exactly 2 cards to your teammate');
            }

            $pdo = Connection::get();

            $alreadySubmittedStmt = $pdo->prepare(
                'SELECT 1 FROM game_initial_card_passes WHERE game_id = :game_id AND game_player_id = :player_id'
            );
            $alreadySubmittedStmt->execute(['game_id' => $gameId, 'player_id' => $gamePlayerId]);
            if ($alreadySubmittedStmt->fetchColumn() !== false) {
                throw new GameStateException('You have already passed your cards to your teammate');
            }

            $state = $this->boardStates->load($gameId);
            foreach ($cardIds as $cardId) {
                if (!$state->isInHand($gamePlayerId, $cardId)) {
                    throw new GameStateException("Card {$cardId} isn't in your hand");
                }
            }

            $pdo->prepare(
                'INSERT INTO game_initial_card_passes (game_id, game_player_id, card_ids) VALUES (:game_id, :player_id, :card_ids)'
            )->execute([
                'game_id' => $gameId,
                'player_id' => $gamePlayerId,
                'card_ids' => json_encode($cardIds),
            ]);

            $teamId = $this->teamIdByGamePlayer($gameId)[$gamePlayerId];
            $teammateId = $this->otherTeamMember($gameId, $teamId, $gamePlayerId);

            $teammatePassStmt = $pdo->prepare(
                'SELECT card_ids FROM game_initial_card_passes WHERE game_id = :game_id AND game_player_id = :player_id'
            );
            $teammatePassStmt->execute(['game_id' => $gameId, 'player_id' => $teammateId]);
            $teammateCardIdsJson = $teammatePassStmt->fetchColumn();

            if ($teammateCardIdsJson !== false) {
                // Both members of this team have now submitted -- apply
                // this team's own actual card transfer right away,
                // independent of whether the OTHER team is done yet.
                $teammateCardIds = array_map(intval(...), json_decode((string) $teammateCardIdsJson, true));
                $this->transferHandCards($gamePlayerId, $cardIds, $teammateId);
                $this->transferHandCards($teammateId, $teammateCardIds, $gamePlayerId);
            }

            $submittedCountStmt = $pdo->prepare('SELECT COUNT(*) FROM game_initial_card_passes WHERE game_id = :game_id');
            $submittedCountStmt->execute(['game_id' => $gameId]);
            $allSubmitted = (int) $submittedCountStmt->fetchColumn() >= self::TEAM_PLAYER_COUNT;

            if ($allSubmitted) {
                $roundStmt = $pdo->prepare(
                    "SELECT * FROM game_rounds WHERE game_id = :game_id AND round_number = 1"
                );
                $roundStmt->execute(['game_id' => $gameId]);
                $round = $roundStmt->fetch();

                $firstPlayerId = (int) $round['first_game_player_id'];
                $freshState = $this->boardStates->load($gameId);
                $freshGrants = $this->computeFreshGrants($freshState, $firstPlayerId, 1);
                $this->boardStates->save($gameId, $freshState);
                $this->updateRoundTurnState((int) $round['id'], $firstPlayerId, $freshGrants, $freshState->discardedThisRound());
            }

            return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => !$allSubmitted];
        });

        $this->touchLastMoveAt($gameId);
        $this->clearQueuedNotificationForGamePlayer($gameId, $gamePlayerId);

        return $result;
    }

    /**
     * Reassigns $cardIds' owner_game_player_id straight in the database --
     * submitInitialCardPass()'s own card transfer happens strictly between
     * plays (nothing has loaded a BoardState for this round's actual turn
     * order yet), so there's no in-memory hand to keep in sync; the next
     * BoardStateRepository::load() picks up the new ownership fresh from
     * game_cards, same as it does for every other zone change.
     *
     * @param int[] $cardIds
     */
    private function transferHandCards(int $fromGamePlayerId, array $cardIds, int $toGamePlayerId): void
    {
        $stmt = Connection::get()->prepare(
            "UPDATE game_cards SET owner_game_player_id = :to_player
             WHERE id = :card_id AND owner_game_player_id = :from_player AND zone = 'hand'"
        );
        foreach ($cardIds as $cardId) {
            $stmt->execute(['to_player' => $toGamePlayerId, 'card_id' => $cardId, 'from_player' => $fromGamePlayerId]);
        }
    }

    /**
     * getState()'s own view of 'closed_team's pregame card-pass phase --
     * null once all 4 players have submitted (at which point round 1 has
     * already unfrozen, so there's nothing left to report). Which specific
     * 2 cards each player chose is never exposed here -- only WHO has
     * submitted, which reveals nothing about hand contents.
     *
     * @return ?array{you_submitted: bool, submitted_game_player_ids: int[]}
     */
    private function pendingInitialCardPass(int $gameId, ?int $viewerGamePlayerId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT game_player_id FROM game_initial_card_passes WHERE game_id = :game_id'
        );
        $stmt->execute(['game_id' => $gameId]);
        $submittedIds = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (count($submittedIds) >= self::TEAM_PLAYER_COUNT) {
            return null;
        }

        return [
            // $viewerGamePlayerId === null for a spectator -- never in
            // $submittedIds (an int[] of real seats), so correctly false.
            'you_submitted' => $viewerGamePlayerId !== null && in_array($viewerGamePlayerId, $submittedIds, true),
            'submitted_game_player_ids' => $submittedIds,
        ];
    }

    /**
     * Either member of the deciding team may propose an answer -- who
     * plays next, or who receives the losing team's shared draw (see
     * "Open Team Play" in php-app/README.md) -- but it isn't locked in
     * until the OTHER teammate confirms via confirmTeamDecision().
     * $proposedGamePlayerId must be one of the two candidates the
     * decision itself was created for (always that same team's own two
     * members).
     *
     * @return array{round_scored: bool, game_completed: bool, pending_decision: bool}
     */
    public function proposeTeamDecision(int $gameId, int $actingGamePlayerId, int $proposedGamePlayerId): array
    {
        $result = $this->withGameLock($gameId, function () use ($gameId, $actingGamePlayerId, $proposedGamePlayerId): array {
            $decision = $this->activeTeamDecision($gameId);
            if ($decision === null) {
                throw new GameStateException("Game {$gameId} has no team decision pending");
            }
            if ($decision['phase'] !== 'propose') {
                throw new GameStateException('This team decision is already awaiting confirmation');
            }

            $candidateIds = array_map(intval(...), json_decode((string) $decision['candidate_game_player_ids'], true));
            if (!in_array($actingGamePlayerId, $candidateIds, true)) {
                throw new GameStateException("Player {$actingGamePlayerId} isn't on the team making this decision");
            }
            if (!in_array($proposedGamePlayerId, $candidateIds, true)) {
                throw new GameStateException("The proposed player must be one of your own team's two members");
            }

            Connection::get()->prepare(
                'UPDATE game_team_decisions SET phase = :phase, proposer_game_player_id = :proposer, proposed_game_player_id = :proposed WHERE id = :id'
            )->execute([
                'phase' => 'confirm',
                'proposer' => $actingGamePlayerId,
                'proposed' => $proposedGamePlayerId,
                'id' => $decision['id'],
            ]);

            return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => true];
        });

        $this->touchLastMoveAt($gameId);
        $this->clearQueuedNotificationForGamePlayer($gameId, $actingGamePlayerId);

        return $result;
    }

    /**
     * The OTHER teammate's response to a proposed answer (never the
     * proposer themselves) -- approving locks it in and actually acts on
     * it (applyTurnOrderDecision()/applyDrawRecipientDecision()); rejecting
     * sends the decision back to 'propose' (clearing the previous
     * proposal) so either teammate can try again.
     *
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int, pending_decision?: bool}
     */
    public function confirmTeamDecision(int $gameId, int $actingGamePlayerId, bool $approve): array
    {
        $result = $this->withGameLock($gameId, function () use ($gameId, $actingGamePlayerId, $approve): array {
            $decision = $this->activeTeamDecision($gameId);
            if ($decision === null) {
                throw new GameStateException("Game {$gameId} has no team decision pending");
            }
            if ($decision['phase'] !== 'confirm') {
                throw new GameStateException('This team decision has no proposal awaiting confirmation yet');
            }
            if ((int) $decision['proposer_game_player_id'] === $actingGamePlayerId) {
                throw new GameStateException("Player {$actingGamePlayerId} proposed this answer and can't also confirm it");
            }

            $pdo = Connection::get();

            if (!$approve) {
                $pdo->prepare(
                    'UPDATE game_team_decisions SET phase = :phase, proposer_game_player_id = NULL, proposed_game_player_id = NULL WHERE id = :id'
                )->execute(['phase' => 'propose', 'id' => $decision['id']]);

                return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => true];
            }

            $pdo->prepare('UPDATE game_team_decisions SET resolved_at = NOW() WHERE id = :id')
                ->execute(['id' => $decision['id']]);

            if ($decision['decision_type'] !== 'turn_order') {
                return $this->applyDrawRecipientDecision($gameId, (int) $decision['game_round_id'], (int) $decision['proposed_game_player_id']);
            }

            // 'closed_team' has only one live "who goes first" choice per
            // round (the leader) rather than 'team's two forced turn
            // placements -- see applyClosedTeamLeaderDecision()'s own
            // docblock for how it differs from applyTurnOrderDecision().
            return $this->fetchGame($gameId)['format'] === 'closed_team'
                ? $this->applyClosedTeamLeaderDecision($gameId, (int) $decision['game_round_id'], (int) $decision['proposed_game_player_id'])
                : $this->applyTurnOrderDecision($gameId, (int) $decision['game_round_id'], (int) $decision['proposed_game_player_id']);
        });

        $this->touchLastMoveAt($gameId);
        $this->clearQueuedNotificationForGamePlayer($gameId, $actingGamePlayerId);

        return $result;
    }

    /**
     * Sets the round's own team_turn_1/2_game_player_id (whichever is
     * still unset) and, only for turn 1, immediately opens the SECOND
     * team's own turn_order decision too -- unlike every other format,
     * there's no single player to wait on a play/pass from in between
     * team 1's turn 1 and team 2's turn 2, so that decision has to exist
     * from the moment team 1's own choice resolves, not left for
     * advanceTeamTurn() to discover later.
     *
     * @return array{round_scored: bool, game_completed: bool}
     */
    private function applyTurnOrderDecision(int $gameId, int $roundId, int $chosenGamePlayerId): array
    {
        $pdo = Connection::get();
        $roundStmt = $pdo->prepare('SELECT * FROM game_rounds WHERE id = :id');
        $roundStmt->execute(['id' => $roundId]);
        $round = $roundStmt->fetch();

        $isFirstTurn = $round['team_turn_1_game_player_id'] === null;
        $column = $isFirstTurn ? 'team_turn_1_game_player_id' : 'team_turn_2_game_player_id';

        $state = $this->boardStates->load($gameId);

        // Team 2's own turn_order decision is opened immediately once team
        // 1's resolves (see below) -- independent of whether team 1's
        // chosen player has actually taken their turn yet, so team 2 can
        // answer early. If they DO answer early, team 1's player is still
        // the live current_turn_game_player_id and must stay that way;
        // only once the round has actually frozen waiting on THIS decision
        // (current_turn_game_player_id already NULL -- either because this
        // is team 1's own turn 1, which starts from a frozen round, or
        // because team 1 already finished and advanceTeamTurn() froze it)
        // does resolving it actually hand the turn to $chosenGamePlayerId.
        if ($round['current_turn_game_player_id'] === null) {
            // Hurt Feelings never applies in team format -- see php-app/README.md.
            $freshGrants = $this->computeFreshGrants($state, $chosenGamePlayerId, 1);
            $this->boardStates->save($gameId, $state);
            $this->updateRoundTurnState($roundId, $chosenGamePlayerId, $freshGrants, $state->discardedThisRound());
        }

        $pdo->prepare("UPDATE game_rounds SET {$column} = :chosen WHERE id = :round_id")
            ->execute(['chosen' => $chosenGamePlayerId, 'round_id' => $roundId]);

        $this->logEvent($gameId, $roundId, $chosenGamePlayerId, 'team_turn_order_decided', null, ['game_player_id' => $chosenGamePlayerId], $state);

        if ($isFirstTurn) {
            $teamIdByPlayer = $this->teamIdByGamePlayer($gameId);
            $firstTeamId = $teamIdByPlayer[$chosenGamePlayerId];
            $secondTeamId = $firstTeamId === 0 ? 1 : 0;
            $this->createTeamDecision($gameId, $roundId, $secondTeamId, 'turn_order', $this->teamMembers($gameId, $secondTeamId));
        }

        return ['round_scored' => false, 'game_completed' => false];
    }

    /**
     * 'closed_team's own counterpart to applyTurnOrderDecision() -- much
     * simpler, since this format only ever has ONE live "who goes first"
     * choice per round (not Open Team Play's two forced turn placements):
     * across-the-table seating (see seatOrderForClosedTeamGame()) already
     * means a plain clockwise rotation alternates between teams on its
     * own, so once the chosen leader is written straight into
     * game_rounds.first_game_player_id (rather than a team_turn_1/2
     * column -- 'closed_team' has no equivalent of those), the rest of the
     * round just falls through to advanceTurn()'s ordinary non-'team'
     * rotate() branch unchanged. Always unfreezes immediately (unlike
     * applyTurnOrderDecision(), there's no "did the other team already
     * answer early" case to check, since there's only one decision here)
     * and never opens a second decision.
     *
     * @return array{round_scored: bool, game_completed: bool}
     */
    private function applyClosedTeamLeaderDecision(int $gameId, int $roundId, int $chosenGamePlayerId): array
    {
        $state = $this->boardStates->load($gameId);

        // Hurt Feelings never applies in this format either (same as Open
        // Team Play -- see "Closed Team Play" in php-app/README.md), so
        // the base grant is always 1.
        $freshGrants = $this->computeFreshGrants($state, $chosenGamePlayerId, 1);
        $this->boardStates->save($gameId, $state);
        $this->updateRoundTurnState($roundId, $chosenGamePlayerId, $freshGrants, $state->discardedThisRound());

        Connection::get()->prepare('UPDATE game_rounds SET first_game_player_id = :chosen WHERE id = :round_id')
            ->execute(['chosen' => $chosenGamePlayerId, 'round_id' => $roundId]);

        $this->logEvent($gameId, $roundId, $chosenGamePlayerId, 'closed_team_leader_decided', null, ['game_player_id' => $chosenGamePlayerId], $state);

        return ['round_scored' => false, 'game_completed' => false];
    }

    /**
     * The losing team's chosen recipient actually draws the shared card,
     * then the next round (and its own turn_order decision, for whichever
     * team just won) gets created -- deferred until here, rather than
     * immediately when the previous round scored, so at most one
     * game_team_decisions row is ever open across the whole game at a
     * time. Mirrors the tail end of finishScoringAndAdvance()'s own
     * non-team "create the next round" logic.
     *
     * @return array{round_scored: bool, game_completed: bool}
     */
    private function applyDrawRecipientDecision(int $gameId, int $roundId, int $recipientGamePlayerId): array
    {
        $pdo = Connection::get();
        $roundStmt = $pdo->prepare('SELECT * FROM game_rounds WHERE id = :id');
        $roundStmt->execute(['id' => $roundId]);
        $round = $roundStmt->fetch();

        $state = $this->boardStates->load($gameId);
        $state->drawCard($recipientGamePlayerId);

        $winningTeamId = (int) $round['winner_team_id'];
        // Honor can still override who goes first, exactly as in every
        // other format -- see BoardState::firstPlayerOverride() -- just
        // resolved to a TEAM here (whichever team the override's own
        // player belongs to) rather than a specific seat, since that team
        // still gets its own turn_order choice for who actually takes the
        // first turn.
        $overridePlayerId = $state->firstPlayerOverride();
        $nextFirstTeamId = $overridePlayerId !== null
            ? $this->teamIdByGamePlayer($gameId)[$overridePlayerId]
            : $winningTeamId;

        $this->boardStates->save($gameId, $state);

        $this->logEvent($gameId, $roundId, $recipientGamePlayerId, 'team_draw_recipient_decided', null, ['game_player_id' => $recipientGamePlayerId], $state);

        $nextFirstPlayerId = $this->teamMembers($gameId, $nextFirstTeamId)[0];
        $insertRound = $pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
             VALUES (:game_id, :round_number, :first_player, NULL, 0, :pending_play_grants, 'in_progress')"
        );
        $insertRound->execute([
            'game_id' => $gameId,
            'round_number' => (int) $round['round_number'] + 1,
            'first_player' => $nextFirstPlayerId, // representative only -- its TEAM is what matters, see startGame()'s own comment
            'pending_play_grants' => json_encode([]),
        ]);
        $newRoundId = (int) $pdo->lastInsertId();

        $this->createTeamDecision($gameId, $newRoundId, $nextFirstTeamId, 'turn_order', $this->teamMembers($gameId, $nextFirstTeamId));

        return ['round_scored' => false, 'game_completed' => false];
    }

    /**
     * Opens a new 'propose'-phase team decision for $teamId in $roundId --
     * see "Open Team Play" in php-app/README.md and game_team_decisions'
     * own migration comment. $candidateGamePlayerIds is always that
     * team's own two members.
     *
     * @param int[] $candidateGamePlayerIds
     */
    private function createTeamDecision(int $gameId, int $roundId, int $teamId, string $decisionType, array $candidateGamePlayerIds): void
    {
        Connection::get()->prepare(
            'INSERT INTO game_team_decisions (game_id, game_round_id, team_id, decision_type, candidate_game_player_ids)
             VALUES (:game_id, :round_id, :team_id, :decision_type, :candidates)'
        )->execute([
            'game_id' => $gameId,
            'round_id' => $roundId,
            'team_id' => $teamId,
            'decision_type' => $decisionType,
            'candidates' => json_encode(array_values($candidateGamePlayerIds)),
        ]);

        // "It's your turn" push notification (issue #108) for every
        // candidate -- either one may propose, so all of them are
        // genuinely "awaiting" this the same way isAwaitingResponseFrom()
        // already treats it for the lobby's own hourglass icon.
        $this->notifyGamePlayersItsYourTurn($gameId, $candidateGamePlayerIds, "Game #{$gameId} needs your team's decision.", 'team-decision');
    }

    /**
     * The one still-open game_team_decisions row for this game, if any --
     * scoped to the whole game rather than just the current round, since a
     * 'draw_recipient' decision belongs to the round that JUST scored
     * (not the new round, if one has even been created yet -- see
     * applyDrawRecipientDecision()), while a 'turn_order' decision belongs
     * to whatever round is currently in progress. At most one is ever
     * open at a time by construction (see the active_marker unique index
     * in migration 0022), so "any match for this game_id" is unambiguous.
     *
     * @return ?array<string, mixed>
     */
    private function activeTeamDecision(int $gameId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM game_team_decisions WHERE game_id = :game_id AND resolved_at IS NULL LIMIT 1'
        );
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function totalWinsForTeam(int $gameId, int $teamId): int
    {
        $stmt = Connection::get()->prepare(
            "SELECT COALESCE(SUM(wins_awarded), 0) AS total FROM game_rounds
             WHERE game_id = :game_id AND status = 'scored' AND winner_team_id = :team_id"
        );
        $stmt->execute(['game_id' => $gameId, 'team_id' => $teamId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The shared tail of a fully-resolved play (whether that play just
     * completed in one request, or completed across a pending-decision
     * pause and response) -- saves $state's mutations, then either the
     * same player gets to play again (plays_remaining > 0) or the turn
     * passes on. Always saves first (rather than trusting each caller to
     * have already done so), since it's the one place both callers'
     * "fully resolved" paths converge. $actingGamePlayerId is whoever
     * actually made the play (the original initiator for a resumed
     * pending decision, not the responder who just answered it).
     *
     * $requestingGamePlayerId is whoever's own HTTP-level action this is --
     * playMood()'s own $gamePlayerId, or (unlike $actingGamePlayerId, which
     * stays the ORIGINAL player mid-Duplicity-chain/pending-decision)
     * respondToDecision()'s own responder, not $initiatingPlayerId. Only
     * ever used to skip THAT one player's own "game finished" push if this
     * exact call is what ends the game -- see
     * recordGameCompletionStats()'s own docblock on why.
     *
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int}
     */
    private function finishPlay(int $gameId, array $round, int $actingGamePlayerId, BoardState $state, int $requestingGamePlayerId): array
    {
        $this->boardStates->save($gameId, $state);

        if ($state->playsRemaining() > 0) {
            $this->updateRoundTurnState((int) $round['id'], $actingGamePlayerId, $state->pendingPlayGrants(), $state->discardedThisRound());

            return ['round_scored' => false, 'game_completed' => false];
        }

        return $this->advanceTurn($gameId, $round, $state, $requestingGamePlayerId);
    }

    /** @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int} */
    private function advanceTurn(int $gameId, array $round, BoardState $state, int $requestingGamePlayerId): array
    {
        if ($this->fetchGame($gameId)['format'] === 'team') {
            return $this->advanceTeamTurn($gameId, $round, $state, $requestingGamePlayerId);
        }

        // Positioned against the FULL (unfiltered) seat rotation, not just
        // the active players -- current_turn_game_player_id can itself be
        // a player who just resigned this exact call (see
        // skipTurnForResignedPlayer()), and they wouldn't be found by
        // array_search() against an already-filtered list. From that
        // position, scan forward (never wrapping -- this is one round's
        // single pass, not a repeating cycle) for the next still-active
        // player; a resigned player's own seat is simply skipped over,
        // which is what makes their future turns auto-skip. Reaching the
        // end of the round without finding one scores the round, using
        // only active players -- see turnOrderForRound()'s own docblock
        // for why a resigned player must never appear there either.
        $fullOrder = $this->rotate($this->seatOrder($gameId), (int) $round['first_game_player_id']);
        $activeIds = $this->activeGamePlayerIds($gameId);
        $currentIndex = array_search((int) $round['current_turn_game_player_id'], $fullOrder, true);

        $nextPlayerId = null;
        for ($i = $currentIndex + 1; $i < count($fullOrder); $i++) {
            if (in_array($fullOrder[$i], $activeIds, true)) {
                $nextPlayerId = $fullOrder[$i];
                break;
            }
        }

        if ($nextPlayerId === null) {
            $activeTurnOrder = array_values(array_filter($fullOrder, static fn (int $id): bool => in_array($id, $activeIds, true)));

            return $this->scoreRoundAndAdvance($gameId, $round, $activeTurnOrder, $state, $requestingGamePlayerId);
        }

        $hurtFeelingsHolder = $round['hurt_feelings_game_player_id'] !== null ? (int) $round['hurt_feelings_game_player_id'] : null;

        $freshGrants = $this->computeFreshGrants($state, $nextPlayerId, $nextPlayerId === $hurtFeelingsHolder ? 2 : 1);
        // computeFreshGrants() may consume a banked Generosity/Joy tag,
        // which has to be persisted even though this turn's own play
        // didn't otherwise touch the board.
        $this->boardStates->save($gameId, $state);
        $this->updateRoundTurnState((int) $round['id'], $nextPlayerId, $freshGrants, $state->discardedThisRound());

        return ['round_scored' => false, 'game_completed' => false];
    }

    /**
     * Team-format counterpart to the rest of advanceTurn() -- turn order
     * isn't a fixed seat rotation here (see "Open Team Play" in
     * php-app/README.md): turn 1 and turn 2 are each a team's own live
     * choice of which member goes (team_turn_1/2_game_player_id, set once
     * applyTurnOrderDecision() resolves that team's decision), and turns 3
     * and 4 are forced -- whichever teammate on each team HASN'T gone yet
     * this round, derived from team_id membership rather than stored
     * anywhere. Turn 1 finishing doesn't advance to a concrete next
     * player by itself: team 2's own turn_order decision was already
     * opened the moment team_turn_1 was set (see applyTurnOrderDecision()),
     * so this freezes the round (current_turn_game_player_id -> NULL)
     * until that resolves, exactly like any other outstanding decision
     * already blocks play/pass elsewhere in this file -- UNLESS team 2
     * already answered early (applyTurnOrderDecision() lets them answer
     * before team 1's player has actually played), in which case
     * team_turn_2_game_player_id is already known and this goes straight
     * to them instead of freezing on a decision that no longer exists to
     * unfreeze it.
     */
    private function advanceTeamTurn(int $gameId, array $round, BoardState $state, int $requestingGamePlayerId): array
    {
        $roundId = (int) $round['id'];
        $currentPlayerId = (int) $round['current_turn_game_player_id'];
        $turn1 = (int) $round['team_turn_1_game_player_id'];
        $turn2 = $round['team_turn_2_game_player_id'] !== null ? (int) $round['team_turn_2_game_player_id'] : null;

        if ($currentPlayerId === $turn1) {
            if ($turn2 === null) {
                $this->freezeRoundForTeamDecision($roundId);

                return ['round_scored' => false, 'game_completed' => false];
            }

            $this->unfreezeRoundForTeamPlayer($gameId, $roundId, $turn2, $state);

            return ['round_scored' => false, 'game_completed' => false];
        }

        $teamIdByPlayer = $this->teamIdByGamePlayer($gameId);
        $firstTeamId = $teamIdByPlayer[$turn1];
        $secondTeamId = $firstTeamId === 0 ? 1 : 0;
        $forcedTurn3PlayerId = $this->otherTeamMember($gameId, $firstTeamId, $turn1);

        if ($currentPlayerId === $turn2) {
            $nextPlayerId = $forcedTurn3PlayerId;
        } elseif ($currentPlayerId === $forcedTurn3PlayerId) {
            $nextPlayerId = $this->otherTeamMember($gameId, $secondTeamId, (int) $turn2);
        } else {
            // Turn 4 (team 2's forced remaining member) just finished --
            // score the round.
            return $this->scoreRoundAndAdvance($gameId, $round, $this->turnOrderForRound($gameId, $round), $state, $requestingGamePlayerId);
        }

        $this->unfreezeRoundForTeamPlayer($gameId, $roundId, $nextPlayerId, $state);

        return ['round_scored' => false, 'game_completed' => false];
    }

    /**
     * Hands the round's live turn to $playerId -- Hurt Feelings never
     * applies in team format (see php-app/README.md), so the base grant
     * is always 1. Shared by advanceTeamTurn()'s forced-turn transitions
     * and its own "team 2 already decided early" case above.
     */
    private function unfreezeRoundForTeamPlayer(int $gameId, int $roundId, int $playerId, BoardState $state): void
    {
        $freshGrants = $this->computeFreshGrants($state, $playerId, 1);
        $this->boardStates->save($gameId, $state);
        $this->updateRoundTurnState($roundId, $playerId, $freshGrants, $state->discardedThisRound());
    }

    /**
     * The order players took their turns in $round, earliest first --
     * either format's own version, since RoundScorer::winner()/
     * hurtFeelings() and nextUnresolvedScoringDecision() only care about
     * "an ordered list of this round's player ids," not how it was
     * produced. Team format's own order is [team_turn_1, team_turn_2, team
     * 1's forced remaining member, team 2's forced remaining member] --
     * see advanceTeamTurn(). A resigned player is dropped from this list
     * (relative order of everyone else preserved) -- team-format games
     * always complete a game immediately on any resignation (see
     * resignGame()), so this only ever actually removes anyone for
     * 'standard' format's own 3-4 player "game continues without them"
     * case, keeping a resigned player from ever being handed a turn or
     * credited a round/game win via winner()/hurtFeelings() below.
     *
     * @return int[]
     */
    private function turnOrderForRound(int $gameId, array $round): array
    {
        if ($this->fetchGame($gameId)['format'] !== 'team') {
            $fullOrder = $this->rotate($this->seatOrder($gameId), (int) $round['first_game_player_id']);
            $activeIds = $this->activeGamePlayerIds($gameId);

            return array_values(array_filter($fullOrder, static fn (int $id): bool => in_array($id, $activeIds, true)));
        }

        $turn1 = (int) $round['team_turn_1_game_player_id'];
        $turn2 = (int) $round['team_turn_2_game_player_id'];
        $teamIdByPlayer = $this->teamIdByGamePlayer($gameId);
        $firstTeamId = $teamIdByPlayer[$turn1];
        $secondTeamId = $firstTeamId === 0 ? 1 : 0;

        return [
            $turn1,
            $turn2,
            $this->otherTeamMember($gameId, $firstTeamId, $turn1),
            $this->otherTeamMember($gameId, $secondTeamId, $turn2),
        ];
    }

    private function freezeRoundForTeamDecision(int $roundId): void
    {
        Connection::get()->prepare(
            'UPDATE game_rounds SET current_turn_game_player_id = NULL, plays_remaining = 0, pending_play_grants = :grants WHERE id = :round_id'
        )->execute(['grants' => json_encode([]), 'round_id' => $roundId]);
    }

    /** @return int the OTHER of $teamId's two members (the one that isn't $knownMemberGamePlayerId) */
    private function otherTeamMember(int $gameId, int $teamId, int $knownMemberGamePlayerId): int
    {
        $members = $this->teamMembers($gameId, $teamId);

        return $members[0] === $knownMemberGamePlayerId ? $members[1] : $members[0];
    }

    /** @return int[] the two game_players.id belonging to $teamId, in seat order */
    private function teamMembers(int $gameId, int $teamId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id FROM game_players WHERE game_id = :game_id AND team_id = :team_id ORDER BY seat_order ASC'
        );
        $stmt->execute(['game_id' => $gameId, 'team_id' => $teamId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,int> game_player_id => team_id, for every seat in $gameId */
    private function teamIdByGamePlayer(int $gameId): array
    {
        $stmt = Connection::get()->prepare('SELECT id, team_id FROM game_players WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id']] = (int) $row['team_id'];
        }

        return $map;
    }

    /**
     * Lifetime game-level wins/losses (issue #106) -- called from every
     * code path that sets games.status = 'completed'
     * (completeGameByResignation(), the non-team and team round-scoring
     * completions). $winnerTeamId is null for every non-team format
     * (winner is just the one $winnerGamePlayerId seat); for 'team'/
     * 'closed_team' it credits every seat sharing that team_id, not just
     * $winnerGamePlayerId's own representative seat -- see
     * finishTeamScoringAndAdvance()'s own docblock on why that's only a
     * representative, never the authoritative record of who won.
     *
     * $excludeGamePlayerId is whoever's own move/response/resignation this
     * completion resulted from -- their lifetime stats are still bumped
     * like everyone else's, but they don't get a "game finished" push:
     * they're the one still looking at the board right now, watching the
     * game end in front of them, so a push notification about it would
     * just be telling them something they already know.
     *
     * Also the single hook point for issue #315's per-card win-rate
     * stats (CardStatsService::recordGameCompletion()) -- every path
     * that reaches game completion already funnels through here, so
     * that's the only place this needs to be called from, rather than
     * duplicating it at each of this method's own call sites.
     */
    private function recordGameCompletionStats(int $gameId, int $winnerGamePlayerId, ?int $winnerTeamId, ?int $excludeGamePlayerId = null): void
    {
        $stmt = Connection::get()->prepare('SELECT id, user_id, team_id FROM game_players WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);
        $players = $stmt->fetchAll();

        $excludeUserId = null;
        $winningUserIds = [];
        $losingUserIds = [];
        foreach ($players as $player) {
            if ($excludeGamePlayerId !== null && (int) $player['id'] === $excludeGamePlayerId) {
                $excludeUserId = (int) $player['user_id'];
            }

            $isWinner = $winnerTeamId !== null
                ? (int) $player['team_id'] === $winnerTeamId
                : (int) $player['id'] === $winnerGamePlayerId;
            if ($isWinner) {
                $winningUserIds[] = (int) $player['user_id'];
            } else {
                $losingUserIds[] = (int) $player['user_id'];
            }
        }

        $this->bumpLifetimeStats($winningUserIds, 'game_wins');
        $this->bumpLifetimeStats($losingUserIds, 'game_losses');
        $this->cardStats->recordGameCompletion($gameId, $winningUserIds, $losingUserIds);

        foreach ($winningUserIds as $userId) {
            if ($userId === $excludeUserId) {
                continue;
            }
            $this->notifications?->notifyGameFinished($userId, $gameId, "Game #{$gameId} is over -- you won!");
        }
        foreach ($losingUserIds as $userId) {
            if ($userId === $excludeUserId) {
                continue;
            }
            $this->notifications?->notifyGameFinished($userId, $gameId, "Game #{$gameId} is over -- you lost.");
        }
    }

    /**
     * Lifetime (best-of-three draft) match-level wins/losses (issue #106)
     * -- called from both places a draft match's own status becomes
     * 'completed': advanceDraftMatch() (the ordinary 2-0 finish) and
     * finalizeWinstonDraft()'s under-12-cards auto-loss branch, which
     * completes the match without game 1 ever completing (see the
     * migration's own comment on why that still counts here even though
     * recordGameCompletionStats() never ran for it).
     */
    private function recordMatchCompletionStats(int $draftMatchId, int $winnerUserId): void
    {
        $stmt = Connection::get()->prepare('SELECT user_id FROM draft_match_players WHERE draft_match_id = :match_id');
        $stmt->execute(['match_id' => $draftMatchId]);
        $userIds = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));

        $this->bumpLifetimeStats([$winnerUserId], 'match_wins');
        $this->bumpLifetimeStats(array_diff($userIds, [$winnerUserId]), 'match_losses');
    }

    /** @param string $column one of user_lifetime_stats' own counter columns -- never user input, so safe to interpolate directly */
    private function bumpLifetimeStats(array $userIds, string $column): void
    {
        if ($userIds === []) {
            return;
        }

        $stmt = Connection::get()->prepare(
            "INSERT INTO user_lifetime_stats (user_id, {$column}) VALUES (:user_id, 1)
             ON DUPLICATE KEY UPDATE {$column} = {$column} + 1"
        );
        foreach ($userIds as $userId) {
            $stmt->execute(['user_id' => $userId]);
        }
    }

    /** @return array{game_wins:int, game_losses:int, match_wins:int, match_losses:int} all-zero for a user with no row yet (nothing completed for them) */
    public function lifetimeStatsFor(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT game_wins, game_losses, match_wins, match_losses FROM user_lifetime_stats WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        $gameWins = $row !== false ? (int) $row['game_wins'] : 0;
        $gameLosses = $row !== false ? (int) $row['game_losses'] : 0;
        $matchWins = $row !== false ? (int) $row['match_wins'] : 0;
        $matchLosses = $row !== false ? (int) $row['match_losses'] : 0;

        return [
            'game_wins' => $gameWins,
            'game_losses' => $gameLosses,
            // Rounded to the nearest whole percent; null (rather than a
            // divide-by-zero 0%) until at least one game/match has
            // actually completed -- 0% would misleadingly read as "you've
            // lost every game" for a user who simply hasn't played yet.
            'game_win_percentage' => self::winPercentage($gameWins, $gameLosses),
            'match_wins' => $matchWins,
            'match_losses' => $matchLosses,
            'match_win_percentage' => self::winPercentage($matchWins, $matchLosses),
        ];
    }

    private static function winPercentage(int $wins, int $losses): ?int
    {
        $total = $wins + $losses;

        return $total > 0 ? (int) round($wins / $total * 100) : null;
    }

    /**
     * In-game notepad (issue #258): private per-seat scratch notes, never
     * shared with anyone else at the table -- keyed on $gamePlayerId
     * directly (the caller already resolved and authorized it, the same
     * `requireGamePlayer()` pattern every other per-seat action uses), not
     * $userId, since a seat already uniquely identifies "this player, in
     * this game." Empty string (not null) for a seat that's never saved
     * one yet, so the frontend's textarea always has a plain string to
     * bind to.
     */
    public function getNote(int $gamePlayerId): string
    {
        return $this->notes->findByGamePlayerId($gamePlayerId) ?? '';
    }

    /**
     * Only editable while the game is still 'in_progress' -- once it
     * reaches a terminal status ('completed'/'abandoned') the note stays
     * fully visible (see getNote() above) but read-only, the same as
     * every other in-progress-only board action.
     */
    public function saveNote(int $gameId, int $gamePlayerId, string $noteText): void
    {
        $game = $this->fetchGame($gameId);
        if ($game['status'] !== 'in_progress') {
            throw new GameStateException('Notes can only be edited while the game is in progress.');
        }

        if (mb_strlen($noteText) > self::MAX_NOTE_LENGTH) {
            throw new \InvalidArgumentException('A note cannot be longer than ' . self::MAX_NOTE_LENGTH . ' characters.');
        }

        $this->notes->upsert($gamePlayerId, $noteText);
    }

    /**
     * In-game chat (issue #109): appends one message to $gameId's chat,
     * visible either to the whole seated table ('table') or, for Open
     * Team Play only ('team' -- NOT isTeamFormat(), see below), to
     * $senderGamePlayerId's own teammate too ('team' channel) -- see
     * chatMessagesFor()'s own read-side filter for the other half of
     * this. Only while the game is 'in_progress', the same "editable
     * only in_progress, stays visible read-only after" rule saveNote()
     * enforces for notes -- "while playing" is this issue's own framing,
     * and a completed/abandoned game has no one left mid-conversation to
     * send to. Fires a best-effort notification (never blocking, see
     * NotificationService's own docblock) to every OTHER seat this
     * message is actually visible to -- never the sender, and never the
     * other team once $channel is 'team'.
     *
     * Deliberately checks `$game['format'] === 'team'` rather than the
     * shared isTeamFormat() predicate every OTHER team-format branch in
     * this file uses -- Closed Team Play's entire premise is that
     * information stays closed between teammates (see "Closed Team Play"
     * in php-app/README.md, point 4: getState() never even reveals a
     * closed_team partner's hand), so a private out-of-band channel for
     * them to coordinate would undercut the one thing that format is
     * actually testing. Open Team Play has no such restriction (partners
     * already see each other's hands via you.teammate_hand), so a
     * private channel there is just a convenience, not a rules
     * violation.
     */
    public function postChatMessage(int $gameId, int $senderGamePlayerId, string $channel, string $messageText): void
    {
        $game = $this->fetchGame($gameId);
        if ($game['status'] !== 'in_progress') {
            throw new GameStateException('Chat messages can only be sent while the game is in progress.');
        }

        $messageText = trim($messageText);
        if ($messageText === '') {
            throw new \InvalidArgumentException('A chat message cannot be empty.');
        }
        if (mb_strlen($messageText) > self::MAX_CHAT_MESSAGE_LENGTH) {
            throw new \InvalidArgumentException('A chat message cannot be longer than ' . self::MAX_CHAT_MESSAGE_LENGTH . ' characters.');
        }

        $senderTeamId = null;
        if ($channel === 'team') {
            if ($game['format'] !== 'team') {
                throw new GameStateException('This game has no team channel.');
            }
            $teamStmt = Connection::get()->prepare('SELECT team_id FROM game_players WHERE id = :id');
            $teamStmt->execute(['id' => $senderGamePlayerId]);
            $rawTeamId = $teamStmt->fetchColumn();
            $senderTeamId = $rawTeamId !== null ? (int) $rawTeamId : null;
            if ($senderTeamId === null) {
                throw new GameStateException('You are not on a team in this game.');
            }
        } elseif ($channel !== 'table') {
            throw new GameStateException('channel must be "table" or "team"');
        }

        $this->chat->insert($gameId, $senderGamePlayerId, $channel, $senderTeamId, $messageText);
        $this->notifyChatMessageRecipients($gameId, $senderGamePlayerId, $channel, $senderTeamId, $messageText);
    }

    private function notifyChatMessageRecipients(int $gameId, int $senderGamePlayerId, string $channel, ?int $senderTeamId, string $messageText): void
    {
        if ($this->notifications === null) {
            return;
        }

        $senderUsername = $this->playerUsernamesFor($gameId)[$senderGamePlayerId] ?? 'Someone';
        $preview = mb_strlen($messageText) > 100 ? mb_substr($messageText, 0, 100) . '...' : $messageText;

        $stmt = Connection::get()->prepare('SELECT id, user_id, team_id FROM game_players WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);
        foreach ($stmt->fetchAll() as $row) {
            $recipientGamePlayerId = (int) $row['id'];
            if ($recipientGamePlayerId === $senderGamePlayerId) {
                continue;
            }
            $recipientTeamId = $row['team_id'] !== null ? (int) $row['team_id'] : null;
            if ($channel === 'team' && $recipientTeamId !== $senderTeamId) {
                continue;
            }
            $this->notifications->notifyNewChatMessage((int) $row['user_id'], $gameId, $senderUsername, $preview);
        }
    }

    /**
     * Issue #109's own read side: every 'table'-channel message plus
     * every 'team'-channel one belonging to $viewerTeamId (see
     * GameChatRepository::messagesFor()'s own NULL-safety for the
     * non-team-format case), each with its sender's username resolved
     * via playerUsernamesFor() rather than a join. Called from
     * buildGameState() for every seated viewer on every poll -- chat
     * deliberately piggybacks on the existing GET /games/state poll
     * rather than its own polling endpoint (see this issue's own scope
     * notes), so this has no caching/pagination of its own: a typical
     * game's message count is small enough (short-lived, deleted with
     * the game after 7 days -- see bin/expire_and_delete_stale_games.php)
     * that returning the whole conversation every 4 seconds is simpler
     * than adding a delta-fetch mechanism this codebase has no precedent
     * for anywhere else.
     *
     * @return array<int, array{id:int, sender_game_player_id:int, sender_username:?string, channel:string, message_text:string, created_at:string}>
     */
    private function chatMessagesFor(int $gameId, ?int $viewerTeamId): array
    {
        $names = $this->playerUsernamesFor($gameId);

        return array_map(
            static fn (array $row): array => [
                ...$row,
                'sender_username' => $names[$row['sender_game_player_id']] ?? null,
            ],
            $this->chat->messagesFor($gameId, $viewerTeamId)
        );
    }

    /**
     * $state is the same instance the triggering play/pass already
     * loaded (and, if it was a play, already saved game_cards for) --
     * reused here rather than reloaded, since a round-wide flag like
     * Vulnerability's discardedThisRound only lives in memory on this
     * object until the writes below persist it, and reloading fresh would
     * silently lose whatever the round's very last play just set.
     *
     * Before computing a final score, checks whether any Enthusiasm/
     * Passion owner still needs to answer this round's scoring decision
     * (see RoundScorer's own docblock for why those two, unlike
     * Exhilaration/Bliss, can't just be applied automatically) -- if so,
     * this pauses the round exactly like a mid-play decision, one card at
     * a time, and finishScoringAndAdvance() only actually runs once
     * nextUnresolvedScoringDecision() finds nothing left to ask (either
     * because none was ever needed, or because respondToDecision() has
     * resumed here after the last one was answered).
     *
     * @param int[] $turnOrder the order players took their turns this round, earliest first
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int, pending_decision?: bool}
     */
    private function scoreRoundAndAdvance(int $gameId, array $round, array $turnOrder, BoardState $state, int $requestingGamePlayerId): array
    {
        $roundId = (int) $round['id'];

        if ($this->hasSkipScoringMarker($state)) {
            return $this->skipScoringAndAdvance($gameId, $round, $state);
        }

        $nextDecision = $this->nextUnresolvedScoringDecision($state, $roundId, $turnOrder);
        if ($nextDecision !== null) {
            $pdo = Connection::get();
            $pdo->beginTransaction();

            try {
                $this->boardStates->save($gameId, $state);
                $this->writeScoringDecisionBatch($gameId, $roundId, $state, $nextDecision);
                $this->logEvent($gameId, $roundId, $nextDecision['ownerId'], 'pending_decision_created', $nextDecision['cardId'], ['scoring_trigger' => true], $state);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => true];
        }

        $scoringDecisions = $this->resolvedScoringDecisionBonuses($state, $roundId);

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
            $result = $this->finishScoringAndAdvance($gameId, $round, $turnOrder, $state, $scoringDecisions, $requestingGamePlayerId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * The actual score/persist/advance logic -- extracted from
     * scoreRoundAndAdvance() so it can run either inside that method's own
     * transaction (the common case, no scoring decision needed) or inside
     * respondToDecision()'s already-open one (once the last outstanding
     * scoring decision resolves). The caller is responsible for the
     * transaction; this method never begins or commits one of its own.
     *
     * Also where an after-scoring ORDER decision (see
     * AFTER_SCORING_ORDER_DECISION_TYPE) gets a chance to pause the round a
     * second time, later than SCORING_DECISION_TYPES -- scores must
     * already be final (RoundScorer::score()/applyScoreSwaps() already
     * ran, right above) before it's even possible to know which cards'
     * conditional afterScoring tags will actually fire this round, and
     * therefore whether any player controls 2+ of them and needs to
     * choose an order. Crucially, this check runs BEFORE anything below it
     * writes to the database or calls boardStates->save() -- exactly
     * mirroring how scoreRoundAndAdvance() itself checks
     * nextUnresolvedScoringDecision() before ever calling this method at
     * all, just one phase later. That means every mutation $state has
     * picked up so far this call (applyScoreSwaps()'s own tag-clearing,
     * clearEndOfRoundSuppressions() below) is safely discarded, not
     * persisted, if this pauses -- $state gets reloaded fresh (and
     * $scores/$state re-derived identically, since both are pure functions
     * of already-resolved decisions) the next time this round advances,
     * whether that's another order decision or the real thing.
     *
     * @param int[] $turnOrder
     * @param array<int, int> $scoringDecisions cardId => resolved bonus, see RoundScorer::score()
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int, pending_decision?: bool}
     */
    private function finishScoringAndAdvance(int $gameId, array $round, array $turnOrder, BoardState $state, array $scoringDecisions, int $requestingGamePlayerId): array
    {
        $roundId = (int) $round['id'];
        $pdo = Connection::get();

        $scores = $this->applyScoreSwaps($state, $this->scorer->score($state, $scoringDecisions));

        // Repentance/Scorn's own 'end_of_round' suppression (as opposed to
        // Faith/Guilt/Meekness/Pacifism/Shame's 'while_source_in_play' kind)
        // expires at this exact boundary regardless of what's still in
        // play -- scored above using whatever was suppressed during the
        // round that just ended, then lifted here so it doesn't carry into
        // the round about to start. Shared by both the team- and non-team
        // paths below, since finishTeamScoringAndAdvance() reuses this same
        // $state.
        $state->clearEndOfRoundSuppressions();

        // A single source of truth for "who won" -- see its own docblock
        // for why the order-decision check below and whichever tail
        // actually runs must never derive this two different ways.
        $outcome = $this->determineRoundWinner($gameId, $round, $scores, $turnOrder);

        // Skip the pause entirely once this round is about to finish the
        // game -- see roundWouldCompleteGame()'s own docblock. Every
        // player's pending after-scoring cards still resolve, in
        // applyAfterScoringHooks() below, just always via its
        // no-decision-made default (ascending cardId) order.
        $nextOrderDecision = $this->roundWouldCompleteGame($gameId, $state, $outcome)
            ? null
            : $this->nextUnresolvedAfterScoringOrderDecision($state, $roundId, $turnOrder, $outcome['winningGamePlayerIds']);
        if ($nextOrderDecision !== null) {
            $this->writeAfterScoringOrderDecisionBatch($gameId, $roundId, $nextOrderDecision, $state);
            $this->logEvent($gameId, $roundId, $nextOrderDecision['ownerId'], 'pending_decision_created', $nextOrderDecision['groups'][0]['cardId'], ['after_scoring_order_trigger' => true], $state);

            return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => true];
        }

        if (self::isTeamFormat($this->fetchGame($gameId)['format'])) {
            return $this->finishTeamScoringAndAdvance($gameId, $round, $turnOrder, $state, $scores, $outcome, $requestingGamePlayerId);
        }

        // $scores covers every seated player (BoardState has no notion of
        // resignation), but $turnOrder is already active-players-only (see
        // turnOrderForRound()) -- narrowed to match before handing it to
        // hurtFeelings(), so a resigned player's own score can never make
        // them Hurt Feelings holder even if it happens to be the lowest in
        // $scores. $winnerId itself already reflects the same narrowing --
        // see determineRoundWinner().
        $activeScores = array_intersect_key($scores, array_flip($turnOrder));
        $winnerId = $outcome['winnerGamePlayerId'];
        $winsAwarded = $this->consumeExtraWinMarker($state);

        $insertScore = $pdo->prepare(
            'INSERT INTO game_round_scores (game_round_id, game_player_id, score) VALUES (:round_id, :player_id, :score)'
        );
        foreach ($scores as $playerId => $score) {
            $insertScore->execute(['round_id' => $roundId, 'player_id' => $playerId, 'score' => $score]);
        }

        $updateRound = $pdo->prepare(
            "UPDATE game_rounds SET status = 'scored', winner_game_player_id = :winner, wins_awarded = :wins_awarded, scored_at = NOW() WHERE id = :round_id"
        );
        $updateRound->execute(['winner' => $winnerId, 'wins_awarded' => $winsAwarded, 'round_id' => $roundId]);

        // Computed here, before losers draw, so a round that pushes the
        // winner to wins_needed can skip the draw entirely below -- no
        // player should draw a card off the round that just ended the
        // game (there's no next round for that card to matter in).
        $totalWins = $this->totalWinsFor($gameId, $winnerId);
        $winsNeeded = (int) $this->fetchGame($gameId)['wins_needed'];
        $gameCompleting = $totalWins >= $winsNeeded;

        if (!$gameCompleting) {
            // A resigned player is still in $scores (their board state is
            // untouched by resignation), but they're done playing -- skip
            // their draw the same way $turnOrder already excludes them
            // from winning.
            foreach ($turnOrder as $playerId) {
                if ($playerId !== $winnerId) {
                    $state->drawCard($playerId);
                }
            }
        }

        $resolvedOrders = $this->resolvedAfterScoringOrders($roundId);
        $this->applyAfterScoringHooks($state, [$winnerId], $turnOrder, $resolvedOrders);

        // Hurt Feelings only exists in games of 3 or more (active) players.
        $hurtFeelingsHolder = count($turnOrder) >= 3 ? $this->scorer->hurtFeelings($activeScores, $turnOrder) : null;

        // Honor (or Awe's own one-time version) overrides who goes first
        // next round regardless of who won -- see
        // BoardState::firstPlayerOverride(). Computed (and
        // computeFreshGrants() run) even if the game is about to
        // complete below and this ends up unused, so any banked grant
        // it consumes is captured by the same save() call either way.
        $firstPlayerOverride = $state->firstPlayerOverride();
        $nextFirstPlayer = $firstPlayerOverride ?? $winnerId;
        $nextRoundGrants = $this->computeFreshGrants($state, $nextFirstPlayer, $hurtFeelingsHolder === $nextFirstPlayer ? 2 : 1);
        $this->boardStates->save($gameId, $state);

        $this->logEvent($gameId, $roundId, null, 'round_scored', null, [
            'scores' => $scores,
            'winner_game_player_id' => $winnerId,
            'hurt_feelings_game_player_id' => $hurtFeelingsHolder,
            // Only worth calling out when it actually changes who goes
            // first (an override naming the round's own winner is a no-op,
            // same as no override at all) and there IS a next round to go
            // first in -- $nextFirstPlayer above is computed either way,
            // but goes unused once $gameCompleting ends the game outright.
            'first_player_override_game_player_id' => (!$gameCompleting && $firstPlayerOverride !== null && $firstPlayerOverride !== $winnerId)
                ? $firstPlayerOverride
                : null,
        ], $state);

        if ($gameCompleting) {
            $completeGame = $pdo->prepare(
                "UPDATE games SET status = 'completed', winner_game_player_id = :winner, completed_at = NOW() WHERE id = :game_id"
            );
            $completeGame->execute(['winner' => $winnerId, 'game_id' => $gameId]);
            $this->recordGameCompletionStats($gameId, $winnerId, null, $requestingGamePlayerId);

            // A no-op for every non-quick_draft game (games.draft_match_id
            // is only ever set for that deck_type) -- see its own docblock.
            $this->advanceDraftMatch($gameId, $winnerId);

            return ['round_scored' => true, 'game_completed' => true, 'winner_game_player_id' => $winnerId];
        }

        $insertRound = $pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, hurt_feelings_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
             VALUES (:game_id, :round_number, :first_player, :hurt_feelings, :first_player_turn, :plays_remaining, :pending_play_grants, 'in_progress')"
        );
        $insertRound->execute([
            'game_id' => $gameId,
            'round_number' => (int) $round['round_number'] + 1,
            'first_player' => $nextFirstPlayer,
            'hurt_feelings' => $hurtFeelingsHolder,
            'first_player_turn' => $nextFirstPlayer,
            'plays_remaining' => count($nextRoundGrants),
            'pending_play_grants' => json_encode($nextRoundGrants),
        ]);
        // Unlike an ordinary same-round turn advance (updateRoundTurnState(),
        // which this INSERT deliberately doesn't go through -- there's no
        // existing row to UPDATE yet), a brand new round setting a real
        // current_turn_game_player_id straight from creation is *also* a
        // "your turn" moment, and was missing its own notification entirely
        // until now -- the exact gap that made round-to-round handoffs go
        // silent even though same-round turn advances already worked.
        $this->notifyItsYourTurn((int) $pdo->lastInsertId(), $nextFirstPlayer);

        return ['round_scored' => true, 'game_completed' => false];
    }

    /**
     * Every draft-based deck_type's own shared best-of-three match
     * progression (Quick Draft, issue #88, and Winston Draft, issue #89),
     * run once a game that belongs to one (games.draft_match_id is
     * non-null) completes -- a no-op for every other game. Credits
     * $winnerGamePlayerId's own USER (draft_match_players is keyed by
     * user_id, not game_player_id -- see migration 0027's docblock) with
     * a match win; at DRAFT_GAMES_TO_WIN the match itself is done,
     * otherwise this creates the next game in the match (same 2 seats,
     * same format/deck_type/wins_needed, match_game_number + 1 -- the new
     * game's own deck_type is read from the game that just completed, not
     * hardcoded, so this works identically for either draft variant) and
     * resets the match to 'deck_building' so both players must sideboard
     * before it can start. Both players' deck_card_ids are explicitly
     * nulled out here -- without that, a leftover value from the game
     * that just finished would silently satisfy startGame()'s own "deck
     * submitted" gate for the new game, skipping the required sideboard
     * step entirely. Whatever deck_card_ids held right before that
     * null-out is copied to previous_deck_card_ids first, purely so
     * getState() can hand the frontend something to pre-select in the new
     * sideboard picker instead of defaulting back to every drafted card
     * -- it plays no part in the "deck submitted" gate itself, which
     * still only ever looks at deck_card_ids.
     */
    private function advanceDraftMatch(int $gameId, int $winnerGamePlayerId): void
    {
        $game = $this->fetchGame($gameId);
        if ($game['draft_match_id'] === null) {
            return;
        }
        $draftMatchId = (int) $game['draft_match_id'];
        $pdo = Connection::get();

        $winnerUserStmt = $pdo->prepare('SELECT user_id FROM game_players WHERE id = :id');
        $winnerUserStmt->execute(['id' => $winnerGamePlayerId]);
        $winnerUserId = (int) $winnerUserStmt->fetchColumn();

        $pdo->prepare(
            'UPDATE draft_match_players SET wins = wins + 1 WHERE draft_match_id = :match_id AND user_id = :user_id'
        )->execute(['match_id' => $draftMatchId, 'user_id' => $winnerUserId]);

        $winsStmt = $pdo->prepare(
            'SELECT wins FROM draft_match_players WHERE draft_match_id = :match_id AND user_id = :user_id'
        );
        $winsStmt->execute(['match_id' => $draftMatchId, 'user_id' => $winnerUserId]);
        $winnerMatchWins = (int) $winsStmt->fetchColumn();

        if ($winnerMatchWins >= $this->draftGamesToWin(count($this->draftMatchUserIds($draftMatchId)))) {
            $pdo->prepare(
                "UPDATE draft_matches SET status = 'completed', winner_user_id = :winner, completed_at = NOW() WHERE id = :id"
            )->execute(['winner' => $winnerUserId, 'id' => $draftMatchId]);
            $this->recordMatchCompletionStats($draftMatchId, $winnerUserId);

            return;
        }

        $seatStmt = $pdo->prepare(
            'SELECT user_id, seat_order FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC'
        );
        $seatStmt->execute(['game_id' => $gameId]);
        $seats = $seatStmt->fetchAll();

        $insertGame = $pdo->prepare(
            "INSERT INTO games (format, deck_type, draft_match_id, match_game_number, status, created_by_user_id, wins_needed, default_selections_mode)
             VALUES (:format, :deck_type, :draft_match_id, :match_game_number, 'waiting', :created_by, :wins_needed, :default_selections_mode)"
        );
        $insertGame->execute([
            'format' => $game['format'],
            'deck_type' => $game['deck_type'],
            'draft_match_id' => $draftMatchId,
            'match_game_number' => (int) $game['match_game_number'] + 1,
            'created_by' => (int) $game['created_by_user_id'],
            'wins_needed' => (int) $game['wins_needed'],
            // Carried over from the game that just finished, same as
            // format/deck_type/wins_needed above -- a best-of-three
            // match's own "default selections" setting is decided once
            // at the match's own creation (issue #274's per-game, not
            // per-user, decision), not re-chosen game to game.
            'default_selections_mode' => (int) $game['default_selections_mode'],
        ]);
        $nextGameId = (int) $pdo->lastInsertId();

        $insertPlayer = $pdo->prepare(
            'INSERT INTO game_players (game_id, user_id, seat_order) VALUES (:game_id, :user_id, :seat_order)'
        );
        foreach ($seats as $seat) {
            $insertPlayer->execute([
                'game_id' => $nextGameId,
                'user_id' => (int) $seat['user_id'],
                'seat_order' => (int) $seat['seat_order'],
            ]);
        }

        $pdo->prepare(
            'UPDATE draft_match_players SET previous_deck_card_ids = deck_card_ids, deck_card_ids = NULL
             WHERE draft_match_id = :match_id'
        )->execute(['match_id' => $draftMatchId]);

        $pdo->prepare("UPDATE draft_matches SET status = 'deck_building' WHERE id = :id")
            ->execute(['id' => $draftMatchId]);

        $seatUserIds = array_map(static fn (array $seat): int => (int) $seat['user_id'], $seats);
        $this->notifyDraftUsersItsYourTurn($nextGameId, $seatUserIds, "Game #{$nextGameId} needs your sideboard before the next game.", 'draft-deck');
    }

    /**
     * Team-format counterpart to the rest of finishScoringAndAdvance() --
     * $scores is already computed exactly like every other format
     * (Sneakiness's swap, Enthusiasm's/Passion's bonus, etc. all already
     * resolved by the caller before this runs); this just aggregates it
     * per team_id, decides the winning TEAM (ties go to whichever team
     * played first this round, per "Open Team Play" in
     * php-app/README.md), and replaces the individual "every non-winner
     * draws a card" rule with a single shared draw the losing team
     * decides who receives (see confirmTeamDecision()'s 'draw_recipient'
     * branch, which performs the actual draw once that's decided). Hurt
     * Feelings never applies in this format, so, unlike the non-team
     * path above, there's no hurt_feelings_game_player_id to compute or
     * carry into the next round.
     *
     * $turnOrder/$outcome are precomputed by the caller (finishScoringAndAdvance(),
     * via determineRoundWinner()) rather than re-derived here -- see that
     * method's own docblock for why the winner/winning-side computation
     * must be a single shared source of truth, not something this method
     * and the after-scoring-order-decision check above it could ever
     * derive two different ways.
     *
     * @param int[] $turnOrder
     * @param array<int,int> $scores game_player_id => score
     * @param array{winnerGamePlayerId:int, winnerTeamId:?int, winningGamePlayerIds:int[]} $outcome
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int, pending_decision?: bool}
     */
    private function finishTeamScoringAndAdvance(int $gameId, array $round, array $turnOrder, BoardState $state, array $scores, array $outcome, int $requestingGamePlayerId): array
    {
        $roundId = (int) $round['id'];
        $pdo = Connection::get();

        $winnerRepresentative = $outcome['winnerGamePlayerId'];
        $winningTeamId = $outcome['winnerTeamId'];
        $winningTeamMembers = $outcome['winningGamePlayerIds']; // seat_order ASC, see determineRoundWinner()
        $losingTeamId = $winningTeamId === 0 ? 1 : 0;

        $winsAwarded = $this->consumeExtraWinMarker($state);

        $insertScore = $pdo->prepare(
            'INSERT INTO game_round_scores (game_round_id, game_player_id, score) VALUES (:round_id, :player_id, :score)'
        );
        foreach ($scores as $playerId => $score) {
            $insertScore->execute(['round_id' => $roundId, 'player_id' => $playerId, 'score' => $score]);
        }

        $updateRound = $pdo->prepare(
            "UPDATE game_rounds SET status = 'scored', winner_game_player_id = :winner, winner_team_id = :winner_team, wins_awarded = :wins_awarded, scored_at = NOW() WHERE id = :round_id"
        );
        $updateRound->execute([
            'winner' => $winnerRepresentative,
            'winner_team' => $winningTeamId,
            'wins_awarded' => $winsAwarded,
            'round_id' => $roundId,
        ]);

        $totalWins = $this->totalWinsForTeam($gameId, $winningTeamId);
        $winsNeeded = (int) $this->fetchGame($gameId)['wins_needed'];
        $gameCompleting = $totalWins >= $winsNeeded;

        $resolvedOrders = $this->resolvedAfterScoringOrders($roundId);
        $this->applyAfterScoringHooks($state, $winningTeamMembers, $turnOrder, $resolvedOrders);
        $this->boardStates->save($gameId, $state);

        $this->logEvent($gameId, $roundId, null, 'round_scored', null, [
            'scores' => $scores,
            'winner_game_player_id' => $winnerRepresentative,
            'winner_team_id' => $winningTeamId,
        ], $state);

        if ($gameCompleting) {
            $completeGame = $pdo->prepare(
                "UPDATE games SET status = 'completed', winner_game_player_id = :winner, winner_team_id = :winner_team, completed_at = NOW() WHERE id = :game_id"
            );
            $completeGame->execute(['winner' => $winnerRepresentative, 'winner_team' => $winningTeamId, 'game_id' => $gameId]);
            $this->recordGameCompletionStats($gameId, $winnerRepresentative, $winningTeamId, $requestingGamePlayerId);

            return ['round_scored' => true, 'game_completed' => true, 'winner_game_player_id' => $winnerRepresentative];
        }

        // The losing team's single shared draw is a team decision, not an
        // automatic per-player draw -- confirmTeamDecision()'s
        // 'draw_recipient' branch actually draws the card AND creates the
        // next round (and its own turn_order decision) once resolved, so
        // only one game_team_decisions row is ever open at a time.
        $this->createTeamDecision($gameId, $roundId, $losingTeamId, 'draw_recipient', $this->teamMembers($gameId, $losingTeamId));

        return ['round_scored' => true, 'game_completed' => false, 'pending_decision' => true];
    }

    /**
     * The next Enthusiasm/Passion mood in play whose owner hasn't yet
     * answered this round's scoring decision for it, in turn order (then
     * by in-play iteration order for a player with more than one) -- or
     * null once every one has been asked. Recomputed fresh from live
     * board state plus whatever's already resolved in the database each
     * time, rather than persisted as an explicit queue, the same way the
     * mid-play Duplicity repeat chain recomputes its own remaining count
     * fresh at every step instead of storing it.
     *
     * @param int[] $turnOrder
     * @return ?array{cardId: int, ownerId: int, effectKey: string}
     */
    private function nextUnresolvedScoringDecision(BoardState $state, int $roundId, array $turnOrder): ?array
    {
        $alreadyResolved = $this->resolvedScoringCardIds($roundId);

        foreach ($turnOrder as $playerId) {
            foreach ($state->moodsOwnedBy($playerId) as $mood) {
                $effectKey = $state->catalogRow($state->effectiveCardId($mood->cardId))['effectKey'];
                if (in_array($effectKey, RoundScorer::DECISION_SCORE_MULTIPLYING_EFFECT_KEYS, true)
                    && !in_array($mood->cardId, $alreadyResolved, true)
                ) {
                    return ['cardId' => $mood->cardId, 'ownerId' => $playerId, 'effectKey' => $effectKey];
                }
            }
        }

        return null;
    }

    /** @return int[] card ids of every scoring decision already resolved this round */
    private function resolvedScoringCardIds(int $roundId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::SCORING_DECISION_TYPES), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT b.played_card_id FROM game_pending_decision_batches b
             JOIN game_pending_decisions d ON d.batch_id = b.id
             WHERE b.game_round_id = ? AND b.resolved_at IS NOT NULL AND d.decision_type IN ({$placeholders})"
        );
        $stmt->execute([$roundId, ...self::SCORING_DECISION_TYPES]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Every resolved Enthusiasm/Passion decision for this round,
     * translated from its raw stored answer into the actual bonus amount
     * RoundScorer::score() adds -- see resolveScoringDecisionBonus().
     *
     * @return array<int, int> cardId => resolved bonus amount
     */
    private function resolvedScoringDecisionBonuses(BoardState $state, int $roundId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::SCORING_DECISION_TYPES), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT b.played_card_id, d.decision_type, d.answer FROM game_pending_decision_batches b
             JOIN game_pending_decisions d ON d.batch_id = b.id
             WHERE b.game_round_id = ? AND b.resolved_at IS NOT NULL AND d.decision_type IN ({$placeholders})"
        );
        $stmt->execute([$roundId, ...self::SCORING_DECISION_TYPES]);

        $bonuses = [];
        foreach ($stmt->fetchAll() as $row) {
            $cardId = (int) $row['played_card_id'];
            $effectKey = $row['decision_type'] === self::ENTHUSIASM_DECISION_TYPE ? 'enthusiasm' : 'passion';
            $answer = new PlayerChoices((array) json_decode((string) $row['answer'], true));
            $bonuses[$cardId] = $this->resolveScoringDecisionBonus($state, $cardId, $effectKey, $answer);
        }

        return $bonuses;
    }

    /**
     * Translates one Enthusiasm/Passion decision's raw stored answer into
     * the bonus amount it resolves to. Enthusiasm's is a plain take-or-
     * decline: accepting always means the owner's own current highest
     * mood value (recomputed now, not whatever it was when the decision
     * was first asked, in case an earlier-resolved decision this same
     * round changed it -- though nothing currently in the pool can).
     * Passion's target_mood_id is validated defensively here (must still
     * be an in-play mood owned by someone else) even though the field's
     * own scope already excludes the owner's own moods from what a
     * well-behaved client would ever submit.
     */
    private function resolveScoringDecisionBonus(BoardState $state, int $cardId, string $effectKey, PlayerChoices $answer): int
    {
        if ($effectKey === 'enthusiasm') {
            return $answer->bool('take_bonus') ? $this->scorer->highestOwnMoodValue($state, $state->ownerOf($cardId)) : 0;
        }

        $targetMoodId = $answer->int('target_mood_id');
        if ($targetMoodId === null) {
            return 0;
        }
        if (!$state->isInPlay($targetMoodId) || $state->ownerOf($targetMoodId) === $state->ownerOf($cardId)) {
            throw new InvalidChoiceException("Card {$targetMoodId} is not a valid opponent mood for Passion to score");
        }

        return $state->valueOf($targetMoodId);
    }

    /**
     * @param array{cardId: int, ownerId: int, effectKey: string} $decision
     */
    private function writeScoringDecisionBatch(int $gameId, int $roundId, BoardState $state, array $decision): void
    {
        $request = $this->scoringDecisionRequest($state, $decision);
        // Reuses writePendingBatch()'s exact machinery (including its
        // race-condition protection, see migration 0011) by shaping this
        // as an ordinary PlayResult::pending() -- invocation_seq and the
        // two choices bags are meaningless for a scoring decision and
        // never read back (see respondToDecision()'s own scoring branch),
        // the same harmless-placeholder precedent Duplicity's repeat
        // offer already established.
        $result = PlayResult::pending([$request], $decision['cardId'], 0, new PlayerChoices([]));

        $this->writePendingBatch($gameId, $roundId, $decision['ownerId'], new PlayerChoices([]), new PlayerChoices([]), $result);
    }

    /**
     * @param array{cardId: int, ownerId: int, effectKey: string} $decision
     */
    private function scoringDecisionRequest(BoardState $state, array $decision): PendingDecisionRequest
    {
        if ($decision['effectKey'] === 'enthusiasm') {
            $template = CardChoiceSchema::reactionTemplate('enthusiasm');

            return new PendingDecisionRequest(
                key: $template['key'],
                targetPlayerId: $decision['ownerId'],
                decisionType: self::ENTHUSIASM_DECISION_TYPE,
                field: $template,
            );
        }

        $template = CardChoiceSchema::reactionTemplate('passion');

        return new PendingDecisionRequest(
            key: $template['key'],
            targetPlayerId: $decision['ownerId'],
            decisionType: self::PASSION_DECISION_TYPE,
            field: $template,
        );
    }

    /**
     * Sneakiness: "choose an opponent... after scoring, swap your score
     * with that player before determining who wins the round." Applied
     * right after RoundScorer::score() and before RoundScorer::winner(),
     * so the swap actually changes who wins.
     *
     * @param array<int, int> $scores game_player_id => score
     * @return array<int, int>
     */
    private function applyScoreSwaps(BoardState $state, array $scores): array
    {
        foreach ($state->moodsInPlay() as $mood) {
            $swapWithPlayerId = $state->effectState($mood->cardId, 'swapScoreWithPlayerId');
            if ($swapWithPlayerId === null) {
                continue;
            }

            $state->clearEffectState($mood->cardId, 'swapScoreWithPlayerId');
            $ownerId = $mood->ownerId;
            [$scores[$ownerId], $scores[$swapWithPlayerId]] = [$scores[$swapWithPlayerId], $scores[$ownerId]];
        }

        return $scores;
    }

    /**
     * Non-mutating peek at whether Corruption's "the winner of the
     * current round wins two rounds instead of one" marker is currently
     * set -- see consumeExtraWinMarker(), which clears it once actually
     * applied. Used by roundWouldCompleteGame() to predict this round's
     * win count without touching board state, since that prediction runs
     * before it's known whether this round will actually go on to be
     * scored via this same $state instance or a freshly-reloaded one on
     * a later request (see roundWouldCompleteGame()'s own docblock).
     */
    private function hasExtraWinMarker(BoardState $state): bool
    {
        foreach ($state->moodsInPlay() as $mood) {
            if ($state->effectState($mood->cardId, 'awardsExtraWin')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Corruption: "...or the winner of the current round wins two rounds
     * instead of one (each losing player still draws only one card)."
     * Doesn't matter who played Corruption or who ends up winning -- it's
     * unconditional on the round itself, unlike Bashfulness's
     * winner-dependent 'afterScoring' tag.
     */
    private function consumeExtraWinMarker(BoardState $state): int
    {
        if (!$this->hasExtraWinMarker($state)) {
            return 1;
        }

        foreach ($state->moodsInPlay() as $mood) {
            if ($state->effectState($mood->cardId, 'awardsExtraWin')) {
                $state->clearEffectState($mood->cardId, 'awardsExtraWin');
            }
        }

        return 2;
    }

    /**
     * Predicts, before any of this round's own rows are written, whether
     * scoring it is about to complete the game -- used only to decide
     * whether the after-scoring order decision (AFTER_SCORING_ORDER_DECISION_TYPE,
     * see its own docblock) is worth pausing to ask at all. With no next
     * round for a chosen order to ever matter to, forcing every player
     * through that choice on the game's final round would just be a
     * stall on a decision whose only visible effect is which of several
     * equally-legal default resolutions (see applyAfterScoringHooks()'s
     * own ascending-cardId fallback) a completed game's final board state
     * ends up showing. Reuses the exact same totalWinsFor()/
     * totalWinsForTeam() + wins_needed comparison finishScoringAndAdvance()/
     * finishTeamScoringAndAdvance() make for real further down -- safe to
     * duplicate here since nothing between this call and that one writes
     * to game_rounds or touches the Corruption marker, so the two are
     * guaranteed to agree.
     */
    private function roundWouldCompleteGame(int $gameId, BoardState $state, array $outcome): bool
    {
        $winsNeeded = (int) $this->fetchGame($gameId)['wins_needed'];
        $predictedWinsAwarded = $this->hasExtraWinMarker($state) ? 2 : 1;

        $totalWins = $outcome['winnerTeamId'] !== null
            ? $this->totalWinsForTeam($gameId, $outcome['winnerTeamId'])
            : $this->totalWinsFor($gameId, $outcome['winnerGamePlayerId']);

        return $totalWins + $predictedWinsAwarded >= $winsNeeded;
    }

    /**
     * Resolves every mood's one-shot "after scoring" tag -- 'afterScoring'
     * (Bashfulness; Gluttony/Insecurity via MoodPlayService's
     * onUseEffectState; Recklessness's own "while in play" ability) and
     * 'returnsToOwnerAfterScoring' (Betrayal; the mood Recklessness took) --
     * then clears each tag so it doesn't reapply next round.
     *
     * A rules-committee ruling on the Recklessness/Betrayal interaction
     * spells out the full general framework this now implements: "after
     * scoring" effects resolve player by player, in the order they went
     * this round (see $turnOrder); if a player's OWN cards carry two or
     * more such effects at once, THEY choose the order those resolve in.
     * "A player's own cards" means every card whose particular
     * after-scoring effect currently belongs to them -- see
     * pendingAfterScoringGroups() for exactly how that's determined (a
     * self-tag belongs to whoever currently controls the tagged card
     * itself; a foreign 'returnsToOwnerAfterScoring' tag belongs to
     * whoever currently controls the *source* card that placed it --
     * Betrayal, or the Recklessness that stole this mood in the first
     * place -- since that's whose printed ability it actually is,
     * regardless of who's currently holding the affected card). $groups
     * is precomputed once, up front, from $state as of the moment
     * everyone's finished answering (nothing between "the last order
     * decision resolves" and this call can move a card, so re-deriving it
     * fresh per player would only waste work, not change the answer).
     * $resolvedOrders (see resolvedAfterScoringOrders()) supplies, for
     * whichever players actually had a real choice to make, the exact
     * cardId order they chose; every other player's own pending cards are
     * processed in their only-ever-shown default (ascending cardId) order,
     * same as before a choice existed at all -- see
     * nextUnresolvedAfterScoringOrderDecision(), which only pauses for a
     * decision in the first place once a player has 2+ pending cards.
     *
     * Within one physical card that itself carries BOTH tags at once (only
     * possible when that card's own self-tag and a foreign tag placed ON
     * it both currently belong to the SAME player -- e.g. Recklessness
     * took a mood that already had its own after-scoring tag, or --
     * trickier -- Recklessness ITSELF got given away via Betrayal and then
     * stolen back again before scoring) the self-tag still always resolves
     * first, unconditionally, never as part of the player's own order
     * choice: it's one card's own compound ability, not two separate
     * cards competing for a turn slot, and printed card text always frames
     * "after scoring" effects as belonging to whoever currently controls
     * that card when this resolves -- Recklessness's own bottom-and-draw
     * is unconditional ("while in play"), so it always fires first; only
     * once that's settled does a foreign "give it back ... if it's still
     * in play" get a chance to run, and by then the mood may already be
     * gone. isInPlay() is checked immediately before giveInPlayToPlayer()
     * for exactly that qualifier.
     *
     * $winningGamePlayerIds is every player whose "you won this round"
     * condition should read true -- a single-element array for every
     * non-team format (just the round's own winner), or a team-format
     * round's whole winning team (both members), since a card like
     * Bashfulness asking "did you win?" means "did your TEAM win" once
     * scores are shared -- see "Open Team Play" in php-app/README.md.
     *
     * @param int[] $winningGamePlayerIds
     * @param int[] $turnOrder
     * @param array<int, int[]> $resolvedOrders game_player_id => their chosen cardId order, see resolvedAfterScoringOrders()
     */
    private function applyAfterScoringHooks(BoardState $state, array $winningGamePlayerIds, array $turnOrder, array $resolvedOrders): void
    {
        $groups = $this->pendingAfterScoringGroups($state, $winningGamePlayerIds);

        foreach ($turnOrder as $playerId) {
            $playerGroups = $groups[$playerId] ?? [];
            if ($playerGroups === []) {
                continue;
            }

            if (isset($resolvedOrders[$playerId])) {
                $byCardId = [];
                foreach ($playerGroups as $group) {
                    $byCardId[$group['cardId']] = $group;
                }
                $ordered = [];
                foreach ($resolvedOrders[$playerId] as $cardId) {
                    if (isset($byCardId[$cardId])) {
                        $ordered[] = $byCardId[$cardId];
                        unset($byCardId[$cardId]);
                    }
                }
                // Anything left over (never happens for a validly-stored
                // answer -- see respondToDecision()'s own permutation
                // check) is appended in the original default order, so a
                // stale/partial answer still resolves every pending card
                // rather than silently dropping one.
                $playerGroups = [...$ordered, ...array_values($byCardId)];
            }

            foreach ($playerGroups as $group) {
                $cardId = $group['cardId'];

                if ($group['self']) {
                    $afterScoring = $state->effectState($cardId, 'afterScoring');
                    if ($afterScoring !== null) {
                        $state->clearEffectState($cardId, 'afterScoring');
                        $ownerId = $state->ownerOf($cardId);
                        match ($afterScoring['action']) {
                            'discard' => $state->moveInPlayToDiscard($cardId),
                            'return_to_hand' => $state->moveInPlayToHand($cardId),
                            'bottom_and_draw' => $this->bottomOfDeckAndDraw($state, $cardId, $ownerId),
                            default => throw new GameStateException("Unknown afterScoring action '{$afterScoring['action']}'"),
                        };
                    }
                }

                if ($group['foreign']) {
                    $returnsToOwner = $state->effectState($cardId, 'returnsToOwnerAfterScoring');
                    if ($returnsToOwner !== null) {
                        $state->clearEffectState($cardId, 'returnsToOwnerAfterScoring');
                        if ($state->isInPlay($cardId)) {
                            $state->giveInPlayToPlayer($cardId, $returnsToOwner['ownerId']);
                        }
                    }
                }
            }
        }
    }

    /**
     * Every mood in play whose 'afterScoring' self-tag will actually fire
     * this round (condition already known, since scores/winner are final
     * by the time this is ever called) and/or whose
     * 'returnsToOwnerAfterScoring' foreign tag is present, grouped by
     * whichever player that specific effect currently belongs to -- see
     * applyAfterScoringHooks()'s own docblock for exactly what "belongs
     * to" means for each kind. A single cardId can appear in TWO different
     * players' own lists at once (e.g. Recklessness given away via
     * Betrayal: its own self-tag now belongs to whoever holds Recklessness,
     * but Betrayal's foreign tag on that same card still belongs to
     * whoever holds Betrayal) -- exactly the case the ruling calls "in
     * your example different players have each card," even though here
     * it's the same card. Every controller's own list is sorted by
     * ascending cardId for a deterministic default order (shown to the
     * player as the starting point for their own choice, and used as-is
     * for anyone who never had one to make).
     *
     * @param int[] $winningGamePlayerIds
     * @return array<int, list<array{cardId: int, self: bool, foreign: bool}>> game_player_id => their pending cards
     */
    private function pendingAfterScoringGroups(BoardState $state, array $winningGamePlayerIds): array
    {
        $byController = [];

        foreach ($state->moodsInPlay() as $mood) {
            $cardId = $mood->cardId;

            $afterScoring = $state->effectState($cardId, 'afterScoring');
            if ($afterScoring !== null) {
                $conditionMet = ($afterScoring['condition'] ?? 'always') === 'always' || in_array($mood->ownerId, $winningGamePlayerIds, true);
                if ($conditionMet) {
                    $byController[$mood->ownerId][$cardId]['cardId'] = $cardId;
                    $byController[$mood->ownerId][$cardId]['self'] = true;
                }
            }

            $returnsToOwner = $state->effectState($cardId, 'returnsToOwnerAfterScoring');
            if ($returnsToOwner !== null) {
                $sourceCardId = $returnsToOwner['sourceCardId'];
                // Betrayal/Recklessness (the only two sources of this tag)
                // are always still in play in practice -- but if some
                // future card ever moved one of them out from under an
                // outstanding tag, falling back to the tag's own
                // recorded destination keeps this from ever throwing.
                $controllerId = $state->isInPlay($sourceCardId) ? $state->ownerOf($sourceCardId) : $returnsToOwner['ownerId'];
                $byController[$controllerId][$cardId]['cardId'] = $cardId;
                $byController[$controllerId][$cardId]['foreign'] = true;
            }
        }

        $groups = [];
        foreach ($byController as $controllerId => $byCardId) {
            ksort($byCardId);
            $groups[$controllerId] = array_map(
                static fn (array $g): array => ['cardId' => $g['cardId'], 'self' => $g['self'] ?? false, 'foreign' => $g['foreign'] ?? false],
                array_values($byCardId)
            );
        }

        return $groups;
    }

    /**
     * The next player, in turn order, who currently controls 2+ pending
     * after-scoring cards (see pendingAfterScoringGroups()) and hasn't yet
     * answered this round's own order decision -- or null once everyone
     * who needed one has. Mirrors nextUnresolvedScoringDecision()'s own
     * "recompute fresh each time, don't persist a queue" shape.
     *
     * @param int[] $turnOrder
     * @param int[] $winningGamePlayerIds
     * @return ?array{ownerId: int, groups: list<array{cardId: int, self: bool, foreign: bool}>}
     */
    private function nextUnresolvedAfterScoringOrderDecision(BoardState $state, int $roundId, array $turnOrder, array $winningGamePlayerIds): ?array
    {
        $groups = $this->pendingAfterScoringGroups($state, $winningGamePlayerIds);
        $alreadyAnswered = $this->resolvedAfterScoringOrderPlayerIds($roundId);

        foreach ($turnOrder as $playerId) {
            $playerGroups = $groups[$playerId] ?? [];
            if (count($playerGroups) < 2 || in_array($playerId, $alreadyAnswered, true)) {
                continue;
            }

            return ['ownerId' => $playerId, 'groups' => $playerGroups];
        }

        return null;
    }

    /** @return int[] game_player_ids who've already answered an AFTER_SCORING_ORDER_DECISION_TYPE decision this round */
    private function resolvedAfterScoringOrderPlayerIds(int $roundId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT DISTINCT d.target_game_player_id FROM game_pending_decision_batches b
             JOIN game_pending_decisions d ON d.batch_id = b.id
             WHERE b.game_round_id = :round_id AND b.resolved_at IS NOT NULL AND d.decision_type = :decision_type'
        );
        $stmt->execute(['round_id' => $roundId, 'decision_type' => self::AFTER_SCORING_ORDER_DECISION_TYPE]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Every resolved after-scoring order decision for this round,
     * translated from its raw stored answer -- see
     * writeAfterScoringOrderDecisionBatch() for the field shape --
     * straight into the cardId order applyAfterScoringHooks() actually
     * applies.
     *
     * @return array<int, int[]> game_player_id => their chosen cardId order
     */
    private function resolvedAfterScoringOrders(int $roundId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT d.target_game_player_id, d.answer FROM game_pending_decision_batches b
             JOIN game_pending_decisions d ON d.batch_id = b.id
             WHERE b.game_round_id = :round_id AND b.resolved_at IS NOT NULL AND d.decision_type = :decision_type'
        );
        $stmt->execute(['round_id' => $roundId, 'decision_type' => self::AFTER_SCORING_ORDER_DECISION_TYPE]);

        $orders = [];
        foreach ($stmt->fetchAll() as $row) {
            $answer = (array) json_decode((string) $row['answer'], true);
            $orders[(int) $row['target_game_player_id']] = array_map(intval(...), (array) ($answer['ordered_card_ids'] ?? []));
        }

        return $orders;
    }

    /**
     * One-line summary of what a pending after-scoring effect on $group's
     * cardId actually does, shown next to its name in the order-decision
     * field so the player doesn't have to already know every card's rules
     * text to make an informed choice -- e.g. "Discards after scoring."
     * for a self afterScoring tag, or "Returns to Alice after scoring
     * (taken via Betrayal)." for a foreign returnsToOwnerAfterScoring
     * tag, naming whichever card actually created it (Betrayal, or the
     * Recklessness that stole this mood in the first place -- see
     * pendingAfterScoringGroups()). A single cardId can carry both at
     * once (Recklessness given away via Betrayal), in which case both
     * sentences are shown together.
     *
     * @param array{cardId: int, self: bool, foreign: bool} $group
     * @param array<int, string> $cardNames
     * @param array<int, string> $playerNames
     */
    private function afterScoringEffectDescription(BoardState $state, array $group, array $cardNames, array $playerNames): string
    {
        $sentences = [];

        if ($group['self']) {
            $afterScoring = $state->effectState($group['cardId'], 'afterScoring');
            $sentences[] = match ($afterScoring['action'] ?? null) {
                'discard' => 'Discards after scoring.',
                'return_to_hand' => 'Returns to your hand after scoring.',
                'bottom_and_draw' => 'Goes to the bottom of your deck and you draw a new card after scoring.',
                default => 'Resolves its own after-scoring effect.',
            };
        }

        if ($group['foreign']) {
            $returnsToOwner = $state->effectState($group['cardId'], 'returnsToOwnerAfterScoring');
            if ($returnsToOwner !== null) {
                $ownerName = $playerNames[$returnsToOwner['ownerId']] ?? 'its original owner';
                $sourceName = $cardNames[$returnsToOwner['sourceCardId']] ?? 'another card';
                $sentences[] = "Returns to {$ownerName} after scoring (taken via {$sourceName}).";
            }
        }

        return implode(' ', $sentences);
    }

    /**
     * @param array{ownerId: int, groups: list<array{cardId: int, self: bool, foreign: bool}>} $decision
     */
    private function writeAfterScoringOrderDecisionBatch(int $gameId, int $roundId, array $decision, BoardState $state): void
    {
        $cardNames = $this->cardNamesFor($gameId);
        $playerNames = $this->playerUsernamesFor($gameId);
        $cards = array_map(
            fn (array $group): array => [
                'card_id' => $group['cardId'],
                'name' => $cardNames[$group['cardId']] ?? 'Unknown',
                'description' => $this->afterScoringEffectDescription($state, $group, $cardNames, $playerNames),
            ],
            $decision['groups']
        );

        // No CardChoiceSchema::reactionTemplate() entry backs this one --
        // unlike Enthusiasm's/Passion's own scoring decisions, it's not a
        // printed reaction on any one card; it's an engine-level question
        // ("in what order do YOUR OWN several after-scoring effects
        // resolve") that only exists once 2+ of a single player's own
        // cards are pending at once. 'cards' carries the exact pending set
        // the field is describing, in the same default order shown as a
        // starting point (see pendingAfterScoringGroups()); the answer is
        // that same set of ids, resubmitted in the player's chosen order.
        $field = [
            'key' => 'ordered_card_ids',
            'type' => 'card_order',
            'label' => 'Choose the order your after-scoring effects resolve',
            'required' => true,
            'cards' => $cards,
        ];

        $request = new PendingDecisionRequest(
            key: $field['key'],
            targetPlayerId: $decision['ownerId'],
            decisionType: self::AFTER_SCORING_ORDER_DECISION_TYPE,
            field: $field,
        );

        // Reuses writePendingBatch()'s exact machinery (see
        // writeScoringDecisionBatch()'s own comment on the same pattern) --
        // played_card_id needs *some* real in-play card for its own FK, so
        // this just names the first (default-order) pending card; nothing
        // downstream ever reads it back as anything more specific than
        // "which decision is this."
        $result = PlayResult::pending([$request], $decision['groups'][0]['cardId'], 0, new PlayerChoices([]));

        $this->writePendingBatch($gameId, $roundId, $decision['ownerId'], new PlayerChoices([]), new PlayerChoices([]), $result);
    }

    /**
     * The single source of truth for "who won this round" -- computed
     * without writing anything to the database, so it can run (and must
     * give the exact same answer) from two different places: the
     * after-scoring-order-decision check in finishScoringAndAdvance()
     * (deciding whether the round needs to pause before it's even
     * persisted) and, moments later once no more decisions are needed,
     * the real score/winner persistence that same method (and
     * finishTeamScoringAndAdvance()) performs. If those two ever computed
     * "did you win" two different ways, a conditional afterScoring tag
     * like Bashfulness's could be asked about in the check but resolve
     * differently once actually applied -- or vice versa.
     *
     * @param array<int,int> $scores game_player_id => score, already through applyScoreSwaps()
     * @param int[] $turnOrder
     * @return array{winnerGamePlayerId:int, winnerTeamId:?int, winningGamePlayerIds:int[]}
     */
    private function determineRoundWinner(int $gameId, array $round, array $scores, array $turnOrder): array
    {
        if (self::isTeamFormat($this->fetchGame($gameId)['format'])) {
            $teamIdByPlayer = $this->teamIdByGamePlayer($gameId);
            $teamScores = [0 => 0, 1 => 0];
            foreach ($scores as $playerId => $score) {
                $teamScores[$teamIdByPlayer[$playerId]] += $score;
            }

            $firstTeamId = $teamIdByPlayer[(int) $round['first_game_player_id']];
            $otherTeamId = $firstTeamId === 0 ? 1 : 0;
            // Ties go to whoever played first this round.
            $winningTeamId = $teamScores[$firstTeamId] >= $teamScores[$otherTeamId] ? $firstTeamId : $otherTeamId;

            $winningTeamMembers = $this->teamMembers($gameId, $winningTeamId); // seat_order ASC
            // A representative teammate for winner_game_player_id's own FK/
            // display purposes only -- whoever scored higher individually,
            // ties by lower seat_order (winningTeamMembers[0] is already
            // the lower-seat_order member, so a strict > below already
            // keeps it on a tie). Never used for team win-counting -- see
            // totalWinsForTeam(), which reads winner_team_id instead.
            $winnerRepresentative = $winningTeamMembers[0];
            foreach ($winningTeamMembers as $playerId) {
                if ($scores[$playerId] > $scores[$winnerRepresentative]) {
                    $winnerRepresentative = $playerId;
                }
            }

            return [
                'winnerGamePlayerId' => $winnerRepresentative,
                'winnerTeamId' => $winningTeamId,
                'winningGamePlayerIds' => $winningTeamMembers,
            ];
        }

        // $scores covers every seated player (BoardState has no notion of
        // resignation), but $turnOrder is already active-players-only (see
        // turnOrderForRound()) -- narrowed to match before handing it to
        // winner(), so a resigned player's own score can never make them
        // the round's winner even if it happens to be the highest in
        // $scores.
        $activeScores = array_intersect_key($scores, array_flip($turnOrder));
        $winnerId = $this->scorer->winner($activeScores, $turnOrder);

        return ['winnerGamePlayerId' => $winnerId, 'winnerTeamId' => null, 'winningGamePlayerIds' => [$winnerId]];
    }

    private function bottomOfDeckAndDraw(BoardState $state, int $cardId, int $ownerId): void
    {
        $state->moveInPlayToBottomOfDeck($cardId);
        $state->drawCard($ownerId);
    }

    private function hasSkipScoringMarker(BoardState $state): bool
    {
        foreach ($state->moodsInPlay() as $mood) {
            if ($state->effectState($mood->cardId, 'skipScoringThisRound')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Computes the play grants for the start of $playerId's turn: $baseCount
     * unconditional grants (1, or 2 with Hurt Feelings), plus whatever
     * perpetual or banked grants their board currently entitles them to --
     * Hope's unconditional extra play, Grace's discard-sourced
     * color-matching one, Stubbornness's conditional one (only if another
     * player currently has more moods in play), and one grant per
     * still-outstanding Generosity/Joy 'banksExtraPlayForPlayerId' tag
     * targeting this player, cleared here since each only ever covers a
     * single turn. Hope/Grace's *same*-turn bonus (for the turn either
     * card is actually played) is granted separately, in MoodPlayService,
     * since it isn't tied to a turn boundary at all.
     *
     * Every one of these four carries 'sourceCardId' (via
     * effectiveSourceCardIds(), which follows a Creativity copy back to
     * whatever it's actually copying -- e.g. a Creativity currently
     * copying Hope resolves to Hope's own instance id here, the same way
     * serializeCard() already shows that Creativity as "Hope" everywhere
     * else) so describePlayGrant() can name the actual source instead of
     * folding it into the base allowance's own "Your normal turn" -- a
     * bare null grant is reserved *only* for the player's first,
     * ordinary play. $baseCount's own second entry (Hurt Feelings, when
     * $baseCount is 2) carries 'sourceLabel' => 'Hurt Feelings' instead,
     * for the same reason -- see $playGrants' own docblock.
     * Hope/Grace/Stubbornness each contribute one grant *per* qualifying
     * mood, not just one overall -- two independent Hopes (a duplicate
     * printed card across two decks in a duel game, or an intentionally
     * duplicate-including custom deck) each carry their own extra play,
     * exactly like two separately-played Hopes already do via
     * MoodPlayService's own same-turn grant.
     *
     * Hope's and Grace's own grants (but not Stubbornness's) also carry
     * 'requiresSourceInPlay' => true -- if that specific Hope/Grace is
     * removed from play later in the turn it's granted for, before the
     * player actually uses the play it granted, the grant is lost, not
     * merely un-attributed (see BoardState::grantIsActive()). Stubbornness
     * grants a play "at the start of your turn" outright, with nothing in
     * its own text tying the grant's survival to its own continued
     * presence the way Hope's/Grace's "while in play" phrasing does, so
     * once granted, it persists for that turn regardless of what happens
     * to Stubbornness afterward.
     *
     * @return array<int, ?array{type?: string, values?: int[], source?: string, sourceCardId?: int, sourceLabel?: string, requiresSourceInPlay?: bool}>
     */
    private function computeFreshGrants(BoardState $state, int $playerId, int $baseCount): array
    {
        // $baseCount is always 1, or 2 when $playerId holds Hurt Feelings
        // this round -- see BoardState::startTurn()'s identical shape for
        // why the second entry is tagged 'sourceLabel' rather than left a
        // second bare null.
        $grants = $baseCount > 1 ? [null, ['sourceLabel' => 'Hurt Feelings']] : [null];

        foreach ($this->effectiveSourceCardIds($state, $playerId, 'hope') as $hopeSourceCardId) {
            $grants[] = ['sourceCardId' => $hopeSourceCardId, 'requiresSourceInPlay' => true];
        }

        foreach ($this->effectiveSourceCardIds($state, $playerId, 'grace') as $graceSourceCardId) {
            $grants[] = [
                'type' => 'shares_color_with_your_moods',
                'source' => 'discard',
                'sourceCardId' => $graceSourceCardId,
                'requiresSourceInPlay' => true,
            ];
        }

        if ($this->anotherPlayerHasMoreMoods($state, $playerId)) {
            foreach ($this->effectiveSourceCardIds($state, $playerId, 'stubbornness') as $stubbornnessSourceCardId) {
                $grants[] = ['sourceCardId' => $stubbornnessSourceCardId];
            }
        }

        foreach ($state->moodsInPlay() as $mood) {
            if ($state->effectState($mood->cardId, 'banksExtraPlayForPlayerId') === $playerId) {
                $state->clearEffectState($mood->cardId, 'banksExtraPlayForPlayerId');
                $grants[] = ['sourceCardId' => $state->effectiveCardId($mood->cardId)];
            }
        }

        return $grants;
    }

    /**
     * The instance id of every one of $playerId's own in-play moods whose
     * EFFECTIVE (copy-aware) effect key is $effectKey -- the same
     * effectiveCardId()-per-mood scan
     * BoardState::countMoodsInPlayWithEffectiveKey() does, except this
     * returns the resolved ids themselves (for a play grant's own
     * 'sourceCardId') rather than just a count. Two Creativities both
     * copying the same real Hope resolve to that Hope's own instance id
     * twice here (not deduplicated) -- correct, since each Creativity is
     * its own physical card in play and so grants its own bonus play, the
     * same as two independent real Hopes would.
     *
     * @return int[]
     */
    private function effectiveSourceCardIds(BoardState $state, int $playerId, string $effectKey): array
    {
        $ids = [];
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            $effectiveCardId = $state->effectiveCardId($mood->cardId);
            if ($state->catalogRow($effectiveCardId)['effectKey'] === $effectKey) {
                $ids[] = $effectiveCardId;
            }
        }

        return $ids;
    }

    private function anotherPlayerHasMoreMoods(BoardState $state, int $playerId): bool
    {
        $myCount = count($state->moodsOwnedBy($playerId));
        foreach ($state->playerOrder() as $otherId) {
            if ($otherId !== $playerId && count($state->moodsOwnedBy($otherId)) > $myCount) {
                return true;
            }
        }

        return false;
    }

    /**
     * Awe: "there is no scoring this round. No one wins or loses this
     * round... You choose which player goes first next round." No scores
     * are recorded, no one draws a card, there's no Hurt Feelings, and win
     * totals are untouched -- the round is simply marked scored with no
     * winner and play moves on. Awe's 'oneTimeFirstPlayerOverride'
     * effectState key (see BoardState::firstPlayerOverride()) picks who
     * goes first; unlike Honor's perpetual override, it's explicitly
     * cleared here alongside skipScoringThisRound once consumed, since
     * Awe's choice only covers this one transition.
     *
     * @return array{round_scored: bool, game_completed: bool}
     */
    private function skipScoringAndAdvance(int $gameId, array $round, BoardState $state): array
    {
        $roundId = (int) $round['id'];

        $nextFirstPlayer = $state->firstPlayerOverride()
            ?? throw new GameStateException("Round {$roundId} was marked to skip scoring but no player was chosen to go first next round");

        foreach ($state->moodsInPlay() as $mood) {
            if ($state->effectState($mood->cardId, 'skipScoringThisRound')) {
                $state->clearEffectState($mood->cardId, 'skipScoringThisRound');
                $state->clearEffectState($mood->cardId, 'oneTimeFirstPlayerOverride');
            }
        }

        // This round boundary is skipping scoring, not skipping the round
        // itself -- Repentance/Scorn's own 'end_of_round' suppression still
        // needs lifting here exactly like the normal finishScoringAndAdvance()
        // path does, or it would otherwise carry into the round Awe just
        // started.
        $state->clearEndOfRoundSuppressions();

        $isTeamFormat = self::isTeamFormat($this->fetchGame($gameId)['format']);
        // Either team format's own turn_order decision still needs
        // computeFreshGrants() to run once the chosen player is actually
        // known -- see applyTurnOrderDecision()/applyClosedTeamLeaderDecision()
        // -- so this skips it here rather than guessing for a player who
        // isn't necessarily who Awe's own choice resolves to at the team
        // level.
        $nextRoundGrants = $isTeamFormat ? [] : $this->computeFreshGrants($state, $nextFirstPlayer, 1);

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
            $updateRound = $pdo->prepare(
                "UPDATE game_rounds SET status = 'scored', scored_at = NOW() WHERE id = :round_id"
            );
            $updateRound->execute(['round_id' => $roundId]);

            $this->boardStates->save($gameId, $state);

            $this->logEvent($gameId, $roundId, null, 'round_scored', null, [
                'skipped' => true,
                'first_player_override_game_player_id' => $nextFirstPlayer,
            ], $state);

            if ($isTeamFormat) {
                // Awe's own player picked $nextFirstPlayer directly, but
                // team format still needs that player's own TEAM to make
                // its own live turn_order choice -- see "Open Team Play"
                // in php-app/README.md -- rather than trusting Awe's pick
                // as the literal next actor.
                $insertRound = $pdo->prepare(
                    "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
                     VALUES (:game_id, :round_number, :first_player, NULL, 0, :pending_play_grants, 'in_progress')"
                );
                $insertRound->execute([
                    'game_id' => $gameId,
                    'round_number' => (int) $round['round_number'] + 1,
                    'first_player' => $nextFirstPlayer,
                    'pending_play_grants' => json_encode([]),
                ]);
                $newRoundId = (int) $pdo->lastInsertId();

                $nextFirstTeamId = $this->teamIdByGamePlayer($gameId)[$nextFirstPlayer];
                $this->createTeamDecision($gameId, $newRoundId, $nextFirstTeamId, 'turn_order', $this->teamMembers($gameId, $nextFirstTeamId));
            } else {
                $insertRound = $pdo->prepare(
                    "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
                     VALUES (:game_id, :round_number, :first_player, :first_player_turn, :plays_remaining, :pending_play_grants, 'in_progress')"
                );
                $insertRound->execute([
                    'game_id' => $gameId,
                    'round_number' => (int) $round['round_number'] + 1,
                    'first_player' => $nextFirstPlayer,
                    'first_player_turn' => $nextFirstPlayer,
                    'plays_remaining' => count($nextRoundGrants),
                    'pending_play_grants' => json_encode($nextRoundGrants),
                ]);
                // Same gap as finishScoringAndAdvance()'s own "create the
                // next round" INSERT -- this is Awe's own skip-scoring
                // equivalent of it, and was missing the same notification.
                $this->notifyItsYourTurn((int) $pdo->lastInsertId(), $nextFirstPlayer);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['round_scored' => true, 'game_completed' => false];
    }

    public function gamePlayerIdFor(int $gameId, int $userId): ?int
    {
        $stmt = Connection::get()->prepare(
            'SELECT id FROM game_players WHERE game_id = :game_id AND user_id = :user_id'
        );
        $stmt->execute(['game_id' => $gameId, 'user_id' => $userId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** @return int[] every seated player's user_id, in seat order */
    public function seatedUserIdsFor(int $gameId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT user_id FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC'
        );
        $stmt->execute(['game_id' => $gameId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Spectator mode (issue #128)'s own share code -- null until a seated
     * player has ever asked for one (see getOrCreateSpectateCode()).
     */
    public function spectateCodeFor(int $gameId): ?string
    {
        $stmt = Connection::get()->prepare('SELECT spectate_code FROM games WHERE id = :game_id');
        $stmt->execute(['game_id' => $gameId]);
        $code = $stmt->fetchColumn();

        return $code !== false && $code !== null ? (string) $code : null;
    }

    /**
     * Get-or-create $gameId's own spectate_code (issue #128) -- lazily
     * generated the first time any seated player asks (see
     * "Spectator mode" in php-app/README.md for why this isn't
     * pre-populated for every game). Callers are responsible for their
     * own authorization (matching this codebase's existing convention --
     * see requireGamePlayer() in index.php -- of checking seat membership
     * once at the route boundary rather than re-deriving it inside
     * GameService for every action).
     *
     * A casually-shareable code, not a secret bearer credential (see the
     * migration's own comment), so a short, directly-stored
     * bin2hex(random_bytes(4)) (8 hex chars) is plenty -- the retry loop
     * below only exists for the vanishingly unlikely case two different
     * games collide on the same 8-char code, not as a security measure.
     */
    public function getOrCreateSpectateCode(int $gameId): string
    {
        $existing = $this->spectateCodeFor($gameId);
        if ($existing !== null) {
            return $existing;
        }

        $pdo = Connection::get();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = bin2hex(random_bytes(4));
            try {
                $stmt = $pdo->prepare(
                    'UPDATE games SET spectate_code = :code WHERE id = :game_id AND spectate_code IS NULL'
                );
                $stmt->execute(['code' => $code, 'game_id' => $gameId]);

                return $this->spectateCodeFor($gameId) ?? $code;
            } catch (PDOException $e) {
                // Unique-constraint collision on spectate_code -- retry
                // with a freshly generated code. Anything else is a real
                // problem, not a collision to shrug off.
                if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw $e;
                }
            }
        }

        throw new GameStateException("Could not generate a unique spectate code for game {$gameId} after several attempts");
    }

    /**
     * Resolves a spectate code (issue #128) to the game_id it belongs to,
     * for the code-entry form on the new "Spectate" page. No
     * friendship/seat check here at all -- the whole point of a shared
     * code is that a stranger who merely holds it may use it (still
     * requires being logged in, though -- see requireAuth() at this
     * route). Only ever succeeds for a game that can actually be
     * spectated right now -- see getSpectatorState()'s own identical
     * 'waiting'/'abandoned' exclusion.
     */
    public function resolveSpectateCode(string $code): int
    {
        $stmt = Connection::get()->prepare('SELECT id, status FROM games WHERE spectate_code = :code');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new GameStateException('No game found for that spectate code.');
        }
        if ($row['status'] === 'waiting' || $row['status'] === 'abandoned') {
            throw new GameStateException("Game {$row['id']} can't be spectated right now.");
        }

        return (int) $row['id'];
    }

    /**
     * @return array<int, array{id:int,format:string,deck_type:string,status:string,wins_needed:int,created_at:string,started_at:?string,last_move_at:?string,completed_at:?string,players:array<int,array{user_id:int,username:string,seat_order:int}>,is_your_turn:bool,is_awaiting_your_response:bool,current_turn_username:?string,awaiting_response_usernames:array<int,string>,winner_usernames:array<int,string>,draft_match_id:?int,match_game_number:?int,draft_match:?array{status:string,your_wins:int,opponent_wins:int,games_to_win:int,winner_username:?string}}>
     */
    public function listGamesForUser(int $userId): array
    {
        $pdo = Connection::get();

        // A 'completed' OR 'abandoned' game moves to listPastGamesForUser()
        // below (issue #99/#84's own "past games" split, widened to cover
        // 'abandoned' too) -- EXCEPT one still belonging to a Quick/
        // Winston/Grid Draft match that isn't itself fully decided yet
        // (draft_matches.status only ever reaches 'completed' once a
        // winner is set -- see draftMatchSummaryFor()'s own docblock),
        // since a finished game 1 of an in-progress best-of-three match is
        // still very much part of what's currently being played, not
        // history. In practice this carve-out never actually applies to an
        // 'abandoned' game -- resignFromDraftMatch()/abandonDraftMatch()/
        // finalizeWinstonDraft() always flip draft_matches.status to
        // 'completed' in the very same statement that marks the game
        // 'abandoned' (a mid-drafting or zero-survivor resignation always
        // decides the whole match, one way or another) -- so an abandoned
        // game moves to Past games immediately, same turn as the
        // resignation itself, never waiting on a sibling game the way a
        // naturally-completed one sometimes does. Only 'waiting'/
        // 'in_progress' games are ever affected by this at all.
        //
        // Waiting/in-progress games always sort above completed/abandoned
        // ones, regardless of recency -- a finished game is never more
        // actionable than an active one, no matter how old the active one
        // is. Within each of those two tiers, most-recently-active first
        // (last_move_at, falling back to started_at, then created_at for a
        // game nothing has happened in yet).
        $gameIdsStmt = $pdo->prepare(
            "SELECT g.id FROM games g
             JOIN game_players gp ON gp.game_id = g.id
             LEFT JOIN draft_matches dm ON dm.id = g.draft_match_id
             WHERE gp.user_id = :user_id
               AND (g.status NOT IN ('completed', 'abandoned') OR (g.draft_match_id IS NOT NULL AND dm.status != 'completed'))
             ORDER BY
                 (g.status IN ('waiting', 'in_progress')) DESC,
                 COALESCE(g.last_move_at, g.started_at, g.created_at) DESC,
                 g.id DESC"
        );
        $gameIdsStmt->execute(['user_id' => $userId]);
        $gameIds = array_map(intval(...), $gameIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        return array_map(fn (int $gameId) => $this->gameSummaryFor($gameId, $userId), $gameIds);
    }

    /**
     * The complement of listGamesForUser() above: every 'completed' or
     * 'abandoned' game NOT still tied to an in-progress draft match -- see
     * that method's own docblock for exactly where the line falls (and
     * why an 'abandoned' game never actually hits that carve-out in
     * practice). Sorted most-recently-completed first, the natural order
     * for a "past games" archive (as opposed to listGamesForUser()'s own
     * actionability-first ordering, which has no reason to apply once
     * nothing here is actionable at all). An 'abandoned' game never gets
     * its own completed_at set (only the draft_matches row it belonged to
     * does -- see abandonDraftMatch()), so it naturally sorts by
     * last_move_at instead, same as it would anywhere else in the app.
     *
     * @return array<int, array{id:int,format:string,deck_type:string,status:string,wins_needed:int,created_at:string,started_at:?string,last_move_at:?string,completed_at:?string,players:array<int,array{user_id:int,username:string,seat_order:int}>,is_your_turn:bool,is_awaiting_your_response:bool,current_turn_username:?string,awaiting_response_usernames:array<int,string>,winner_usernames:array<int,string>,draft_match_id:?int,match_game_number:?int,draft_match:?array{status:string,your_wins:int,opponent_wins:int,games_to_win:int,winner_username:?string}}>
     */
    public function listPastGamesForUser(int $userId): array
    {
        $pdo = Connection::get();

        $gameIdsStmt = $pdo->prepare(
            "SELECT g.id FROM games g
             JOIN game_players gp ON gp.game_id = g.id
             LEFT JOIN draft_matches dm ON dm.id = g.draft_match_id
             WHERE gp.user_id = :user_id
               AND g.status IN ('completed', 'abandoned')
               AND (g.draft_match_id IS NULL OR dm.status = 'completed')
             ORDER BY COALESCE(g.completed_at, g.last_move_at, g.started_at, g.created_at) DESC, g.id DESC"
        );
        $gameIdsStmt->execute(['user_id' => $userId]);
        $gameIds = array_map(intval(...), $gameIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        return array_map(fn (int $gameId) => $this->gameSummaryFor($gameId, $userId), $gameIds);
    }

    /**
     * Issue #84's cleanup cron, first pass -- see
     * bin/expire_and_delete_stale_games.php. Permanently deletes every
     * 'completed' game whose last activity (COALESCE(last_move_at,
     * started_at, created_at) -- the same staleness definition
     * listGamesForUser()'s own sort and expireStaleActiveGames() below
     * both use) is older than $olderThanDays: an outcome that's already
     * settled and hasn't been looked at in that long has nothing left
     * worth keeping around. `DELETE FROM games` cascades to every other
     * per-game table (game_players/game_rounds/game_round_scores/
     * game_cards/game_events/game_pending_decision_batches/
     * game_team_decisions/game_initial_card_passes/game_notes -- all
     * ON DELETE CASCADE from games, or transitively via game_players/
     * game_rounds/game_pending_decision_batches -- see
     * database/README.md), so no manual per-table cleanup is needed for
     * any of those. A Quick/Winston/Grid Draft match (draft_matches) has
     * no FK pointing back to games, so it's never touched by that
     * cascade -- once none of a match's own games are left referencing
     * it (either deleted here, or genuinely never created), that match
     * row (and its draft_match_players/draft_round_picks/
     * draft_pile_stage_picks/draft_winston_state/draft_grid_state, all
     * ON DELETE CASCADE from draft_matches) is deleted too, so a match
     * doesn't outlive every game that was ever part of it.
     *
     * @return int how many games were deleted
     */
    public function deleteStaleCompletedGames(int $olderThanDays = 7): int
    {
        $pdo = Connection::get();

        $idsStmt = $pdo->prepare(
            "SELECT id FROM games
             WHERE status = 'completed'
               AND COALESCE(last_move_at, started_at, created_at) < (NOW() - INTERVAL :days DAY)"
        );
        $idsStmt->execute(['days' => $olderThanDays]);
        $gameIds = array_map(intval(...), $idsStmt->fetchAll(PDO::FETCH_COLUMN));

        if ($gameIds === []) {
            return 0;
        }

        $deleteStmt = $pdo->prepare('DELETE FROM games WHERE id = :id');
        foreach ($gameIds as $gameId) {
            $deleteStmt->execute(['id' => $gameId]);
        }

        $pdo->exec(
            'DELETE dm FROM draft_matches dm
             LEFT JOIN games g ON g.draft_match_id = dm.id
             WHERE g.id IS NULL'
        );

        return count($gameIds);
    }

    /**
     * Issue #84's cleanup cron, second pass -- see
     * bin/expire_and_delete_stale_games.php and
     * deleteStaleCompletedGames() above for the first. Any game NOT
     * already 'completed' (so 'waiting'/'in_progress'/'abandoned') whose
     * last activity is also older than $olderThanDays gets force-ended
     * instead of deleted outright -- unlike the already-settled games
     * above, there's no existing outcome to just discard, so the record
     * is kept (logged with a 'game_expired' event and no winner --
     * describeEvent()'s own new case below renders that plainly, and
     * gameSummaryFor()'s winner_usernames/the board's own "Game over"
     * line already tolerate a completed game with no winner cleanly) --
     * it'll be picked up and deleted by deleteStaleCompletedGames() on
     * some future run once IT has been stale for long enough in turn.
     * Deliberately does NOT try to gracefully wind down whatever mid-round
     * state the game was frozen in (an open game_rounds row, a pending
     * decision, cards still sitting in hand/in_play) the way
     * resignGame() does for a player-initiated resignation -- nobody is
     * coming back to finish a game this stale, so there's nothing left to
     * reconcile, just a status flip.
     *
     * @return int how many games were expired
     */
    public function expireStaleActiveGames(int $olderThanDays = 7): int
    {
        $pdo = Connection::get();

        $idsStmt = $pdo->prepare(
            "SELECT id FROM games
             WHERE status != 'completed'
               AND COALESCE(last_move_at, started_at, created_at) < (NOW() - INTERVAL :days DAY)"
        );
        $idsStmt->execute(['days' => $olderThanDays]);
        $gameIds = array_map(intval(...), $idsStmt->fetchAll(PDO::FETCH_COLUMN));

        $expiredCount = 0;
        foreach ($gameIds as $gameId) {
            $expired = $this->withGameLock($gameId, function () use ($gameId, $olderThanDays): bool {
                // Re-checked inside the lock in case a player's own
                // action raced with this cron between the SELECT above
                // and here -- astronomically unlikely at a 7-day-plus
                // staleness threshold, but cheap to guard against
                // terminating a game that just became active again.
                $staleCheckStmt = Connection::get()->prepare(
                    "SELECT 1 FROM games WHERE id = :id AND status != 'completed'
                     AND COALESCE(last_move_at, started_at, created_at) < (NOW() - INTERVAL :days DAY)"
                );
                $staleCheckStmt->execute(['id' => $gameId, 'days' => $olderThanDays]);
                if ($staleCheckStmt->fetchColumn() === false) {
                    return false;
                }

                $this->logEvent($gameId, null, null, 'game_expired', null, []);
                Connection::get()->prepare(
                    "UPDATE games SET status = 'completed', completed_at = NOW() WHERE id = :id"
                )->execute(['id' => $gameId]);

                return true;
            });

            if ($expired) {
                $expiredCount++;
            }
        }

        return $expiredCount;
    }

    /**
     * Spectator mode (issue #128): every currently-'in_progress' game any
     * of $friendUserIds is seated in, that $viewerUserId is NOT seated in
     * -- the lobby's own games (listGamesForUser() above) never appear
     * here, by construction. Deliberately narrower than
     * listGamesForUser(): 'waiting' games are excluded outright (nothing
     * dealt yet to spectate -- see getSpectatorState()'s own identical
     * boundary), and 'completed' ones too (the issue's own "games any of
     * your friends are currently in" wording, plus a completed game is
     * always still reachable by its spectate_code for anyone who wants to
     * review it -- see resolveSpectateCode()). Hydrated via the same
     * gameSummaryFor() every lobby row uses, but with a null viewer (see
     * that method's own docblock for why a real, non-participant viewer
     * id would actually be worse here, not just redundant).
     *
     * @param int[] $friendUserIds
     * @return array<int, array<string, mixed>>
     */
    public function listFriendsInProgressGames(int $viewerUserId, array $friendUserIds): array
    {
        if ($friendUserIds === []) {
            return [];
        }

        $pdo = Connection::get();
        $placeholders = implode(',', array_fill(0, count($friendUserIds), '?'));
        $gameIdsStmt = $pdo->prepare(
            "SELECT DISTINCT g.id FROM games g
             JOIN game_players gp ON gp.game_id = g.id
             WHERE gp.user_id IN ({$placeholders})
               AND g.status = 'in_progress'
               AND g.id NOT IN (SELECT game_id FROM game_players WHERE user_id = ?)
             ORDER BY g.last_move_at DESC, g.id DESC"
        );
        $gameIdsStmt->execute([...$friendUserIds, $viewerUserId]);
        $gameIds = array_map(intval(...), $gameIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        return array_map(fn (int $gameId) => $this->gameSummaryFor($gameId, null), $gameIds);
    }

    /**
     * listGamesForUser()'s/listFriendsInProgressGames()'s shared per-game
     * hydration. $viewerUserId null means "no personalized view at all,"
     * not just "a viewer who happens not to be seated" -- every
     * $viewerUserId-dependent field (is_your_turn,
     * is_awaiting_your_response, and, most importantly,
     * draftMatchSummaryFor()'s own your_wins/opponent_wins, which would
     * otherwise misattribute a real-but-non-participant viewer as one of
     * the match's own two players) simply stays at its unpersonalized
     * default instead. Every other field here (usernames, status,
     * winner_usernames, ...) is already public regardless of viewer.
     *
     * @return array<string, mixed>
     */
    private function gameSummaryFor(int $gameId, ?int $viewerUserId): array
    {
        $pdo = Connection::get();
        $game = $this->fetchGame($gameId);

        $playersStmt = $pdo->prepare(
            'SELECT gp.id, gp.user_id, gp.seat_order, gp.team_id, u.username FROM game_players gp
             JOIN users u ON u.id = gp.user_id
             WHERE gp.game_id = :game_id ORDER BY gp.seat_order ASC'
        );
        $playersStmt->execute(['game_id' => $gameId]);
        $playerRows = $playersStmt->fetchAll();

        $yourGamePlayerId = null;
        $players = [];
        foreach ($playerRows as $row) {
            if ($viewerUserId !== null && (int) $row['user_id'] === $viewerUserId) {
                $yourGamePlayerId = (int) $row['id'];
            }
            $players[] = [
                'user_id' => (int) $row['user_id'],
                'username' => $row['username'],
                'seat_order' => (int) $row['seat_order'],
            ];
        }

        // Same "credit the whole winning team" logic as getState()'s own
        // 'winner_usernames' -- both teammates' usernames for a
        // team-format win, just the one player's otherwise. Empty until
        // the game actually completes (winner_team_id/winner_game_player_id
        // are both still null).
        $winnerUsernames = [];
        if ($game['winner_team_id'] !== null) {
            foreach ($playerRows as $row) {
                if ($row['team_id'] !== null && (int) $row['team_id'] === (int) $game['winner_team_id']) {
                    $winnerUsernames[] = $row['username'];
                }
            }
        } elseif ($game['winner_game_player_id'] !== null) {
            foreach ($playerRows as $row) {
                if ((int) $row['id'] === (int) $game['winner_game_player_id']) {
                    $winnerUsernames[] = $row['username'];
                }
            }
        }

        $draftMatchId = $game['draft_match_id'] !== null ? (int) $game['draft_match_id'] : null;

        $currentTurnGamePlayerId = null;
        $awaitingYourResponse = false;
        $currentTurnUsername = null;
        $awaitingResponseUsernames = [];
        if ($game['status'] === 'in_progress') {
            $roundStmt = $pdo->prepare(
                "SELECT current_turn_game_player_id FROM game_rounds
                 WHERE game_id = :game_id AND status = 'in_progress'
                 ORDER BY round_number DESC LIMIT 1"
            );
            $roundStmt->execute(['game_id' => $gameId]);
            $currentTurnGamePlayerId = $roundStmt->fetchColumn();
            $currentTurnGamePlayerId = $currentTurnGamePlayerId !== false ? (int) $currentTurnGamePlayerId : null;

            // One isAwaitingResponseFrom() check per seated player (not
            // just the viewer) -- lets the lobby flag every player the
            // game is currently blocked on, not only the viewer
            // themselves, so a game where your own play opened a
            // decision targeting someone ELSE (still "your turn" by
            // current_turn_game_player_id, but not actually actionable)
            // shows correctly on whichever player's name it's really
            // waiting on.
            foreach ($playerRows as $row) {
                $rowGamePlayerId = (int) $row['id'];
                if ($rowGamePlayerId === $currentTurnGamePlayerId) {
                    $currentTurnUsername = $row['username'];
                }
                $rowAwaitingResponse = $this->isAwaitingResponseFrom($gameId, $rowGamePlayerId, $game['format']);
                if ($rowAwaitingResponse) {
                    $awaitingResponseUsernames[] = $row['username'];
                }
                if ($yourGamePlayerId !== null && $rowGamePlayerId === $yourGamePlayerId) {
                    $awaitingYourResponse = $rowAwaitingResponse;
                }
            }
        } elseif ($game['status'] === 'waiting' && $draftMatchId !== null) {
            // No current_turn_username here -- a still-'waiting' draft
            // game has no board "turn" concept yet, only who the draft
            // itself is blocked on (see draftAwaitingResponseUsernames()).
            $awaitingResponseUsernames = $this->draftAwaitingResponseUsernames($draftMatchId, $game['deck_type'], $playerRows);
            if ($yourGamePlayerId !== null) {
                $yourUsername = null;
                foreach ($playerRows as $row) {
                    if ((int) $row['id'] === $yourGamePlayerId) {
                        $yourUsername = $row['username'];
                        break;
                    }
                }
                $awaitingYourResponse = $yourUsername !== null && in_array($yourUsername, $awaitingResponseUsernames, true);
            }
        }

        return [
            'id' => $gameId,
            'format' => $game['format'],
            'deck_type' => $game['deck_type'],
            'custom_deck_name' => $game['custom_deck_name'],
            'status' => $game['status'],
            'wins_needed' => (int) $game['wins_needed'],
            'default_selections_mode' => (bool) $game['default_selections_mode'],
            'created_at' => $game['created_at'],
            'started_at' => $game['started_at'],
            'last_move_at' => $game['last_move_at'],
            'completed_at' => $game['completed_at'],
            'players' => $players,
            'is_your_turn' => $yourGamePlayerId !== null && $yourGamePlayerId === $currentTurnGamePlayerId,
            // True for a delayed choice that's on you specifically --
            // distinct from is_your_turn, since none of these ever
            // require it to actually be your own turn: a card another
            // player played that targets you (Compulsion-style, see
            // RequiresOpponentDecision), your own team's turn_order/
            // draw_recipient decision needing your propose/confirm, or
            // (closed_team only) your still-unsubmitted pregame blind
            // card pass. See isAwaitingResponseFrom(). Also true while
            // the game is still 'waiting' on a draft-based deck_type
            // (quick_draft/winston_draft/grid_draft) if the draft or
            // deck-building step itself is specifically blocked on you
            // -- see draftAwaitingResponseUsernames().
            'is_awaiting_your_response' => $awaitingYourResponse,
            // Whichever seated player current_turn_game_player_id
            // actually belongs to, by username -- lets the lobby show a
            // play-arrow icon next to that specific player's name (see
            // buildGameRow()) rather than a row-wide "(your turn)" tag
            // that only ever named the viewer. Null whenever the game
            // isn't 'in_progress' or the round is between turns (e.g.
            // an open team turn_order decision -- see
            // applyTurnOrderDecision()'s own docblock) -- also null for
            // a still-'waiting' draft game, which has no board "turn"
            // concept yet (see awaiting_response_usernames below for
            // who a drafting/deck-building game is blocked on instead).
            'current_turn_username' => $currentTurnUsername,
            // Every seated player isAwaitingResponseFrom() currently
            // returns true for -- the generalized, all-players version
            // of is_awaiting_your_response, so the lobby can show a
            // waiting-hourglass icon next to each one specifically
            // (there can be more than one at once, e.g. closed_team's
            // pregame card pass before every player has submitted).
            // For a still-'waiting' draft-based game, this is instead
            // draftAwaitingResponseUsernames()'s own view of who the
            // draft/deck-building step is blocked on -- same field,
            // same icon, just a pre-game source instead of an
            // in-progress one.
            'awaiting_response_usernames' => $awaitingResponseUsernames,
            'winner_usernames' => $winnerUsernames,
            // Lets the lobby group any draft-based match's (Quick
            // Draft or Winston Draft) up-to-3 games together (same
            // draft_match_id) instead of listing them as unrelated
            // rows, and show the match's own result once it's done --
            // see draftMatchSummaryFor(). 'draft_match' is generic
            // (not 'quick_draft_match') since this shape and its
            // lobby-rendering code are already 100% deck-type-agnostic
            // -- unlike getState()'s own per-format 'quick_draft'/
            // 'winston_draft' fields below, whose 'drafting' sub-shape
            // genuinely differs between the two variants. Null for a
            // null $viewerUserId -- see this method's own docblock for
            // why (draftMatchSummaryFor() assumes a real participant).
            'draft_match_id' => $draftMatchId,
            'match_game_number' => $game['match_game_number'] !== null ? (int) $game['match_game_number'] : null,
            'draft_match' => ($draftMatchId !== null && $viewerUserId !== null)
                ? $this->draftMatchSummaryFor($draftMatchId, $viewerUserId)
                : null,
        ];
    }

    /**
     * listGamesForUser()'s own "is a delayed choice on you specifically"
     * check -- unlike is_your_turn, none of these three require it to
     * actually be your own turn, so they're checked independently of
     * current_turn_game_player_id:
     *
     * 1. (closed_team only) your own pregame blind card pass hasn't been
     *    submitted yet -- see submitInitialCardPass()/"Closed Team Play"
     *    in php-app/README.md. Checked first and returns early since this
     *    blocks everything else in the game.
     * 2. (format 'draft', match_game_number > 1 only) round 1 is still
     *    frozen (current_turn_game_player_id NULL) awaiting your own
     *    setPlayFirstNextMatchGame() call -- only true for the previous
     *    game's loser, the one setPlayFirstNextMatchGame() actually lets
     *    act; see that method's own docblock and "Quick Draft"/"Winston
     *    Draft"/"Grid Draft" in php-app/README.md.
     * 3. (team/closed_team) your team has an open turn_order/draw_recipient
     *    decision (activeTeamDecision()) and you're one of its candidates
     *    -- either phase 'propose' (any candidate may act) or phase
     *    'confirm' where you're specifically the non-proposing teammate
     *    (see confirmTeamDecision()'s own "the OTHER teammate" rule).
     * 4. Any format: the current round has an outstanding pending
     *    decision (Compulsion-style, or an Enthusiasm/Passion scoring
     *    decision -- see RequiresOpponentDecision) whose active step
     *    targets you. Reuses activePendingBatch()/activePendingDecision()
     *    rather than the fuller serializePendingDecision(), since this
     *    only needs the yes/no, never the actual prompt shown to you.
     */
    private function isAwaitingResponseFrom(int $gameId, int $gamePlayerId, string $format): bool
    {
        if ($format === 'closed_team') {
            $submittedStmt = Connection::get()->prepare(
                'SELECT 1 FROM game_initial_card_passes WHERE game_id = :game_id AND game_player_id = :player_id'
            );
            $submittedStmt->execute(['game_id' => $gameId, 'player_id' => $gamePlayerId]);
            if ($submittedStmt->fetchColumn() === false) {
                return true;
            }
        }

        if ($format === 'draft' && $this->isAwaitingFirstPlayerChoiceFrom($gameId, $gamePlayerId)) {
            return true;
        }

        if (self::isTeamFormat($format)) {
            $decision = $this->activeTeamDecision($gameId);
            if ($decision !== null) {
                $candidateIds = array_map(intval(...), json_decode((string) $decision['candidate_game_player_ids'], true));
                if (in_array($gamePlayerId, $candidateIds, true)) {
                    if ($decision['phase'] === 'propose') {
                        return true;
                    }
                    if ($decision['phase'] === 'confirm' && (int) $decision['proposer_game_player_id'] !== $gamePlayerId) {
                        return true;
                    }
                }
            }
        }

        $roundStmt = Connection::get()->prepare(
            "SELECT id FROM game_rounds WHERE game_id = :game_id AND status = 'in_progress' ORDER BY round_number DESC LIMIT 1"
        );
        $roundStmt->execute(['game_id' => $gameId]);
        $roundId = $roundStmt->fetchColumn();
        if ($roundId === false) {
            return false;
        }

        $batch = $this->activePendingBatch((int) $roundId);
        if ($batch === null) {
            return false;
        }

        $decisionRow = $this->activePendingDecision((int) $batch['id']);

        return $decisionRow !== null && (int) $decisionRow['target_game_player_id'] === $gamePlayerId;
    }

    /**
     * isAwaitingResponseFrom()'s own case 2 -- true only for $gamePlayerId
     * if this is game 2/3 of a best-of-three draft match, its round 1 is
     * still frozen (current_turn_game_player_id NULL, see startGame()'s
     * own freeze), and $gamePlayerId belongs to the previous game's
     * loser -- the only player setPlayFirstNextMatchGame() actually lets
     * act. False for game 1 (nothing to freeze on) and once the choice
     * has been made (round unfrozen).
     */
    private function isAwaitingFirstPlayerChoiceFrom(int $gameId, int $gamePlayerId): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT g.draft_match_id, g.match_game_number, gr.current_turn_game_player_id
             FROM games g
             JOIN game_rounds gr ON gr.game_id = g.id AND gr.round_number = 1
             WHERE g.id = :game_id'
        );
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();

        if (
            $row === false
            || $row['current_turn_game_player_id'] !== null
            || $row['draft_match_id'] === null
            || $row['match_game_number'] === null
            || (int) $row['match_game_number'] <= 1
        ) {
            return false;
        }

        $previousWinnerUserId = $this->previousMatchGameWinnerUserId((int) $row['draft_match_id'], (int) $row['match_game_number']);
        if ($previousWinnerUserId === null) {
            return false;
        }

        $seatsStmt = Connection::get()->prepare('SELECT id, user_id FROM game_players WHERE game_id = :game_id');
        $seatsStmt->execute(['game_id' => $gameId]);
        foreach ($seatsStmt->fetchAll() as $seatRow) {
            if ((int) $seatRow['id'] === $gamePlayerId) {
                return (int) $seatRow['user_id'] !== $previousWinnerUserId;
            }
        }

        return false;
    }

    /**
     * listGamesForUser()'s own "who is a still-'waiting' draft game
     * currently blocked on" -- the pre-game analog of
     * isAwaitingResponseFrom(), which only ever applies once a game
     * reaches 'in_progress'. During 'deck_building' this is whichever
     * player(s) haven't yet submitted THIS game's own deck
     * (draft_match_players.deck_card_ids still null -- see
     * submitDraftDeck()). During 'drafting' it's deck-type-specific:
     * quick_draft's per-player draw/received pick stage (delegated to
     * quickDraftAwaitingResponseUsernames(), mirroring
     * quickDraftDraftingStateFor()'s own 'stage' derivation), or
     * winston_draft's/grid_draft's single active turn player
     * (draft_winston_state.current_player_user_id /
     * draft_grid_state.current_turn_user_id -- there's never more than
     * one at once for either, since both are strictly alternating
     * single-active-player formats). Empty once the match itself has
     * completed (nothing left to wait on).
     *
     * @param array<int, array<string, mixed>> $playerRows game_players rows joined to users, this game's own seats
     * @return string[]
     */
    private function draftAwaitingResponseUsernames(int $draftMatchId, string $deckType, array $playerRows): array
    {
        $match = $this->fetchDraftMatch($draftMatchId);

        $usernamesByUserId = [];
        foreach ($playerRows as $row) {
            $usernamesByUserId[(int) $row['user_id']] = $row['username'];
        }

        if ($match['status'] === 'deck_building') {
            $stmt = Connection::get()->prepare(
                'SELECT user_id FROM draft_match_players WHERE draft_match_id = :id AND deck_card_ids IS NULL'
            );
            $stmt->execute(['id' => $draftMatchId]);

            $usernames = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                if (isset($usernamesByUserId[(int) $userId])) {
                    $usernames[] = $usernamesByUserId[(int) $userId];
                }
            }

            return $usernames;
        }

        if ($match['status'] !== 'drafting') {
            return [];
        }

        if ($deckType === 'quick_draft') {
            return $this->quickDraftAwaitingResponseUsernames($draftMatchId, (int) $match['current_round'], $usernamesByUserId);
        }

        $turnStmt = match ($deckType) {
            'winston_draft' => Connection::get()->prepare(
                'SELECT current_player_user_id FROM draft_winston_state WHERE draft_match_id = :id'
            ),
            'grid_draft' => Connection::get()->prepare(
                'SELECT current_turn_user_id FROM draft_grid_state WHERE draft_match_id = :id'
            ),
            default => null,
        };
        if ($turnStmt === null) {
            return [];
        }

        $turnStmt->execute(['id' => $draftMatchId]);
        $currentTurnUserId = $turnStmt->fetchColumn();
        if ($currentTurnUserId === false || !isset($usernamesByUserId[(int) $currentTurnUserId])) {
            return [];
        }

        return [$usernamesByUserId[(int) $currentTurnUserId]];
    }

    /**
     * draftAwaitingResponseUsernames()'s own quick_draft piece -- mirrors
     * quickDraftDraftingStateFor()'s own "current stage" derivation,
     * generalized to every seated player at once: finds the round's
     * current stage (the lowest stage_number not yet complete for every
     * pile), then reports whoever hasn't yet submitted the pick for
     * whichever pile they hold at that stage -- see submitQuickDraftPick()'s
     * own docblock for the seat-rotation math behind "whichever pile they
     * hold".
     *
     * @param array<int, string> $usernamesByUserId keyed by user_id, any order
     * @return string[]
     */
    private function quickDraftAwaitingResponseUsernames(int $draftMatchId, int $currentRound, array $usernamesByUserId): array
    {
        $userIds = $this->draftMatchUserIds($draftMatchId);
        $playerCount = count($userIds);
        $direction = self::quickDraftPassDirection($currentRound);
        $stagePicksThisRound = $this->loadDraftPileStagePicks($draftMatchId)[$currentRound] ?? [];

        $currentStage = null;
        for ($stage = 1; $stage <= $playerCount; $stage++) {
            $completedPiles = 0;
            foreach ($stagePicksThisRound as $stagesForPile) {
                if (isset($stagesForPile[$stage])) {
                    $completedPiles++;
                }
            }
            if ($completedPiles < $playerCount) {
                $currentStage = $stage;
                break;
            }
        }

        if ($currentStage === null) {
            return [];
        }

        $usernames = [];
        foreach ($userIds as $seatIndex => $userId) {
            $pileOwnerUserId = $userIds[self::mod($seatIndex - ($currentStage - 1) * $direction, $playerCount)];
            if (!isset($stagePicksThisRound[$pileOwnerUserId][$currentStage]) && isset($usernamesByUserId[$userId])) {
                $usernames[] = $usernamesByUserId[$userId];
            }
        }

        return $usernames;
    }

    /**
     * The match-level scoreline shared by listGamesForUser()'s own
     * per-game 'draft_match' entries (so the lobby can group a match's
     * up-to-3 games together and, once it's done, show who won) --
     * {status, your_wins, opponent_wins, games_to_win, winner_username,
     * players}. winner_username is null except once status is 'completed'
     * (draft_matches.winner_user_id is null until then). your_wins/
     * opponent_wins/games_to_win are kept for every 2-player draft match
     * (Winston/Grid Draft, and 2-player Quick Draft) exactly as before;
     * opponent_wins is only ever the first non-viewer seat for a 3-4
     * player Quick Draft match, so 'players' (every seated player's own
     * user_id/username/wins/is_you) is what a multiplayer-aware frontend
     * should actually render from. Shared by every draft-based deck_type
     * (only ever reads draft_match_players.wins/draft_matches.status/
     * winner_user_id, nothing pack/pile-specific). A separate, leaner
     * query from quickDraftStateFor()'s/winstonDraftStateFor()'s own
     * draft_match_players read below -- those also need drafted/deck/
     * previous_deck card ids for the deck-building sub-state, which would
     * be wasted work here.
     *
     * @return array<string, mixed>
     */
    private function draftMatchSummaryFor(int $draftMatchId, int $viewerUserId): array
    {
        $match = $this->fetchDraftMatch($draftMatchId);
        $userIds = $this->draftMatchUserIds($draftMatchId);
        $opponentUserId = null;
        foreach ($userIds as $userId) {
            if ($userId !== $viewerUserId) {
                $opponentUserId = $userId;
                break;
            }
        }

        $rowsStmt = Connection::get()->prepare(
            'SELECT dmp.user_id, dmp.wins, u.username FROM draft_match_players dmp
             JOIN users u ON u.id = dmp.user_id WHERE dmp.draft_match_id = :id'
        );
        $rowsStmt->execute(['id' => $draftMatchId]);
        $rowsByUser = [];
        foreach ($rowsStmt->fetchAll() as $row) {
            $rowsByUser[(int) $row['user_id']] = $row;
        }

        $winnerUsername = $match['winner_user_id'] !== null
            ? ($rowsByUser[(int) $match['winner_user_id']]['username'] ?? null)
            : null;

        $players = [];
        foreach ($userIds as $userId) {
            $players[] = [
                'user_id' => $userId,
                'username' => $rowsByUser[$userId]['username'] ?? null,
                'wins' => (int) ($rowsByUser[$userId]['wins'] ?? 0),
                'is_you' => $userId === $viewerUserId,
            ];
        }

        return [
            'status' => $match['status'],
            'your_wins' => (int) ($rowsByUser[$viewerUserId]['wins'] ?? 0),
            'opponent_wins' => $opponentUserId !== null ? (int) ($rowsByUser[$opponentUserId]['wins'] ?? 0) : 0,
            'games_to_win' => $this->draftGamesToWin(count($userIds)),
            'winner_username' => $winnerUsername,
            'players' => $players,
        ];
    }

    /**
     * Issue #314 "view shared draft pool after a draft match completes":
     * $gameId's own draft_match_id (fixed at match creation, shared across
     * however many of its up-to-3 games rows exist -- see draft_matches'
     * own migration 0027 docblock) resolved back to the match's full
     * pool_card_ids, each seated player's own drafted_card_ids (deliberately
     * NOT deck_card_ids -- see this method's own reasoning below), and
     * whatever's left over. Authorization (seated player or
     * canSpectateGame()) is the caller's own job, same as
     * replayStateAsOf()/fullEventLog() -- see the GET /games/draft-pool
     * route.
     *
     * "Drafted" here means drafted_card_ids specifically, not the
     * player's final deck_card_ids -- the two are genuinely different
     * columns (see submitDraftDeck()'s own docblock: deck_card_ids is a
     * player-chosen SUBSET of drafted_card_ids, changeable game-to-game
     * within the same match's sideboarding, while drafted_card_ids is the
     * fixed result of the draft itself). This view answers "what did you
     * draft," not "what's in your current deck."
     *
     * A pool card belonging to nobody is a real, expected outcome, not an
     * edge case -- Quick Draft discards exactly 2 cards per pile per
     * round by design, Grid Draft discards whatever's left in the grid at
     * the end of every round, and even Winston Draft (which normally
     * drafts the entire pool) can leave cards unclaimed if a short player
     * was ever dropped via removeDraftMatchPlayer(). $undraftedCardIds is
     * computed via the same multisetSubtract() every other pool/pick
     * accounting in this class already uses, so a duplicate catalog id
     * (a custom pool listing 2 of the same card) is still subtracted
     * correctly one-for-one rather than an id-based array_diff wrongly
     * removing every copy at once.
     *
     * @return array<string, mixed>
     */
    public function draftMatchPoolView(int $gameId): array
    {
        $game = $this->fetchGame($gameId);
        $draftMatchId = $game['draft_match_id'] !== null ? (int) $game['draft_match_id'] : null;
        if ($draftMatchId === null) {
            throw new GameStateException("Game {$gameId} isn't part of a draft match");
        }

        $match = $this->fetchDraftMatch($draftMatchId);
        if ($match['status'] !== 'completed') {
            throw new GameStateException("Draft match {$draftMatchId} isn't completed yet -- its draft pool is only available once the match is over");
        }

        $rowsStmt = Connection::get()->prepare(
            'SELECT dmp.user_id, dmp.drafted_card_ids, u.username FROM draft_match_players dmp
             JOIN users u ON u.id = dmp.user_id WHERE dmp.draft_match_id = :id ORDER BY dmp.id ASC'
        );
        $rowsStmt->execute(['id' => $draftMatchId]);

        $players = [];
        $everyDraftedCardId = [];
        foreach ($rowsStmt->fetchAll() as $row) {
            $draftedCardIds = $row['drafted_card_ids'] !== null
                ? array_map(intval(...), json_decode((string) $row['drafted_card_ids'], true))
                : [];
            $everyDraftedCardId = [...$everyDraftedCardId, ...$draftedCardIds];

            $players[] = [
                'user_id' => (int) $row['user_id'],
                'username' => $row['username'],
                'cards' => $this->serializeCatalogCards($draftedCardIds),
            ];
        }

        $poolCardIds = array_map(intval(...), json_decode((string) $match['pool_card_ids'], true));
        $undraftedCardIds = $this->multisetSubtract($poolCardIds, $everyDraftedCardId);

        return [
            'draft_match_id' => $draftMatchId,
            'players' => $players,
            'undrafted_cards' => $this->serializeCatalogCards($undraftedCardIds),
        ];
    }

    /**
     * getState()'s own 'quick_draft' field -- the match-level scoreline
     * (always present once a draft_match_id exists) plus whichever one of
     * 'drafting'/'deck_building' is currently live (null if the match has
     * already completed). Never exposes the opponent's own drafted/kept
     * cards -- only $viewerUserId's own. 'next_game_id' is only ever set
     * once THIS game has completed and advanceDraftMatch() has
     * already created the next one (i.e. the match itself isn't
     * 'completed' either) -- lets the frontend offer a direct "Go to next
     * game" link from a finished game's own board instead of making the
     * player find it back in the lobby list themselves.
     *
     * @param array<string, mixed> $game
     */
    private function quickDraftStateFor(array $game, int $viewerUserId): array
    {
        $draftMatchId = (int) $game['draft_match_id'];
        $match = $this->fetchDraftMatch($draftMatchId);
        $userIds = $this->draftMatchUserIds($draftMatchId);
        $opponentUserId = null;
        foreach ($userIds as $userId) {
            if ($userId !== $viewerUserId) {
                $opponentUserId = $userId;
                break;
            }
        }

        $playersStmt = Connection::get()->prepare(
            'SELECT dmp.user_id, dmp.wins, dmp.drafted_card_ids, dmp.deck_card_ids, dmp.previous_deck_card_ids, u.username
             FROM draft_match_players dmp JOIN users u ON u.id = dmp.user_id WHERE dmp.draft_match_id = :id'
        );
        $playersStmt->execute(['id' => $draftMatchId]);
        $playersByUser = [];
        foreach ($playersStmt->fetchAll() as $row) {
            $playersByUser[(int) $row['user_id']] = $row;
        }

        $nextGameId = null;
        if ($game['status'] === 'completed' && $match['status'] !== 'completed') {
            $nextGameStmt = Connection::get()->prepare(
                'SELECT id FROM games WHERE draft_match_id = :match_id ORDER BY match_game_number DESC LIMIT 1'
            );
            $nextGameStmt->execute(['match_id' => $draftMatchId]);
            $latestGameId = (int) $nextGameStmt->fetchColumn();
            if ($latestGameId !== (int) $game['id']) {
                $nextGameId = $latestGameId;
            }
        }

        $players = [];
        foreach ($userIds as $userId) {
            $players[] = [
                'user_id' => $userId,
                'username' => $playersByUser[$userId]['username'] ?? null,
                'wins' => (int) ($playersByUser[$userId]['wins'] ?? 0),
                'is_you' => $userId === $viewerUserId,
            ];
        }

        $state = [
            'draft_match_id' => $draftMatchId,
            'match_game_number' => $game['match_game_number'] !== null ? (int) $game['match_game_number'] : null,
            'status' => $match['status'],
            'games_to_win' => $this->draftGamesToWin(count($userIds)),
            'next_game_id' => $nextGameId,
            'your_wins' => (int) ($playersByUser[$viewerUserId]['wins'] ?? 0),
            'opponent_wins' => $opponentUserId !== null ? (int) ($playersByUser[$opponentUserId]['wins'] ?? 0) : 0,
            'players' => $players,
            'drafting' => null,
            'deck_building' => null,
        ];

        if ($match['status'] === 'drafting') {
            $state['drafting'] = $this->quickDraftDraftingStateFor($draftMatchId, (int) $match['current_round'], $viewerUserId, $userIds);
        } elseif ($match['status'] === 'deck_building') {
            // Quick Draft's own max deck size is always however many
            // cards this player actually drafted (issue #189 -- no longer
            // a flat 16, since a 3-player match's own pool nets 18) -- see
            // submitDraftDeck()'s matching change.
            $state['deck_building'] = $this->draftDeckBuildingStateFor(
                $playersByUser,
                $viewerUserId,
                array_values(array_diff($userIds, [$viewerUserId])),
                self::QUICK_DRAFT_MIN_DECK_SIZE,
                null,
            );
        }

        return $state;
    }

    /**
     * getState()'s own 'deck_building' sub-state, shared identically by
     * Quick Draft, Winston Draft, and Grid Draft (quickDraftStateFor()/
     * winstonDraftStateFor()/gridDraftStateFor()) -- only their own min/max
     * deck size, and how many other players there are, differ.
     * $maxDeckSize is nullable: Winston Draft/Grid Draft, and (since issue
     * #189) Quick Draft too, have no fixed ceiling -- the total cards
     * drafted varies by player count and/or how the draft unfolds -- so
     * passing null caps it at however many cards $viewerUserId actually
     * drafted instead of a shared constant. $otherUserIds is every OTHER
     * seated player (1 for a 2-player match, up to 3 for a multiplayer
     * Quick Draft match) -- 'opponent_submitted' is kept, populated from
     * whichever of them comes first in $otherUserIds, purely so an
     * unchanged 2-player frontend keeps working verbatim; 'other_players'
     * (every one of them, with their own username/submitted flag) is what
     * a multiplayer-aware frontend should actually render from.
     *
     * @param array<int, array<string, mixed>> $playersByUser draft_match_players rows, keyed by user_id
     * @param int[] $otherUserIds
     */
    private function draftDeckBuildingStateFor(
        array $playersByUser,
        int $viewerUserId,
        array $otherUserIds,
        int $minDeckSize,
        ?int $maxDeckSize,
    ): array {
        $viewerRow = $playersByUser[$viewerUserId] ?? null;
        $draftedCardIds = $viewerRow !== null && $viewerRow['drafted_card_ids'] !== null
            ? array_map(intval(...), json_decode((string) $viewerRow['drafted_card_ids'], true))
            : [];
        $deckCardIds = $viewerRow !== null && $viewerRow['deck_card_ids'] !== null
            ? array_map(intval(...), json_decode((string) $viewerRow['deck_card_ids'], true))
            : null;
        // Only ever meaningful while $deckCardIds is still null (this
        // game's own deck hasn't been (re)submitted yet) -- the very
        // first game of a match has no previous game to carry a deck
        // over from, so this stays null there too, and the frontend
        // falls back to preselecting every drafted card exactly as it
        // did before this field existed.
        $previousDeckCardIds = $viewerRow !== null && $viewerRow['previous_deck_card_ids'] !== null
            ? array_map(intval(...), json_decode((string) $viewerRow['previous_deck_card_ids'], true))
            : null;

        $otherPlayers = [];
        foreach ($otherUserIds as $otherUserId) {
            $otherPlayers[] = [
                'user_id' => $otherUserId,
                'username' => $playersByUser[$otherUserId]['username'] ?? null,
                'submitted' => ($playersByUser[$otherUserId]['deck_card_ids'] ?? null) !== null,
            ];
        }

        return [
            'drafted_cards' => $this->serializeCatalogCards($draftedCardIds),
            'deck_card_ids' => $deckCardIds,
            'previous_deck_card_ids' => $previousDeckCardIds,
            'min_deck_size' => $minDeckSize,
            'max_deck_size' => $maxDeckSize ?? count($draftedCardIds),
            'you_submitted' => $deckCardIds !== null,
            'opponent_submitted' => $otherPlayers[0]['submitted'] ?? false,
            'other_players' => $otherPlayers,
        ];
    }

    /**
     * getState()'s own view of the current draft round for $viewerUserId.
     * 'stage' is the round's current stage number (1..total_stages, where
     * total_stages = the match's player count -- see submitQuickDraftPick()'s
     * own docblock for why); 'status' is 'picking' (viewer hasn't yet
     * submitted their pick for whichever pile they currently hold --
     * 'pack' is that pile's actual remaining contents) or 'awaiting_others'
     * (viewer has already submitted this stage's pick, or the round has
     * fully resolved and just hasn't advanced yet -- either way there's
     * nothing for them to act on right now, so 'pack' is empty). This
     * generalizes the original 2-player 'draw'/'awaiting_opponent_draw'/
     * 'received'/'awaiting_opponent_received' enum, which was really just
     * this same picking/awaiting_others distinction spelled out for
     * exactly 2 stages. 'kept_so_far' is every card $viewerUserId has kept
     * in this match's draft so far (across every prior round, plus
     * whatever's already resolved this round) -- never any other player's
     * own kept/passed/discarded cards, which stay fully invisible until
     * the draft ends and every player's full drafted_card_ids is their own
     * private data on draft_match_players.
     *
     * @param int[] $userIds the match's user ids (any count 2-4), in seat order
     */
    private function quickDraftDraftingStateFor(int $draftMatchId, int $currentRound, int $viewerUserId, array $userIds): array
    {
        $playerCount = count($userIds);
        $direction = self::quickDraftPassDirection($currentRound);
        $seatIndex = array_search($viewerUserId, $userIds, true);

        $drawnByRound = $this->loadDraftRoundPicks($draftMatchId);
        $stagePicksByRound = $this->loadDraftPileStagePicks($draftMatchId);

        $keptSoFar = [];
        foreach ($stagePicksByRound as $roundNumber => $pilesByOwner) {
            if ($roundNumber >= $currentRound) {
                continue;
            }
            foreach ($pilesByOwner as $stagesForPile) {
                foreach ($stagesForPile as $stage) {
                    if ($stage['holder_user_id'] === $viewerUserId) {
                        $keptSoFar = [...$keptSoFar, ...$stage['kept_card_ids']];
                    }
                }
            }
        }

        $stagePicksThisRound = $stagePicksByRound[$currentRound] ?? [];

        // The round's current stage: the lowest stage number not yet
        // complete for every pile. null once every pile has finished
        // every stage (the round is fully resolved but hasn't advanced
        // yet -- normally brief, since the same submission that completes
        // the last pile's last stage also triggers the advance).
        $currentStage = null;
        for ($stage = 1; $stage <= $playerCount; $stage++) {
            $completedPiles = 0;
            foreach ($stagePicksThisRound as $stagesForPile) {
                if (isset($stagesForPile[$stage])) {
                    $completedPiles++;
                }
            }
            if ($completedPiles < $playerCount) {
                $currentStage = $stage;
                break;
            }
        }

        $pileOwnerUserId = $currentStage !== null
            ? $userIds[self::mod($seatIndex - ($currentStage - 1) * $direction, $playerCount)]
            : null;
        $viewerSubmittedCurrentStage = $pileOwnerUserId !== null && isset($stagePicksThisRound[$pileOwnerUserId][$currentStage]);

        $status = 'awaiting_others';
        $pack = [];
        if ($pileOwnerUserId !== null && !$viewerSubmittedCurrentStage) {
            $status = 'picking';
            $pack = $drawnByRound[$currentRound][$pileOwnerUserId] ?? [];
            for ($priorStage = 1; $priorStage < $currentStage; $priorStage++) {
                $pack = $this->multisetSubtract($pack, $stagePicksThisRound[$pileOwnerUserId][$priorStage]['kept_card_ids'] ?? []);
            }
        }

        foreach ($stagePicksThisRound as $stagesForPile) {
            foreach ($stagesForPile as $stage) {
                if ($stage['holder_user_id'] === $viewerUserId) {
                    $keptSoFar = [...$keptSoFar, ...$stage['kept_card_ids']];
                }
            }
        }

        return [
            'round' => $currentRound,
            'total_rounds' => self::quickDraftRounds($playerCount),
            'stage' => $currentStage ?? $playerCount,
            'total_stages' => $playerCount,
            'pass_direction' => $direction === 1 ? 'right' : 'left',
            'status' => $status,
            'pack' => $this->serializeCatalogCards($pack),
            'kept_so_far' => $this->serializeCatalogCards($keptSoFar),
        ];
    }

    /**
     * getState()'s own 'winston_draft' field -- Winston Draft's own analog
     * of quickDraftStateFor() above: the same match-level scoreline plus
     * whichever one of 'drafting'/'deck_building' is currently live (both
     * null once the match has completed, including the "you're
     * automatically short of WINSTON_MIN_DECK_SIZE cards" auto-loss path
     * -- see finalizeWinstonDraft()). Kept as its own separate field from
     * 'quick_draft' rather than unified -- their 'drafting' sub-shapes are
     * genuinely different (round/stage/pack vs. pile-sizes/current-pile/
     * turn), so a shared field name wouldn't save the frontend any
     * branching, unlike the lobby's own 'draft_match' summary (identical
     * shape for both, and already generalized).
     *
     * @param array<string, mixed> $game
     */
    private function winstonDraftStateFor(array $game, int $viewerUserId): array
    {
        $draftMatchId = (int) $game['draft_match_id'];
        $match = $this->fetchDraftMatch($draftMatchId);
        $userIds = $this->draftMatchUserIds($draftMatchId);
        $opponentUserId = null;
        foreach ($userIds as $userId) {
            if ($userId !== $viewerUserId) {
                $opponentUserId = $userId;
                break;
            }
        }

        $playersStmt = Connection::get()->prepare(
            'SELECT dmp.user_id, dmp.wins, dmp.drafted_card_ids, dmp.deck_card_ids, dmp.previous_deck_card_ids, u.username
             FROM draft_match_players dmp JOIN users u ON u.id = dmp.user_id WHERE dmp.draft_match_id = :id'
        );
        $playersStmt->execute(['id' => $draftMatchId]);
        $playersByUser = [];
        foreach ($playersStmt->fetchAll() as $row) {
            $playersByUser[(int) $row['user_id']] = $row;
        }

        $nextGameId = null;
        if ($game['status'] === 'completed' && $match['status'] !== 'completed') {
            $nextGameStmt = Connection::get()->prepare(
                'SELECT id FROM games WHERE draft_match_id = :match_id ORDER BY match_game_number DESC LIMIT 1'
            );
            $nextGameStmt->execute(['match_id' => $draftMatchId]);
            $latestGameId = (int) $nextGameStmt->fetchColumn();
            if ($latestGameId !== (int) $game['id']) {
                $nextGameId = $latestGameId;
            }
        }

        $state = [
            'draft_match_id' => $draftMatchId,
            'match_game_number' => $game['match_game_number'] !== null ? (int) $game['match_game_number'] : null,
            'status' => $match['status'],
            'games_to_win' => $this->draftGamesToWin(count($userIds)),
            'next_game_id' => $nextGameId,
            'your_wins' => (int) ($playersByUser[$viewerUserId]['wins'] ?? 0),
            'opponent_wins' => $opponentUserId !== null ? (int) ($playersByUser[$opponentUserId]['wins'] ?? 0) : 0,
            'players' => array_map(fn (int $uid) => [
                'user_id' => $uid,
                'username' => $playersByUser[$uid]['username'] ?? null,
                'wins' => (int) ($playersByUser[$uid]['wins'] ?? 0),
                'is_you' => $uid === $viewerUserId,
            ], $userIds),
            'drafting' => null,
            'deck_building' => null,
        ];

        if ($match['status'] === 'drafting') {
            $state['drafting'] = $this->winstonDraftDraftingStateFor($draftMatchId, $viewerUserId, $userIds, $playersByUser);
        } elseif ($match['status'] === 'deck_building') {
            $state['deck_building'] = $this->draftDeckBuildingStateFor(
                $playersByUser,
                $viewerUserId,
                array_values(array_diff($userIds, [$viewerUserId])),
                self::WINSTON_MIN_DECK_SIZE,
                null,
            );
        }

        return $state;
    }

    /**
     * getState()'s own view of Winston Draft's current pile/deck/turn
     * state for $viewerUserId. pile_sizes/remaining_deck_count/
     * current_pile_number are always visible to every seated player -- a
     * real stack of face-down cards is physically visible even though
     * its *contents* aren't, so there's nothing to hide about how tall
     * each pile is or whose turn it is (current_turn_username, issue
     * #189, names whoever that actually is -- for a 3-4 player match,
     * "not your turn" alone doesn't say whose turn it is the way it did
     * for exactly 2). current_pile_cards (the actual card identities) is
     * only ever populated when $viewerUserId is the current player -- no
     * other seated player ever sees what's actually in the pile being
     * looked at, exactly matching quickDraftDraftingStateFor()'s own
     * "never expose the active player's own pack" contract.
     * drafted_so_far is always $viewerUserId's own accumulated picks to
     * date, never anyone else's. other_players (issue #189) covers every
     * *other* seated player's own last_take_pile_number/
     * last_drew_from_deck/drafted_card_count -- opponent_last_take_pile_number/
     * opponent_last_drew_from_deck/opponent_drafted_card_count are kept
     * as a single-value fallback (the first of them). None of this is
     * unsafe to expose without revealing card identities: which numbered
     * pile a player last claimed (or that they instead declined all 3
     * piles and took the mandatory top-of-deck draw), and how many cards
     * they've drafted in total, are all things a real opponent watching
     * across the table would already see for themselves (a taken pile's
     * height and a rival's growing stack of face-down cards are
     * physically visible), unlike what's actually printed on those cards.
     *
     * @param int[] $userIds the match's user ids (any count 2-4), in seat order
     * @param array<int, array<string, mixed>> $playersByUser draft_match_players rows, keyed by user_id
     */
    private function winstonDraftDraftingStateFor(int $draftMatchId, int $viewerUserId, array $userIds, array $playersByUser): array
    {
        $stateStmt = Connection::get()->prepare('SELECT * FROM draft_winston_state WHERE draft_match_id = :id');
        $stateStmt->execute(['id' => $draftMatchId]);
        $winstonState = $stateStmt->fetch();

        $currentPlayerUserId = (int) $winstonState['current_player_user_id'];
        $currentPileNumber = (int) $winstonState['current_pile_number'];
        $isYourTurn = $currentPlayerUserId === $viewerUserId;

        $pileSizes = [];
        for ($pileNumber = 1; $pileNumber <= 3; $pileNumber++) {
            $pileSizes[] = count(json_decode((string) $winstonState["pile_{$pileNumber}_card_ids"], true));
        }

        $currentPileCards = [];
        if ($isYourTurn) {
            $currentPileCardIds = array_map(
                intval(...),
                json_decode((string) $winstonState["pile_{$currentPileNumber}_card_ids"], true)
            );
            $currentPileCards = $this->serializeCatalogCards($currentPileCardIds);
        }

        $draftedCardIdsFor = fn (int $userId): array => ($playersByUser[$userId]['drafted_card_ids'] ?? null) !== null
            ? array_map(intval(...), json_decode((string) $playersByUser[$userId]['drafted_card_ids'], true))
            : [];

        $lastDraftActionByUserId = (array) json_decode((string) $winstonState['last_draft_action_by_user_id'], true);

        $otherUserIds = array_values(array_diff($userIds, [$viewerUserId]));
        $otherPlayers = array_map(function (int $uid) use ($lastDraftActionByUserId, $draftedCardIdsFor, $playersByUser): array {
            $lastAction = $lastDraftActionByUserId[(string) $uid] ?? null;

            return [
                'user_id' => $uid,
                'username' => $playersByUser[$uid]['username'] ?? null,
                'drafted_card_count' => count($draftedCardIdsFor($uid)),
                'last_take_pile_number' => is_int($lastAction) ? $lastAction : null,
                'last_drew_from_deck' => $lastAction === 'deck',
            ];
        }, $otherUserIds);
        $firstOtherPlayer = $otherPlayers[0] ?? null;

        return [
            'is_your_turn' => $isYourTurn,
            'current_turn_username' => $playersByUser[$currentPlayerUserId]['username'] ?? null,
            'current_pile_number' => $currentPileNumber,
            'pile_sizes' => $pileSizes,
            'remaining_deck_count' => count(json_decode((string) $winstonState['remaining_deck_card_ids'], true)),
            'current_pile_cards' => $currentPileCards,
            'drafted_so_far' => $this->serializeCatalogCards($draftedCardIdsFor($viewerUserId)),
            'opponent_last_take_pile_number' => $firstOtherPlayer['last_take_pile_number'] ?? null,
            'opponent_last_drew_from_deck' => $firstOtherPlayer['last_drew_from_deck'] ?? false,
            'opponent_drafted_card_count' => $firstOtherPlayer['drafted_card_count'] ?? 0,
            'other_players' => $otherPlayers,
        ];
    }

    /**
     * getState()'s own 'grid_draft' field builder -- structurally the same
     * shape as winstonDraftStateFor() above (match-level scoreline plus
     * drafting/deck_building sub-state, same next_game_id logic), reusing
     * draftDeckBuildingStateFor() identically once the match reaches
     * deck_building.
     *
     * @param array<string, mixed> $game
     */
    private function gridDraftStateFor(array $game, int $viewerUserId): array
    {
        $draftMatchId = (int) $game['draft_match_id'];
        $match = $this->fetchDraftMatch($draftMatchId);
        $userIds = $this->draftMatchUserIds($draftMatchId);
        $opponentUserId = null;
        foreach ($userIds as $userId) {
            if ($userId !== $viewerUserId) {
                $opponentUserId = $userId;
                break;
            }
        }

        $playersStmt = Connection::get()->prepare(
            'SELECT dmp.user_id, dmp.wins, dmp.drafted_card_ids, dmp.deck_card_ids, dmp.previous_deck_card_ids, u.username
             FROM draft_match_players dmp JOIN users u ON u.id = dmp.user_id WHERE dmp.draft_match_id = :id'
        );
        $playersStmt->execute(['id' => $draftMatchId]);
        $playersByUser = [];
        foreach ($playersStmt->fetchAll() as $row) {
            $playersByUser[(int) $row['user_id']] = $row;
        }

        $nextGameId = null;
        if ($game['status'] === 'completed' && $match['status'] !== 'completed') {
            $nextGameStmt = Connection::get()->prepare(
                'SELECT id FROM games WHERE draft_match_id = :match_id ORDER BY match_game_number DESC LIMIT 1'
            );
            $nextGameStmt->execute(['match_id' => $draftMatchId]);
            $latestGameId = (int) $nextGameStmt->fetchColumn();
            if ($latestGameId !== (int) $game['id']) {
                $nextGameId = $latestGameId;
            }
        }

        $state = [
            'draft_match_id' => $draftMatchId,
            'match_game_number' => $game['match_game_number'] !== null ? (int) $game['match_game_number'] : null,
            'status' => $match['status'],
            'games_to_win' => $this->draftGamesToWin(count($userIds)),
            'next_game_id' => $nextGameId,
            'your_wins' => (int) ($playersByUser[$viewerUserId]['wins'] ?? 0),
            'opponent_wins' => $opponentUserId !== null ? (int) ($playersByUser[$opponentUserId]['wins'] ?? 0) : 0,
            'players' => array_map(fn (int $uid) => [
                'user_id' => $uid,
                'username' => $playersByUser[$uid]['username'] ?? null,
                'wins' => (int) ($playersByUser[$uid]['wins'] ?? 0),
                'is_you' => $uid === $viewerUserId,
            ], $userIds),
            'drafting' => null,
            'deck_building' => null,
        ];

        if ($match['status'] === 'drafting') {
            $state['drafting'] = $this->gridDraftDraftingStateFor($draftMatchId, $viewerUserId, $userIds, $playersByUser);
        } elseif ($match['status'] === 'deck_building') {
            $state['deck_building'] = $this->draftDeckBuildingStateFor(
                $playersByUser,
                $viewerUserId,
                array_values(array_diff($userIds, [$viewerUserId])),
                self::GRID_DRAFT_MIN_DECK_SIZE,
                null,
            );
        }

        return $state;
    }

    /**
     * getState()'s own view of Grid Draft's current grid/turn state for
     * $viewerUserId. The grid's cards are always fully visible to every
     * seated player (unlike Winston Draft's face-down piles, a dealt grid
     * is face-up on the table) -- grid_cards is a grid_size^2-element
     * array in row-major order (index = row * grid_size + column), with a
     * null entry for any cell already taken (and not yet refilled) this
     * round. grid_size is gridDraftGridSize(count($userIds)) -- 4 for a
     * 4-player match, 3 otherwise -- so the frontend knows the grid's own
     * dimensions and can compute cell indices to match.
     * current_turn_username names whoever current_turn_user_id actually
     * is, for a 3-4 player match where "not your turn" alone doesn't say
     * whose turn it actually is (issue #189) -- 2-player matches can
     * still derive the exact same "your turn"/"opponent's turn" contract
     * they always have from is_your_turn alone. Grid Draft is open
     * information end to end -- every card any player has ever drafted
     * was visible to everyone the moment it was dealt into a face-up grid
     * -- so, unlike Quick Draft's/Winston Draft's own drafted_so_far
     * (each strictly the viewer's own picks, never anyone else's), this
     * also exposes every other seated player's own drafted_so_far via
     * other_players_drafted_so_far ({user_id, username, drafted_so_far}
     * per other player); opponent_drafted_so_far is kept as a
     * single-value fallback (the first of them) for a 2-player match.
     *
     * @param int[] $userIds the match's user ids (any count 2-4), in seat order
     * @param array<int, array<string, mixed>> $playersByUser draft_match_players rows, keyed by user_id
     */
    private function gridDraftDraftingStateFor(int $draftMatchId, int $viewerUserId, array $userIds, array $playersByUser): array
    {
        $stateStmt = Connection::get()->prepare('SELECT * FROM draft_grid_state WHERE draft_match_id = :id');
        $stateStmt->execute(['id' => $draftMatchId]);
        $gridState = $stateStmt->fetch();

        $gridCardIds = json_decode((string) $gridState['grid_card_ids'], true);
        $gridCards = array_map(
            fn ($cardId) => $cardId !== null ? $this->serializeCatalogCards([(int) $cardId])[0] : null,
            $gridCardIds
        );

        $draftedCardIdsFor = fn (int $userId): array => ($playersByUser[$userId]['drafted_card_ids'] ?? null) !== null
            ? array_map(intval(...), json_decode((string) $playersByUser[$userId]['drafted_card_ids'], true))
            : [];

        $currentTurnUserId = (int) $gridState['current_turn_user_id'];
        $otherUserIds = array_values(array_diff($userIds, [$viewerUserId]));
        $opponentUserId = $otherUserIds[0] ?? null;

        return [
            'is_your_turn' => $currentTurnUserId === $viewerUserId,
            'current_turn_username' => $playersByUser[$currentTurnUserId]['username'] ?? null,
            'current_round' => (int) $gridState['current_round'],
            'total_rounds' => self::gridDraftRounds(count($userIds)),
            'grid_size' => self::gridDraftGridSize(count($userIds)),
            'first_picker_user_id' => (int) $gridState['first_picker_user_id'],
            'picks_this_round' => (int) $gridState['picks_this_round'],
            'total_picks_per_round' => count($userIds),
            'grid_cards' => $gridCards,
            'remaining_deck_count' => count(json_decode((string) $gridState['remaining_deck_card_ids'], true)),
            'drafted_so_far' => $this->serializeCatalogCards($draftedCardIdsFor($viewerUserId)),
            'opponent_drafted_so_far' => $opponentUserId !== null
                ? $this->serializeCatalogCards($draftedCardIdsFor($opponentUserId))
                : [],
            'other_players_drafted_so_far' => array_map(fn (int $uid) => [
                'user_id' => $uid,
                'username' => $playersByUser[$uid]['username'] ?? null,
                'drafted_so_far' => $this->serializeCatalogCards($draftedCardIdsFor($uid)),
            ], $otherUserIds),
        ];
    }

    /** @return array<string, mixed> */
    public function getState(int $gameId, int $viewerUserId): array
    {
        $viewerGamePlayerId = $this->gamePlayerIdFor($gameId, $viewerUserId);
        if ($viewerGamePlayerId === null) {
            throw new GameStateException("User {$viewerUserId} is not seated in game {$gameId}");
        }

        return $this->buildGameState($gameId, $viewerUserId, $viewerGamePlayerId);
    }

    /**
     * Spectator mode (issue #128): the public-information view of a game
     * nobody watching is actually seated in. buildGameState() below simply
     * never populates a 'you' key at all for a null viewer -- the same
     * pattern 'closed_team' already uses to never populate
     * 'you.teammate_hand' (see "Closed Team Play" in php-app/README.md) --
     * so no hand contents or a targeted decision's own private 'field'
     * payload ever leak while the game is still 'in_progress'. Once a game
     * is 'completed' there's nothing competitive left to conceal, so
     * $revealAllHands additionally exposes every seated player's final
     * hand (players[].hand) -- matching how a finished game is customarily
     * "shown" once it no longer matters. Spectating a still-'waiting' game
     * (nothing dealt yet) or an 'abandoned' one is rejected outright. See
     * "Spectator mode" in php-app/README.md, including the deferred
     * "tournament spectator" mode (full private info, LIVE) this
     * deliberately does not cover.
     *
     * @return array<string, mixed>
     */
    public function getSpectatorState(int $gameId): array
    {
        $game = $this->fetchGame($gameId);
        if ($game['status'] === 'waiting' || $game['status'] === 'abandoned') {
            throw new GameStateException("Game {$gameId} can't be spectated right now.");
        }

        return $this->buildGameState($gameId, null, null, $game['status'] === 'completed');
    }

    /**
     * Shared builder behind both getState() (a real seated player --
     * $viewerUserId/$viewerGamePlayerId always non-null) and
     * getSpectatorState() (a non-seated spectator -- both null,
     * $revealAllHands only ever true for a completed game). Every branch
     * below conditioned on $viewerGamePlayerId/$viewerUserId === null was
     * deliberately made explicit rather than relying on a null id merely
     * happening to compare unequal everywhere -- see "Spectator mode" in
     * php-app/README.md.
     *
     * @return array<string, mixed>
     */
    private function buildGameState(int $gameId, ?int $viewerUserId, ?int $viewerGamePlayerId, bool $revealAllHands = false): array
    {
        $game = $this->fetchGame($gameId);
        $pdo = Connection::get();

        $playersStmt = $pdo->prepare(
            'SELECT gp.id, gp.user_id, gp.seat_order, gp.team_id, gp.custom_deck_name, gp.custom_deck_card_ids, gp.resigned_at, u.username, u.share_presence FROM game_players gp
             JOIN users u ON u.id = gp.user_id
             WHERE gp.game_id = :game_id ORDER BY gp.seat_order ASC'
        );
        $playersStmt->execute(['game_id' => $gameId]);
        $playerRows = $playersStmt->fetchAll();

        // Online/presence indicator (issue #110) -- computed once per
        // request for every seated player at once (a single sessions
        // lookup) rather than per-row, and reused for the loop below.
        // Deliberately not shared with serializeReplaySnapshot()'s own,
        // separate players loop -- a completed game's historical replay
        // has no "current round" for a specific past event (see that
        // method's own docblock), and "was this player online" is
        // meaningless for a moment frozen in the past the same way.
        $sharePresenceByUserId = [];
        foreach ($playerRows as $row) {
            $sharePresenceByUserId[(int) $row['user_id']] = (bool) $row['share_presence'];
        }
        $presenceStatuses = $this->presence->statusesFor($sharePresenceByUserId);

        $handCounts = [];
        if ($game['status'] === 'in_progress' || $game['status'] === 'completed') {
            $handCountStmt = $pdo->prepare(
                "SELECT owner_game_player_id, COUNT(*) AS n FROM game_cards
                 WHERE game_id = :game_id AND zone = 'hand'
                 GROUP BY owner_game_player_id"
            );
            $handCountStmt->execute(['game_id' => $gameId]);
            foreach ($handCountStmt->fetchAll() as $row) {
                $handCounts[(int) $row['owner_game_player_id']] = (int) $row['n'];
            }
        }

        $players = [];
        foreach ($playerRows as $row) {
            $players[] = [
                'game_player_id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'username' => $row['username'],
                'seat_order' => (int) $row['seat_order'],
                // Only meaningful for format 'team' -- see "Open Team
                // Play" in php-app/README.md -- null for every other
                // format, since game_players.team_id is never written
                // otherwise.
                'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
                'hand_count' => $handCounts[(int) $row['id']] ?? 0,
                'total_wins' => $this->totalWinsFor($gameId, (int) $row['id']),
                // Overwritten below with the live sum of this player's
                // in-play moods' current values once BoardState is loaded
                // -- 0 here only actually applies to a 'waiting' game
                // (nothing dealt yet), the one status this function
                // returns for before ever reaching that point.
                'total_score' => 0,
                // Overwritten below with this player's own remaining deck
                // size once BoardState is loaded (0 here only actually
                // applies to a 'waiting' game) -- always public, the same
                // category of information as hand_count above, so a
                // spectator sees it for every player, not just their own
                // (see "Spectator mode" in php-app/README.md).
                'deck_count' => 0,
                // Only meaningful for deck_type = 'custom_duel' -- each
                // player's own submitted decklist (see
                // submitCustomDuelDeck()), null/false for every other
                // deck_type since game_players.custom_deck_* is only ever
                // written for that one. deck_submitted lets the waiting
                // board show "waiting on Bob" without exposing Bob's own
                // decklist contents before the game starts.
                'custom_deck_name' => $row['custom_deck_name'],
                'deck_submitted' => $row['custom_deck_card_ids'] !== null,
                // Whether this seat has resigned -- see resignGame(). For
                // a 2-player/team-format game a resignation always
                // completes the whole game immediately, so this is really
                // only ever true mid-game for 'standard' format's own 3-4
                // player "game continues without them" case.
                'resigned' => $row['resigned_at'] !== null,
                // Online/presence indicator (issue #110) -- 'online',
                // 'offline', or 'hidden' (this player has opted out of
                // sharing presence at all, see users.share_presence /
                // the User info page) -- see PresenceService.
                'presence' => $presenceStatuses[(int) $row['user_id']],
            ];
        }

        // Team-format games credit the whole winning team (both
        // teammates' usernames), not just the representative
        // winner_game_player_id -- see "Open Team Play" in
        // php-app/README.md. Non-team games fall back to the single
        // winner_game_player_id.
        $winnerUsernames = [];
        if ($game['winner_team_id'] !== null) {
            foreach ($players as $player) {
                if ($player['team_id'] === (int) $game['winner_team_id']) {
                    $winnerUsernames[] = $player['username'];
                }
            }
        } elseif ($game['winner_game_player_id'] !== null) {
            foreach ($players as $player) {
                if ($player['game_player_id'] === (int) $game['winner_game_player_id']) {
                    $winnerUsernames[] = $player['username'];
                }
            }
        }

        $response = [
            'game' => [
                'id' => $gameId,
                'format' => $game['format'],
                'deck_type' => $game['deck_type'],
                'custom_deck_name' => $game['custom_deck_name'],
                'duel_deck_rules' => $game['deck_type'] === 'custom_duel' ? [
                    'preset' => $game['custom_duel_rules_preset'],
                    'min_cards' => (int) $game['custom_duel_min_cards'],
                    'rarity_limits' => (array) json_decode((string) $game['custom_duel_rarity_limits'], true),
                    'duplicate_limits' => (array) json_decode((string) $game['custom_duel_duplicate_limits'], true),
                    'even_color_distribution_rarities' => (array) json_decode((string) $game['custom_duel_even_color_distribution_rarities'], true),
                ] : null,
                'status' => $game['status'],
                'wins_needed' => (int) $game['wins_needed'],
                // "Default selections" mode (issue #274) -- a per-game
                // toggle set once at createGame() time, applying to
                // every seated player for this game's whole lifetime.
                // See withChoiceDefault()'s own docblock for what it
                // actually changes; surfaced here purely so the frontend
                // can show players it's on (per the issue's own "visible
                // to the players playing it" requirement), not because
                // choice_fields/pending_decision.field need it -- those
                // already carry a `default` key per field wherever one
                // was computed, with no client-side re-derivation needed.
                'default_selections_mode' => (bool) $game['default_selections_mode'],
                'winner_game_player_id' => $game['winner_game_player_id'] !== null ? (int) $game['winner_game_player_id'] : null,
                // Every winning username -- both teammates' for a
                // team-format win, just the one player's otherwise. Empty
                // until the game completes.
                'winner_usernames' => $winnerUsernames,
                // Only meaningful for format 'team' -- winner_game_player_id
                // above is still set too (a representative teammate, for
                // display), but this is the authoritative "which team
                // actually won" -- see "Open Team Play" in php-app/README.md.
                'winner_team_id' => $game['winner_team_id'] !== null ? (int) $game['winner_team_id'] : null,
                // Only meaningful for deck_type = 'quick_draft' -- which of
                // the match's up to 3 games this one is (1/2/3). Null for
                // every other deck_type. See the 'quick_draft' field below
                // for the match-level scoreline this drives on the frontend.
                'match_game_number' => $game['match_game_number'] !== null ? (int) $game['match_game_number'] : null,
            ],
            'players' => $players,
            'round' => null,
            'in_play' => [],
            'discard_pile' => [],
            'deck_count' => 0,
            // Only populated for format 'team'/'closed_team'.
            'teams' => null,
            // Only populated for format 'team'/'closed_team', and only
            // while a game_team_decisions row is still open -- see "Open
            // Team Play"/"Closed Team Play" in php-app/README.md.
            'team_decision' => null,
            // Only populated for format 'closed_team', and only until
            // every player has submitted their pregame blind card pass --
            // see submitInitialCardPass() and "Closed Team Play" in
            // php-app/README.md.
            'initial_card_pass' => null,
            // Only populated for a best-of-three draft match's own game
            // 2/3 (Quick Draft/Winston Draft/Grid Draft), and only while
            // round 1 is still frozen awaiting the previous game's
            // loser's own "who goes first" decision -- see
            // setPlayFirstNextMatchGame() and startGame()'s own freeze
            // for match games above.
            'first_player_decision' => null,
            // Only populated for deck_type = 'quick_draft' -- unlike every
            // other quick_draft/custom_duel-style pregame field above,
            // this is populated regardless of $game['status'] (a Quick
            // Draft match's drafting/deck_building phases both happen
            // while the game itself is still 'waiting') -- see
            // quickDraftStateFor().
            'quick_draft' => null,
            // Winston Draft's own analog of 'quick_draft' immediately
            // above -- see winstonDraftStateFor().
            'winston_draft' => null,
            // Grid Draft's (issue #188) own analog of 'quick_draft'
            // immediately above -- see gridDraftStateFor().
            'grid_draft' => null,
        ];

        if ($viewerGamePlayerId !== null) {
            // Omitted entirely for a spectator (no viewer seat to speak
            // from) -- mirrors 'closed_team' never populating
            // 'you.teammate_hand' (see this method's own docblock above).
            $response['you'] = ['game_player_id' => $viewerGamePlayerId];
        }

        if ($viewerUserId !== null) {
            if ($game['deck_type'] === 'quick_draft' && $game['draft_match_id'] !== null) {
                $response['quick_draft'] = $this->quickDraftStateFor($game, $viewerUserId);
            } elseif ($game['deck_type'] === 'winston_draft' && $game['draft_match_id'] !== null) {
                $response['winston_draft'] = $this->winstonDraftStateFor($game, $viewerUserId);
            } elseif ($game['deck_type'] === 'grid_draft' && $game['draft_match_id'] !== null) {
                $response['grid_draft'] = $this->gridDraftStateFor($game, $viewerUserId);
            }
        }

        if ($game['status'] !== 'in_progress' && $game['status'] !== 'completed') {
            return $response;
        }

        $roundStmt = $pdo->prepare(
            'SELECT * FROM game_rounds WHERE game_id = :game_id ORDER BY round_number DESC LIMIT 1'
        );
        $roundStmt->execute(['game_id' => $gameId]);
        $roundRow = $roundStmt->fetch();

        if (
            (int) $roundRow['round_number'] === 1
            && $roundRow['current_turn_game_player_id'] === null
            && $game['draft_match_id'] !== null
            && $game['match_game_number'] !== null
            && (int) $game['match_game_number'] > 1
        ) {
            // A non-null sentinel no real user_id can ever equal --
            // firstPlayerDecisionStateFor()'s own 'you_are_previous_loser'
            // comparison then correctly resolves false for a spectator
            // (who is never the previous game's loser) without widening
            // its signature just for this one nullable case.
            $response['first_player_decision'] = $this->firstPlayerDecisionStateFor($game, (int) $game['match_game_number'], $viewerUserId ?? 0);
        }

        $state = $this->boardStates->load($gameId);
        $names = $this->cardNamesFor($gameId);
        $playerNames = array_column($players, 'username', 'game_player_id');

        // A quality-of-life running total -- how many points a player
        // would score if the round ended and every in-play mood scored at
        // its current value -- so nobody has to manually add up the
        // numbers on their own board. Deliberately NOT the accumulated
        // game_round_scores total across previous rounds (that's what
        // total_wins already exists to summarize at the round-victory
        // level); this is always just a live snapshot of the board as it
        // stands right now, resetting to 0 the moment a round scores and
        // every mood leaves play. deck_count is likewise always public
        // (see the players[] entry's own comment above) and always
        // correct for a 'duel' game's own separate decks.
        foreach ($response['players'] as &$player) {
            $player['total_score'] = $this->boardPointTotalFor($state, $player['game_player_id']);
            $player['deck_count'] = $state->hasSeparateDecks()
                ? count($state->deck($player['game_player_id']))
                : count($state->deck());
            if ($revealAllHands) {
                // Every seated player's final hand -- only ever populated
                // for a completed game (see getSpectatorState() above),
                // once there's no more competitive reason to hide it.
                // reactingViewerId: null since no live play is left for
                // any choice_fields/is_playable simulation to matter.
                $player['hand'] = array_map(
                    fn (int $cardId) => $this->serializeCard($state, $cardId, $names, null),
                    $state->hand($player['game_player_id'])
                );
            }
        }
        unset($player);

        if ($roundRow !== false) {
            $currentTurnGamePlayerId = $roundRow['current_turn_game_player_id'] !== null ? (int) $roundRow['current_turn_game_player_id'] : null;
            $response['round'] = [
                'round_number' => (int) $roundRow['round_number'],
                'status' => $roundRow['status'],
                'current_turn_game_player_id' => $currentTurnGamePlayerId,
                'plays_remaining' => (int) $roundRow['plays_remaining'],
                'play_grants' => array_map(
                    fn (?array $restriction) => $this->describePlayGrant($restriction, $names),
                    $state->pendingPlayGrants(),
                ),
                'first_game_player_id' => (int) $roundRow['first_game_player_id'],
                // Who ACTUALLY took turn 1 this round -- for format 'team',
                // first_game_player_id above only identifies a
                // representative member of whichever TEAM went first (see
                // GameService::startGame()'s own comment), not necessarily
                // the player who did; this is BoardState::roundFirstPlayerId()
                // instead, the same field Chivalry/Triumph key off of (also
                // honors an Honor override, and every non-team format's own
                // first_game_player_id besides). See "Open Team Play" in
                // php-app/README.md.
                'went_first_game_player_id' => $state->roundFirstPlayerId(),
                'hurt_feelings_game_player_id' => $roundRow['hurt_feelings_game_player_id'] !== null ? (int) $roundRow['hurt_feelings_game_player_id'] : null,
                'banned_colors' => $state->bannedColorsThisRound(),
                'discarded_this_round' => (bool) $roundRow['discarded_this_round'],
                'pending_decision' => $this->serializePendingDecision((int) $roundRow['id'], $viewerGamePlayerId, $state, (bool) $game['default_selections_mode']),
                'scoring_preview' => $this->serializeScoringPreview($state, (int) $roundRow['id']),
                'scoring_effects' => $this->scoringEffectEntries($state, $names, $playerNames),
                'board_effects' => $this->boardEffectEntries($state, $names, $playerNames),
            ];
            if ($viewerGamePlayerId !== null) {
                $response['you']['is_your_turn'] = $currentTurnGamePlayerId === $viewerGamePlayerId;
            }
        }

        if ($viewerGamePlayerId !== null) {
            $response['you']['hand'] = array_map(
                fn (int $cardId) => $this->serializeCard($state, $cardId, $names, $viewerGamePlayerId, (bool) $game['default_selections_mode']),
                $state->hand($viewerGamePlayerId)
            );
        }

        if (self::isTeamFormat($game['format'])) {
            // Which player is your teammate is common to both team
            // formats (derived purely from team_id, regardless of
            // adjacent vs. across seating) -- but their HAND is only
            // exposed for 'team' (Open Team Play's "open information"
            // premise, see php-app/README.md); 'closed_team' identifies
            // the teammate the same way but never populates
            // teammate_hand, since that format's whole point is that
            // hands stay private between teammates.
            foreach ($response['players'] as $player) {
                if ($player['team_id'] === null) {
                    continue;
                }
                $isTeammate = $viewerGamePlayerId !== null
                    && $player['game_player_id'] !== $viewerGamePlayerId
                    && $this->teamIdByGamePlayer($gameId)[$viewerGamePlayerId] === $player['team_id'];
                if ($isTeammate) {
                    $response['you']['teammate_game_player_id'] = $player['game_player_id'];
                    if ($game['format'] === 'team') {
                        $response['you']['teammate_hand'] = array_map(
                            fn (int $cardId) => $this->serializeCard($state, $cardId, $names, $player['game_player_id']),
                            $state->hand($player['game_player_id'])
                        );
                    }
                    break;
                }
            }

            $teams = [
                0 => ['team_id' => 0, 'game_player_ids' => [], 'total_score' => 0, 'total_wins' => $this->totalWinsForTeam($gameId, 0)],
                1 => ['team_id' => 1, 'game_player_ids' => [], 'total_score' => 0, 'total_wins' => $this->totalWinsForTeam($gameId, 1)],
            ];
            foreach ($response['players'] as $player) {
                if ($player['team_id'] === null) {
                    continue;
                }
                $teams[$player['team_id']]['game_player_ids'][] = $player['game_player_id'];
                $teams[$player['team_id']]['total_score'] += $player['total_score'];
            }
            $response['teams'] = array_values($teams);

            $decision = $this->activeTeamDecision($gameId);
            if ($decision !== null) {
                $candidateIds = array_map(intval(...), json_decode((string) $decision['candidate_game_player_ids'], true));
                $proposerId = $decision['proposer_game_player_id'] !== null ? (int) $decision['proposer_game_player_id'] : null;
                $isViewerCandidate = $viewerGamePlayerId !== null && in_array($viewerGamePlayerId, $candidateIds, true);

                $response['team_decision'] = [
                    'decision_type' => $decision['decision_type'],
                    'team_id' => (int) $decision['team_id'],
                    'phase' => $decision['phase'],
                    'candidate_game_player_ids' => $candidateIds,
                    'proposer_game_player_id' => $proposerId,
                    'proposed_game_player_id' => $decision['proposed_game_player_id'] !== null ? (int) $decision['proposed_game_player_id'] : null,
                    'can_propose' => $decision['phase'] === 'propose' && $isViewerCandidate,
                    'can_confirm' => $decision['phase'] === 'confirm' && $isViewerCandidate && $proposerId !== $viewerGamePlayerId,
                ];
            }

            if ($game['format'] === 'closed_team') {
                $response['initial_card_pass'] = $this->pendingInitialCardPass($gameId, $viewerGamePlayerId);
            }
        }

        // Every distinct in-play card id currently backing an active,
        // not-yet-consumed play grant (BoardState::pendingPlayGrants()
        // already only returns the active ones -- see grantIsActive()) --
        // computed once here rather than per-card, since it's the same
        // set for every mood checked below. Most relevant to Hope/Grace
        // (see 'has_unused_play_grant' below), but works the same for any
        // other card's own outstanding grant that hasn't been spent yet.
        $activeGrantSourceCardIds = [];
        foreach ($state->pendingPlayGrants() as $grant) {
            if ($grant !== null && isset($grant['sourceCardId'])) {
                $activeGrantSourceCardIds[] = $grant['sourceCardId'];
            }
        }

        foreach ($state->moodsInPlay() as $cardId => $mood) {
            $serialized = $this->serializeCard($state, $cardId, $names);
            $boosterCardId = $serialized['has_dice_value'] ? $state->diceValueBoosterCardId($cardId) : null;
            $response['in_play'][] = [
                ...$serialized,
                'owner_game_player_id' => $mood->ownerId,
                'is_suppressed' => $mood->isSuppressed,
                // Whether this specific card currently has an unused play
                // grant it's responsible for -- most useful for Hope/Grace,
                // whose own grant (see BoardState::grantIsActive()) is lost
                // outright if this card leaves play before it's spent, so
                // knowing it's still "armed" actually means something.
                // Only ever true during this card's own owner's turn --
                // Hope's/Grace's perpetual bonus for a future turn doesn't
                // exist as a grant at all until computeFreshGrants() creates
                // it fresh when that turn actually starts.
                'has_unused_play_grant' => in_array($cardId, $activeGrantSourceCardIds, true),
                // A permanent one-time "after playing this mood, ... this
                // mood's value becomes N" trigger (Dignity, Delight, ...)
                // has locked its value in via BoardState::setValueOverride()
                // -- as opposed to a "while in play" card (Determination)
                // whose value keeps being recomputed live by valueOf() and
                // never touches 'valueOverride' at all. The frontend uses
                // this to distinguish the two visually (see "Card art
                // rendering" in web-static/README.md).
                'value_locked' => array_key_exists('valueOverride', $mood->effectState),
                'suppression_expiry' => $mood->suppressionExpiry,
                'suppressed_by_card_id' => $mood->suppressionSourceCardId,
                'suppressed_by_name' => $mood->suppressionSourceCardId !== null
                    ? ($names[$mood->suppressionSourceCardId] ?? null)
                    : null,
                'boosted_by_card_id' => $boosterCardId,
                'boosted_by_name' => $boosterCardId !== null ? ($names[$boosterCardId] ?? null) : null,
                'affecting' => $this->affectingEntries($state, $cardId, $names),
                'temporary_ownership' => $this->temporaryOwnershipInfo($state, $cardId, $names, $playerNames),
                // Only Bliss's own effectState carries anything worth
                // surfacing this way -- the color of the card discarded to
                // pay its cost, remembered once at play time (see
                // BlissEffect::payToPlayCost()) -- so every other card's
                // detail view just reads this as null/absent.
                'bliss_discard_color' => $serialized['effect_key'] === 'bliss' ? $state->effectState($cardId, 'blissColor') : null,
            ];
        }

        // $viewerGamePlayerId (not omitted, the way in_play's own mapping
        // above still is) so is_playable/choice_fields'/reaction fields
        // reflect the rare case a discard-sourced play grant (Angst,
        // Harmony, Grief) or Melancholy's blanket "play from the discard
        // pile as though it were your hand" actually covers one of these
        // cards right now -- see MoodPlayService::isPlayable() and
        // BoardState::grantAllows()'s 'source' => 'discard' handling.
        $response['discard_pile'] = array_map(
            function (int $cardId) use ($state, $names, $viewerGamePlayerId, $playerNames, $game): array {
                $lastOwnerId = $state->discardOwnerOf($cardId);
                return [
                    ...$this->serializeCard($state, $cardId, $names, $viewerGamePlayerId, (bool) $game['default_selections_mode']),
                    // Two players' identical catalog cards can both sit in
                    // the discard pile at once (a 'duel' game gives each
                    // player their own deck -- see BoardState::
                    // $catalogCardIdFor), so a bare name alone can't tell
                    // them apart -- e.g. Corruption's own discard_card_ids
                    // choice, which bottoms each cycled card onto its
                    // *owner's* deck, so which physical card you mean
                    // genuinely matters, not just its printed identity.
                    'last_owner_game_player_id' => $lastOwnerId,
                    'last_owner_name' => $lastOwnerId !== null ? ($playerNames[$lastOwnerId] ?? null) : null,
                ];
            },
            $state->discardPile()
        );

        // The viewer's own deck's remaining count -- in a 'duel' game this
        // is specifically *their* deck, not their opponent's (the two are
        // never the same number once either player has drawn a different
        // count of cards); in a shared-deck game every player's own key
        // resolves to the same single pool anyway, so this is unchanged
        // from before per-player decks existed. Meaningless for a
        // spectator (no seat of their own) -- players[].deck_count above
        // is what a spectator reads instead.
        $response['deck_count'] = $viewerGamePlayerId !== null ? count($state->deck($viewerGamePlayerId)) : 0;
        $response['recent_events'] = $this->recentEvents($gameId, $players);

        // In-game chat (issue #109) -- seated players only, same as
        // game_notes above (a spectator, $viewerGamePlayerId === null,
        // gets none, never even 'table'-channel messages -- unlike
        // recent_events/game_events, which a spectator CAN already see
        // via GET /games/log's own canSpectateGame() gate). $viewerTeamId
        // (null for every non-team format, or a team-format seat somehow
        // missing one) is read straight off $players, already built above
        // -- no extra query needed.
        if ($viewerGamePlayerId !== null) {
            $viewerTeamId = null;
            foreach ($players as $player) {
                if ($player['game_player_id'] === $viewerGamePlayerId) {
                    $viewerTeamId = $player['team_id'];
                    break;
                }
            }
            $response['chat_messages'] = $this->chatMessagesFor($gameId, $viewerTeamId);
        } else {
            $response['chat_messages'] = [];
        }

        return $response;
    }

    /**
     * The entire game_events log for $gameId, oldest first (issue #98) --
     * unlike recentEvents() below, this is deliberately unbounded and
     * unpaginated: a typical game's event count (rarely more than a few
     * hundred rows even for a long multiplayer game) is small enough that
     * returning the whole thing in one response is simpler than adding
     * pagination for a case that doesn't actually need it. Reuses the same
     * describeEvent() rendering recentEvents() does (so the two views can
     * never drift out of phrasing sync), but each entry additionally
     * carries the raw event_type/round_number/acting_game_player_id/
     * card_id/details alongside the resolved acting_username/card_name --
     * recentEvents()'s own {id, created_at, description} shape only ever
     * needs to be displayed, but this is also the one response the
     * frontend's "download data" button (see "Game log" in
     * web-static/README.md) turns directly into a JSON export, so it's
     * worth including enough raw data for that export to be genuinely
     * useful offline, not just a repeat of the human-readable text the
     * "download text"/"copy text" buttons already cover. No per-viewer
     * filtering, same as recentEvents() -- every event is already visible
     * to every seated player regardless of who originally triggered it
     * (see recentEvents()'s own docblock re: Paranoia/Curiosity reveals),
     * so this only needs requireGamePlayer()'s seated-player check, not
     * anything further.
     *
     * @return array<int, array{id:int, created_at:string, round_number:?int, event_type:string, acting_game_player_id:?int, acting_username:?string, card_id:?int, card_name:?string, details:array<string,mixed>, description:string}>
     */
    public function fullEventLog(int $gameId): array
    {
        $pdo = Connection::get();

        // Once a game is 'completed', every hand is already fully revealed
        // to any spectator via getSpectatorState()'s own revealAllHands, so
        // there's nothing left to hide -- see drawCard()/$pendingDraws' own
        // docblock for why draws stay anonymous (card_id redacted below)
        // for every other status.
        $isCompleted = $this->fetchGame($gameId)['status'] === 'completed';

        $playersStmt = $pdo->prepare(
            'SELECT gp.id, gp.team_id, u.username FROM game_players gp
             JOIN users u ON u.id = gp.user_id WHERE gp.game_id = :game_id'
        );
        $playersStmt->execute(['game_id' => $gameId]);
        $playerRows = $playersStmt->fetchAll();

        $playerNames = array_column($playerRows, 'username', 'id');
        $cardNames = $this->cardNamesFor($gameId);

        // Only populated for format 'team' -- see recentEvents()'s own
        // identical construction below.
        $teamMembersByTeamId = [];
        foreach ($playerRows as $row) {
            if ($row['team_id'] !== null) {
                $teamMembersByTeamId[(int) $row['team_id']][] = $row['username'];
            }
        }

        $stmt = $pdo->prepare(
            'SELECT e.id, e.event_type, e.acting_game_player_id, e.card_id, e.details, e.created_at, r.round_number
             FROM game_events e
             LEFT JOIN game_rounds r ON r.id = e.game_round_id
             WHERE e.game_id = :game_id ORDER BY e.id ASC'
        );
        $stmt->execute(['game_id' => $gameId]);

        return array_map(
            function (array $row) use ($playerNames, $cardNames, $teamMembersByTeamId, $isCompleted): array {
                $actingId = $row['acting_game_player_id'] !== null ? (int) $row['acting_game_player_id'] : null;
                $cardId = $row['card_id'] !== null ? (int) $row['card_id'] : null;
                $details = $row['details'] !== null ? json_decode((string) $row['details'], true) : [];
                if (!$isCompleted && isset($details['draws'])) {
                    $details['draws'] = array_map(
                        static fn (array $draw): array => ['player_id' => $draw['player_id']],
                        $details['draws'],
                    );
                }

                return [
                    'id' => (int) $row['id'],
                    'created_at' => $row['created_at'],
                    'round_number' => $row['round_number'] !== null ? (int) $row['round_number'] : null,
                    'event_type' => $row['event_type'],
                    'acting_game_player_id' => $actingId,
                    'acting_username' => $actingId !== null ? ($playerNames[$actingId] ?? null) : null,
                    'card_id' => $cardId,
                    'card_name' => $cardId !== null ? ($cardNames[$cardId] ?? null) : null,
                    'details' => $details,
                    'description' => $this->describeEvent($row, $playerNames, $cardNames, $teamMembersByTeamId),
                ];
            },
            $stmt->fetchAll(),
        );
    }

    /**
     * Every column this app has ever added a JSON-typed column for, keyed
     * by table -- MySQL's JSON column type has no native PDO binding, so
     * every such value comes back from a plain `SELECT *` as an
     * escaped-string blob rather than a real nested value. Listed
     * explicitly (rather than heuristically decoding any string that
     * merely starts with '{'/'[') so a free-text column that happens to
     * look like JSON -- game_notes.note_text, most plausibly -- is never
     * silently reinterpreted; only exportGameData() below reads this map.
     */
    private const JSON_COLUMNS_BY_TABLE = [
        'games' => ['custom_deck_card_ids', 'custom_duel_rarity_limits', 'custom_duel_duplicate_limits', 'custom_duel_even_color_distribution_rarities'],
        'game_players' => ['custom_deck_card_ids'],
        'game_rounds' => ['pending_play_grants'],
        'game_cards' => ['effect_state'],
        'game_events' => ['details'],
        'game_pending_decision_batches' => ['top_level_choices', 'invocation_choices'],
        'game_pending_decisions' => ['field', 'answer'],
        'game_team_decisions' => ['candidate_game_player_ids'],
        'game_initial_card_passes' => ['card_ids'],
        'draft_matches' => ['pool_card_ids'],
        'draft_match_players' => ['drafted_card_ids', 'deck_card_ids'],
        'draft_round_picks' => ['drawn_card_ids'],
        'draft_pile_stage_picks' => ['kept_card_ids'],
        'draft_winston_state' => ['remaining_deck_card_ids', 'pile_1_card_ids', 'pile_2_card_ids', 'pile_3_card_ids'],
        'draft_grid_state' => ['remaining_deck_card_ids', 'grid_card_ids'],
    ];

    /**
     * Every DB row related to $gameId, across every table with any FK
     * relationship (direct or transitive) to `games.id` (issue #99) -- a
     * raw, complete data dump meant for offline archiving before a game's
     * data is eventually cleaned up (see issue #84), not the
     * human-readable view fullEventLog() already provides (issue #98):
     * that one is curated/rendered for display; this one preserves
     * everything a player might want to keep, including internal
     * bookkeeping (pending-decision batches, team decisions, the initial
     * blind card pass) fullEventLog() never surfaces at all. Every
     * JSON-typed column is decoded into a real nested value rather than
     * left as an escaped string -- see JSON_COLUMNS_BY_TABLE -- so the
     * exported file reads as ordinary nested JSON, not JSON-inside-JSON.
     *
     * $requestingGamePlayerId scopes `game_notes` to only the requesting
     * player's own note, never another seated player's -- game_notes is a
     * deliberately private per-seat scratchpad (see GameNoteRepository),
     * and an export triggered by one player has no business revealing
     * what a DIFFERENT player privately wrote to themselves. It similarly
     * scopes `game_chat_messages` (issue #109) to exclude any 'team'-
     * channel message belonging to the opposing team -- 'table'-channel
     * messages are exported in full, same as everyone already sees them
     * live.
     *
     * Quick/Winston/Grid Draft games additionally carry a `draft_match`
     * section (via `games.draft_match_id`). A single `draft_matches` row
     * is shared across up to 3 `games` rows in a best-of-three match (see
     * migration 0027's own docblock), so this section reflects the WHOLE
     * match's draft history, not just $gameId's own slice of it -- the
     * sibling games' own `games`/`game_players`/etc rows are never
     * included here, only $gameId's.
     *
     * @return array<string, mixed>
     */
    public function exportGameData(int $gameId, int $requestingGamePlayerId): array
    {
        $pdo = Connection::get();
        $game = $this->decodeJsonColumns($this->fetchGame($gameId), 'games');

        $notesStmt = $pdo->prepare('SELECT * FROM game_notes WHERE game_player_id = :game_player_id ORDER BY id ASC');
        $notesStmt->execute(['game_player_id' => $requestingGamePlayerId]);
        $notes = array_map(fn (array $row) => $this->decodeJsonColumns($row, 'game_notes'), $notesStmt->fetchAll());

        // In-game chat (issue #109) needs the same care game_notes above
        // already gets: an export triggered by one player has no business
        // revealing a 'team'-channel message sent to the OTHER team --
        // fetchAllForGame() below would return every row unfiltered, so
        // this uses the same WHERE clause chatMessagesFor()/
        // GameChatRepository::messagesFor() already apply on every poll,
        // rather than that helper.
        $requesterTeamIdStmt = $pdo->prepare('SELECT team_id FROM game_players WHERE id = :id');
        $requesterTeamIdStmt->execute(['id' => $requestingGamePlayerId]);
        $rawRequesterTeamId = $requesterTeamIdStmt->fetchColumn();
        $requesterTeamId = $rawRequesterTeamId !== null ? (int) $rawRequesterTeamId : null;

        $chatStmt = $pdo->prepare(
            "SELECT * FROM game_chat_messages
             WHERE game_id = :game_id AND (channel = 'table' OR (channel = 'team' AND team_id = :viewer_team_id))
             ORDER BY id ASC"
        );
        $chatStmt->execute(['game_id' => $gameId, 'viewer_team_id' => $requesterTeamId]);
        $chatMessages = array_map(fn (array $row) => $this->decodeJsonColumns($row, 'game_chat_messages'), $chatStmt->fetchAll());

        $roundScoresStmt = $pdo->prepare(
            'SELECT s.* FROM game_round_scores s
             JOIN game_rounds r ON r.id = s.game_round_id
             WHERE r.game_id = :game_id ORDER BY s.id ASC'
        );
        $roundScoresStmt->execute(['game_id' => $gameId]);

        $pendingDecisionsStmt = $pdo->prepare(
            'SELECT d.* FROM game_pending_decisions d
             JOIN game_pending_decision_batches b ON b.id = d.batch_id
             WHERE b.game_id = :game_id ORDER BY d.id ASC'
        );
        $pendingDecisionsStmt->execute(['game_id' => $gameId]);

        $draftMatchId = $game['draft_match_id'] !== null ? (int) $game['draft_match_id'] : null;
        $draftMatch = null;
        $draftMatchPlayers = [];
        $draftRoundPicks = [];
        $draftPileStagePicks = [];
        $draftWinstonState = null;
        $draftGridState = null;
        if ($draftMatchId !== null) {
            $matchStmt = $pdo->prepare('SELECT * FROM draft_matches WHERE id = :id');
            $matchStmt->execute(['id' => $draftMatchId]);
            $matchRow = $matchStmt->fetch();
            $draftMatch = $matchRow !== false ? $this->decodeJsonColumns($matchRow, 'draft_matches') : null;

            $draftMatchPlayers = $this->fetchAllForDraftMatch($pdo, 'draft_match_players', $draftMatchId);
            $draftRoundPicks = $this->fetchAllForDraftMatch($pdo, 'draft_round_picks', $draftMatchId);
            $draftPileStagePicks = $this->fetchAllForDraftMatch($pdo, 'draft_pile_stage_picks', $draftMatchId);
            $draftWinstonState = $this->fetchOneForDraftMatch($pdo, 'draft_winston_state', $draftMatchId);
            $draftGridState = $this->fetchOneForDraftMatch($pdo, 'draft_grid_state', $draftMatchId);
        }

        return [
            'exported_at' => gmdate('c'),
            'game' => $game,
            'game_players' => $this->fetchAllForGame($pdo, 'game_players', $gameId),
            'game_rounds' => $this->fetchAllForGame($pdo, 'game_rounds', $gameId),
            'game_round_scores' => array_map(fn (array $row) => $this->decodeJsonColumns($row, 'game_round_scores'), $roundScoresStmt->fetchAll()),
            'game_cards' => $this->fetchAllForGame($pdo, 'game_cards', $gameId),
            'game_events' => $this->fetchAllForGame($pdo, 'game_events', $gameId),
            'game_chat_messages' => $chatMessages,
            'game_pending_decision_batches' => $this->fetchAllForGame($pdo, 'game_pending_decision_batches', $gameId),
            'game_pending_decisions' => array_map(fn (array $row) => $this->decodeJsonColumns($row, 'game_pending_decisions'), $pendingDecisionsStmt->fetchAll()),
            'game_team_decisions' => $this->fetchAllForGame($pdo, 'game_team_decisions', $gameId),
            'game_initial_card_passes' => $this->fetchAllForGame($pdo, 'game_initial_card_passes', $gameId),
            'game_notes' => $notes,
            'draft_match' => $draftMatch,
            'draft_match_players' => $draftMatchPlayers,
            'draft_round_picks' => $draftRoundPicks,
            'draft_pile_stage_picks' => $draftPileStagePicks,
            'draft_winston_state' => $draftWinstonState,
            'draft_grid_state' => $draftGridState,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAllForGame(PDO $pdo, string $table, int $gameId): array
    {
        // $table is always one of this method's own hardcoded call sites
        // above, never externally supplied, so interpolating it directly
        // into the query carries no injection risk.
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE game_id = :game_id ORDER BY id ASC");
        $stmt->execute(['game_id' => $gameId]);

        return array_map(fn (array $row) => $this->decodeJsonColumns($row, $table), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAllForDraftMatch(PDO $pdo, string $table, int $draftMatchId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE draft_match_id = :draft_match_id ORDER BY id ASC");
        $stmt->execute(['draft_match_id' => $draftMatchId]);

        return array_map(fn (array $row) => $this->decodeJsonColumns($row, $table), $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    private function fetchOneForDraftMatch(PDO $pdo, string $table, int $draftMatchId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE draft_match_id = :draft_match_id");
        $stmt->execute(['draft_match_id' => $draftMatchId]);
        $row = $stmt->fetch();

        return $row !== false ? $this->decodeJsonColumns($row, $table) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeJsonColumns(array $row, string $table): array
    {
        foreach (self::JSON_COLUMNS_BY_TABLE[$table] ?? [] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $row[$key] = json_decode($row[$key], true);
            }
        }

        return $row;
    }

    /**
     * Issue #240 "watch game replay": the board exactly as it looked
     * immediately after event $eventId finished, for a COMPLETED game only
     * -- see ReplayStateBuilder's own docblock for how the historical
     * BoardState itself gets reconstructed (full forward replay of
     * recorded facts, never re-executed effect code). $eventId of 0 is
     * genesis -- round-1's dealt hands, before any event exists -- see
     * ReplayStateBuilder::stateAsOf()'s own docblock for that sentinel.
     * Authorization (seated player or canSpectateGame()) is the caller's
     * own job, identical to fullEventLog()'s -- see the GET
     * /games/replay/state route.
     *
     * @return array<string, mixed>
     */
    public function replayStateAsOf(int $gameId, int $eventId): array
    {
        $state = $this->replay->stateAsOf($gameId, $eventId);

        return $this->serializeReplaySnapshot($gameId, $eventId, $state);
    }

    /**
     * Same top-level shape getSpectatorState()/buildGameState() return
     * (so renderBoard() needs zero frontend changes to show it), but built
     * from a ReplayStateBuilder-reconstructed $state instead of the live
     * BoardStateRepository::load() + a live game_rounds row -- there IS no
     * "current round" for a specific past event, so every field that only
     * makes sense for an in-progress turn (current_turn_game_player_id,
     * pending_decision, plays_remaining, play_grants, team_decision,
     * initial_card_pass, first_player_decision, quick_draft/winston_draft/
     * grid_draft) is nulled out here rather than reused from
     * buildGameState() (confirmed too coupled to those live concepts to
     * share directly). 'you' always has a null game_player_id, matching
     * getSpectatorState()'s own "not a seated viewer" shape -- replay never
     * has a viewer of its own, seated or not. Every hand is always
     * revealed, same as a completed game's own spectator view.
     *
     * @return array<string, mixed>
     */
    private function serializeReplaySnapshot(int $gameId, int $eventId, BoardState $state): array
    {
        $game = $this->fetchGame($gameId);
        $pdo = Connection::get();

        $playersStmt = $pdo->prepare(
            'SELECT gp.id, gp.user_id, gp.seat_order, gp.team_id, gp.custom_deck_name, gp.custom_deck_card_ids, gp.resigned_at, u.username FROM game_players gp
             JOIN users u ON u.id = gp.user_id
             WHERE gp.game_id = :game_id ORDER BY gp.seat_order ASC'
        );
        $playersStmt->execute(['game_id' => $gameId]);
        $playerRows = $playersStmt->fetchAll();

        $names = $this->cardNamesFor($gameId);

        $winnerUsernames = [];
        $players = [];
        foreach ($playerRows as $row) {
            $players[] = [
                'game_player_id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'username' => $row['username'],
                'seat_order' => (int) $row['seat_order'],
                'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
                'hand_count' => count($state->hand((int) $row['id'])),
                'total_wins' => $this->totalWinsFor($gameId, (int) $row['id']),
                'total_score' => $this->boardPointTotalFor($state, (int) $row['id']),
                'deck_count' => $state->hasSeparateDecks() ? count($state->deck((int) $row['id'])) : count($state->deck()),
                'custom_deck_name' => $row['custom_deck_name'],
                'deck_submitted' => $row['custom_deck_card_ids'] !== null,
                'resigned' => $row['resigned_at'] !== null,
                'hand' => array_map(
                    fn (int $cardId) => $this->serializeCard($state, $cardId, $names, null),
                    $state->hand((int) $row['id']),
                ),
            ];
            if ($game['winner_team_id'] !== null && (int) $row['team_id'] === (int) $game['winner_team_id']) {
                $winnerUsernames[] = $row['username'];
            } elseif ($game['winner_game_player_id'] !== null && (int) $row['id'] === (int) $game['winner_game_player_id']) {
                $winnerUsernames[] = $row['username'];
            }
        }
        $playerNames = array_column($players, 'username', 'game_player_id');

        $roundNumberStmt = $pdo->prepare(
            'SELECT r.round_number FROM game_events e LEFT JOIN game_rounds r ON r.id = e.game_round_id WHERE e.id = :event_id'
        );
        $roundNumberStmt->execute(['event_id' => $eventId]);
        $roundNumber = $roundNumberStmt->fetchColumn();

        $teams = null;
        if (self::isTeamFormat($game['format'])) {
            $teamTotals = [
                0 => ['team_id' => 0, 'game_player_ids' => [], 'total_score' => 0, 'total_wins' => $this->totalWinsForTeam($gameId, 0)],
                1 => ['team_id' => 1, 'game_player_ids' => [], 'total_score' => 0, 'total_wins' => $this->totalWinsForTeam($gameId, 1)],
            ];
            foreach ($players as $player) {
                if ($player['team_id'] === null) {
                    continue;
                }
                $teamTotals[$player['team_id']]['game_player_ids'][] = $player['game_player_id'];
                $teamTotals[$player['team_id']]['total_score'] += $player['total_score'];
            }
            $teams = array_values($teamTotals);
        }

        return [
            'game' => [
                'id' => $gameId,
                'format' => $game['format'],
                'deck_type' => $game['deck_type'],
                'custom_deck_name' => $game['custom_deck_name'],
                'duel_deck_rules' => $game['deck_type'] === 'custom_duel' ? [
                    'preset' => $game['custom_duel_rules_preset'],
                    'min_cards' => (int) $game['custom_duel_min_cards'],
                    'rarity_limits' => (array) json_decode((string) $game['custom_duel_rarity_limits'], true),
                    'duplicate_limits' => (array) json_decode((string) $game['custom_duel_duplicate_limits'], true),
                    'even_color_distribution_rarities' => (array) json_decode((string) $game['custom_duel_even_color_distribution_rarities'], true),
                ] : null,
                'status' => $game['status'],
                'wins_needed' => (int) $game['wins_needed'],
                'winner_game_player_id' => $game['winner_game_player_id'] !== null ? (int) $game['winner_game_player_id'] : null,
                'winner_usernames' => $winnerUsernames,
                'winner_team_id' => $game['winner_team_id'] !== null ? (int) $game['winner_team_id'] : null,
                'match_game_number' => $game['match_game_number'] !== null ? (int) $game['match_game_number'] : null,
            ],
            'players' => $players,
            'you' => ['game_player_id' => null],
            'round' => [
                'round_number' => $roundNumber !== false && $roundNumber !== null ? (int) $roundNumber : null,
                'status' => null,
                'current_turn_game_player_id' => null,
                'plays_remaining' => 0,
                'play_grants' => [],
                'first_game_player_id' => null,
                'went_first_game_player_id' => null,
                'hurt_feelings_game_player_id' => null,
                'banned_colors' => [],
                'discarded_this_round' => false,
                'pending_decision' => null,
                'scoring_preview' => null,
                'scoring_effects' => $this->scoringEffectEntries($state, $names, $playerNames),
                'board_effects' => $this->boardEffectEntries($state, $names, $playerNames),
            ],
            'in_play' => array_map(
                function (int $cardId) use ($state, $names, $playerNames): array {
                    $mood = $state->moodsInPlay()[$cardId];
                    $serialized = $this->serializeCard($state, $cardId, $names, null);
                    $boosterCardId = $serialized['has_dice_value'] ? $state->diceValueBoosterCardId($cardId) : null;

                    return [
                        ...$serialized,
                        'owner_game_player_id' => $mood->ownerId,
                        'is_suppressed' => $mood->isSuppressed,
                        'has_unused_play_grant' => false,
                        'value_locked' => array_key_exists('valueOverride', $mood->effectState),
                        'suppression_expiry' => $mood->suppressionExpiry,
                        'suppressed_by_card_id' => $mood->suppressionSourceCardId,
                        'suppressed_by_name' => $mood->suppressionSourceCardId !== null
                            ? ($names[$mood->suppressionSourceCardId] ?? null)
                            : null,
                        'boosted_by_card_id' => $boosterCardId,
                        'boosted_by_name' => $boosterCardId !== null ? ($names[$boosterCardId] ?? null) : null,
                        'affecting' => $this->affectingEntries($state, $cardId, $names),
                        'temporary_ownership' => $this->temporaryOwnershipInfo($state, $cardId, $names, $playerNames),
                        'bliss_discard_color' => $serialized['effect_key'] === 'bliss' ? $state->effectState($cardId, 'blissColor') : null,
                    ];
                },
                array_keys($state->moodsInPlay()),
            ),
            'discard_pile' => array_map(
                function (int $cardId) use ($state, $names, $playerNames): array {
                    $lastOwnerId = $state->discardOwnerOf($cardId);

                    return [
                        ...$this->serializeCard($state, $cardId, $names, null),
                        'last_owner_game_player_id' => $lastOwnerId,
                        'last_owner_name' => $lastOwnerId !== null ? ($playerNames[$lastOwnerId] ?? null) : null,
                    ];
                },
                $state->discardPile(),
            ),
            'deck_count' => 0,
            'recent_events' => $this->recentEvents($gameId, $players, 15, $eventId),
            'teams' => $teams,
            'team_decision' => null,
            'initial_card_pass' => null,
            'first_player_decision' => null,
            'quick_draft' => null,
            'winston_draft' => null,
            'grid_draft' => null,
        ];
    }

    /**
     * Every card in a shared-deck game's single deck (issue #197) --
     * every deck_type where the whole table draws from one pool rather
     * than each player having their own, see isSharedDeckType(). Read
     * back from the game's own persisted game_cards rows across every
     * zone (deck/hand/in_play/discard combined = the entire deck, since
     * every row is created once at startGame() time and nothing is ever
     * added to game_cards afterward -- see BoardStateRepository's own
     * docblock), NOT recomputed by re-calling buildStructureDeckCardIds()
     * etc. -- those pick randomly, so a second call after the game
     * started would show a different random deck than the one actually
     * dealt, not the real one. ('custom''s own custom_deck_card_ids is
     * already stable without this, but reading game_cards uniformly here
     * is simpler than special-casing it.)
     *
     * Sorted by color (white/blue/black/red/green -- JCEDDYS_75_DECK_COLORS
     * happens to already be exactly this order, reused rather than
     * duplicated) then alphabetically by name within a color, so the same
     * deck always lists the same way regardless of what order its cards
     * happened to get dealt/shuffled in. Duplicate catalog ids (e.g. a
     * jceddys_75 deck's own up-to-2-copies-per-card slots -- see
     * randomCardIdsWithCopyLimit()) appear as one thumbnail per copy, the
     * same convention openDeckView() already uses for a saved decklist,
     * rather than a single entry with a count badge.
     *
     * No per-viewer filtering, same as fullEventLog() immediately above --
     * every card in the deck is equally visible to every seated player,
     * so this only needs requireGamePlayer()'s seated-player check, not
     * anything further.
     *
     * @return array<int, array<string, mixed>>
     */
    public function viewSharedDeck(int $gameId): array
    {
        $game = $this->fetchGame($gameId);
        if (!self::isSharedDeckType($game['deck_type'])) {
            throw new GameStateException("Game {$gameId}'s deck_type \"{$game['deck_type']}\" has no single shared deck to view -- each player has their own");
        }
        if ($game['status'] === 'waiting') {
            throw new GameStateException("Game {$gameId} hasn't started yet -- its deck hasn't been dealt");
        }

        $stmt = Connection::get()->prepare('SELECT card_id FROM game_cards WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);
        $cardIds = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));

        $cards = CardCatalog::serialize($cardIds);
        usort(
            $cards,
            static fn (array $a, array $b): int => [array_search($a['color'], self::JCEDDYS_75_DECK_COLORS, true), $a['name']]
                <=> [array_search($b['color'], self::JCEDDYS_75_DECK_COLORS, true), $b['name']]
        );

        return $cards;
    }

    /**
     * The last few plays/passes/rounds-scored for this game, newest first,
     * as ready-to-display strings -- a "smallish panel" alternative to
     * building out a full game-history view, specifically so a player who
     * wasn't the one who played Paranoia or Curiosity has some way to find
     * out what got revealed (see BoardState::recordRevealedCard()) instead
     * of that information being visible only in the instant it happened,
     * to only the one player who happened to submit that request.
     * Deliberately built server-side rather than handing the client raw
     * event rows to interpret -- describeEvent() is the one place that
     * needs to know each event_type's/detail shape, rather than
     * duplicating that knowledge into game.js too.
     *
     * @param array<int, array{game_player_id:int, username:string}> $players
     * @return array<int, array{id:int, created_at:string, description:string}>
     */
    /**
     * $upToEventId (issue #240 replay) caps this to the events a viewer
     * would actually have seen up through that specific point in history,
     * rather than the game's own most-recent 15 overall -- so a replay
     * step's own "Recent plays" section matches exactly what a live player
     * would have seen at that moment, not spoilers from later in the game.
     * Genesis's sentinel event id of 0 (see ReplayStateBuilder::stateAsOf())
     * naturally yields no rows here (`id <= 0` matches nothing), correctly
     * showing nothing has happened yet.
     */
    private function recentEvents(int $gameId, array $players, int $limit = 15, ?int $upToEventId = null): array
    {
        $sql = 'SELECT id, event_type, acting_game_player_id, card_id, details, created_at
                 FROM game_events WHERE game_id = :game_id';
        if ($upToEventId !== null) {
            $sql .= ' AND id <= :up_to_event_id';
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit';
        $stmt = Connection::get()->prepare($sql);
        $stmt->bindValue('game_id', $gameId, PDO::PARAM_INT);
        if ($upToEventId !== null) {
            $stmt->bindValue('up_to_event_id', $upToEventId, PDO::PARAM_INT);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $playerNames = array_column($players, 'username', 'game_player_id');
        $cardNames = $this->cardNamesFor($gameId);

        // Only populated for format 'team' -- team_id => [teammate names],
        // used solely for describeRoundScored()'s own team-aware phrasing,
        // since a team win means BOTH teammates, not one representative.
        $teamMembersByTeamId = [];
        foreach ($players as $player) {
            if (($player['team_id'] ?? null) !== null) {
                $teamMembersByTeamId[$player['team_id']][] = $player['username'];
            }
        }

        return array_map(
            fn (array $row) => [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'],
                'description' => $this->describeEvent($row, $playerNames, $cardNames, $teamMembersByTeamId),
            ],
            $stmt->fetchAll(),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $playerNames
     * @param array<int, string> $cardNames
     * @param array<int, string[]> $teamMembersByTeamId team_id => [teammate names], only for format 'team'
     */
    private function describeEvent(array $row, array $playerNames, array $cardNames, array $teamMembersByTeamId = []): string
    {
        $actor = $row['acting_game_player_id'] !== null ? ($playerNames[(int) $row['acting_game_player_id']] ?? 'A player') : 'A player';
        $cardName = $row['card_id'] !== null ? ($cardNames[(int) $row['card_id']] ?? 'a card') : 'a card';
        $details = $row['details'] !== null ? json_decode((string) $row['details'], true) : [];

        // BoardState::$pendingCardMoves' own docblock explains why entering
        // play is deliberately never one of the moves it records -- this is
        // the one place that fact still gets said, reading the mood's own
        // persisted 'playedFromZone' tag (see withPlayedFrom()) instead.
        // Only ever set on the two event types that actually announce a
        // play -- a scoring-time pending_decision_created (Enthusiasm/
        // Passion) is never about a card just entering play, so this stays
        // silent there even though it shares the same event_type.
        $playedFrom = $details['played_from'] ?? null;
        $playedFromSuffix = $playedFrom !== null ? " from {$playedFrom}" : '';

        // BoardState::$pendingGrantUsed's own docblock: only ever set when
        // the play actually consumed a genuinely granted extra play, never
        // for the ordinary base allowance every turn already starts with
        // -- so this stays empty for a plain first play of the turn, the
        // same way $playedFromSuffix stays empty for played_from's own
        // absence. Same two event types as $playedFromSuffix, for the same
        // reason (only those two ever announce a card actually being played).
        $grantUsed = $details['grant_used'] ?? null;
        $grantUsedSuffix = $grantUsed !== null ? ' (using ' . $this->describeGrantDetails($grantUsed, $cardNames) . ')' : '';

        // A scoring-time pending_decision_created (Enthusiasm/Passion,
        // tagged via 'scoring_trigger' at the two writeScoringDecisionBatch()
        // call sites) is never a fresh play -- the card triggering it has
        // already been sitting in play since some earlier turn -- so it
        // gets its own phrasing instead of the "{actor} played {card}..."
        // template every other pending_decision_created shares, which would
        // otherwise misleadingly read as though the player just played a
        // second copy of the same card.
        $description = match (true) {
            $row['event_type'] === 'mood_played' => "{$actor} played {$cardName}{$playedFromSuffix}{$grantUsedSuffix}",
            $row['event_type'] === 'turn_passed' => "{$actor} passed",
            $row['event_type'] === 'pending_decision_created' && ($details['scoring_trigger'] ?? false) => "{$cardName}'s scoring effect triggered, waiting on a response from {$actor}",
            $row['event_type'] === 'pending_decision_created' => "{$actor} played {$cardName}{$playedFromSuffix}{$grantUsedSuffix}, waiting on a response",
            $row['event_type'] === 'pending_decision_resolved' => "A response to {$cardName} was resolved",
            $row['event_type'] === 'round_scored' => $this->describeRoundScored($details, $playerNames, $teamMembersByTeamId),
            $row['event_type'] === 'team_turn_order_decided' => "{$actor} was chosen by their team to take this turn",
            $row['event_type'] === 'team_draw_recipient_decided' => "The losing team chose {$actor} to draw their shared card",
            $row['event_type'] === 'draft_match_first_player_decided' => "{$actor} will go first this game",
            // Issue #84's cleanup cron (expireStaleActiveGames()) --
            // acting_game_player_id/card_id are both null for this event,
            // so it needs its own phrasing rather than falling through to
            // the default "{actor} played {card}" template below, which
            // would otherwise misleadingly render as "A player played a
            // card".
            $row['event_type'] === 'game_expired' => 'This game was automatically ended due to inactivity',
            // resignFromDraftMatch()'s own event -- card_id is always
            // null (there's no card involved), so this needs its own
            // phrasing for the same reason 'game_expired' above does.
            $row['event_type'] === 'draft_resigned' => "{$actor} resigned from the draft",
            default => "{$actor} played {$cardName}{$playedFromSuffix}{$grantUsedSuffix}",
        };

        // Paranoia/Curiosity's own reveal -- see BoardState::
        // recordRevealedCard()'s docblock for why this is the one detail
        // that has to be spelled out explicitly rather than left for the
        // choices already folded into $details to explain on their own.
        $revealedCardIds = $details['revealed_card_ids'] ?? [];
        if ($revealedCardIds !== []) {
            $revealedNames = array_map(fn (int $id) => $cardNames[$id] ?? 'a card', $revealedCardIds);
            $targetName = isset($details['target_player_id']) ? ($playerNames[(int) $details['target_player_id']] ?? 'a player') : 'a player';
            $description .= ', revealing ' . implode(', ', $revealedNames) . " from {$targetName}'s hand";
        }

        // Every other choice actually submitted for this play/response --
        // a target player, a chosen mood/hand card, a color, a mode string,
        // and so on. Only mood_played/pending_decision_created/
        // pending_decision_resolved ever have anything worth describing
        // here (round_scored's own details are fully handled by
        // describeRoundScored() above, and mood_played's own
        // 'revealed_card_ids' is already spoken for above).
        if (in_array($row['event_type'], ['mood_played', 'pending_decision_created', 'pending_decision_resolved'], true)) {
            $choiceSummary = $this->describeChoices($details, $playerNames, $cardNames);
            if ($choiceSummary !== '') {
                $description .= " ({$choiceSummary})";
            }
        }

        // Every zone move BoardState::consumeCardMoves() actually recorded
        // for this event -- spelled out regardless of event type (unlike
        // the choice summary above, round_scored's own after-scoring
        // hooks -- Bashfulness/Betrayal/Gluttony/Insecurity/Recklessness --
        // can move cards too) and regardless of whether the choice summary
        // above already named the same card for a different reason (e.g.
        // "chosen mood ids: X" doesn't itself say X actually left play) --
        // this is what makes a random/effect-internal move (Cruelty,
        // Indecisiveness, Altruism) or every target's own move in a
        // multi-target batch (Malice's cascade, Confusion, Disillusionment,
        // Suspicion) show up here exactly like a directly-chosen one does.
        $cardMoves = $details['card_moves'] ?? [];
        if ($cardMoves !== []) {
            $moveParts = array_map(fn (array $move) => $this->describeCardMove($move, $playerNames, $cardNames), $cardMoves);
            $description .= '; ' . implode('; ', $moveParts);
        }

        // Every ownership reassignment BoardState::consumeOwnershipChanges()
        // recorded -- a card's zone move and its ownership are tracked (and
        // logged) completely independently, since either can happen without
        // the other (Chaos/Guile/Instability/Avoidance/Arrogance/Betrayal/
        // Recklessness never move a mood out of play at all, just hand it
        // to someone else; a mood moving zones -- e.g. back to a hand --
        // never itself implies who owns it changed).
        $ownershipChanges = $details['ownership_changes'] ?? [];
        if ($ownershipChanges !== []) {
            $ownershipParts = array_map(
                fn (array $change) => ($cardNames[$change['card_id']] ?? 'a card') . ' changed ownership from ' .
                    ($playerNames[$change['from_player_id']] ?? 'a player') . ' to ' .
                    ($playerNames[$change['to_player_id']] ?? 'a player'),
                $ownershipChanges,
            );
            $description .= '; ' . implode('; ', $ownershipParts);
        }

        // Every draw BoardState::consumeDraws() recorded for this event --
        // deliberately just "{player} drew a card", never which card (see
        // $pendingDraws' own docblock), and one segment per draw rather
        // than grouped/counted, matching how card_moves/ownership_changes
        // above also list every occurrence individually.
        $draws = $details['draws'] ?? [];
        if ($draws !== []) {
            $drawParts = array_map(fn (array $draw) => ($playerNames[$draw['player_id']] ?? 'A player') . ' drew a card', $draws);
            $description .= '; ' . implode('; ', $drawParts);
        }

        // Every extra play BoardState::consumeGrantsCreated() recorded for
        // this event -- source/restriction/zone, via the same
        // describeGrantDetails() wording a just-used grant gets above and
        // an outstanding one gets in round.play_grants (see
        // describePlayGrant()). Attributed to $grantRecipient rather than
        // $actor: grantExtraPlay() always grants to whoever's turn is
        // currently active, which for most event types IS the same player
        // acting_game_player_id already names -- EXCEPT
        // 'pending_decision_resolved', whose own acting_game_player_id is
        // the RESPONDER (e.g. Intimidation's target, who reveals a card),
        // not the player whose turn it actually is. respondToDecision()
        // threads the real turn-owner through as
        // $details['initiating_game_player_id'] specifically so this stays
        // correct for that one event type -- see its own call site's
        // docblock. Falls back to $actor for every other event type, where
        // that key is never set and $actor is already right.
        $grantRecipient = isset($details['initiating_game_player_id'])
            ? ($playerNames[(int) $details['initiating_game_player_id']] ?? 'A player')
            : $actor;

        $grantsCreated = $details['grants_created'] ?? [];
        if ($grantsCreated !== []) {
            $grantParts = array_map(
                fn (?array $restriction) => "{$grantRecipient} was granted " . $this->describeGrantDetails($restriction ?? [], $cardNames),
                $grantsCreated,
            );
            $description .= '; ' . implode('; ', $grantParts);
        }

        // Every 'requiresSourceInPlay' grant BoardState::consumeGrantsLost()
        // recorded as orphaned by this event -- Hope's/Grace's own bonus,
        // never used, because the specific card that created it just left
        // play (see grantIsActive()'s own docblock). Attributed to
        // $grantRecipient for the same reason $grantsCreated above is.
        // Surfaced explicitly here (rather than leaving players to infer
        // it from plays_remaining quietly dropping by one) so there's no
        // confusion later about where an expected extra play went.
        $grantsLost = $details['grants_lost'] ?? [];
        if ($grantsLost !== []) {
            $lostParts = array_map(
                fn (array $restriction) => "{$grantRecipient} lost " . $this->describeGrantDetails($restriction, $cardNames) . ' -- its source left play before it was used',
                $grantsLost,
            );
            $description .= '; ' . implode('; ', $lostParts);
        }

        return $description;
    }

    /** @param array{card_id:int, from_zone:string, to_zone:string, from_player_id:?int, to_player_id:?int} $move */
    private function describeCardMove(array $move, array $playerNames, array $cardNames): string
    {
        $cardName = $cardNames[$move['card_id']] ?? 'a card';
        $from = $this->describeZone($move['from_zone'], $move['from_player_id'], $playerNames);
        $to = $this->describeZone($move['to_zone'], $move['to_player_id'], $playerNames);

        return "{$cardName} moved from {$from} to {$to}";
    }

    /** @param array<int, string> $playerNames */
    private function describeZone(string $zone, ?int $playerId, array $playerNames): string
    {
        return match ($zone) {
            'play' => 'play',
            'discard' => 'the discard pile',
            'deck' => 'the deck',
            'hand' => $playerId !== null ? (($playerNames[$playerId] ?? 'a player') . "'s hand") : 'a hand',
            default => $zone,
        };
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string> $playerNames
     * @param array<int, string[]> $teamMembersByTeamId team_id => [teammate names], only for format 'team'
     */
    private function describeRoundScored(array $details, array $playerNames, array $teamMembersByTeamId = []): string
    {
        if ($details['skipped'] ?? false) {
            $description = 'Round scored (nobody won)';
            // Awe's own one-time override is the only way this branch is
            // ever reached (see skipScoringAndAdvance()), so unlike the
            // normal scored branch below, this is always worth announcing
            // -- there's no "round's winner" to already imply it.
            $overrideId = $details['first_player_override_game_player_id'] ?? null;
            if ($overrideId !== null) {
                $description .= '; ' . ($playerNames[(int) $overrideId] ?? 'a player') . ' goes first next round';
            }

            return $description;
        }

        $scoreParts = [];
        foreach ($details['scores'] ?? [] as $gamePlayerId => $score) {
            $scoreParts[] = ($playerNames[(int) $gamePlayerId] ?? 'a player') . ': ' . $score;
        }

        $description = 'Round scored';
        if ($scoreParts !== []) {
            $description .= ' (' . implode(', ', $scoreParts) . ')';
        }

        // A team-format round's own winner_team_id takes priority over
        // winner_game_player_id's representative -- "Team A wins" naming
        // both teammates, rather than crediting whichever one happened to
        // score higher that round (see "Open Team Play" in
        // php-app/README.md).
        $winnerTeamId = $details['winner_team_id'] ?? null;
        if ($winnerTeamId !== null && isset($teamMembersByTeamId[$winnerTeamId])) {
            $description .= ' -- Team (' . implode(' & ', $teamMembersByTeamId[$winnerTeamId]) . ') wins the round';

            return $description;
        }

        $winnerId = $details['winner_game_player_id'] ?? null;
        if ($winnerId !== null) {
            $description .= ' -- ' . ($playerNames[(int) $winnerId] ?? 'a player') . ' won';
        }

        // Only present in games of 3+ players (see the 'hurt_feelings_
        // game_player_id' key's own producer in scoreRoundAndAdvance()) --
        // the resulting player's Hurt Feelings status only actually takes
        // effect next round, but it's decided here, at this round's
        // scoring, so this is the one place worth announcing who it went
        // to rather than leaving it to only be inferable from the
        // players-list indicator once the next round starts.
        $hurtFeelingsId = $details['hurt_feelings_game_player_id'] ?? null;
        if ($hurtFeelingsId !== null) {
            $description .= '; ' . ($playerNames[(int) $hurtFeelingsId] ?? 'a player') . ' has Hurt Feelings next round';
        }

        // Set only when an in-play override (Honor, or Awe's one-time
        // version -- see BoardState::firstPlayerOverride()) actually hands
        // the next round to someone other than this round's own winner --
        // otherwise that'd be indistinguishable from the ordinary
        // "winner goes first" rule and not worth a separate callout.
        $overrideId = $details['first_player_override_game_player_id'] ?? null;
        if ($overrideId !== null) {
            $description .= '; ' . ($playerNames[(int) $overrideId] ?? 'a player') . ' goes first next round instead of the round\'s winner';
        }

        return $description;
    }

    /**
     * Renders whatever was actually submitted for a play/response as a
     * short, generic summary -- "target player: Bob", "given card: Charity"
     * -- rather than needing per-effect-key knowledge of every card's own
     * choice shape (CardChoiceSchema's field definitions don't cover a
     * pending-decision response anyway, since those field keys are
     * generated dynamically per target -- e.g. Confusion's
     * "given_card_id_169" -- not statically known ahead of time). Keyed
     * purely by naming convention, the same one every choice/answer key in
     * this codebase already follows: a trailing '_player_id(s)'/
     * '_mood_id(s)'/'_card_id(s)' names what kind of id it is, regardless
     * of which specific card or decision it came from.
     *
     * @param array<string, mixed> $details
     * @param array<int, string> $playerNames
     * @param array<int, string> $cardNames
     */
    private function describeChoices(array $details, array $playerNames, array $cardNames): string
    {
        $parts = [];
        foreach ($details as $key => $value) {
            if (in_array($key, ['revealed_card_ids', 'skipped', 'card_moves', 'ownership_changes', 'played_from', 'draws', 'grants_created', 'grant_used', 'grants_lost', 'scoring_trigger', 'initiating_game_player_id', 'suppression_changes', 'effect_state_changes'], true)) {
                continue; // issue #240 replay's own two new queues -- not choices a player made, and not rendered in describeEvent() either (no player-facing text needs them; the raw details are enough for ReplayStateBuilder to consume).
            }

            $entry = $this->describeChoiceEntry($key, $value, $playerNames, $cardNames);
            if ($entry !== null) {
                $parts[] = $entry;
            }
        }

        return implode(', ', $parts);
    }

    private function describeChoiceEntry(string $key, mixed $value, array $playerNames, array $cardNames): ?string
    {
        if ($value === null || $value === [] || $value === false) {
            return null; // a decline/empty choice -- nothing worth showing
        }

        $label = $this->humanizeChoiceKey($key);

        // No leading underscore required (Suspicion's own key is the bare
        // 'player_ids', not e.g. 'target_player_ids') -- every other
        // choice/answer key in the whole schema that ends this way always
        // has one, but requiring it here would silently fall through to
        // the raw-id-printing generic branch below for Suspicion's own
        // choice specifically.
        if (str_contains($key, 'player_ids') && is_array($value)) {
            return $label . ': ' . implode(', ', array_map(fn ($id) => $playerNames[(int) $id] ?? 'a player', $value));
        }
        if (str_contains($key, 'player_id')) {
            return $label . ': ' . ($playerNames[(int) $value] ?? 'a player');
        }
        if ((str_contains($key, 'mood_ids') || str_contains($key, 'card_ids')) && is_array($value)) {
            return $label . ': ' . implode(', ', array_map(fn ($id) => $cardNames[(int) $id] ?? 'a card', $value));
        }
        if (str_contains($key, 'mood_id') || str_contains($key, 'card_id')) {
            return $label . ': ' . ($cardNames[(int) $value] ?? 'a card');
        }
        if ($value === true) {
            return $label;
        }
        if (is_array($value)) {
            return $label . ': ' . implode(', ', $value);
        }

        return $label . ': ' . $value;
    }

    /**
     * "given_card_id_169" -> "given card" (both the per-target numeric
     * suffix a RequiresOpponentDecision response key carries, and the
     * trailing _id/_ids every choice key ends with regardless of whether
     * it's actually plural, are just naming plumbing -- neither belongs in
     * the displayed label).
     */
    private function humanizeChoiceKey(string $key): string
    {
        $key = (string) preg_replace('/_\d+$/', '', $key);

        // discard_mood_id/discard_mood_ids specifically move an in-play
        // mood to the discard pile -- distinct enough from every other
        // "discard" choice (Dignity's discard_card_id, etc., all of which
        // discard a HAND card, and read fine as this method's own generic
        // "discard card") that just calling it "discard mood" reads as
        // though it's the same familiar hand-to-discard action instead.
        if (str_starts_with($key, 'discard_mood')) {
            return str_ends_with($key, 's') ? 'moods moved from play to discard' : 'mood moved from play to discard';
        }

        $key = (string) preg_replace('/_ids?$/', '', $key);

        return str_replace('_', ' ', $key);
    }

    /**
     * @param ?array{type?: string, values?: int[], source?: string, sourceCardId?: int, sourceLabel?: string} $restriction
     * @param array<int, string> $cardNames
     * @return array{description:string, source_card_id:?int, source_card_name:?string}
     */
    private function describePlayGrant(?array $restriction, array $cardNames): array
    {
        if ($restriction === null) {
            // Only ever startTurn()'s own first, ordinary base play --
            // every actual grantExtraPlay() call always folds in
            // 'sourceCardId' (or, for Hurt Feelings' own second base play,
            // 'sourceLabel'), so this is never a granted extra play.
            return ['description' => 'Your normal turn', 'source_card_id' => null, 'source_card_name' => null];
        }

        return [
            'description' => ucfirst($this->describeGrantDetails($restriction, $cardNames)),
            'source_card_id' => $restriction['sourceCardId'] ?? null,
            'source_card_name' => $this->sourceCardNameFor($restriction, $cardNames),
        ];
    }

    /**
     * @param array{sourceCardId?: int, sourceLabel?: string} $restriction
     * @param array<int, string> $cardNames
     */
    private function sourceCardNameFor(array $restriction, array $cardNames): ?string
    {
        // 'sourceLabel' -- currently only Hurt Feelings' own second base
        // play (see BoardState::startTurn()) -- names a grant that isn't
        // attributable to any specific card, so it takes priority over a
        // 'sourceCardId' lookup that would never be present alongside it.
        if (isset($restriction['sourceLabel'])) {
            return $restriction['sourceLabel'];
        }

        $sourceCardId = $restriction['sourceCardId'] ?? null;

        return $sourceCardId !== null ? ($cardNames[$sourceCardId] ?? 'a card') : null;
    }

    /**
     * "an extra play from Charity", "an extra play from the discard pile
     * (must share a color with one of your moods)", etc. -- the shared
     * source/zone/restriction wording describePlayGrant() uses for an
     * outstanding grant (capitalized there via ucfirst()) and describeEvent()
     * uses verbatim (lowercase, mid-sentence) for a newly created or
     * just-used one. $restriction is never null here -- unlike
     * describePlayGrant(), which also has to cover startTurn()'s own base
     * allowance sentinel, every caller of this method already knows it has
     * an actual grant (an empty array for Hope's/Grace's own untracked
     * same-turn bonus, per MoodPlayService::playMood(), rather than null,
     * so the "Your normal turn" case never gets here by accident).
     *
     * @param array{type?: string, values?: int[], source?: string, sourceCardId?: int, sourceLabel?: string} $restriction
     * @param array<int, string> $cardNames
     */
    private function describeGrantDetails(array $restriction, array $cardNames): string
    {
        $sourceCardName = $this->sourceCardNameFor($restriction, $cardNames);

        $zoneNote = ($restriction['source'] ?? 'hand') === 'discard' ? ' from the discard pile' : '';

        $restrictionNote = match ($restriction['type'] ?? null) {
            null => '',
            'shares_color_with_your_moods' => ' (must share a color with one of your moods)',
            'does_not_share_color_with_your_moods' => ' (must not share a color with any of your moods)',
            'base_value_in' => ' (base value ' . implode(' or ', $restriction['values']) . ')',
            'specific_card_ids' => ' (' . implode(' or ', array_map(fn ($id) => $cardNames[$id] ?? 'a card', $restriction['values'])) . ' only)',
            default => '',
        };

        return $sourceCardName !== null
            ? "an extra play from {$sourceCardName}{$zoneNote}{$restrictionNote}"
            : "an extra play{$zoneNote}{$restrictionNote}";
    }

    /**
     * colorOf()/valueOf() reflect live "while in play" effects (Imagination,
     * suppression, etc.) and only work for cards currently in play -- for a
     * card sitting in a hand or the discard pile there's no live effect to
     * apply, so its printed catalog color/base value is what's shown.
     *
     * $reactingViewerId is only passed for a card the viewer might actually
     * end up playing -- every card in their own hand, plus (unlike an
     * in-play card, which is never itself a play candidate) every card in
     * the discard pile, since a discard-sourced play grant (Angst,
     * Harmony, Grief) or Melancholy can make one of those playable too. It's
     * what lets this method decide whether to append Scorn's/Validation's
     * reactToAnotherPlay() fields (see CardChoiceSchema's docblock): both
     * react to the viewer's own subsequent plays, so they only ever apply
     * to a card the viewer might actually play, never to an in-play card
     * being merely displayed. The same flag gates 'is_playable'
     * (MoodPlayService::isPlayable()) -- true by default for an in-play
     * card being merely displayed, since nothing there ever reads it.
     *
     * @param array<int, string> $names
     * @return array{card_id:int,catalog_card_id:int,name:string,color:string,base_color:string,value:int,base_value:int,alt_value:?int,effect_key:string,rules_text:string,has_dice_value:bool,choice_fields:array<int,array<string,mixed>>,is_playable:bool,copy_simulation:?array<int,array{extra_fields:array<int,array<string,mixed>>,cost_payable:bool}>}
     */
    /**
     * "Default selections" mode (issue #274) -- $defaultSelectionsMode
     * true only for the two real "about to submit a play" call sites in
     * buildGameState() (the viewer's own hand, and the discard pile for
     * the rare discard-sourced play grant), never for a read-only
     * teammate-hand/spectator/replay serialization, where nothing is
     * ever actually submitted from what's shown.
     */
    private function serializeCard(BoardState $state, int $cardId, array $names, ?int $reactingViewerId = null, bool $defaultSelectionsMode = false): array
    {
        $catalog = $state->catalogRow($cardId);
        $inPlay = $state->isInPlay($cardId);

        // A Creativity copy's dice value (like its color/value) comes from
        // whatever card it's currently copying, not from Creativity's own
        // (dice-less) catalog row -- see EncouragementEffect, which checks
        // the same effectiveCardId() for exactly this reason.
        $effectiveCardId = $inPlay ? $state->effectiveCardId($cardId) : $cardId;
        $diceValueCatalog = $inPlay ? $state->catalogRow($effectiveCardId) : $catalog;
        $color = $inPlay ? $state->colorOf($cardId) : $catalog['color'];
        $baseValue = $diceValueCatalog['baseValue'];

        // An in-play Creativity that's actually copying something (it can
        // also be played uncopied, as a blank mood -- effectiveCardId()
        // then just returns $cardId itself) displays as the copied mood
        // rather than as Creativity: its name, rules text, and effect_key
        // all switch to the copied card's own, the same way its color and
        // value already do above, so e.g. a Creativity copying Serenity
        // reads and behaves exactly like an in-play Serenity everywhere
        // (including bliss_discard_color below, if it copied Bliss). Only
        // choice_fields/copy_simulation keep reading $catalog's own
        // 'creativity' effect_key -- those describe what's available when
        // *playing* Creativity from hand, which $cardId's own printed
        // identity always governs regardless of what it later copies.
        $isCreativityCopy = $inPlay && $effectiveCardId !== $cardId;

        $choiceFields = CardChoiceSchema::forEffectKey($catalog['effectKey']);
        if ($reactingViewerId !== null) {
            $choiceFields = [
                ...$choiceFields,
                ...$this->reactionFields($state, $reactingViewerId, $color),
            ];
        }
        // Still in hand (not yet played): a value/parity-filtered 'mood'
        // field (Courage, Anxiety, Spite, Shock, Worry, Hostility) needs
        // its candidate list computed as if $cardId were already in play --
        // see BoardState::valueOfAsIfAlsoInPlay()'s own docblock for why.
        if (!$inPlay && $reactingViewerId !== null) {
            $choiceFields = array_map(
                fn (array $field) => $this->withSimulatedMoodCandidates($state, $field, $cardId, $reactingViewerId),
                $choiceFields
            );

            // Repentance's own 'value' field ('allow_extra_values') needs the
            // same as-if-already-in-play treatment -- see
            // withExtraOutOfRangeValues()'s own docblock.
            $choiceFields = array_map(
                fn (array $field) => $this->withExtraOutOfRangeValues($state, $field, $cardId, $reactingViewerId),
                $choiceFields
            );

            // 'grant_source_card_id' -- present only when 2+ distinct
            // outstanding grants would each independently allow playing
            // this card (BoardState::usableGrants()), letting the player
            // pick which one to spend instead of always silently
            // consuming whichever happens to come first. Prepended, not
            // appended -- like Guile's/Regret's own mandatory discard
            // cost, this is resolved before the card's own after-playing
            // choices, not after them.
            $grantOptions = $this->grantChoiceOptions($state, $cardId, $reactingViewerId, $names);
            if ($grantOptions !== []) {
                $choiceFields = [
                    [
                        'key' => 'grant_source_card_id',
                        'type' => 'grant_choice',
                        'required' => false,
                        'label' => 'Which play to use for this card',
                        'options' => $grantOptions,
                    ],
                    ...$choiceFields,
                ];
            }

            if ($defaultSelectionsMode) {
                $choiceFields = array_map(
                    fn (array $field) => $this->withChoiceDefault($state, $field, $catalog['effectKey'], $reactingViewerId),
                    $choiceFields
                );
            }
        }

        return [
            'card_id' => $cardId,
            // The catalog id (cards.id) this card's art asset is keyed by
            // -- see web-static/README.md's "Assets" section. For a
            // Creativity copy this is the COPIED card's catalog id,
            // matching name/rules_text's own switch just below, so the
            // art shown matches whatever mood is actually displayed.
            'catalog_card_id' => $state->catalogCardId($isCreativityCopy ? $effectiveCardId : $cardId),
            'name' => $isCreativityCopy ? ($names[$effectiveCardId] ?? $diceValueCatalog['effectKey']) : ($names[$cardId] ?? $catalog['effectKey']),
            'color' => $color,
            // The printed color, ignoring Imagination's "while in play, all
            // moods are the chosen color" override that $color itself
            // already reflects -- for a Creativity copy this is the
            // COPIED card's own printed color (matching base_value's own
            // $diceValueCatalog use just below), not Creativity's own
            // (colorless-in-spirit, since it's whatever it copies) row.
            'base_color' => $diceValueCatalog['color'],
            'value' => $inPlay ? $state->valueOf($cardId) : $catalog['baseValue'],
            'base_value' => $baseValue,
            'alt_value' => $diceValueCatalog['altValue'],
            'effect_key' => $isCreativityCopy ? $diceValueCatalog['effectKey'] : $catalog['effectKey'],
            'rules_text' => $isCreativityCopy ? $diceValueCatalog['rulesText'] : $catalog['rulesText'],
            'has_dice_value' => $diceValueCatalog['altValue'] !== null,
            'choice_fields' => $choiceFields,
            'is_playable' => $reactingViewerId === null || $this->plays->isPlayable($state, $reactingViewerId, $cardId),
            'is_creativity_copy' => $isCreativityCopy,
            'copy_simulation' => ($reactingViewerId !== null && $catalog['effectKey'] === 'creativity')
                ? $this->creativityCopySimulation($state, $reactingViewerId, $cardId)
                : null,
        ];
    }

    /**
     * "Default selections" mode (issue #274): computes a `default` value
     * to pre-fill a choice field with, following the policy settled on
     * for the issue -- only a REQUIRED 'mode' field ever gets one:
     *
     * - A field with `required: false` is left alone (no `default` key
     *   added at all) -- the existing blank/unchecked/empty state IS
     *   already "no discard"/"don't take the bonus"/etc., which for an
     *   optional effect already reads as the obviously reasonable
     *   default (see Dignity's own example in the issue). Nothing to add.
     * - A REQUIRED field whose type is anything other than 'mode'
     *   (hand_card/discard_card/mood/player/value/bool) is ALSO left
     *   alone, regardless of scope -- these are exactly the fields with
     *   no obviously-safe generic pick: a mandatory cost (which of your
     *   own cards/moods to give up to pay for the play), a specific
     *   value to force-discard, or a player/mood target that may affect
     *   an opponent (positively or negatively). None of those are
     *   "obvious" the way an abstract mode/color/direction choice is, so
     *   this degrades to today's blank-field behavior for all of them --
     *   the player still has to make an explicit, deliberate choice, the
     *   same as without this mode on.
     * - A REQUIRED 'mode' field (a plain enumerated choice among
     *   `options` -- a color to declare, which direction to pass, single
     *   vs. all) defaults to its first option, UNLESS $effectKey has its
     *   own hand-authored override -- currently just Imagination's own
     *   'color' field, which defaults to whichever color is most
     *   represented among $ownerId's own moods currently in play (the
     *   issue's own flagship example of a smarter-than-generic default),
     *   falling back to the same first-option baseline if $ownerId has
     *   no moods in play yet to have a most-represented color at all.
     */
    private function withChoiceDefault(BoardState $state, array $field, string $effectKey, int $ownerId): array
    {
        if (($field['required'] ?? false) !== true || ($field['type'] ?? null) !== 'mode') {
            return $field;
        }

        $options = $field['options'] ?? [];
        if ($options === []) {
            return $field;
        }

        if ($effectKey === 'imagination' && $field['key'] === 'color') {
            $mostRepresented = $this->mostRepresentedColorInPlay($state, $ownerId, $options);
            if ($mostRepresented !== null) {
                return [...$field, 'default' => $mostRepresented];
            }
        }

        return [...$field, 'default' => $options[0]];
    }

    /**
     * Imagination's own hand-authored default (see withChoiceDefault()'s
     * own docblock) -- tallies $ownerId's own moods currently in play by
     * color, returning whichever of $colorOptions has the highest count.
     * Ties (including "every count is 0, nothing in play yet") resolve to
     * whichever candidate comes first in $colorOptions, via arsort()'s
     * PHP 8+ stable-sort guarantee preserving the array's own insertion
     * order (built from $colorOptions itself) among equal values -- the
     * same deterministic "first legal option" fallback every other
     * required 'mode' field already uses, just reached via a tie/no-data
     * case here instead of being the immediate answer.
     *
     * @param string[] $colorOptions
     */
    private function mostRepresentedColorInPlay(BoardState $state, int $ownerId, array $colorOptions): ?string
    {
        $counts = array_fill_keys($colorOptions, 0);
        $anyInPlay = false;
        foreach ($state->moodsInPlay() as $cardId => $mood) {
            if ($mood->ownerId !== $ownerId) {
                continue;
            }
            $color = $state->colorOf($cardId);
            if (isset($counts[$color])) {
                $counts[$color]++;
                $anyInPlay = true;
            }
        }

        if (!$anyInPlay) {
            return null;
        }

        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * Adds a server-computed candidate_card_ids to a 'mood'-type choice
     * field whose filter depends on value/parity (min_value, max_value, or
     * parity -- not colors/has_dice_value, which don't change based on
     * $cardId entering play) -- a no-op for every other field. game.js's
     * own fieldOptions() already treats candidate_card_ids as authoritative
     * and skips applying field.filter itself once it's present (the same
     * mechanism Pride's/Fury's/Instability's own pending-decision
     * candidate_card_ids already rely on), so this is what actually fixes
     * the target from looking ineligible: it filters using
     * BoardState::valueOfAsIfAlsoInPlay() instead of each candidate's
     * current (pre-play) 'value', matching every other constraint the
     * field's own scope/filter would otherwise apply (self-exclusion,
     * own/other scope, a 'colors' filter if present) so the result is a
     * fully correct replacement, not just a value re-check.
     */
    private function withSimulatedMoodCandidates(BoardState $state, array $field, int $cardId, int $ownerId): array
    {
        $filter = $field['filter'] ?? null;
        if ($field['type'] !== 'mood'
            || isset($field['candidate_card_ids'])
            || $filter === null
            || (!isset($filter['min_value']) && !isset($filter['max_value']) && !isset($filter['parity']))
        ) {
            return $field;
        }

        $candidates = [];
        foreach ($state->moodsInPlay() as $inPlayCardId => $mood) {
            if ($inPlayCardId === $cardId) {
                continue;
            }
            if ($field['scope'] === 'own' && $mood->ownerId !== $ownerId) {
                continue;
            }
            if ($field['scope'] === 'other' && $mood->ownerId === $ownerId) {
                continue;
            }
            if (isset($filter['colors']) && !in_array($state->colorOf($inPlayCardId), $filter['colors'], true)) {
                continue;
            }

            $value = $state->valueOfAsIfAlsoInPlay($inPlayCardId, $cardId, $ownerId);
            if (isset($filter['values']) && !in_array($value, $filter['values'], true)) {
                continue;
            }
            if (isset($filter['min_value']) && $value < $filter['min_value']) {
                continue;
            }
            if (isset($filter['max_value']) && $value > $filter['max_value']) {
                continue;
            }
            if (isset($filter['parity']) && ($value % 2 === 0) !== ($filter['parity'] === 'even')) {
                continue;
            }

            $candidates[] = $inPlayCardId;
        }

        $field['candidate_card_ids'] = $candidates;

        return $field;
    }

    /**
     * Adds extra_values to a 'value'-type field marked 'allow_extra_values'
     * (Repentance's own -- see CardChoiceSchema's own docblock for why this
     * is opt-in rather than every 'value' field, e.g. Rebellion's own 0-3
     * range must never be widened) -- a no-op for every other field.
     * Repentance's printed text is just "choose a number," with no upper
     * bound of its own; CardChoiceSchema's 0-12 range is only a practical
     * default matching the highest printed base value in the catalog, not
     * a real rule. A mood whose own value scales with some count (Euphoria's
     * "+1 per mood in play, including itself," Vanity/Sloth/Sadness/Envy,
     * etc.) can genuinely exceed 12 given enough moods/hand/discard cards --
     * and since Repentance is itself about to become one more mood in play,
     * such a target's value has to be computed via
     * BoardState::valueOfAsIfAlsoInPlay() (same reasoning as
     * withSimulatedMoodCandidates() above), not each mood's current
     * (pre-play) value, or a value that only crosses 12 once Repentance
     * itself counts would never be offered.
     */
    private function withExtraOutOfRangeValues(BoardState $state, array $field, int $cardId, int $ownerId): array
    {
        if ($field['type'] !== 'value' || !($field['allow_extra_values'] ?? false)) {
            return $field;
        }

        $extraValues = [];
        foreach ($state->moodsInPlay() as $inPlayCardId => $mood) {
            $value = $state->valueOfAsIfAlsoInPlay($inPlayCardId, $cardId, $ownerId);
            if ($value > $field['max'] && !in_array($value, $extraValues, true)) {
                $extraValues[] = $value;
            }
        }
        sort($extraValues);
        $field['extra_values'] = $extraValues;

        return $field;
    }

    /**
     * The 'grant_source_card_id' field's own options: one per currently
     * usable, distinguishable grant for playing $cardId
     * (BoardState::usableGrants()), reusing describePlayGrant()'s own
     * description text verbatim (so "An extra play from Hope (must share
     * a color with one of your moods)"-style detail isn't duplicated
     * here) and its 'source_card_id' (0 standing in for the base
     * allowance, which has none of its own -- see usableGrants()'s own
     * docblock) as each option's value. Empty whenever there's at most
     * one usable grant, since there's nothing to actually choose between
     * -- the caller skips adding the field entirely in that case.
     *
     * @param array<int, string> $cardNames
     * @return array<int, array{value: int, label: string}>
     */
    private function grantChoiceOptions(BoardState $state, int $cardId, int $viewerId, array $cardNames): array
    {
        $grants = $state->usableGrants($cardId, $viewerId);
        if (count($grants) < 2) {
            return [];
        }

        return array_map(function (?array $restriction) use ($cardNames) {
            $described = $this->describePlayGrant($restriction, $cardNames);

            return ['value' => $described['source_card_id'] ?? 0, 'label' => $described['description']];
        }, $grants);
    }

    /**
     * The reverse of a card's own "affected by" info (suppressed_by_*,
     * boosted_by_*, both set alongside this in the in_play mapping above) --
     * every OTHER in-play mood that $cardId is itself currently affecting,
     * so a card like Encouragement or Guilt can say what it's doing without
     * the viewer having to find and check each target individually.
     * Suppression can have several targets at once (Guilt/Contempt's "all"
     * mode); a dice-value boost has at most one specific target for
     * Encouragement, or several for Idealism's blanket "every mood I own"
     * (both fall out of the same diceValueBoosterCardId() check on every
     * other candidate, no special-casing needed here). A card's own
     * suppression zeroes its value regardless of any dice value that would
     * otherwise apply (see BoardState::valueOf()), so a currently-suppressed
     * target is deliberately not excluded here -- Encouragement/Idealism are
     * still "affecting" it in the sense that they'd apply the moment it's
     * no longer suppressed, and the target's own card-detail view already
     * shows the suppression itself distinctly enough not to be confusing.
     *
     * @param array<int, string> $names
     * @return array<int, array{card_id:int, name:string, relationship:string}>
     */
    private function affectingEntries(BoardState $state, int $cardId, array $names): array
    {
        $affecting = [];
        foreach ($state->moodsInPlay() as $otherCardId => $otherMood) {
            if ($otherCardId === $cardId) {
                continue;
            }
            $otherRow = $state->catalogRow($state->effectiveCardId($otherCardId));
            if ($otherRow['altValue'] !== null && $state->diceValueBoosterCardId($otherCardId) === $cardId) {
                $affecting[] = ['card_id' => $otherCardId, 'name' => $names[$otherCardId] ?? '?', 'relationship' => 'dice_value'];
            }
        }
        foreach ($state->suppressedByCardId($cardId) as $suppressedCardId) {
            $affecting[] = ['card_id' => $suppressedCardId, 'name' => $names[$suppressedCardId] ?? '?', 'relationship' => 'suppressed'];
        }

        return $affecting;
    }

    /**
     * Whether $cardId's current owner only holds it temporarily, and if
     * so, everything the card-detail view needs to explain that: which
     * card caused it, who owned it originally, and when it reverts. Two
     * distinct effectState tags feed this, matching BoardState's own two
     * "give it back later" mechanics -- Arrogance's
     * 'returnsToOwnerIfCardLeavesPlay' (reverts once its own sourceCardId
     * leaves play) and Betrayal's/Recklessness's 'returnsToOwnerAfterScoring'
     * (reverts at the end of the current round) -- checked in that order,
     * though a mood is never tagged with both at once in practice. Every
     * OTHER giveInPlayToPlayer() caller (Guile, Instability, Avoidance,
     * Chaos) is a permanent trade with no such tag, so this returns null
     * for those -- their ownership change is still visible in game history
     * (see BoardState::consumeOwnershipChanges()), just not "temporary".
     *
     * @param array<int, string> $names
     * @param array<int, string> $playerNames
     * @return ?array{original_owner_game_player_id:int, original_owner_name:string, source_card_id:int, source_card_name:string, reverts:string}
     */
    private function temporaryOwnershipInfo(BoardState $state, int $cardId, array $names, array $playerNames): ?array
    {
        $stolen = $state->effectState($cardId, 'returnsToOwnerIfCardLeavesPlay');
        if ($stolen !== null) {
            return [
                'original_owner_game_player_id' => $stolen['ownerId'],
                'original_owner_name' => $playerNames[$stolen['ownerId']] ?? 'a player',
                'source_card_id' => $stolen['sourceCardId'],
                'source_card_name' => $names[$stolen['sourceCardId']] ?? 'a card',
                'reverts' => 'when_source_leaves_play',
            ];
        }

        $returnsAfterScoring = $state->effectState($cardId, 'returnsToOwnerAfterScoring');
        if ($returnsAfterScoring !== null) {
            return [
                'original_owner_game_player_id' => $returnsAfterScoring['ownerId'],
                'original_owner_name' => $playerNames[$returnsAfterScoring['ownerId']] ?? 'a player',
                'source_card_id' => $returnsAfterScoring['sourceCardId'],
                'source_card_name' => $names[$returnsAfterScoring['sourceCardId']] ?? 'a card',
                'reverts' => 'after_scoring',
            ];
        }

        return null;
    }

    /**
     * For Creativity specifically (identified above by its own raw
     * effect_key), precomputes -- for every mood currently in play --
     * what the choices panel would need to offer if this Creativity ends
     * up copying that mood: the same reactionFields() this class already
     * builds for an ordinary hand card, just parameterized by the
     * candidate's own *effective* color/catalog row (BoardState::
     * effectiveCardId() -- matches MoodPlayService::playMood()'s own
     * resolution exactly, so copying a candidate that's itself a
     * Creativity-copy of something else simulates copying that something
     * else, not "blank Creativity"), plus whether that candidate's own
     * "to play" cost could be paid right now. The client swaps in the
     * matching bundle as copy_card_id changes instead of needing a round
     * trip -- see web-static/README.md.
     * Duplicity's own repeat option isn't part of this: it's no longer a
     * pre-submitted top-level choice at all (see MoodPlayService::
     * continueAfterPlayingChain()), so it needs no client-side
     * precomputation here either -- once the Creativity play is actually
     * in play (real or copied), the same pause-based offer applies
     * uniformly, no special-casing for a copy.
     *
     * @return array<int, array{extra_fields: array<int, array<string, mixed>>, cost_payable: bool}>
     */
    private function creativityCopySimulation(BoardState $state, int $viewerId, int $creativityCardId): array
    {
        $simulation = [];
        foreach ($state->moodsInPlay() as $candidateCardId => $mood) {
            $candidateRow = $state->catalogRow($state->effectiveCardId($candidateCardId));
            $simulation[$candidateCardId] = [
                'extra_fields' => $this->reactionFields($state, $viewerId, $candidateRow['color']),
                'cost_payable' => $this->plays->canPayCopiedToPlayCost($state, $viewerId, $creativityCardId, $candidateCardId),
            ];
        }

        return $simulation;
    }

    /**
     * Scorn's reactToAnotherPlay() choice, filled in for *this specific
     * card* (see CardChoiceSchema::reactionTemplate()): the suppress-target
     * is narrowed to $color (matching the played card's color, mirroring
     * ScornEffect's own check). Validation has no field here at all --
     * its own reaction is unconditional (see ValidationEffect's own
     * docblock), so nothing needs offering or submitting for it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reactionFields(BoardState $state, int $viewerId, string $color): array
    {
        $fields = [];

        if ($state->playerHasMoodInPlay($viewerId, 'scorn')) {
            $fields[] = [
                ...CardChoiceSchema::reactionTemplate('scorn'),
                'filter' => ['colors' => [$color]],
            ];
        }

        return $fields;
    }

    /**
     * A card's own game_cards.id is now its identity (see
     * BoardState::$catalogCardIdFor's docblock -- a 'duel' game gives each
     * player their own complete deck, so the same catalog card can exist
     * twice in one game), so a name lookup has to be scoped to one game's
     * own cards rather than the catalog's 1-133 ids directly.
     *
     * @return array<int, string> game_cards.id => name
     */
    private function cardNamesFor(int $gameId): array
    {
        if (!isset($this->cardNamesByGame[$gameId])) {
            $stmt = Connection::get()->prepare(
                'SELECT gc.id, c.name FROM game_cards gc JOIN cards c ON c.id = gc.card_id WHERE gc.game_id = :game_id'
            );
            $stmt->execute(['game_id' => $gameId]);
            $names = [];
            foreach ($stmt->fetchAll() as $row) {
                $names[(int) $row['id']] = $row['name'];
            }
            $this->cardNamesByGame[$gameId] = $names;
        }

        return $this->cardNamesByGame[$gameId];
    }

    /**
     * Same memoization pattern as cardNamesFor(), for game_player_id =>
     * username -- used by writeAfterScoringOrderDecisionBatch() to name a
     * returnsToOwnerAfterScoring tag's ultimate destination player in its
     * field's per-card description.
     *
     * @return array<int, string> game_player_id => username
     */
    private function playerUsernamesFor(int $gameId): array
    {
        if (!isset($this->playerUsernamesByGame[$gameId])) {
            $stmt = Connection::get()->prepare(
                'SELECT gp.id, u.username FROM game_players gp JOIN users u ON u.id = gp.user_id WHERE gp.game_id = :game_id'
            );
            $stmt->execute(['game_id' => $gameId]);
            $names = [];
            foreach ($stmt->fetchAll() as $row) {
                $names[(int) $row['id']] = $row['username'];
            }
            $this->playerUsernamesByGame[$gameId] = $names;
        }

        return $this->playerUsernamesByGame[$gameId];
    }

    private function totalWinsFor(int $gameId, int $playerId): int
    {
        $stmt = Connection::get()->prepare(
            "SELECT COALESCE(SUM(wins_awarded), 0) AS total FROM game_rounds
             WHERE game_id = :game_id AND status = 'scored' AND winner_game_player_id = :player_id"
        );
        $stmt->execute(['game_id' => $gameId, 'player_id' => $playerId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The live sum of $playerId's own in-play moods' current values --
     * distinct from totalWinsFor()'s round-victory count, and distinct
     * from any accumulated across-rounds total: this reads straight off
     * the board (BoardState::valueOf(), the same computation scoring
     * itself uses) rather than any persisted game_round_scores row, so it
     * naturally stays in sync with anything that changes a mood's value
     * mid-round (suppression, a dice-value boost, Imagination's recolor,
     * ...) without needing its own invalidation.
     */
    private function boardPointTotalFor(BoardState $state, int $playerId): int
    {
        $total = 0;
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            $total += $state->valueOf($mood->cardId);
        }

        return $total;
    }

    private function currentRound(int $gameId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT * FROM game_rounds WHERE game_id = :game_id AND status = 'in_progress'
             ORDER BY round_number DESC LIMIT 1"
        );
        $stmt->execute(['game_id' => $gameId]);
        $round = $stmt->fetch();

        if ($round === false) {
            throw new GameStateException("Game {$gameId} has no round in progress");
        }

        return $round;
    }

    private function fetchGame(int $gameId): array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM games WHERE id = :game_id');
        $stmt->execute(['game_id' => $gameId]);
        $game = $stmt->fetch();

        if ($game === false) {
            throw new GameStateException("No such game {$gameId}");
        }

        return $game;
    }

    /**
     * Stamps games.last_move_at with the current time -- called once, after
     * playMood()/pass()/respondToDecision() has already run to completion
     * (wrapping the whole withGameLock() call rather than threading a call
     * through every nested private method those three delegate to), so a
     * request that throws before reaching that point never counts as a
     * move. A standalone statement outside of whatever transaction(s) the
     * call just committed -- harmless to run a moment after those commit,
     * and far simpler than plumbing this into finishPlay()/advanceTurn()/
     * scoreRoundAndAdvance()'s own several internal transactions, all of
     * which already return successfully by the time this runs. See
     * listGamesForUser(), which sorts the lobby by this column (falling
     * back to started_at/created_at) so a stalled game doesn't outrank an
     * actively-progressing one.
     */
    private function touchLastMoveAt(int $gameId): void
    {
        Connection::get()->prepare('UPDATE games SET last_move_at = NOW() WHERE id = :game_id')
            ->execute(['game_id' => $gameId]);
    }

    /** @return int[] game_players.id, ordered by seat_order */
    private function seatOrder(int $gameId): array
    {
        $stmt = Connection::get()->prepare('SELECT id FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC');
        $stmt->execute(['game_id' => $gameId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param int[] $playerIds
     * @return int[] $playerIds rotated so $startId comes first
     */
    private function rotate(array $playerIds, int $startId): array
    {
        $startIndex = array_search($startId, $playerIds, true);

        return array_merge(array_slice($playerIds, $startIndex), array_slice($playerIds, 0, $startIndex));
    }

    /**
     * @param array<int, ?array{type: string, values?: int[]}> $playGrants
     *
     * "It's your turn" push notifications (issue #108) fire from here: this
     * is the one place $playerId (whose turn it now is) is set, so
     * comparing against the round's previous value before overwriting it
     * tells us exactly when the turn actually changed hands -- without
     * that check, a same-player extra play (a banked Generosity/Joy grant,
     * a Duplicity repeat) would re-notify the player already mid-turn for
     * no reason.
     */
    private function updateRoundTurnState(int $roundId, int $playerId, array $playGrants, bool $discardedThisRound): void
    {
        $pdo = Connection::get();

        $previousStmt = $pdo->prepare('SELECT current_turn_game_player_id FROM game_rounds WHERE id = :round_id');
        $previousStmt->execute(['round_id' => $roundId]);
        $previousPlayerId = $previousStmt->fetchColumn();
        $previousPlayerId = $previousPlayerId !== false ? (int) $previousPlayerId : null;

        $stmt = $pdo->prepare(
            'UPDATE game_rounds SET current_turn_game_player_id = :player_id, plays_remaining = :plays_remaining, pending_play_grants = :pending_play_grants, discarded_this_round = :discarded_this_round WHERE id = :round_id'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'plays_remaining' => count($playGrants),
            'pending_play_grants' => json_encode($playGrants),
            'discarded_this_round' => $discardedThisRound ? 1 : 0,
            'round_id' => $roundId,
        ]);

        if ($previousPlayerId !== $playerId) {
            $this->notifyItsYourTurn($roundId, $playerId);
        }
    }

    private function notifyItsYourTurn(int $roundId, int $gamePlayerId): void
    {
        if ($this->notifications === null) {
            return;
        }

        $stmt = Connection::get()->prepare(
            'SELECT gr.game_id, gp.user_id
             FROM game_rounds gr
             JOIN game_players gp ON gp.id = :game_player_id
             WHERE gr.id = :round_id'
        );
        $stmt->execute(['game_player_id' => $gamePlayerId, 'round_id' => $roundId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }

        $gameId = (int) $row['game_id'];
        $this->notifications->notifyYourTurn((int) $row['user_id'], $gameId, "Game #{$gameId} is waiting on your move.");
    }

    /**
     * Called at every point a player's action successfully resolves
     * something they might have been reminded about -- clears any
     * queued-but-not-yet-delivered "waiting on you" notification for this
     * game before the cron flush (bin/send_queued_notifications.php) ever
     * gets to it, so they never get a stale nudge for a turn/decision
     * they've already handled. A no-op if nothing was queued, or if it
     * was queued for a different game or for something other than this
     * game (see QueuedNotificationRepository::clearForGameIfMatches()).
     */
    private function clearQueuedNotificationForGamePlayer(int $gameId, int $gamePlayerId): void
    {
        if ($this->notifications === null) {
            return;
        }

        $stmt = Connection::get()->prepare('SELECT user_id FROM game_players WHERE id = :id');
        $stmt->execute(['id' => $gamePlayerId]);
        $userId = $stmt->fetchColumn();
        if ($userId !== false) {
            $this->notifications->clearQueuedForGame((int) $userId, $gameId);
        }
    }

    private function assertNoPendingDecision(int $roundId): void
    {
        $stmt = Connection::get()->prepare(
            'SELECT 1 FROM game_pending_decision_batches WHERE game_round_id = :round_id AND resolved_at IS NULL LIMIT 1'
        );
        $stmt->execute(['round_id' => $roundId]);

        if ($stmt->fetchColumn() !== false) {
            throw new GameStateException("Round {$roundId} has a decision still pending -- no one can play or pass until it's answered");
        }
    }

    /** @return array<string, mixed>|null */
    private function activePendingBatch(int $roundId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM game_pending_decision_batches WHERE game_round_id = :round_id AND resolved_at IS NULL LIMIT 1'
        );
        $stmt->execute(['round_id' => $roundId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * The one decision currently awaiting a response within $batchId --
     * the lowest step_index still unresolved -- even if the batch has
     * several queued targets (e.g. Disillusionment/Suspicion), only this
     * one is ever actively shown to anyone.
     *
     * @return array<string, mixed>|null
     */
    private function activePendingDecision(int $batchId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM game_pending_decisions WHERE batch_id = :batch_id AND resolved_at IS NULL ORDER BY step_index ASC LIMIT 1'
        );
        $stmt->execute(['batch_id' => $batchId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * GET /games/state's view of the round's outstanding decision (if any)
     * from $viewerGamePlayerId's own perspective -- every viewer sees who
     * initiated it, which card, and who it's waiting on, but the actual
     * prompt (`field`, e.g. Compulsion's target's own hand-card options)
     * is only ever included for the targeted player themselves, the same
     * way an opponent's hand is never exposed to anyone else.
     *
     * @return array<string, mixed>|null
     */
    /**
     * "Default selections" mode (issue #274) applies here too, not just
     * to serializeCard()'s own choice_fields -- $field mirrors
     * CardChoiceSchema's field shape (see this method's own docblock
     * above) and is rendered client-side by the exact same
     * buildFieldRow()/buildFieldWidget() code either way, so the
     * withChoiceDefault() enrichment is the same policy applied to the
     * one field a pending decision carries, rather than an array of
     * them. Only for $isYou, matching the gate 'field' itself was
     * already behind (the decision's own private choice payload is
     * still never populated for anyone else).
     */
    private function serializePendingDecision(int $roundId, ?int $viewerGamePlayerId, ?BoardState $state = null, bool $defaultSelectionsMode = false): ?array
    {
        $batchRow = $this->activePendingBatch($roundId);
        if ($batchRow === null) {
            return null;
        }

        $decisionRow = $this->activePendingDecision((int) $batchRow['id']);
        if ($decisionRow === null) {
            return null;
        }

        $targetGamePlayerId = (int) $decisionRow['target_game_player_id'];
        // $viewerGamePlayerId === null for a spectator (see
        // GameService::buildGameState()) -- correctly never equals a real
        // target_game_player_id, so 'field' (the decision's own private
        // choice payload) is never populated below, the same way it's
        // never populated for a real player who isn't the one being
        // asked.
        $isYou = $targetGamePlayerId === $viewerGamePlayerId;
        $playedCardId = (int) $batchRow['played_card_id'];

        $result = [
            'initiating_game_player_id' => (int) $batchRow['initiating_game_player_id'],
            'played_card_id' => $playedCardId,
            'played_card_name' => $this->cardNamesFor((int) $batchRow['game_id'])[$playedCardId] ?? null,
            'decision_type' => $decisionRow['decision_type'],
            'target_game_player_id' => $targetGamePlayerId,
            'is_you' => $isYou,
        ];

        if ($isYou) {
            $field = json_decode((string) $decisionRow['field'], true);
            if ($defaultSelectionsMode && $state !== null) {
                $effectKey = $state->catalogRow($playedCardId)['effectKey'] ?? '';
                $field = $this->withChoiceDefault($state, $field, $effectKey, $targetGamePlayerId);
            }
            $result['field'] = $field;
        }

        return $result;
    }

    /**
     * The round's live score-so-far (see RoundScorer::score()'s partial-
     * preview support: an unanswered Enthusiasm/Passion decision just
     * reads as declined-for-now) plus any active Sneakiness swap targets,
     * shown alongside an outstanding Enthusiasm/Passion decision so
     * whoever's answering it -- and everyone else watching -- can reason
     * about whether taking the bonus actually helps them. Without this,
     * "you may score one of your opponents' moods" is close to
     * meaningless to decide on blind, especially once a swap is in play.
     * Every viewer sees the same thing (final round scores aren't hidden
     * information the way an opponent's hand is), and it's null whenever
     * the round has no active scoring decision, since it's only relevant
     * while one's outstanding.
     *
     * @return ?array{scores: array<int, int>, sneakiness_swaps: array<int, array{game_player_id: int, swaps_with_game_player_id: int}>}
     */
    private function serializeScoringPreview(BoardState $state, int $roundId): ?array
    {
        $batchRow = $this->activePendingBatch($roundId);
        if ($batchRow === null) {
            return null;
        }

        $decisionRow = $this->activePendingDecision((int) $batchRow['id']);
        if ($decisionRow === null || !in_array($decisionRow['decision_type'], self::SCORING_DECISION_TYPES, true)) {
            return null;
        }

        $scoringDecisions = $this->resolvedScoringDecisionBonuses($state, $roundId);

        $swaps = [];
        foreach ($state->moodsInPlay() as $mood) {
            $swapWithPlayerId = $state->effectState($mood->cardId, 'swapScoreWithPlayerId');
            if ($swapWithPlayerId !== null) {
                $swaps[] = ['game_player_id' => $mood->ownerId, 'swaps_with_game_player_id' => (int) $swapWithPlayerId];
            }
        }

        return [
            'scores' => $this->scorer->score($state, $scoringDecisions),
            'sneakiness_swaps' => $swaps,
        ];
    }

    /**
     * A board-wide summary of every in-play mood whose ability changes how
     * this round's scoring will work -- unlike serializeScoringPreview()
     * above, which only ever appears while an Enthusiasm/Passion decision
     * is actually outstanding, this is always computed so a player can see
     * what's coming before the round even ends. None of it is hidden
     * information: every entry is either a public in-play card or a choice
     * already made openly when it was played (Sneakiness's target, Awe's
     * next-first-player, Corruption's mode), so it's identical for every
     * viewer.
     *
     * Sneakiness/Awe/Corruption only appear here for as long as their
     * one-time round-scoped effectState tag stays set -- see
     * applyScoreSwaps()/skipScoringAndAdvance()/consumeExtraWinMarker(),
     * which each clear their own tag once the round it covers actually
     * scores -- while Bliss/Exhilaration/Enthusiasm/Passion are genuinely
     * perpetual for as long as the card stays in play. The effect_key
     * lookup goes through effectiveCardId(), mirroring RoundScorer::
     * score()'s own check, so a Creativity copy of one of these is picked
     * up the same way it actually contributes to the score.
     *
     * @param array<int, string> $names
     * @param array<int, string> $playerNames
     * @return array<int, array{card_id:int, card_name:string, owner_game_player_id:int, description:string}>
     */
    private function scoringEffectEntries(BoardState $state, array $names, array $playerNames): array
    {
        $entries = [];
        foreach ($state->moodsInPlay() as $mood) {
            $effectKey = $state->catalogRow($state->effectiveCardId($mood->cardId))['effectKey'];
            $ownerName = $playerNames[$mood->ownerId] ?? 'A player';
            $cardName = $names[$mood->cardId] ?? 'a card';

            $description = match ($effectKey) {
                'exhilaration' => "{$ownerName}'s {$cardName} scores all of their moods an extra time.",
                'bliss' => $this->blissScoringDescription($state, $mood, $ownerName, $cardName),
                'sneakiness' => $this->sneakinessScoringDescription($state, $mood, $ownerName, $cardName, $playerNames),
                'awe' => $state->effectState($mood->cardId, 'skipScoringThisRound')
                    ? "{$ownerName}'s {$cardName} means this round won't be scored -- no one wins or loses."
                    : null,
                'corruption' => $state->effectState($mood->cardId, 'awardsExtraWin')
                    ? "This round's winner will get two wins instead of one ({$cardName})."
                    : null,
                'enthusiasm' => "{$ownerName} may score their highest-valued mood an extra time ({$cardName}).",
                'passion' => "{$ownerName} may score one of an opponent's moods as their own ({$cardName}).",
                default => null,
            };

            if ($description !== null) {
                $entries[] = [
                    'card_id' => $mood->cardId,
                    'card_name' => $cardName,
                    'owner_game_player_id' => $mood->ownerId,
                    'description' => $description,
                ];
            }
        }

        return $entries;
    }

    private function blissScoringDescription(BoardState $state, MoodInPlay $mood, string $ownerName, string $cardName): ?string
    {
        $color = $state->effectState($mood->cardId, 'blissColor');
        if ($color === null) {
            return null;
        }

        return "{$ownerName}'s {$cardName} scores their {$color} moods two extra times (a {$color} card was discarded to it).";
    }

    /** @param array<int, string> $playerNames */
    private function sneakinessScoringDescription(BoardState $state, MoodInPlay $mood, string $ownerName, string $cardName, array $playerNames): ?string
    {
        $swapWithPlayerId = $state->effectState($mood->cardId, 'swapScoreWithPlayerId');
        if ($swapWithPlayerId === null) {
            return null;
        }

        $opponentName = $playerNames[$swapWithPlayerId] ?? 'a player';

        return "{$ownerName}'s {$cardName} will swap their round score with {$opponentName}'s.";
    }

    /**
     * A board-wide summary of every in-play mood whose "while in play"
     * ability reshapes every mood on the board (not just how scoring
     * works, which scoringEffectEntries() above already covers) -- e.g.
     * Imagination overriding every mood's color via
     * BoardState::colorOf(). Sits alongside scoring_effects as its own
     * always-visible list rather than folded into it, since this is about
     * what a mood *is* right now, not what it's worth.
     *
     * @param array<int, string> $names
     * @param array<int, string> $playerNames
     * @return array<int, array{card_id:int, card_name:string, owner_game_player_id:int, description:string}>
     */
    private function boardEffectEntries(BoardState $state, array $names, array $playerNames): array
    {
        $entries = [];
        foreach ($state->moodsInPlay() as $mood) {
            $effectKey = $state->catalogRow($state->effectiveCardId($mood->cardId))['effectKey'];
            $ownerName = $playerNames[$mood->ownerId] ?? 'A player';
            $cardName = $names[$mood->cardId] ?? 'a card';

            $description = match ($effectKey) {
                'imagination' => $this->imaginationBoardDescription($state, $mood, $ownerName, $cardName),
                default => null,
            };

            if ($description !== null) {
                $entries[] = [
                    'card_id' => $mood->cardId,
                    'card_name' => $cardName,
                    'owner_game_player_id' => $mood->ownerId,
                    'description' => $description,
                ];
            }
        }

        return $entries;
    }

    private function imaginationBoardDescription(BoardState $state, MoodInPlay $mood, string $ownerName, string $cardName): ?string
    {
        $color = $state->effectState($mood->cardId, 'color');
        if ($color === null) {
            return null;
        }

        return "{$ownerName}'s {$cardName} — all moods are {$color}.";
    }

    /**
     * Every row in $batchId, reconstructed as PlayerChoices keyed by each
     * row's own field key -- see RequiresOpponentDecision::
     * resolveDecisions()'s $answers parameter.
     *
     * @return array<string, PlayerChoices>
     */
    private function collectAnswers(int $batchId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT field, answer FROM game_pending_decisions WHERE batch_id = :batch_id ORDER BY step_index ASC'
        );
        $stmt->execute(['batch_id' => $batchId]);

        $answers = [];
        foreach ($stmt->fetchAll() as $row) {
            $field = json_decode((string) $row['field'], true);
            $answers[$field['key']] = new PlayerChoices((array) json_decode((string) $row['answer'], true));
        }

        return $answers;
    }

    /**
     * Writes one new pending-decision batch and its rows -- assumes the
     * caller already holds an open transaction (playMood()/
     * respondToDecision() both do), so this does no transaction management
     * of its own.
     */
    /**
     * assertNoPendingDecision() is a plain SELECT before this method's own
     * INSERT, so two requests for the same round (the same player's two
     * open tabs, or a play racing a respondToDecision() that itself
     * uncovers a chained pending decision) can both pass that check before
     * either one's batch actually exists. The database closes the window
     * this application-level check can't: migration 0011's
     * uq_pending_batches_one_open_per_round unique index allows any number
     * of resolved batches per round but at most one open (unresolved) one,
     * so the loser of the race gets a duplicate-key error here instead of
     * silently creating a second, simultaneously-open batch. Translated
     * into the same GameStateException assertNoPendingDecision() throws
     * for the non-racing case, so both surface identically to the caller.
     */
    private function writePendingBatch(
        int $gameId,
        int $roundId,
        int $initiatingPlayerId,
        PlayerChoices $topLevelChoices,
        PlayerChoices $invocationChoices,
        PlayResult $result,
    ): void {
        $pdo = Connection::get();

        $insertBatch = $pdo->prepare(
            'INSERT INTO game_pending_decision_batches
                (game_id, game_round_id, played_card_id, invocation_seq, initiating_game_player_id, top_level_choices, invocation_choices)
             VALUES (:game_id, :round_id, :played_card_id, :invocation_seq, :initiator, :top_level_choices, :invocation_choices)'
        );

        try {
            $insertBatch->execute([
                'game_id' => $gameId,
                'round_id' => $roundId,
                'played_card_id' => $result->playedCardId,
                'invocation_seq' => $result->invocationSeq,
                'initiator' => $initiatingPlayerId,
                'top_level_choices' => json_encode($topLevelChoices->toArray()),
                'invocation_choices' => json_encode($invocationChoices->toArray()),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new GameStateException("Round {$roundId} has a decision still pending -- no one can play or pass until it's answered");
            }
            throw $e;
        }
        $batchId = (int) $pdo->lastInsertId();

        $insertDecision = $pdo->prepare(
            'INSERT INTO game_pending_decisions (batch_id, step_index, target_game_player_id, decision_type, field)
             VALUES (:batch_id, :step_index, :target_player_id, :decision_type, :field)'
        );
        $targetPlayerIds = [];
        foreach ($result->pendingDecisions as $stepIndex => $decision) {
            $insertDecision->execute([
                'batch_id' => $batchId,
                'step_index' => $stepIndex,
                'target_player_id' => $decision->targetPlayerId,
                'decision_type' => $decision->decisionType,
                'field' => json_encode($decision->field),
            ]);
            $targetPlayerIds[$decision->targetPlayerId] = true;
        }

        $this->notifyPendingDecisionTargets($gameId, array_keys($targetPlayerIds));
    }

    /**
     * "It's your turn" push notification (issue #108) for whoever a fresh
     * pending-decision batch is waiting on -- e.g. Compulsion asking an
     * opponent which card to give up. Deduplicated by the caller (one
     * notification per targeted player per batch, even if they're asked
     * more than one question in it).
     *
     * @param int[] $gamePlayerIds
     */
    private function notifyPendingDecisionTargets(int $gameId, array $gamePlayerIds): void
    {
        $this->notifyGamePlayersItsYourTurn($gameId, $gamePlayerIds, "Game #{$gameId} needs your decision.", 'decision');
    }

    /**
     * Shared "it's your turn" push notification (issue #108) fan-out for
     * every non-turn "waiting on you" state the lobby's own
     * isAwaitingResponseFrom()/draftAwaitingResponseUsernames() already
     * recognize -- a Compulsion-style pending decision
     * (notifyPendingDecisionTargets()), a fresh team turn_order/
     * draw_recipient decision (createTeamDecision()), closed_team's
     * pregame card pass, and a draft match's first-player-choice freeze
     * (both from startGame()). $tag is per-caller so each of these gets
     * its own notification (never collapsed/deduped against a different
     * kind of "your turn" for the same game) -- see
     * NotificationService::notifyYourTurn()'s own tag parameter.
     *
     * @param int[] $gamePlayerIds
     */
    private function notifyGamePlayersItsYourTurn(int $gameId, array $gamePlayerIds, string $message, string $tag): void
    {
        if ($this->notifications === null || $gamePlayerIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($gamePlayerIds), '?'));
        $stmt = Connection::get()->prepare("SELECT user_id FROM game_players WHERE id IN ({$placeholders})");
        $stmt->execute($gamePlayerIds);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $this->notifications->notifyYourTurn((int) $userId, $gameId, $message, $tag);
        }
    }

    /**
     * Draft-drafting/deck-building counterpart to
     * notifyGamePlayersItsYourTurn() immediately above -- a Quick Draft/
     * Winston Draft/Grid Draft match's own "waiting on you" state
     * (draftAwaitingResponseUsernames()'s own domain) lives on
     * draft_round_picks/draft_winston_state/draft_grid_state/
     * draft_match_players, all keyed by user_id rather than
     * game_player_id (a match's drafted/deck data outlives any single one
     * of its up-to-3 `games` rows -- see migration 0027's own docblock),
     * so this skips that method's game_players lookup entirely and
     * notifies user ids directly. Called from dealQuickDraftRound()/
     * submitQuickDraftPick() (a fresh round dealt, or the received-stage
     * unlocked once both players have drawn), initializeWinstonDraft()/
     * submitWinstonDraftPick() and initializeGridDraft()/
     * submitGridDraftPick() (whoever the active turn passes to), and
     * finalizeQuickDraft()/finalizeWinstonDraft()/submitGridDraftPick()/
     * advanceDraftMatch() (deck_building -- an initial trim or a later
     * sideboard, either way every seated player needs to (re)submit a
     * deck). $tag distinguishes "a pick is waiting" ('draft-pick') from
     * "a deck submission is waiting" ('draft-deck') the same way
     * notifyGamePlayersItsYourTurn()'s own $tag keeps its several cases
     * from colliding.
     *
     * @param int[] $userIds
     */
    private function notifyDraftUsersItsYourTurn(int $gameId, array $userIds, string $message, string $tag): void
    {
        if ($this->notifications === null) {
            return;
        }

        foreach ($userIds as $userId) {
            $this->notifications->notifyYourTurn($userId, $gameId, $message, $tag);
        }
    }

    /**
     * Folds whatever BoardState::consumeRevealedCardIds()/consumeCardMoves()/
     * consumeOwnershipChanges()/consumeDraws()/consumeGrantsCreated()/
     * consumeGrantUsed()/consumeGrantsLost() have collected since the last
     * event was logged into $details before it's persisted -- see those methods' own
     * docblocks for why this can't just be read back out of $details like
     * everything else in it. A no-op for a play that revealed, moved, or
     * reassigned nothing, drew no cards, and granted/used no extra play.
     * $state is optional purely because a few call sites (turn_passed, and
     * the scoring-decision response branch, neither of which can move or
     * reassign a card) have no BoardState in scope at the point they log
     * their own event -- every call site that already has one loaded
     * always passes it, so nothing it did is ever silently lost. Always
     * called from logEvent() itself, immediately before the event is
     * persisted, never earlier: every consume method clears what it
     * returns, so reading them any other time would risk attributing a
     * change to the wrong event or dropping it entirely.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function withCardHistory(?BoardState $state, array $details): array
    {
        if ($state === null) {
            return $details;
        }

        $revealedCardIds = $state->consumeRevealedCardIds();
        if ($revealedCardIds !== []) {
            $details['revealed_card_ids'] = $revealedCardIds;
        }

        $cardMoves = $state->consumeCardMoves();
        if ($cardMoves !== []) {
            $details['card_moves'] = $cardMoves;
        }

        $ownershipChanges = $state->consumeOwnershipChanges();
        if ($ownershipChanges !== []) {
            $details['ownership_changes'] = $ownershipChanges;
        }

        $draws = $state->consumeDraws();
        if ($draws !== []) {
            $details['draws'] = $draws;
        }

        $grantsCreated = $state->consumeGrantsCreated();
        if ($grantsCreated !== []) {
            $details['grants_created'] = $grantsCreated;
        }

        $grantUsed = $state->consumeGrantUsed();
        if ($grantUsed !== null) {
            $details['grant_used'] = $grantUsed;
        }

        $grantsLost = $state->consumeGrantsLost();
        if ($grantsLost !== []) {
            $details['grants_lost'] = $grantsLost;
        }

        $suppressionChanges = $state->consumeSuppressionChanges();
        if ($suppressionChanges !== []) {
            $details['suppression_changes'] = $suppressionChanges;
        }

        $effectStateChanges = $state->consumeEffectStateChanges();
        if ($effectStateChanges !== []) {
            $details['effect_state_changes'] = $effectStateChanges;
        }

        return $details;
    }

    /**
     * Folds BoardState::$cardId's own 'playedFromZone' effectState tag
     * (set once, permanently, the moment it actually entered play -- see
     * BoardState::initialEffectState()) into $details as 'played_from', so
     * describeEvent() can say "played Harmony from discard" -- unlike
     * $revealedCardIds/$cardMoves/$ownershipChanges above, this doesn't
     * need a BoardState-level consume/clear step: it's read (never
     * cleared) directly off the mood's own persisted effectState, which is
     * exactly why it survives from the moment a play pauses on a
     * RequiresOpponentDecision all the way to whichever later request
     * finally resolves it, unlike the transient per-request tracking the
     * other three use.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function withPlayedFrom(BoardState $state, int $cardId, array $details): array
    {
        $playedFrom = $state->effectState($cardId, 'playedFromZone');
        if ($playedFrom !== null) {
            $details['played_from'] = $playedFrom;
        }

        return $details;
    }

    /** @param array<string, mixed> $details */
    private function logEvent(int $gameId, ?int $roundId, ?int $actingPlayerId, string $eventType, ?int $cardId, array $details, ?BoardState $state = null): void
    {
        $details = $this->withCardHistory($state, $details);

        $stmt = Connection::get()->prepare(
            'INSERT INTO game_events (game_id, game_round_id, acting_game_player_id, event_type, card_id, details)
             VALUES (:game_id, :round_id, :acting_player_id, :event_type, :card_id, :details)'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'round_id' => $roundId,
            'acting_player_id' => $actingPlayerId,
            'event_type' => $eventType,
            'card_id' => $cardId,
            'details' => $details === [] ? null : json_encode($details),
        ]);
    }
}
