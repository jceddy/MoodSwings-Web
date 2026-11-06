<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Implemented by moods whose ability fires "before scoring" (Neglect): once a round's last play or pass has
 * happened but before anyone's score is computed. GameService::runBeforeScoringTriggers() runs every such mood
 * in play, one after another (owners in the round's turn order, each owner's moods in the order they entered
 * play), at the start of scoring -- ahead of Enthusiasm/Passion's own scoring decisions, so what they change
 * is reflected in the scores. A trigger that needs a player's input pauses the round exactly like any other
 * pending decision (decision type 'before_scoring_trigger'); once it's answered the next trigger runs, then
 * scoring carries on.
 *
 * Mirrors TurnStartEffect, and like it implementers still extend AbstractMoodEffect for the no-op
 * afterPlaying()/computeValue() defaults.
 */
interface BeforeScoringEffect
{
    /**
     * The decisions to ask before this trigger can resolve, or null when the trigger is automatic and needs
     * nobody's input. An empty array means nothing happens this round and the trigger is skipped. Must not
     * mutate $state.
     *
     * @return ?PendingDecisionRequest[]
     */
    public function beforeScoringRequests(BoardState $state, int $cardId, int $playerId): ?array;

    /**
     * Applies the trigger once every request from beforeScoringRequests() has been answered -- $answers is
     * keyed by each request's own key, and is empty for an automatic trigger. Returns any FOLLOW-UP decisions
     * that only become askable now, or [] when fully resolved.
     *
     * @param array<string, PlayerChoices> $answers
     * @return PendingDecisionRequest[]
     */
    public function applyBeforeScoring(BoardState $state, int $cardId, int $playerId, array $answers): array;
}
