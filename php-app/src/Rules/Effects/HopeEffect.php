<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * Hope: "While in play, you may play an additional mood during each of
 * your turns, including the turn you play this mood." Has no
 * after-playing ability of its own -- its whole ability is "while in
 * play" -- so it's implemented entirely outside this class: the same-turn
 * bonus is granted directly by MoodPlayService the moment Hope enters
 * play, and every turn after that by
 * GameService::computeFreshGrants(), both keyed off the effect_key
 * 'hope' rather than any method here. This class exists only so the
 * registry has an entry to dispatch computeValue() to -- inherited
 * from AbstractMoodEffect unchanged, since Hope has no printed value
 * ability of its own.
 */
final class HopeEffect extends AbstractMoodEffect
{
}
