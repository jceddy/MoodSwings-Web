<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * Melancholy: "While in play, you may play moods from the discard pile as
 * though they were in your hand." Unlike every other "while in play"
 * card, this isn't a value computation -- it widens which zone *any* of
 * its owner's normal plays can draw from, so it's implemented entirely in
 * BoardState::grantAllows() (checked by effect_key, the same way
 * BoardState::colorOf() special-cases Imagination) rather than through
 * this class. This class exists only so the registry has an entry to
 * dispatch computeValue() to -- inherited from AbstractMoodEffect
 * unchanged, since Melancholy has no printed value ability of its own.
 */
final class MelancholyEffect extends AbstractMoodEffect
{
}
