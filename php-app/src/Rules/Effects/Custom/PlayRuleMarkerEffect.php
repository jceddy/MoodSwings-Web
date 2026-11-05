<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * A "while in play" mood whose whole ability is a rule the turn engine reads straight off the board -- it has no
 * effect code of its own, just its printed value:
 *  - Obstinacy ("You can only play one mood per turn"): BoardState::grantIsActive() stops its owner's grants once a mood
 *    has been played this turn.
 *  - Restraint ("Moods can't let any player play additional moods"): BoardState::extraPlaysBlocked().
 *  - Defensiveness ("The after-playing effects of additional moods your opponents play don't happen"):
 *    BoardState::afterPlayingSuppressedFor().
 *  - Reassurance ("Your opponents can't choose your moods with a value of 2 or less"): BoardState::isProtectedFromChoiceBy().
 *  - Elusiveness ("If an opponent's effect would put one of your moods into the discard pile, put it into your hand
 *    instead"): BoardState::moveInPlayToDiscard()'s replacement.
 * Registered under each key so the card resolves; the engine finds the rule by the effective effect key (so a Creativity
 * copying one carries it too).
 */
final class PlayRuleMarkerEffect extends AbstractMoodEffect
{
}
