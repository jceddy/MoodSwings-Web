<?php

declare(strict_types=1);

namespace MoodSwings\Game\Exceptions;

use RuntimeException;

/**
 * Thrown for game-level actions the current state doesn't allow: starting
 * a game that's already started, too few players, acting on a game that
 * isn't in progress, or passing out of turn. Illegal *mood plays* within an
 * otherwise-valid turn are MoodSwings\Rules\Exceptions\IllegalPlayException
 * instead -- this is for the surrounding game/turn structure.
 */
final class GameStateException extends RuntimeException
{
}
