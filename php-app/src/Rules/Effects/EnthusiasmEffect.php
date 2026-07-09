<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * Enthusiasm: "While in play, while scoring, you may score one of your
 * moods an extra time." Unlike Exhilaration/Bliss's own unconditional
 * bonuses, this one is genuinely optional ("you may"), and taking it
 * isn't always correct -- a card like Sneakiness swaps its owner's final
 * score with a chosen opponent's, so inflating your own pre-swap score
 * isn't always in your interest. GameService pauses at scoring time and
 * asks the owner explicitly (see RoundScorer::score()'s own docblock and
 * $scoringDecisions parameter) rather than assuming the maximum is always
 * wanted. This class exists only so the registry has an entry to
 * dispatch computeValue() to -- Enthusiasm has no printed value ability
 * of its own.
 */
final class EnthusiasmEffect extends AbstractMoodEffect
{
}
