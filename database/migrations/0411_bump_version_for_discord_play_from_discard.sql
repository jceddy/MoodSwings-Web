-- Issue #233 follow-up (reported live: "the discord client needs to
-- support playing cards from discard when allowed to by Grace or
-- similar effects"). Pure code change, no schema change --
-- DiscordGameCommandService::playableCardOptions() (renamed from
-- playableHandOptions()) now also offers a discard_pile entry once its
-- own is_playable is true, exactly the same signal GameService::getState()
-- already computed for a discard-sourced play grant (Grace/Harmony/
-- Grief/Angst, or Melancholy's own blanket allowance) -- see
-- php-app/README.md.

UPDATE schema_version SET version = '1.56.17' WHERE id = 1;
