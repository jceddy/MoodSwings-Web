<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\Effects\AngerEffect;
use MoodSwings\Rules\Effects\AnxietyEffect;
use MoodSwings\Rules\Effects\BenevolenceEffect;
use MoodSwings\Rules\Effects\BitternessEffect;
use MoodSwings\Rules\Effects\BravadoEffect;
use MoodSwings\Rules\Effects\CharityEffect;
use MoodSwings\Rules\Effects\ChivalryEffect;
use MoodSwings\Rules\Effects\ConvictionEffect;
use MoodSwings\Rules\Effects\CourageEffect;
use MoodSwings\Rules\Effects\DignityEffect;
use MoodSwings\Rules\Effects\EagernessEffect;
use MoodSwings\Rules\Effects\EnvyEffect;
use MoodSwings\Rules\Effects\FaithEffect;
use MoodSwings\Rules\Effects\FascinationEffect;
use MoodSwings\Rules\Effects\FriendlinessEffect;
use MoodSwings\Rules\Effects\FuryEffect;
use MoodSwings\Rules\Effects\GuileEffect;
use MoodSwings\Rules\Effects\GuiltEffect;
use MoodSwings\Rules\Effects\HateEffect;
use MoodSwings\Rules\Effects\ImaginationEffect;
use MoodSwings\Rules\Effects\KindnessEffect;
use MoodSwings\Rules\Effects\MeeknessEffect;
use MoodSwings\Rules\Effects\PacifismEffect;
use MoodSwings\Rules\Effects\PairedColorThresholdEffect;
use MoodSwings\Rules\Effects\RageEffect;
use MoodSwings\Rules\Effects\RebellionEffect;
use MoodSwings\Rules\Effects\RepentanceEffect;
use MoodSwings\Rules\Effects\SadnessEffect;
use MoodSwings\Rules\Effects\SelfLoathingEffect;
use MoodSwings\Rules\Effects\ShameEffect;
use MoodSwings\Rules\Effects\ShockEffect;
use MoodSwings\Rules\Effects\SpiteEffect;
use MoodSwings\Rules\Effects\TriumphEffect;
use MoodSwings\Rules\Effects\VanityEffect;
use MoodSwings\Rules\Effects\WonderEffect;
use MoodSwings\Rules\Effects\WrathEffect;
use MoodSwings\Rules\Effects\ZealEffect;

/**
 * Wires up every MoodEffect implemented so far. This is a growing slice of
 * the full 133-card pool, chosen to exercise the range of patterns the
 * engine needs to support (unconditional/conditional/restricted extra-play
 * grants, one-time value overrides paid for by optional costs, a global
 * color override with a per-mood stored choice, a reusable parameterized
 * effect covering ten cards at once, multi-target choices validated
 * against live values, deck/hand manipulation across players, mandatory
 * "to play" costs, dynamic values keyed off an opponent's board, the
 * discard pile, or who went first this round, source-tied and
 * end-of-round suppression, a modal single-vs-mass choice, a "most common
 * color(s)" board computation, and a mandatory effect touching every
 * player at once -- not full coverage. Cards not registered here throw
 * EffectNotImplementedException if their ability is actually invoked;
 * implementing the rest is incremental follow-up work.
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
        $registry->register('friendliness', new FriendlinessEffect());
        $registry->register('kindness', new KindnessEffect());
        $registry->register('eagerness', new EagernessEffect());
        $registry->register('meekness', new MeeknessEffect());
        $registry->register('pacifism', new PacifismEffect());
        $registry->register('repentance', new RepentanceEffect());
        $registry->register('hate', new HateEffect());
        $registry->register('wrath', new WrathEffect());
        $registry->register('rage', new RageEffect());
        $registry->register('anxiety', new AnxietyEffect());
        $registry->register('chivalry', new ChivalryEffect());
        $registry->register('triumph', new TriumphEffect());
        $registry->register('guilt', new GuiltEffect());
        $registry->register('shame', new ShameEffect());
        $registry->register('bitterness', new BitternessEffect());
        $registry->register('spite', new SpiteEffect());
        $registry->register('rebellion', new RebellionEffect());
        $registry->register('shock', new ShockEffect());
        $registry->register('fury', new FuryEffect());
        $registry->register('bravado', new BravadoEffect());

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
