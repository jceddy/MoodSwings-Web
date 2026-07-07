<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * Stubbornness: "While in play, at the start of each of your turns, if
 * another player has more moods than you, you may play an additional
 * mood this turn." Unlike Hope/Grace, this never applies to the turn
 * Stubbornness itself is played (that isn't "the start of your turn"),
 * only every turn after -- entirely handled by
 * GameService::computeFreshGrants() checking effect_key 'stubbornness'
 * and the board's mood counts at the start of each subsequent turn. This
 * class is empty; it has no after-playing ability, and its "while in
 * play" ability isn't a value computation either.
 */
final class StubbornnessEffect extends AbstractMoodEffect
{
}
