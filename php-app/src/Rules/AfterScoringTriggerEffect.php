<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Implemented by moods with a PERPETUAL "while in play -- after scoring, ..." ability (Perseverance).
 * Unlike the one-shot 'afterScoring' self-tag Recklessness/Frenzy/Bashfulness carry (which names an
 * action to take once), this fires after every round that scores while the mood is in play.
 * GameService::applyAfterScoringHooks() calls afterScoring() for each such mood once the round's
 * winner -- and so every player's round-win count (BoardState::roundWinsOf()) -- is settled.
 */
interface AfterScoringTriggerEffect
{
    public function afterScoring(BoardState $state, int $cardId, int $ownerId): void;
}
