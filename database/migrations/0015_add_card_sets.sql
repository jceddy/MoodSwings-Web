-- Every card belongs to at least one Set (a printed product, identified by
-- a short set code the way physical/digital card games label packs -- e.g.
-- Magic's "war of the spark" is "WAR"). The only Set that exists today is
-- the base game itself, "Mood Swings" (MSW), which all 133 existing cards
-- belong to.
--
-- This is a many-to-many join (card_sets), not a set_id column directly on
-- cards, because a card reappearing in a later set (a reprint, a
-- crossover/anniversary product, etc.) is expected to eventually happen
-- even though it's not possible with only one Set defined yet -- a single
-- FK column would have to be widened into a join table the moment that
-- happened anyway, so it's modeled that way from the start.
CREATE TABLE IF NOT EXISTS sets (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL,
    name VARCHAR(60) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sets_code (code),
    UNIQUE KEY uq_sets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sets (code, name) VALUES ('MSW', 'Mood Swings');

CREATE TABLE IF NOT EXISTS card_sets (
    card_id SMALLINT UNSIGNED NOT NULL,
    set_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (card_id, set_id),
    CONSTRAINT fk_card_sets_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE CASCADE,
    CONSTRAINT fk_card_sets_set FOREIGN KEY (set_id) REFERENCES sets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO card_sets (card_id, set_id)
SELECT c.id, s.id FROM cards c, sets s WHERE s.code = 'MSW';
