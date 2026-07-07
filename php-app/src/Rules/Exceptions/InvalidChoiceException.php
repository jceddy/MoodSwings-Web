<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Exceptions;

use RuntimeException;

/**
 * Thrown when a card effect is given a choice it can't act on: a required
 * choice is missing, a targeted card doesn't exist or isn't where it needs
 * to be, or a chosen value/color/target violates the card's own
 * constraints (e.g. Courage's target must currently have a value of 5 or
 * more).
 */
final class InvalidChoiceException extends RuntimeException
{
}
