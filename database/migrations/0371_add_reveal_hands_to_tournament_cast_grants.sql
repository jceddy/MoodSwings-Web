-- Tournament spectator mode follow-up (issue #238): a "no hands" cast
-- grant option -- a caster the creator trusts to watch a tournament's
-- still-in_progress matches without seeing anyone's actual cards (public
-- information only, the same shape a plain issue #128 spectator gets for
-- an in_progress game), distinct from a full hands-revealed grant.
-- Defaults to 1 (hands revealed) so every grant issue #238 already
-- created keeps its original, only-ever-offered behavior unchanged.
ALTER TABLE tournament_cast_grants
    ADD COLUMN reveal_hands TINYINT(1) NOT NULL DEFAULT 1 AFTER granted_by_user_id;

UPDATE schema_version SET version = '1.52.1' WHERE id = 1;
