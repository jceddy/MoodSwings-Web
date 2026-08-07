-- Joy ("after playing this mood, you may play an additional mood on your
-- next turn") and Generosity (the same, targeting a chosen opponent
-- instead of yourself) used to bank their extra play as a tag on the
-- physical card's own effect_state (game_cards.effect_state), read back
-- by GameService::computeFreshGrants() only while that specific card
-- remained in play. If the card left play (discarded, returned to hand,
-- stolen by another player's Regret/etc.) before the tagged beneficiary's
-- next turn ever started while it was still in play, the banked play was
-- silently lost; if the same physical card was later replayed by a
-- DIFFERENT player, that replay overwrote the tag in place, silently
-- reassigning the original beneficiary's banked play to whoever replayed
-- it. This column lets a banked play be tracked per game_player_id,
-- entirely independent of the card that created it -- see
-- BoardState::bankExtraPlay()/consumeBankedExtraPlaysFor().
ALTER TABLE game_players
    ADD COLUMN banked_extra_plays TEXT NULL AFTER resigned_at;
UPDATE schema_version SET version = '1.11.19' WHERE id = 1;
