<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Exceptions;

use RuntimeException;

/**
 * Thrown when a play is attempted that the rules don't allow: it isn't the
 * acting player's turn, they have no plays remaining this turn, the card
 * isn't in their hand, or a "to play this card" cost can't be paid.
 */
final class IllegalPlayException extends RuntimeException
{
}
