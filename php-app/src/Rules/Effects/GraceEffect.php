<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;

/**
 * Grace: "While in play, during each of your turns (including the turn
 * you play this mood), you may play an additional mood from the discard
 * pile if it shares a color with one of your moods." Same shape as Hope,
 * just restricted to a discard-sourced, color-matching bonus play instead
 * of an unconditional one -- see HopeEffect, MoodPlayService, and
 * GameService::computeFreshGrants(). This class is likewise empty; its
 * whole ability lives outside the MoodEffect interface.
 */
final class GraceEffect extends AbstractMoodEffect
{
}
