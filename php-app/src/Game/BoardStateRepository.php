<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Database\Connection;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\EffectRegistry;
use PDO;

/**
 * Loads a BoardState from, and persists it back to, the `cards`/
 * `game_cards`/`game_players` tables. This is the only place the pure
 * in-memory rules engine (see src/Rules/) touches the database -- GameService
 * calls load() before resolving a play and save() after, so BoardState
 * itself never has to know it's backed by a database at all.
 *
 * save() always rewrites every one of a game's game_cards rows rather than
 * diffing what changed. With only 133 cards per game this is cheap, and it
 * sidesteps having to track which rows a given card effect touched --
 * BoardState itself never records that either, so a diff would mean
 * comparing full before/after state anyway.
 */
final class BoardStateRepository
{
    public function __construct(private readonly EffectRegistry $registry)
    {
    }

    public function load(int $gameId): BoardState
    {
        $pdo = Connection::get();

        $playersStmt = $pdo->prepare(
            'SELECT id FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC'
        );
        $playersStmt->execute(['game_id' => $gameId]);
        $playerIds = array_map(intval(...), $playersStmt->fetchAll(PDO::FETCH_COLUMN));

        $catalog = [];
        foreach ($pdo->query('SELECT * FROM cards') as $row) {
            $catalog[(int) $row['id']] = $this->mapCatalogRow($row);
        }

        $cardsStmt = $pdo->prepare('SELECT * FROM game_cards WHERE game_id = :game_id');
        $cardsStmt->execute(['game_id' => $gameId]);
        $gameCards = $cardsStmt->fetchAll();

        $cardIdBySurrogateId = [];
        foreach ($gameCards as $row) {
            $cardIdBySurrogateId[(int) $row['id']] = (int) $row['card_id'];
        }

        $hands = [];
        $deckByPosition = [];
        $discard = [];
        $inPlayRows = [];

        foreach ($gameCards as $row) {
            $cardId = (int) $row['card_id'];
            match ($row['zone']) {
                'hand' => $hands[(int) $row['owner_game_player_id']][] = $cardId,
                'deck' => $deckByPosition[(int) $row['deck_position']] = $cardId,
                'discard' => $discard[] = $cardId,
                'in_play' => $inPlayRows[] = $row,
            };
        }

        ksort($deckByPosition);

        $state = new BoardState($catalog, $this->registry, $playerIds, $hands, array_values($deckByPosition), $discard);

        foreach ($inPlayRows as $row) {
            $sourceCardId = $row['suppression_source_game_card_id'] !== null
                ? ($cardIdBySurrogateId[(int) $row['suppression_source_game_card_id']] ?? null)
                : null;

            $state->restoreMoodInPlay(
                (int) $row['card_id'],
                (int) $row['owner_game_player_id'],
                $row['copied_card_id'] !== null ? (int) $row['copied_card_id'] : null,
                (bool) $row['is_suppressed'],
                $row['suppression_expiry'],
                $sourceCardId,
                $row['effect_state'] !== null ? json_decode((string) $row['effect_state'], true) : [],
            );
        }

        $roundStmt = $pdo->prepare(
            "SELECT current_turn_game_player_id, first_game_player_id, plays_remaining, pending_play_grants FROM game_rounds
             WHERE game_id = :game_id AND status = 'in_progress'
             ORDER BY round_number DESC LIMIT 1"
        );
        $roundStmt->execute(['game_id' => $gameId]);
        $roundRow = $roundStmt->fetch();
        if ($roundRow !== false) {
            // pending_play_grants may be absent on older rows (e.g. before
            // any restricted grant existed this turn) -- in that case every
            // outstanding play is unconditional.
            $playGrants = $roundRow['pending_play_grants'] !== null
                ? json_decode((string) $roundRow['pending_play_grants'], true)
                : array_fill(0, (int) $roundRow['plays_remaining'], null);

            $state->restoreTurnState(
                $roundRow['current_turn_game_player_id'] !== null ? (int) $roundRow['current_turn_game_player_id'] : null,
                $playGrants,
                (int) $roundRow['first_game_player_id'],
            );
        }

        return $state;
    }

