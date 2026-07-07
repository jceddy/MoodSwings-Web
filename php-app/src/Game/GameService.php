<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Database\Connection;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Rules\BoardState;
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
 * consumeExtraWinMarker() (Corruption).
 *
 * Deliberately out of scope for now: any HTTP/auth layer -- this takes
 * game_player ids directly and treats them as already-authorized.
 */
final class GameService
{
    private const STARTING_HAND_SIZE = 5;
    private const TOTAL_CARDS = 133;

    public function __construct(
        private readonly BoardStateRepository $boardStates,
        private readonly MoodPlayService $plays,
        private readonly RoundScorer $scorer,
    ) {
    }

    /** @param int[] $userIds seat order follows array order */
    public function createGame(int $createdByUserId, array $userIds, string $format = 'standard', int $winsNeeded = 3): int
    {
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
        if (count($playerIds) < 2) {
            throw new GameStateException("Game {$gameId} needs at least 2 players to start");
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
            $this->updateRoundTurnState((int) $round['id'], $gamePlayerId, $state->pendingPlayGrants());

            return ['round_scored' => false, 'game_completed' => false];
        }

        return $this->advanceTurn($gameId, $round);
    }

    /** @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int} */
    public function pass(int $gameId, int $gamePlayerId): array
    {
        $round = $this->currentRound($gameId);

        if ((int) $round['current_turn_game_player_id'] !== $gamePlayerId) {
            throw new GameStateException("It is not player {$gamePlayerId}'s turn");
        }

        $this->logEvent($gameId, (int) $round['id'], $gamePlayerId, 'turn_passed', null, []);

        return $this->advanceTurn($gameId, $round);
    }

    /** @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int} */
    private function advanceTurn(int $gameId, array $round): array
    {
        $turnOrder = $this->rotate($this->seatOrder($gameId), (int) $round['first_game_player_id']);
        $currentIndex = array_search((int) $round['current_turn_game_player_id'], $turnOrder, true);
        $nextIndex = $currentIndex + 1;

        if ($nextIndex >= count($turnOrder)) {
            return $this->scoreRoundAndAdvance($gameId, $round, $turnOrder);
        }

        $nextPlayerId = $turnOrder[$nextIndex];
        $hurtFeelingsHolder = $round['hurt_feelings_game_player_id'] !== null ? (int) $round['hurt_feelings_game_player_id'] : null;

        // A fresh turn's plays are always unconditional grants -- any
        // restriction from the previous player's cards only ever applied
        // to their own turn.
        $freshGrants = array_fill(0, $nextPlayerId === $hurtFeelingsHolder ? 2 : 1, null);
        $this->updateRoundTurnState((int) $round['id'], $nextPlayerId, $freshGrants);

        return ['round_scored' => false, 'game_completed' => false];
    }

    /**
     * @param int[] $turnOrder the order players took their turns this round, earliest first
     * @return array{round_scored: bool, game_completed: bool, winner_game_player_id?: int}
     */
    private function scoreRoundAndAdvance(int $gameId, array $round, array $turnOrder): array
    {
        $roundId = (int) $round['id'];
        $state = $this->boardStates->load($gameId);

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

            // Hurt Feelings only exists in games of 3 or more players.
            $hurtFeelingsHolder = count($turnOrder) >= 3 ? $this->scorer->hurtFeelings($scores, $turnOrder) : null;

            // Honor overrides who goes first next round regardless of who
            // won -- see BoardState::firstPlayerOverride().
            $nextFirstPlayer = $state->firstPlayerOverride() ?? $winnerId;
            $nextRoundPlaysRemaining = $hurtFeelingsHolder === $nextFirstPlayer ? 2 : 1;

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
                'plays_remaining' => $nextRoundPlaysRemaining,
                'pending_play_grants' => json_encode(array_fill(0, $nextRoundPlaysRemaining, null)),
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
                 VALUES (:game_id, :round_number, :first_player, :first_player_turn, 1, :pending_play_grants, 'in_progress')"
            );
            $insertRound->execute([
                'game_id' => $gameId,
                'round_number' => (int) $round['round_number'] + 1,
                'first_player' => $nextFirstPlayer,
                'first_player_turn' => $nextFirstPlayer,
                'pending_play_grants' => json_encode([null]),
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['round_scored' => true, 'game_completed' => false];
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
    private function updateRoundTurnState(int $roundId, int $playerId, array $playGrants): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE game_rounds SET current_turn_game_player_id = :player_id, plays_remaining = :plays_remaining, pending_play_grants = :pending_play_grants WHERE id = :round_id'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'plays_remaining' => count($playGrants),
            'pending_play_grants' => json_encode($playGrants),
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
