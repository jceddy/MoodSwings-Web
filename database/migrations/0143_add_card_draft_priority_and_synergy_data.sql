-- Issue #359 (draft practice bots): a bot picking cards during a draft
-- needs some notion of "how good is this card" beyond base_value (which
-- only reflects a card's own printed scoring value, not its overall
-- draft desirability -- plenty of low-value cards are strong for their
-- abilities, and plenty of high-value cards are weak without support).
-- This adds two pieces of externally-curated reference data, supplied by
-- the maintainer as a spreadsheet and imported here the same way migration
-- 0003 seeded the card catalog itself -- literal data, not a runtime
-- import path (there isn't one in this codebase, and one card set's worth
-- of reference data doesn't justify adding one):
--
-- 1. draft_priority_score: a general draft-pick-priority ranking across
--    all 133 cards (higher = better), independent of any particular
--    synergy. Tiered rather than a strict 1-133 ordering (many cards
--    share a score) -- that's how the source data itself is shaped, and
--    ties are fine for a bot: BotPlayerService will need its own
--    tie-breaking anyway (e.g. card_stats win rate) once two candidates
--    land on the same score.
-- 2. card_synergy_partners: 5 build-around mythics (Validation,
--    Exhilaration, Bliss, Duplicity, Thrill), each paired with its own 14
--    curated non-mythic partner cards that a bot should prioritize more
--    highly once it has drafted that mythic. A join table rather than a
--    "synergy group" table with its own identity/name, because every
--    group here is anchored by exactly one specific mythic card -- the
--    mythic's own row in `cards` already *is* the group's identity, and
--    "which mythic does this partner belong to" is the only question
--    BotPlayerService will ever need answered (WHERE mythic_card_id = ?).
--    Not necessarily disjoint sets (a card can partner with more than one
--    mythic) and deliberately NOT reciprocal/symmetric -- a mythic isn't
--    listed as its own partner, and the 5 mythics aren't cross-linked to
--    each other here even though two of them do appear on each other's
--    partner lists as plain draft-priority-ranked cards.

ALTER TABLE cards
    ADD COLUMN draft_priority_score SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER base_value;

CREATE TABLE IF NOT EXISTS card_synergy_partners (
    mythic_card_id SMALLINT UNSIGNED NOT NULL,
    partner_card_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (mythic_card_id, partner_card_id),
    CONSTRAINT fk_card_synergy_partners_mythic FOREIGN KEY (mythic_card_id) REFERENCES cards (id) ON DELETE CASCADE,
    CONSTRAINT fk_card_synergy_partners_partner FOREIGN KEY (partner_card_id) REFERENCES cards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every one of the 133 catalog cards gets its own score below (source
-- spreadsheet covers the full catalog) -- the ELSE branch/DEFAULT 1 above
-- is only ever a safety net for a future card added without an updated
-- ranking, not something any card ships with today.
UPDATE cards SET draft_priority_score = CASE name
    WHEN 'Creativity' THEN 40
    WHEN 'Intimidation' THEN 40
    WHEN 'Rationalization' THEN 24
    WHEN 'Recklessness' THEN 24
    WHEN 'Paranoia' THEN 20
    WHEN 'Bliss' THEN 16
    WHEN 'Duplicity' THEN 16
    WHEN 'Euphoria' THEN 16
    WHEN 'Exhilaration' THEN 16
    WHEN 'Hope' THEN 16
    WHEN 'Regret' THEN 16
    WHEN 'Thrill' THEN 16
    WHEN 'Validation' THEN 16
    WHEN 'Anger' THEN 12
    WHEN 'Compulsion' THEN 10
    WHEN 'Hate' THEN 10
    WHEN 'Awe' THEN 8
    WHEN 'Denial' THEN 8
    WHEN 'Harmony' THEN 8
    WHEN 'Insecurity' THEN 8
    WHEN 'Melancholy' THEN 8
    WHEN 'Passion' THEN 8
    WHEN 'Fear' THEN 6
    WHEN 'Conviction' THEN 4
    WHEN 'Eagerness' THEN 4
    WHEN 'Enthusiasm' THEN 4
    WHEN 'Fondness' THEN 4
    WHEN 'Gluttony' THEN 4
    WHEN 'Nostalgia' THEN 4
    WHEN 'Panic' THEN 4
    WHEN 'Suspicion' THEN 4
    WHEN 'Zeal' THEN 4
    WHEN 'Ambition' THEN 2
    WHEN 'Bravado' THEN 2
    WHEN 'Determination' THEN 2
    WHEN 'Joy' THEN 2
    WHEN 'Pacifism' THEN 2
    WHEN 'Shock' THEN 2
    WHEN 'Altruism' THEN 1
    WHEN 'Ambivalence' THEN 1
    WHEN 'Angst' THEN 1
    WHEN 'Animosity' THEN 1
    WHEN 'Anxiety' THEN 1
    WHEN 'Apathy' THEN 1
    WHEN 'Arrogance' THEN 1
    WHEN 'Avoidance' THEN 1
    WHEN 'Bashfulness' THEN 1
    WHEN 'Benevolence' THEN 1
    WHEN 'Betrayal' THEN 1
    WHEN 'Bitterness' THEN 1
    WHEN 'Boredom' THEN 1
    WHEN 'Celebration' THEN 1
    WHEN 'Chaos' THEN 1
    WHEN 'Charity' THEN 1
    WHEN 'Cheer' THEN 1
    WHEN 'Chivalry' THEN 1
    WHEN 'Complacency' THEN 1
    WHEN 'Condescension' THEN 1
    WHEN 'Confusion' THEN 1
    WHEN 'Contempt' THEN 1
    WHEN 'Corruption' THEN 1
    WHEN 'Courage' THEN 1
    WHEN 'Cruelty' THEN 1
    WHEN 'Curiosity' THEN 1
    WHEN 'Cynicism' THEN 1
    WHEN 'Delight' THEN 1
    WHEN 'Dignity' THEN 1
    WHEN 'Discipline' THEN 1
    WHEN 'Disgust' THEN 1
    WHEN 'Disillusionment' THEN 1
    WHEN 'Disorientation' THEN 1
    WHEN 'Disregard' THEN 1
    WHEN 'Doubt' THEN 1
    WHEN 'Embarrassment' THEN 1
    WHEN 'Encouragement' THEN 1
    WHEN 'Enjoyment' THEN 1
    WHEN 'Envy' THEN 1
    WHEN 'Excitement' THEN 1
    WHEN 'Faith' THEN 1
    WHEN 'Fascination' THEN 1
    WHEN 'Fickleness' THEN 1
    WHEN 'Friendliness' THEN 1
    WHEN 'Frustration' THEN 1
    WHEN 'Fury' THEN 1
    WHEN 'Generosity' THEN 1
    WHEN 'Glee' THEN 1
    WHEN 'Grace' THEN 1
    WHEN 'Grief' THEN 1
    WHEN 'Guile' THEN 1
    WHEN 'Guilt' THEN 1
    WHEN 'Happiness' THEN 1
    WHEN 'Hesitation' THEN 1
    WHEN 'Honor' THEN 1
    WHEN 'Hostility' THEN 1
    WHEN 'Idealism' THEN 1
    WHEN 'Imagination' THEN 1
    WHEN 'Indecisiveness' THEN 1
    WHEN 'Indifference' THEN 1
    WHEN 'Infatuation' THEN 1
    WHEN 'Instability' THEN 1
    WHEN 'Kindness' THEN 1
    WHEN 'Laziness' THEN 1
    WHEN 'Love' THEN 1
    WHEN 'Loyalty' THEN 1
    WHEN 'Malice' THEN 1
    WHEN 'Meekness' THEN 1
    WHEN 'Misery' THEN 1
    WHEN 'Neurosis' THEN 1
    WHEN 'Obsession' THEN 1
    WHEN 'Patience' THEN 1
    WHEN 'Pity' THEN 1
    WHEN 'Pride' THEN 1
    WHEN 'Rage' THEN 1
    WHEN 'Rebellion' THEN 1
    WHEN 'Rejection' THEN 1
    WHEN 'Repentance' THEN 1
    WHEN 'Sadness' THEN 1
    WHEN 'Scorn' THEN 1
    WHEN 'Self-Loathing' THEN 1
    WHEN 'Serenity' THEN 1
    WHEN 'Shame' THEN 1
    WHEN 'Sloth' THEN 1
    WHEN 'Sneakiness' THEN 1
    WHEN 'Spite' THEN 1
    WHEN 'Stubbornness' THEN 1
    WHEN 'Superiority' THEN 1
    WHEN 'Tranquility' THEN 1
    WHEN 'Triumph' THEN 1
    WHEN 'Vanity' THEN 1
    WHEN 'Vulnerability' THEN 1
    WHEN 'Wonder' THEN 1
    WHEN 'Worry' THEN 1
    WHEN 'Wrath' THEN 1
    ELSE draft_priority_score
END;

INSERT INTO card_synergy_partners (mythic_card_id, partner_card_id)
SELECT m.id, p.id FROM cards m JOIN cards p WHERE (m.name, p.name) IN (
    ('Validation', 'Creativity'),
    ('Validation', 'Intimidation'),
    ('Validation', 'Rationalization'),
    ('Validation', 'Recklessness'),
    ('Validation', 'Paranoia'),
    ('Validation', 'Euphoria'),
    ('Validation', 'Anger'),
    ('Validation', 'Hate'),
    ('Validation', 'Compulsion'),
    ('Validation', 'Denial'),
    ('Validation', 'Passion'),
    ('Validation', 'Fear'),
    ('Validation', 'Panic'),
    ('Validation', 'Fondness'),
    ('Exhilaration', 'Creativity'),
    ('Exhilaration', 'Intimidation'),
    ('Exhilaration', 'Paranoia'),
    ('Exhilaration', 'Anger'),
    ('Exhilaration', 'Hate'),
    ('Exhilaration', 'Compulsion'),
    ('Exhilaration', 'Melancholy'),
    ('Exhilaration', 'Harmony'),
    ('Exhilaration', 'Suspicion'),
    ('Exhilaration', 'Gluttony'),
    ('Exhilaration', 'Nostalgia'),
    ('Exhilaration', 'Ambition'),
    ('Exhilaration', 'Bravado'),
    ('Exhilaration', 'Shock'),
    ('Bliss', 'Creativity'),
    ('Bliss', 'Intimidation'),
    ('Bliss', 'Paranoia'),
    ('Bliss', 'Euphoria'),
    ('Bliss', 'Hope'),
    ('Bliss', 'Hate'),
    ('Bliss', 'Compulsion'),
    ('Bliss', 'Awe'),
    ('Bliss', 'Harmony'),
    ('Bliss', 'Eagerness'),
    ('Bliss', 'Enthusiasm'),
    ('Bliss', 'Nostalgia'),
    ('Bliss', 'Determination'),
    ('Bliss', 'Joy'),
    ('Duplicity', 'Creativity'),
    ('Duplicity', 'Intimidation'),
    ('Duplicity', 'Rationalization'),
    ('Duplicity', 'Recklessness'),
    ('Duplicity', 'Paranoia'),
    ('Duplicity', 'Regret'),
    ('Duplicity', 'Anger'),
    ('Duplicity', 'Hate'),
    ('Duplicity', 'Compulsion'),
    ('Duplicity', 'Insecurity'),
    ('Duplicity', 'Fear'),
    ('Duplicity', 'Suspicion'),
    ('Duplicity', 'Zeal'),
    ('Duplicity', 'Pacifism'),
    ('Thrill', 'Creativity'),
    ('Thrill', 'Intimidation'),
    ('Thrill', 'Rationalization'),
    ('Thrill', 'Recklessness'),
    ('Thrill', 'Paranoia'),
    ('Thrill', 'Regret'),
    ('Thrill', 'Hope'),
    ('Thrill', 'Hate'),
    ('Thrill', 'Compulsion'),
    ('Thrill', 'Insecurity'),
    ('Thrill', 'Fear'),
    ('Thrill', 'Conviction'),
    ('Thrill', 'Panic'),
    ('Thrill', 'Zeal')
);

UPDATE schema_version SET version = '1.27.3' WHERE id = 1;