    public function save(int $gameId, BoardState $state): void
    {
        $pdo = Connection::get();

        $upsert = $pdo->prepare(
            'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, deck_position, copied_card_id, is_suppressed, suppression_expiry, effect_state)
             VALUES (:game_id, :card_id, :zone, :owner, :deck_position, :copied_card_id, :is_suppressed, :suppression_expiry, :effect_state)
             ON DUPLICATE KEY UPDATE
                zone = VALUES(zone),
                owner_game_player_id = VALUES(owner_game_player_id),
                deck_position = VALUES(deck_position),
                copied_card_id = VALUES(copied_card_id),
                is_suppressed = VALUES(is_suppressed),
                suppression_expiry = VALUES(suppression_expiry),
                effect_state = VALUES(effect_state),
                suppression_source_game_card_id = NULL'
        );

        $write = function (
            int $cardId,
            string $zone,
            ?int $owner,
            ?int $deckPosition,
            ?int $copiedCardId,
            bool $isSuppressed,
            ?string $suppressionExpiry,
            array $effectState,
        ) use ($upsert, $gameId): void {
            $upsert->execute([
                'game_id' => $gameId,
                'card_id' => $cardId,
                'zone' => $zone,
                'owner' => $owner,
                'deck_position' => $deckPosition,
                'copied_card_id' => $copiedCardId,
                'is_suppressed' => $isSuppressed ? 1 : 0,
                'suppression_expiry' => $suppressionExpiry,
                'effect_state' => $effectState === [] ? null : json_encode($effectState),
            ]);
        };

        foreach ($state->playerOrder() as $playerId) {
            foreach ($state->hand($playerId) as $cardId) {
                $write($cardId, 'hand', $playerId, null, null, false, null, []);
            }
        }

        foreach ($state->deck() as $position => $cardId) {
            $write($cardId, 'deck', null, $position, null, false, null, []);
        }

        foreach ($state->discardPile() as $cardId) {
            $write($cardId, 'discard', null, null, null, false, null, []);
        }

        /** @var array<int, int> cardId => its suppression source's cardId */
        $suppressionSources = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->suppressionSourceCardId !== null) {
                $suppressionSources[$mood->cardId] = $mood->suppressionSourceCardId;
            }
            $write(
                $mood->cardId,
                'in_play',
                $mood->ownerId,
                null,
                $mood->copiedCardId,
                $mood->isSuppressed,
                $mood->suppressionExpiry,
                $mood->effectState,
            );
        }

        // suppression_source_game_card_id is a self-referencing FK to
        // another row's surrogate id, which doesn't exist until after the
        // upserts above have run, so it's resolved and written in a second
        // pass.
        if ($suppressionSources !== []) {
            $surrogateIdByCardId = $this->surrogateIdsByCardId($gameId);
            $updateSource = $pdo->prepare(
                'UPDATE game_cards SET suppression_source_game_card_id = :source_id WHERE game_id = :game_id AND card_id = :card_id'
            );
            foreach ($suppressionSources as $cardId => $sourceCardId) {
                $updateSource->execute([
                    'source_id' => $surrogateIdByCardId[$sourceCardId] ?? null,
                    'game_id' => $gameId,
                    'card_id' => $cardId,
                ]);
            }
        }
    }

    /** @return array<int, int> card_id => game_cards.id */
    private function surrogateIdsByCardId(int $gameId): array
    {
        $stmt = Connection::get()->prepare('SELECT id, card_id FROM game_cards WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['card_id']] = (int) $row['id'];
        }

        return $map;
    }

    /** @return array{color:string,rarity:string,baseValue:int,altValue:?int,effectKey:string,hasToPlay:bool,hasWhileInPlay:bool,hasAfterPlaying:bool,rulesText:string} */
    private function mapCatalogRow(array $row): array
    {
        return [
            'color' => $row['color'],
            'rarity' => $row['rarity'],
            'baseValue' => (int) $row['base_value'],
            'altValue' => $row['alt_value'] !== null ? (int) $row['alt_value'] : null,
            'effectKey' => $row['effect_key'],
            'hasToPlay' => (bool) $row['has_to_play_ability'],
            'hasWhileInPlay' => (bool) $row['has_while_in_play_ability'],
            'hasAfterPlaying' => (bool) $row['has_after_playing_ability'],
            'rulesText' => $row['rules_text'],
        ];
    }
}
