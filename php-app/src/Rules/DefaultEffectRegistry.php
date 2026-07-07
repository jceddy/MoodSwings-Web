<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\Effects\AngerEffect;
use MoodSwings\Rules\Effects\BenevolenceEffect;
use MoodSwings\Rules\Effects\CharityEffect;
use MoodSwings\Rules\Effects\ConvictionEffect;
use MoodSwings\Rules\Effects\CourageEffect;
use MoodSwings\Rules\Effects\DignityEffect;
use MoodSwings\Rules\Effects\EnvyEffect;
use MoodSwings\Rules\Effects\FaithEffect;
use MoodSwings\Rules\Effects\FascinationEffect;
use MoodSwings\Rules\Effects\GuileEffect;
use MoodSwings\Rules\Effects\ImaginationEffect;
use MoodSwings\Rules\Effects\PairedColorThresholdEffect;
use MoodSwings\Rules\Effects\SadnessEffect;
use MoodSwings\Rules\Effects\SelfLoathingEffect;
use MoodSwings\Rules\Effects\VanityEffect;
use MoodSwings\Rules\Effects\WonderEffect;
use MoodSwings\Rules\Effects\ZealEffect;

/**
 * Wires up every MoodEffect implemented so far. This is a growing slice of
 * the full 133-card pool, chosen to exercise the range of patterns the
 * engine needs to support (unconditional/conditional extra-play grants,
 * one-time value overrides paid for by optional costs, a global color
 * override with a per-mood stored choice, a reusable parameterized effect
 * covering ten cards at once, multi-target choices validated against live
 * values, deck/hand manipulation across players, mandatory "to play"
 * costs, dynamic values keyed off an opponent's board or the discard
 * pile, and source-tied suppression) -- not full coverage. Cards not
 * registered here throw EffectNotImplementedException if their ability is
 * actually invoked; implementing the rest is incremental follow-up work.
 */
final class DefaultEffectRegistry
{
    public static function build(): EffectRegistry
    {
        $registry = new EffectRegistry();

        $registry->register('charity', new CharityEffect());
        $registry->register('dignity', new DignityEffect());
        $registry->register('imagination', new ImaginationEffect());
        $registry->register('courage', new CourageEffect());
        $registry->register('conviction', new ConvictionEffect());
        $registry->register('zeal', new ZealEffect());
        $registry->register('benevolence', new BenevolenceEffect());
        $registry->register('faith', new FaithEffect());
        $registry->register('guile', new GuileEffect());
        $registry->register('envy', new EnvyEffect());
        $registry->register('sadness', new SadnessEffect());
        $registry->register('vanity', new VanityEffect());
        $registry->register('fascination', new FascinationEffect());
        $registry->register('wonder', new WonderEffect());
        $registry->register('anger', new AngerEffect());
        $registry->register('self_loathing', new SelfLoathingEffect());

        $registry->register('ambivalence', new PairedColorThresholdEffect('red', 'green'));
        $registry->register('discipline', new PairedColorThresholdEffect('black', 'red'));
        $registry->register('loyalty', new PairedColorThresholdEffect('green', 'blue'));
        $registry->register('obsession', new PairedColorThresholdEffect('white', 'black'));
        $registry->register('disgust', new PairedColorThresholdEffect('green', 'white'));
        $registry->register('frustration', new PairedColorThresholdEffect('white', 'blue'));
        $registry->register('disregard', new PairedColorThresholdEffect('blue', 'black'));
        $registry->register('enjoyment', new PairedColorThresholdEffect('red', 'white'));
        $registry->register('pity', new PairedColorThresholdEffect('blue', 'red'));
        $registry->register('excitement', new PairedColorThresholdEffect('black', 'green'));

        return $registry;
    }
}
