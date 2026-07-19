-- Adds a real collector_number to card_sets (issue #92 follow-up). A
-- saved decklist's own decklist-text format (see DecklistParser /
-- "Saved decklists" in php-app/README.md) writes each card as
-- "1 Name (SET) NUMBER" -- until now NUMBER was just the card's own
-- catalog id (cards.id), reused as a stand-in since there was no
-- dedicated per-printing numbering field. That was never quite right:
-- collector_number belongs on card_sets, not cards, for the exact same
-- reprint-safety reason the card_sets join table itself exists (see
-- migration 0015's own header comment) -- a card's collector number is
-- a property of one specific printing, not the card as a whole, and a
-- future reprint in a second Set would need its own separate number.
--
-- Populated 1-133 in cards.id order for the existing MSW printing, the
-- only one that exists today. This is a from-scratch custom card game
-- with no external canonical numbering to defer to, so cards.id order
-- -- already the authority for art-file naming, see "Assets" in
-- web-static/README.md -- is simply promoted to be the collector-number
-- order too, rather than an arbitrary renumbering.
ALTER TABLE card_sets ADD COLUMN collector_number SMALLINT UNSIGNED NULL AFTER set_id;

UPDATE card_sets cs
JOIN sets s ON s.id = cs.set_id
SET cs.collector_number = cs.card_id
WHERE s.code = 'MSW';

ALTER TABLE card_sets MODIFY COLUMN collector_number SMALLINT UNSIGNED NOT NULL;

UPDATE schema_version SET version = '0.13.0' WHERE id = 1;
