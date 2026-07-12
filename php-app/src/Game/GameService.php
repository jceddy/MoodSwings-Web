<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Database\Connection;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\CardChoiceSchema;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\MoodInPlay;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\PlayResult;
use MoodSwings\Rules\RoundScorer;
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

    /** @var array<int, array<int, string>> gameId => (card_id => name), memoized per instance by cardNamesFor() */
    private array $cardNamesByGame = [];

    public function __construct(
        private readonly BoardStateRepository $boardStates,
        private readonly MoodPlayService $plays,
        private readonly RoundScorer $scorer,
        private readonly int $gameLockTimeoutSeconds = self::GAME_LOCK_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * @param int[] $userIds seat order follows array order
     * @param ?array{preset?: string, min_cards?: int, rarity_limits?: array<string,int>, duplicate_limits?: array<string,int>, even_color_distribution_rarities?: string[]} $duelDeckRules
     *        only meaningful when $deckType is 'custom_duel' -- see resolveDuelDeckRules().
     */
    public function createGame(
        int $createdByUserId,
        array $userIds,
        string $format = 'standard',
        int $winsNeeded = 3,
        string $deckType = 'structure',
        ?string $decklistText = null,
        ?array $duelDeckRules = null,
    ): int {
        if (count($userIds) > self::MAX_PLAYERS) {
            throw new GameStateException('A game cannot have more than ' . self::MAX_PLAYERS . ' players');
        }
        if ($format === 'duel' && count($userIds) !== 2) {
            throw new GameStateException('A duel game must have exactly 2 players');
        }

        $customDeckName = null;
        $customDeckCardIds = null;
        $duelRulesPreset = null;
        $duelMinCards = null;
        $duelRarityLimits = null;
        $duelDuplicateLimits = null;
        $duelEvenColorDistributionRarities = null;

        if ($deckType === 'custom') {
            if ($format === 'duel') {
                throw new GameStateException('Custom decklists are not supported for duel games -- use deck_type "custom_duel" instead');
            }

            ['name' => $customDeckName, 'cardIds' => $customDeckCardIds] = $this->parseCustomDecklist($decklistText, count($userIds));
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

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
            $insertGame = $pdo->prepare(
                "INSERT INTO games (
                    format, deck_type, custom_deck_name, custom_deck_card_ids,
                    custom_duel_rules_preset, custom_duel_min_cards, custom_duel_rarity_limits, custom_duel_duplicate_limits,
                    custom_duel_even_color_distribution_rarities,
                    status, created_by_user_id, wins_needed
                 ) VALUES (
                    :format, :deck_type, :custom_deck_name, :custom_deck_card_ids,
                    :duel_rules_preset, :duel_min_cards, :duel_rarity_limits, :duel_duplicate_limits,
                    :duel_even_color_distribution_rarities,
                    'waiting', :created_by, :wins_needed
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
                'created_by' => $createdByUserId,
                'wins_needed' => $winsNeeded,
            ]);
            $gameId = (int) $pdo->lastInsertId();

            $insertPlayer = $pdo->prepare(
                'INSERT INTO game_players (game_id, user_id, seat_order) VALUES (:game_id, :user_id, :seat_order)'
            );
            foreach (array_values($userIds) as $seatOrder => $userId) {
                $insertPlayer->execute(['game_id' => $gameId, 'user_id' => $userId, 'seat_order' => $seatOrder]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $gameId;
    }

    /**
     * @return array{idsByName: array<string,int>, rowsById: array<int, array{name:string, rarity:string, color:string}>}
     */
    private function loadCardCatalog(): array
    {
        $stmt = Connection::get()->query('SELECT id, name, rarity, color FROM cards');
        $idsByName = [];
        $rowsById = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $idsByName[mb_strtolower($row['name'])] = $id;
            $rowsById[$id] = ['name' => $row['name'], 'rarity' => $row['rarity'], 'color' => $row['color']];
        }

        return ['idsByName' => $idsByName, 'rowsById' => $rowsById];
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

        $minimumCards = 15 * ($playerCount - 1);
        if (count($parsed['cardIds']) < $minimumCards) {
            throw new GameStateException(
                "The decklist has only " . count($parsed['cardIds']) . " card(s), but at least {$minimumCards} are required for {$playerCount} players"
            );
        }

        return $parsed;
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
    public function submitCustomDuelDeck(int $gameId, int $gamePlayerId, string $decklistText): void
    {
        $game = $this->fetchGame($gameId);
        if ($game['deck_type'] !== 'custom_duel') {
            throw new GameStateException("Game {$gameId} does not use custom duel decklists");
        }
        if ($game['status'] !== 'waiting') {
            throw new GameStateException("Game {$gameId} has already started -- decklists can no longer be submitted");
        }

        $ownerStmt = Connection::get()->prepare('SELECT COUNT(*) FROM game_players WHERE id = :id AND game_id = :game_id');
        $ownerStmt->execute(['id' => $gamePlayerId, 'game_id' => $gameId]);
        if ((int) $ownerStmt->fetchColumn() === 0) {
            throw new GameStateException("Player {$gamePlayerId} is not seated in game {$gameId}");
        }

        if (trim($decklistText) === '') {
            throw new GameStateException('A decklist is required');
        }

        $catalog = $this->loadCardCatalog();
        $parsed = (new DecklistParser($catalog['idsByName']))->parse($decklistText);

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

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
            $insertCard = $pdo->prepare(
                'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, deck_position)
                 VALUES (:game_id, :card_id, :zone, :owner, :deck_position)'
            );

            // 'duel' gives each player their OWN complete deck -- built,
            // and shuffled, independently per player by the exact same
            // rules a normal single-player deck uses -- rather than
            // splitting one shared pool (createGame() already rejected any
            // 'duel' game without exactly 2 players; see
            // BoardState::$hasSeparateDecks). The same catalog card can
            // therefore legitimately end up in both players' pools at
            // once; a card's identity within the game is its own
            // game_cards.id, not its catalog card_id -- see
            // BoardState::$catalogCardIdFor.
            if ($game['format'] === 'duel') {
                foreach ($playerIds as $playerId) {
                    $playerCardIds = $game['deck_type'] === 'custom_duel'
                        ? $customDuelDeckCardIds[$playerId]
                        : $this->deckCardIdsFor($game);
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

            $firstPlayerId = $playerIds[array_rand($playerIds)];

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

            $updateGame = $pdo->prepare("UPDATE games SET status = 'in_progress', started_at = NOW() WHERE id = :game_id");
            $updateGame->execute(['game_id' => $gameId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
            $result = $this->plays->playMood($state, $gamePlayerId, $cardId, $playerChoices);

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

            return $this->finishPlay($gameId, $round, $gamePlayerId, $state);
        });

        $this->touchLastMoveAt($gameId);

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

            return $this->advanceTurn($gameId, $round, $this->boardStates->load($gameId));
        });

        $this->touchLastMoveAt($gameId);

        return $result;
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

                if (in_array($decisionRow['decision_type'], self::SCORING_DECISION_TYPES, true)) {
                    // Enthusiasm's/Passion's own scoring-time decision (see
                    // scoreRoundAndAdvance()) rather than a mid-play one --
                    // resolved entirely differently: no MoodPlayService chain
                    // to resume, just either the next scoring decision still
                    // outstanding this round, or (once none remain) the same
                    // score/persist/advance tail that would have run
                    // immediately if no decision had ever been needed. Neither
                    // decision type moves a card, so this branch's own event
                    // has no BoardState to fold card history from.
                    $this->logEvent($gameId, $roundId, $gamePlayerId, 'pending_decision_resolved', $playedCardId, $choices);

                    $state = $this->boardStates->load($gameId);
                    $turnOrder = $this->rotate($this->seatOrder($gameId), (int) $round['first_game_player_id']);

                    $nextDecision = $this->nextUnresolvedScoringDecision($state, $roundId, $turnOrder);
                    if ($nextDecision !== null) {
                        $this->writeScoringDecisionBatch($gameId, $roundId, $state, $nextDecision);
                        $this->logEvent($gameId, $roundId, $nextDecision['ownerId'], 'pending_decision_created', $nextDecision['cardId'], [], $state);

                        $pdo->commit();

                        return ['round_scored' => false, 'game_completed' => false, 'pending_decision' => true];
                    }

                    $scoringDecisions = $this->resolvedScoringDecisionBonuses($state, $roundId);
                    $result = $this->finishScoringAndAdvance($gameId, $round, $turnOrder, $state, $scoringDecisions);
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
                $this->logEvent($gameId, $roundId, $gamePlayerId, 'pending_decision_resolved', $playedCardId, $choices, $state);

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
            return $this->finishPlay($gameId, $round, $initiatingPlayerId, $state);
        });

        $this->touchLastMoveAt($gameId);

        return $result;
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
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int}
     */
    private function finishPlay(int $gameId, array $round, int $actingGamePlayerId, BoardState $state): array
    {
        $this->boardStates->save($gameId, $state);

        if ($state->playsRemaining() > 0) {
            $this->updateRoundTurnState((int) $round['id'], $actingGamePlayerId, $state->pendingPlayGrants(), $state->discardedThisRound());

            return ['round_scored' => false, 'game_completed' => false];
        }

        return $this->advanceTurn($gameId, $round, $state);
    }

    /** @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int} */
    private function advanceTurn(int $gameId, array $round, BoardState $state): array
    {
        $turnOrder = $this->rotate($this->seatOrder($gameId), (int) $round['first_game_player_id']);
        $currentIndex = array_search((int) $round['current_turn_game_player_id'], $turnOrder, true);
        $nextIndex = $currentIndex + 1;

        if ($nextIndex >= count($turnOrder)) {
            return $this->scoreRoundAndAdvance($gameId, $round, $turnOrder, $state);
        }

        $nextPlayerId = $turnOrder[$nextIndex];
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
    private function scoreRoundAndAdvance(int $gameId, array $round, array $turnOrder, BoardState $state): array
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
                $this->logEvent($gameId, $roundId, $nextDecision['ownerId'], 'pending_decision_created', $nextDecision['cardId'], [], $state);

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
            $result = $this->finishScoringAndAdvance($gameId, $round, $turnOrder, $state, $scoringDecisions);
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
     * @param int[] $turnOrder
     * @param array<int, int> $scoringDecisions cardId => resolved bonus, see RoundScorer::score()
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int}
     */
    private function finishScoringAndAdvance(int $gameId, array $round, array $turnOrder, BoardState $state, array $scoringDecisions): array
    {
        $roundId = (int) $round['id'];
        $pdo = Connection::get();

        $scores = $this->applyScoreSwaps($state, $this->scorer->score($state, $scoringDecisions));
        $winnerId = $this->scorer->winner($scores, $turnOrder);
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
            foreach (array_keys($scores) as $playerId) {
                if ($playerId !== $winnerId) {
                    $state->drawCard($playerId);
                }
            }
        }
        $this->applyAfterScoringHooks($state, $winnerId);

        // Hurt Feelings only exists in games of 3 or more players.
        $hurtFeelingsHolder = count($turnOrder) >= 3 ? $this->scorer->hurtFeelings($scores, $turnOrder) : null;

        // Honor overrides who goes first next round regardless of who
        // won -- see BoardState::firstPlayerOverride(). Computed (and
        // computeFreshGrants() run) even if the game is about to
        // complete below and this ends up unused, so any banked grant
        // it consumes is captured by the same save() call either way.
        $nextFirstPlayer = $state->firstPlayerOverride() ?? $winnerId;
        $nextRoundGrants = $this->computeFreshGrants($state, $nextFirstPlayer, $hurtFeelingsHolder === $nextFirstPlayer ? 2 : 1);
        $this->boardStates->save($gameId, $state);

        $this->logEvent($gameId, $roundId, null, 'round_scored', null, [
            'scores' => $scores,
            'winner_game_player_id' => $winnerId,
        ], $state);

        if ($gameCompleting) {
            $completeGame = $pdo->prepare(
                "UPDATE games SET status = 'completed', winner_game_player_id = :winner, completed_at = NOW() WHERE id = :game_id"
            );
            $completeGame->execute(['winner' => $winnerId, 'game_id' => $gameId]);

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

        return ['round_scored' => true, 'game_completed' => false];
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
     * Corruption: "...or the winner of the current round wins two rounds
     * instead of one (each losing player still draws only one card)."
     * Doesn't matter who played Corruption or who ends up winning -- it's
     * unconditional on the round itself, unlike Bashfulness's
     * winner-dependent 'afterScoring' tag.
     */
    private function consumeExtraWinMarker(BoardState $state): int
    {
        $winsAwarded = 1;
        foreach ($state->moodsInPlay() as $mood) {
            if ($state->effectState($mood->cardId, 'awardsExtraWin')) {
                $state->clearEffectState($mood->cardId, 'awardsExtraWin');
                $winsAwarded = 2;
            }
        }

        return $winsAwarded;
    }

    /**
     * Resolves every mood's one-shot "after scoring" tag -- 'afterScoring'
     * (Bashfulness; Gluttony/Insecurity via MoodPlayService's
     * onUseEffectState; Recklessness's own "while in play" ability) and
     * 'returnsToOwnerAfterScoring' (Betrayal; the mood Recklessness took) --
     * then clears each tag so it doesn't reapply next round. Snapshots the
     * mood list up front since some actions remove the mood from play,
     * which would otherwise mutate moodsInPlay() mid-iteration.
     */
    private function applyAfterScoringHooks(BoardState $state, int $winnerId): void
    {
        foreach ($state->moodsInPlay() as $mood) {
            $cardId = $mood->cardId;
            $ownerId = $mood->ownerId;

            $afterScoring = $state->effectState($cardId, 'afterScoring');
            if ($afterScoring !== null) {
                $state->clearEffectState($cardId, 'afterScoring');
            }

            // Resolved before 'afterScoring' below, since a mood can carry
            // both tags at once (e.g. Recklessness took a mood that already
            // had its own after-scoring tag) and 'afterScoring' may remove
            // the mood from play, which would leave nothing for
            // giveInPlayToPlayer() to act on.
            $returnsToOwner = $state->effectState($cardId, 'returnsToOwnerAfterScoring');
            if ($returnsToOwner !== null) {
                $state->clearEffectState($cardId, 'returnsToOwnerAfterScoring');
                $state->giveInPlayToPlayer($cardId, $returnsToOwner['ownerId']);
            }

            if ($afterScoring !== null) {
                $conditionMet = ($afterScoring['condition'] ?? 'always') === 'always' || $ownerId === $winnerId;
                if ($conditionMet) {
                    match ($afterScoring['action']) {
                        'discard' => $state->moveInPlayToDiscard($cardId),
                        'return_to_hand' => $state->moveInPlayToHand($cardId),
                        'bottom_and_draw' => $this->bottomOfDeckAndDraw($state, $cardId, $ownerId),
                        default => throw new GameStateException("Unknown afterScoring action '{$afterScoring['action']}'"),
                    };
                }
            }
        }
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
     * effectiveSourceCardId(), which follows a Creativity copy back to
     * whatever it's actually copying -- e.g. a Creativity currently
     * copying Hope resolves to Hope's own instance id here, the same way
     * serializeCard() already shows that Creativity as "Hope" everywhere
     * else) so describePlayGrant() can name the actual source instead of
     * folding it into the base allowance's own "Your normal turn" -- a
     * bare null grant is reserved *only* for $baseCount's own entries.
     *
     * @return array<int, ?array{type?: string, values?: int[], source?: string, sourceCardId?: int}>
     */
    private function computeFreshGrants(BoardState $state, int $playerId, int $baseCount): array
    {
        $grants = array_fill(0, $baseCount, null);

        $hopeSourceCardId = $this->effectiveSourceCardId($state, $playerId, 'hope');
        if ($hopeSourceCardId !== null) {
            $grants[] = ['sourceCardId' => $hopeSourceCardId];
        }

        $graceSourceCardId = $this->effectiveSourceCardId($state, $playerId, 'grace');
        if ($graceSourceCardId !== null) {
            $grants[] = ['type' => 'shares_color_with_your_moods', 'source' => 'discard', 'sourceCardId' => $graceSourceCardId];
        }

        $stubbornnessSourceCardId = $this->effectiveSourceCardId($state, $playerId, 'stubbornness');
        if ($stubbornnessSourceCardId !== null && $this->anotherPlayerHasMoreMoods($state, $playerId)) {
            $grants[] = ['sourceCardId' => $stubbornnessSourceCardId];
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
     * The instance id of $playerId's own in-play mood whose EFFECTIVE
     * (copy-aware) effect key is $effectKey, or null if they have none --
     * the same effectiveCardId()-per-mood scan
     * BoardState::countMoodsInPlayWithEffectiveKey() does, except this
     * returns the resolved id itself (for a play grant's own
     * 'sourceCardId') rather than just a count. Effect keys this is
     * called with (hope/grace/stubbornness) are all singleton in
     * practice, so "first match" is never actually ambiguous.
     */
    private function effectiveSourceCardId(BoardState $state, int $playerId, string $effectKey): ?int
    {
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            $effectiveCardId = $state->effectiveCardId($mood->cardId);
            if ($state->catalogRow($effectiveCardId)['effectKey'] === $effectKey) {
                return $effectiveCardId;
            }
        }

        return null;
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

        $nextRoundGrants = $this->computeFreshGrants($state, $nextFirstPlayer, 1);

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
            $updateRound = $pdo->prepare(
                "UPDATE game_rounds SET status = 'scored', scored_at = NOW() WHERE id = :round_id"
            );
            $updateRound->execute(['round_id' => $roundId]);

            $this->boardStates->save($gameId, $state);

            $this->logEvent($gameId, $roundId, null, 'round_scored', null, ['skipped' => true], $state);

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

    /** @return array<int, array{id:int,format:string,deck_type:string,status:string,wins_needed:int,created_at:string,started_at:?string,last_move_at:?string,completed_at:?string,players:array<int,array{user_id:int,username:string,seat_order:int}>,is_your_turn:bool}> */
    public function listGamesForUser(int $userId): array
    {
        $pdo = Connection::get();

        // Waiting/in-progress games always sort above completed (or
        // abandoned) ones, regardless of recency -- a finished game is
        // never more actionable than an active one, no matter how old the
        // active one is. Within each of those two tiers, most-recently-
        // active first (last_move_at, falling back to started_at, then
        // created_at for a game nothing has happened in yet).
        $gameIdsStmt = $pdo->prepare(
            "SELECT g.id FROM games g
             JOIN game_players gp ON gp.game_id = g.id
             WHERE gp.user_id = :user_id
             ORDER BY
                 (g.status IN ('waiting', 'in_progress')) DESC,
                 COALESCE(g.last_move_at, g.started_at, g.created_at) DESC,
                 g.id DESC"
        );
        $gameIdsStmt->execute(['user_id' => $userId]);
        $gameIds = array_map(intval(...), $gameIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        $games = [];
        foreach ($gameIds as $gameId) {
            $game = $this->fetchGame($gameId);

            $playersStmt = $pdo->prepare(
                'SELECT gp.id, gp.user_id, gp.seat_order, u.username FROM game_players gp
                 JOIN users u ON u.id = gp.user_id
                 WHERE gp.game_id = :game_id ORDER BY gp.seat_order ASC'
            );
            $playersStmt->execute(['game_id' => $gameId]);
            $playerRows = $playersStmt->fetchAll();

            $yourGamePlayerId = null;
            $players = [];
            foreach ($playerRows as $row) {
                if ((int) $row['user_id'] === $userId) {
                    $yourGamePlayerId = (int) $row['id'];
                }
                $players[] = [
                    'user_id' => (int) $row['user_id'],
                    'username' => $row['username'],
                    'seat_order' => (int) $row['seat_order'],
                ];
            }

            $currentTurnGamePlayerId = null;
            if ($game['status'] === 'in_progress') {
                $roundStmt = $pdo->prepare(
                    "SELECT current_turn_game_player_id FROM game_rounds
                     WHERE game_id = :game_id AND status = 'in_progress'
                     ORDER BY round_number DESC LIMIT 1"
                );
                $roundStmt->execute(['game_id' => $gameId]);
                $currentTurnGamePlayerId = $roundStmt->fetchColumn();
                $currentTurnGamePlayerId = $currentTurnGamePlayerId !== false ? (int) $currentTurnGamePlayerId : null;
            }

            $games[] = [
                'id' => $gameId,
                'format' => $game['format'],
                'deck_type' => $game['deck_type'],
                'custom_deck_name' => $game['custom_deck_name'],
                'status' => $game['status'],
                'wins_needed' => (int) $game['wins_needed'],
                'created_at' => $game['created_at'],
                'started_at' => $game['started_at'],
                'last_move_at' => $game['last_move_at'],
                'completed_at' => $game['completed_at'],
                'players' => $players,
                'is_your_turn' => $yourGamePlayerId !== null && $yourGamePlayerId === $currentTurnGamePlayerId,
            ];
        }

        return $games;
    }

    /** @return array<string, mixed> */
    public function getState(int $gameId, int $viewerUserId): array
    {
        $viewerGamePlayerId = $this->gamePlayerIdFor($gameId, $viewerUserId);
        if ($viewerGamePlayerId === null) {
            throw new GameStateException("User {$viewerUserId} is not seated in game {$gameId}");
        }

        $game = $this->fetchGame($gameId);
        $pdo = Connection::get();

        $playersStmt = $pdo->prepare(
            'SELECT gp.id, gp.user_id, gp.seat_order, gp.custom_deck_name, gp.custom_deck_card_ids, u.username FROM game_players gp
             JOIN users u ON u.id = gp.user_id
             WHERE gp.game_id = :game_id ORDER BY gp.seat_order ASC'
        );
        $playersStmt->execute(['game_id' => $gameId]);
        $playerRows = $playersStmt->fetchAll();

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
                'hand_count' => $handCounts[(int) $row['id']] ?? 0,
                'total_wins' => $this->totalWinsFor($gameId, (int) $row['id']),
                // Overwritten below with the live sum of this player's
                // in-play moods' current values once BoardState is loaded
                // -- 0 here only actually applies to a 'waiting' game
                // (nothing dealt yet), the one status this function
                // returns for before ever reaching that point.
                'total_score' => 0,
                // Only meaningful for deck_type = 'custom_duel' -- each
                // player's own submitted decklist (see
                // submitCustomDuelDeck()), null/false for every other
                // deck_type since game_players.custom_deck_* is only ever
                // written for that one. deck_submitted lets the waiting
                // board show "waiting on Bob" without exposing Bob's own
                // decklist contents before the game starts.
                'custom_deck_name' => $row['custom_deck_name'],
                'deck_submitted' => $row['custom_deck_card_ids'] !== null,
            ];
        }

        $winnerUsername = null;
        if ($game['winner_game_player_id'] !== null) {
            foreach ($players as $player) {
                if ($player['game_player_id'] === (int) $game['winner_game_player_id']) {
                    $winnerUsername = $player['username'];
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
                'winner_game_player_id' => $game['winner_game_player_id'] !== null ? (int) $game['winner_game_player_id'] : null,
                'winner_username' => $winnerUsername,
            ],
            'players' => $players,
            'you' => ['game_player_id' => $viewerGamePlayerId],
            'round' => null,
            'in_play' => [],
            'discard_pile' => [],
            'deck_count' => 0,
        ];

        if ($game['status'] !== 'in_progress' && $game['status'] !== 'completed') {
            return $response;
        }

        $roundStmt = $pdo->prepare(
            'SELECT * FROM game_rounds WHERE game_id = :game_id ORDER BY round_number DESC LIMIT 1'
        );
        $roundStmt->execute(['game_id' => $gameId]);
        $roundRow = $roundStmt->fetch();

        $state = $this->boardStates->load($gameId);

        // A quality-of-life running total -- how many points a player
        // would score if the round ended and every in-play mood scored at
        // its current value -- so nobody has to manually add up the
        // numbers on their own board. Deliberately NOT the accumulated
        // game_round_scores total across previous rounds (that's what
        // total_wins already exists to summarize at the round-victory
        // level); this is always just a live snapshot of the board as it
        // stands right now, resetting to 0 the moment a round scores and
        // every mood leaves play.
        foreach ($response['players'] as &$player) {
            $player['total_score'] = $this->boardPointTotalFor($state, $player['game_player_id']);
        }
        unset($player);

        $names = $this->cardNamesFor($gameId);
        $playerNames = array_column($players, 'username', 'game_player_id');

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
                'hurt_feelings_game_player_id' => $roundRow['hurt_feelings_game_player_id'] !== null ? (int) $roundRow['hurt_feelings_game_player_id'] : null,
                'banned_colors' => $state->bannedColorsThisRound(),
                'discarded_this_round' => (bool) $roundRow['discarded_this_round'],
                'pending_decision' => $this->serializePendingDecision((int) $roundRow['id'], $viewerGamePlayerId),
                'scoring_preview' => $this->serializeScoringPreview($state, (int) $roundRow['id']),
                'scoring_effects' => $this->scoringEffectEntries($state, $names, $playerNames),
                'board_effects' => $this->boardEffectEntries($state, $names, $playerNames),
            ];
            $response['you']['is_your_turn'] = $currentTurnGamePlayerId === $viewerGamePlayerId;
        }

        $response['you']['hand'] = array_map(
            fn (int $cardId) => $this->serializeCard($state, $cardId, $names, $viewerGamePlayerId),
            $state->hand($viewerGamePlayerId)
        );

        foreach ($state->moodsInPlay() as $cardId => $mood) {
            $serialized = $this->serializeCard($state, $cardId, $names);
            $boosterCardId = $serialized['has_dice_value'] ? $state->diceValueBoosterCardId($cardId) : null;
            $response['in_play'][] = [
                ...$serialized,
                'owner_game_player_id' => $mood->ownerId,
                'is_suppressed' => $mood->isSuppressed,
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
            function (int $cardId) use ($state, $names, $viewerGamePlayerId, $playerNames): array {
                $lastOwnerId = $state->discardOwnerOf($cardId);
                return [
                    ...$this->serializeCard($state, $cardId, $names, $viewerGamePlayerId),
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
        // from before per-player decks existed.
        $response['deck_count'] = count($state->deck($viewerGamePlayerId));
        $response['recent_events'] = $this->recentEvents($gameId, $players);

        return $response;
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
    private function recentEvents(int $gameId, array $players, int $limit = 15): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, event_type, acting_game_player_id, card_id, details, created_at
             FROM game_events WHERE game_id = :game_id ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue('game_id', $gameId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $playerNames = array_column($players, 'username', 'game_player_id');
        $cardNames = $this->cardNamesFor($gameId);

        return array_map(
            fn (array $row) => [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'],
                'description' => $this->describeEvent($row, $playerNames, $cardNames),
            ],
            $stmt->fetchAll(),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $playerNames
     * @param array<int, string> $cardNames
     */
    private function describeEvent(array $row, array $playerNames, array $cardNames): string
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

        $description = match ($row['event_type']) {
            'mood_played' => "{$actor} played {$cardName}{$playedFromSuffix}{$grantUsedSuffix}",
            'turn_passed' => "{$actor} passed",
            'pending_decision_created' => "{$actor} played {$cardName}{$playedFromSuffix}{$grantUsedSuffix}, waiting on a response",
            'pending_decision_resolved' => "A response to {$cardName} was resolved",
            'round_scored' => $this->describeRoundScored($details, $playerNames),
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
            $drawParts = array_map(fn (int $playerId) => ($playerNames[$playerId] ?? 'A player') . ' drew a card', $draws);
            $description .= '; ' . implode('; ', $drawParts);
        }

        // Every extra play BoardState::consumeGrantsCreated() recorded for
        // this event -- source/restriction/zone, via the same
        // describeGrantDetails() wording a just-used grant gets above and
        // an outstanding one gets in round.play_grants (see
        // describePlayGrant()). Attributed to $actor: grantExtraPlay()
        // always grants to whoever's turn is currently active, which is
        // always the same player this event's own acting_game_player_id
        // already names (see $pendingGrantsCreated's own docblock).
        $grantsCreated = $details['grants_created'] ?? [];
        if ($grantsCreated !== []) {
            $grantParts = array_map(
                fn (?array $restriction) => "{$actor} was granted " . $this->describeGrantDetails($restriction ?? [], $cardNames),
                $grantsCreated,
            );
            $description .= '; ' . implode('; ', $grantParts);
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
     */
    private function describeRoundScored(array $details, array $playerNames): string
    {
        if ($details['skipped'] ?? false) {
            return 'Round scored (nobody won)';
        }

        $scoreParts = [];
        foreach ($details['scores'] ?? [] as $gamePlayerId => $score) {
            $scoreParts[] = ($playerNames[(int) $gamePlayerId] ?? 'a player') . ': ' . $score;
        }

        $description = 'Round scored';
        if ($scoreParts !== []) {
            $description .= ' (' . implode(', ', $scoreParts) . ')';
        }

        $winnerId = $details['winner_game_player_id'] ?? null;
        if ($winnerId !== null) {
            $description .= ' -- ' . ($playerNames[(int) $winnerId] ?? 'a player') . ' won';
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
            if (in_array($key, ['revealed_card_ids', 'skipped', 'card_moves', 'ownership_changes', 'played_from', 'draws', 'grants_created', 'grant_used'], true)) {
                continue; // already spoken for elsewhere in describeEvent()
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
     * @param ?array{type?: string, values?: int[], source?: string, sourceCardId?: int} $restriction
     * @param array<int, string> $cardNames
     * @return array{description:string, source_card_id:?int, source_card_name:?string}
     */
    private function describePlayGrant(?array $restriction, array $cardNames): array
    {
        if ($restriction === null) {
            // Only ever startTurn()'s own base allowance (1, or 2 with Hurt
            // Feelings) -- every actual grantExtraPlay() call always folds
            // in 'sourceCardId', so this is never a granted extra play.
            return ['description' => 'Your normal turn', 'source_card_id' => null, 'source_card_name' => null];
        }

        return [
            'description' => ucfirst($this->describeGrantDetails($restriction, $cardNames)),
            'source_card_id' => $restriction['sourceCardId'] ?? null,
            'source_card_name' => $this->sourceCardNameFor($restriction, $cardNames),
        ];
    }

    /** @param array<int, string> $cardNames */
    private function sourceCardNameFor(array $restriction, array $cardNames): ?string
    {
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
     * @param array{type?: string, values?: int[], source?: string, sourceCardId?: int} $restriction
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
    private function serializeCard(BoardState $state, int $cardId, array $names, ?int $reactingViewerId = null): array
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
                ...$this->reactionFields($state, $reactingViewerId, $color, $baseValue),
            ];
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
     * candidate's own raw color/base_value/catalog row (never the
     * "effective," copy-resolved one -- matches MoodPlayService::
     * playMood()'s own zero-hop $state->catalogRow($copiedCardId)
     * resolution exactly, so copying a copy correctly simulates "blank
     * Creativity," not whatever the copy itself was copying), plus
     * whether that candidate's own "to play" cost could be paid right
     * now. The client swaps in the matching bundle as copy_card_id
     * changes instead of needing a round trip -- see web-static/README.md.
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
            $candidateRow = $state->catalogRow($candidateCardId);
            $simulation[$candidateCardId] = [
                'extra_fields' => $this->reactionFields($state, $viewerId, $candidateRow['color'], $candidateRow['baseValue']),
                'cost_payable' => $this->plays->canPayCopiedToPlayCost($state, $viewerId, $creativityCardId, $candidateCardId),
            ];
        }

        return $simulation;
    }

    /**
     * Scorn's and Validation's reactToAnotherPlay() choices, filled in for
     * *this specific card* (see CardChoiceSchema::reactionTemplate()):
     * Scorn's suppress-target is narrowed to $color (matching the played
     * card's color, mirroring ScornEffect's own check); Validation's field
     * is included at all only when $baseValue is 0 or 1, since
     * ValidationEffect's reaction is a no-op for any other value.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reactionFields(BoardState $state, int $viewerId, string $color, int $baseValue): array
    {
        $fields = [];

        if ($state->playerHasMoodInPlay($viewerId, 'scorn')) {
            $fields[] = [
                ...CardChoiceSchema::reactionTemplate('scorn'),
                'filter' => ['colors' => [$color]],
            ];
        }

        if (in_array($baseValue, [0, 1], true) && $state->playerHasMoodInPlay($viewerId, 'validation')) {
            $fields[] = CardChoiceSchema::reactionTemplate('validation');
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

    /** @param array<int, ?array{type: string, values?: int[]}> $playGrants */
    private function updateRoundTurnState(int $roundId, int $playerId, array $playGrants, bool $discardedThisRound): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE game_rounds SET current_turn_game_player_id = :player_id, plays_remaining = :plays_remaining, pending_play_grants = :pending_play_grants, discarded_this_round = :discarded_this_round WHERE id = :round_id'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'plays_remaining' => count($playGrants),
            'pending_play_grants' => json_encode($playGrants),
            'discarded_this_round' => $discardedThisRound ? 1 : 0,
            'round_id' => $roundId,
        ]);
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
    private function serializePendingDecision(int $roundId, int $viewerGamePlayerId): ?array
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
            $result['field'] = json_decode((string) $decisionRow['field'], true);
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
        foreach ($result->pendingDecisions as $stepIndex => $decision) {
            $insertDecision->execute([
                'batch_id' => $batchId,
                'step_index' => $stepIndex,
                'target_player_id' => $decision->targetPlayerId,
                'decision_type' => $decision->decisionType,
                'field' => json_encode($decision->field),
            ]);
        }
    }

    /**
     * Folds whatever BoardState::consumeRevealedCardIds()/consumeCardMoves()/
     * consumeOwnershipChanges()/consumeDraws()/consumeGrantsCreated()/
     * consumeGrantUsed() have collected since the last event was logged
     * into $details before it's persisted -- see those methods' own
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
