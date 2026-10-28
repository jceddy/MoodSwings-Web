-- League play: decks are now chosen inside each League game (built from the
-- player's collection) rather than when queueing, so the queue no longer
-- carries a deck snapshot.
--
-- This also resets League as if the event had just been started: every League
-- match (and the games behind it), queue entry, participant and collection is
-- wiped, so each player's first join of the (still active) event grants a
-- fresh starter deck again. Ended events are left as history.

-- The starter decks first-joins saved as private decklists ("League deck -
-- <event>"); a re-join would otherwise save a second copy of each.
DELETE d FROM user_decklists d
    JOIN league_participants p ON p.user_id = d.user_id
    JOIN league_events e ON e.id = p.event_id
    WHERE d.name = LEFT(CONCAT('League deck - ', e.name), 120);

-- DELETE FROM games cascades to every per-game table.
DELETE FROM games WHERE game_match_id IN (SELECT game_match_id FROM league_matches);
DELETE FROM game_matches WHERE id IN (SELECT game_match_id FROM league_matches);

DELETE FROM league_matches;
DELETE FROM league_queue;
DELETE FROM league_participants;
DELETE FROM user_collection_cards;

UPDATE league_events SET started_at = NOW() WHERE status = 'active';

ALTER TABLE league_queue
    DROP COLUMN deck_name,
    DROP COLUMN deck_card_ids;

UPDATE schema_version SET version = '1.63.3' WHERE id = 1;
