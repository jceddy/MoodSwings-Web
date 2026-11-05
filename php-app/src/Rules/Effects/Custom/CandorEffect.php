<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Candor: "While in play -- Your opponents play with their hands revealed." A pure information rule with no
 * effect on the rules engine: GameService::buildGameState() shows an owner of a Candor in play every
 * opponent's hand (`you.revealed_hands`), and nobody else.
 */
final class CandorEffect extends AbstractMoodEffect
{
}
