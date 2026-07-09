<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * Passion: "While in play, while scoring, you may score one of your
 * opponents' moods as though it were yours (they also still score it)."
 * Genuinely optional, like Enthusiasm, and for the same reason not always
 * correct to take even at its best value -- see EnthusiasmEffect's own
 * docblock and RoundScorer::score()'s $scoringDecisions parameter.
 * GameService pauses at scoring time and asks the owner to pick a
 * specific opponent mood (or decline) rather than always taking the
 * highest-valued one automatically. This class is likewise empty.
 */
final class PassionEffect extends AbstractMoodEffect
{
}
