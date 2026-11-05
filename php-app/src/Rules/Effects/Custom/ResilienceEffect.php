<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * Resilience: "While in play -- During each of your turns (including the turn you play this mood), you
 * may play an additional mood from the discard pile if it has a 0, 1, 2, or 3 in its top right corner."
 * Like Hope/Grace it has no after-playing ability, so the grants live outside this class: MoodPlayService
 * hands over the same-turn play when Resilience enters play and GameService::computeFreshGrants() every
 * turn after, both keyed off the 'resilience' effect key (see RESTRICTION).
 */
final class ResilienceEffect extends AbstractMoodEffect
{
    /** The grant's restriction, shared by both places that hand it out. */
    public const RESTRICTION = ['type' => 'base_value_in', 'values' => [0, 1, 2, 3], 'source' => 'discard', 'requiresSourceInPlay' => true];
}
