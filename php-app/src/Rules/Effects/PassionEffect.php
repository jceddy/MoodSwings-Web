<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * Passion: "While in play, while scoring, you may score one of your
 * opponents' moods as though it were yours (they also still score it)."
 * Same "no downside, so no interactive choice needed" reasoning as
 * Enthusiasm -- RoundScorer::score() adds the single highest-valued
 * opponent mood across the whole table to Passion's owner's total
 * (without removing it from the opponent's own total). This class is
 * likewise empty.
 */
final class PassionEffect extends AbstractMoodEffect
{
}
