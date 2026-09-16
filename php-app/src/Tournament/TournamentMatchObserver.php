<?php

declare(strict_types=1);

namespace MoodSwings\Tournament;

/**
 * GameService's own hook into the tournament system, set once at
 * bootstrap via GameService::setTournamentObserver() rather than
 * constructor-injected -- TournamentService itself depends on
 * GameService (to create each matchup's underlying game), so a
 * constructor dependency in the other direction would be circular.
 * Implemented by TournamentService; GameService knows nothing about
 * that class, only this interface.
 */
interface TournamentMatchObserver
{
    /**
     * Called once a game's own top-level match has genuinely concluded
     * -- i.e. not just "this one game finished" for a best-of-three/
     * draft match still awaiting game 2 or 3, but "there is now a final
     * winner for whatever the tournament seated these two players into."
     * A no-op if $gameId isn't linked to any tournament match at all.
     *
     * $gameMatchId/$draftMatchId are whichever of games.game_match_id/
     * draft_match_id (if either) the finishing game itself carries --
     * passed through rather than re-resolved, since GameService already
     * has them at every call site and they're the stable identifier
     * across a best-of-three/draft match's own up-to-3 `games` rows
     * (unlike $gameId, which is only the ONE that just finished).
     */
    public function onMatchConcluded(int $gameId, ?int $gameMatchId, ?int $draftMatchId, int $winnerUserId): void;

    /**
     * Booster Draft (issue #91 follow-up): called whenever
     * GameService::submitCustomDuelDeck() successfully stores a seat's
     * own deck AND that seat carries its own custom_deck_allowed_card_ids
     * restriction -- i.e. only ever for a Booster Draft tournament
     * match's own seat, never an ordinary custom_duel game (which has no
     * such restriction, so never calls this at all). Persists the
     * submission as this participant's new "current deck", pre-filled
     * the next time they submit one for a later match -- see
     * TournamentService::onCustomDuelDeckSubmitted()'s own docblock.
     * $gameMatchId is the submitting game's own games.game_match_id (if
     * any -- a best-of-three Booster Draft match's game 2/3 needs it to
     * resolve back to the right tournament_matches row, exactly like
     * onMatchConcluded()'s own $gameMatchId param).
     *
     * @param int[] $cardIds
     */
    public function onCustomDuelDeckSubmitted(int $gameId, ?int $gameMatchId, int $gamePlayerId, int $userId, array $cardIds): void;
}
