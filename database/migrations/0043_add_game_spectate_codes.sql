-- Spectator mode (issue #128): lets anyone holding a game's short share
-- code spectate it (in addition to a friends-of-a-seated-player path that
-- needs no extra schema at all -- see FriendshipService::areFriends()).
--
-- Generated lazily, one game at a time, the first time a seated player
-- actually asks to share their game (see
-- GameService::getOrCreateSpectateCode()) -- not backfilled/pre-populated
-- for every existing game, since most games are never shared. A code is a
-- casually-shareable convenience, not a secret bearer credential like a
-- session token (see AuthService's token_hash columns), so it's stored
-- directly rather than hashed -- there's nothing more sensitive behind it
-- than "watch this game," and knowing it never grants the ability to act
-- as a player.
ALTER TABLE games
    ADD COLUMN spectate_code CHAR(8) DEFAULT NULL,
    ADD UNIQUE KEY uq_games_spectate_code (spectate_code);

UPDATE schema_version SET version = '0.16.0' WHERE id = 1;
