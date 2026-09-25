-- Tournament spectator mode follow-up (reported live: "casters with
-- access to private information (both user's hands) can't play in the
-- tournament"): TournamentService now enforces that a hands-revealed
-- (reveal_hands=1) cast grant and active tournament participation
-- ('invited'/'joined') are mutually exclusive, in both directions
-- (grantCastAccess()/createTournament()'s own cast grants vs.
-- invite()/joinOpenTournament()). No schema change -- purely a
-- service-layer rule -- so this migration only bumps schema_version.
UPDATE schema_version SET version = '1.52.2' WHERE id = 1;
