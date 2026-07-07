<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Exceptions;

use RuntimeException;

/**
 * Thrown when a card has a to-play/while-in-play/after-playing ability
 * (per the `cards` catalog's has_*_ability flags) but no MoodEffect has
 * been registered for its effect_key yet. Deliberately loud rather than
 * silently no-op-ing, so an unimplemented card's effect can never be
 * mistaken for "this card genuinely does nothing" -- only the five vanilla
 * commons and Creativity have no ability at all, and those never reach an
 * EffectRegistry lookup in the first place.
 */
final class EffectNotImplementedException extends RuntimeException
{
}
