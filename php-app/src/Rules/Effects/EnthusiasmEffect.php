<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * Enthusiasm: "While in play, while scoring, you may score one of your
 * moods an extra time." Since card values are never negative, taking
 * this is always at least as good as declining it, so it's resolved
 * unconditionally by RoundScorer::score() adding its owner's single
 * highest-valued mood a second time, rather than needing an interactive
 * scoring-time choice. This class exists only so the registry has an
 * entry to dispatch computeValue() to -- Enthusiasm has no printed value
 * ability of its own.
 */
final class EnthusiasmEffect extends AbstractMoodEffect
{
}
