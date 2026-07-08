<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Database\Connection;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\CardChoiceSchema;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RoundScorer;
use PDO;
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

    /** @var ?array<int, string> card_id => name, memoized per instance by cardCatalogNames() */
    private ?array $cardNames = null;

    public function __construct(
        private readonly BoardStateRepository $boardStates,
        private readonly MoodPlayService $plays,
        private readonly RoundScorer $scorer,
    ) {
    }

    /** @param int[] $userIds seat order follows array order */
    public function createGame(int $createdByUserId, array $userIds, string $format = 'standard', int $winsNeeded = 3): int
    {
        if (count($userIds) > self::MAX_PLAYERS) {
            throw new GameStateException('A game cannot have more than ' . self::MAX_PLAYERS . ' players');
        }

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
            $insertGame = $pdo->prepare(
                "INSERT INTO games (format, status, created_by_user_id, wins_needed)
                 VALUES (:format, 'waiting', :created_by, :wins_needed)"
            );
            $insertGame->execute([
                'format' => $format,
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

        $cardIds = range(1, self::TOTAL_CARDS);
        shuffle($cardIds);

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
            $insertCard = $pdo->prepare(
                'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, deck_position)
                 VALUES (:game_id, :card_id, :zone, :owner, :deck_position)'
            );

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
     * @param array<string, mixed> $choices
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int}
     */
    public function playMood(int $gameId, int $gamePlayerId, int $cardId, array $choices): array
    {
        $round = $this->currentRound($gameId);
        $state = $this->boardStates->load($gameId);

        $this->plays->playMood($state, $gamePlayerId, $cardId, new PlayerChoices($choices));

        $this->boardStates->save($gameId, $state);
        $this->logEvent($gameId, (int) $round['id'], $gamePlayerId, 'mood_played', $cardId, $choices);

        if ($state->playsRemaining() > 0) {
            $this->updateRoundTurnState((int) $round['id'], $gamePlayerId, $state->pendingPlayGrants(), $state->discardedThisRound());

            return ['round_scored' => false, 'game_completed' => false];
        }

        return $this->advanceTurn($gameId, $round, $state);
    }

    /** @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int} */
    public function pass(int $gameId, int $gamePlayerId): array
    {
        $round = $this->currentRound($gameId);

        if ((int) $round['current_turn_game_player_id'] !== $gamePlayerId) {
            throw new GameStateException("It is not player {$gamePlayerId}'s turn");
        }

        $this->logEvent($gameId, (int) $round['id'], $gamePlayerId, 'turn_passed', null, []);

        return $this->advanceTurn($gameId, $round, $this->boardStates->load($gameId));
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
     * @param int[] $turnOrder the order players took their turns this round, earliest first
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int}
     */
    private function scoreRoundAndAdvance(int $gameId, array $round, array $turnOrder, BoardState $state): array
    {
        $roundId = (int) $round['id'];

        if ($this->hasSkipScoringMarker($state)) {
            return $this->skipScoringAndAdvance($gameId, $round, $state);
        }

        $scores = $this->applyScoreSwaps($state, $this->scorer->score($state));
        $winnerId = $this->scorer->winner($scores, $turnOrder);
        $winsAwarded = $this->consumeExtraWinMarker($state);

        $pdo = Connection::get();
        $pdo->beginTransaction();

        try {
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

            foreach (array_keys($scores) as $playerId) {
                if ($playerId !== $winnerId) {
                    $state->drawCard($playerId);
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
            ]);

            $totalWins = $this->totalWinsFor($gameId, $winnerId);
            $winsNeeded = (int) $this->fetchGame($gameId)['wins_needed'];

            if ($totalWins >= $winsNeeded) {
                $completeGame = $pdo->prepare(
                    "UPDATE games SET status = 'completed', winner_game_player_id = :winner, completed_at = NOW() WHERE id = :game_id"
                );
                $completeGame->execute(['winner' => $winnerId, 'game_id' => $gameId]);

                $pdo->commit();

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

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['round_scored' => true, 'game_completed' => false];
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
            $returnsToOwnerId = $state->effectState($cardId, 'returnsToOwnerAfterScoring');
            if ($returnsToOwnerId !== null) {
                $state->clearEffectState($cardId, 'returnsToOwnerAfterScoring');
                $state->giveInPlayToPlayer($cardId, $returnsToOwnerId);
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
     * @return array<int, ?array{type?: string, values?: int[], source?: string}>
     */
    private function computeFreshGrants(BoardState $state, int $playerId, int $baseCount): array
    {
        $grants = array_fill(0, $baseCount, null);

        if ($state->playerHasMoodInPlay($playerId, 'hope')) {
            $grants[] = null;
        }
        if ($state->playerHasMoodInPlay($playerId, 'grace')) {
            $grants[] = ['type' => 'shares_color_with_your_moods', 'source' => 'discard'];
        }
        if ($state->playerHasMoodInPlay($playerId, 'stubbornness') && $this->anotherPlayerHasMoreMoods($state, $playerId)) {
            $grants[] = null;
        }

        foreach ($state->moodsInPlay() as $mood) {
            if ($state->effectState($mood->cardId, 'banksExtraPlayForPlayerId') === $playerId) {
                $state->clearEffectState($mood->cardId, 'banksExtraPlayForPlayerId');
                $grants[] = null;
            }
        }

        return $grants;
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

            $this->logEvent($gameId, $roundId, null, 'round_scored', null, ['skipped' => true]);

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

    /** @return array<int, array{id:int,format:string,status:string,wins_needed:int,created_at:string,started_at:?string,players:array<int,array{user_id:int,username:string,seat_order:int}>,is_your_turn:bool}> */
    public function listGamesForUser(int $userId): array
    {
        $pdo = Connection::get();

        $gameIdsStmt = $pdo->prepare(
            'SELECT g.id FROM games g
             JOIN game_players gp ON gp.game_id = g.id
             WHERE gp.user_id = :user_id
             ORDER BY COALESCE(g.started_at, g.created_at) DESC, g.id DESC'
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
                'status' => $game['status'],
                'wins_needed' => (int) $game['wins_needed'],
                'created_at' => $game['created_at'],
                'started_at' => $game['started_at'],
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
            'SELECT gp.id, gp.user_id, gp.seat_order, u.username FROM game_players gp
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

        $cardIds = array_keys($this->cardCatalogNames());

        if ($roundRow !== false) {
            $currentTurnGamePlayerId = $roundRow['current_turn_game_player_id'] !== null ? (int) $roundRow['current_turn_game_player_id'] : null;
            $response['round'] = [
                'round_number' => (int) $roundRow['round_number'],
                'status' => $roundRow['status'],
                'current_turn_game_player_id' => $currentTurnGamePlayerId,
                'plays_remaining' => (int) $roundRow['plays_remaining'],
                'first_game_player_id' => (int) $roundRow['first_game_player_id'],
                'hurt_feelings_game_player_id' => $roundRow['hurt_feelings_game_player_id'] !== null ? (int) $roundRow['hurt_feelings_game_player_id'] : null,
                'banned_colors' => $state->bannedColorsThisRound(),
                'discarded_this_round' => (bool) $roundRow['discarded_this_round'],
            ];
            $response['you']['is_your_turn'] = $currentTurnGamePlayerId === $viewerGamePlayerId;
        }

        $response['you']['hand'] = array_map(
            fn (int $cardId) => $this->serializeCard($state, $cardId, $viewerGamePlayerId),
            $state->hand($viewerGamePlayerId)
        );

        $names = $this->cardCatalogNames();
        foreach ($state->moodsInPlay() as $cardId => $mood) {
            $response['in_play'][] = [
                ...$this->serializeCard($state, $cardId),
                'owner_game_player_id' => $mood->ownerId,
                'is_suppressed' => $mood->isSuppressed,
                'suppression_expiry' => $mood->suppressionExpiry,
                'suppressed_by_card_id' => $mood->suppressionSourceCardId,
                'suppressed_by_name' => $mood->suppressionSourceCardId !== null
                    ? ($names[$mood->suppressionSourceCardId] ?? null)
                    : null,
            ];
        }

        $response['discard_pile'] = array_map(
            fn (int $cardId) => $this->serializeCard($state, $cardId),
            $state->discardPile()
        );

        $response['deck_count'] = count($state->deck());

        return $response;
    }

    /**
     * colorOf()/valueOf() reflect live "while in play" effects (Imagination,
     * suppression, etc.) and only work for cards currently in play -- for a
     * card sitting in a hand or the discard pile there's no live effect to
     * apply, so its printed catalog color/base value is what's shown.
     *
     * $reactingViewerId is only passed for a card in the *viewer's own
     * hand* -- it's what lets this method decide whether to append Scorn's/
     * Validation's reactToAnotherPlay() fields (see CardChoiceSchema's
     * docblock): both react to the viewer's own subsequent plays, so they
     * only ever apply to a card the viewer might actually play, never to
     * an in-play or discard-pile card being merely displayed. The same
     * flag gates 'is_playable' (MoodPlayService::isPlayable()) -- true by
     * default for an in-play/discard-pile card being merely displayed,
     * since nothing there ever reads it.
     *
     * @return array{card_id:int,name:string,color:string,value:int,base_value:int,alt_value:?int,effect_key:string,rules_text:string,has_dice_value:bool,choice_fields:array<int,array<string,mixed>>,is_playable:bool}
     */
    private function serializeCard(BoardState $state, int $cardId, ?int $reactingViewerId = null): array
    {
        $catalog = $state->catalogRow($cardId);
        $names = $this->cardCatalogNames();
        $inPlay = $state->isInPlay($cardId);

        // A Creativity copy's dice value (like its color/value) comes from
        // whatever card it's currently copying, not from Creativity's own
        // (dice-less) catalog row -- see EncouragementEffect, which checks
        // the same effectiveCardId() for exactly this reason.
        $diceValueCatalog = $inPlay ? $state->catalogRow($state->effectiveCardId($cardId)) : $catalog;
        $color = $inPlay ? $state->colorOf($cardId) : $catalog['color'];
        $baseValue = $diceValueCatalog['baseValue'];

        $choiceFields = CardChoiceSchema::forEffectKey($catalog['effectKey']);
        if ($reactingViewerId !== null) {
            $choiceFields = [
                ...$choiceFields,
                ...$this->reactionFields($state, $reactingViewerId, $color, $baseValue),
                ...$this->duplicityFields($state, $reactingViewerId, $catalog),
            ];
        }

        return [
            'card_id' => $cardId,
            'name' => $names[$cardId] ?? $catalog['effectKey'],
            'color' => $color,
            'value' => $inPlay ? $state->valueOf($cardId) : $catalog['baseValue'],
            'base_value' => $baseValue,
            'alt_value' => $diceValueCatalog['altValue'],
            'effect_key' => $catalog['effectKey'],
            'rules_text' => $catalog['rulesText'],
            'has_dice_value' => $diceValueCatalog['altValue'] !== null,
            'choice_fields' => $choiceFields,
            'is_playable' => $reactingViewerId === null || $this->plays->isPlayable($state, $reactingViewerId, $cardId),
        ];
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
     * Duplicity's repeat-with-fresh-choices fields, appended for *this
     * specific card* when the viewer has Duplicity in play. Gated on
     * $catalog['hasAfterPlaying'] -- the card's own *raw* (non-Creativity-
     * copy-aware) flag, deliberately: MoodPlayService gates the real
     * repeat on the *effective* (copy-aware) row, but since Creativity's
     * copy_card_id is only known once the play is actually submitted, this
     * can't precompute the right nested fields for a Creativity play here
     * -- so Creativity (whose own raw hasAfterPlaying is false) simply
     * never gets a repeat option, rather than offering a wrong one. See
     * CardChoiceSchema's docblock for the same note.
     *
     * @param array{effectKey:string,hasAfterPlaying:bool} $catalog
     * @return array<int, array<string, mixed>>
     */
    private function duplicityFields(BoardState $state, int $viewerId, array $catalog): array
    {
        if (
            !$catalog['hasAfterPlaying']
            || $catalog['effectKey'] === 'duplicity'
            || !$state->playerHasMoodInPlay($viewerId, 'duplicity')
        ) {
            return [];
        }

        return [
            CardChoiceSchema::reactionTemplate('duplicity'),
            [
                'key' => 'duplicity_repeat_choices',
                'type' => 'nested',
                'required' => false,
                'label' => 'Choices for the repeat (only used if repeating above)',
                'fields' => CardChoiceSchema::afterPlayingFields($catalog['effectKey']),
            ],
        ];
    }

    /** @return array<int, string> card_id => name */
    private function cardCatalogNames(): array
    {
        if ($this->cardNames === null) {
            $stmt = Connection::get()->query('SELECT id, name FROM cards');
            $this->cardNames = [];
            foreach ($stmt->fetchAll() as $row) {
                $this->cardNames[(int) $row['id']] = $row['name'];
            }
        }

        return $this->cardNames;
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

    /** @param array<string, mixed> $details */
    private function logEvent(int $gameId, ?int $roundId, ?int $actingPlayerId, string $eventType, ?int $cardId, array $details): void
    {
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
