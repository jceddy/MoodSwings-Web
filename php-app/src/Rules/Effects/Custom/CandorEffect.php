<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/**
 * Candor: "While in play -- Your opponents play with their hands revealed." A pure information rule with no
 * effect on the rules engine. A reveal is to all players, so GameService::buildGameState() shows every seated
 * player (`you.revealed_hands`) the hand of each opponent of a Candor's owner -- the owner sees them all, an
 * opponent sees the other opponents' -- but never the owner's own hand, which Candor doesn't reveal.
 */
final class CandorEffect extends AbstractMoodEffect
{
}
