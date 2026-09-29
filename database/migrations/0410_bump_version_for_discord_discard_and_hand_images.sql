-- Issue #233 follow-up (reported live: "can we show the discard pile as
-- an image, as well? ... I would also like to show the active user's
-- hand as a composite image, labeled 'your hand'"). Pure code change, no
-- schema change -- the discard pile is folded into the existing composite
-- board image as an extra labeled row (DiscordGameCommandService::
-- discardImageRow()), and the hand gets its own separate, per-seat-signed
-- embed/endpoint (renderHandImage()/handImageUrl(), since a hand is
-- private information that can never share the board image's own
-- public, signed-by-game-id-alone URL). Same "schema-version-only
-- migration" pattern as every other pure-code-change Discord follow-up
-- (0387-0393) -- see php-app/README.md.

UPDATE schema_version SET version = '1.56.16' WHERE id = 1;
