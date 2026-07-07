<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\Effects\AltruismEffect;
use MoodSwings\Rules\Effects\AmbitionEffect;
use MoodSwings\Rules\Effects\AngerEffect;
use MoodSwings\Rules\Effects\AngstEffect;
use MoodSwings\Rules\Effects\AnimosityEffect;
use MoodSwings\Rules\Effects\AnxietyEffect;
use MoodSwings\Rules\Effects\AvoidanceEffect;
use MoodSwings\Rules\Effects\BenevolenceEffect;
use MoodSwings\Rules\Effects\BitternessEffect;
use MoodSwings\Rules\Effects\BravadoEffect;
use MoodSwings\Rules\Effects\CelebrationEffect;
use MoodSwings\Rules\Effects\CharityEffect;
use MoodSwings\Rules\Effects\ChivalryEffect;
use MoodSwings\Rules\Effects\CondescensionEffect;
use MoodSwings\Rules\Effects\ConfusionEffect;
use MoodSwings\Rules\Effects\ContemptEffect;
use MoodSwings\Rules\Effects\ConvictionEffect;
use MoodSwings\Rules\Effects\CourageEffect;
use MoodSwings\Rules\Effects\CrueltyEffect;
use MoodSwings\Rules\Effects\CuriosityEffect;
use MoodSwings\Rules\Effects\CynicismEffect;
use MoodSwings\Rules\Effects\DenialEffect;
use MoodSwings\Rules\Effects\DeterminationEffect;
use MoodSwings\Rules\Effects\DignityEffect;
use MoodSwings\Rules\Effects\DisorientationEffect;
use MoodSwings\Rules\Effects\EagernessEffect;
use MoodSwings\Rules\Effects\EnvyEffect;
use MoodSwings\Rules\Effects\EuphoriaEffect;
use MoodSwings\Rules\Effects\FaithEffect;
use MoodSwings\Rules\Effects\FascinationEffect;
use MoodSwings\Rules\Effects\FearEffect;
use MoodSwings\Rules\Effects\FicklenessEffect;
use MoodSwings\Rules\Effects\FondnessEffect;
use MoodSwings\Rules\Effects\FriendlinessEffect;
use MoodSwings\Rules\Effects\FuryEffect;
use MoodSwings\Rules\Effects\GriefEffect;
use MoodSwings\Rules\Effects\GuileEffect;
use MoodSwings\Rules\Effects\GuiltEffect;
use MoodSwings\Rules\Effects\HandDiscardValueBoostEffect;
use MoodSwings\Rules\Effects\HappinessEffect;
use MoodSwings\Rules\Effects\HarmonyEffect;
use MoodSwings\Rules\Effects\HateEffect;
use MoodSwings\Rules\Effects\HesitationEffect;
use MoodSwings\Rules\Effects\HonorEffect;
use MoodSwings\Rules\Effects\HostilityEffect;
use MoodSwings\Rules\Effects\ImaginationEffect;
use MoodSwings\Rules\Effects\IndecisivenessEffect;
use MoodSwings\Rules\Effects\InfatuationEffect;
use MoodSwings\Rules\Effects\InstabilityEffect;
use MoodSwings\Rules\Effects\KindnessEffect;
use MoodSwings\Rules\Effects\LoveEffect;
use MoodSwings\Rules\Effects\MaliceEffect;
use MoodSwings\Rules\Effects\MeeknessEffect;
use MoodSwings\Rules\Effects\MiseryEffect;
use MoodSwings\Rules\Effects\NeurosisEffect;
use MoodSwings\Rules\Effects\NostalgiaEffect;
use MoodSwings\Rules\Effects\PacifismEffect;
use MoodSwings\Rules\Effects\PairedColorThresholdEffect;
use MoodSwings\Rules\Effects\PanicEffect;
use MoodSwings\Rules\Effects\ParanoiaEffect;
use MoodSwings\Rules\Effects\RageEffect;
use MoodSwings\Rules\Effects\RationalizationEffect;
use MoodSwings\Rules\Effects\RebellionEffect;
use MoodSwings\Rules\Effects\RegretEffect;
use MoodSwings\Rules\Effects\RejectionEffect;
use MoodSwings\Rules\Effects\RepentanceEffect;
use MoodSwings\Rules\Effects\SadnessEffect;
use MoodSwings\Rules\Effects\SelfLoathingEffect;
use MoodSwings\Rules\Effects\SerenityEffect;
use MoodSwings\Rules\Effects\ShameEffect;
use MoodSwings\Rules\Effects\ShockEffect;
use MoodSwings\Rules\Effects\SlothEffect;
use MoodSwings\Rules\Effects\SpiteEffect;
use MoodSwings\Rules\Effects\SuperiorityEffect;
use MoodSwings\Rules\Effects\SuspicionEffect;
use MoodSwings\Rules\Effects\ThrillEffect;
use MoodSwings\Rules\Effects\TranquilityEffect;
use MoodSwings\Rules\Effects\TriumphEffect;
use MoodSwings\Rules\Effects\VanityEffect;
use MoodSwings\Rules\Effects\WonderEffect;
use MoodSwings\Rules\Effects\WorryEffect;
use MoodSwings\Rules\Effects\WrathEffect;
use MoodSwings\Rules\Effects\ZealEffect;

/**
 * Wires up every MoodEffect implemented so far. This is a growing slice of
 * the full 133-card pool, chosen to exercise the range of patterns the
 * engine needs to support (unconditional/conditional/restricted extra-play
 * grants, one-time value overrides paid for by optional costs (including a
 * reusable parameterized class for the "discard a qualifying hand card ->
 * value becomes X" family), a global color override with a per-mood stored
 * choice, a reusable parameterized effect covering ten cards at once,
 * multi-target choices validated against live values, deck/hand
 * manipulation across players (including stealing a mood directly into the
 * acting player's own hand, distinct from returning it to its owner's),
 * mandatory "to play" costs, dynamic values keyed off an opponent's board,
 * the discard pile, or who went first this round, source-tied and
 * end-of-round suppression, modal single-vs-mass choices (mandatory and
 * optional), "most common color(s)"/"any color reaches N" board
 * computations over moods in play or the discard pile, a mandatory effect
 * touching every player at once, a range of pure while-in-play value
 * formulas (self-vs-every-opponent comparisons, a universal or
 * any-opponent threshold, a distinct color count, parity checks, and a
 * five-color-presence check), a genuinely-random target (rather than
 * another player's informed choice), a pairwise qualifying condition
 * across two chosen targets, two-stage optional effects, two independent
 * options in one effect with no cost/reward link between them, a
 * single-pass turn-order distribution from the discard pile with the
 * remainder shuffled onto the bottom of the deck, extra-play grants
 * sourced from the discard pile instead of hand (Harmony/Grief/Angst --
 * see BoardState::isInDiscardPile()/moveDiscardToInPlay()), a persistent
 * "who goes first next round" override consulted by GameService instead
 * of the round winner (Honor -- see BoardState::firstPlayerOverride()),
 * and a direction-based simultaneous exchange with every player at the
 * table (Avoidance/Confusion/Rationalization) -- not full coverage. Cards
 * not registered here throw EffectNotImplementedException if their
 * ability is actually invoked; implementing the rest is incremental
 * follow-up work.
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
        $registry->register('superiority', new SuperiorityEffect());
        $registry->register('fondness', new FondnessEffect());
        $registry->register('animosity', new AnimosityEffect());
        $registry->register('celebration', new CelebrationEffect());
        $registry->register('determination', new DeterminationEffect());
        $registry->register('serenity', new SerenityEffect());
        $registry->register('tranquility', new TranquilityEffect());
        $registry->register('euphoria', new EuphoriaEffect());
        $registry->register('sloth', new SlothEffect());
        $registry->register('love', new LoveEffect());
        $registry->register('neurosis', new NeurosisEffect());
        $registry->register('regret', new RegretEffect());
        $registry->register('cruelty', new CrueltyEffect());
        $registry->register('indecisiveness', new IndecisivenessEffect());
        $registry->register('rejection', new RejectionEffect());
        $registry->register('denial', new DenialEffect());
        $registry->register('disorientation', new DisorientationEffect());
        $registry->register('panic', new PanicEffect());
        $registry->register('worry', new WorryEffect());
        $registry->register('contempt', new ContemptEffect());
        $registry->register('happiness', new HappinessEffect());
        $registry->register('misery', new MiseryEffect());
        $registry->register('ambition', new AmbitionEffect());
        $registry->register('thrill', new ThrillEffect());
        $registry->register('fear', new FearEffect());
        $registry->register('paranoia', new ParanoiaEffect());
        $registry->register('suspicion', new SuspicionEffect());
        $registry->register('altruism', new AltruismEffect());
        $registry->register('curiosity', new CuriosityEffect());
        $registry->register('condescension', new CondescensionEffect());
        $registry->register('cynicism', new CynicismEffect());
        $registry->register('infatuation', new InfatuationEffect());
        $registry->register('hostility', new HostilityEffect());
        $registry->register('malice', new MaliceEffect());
        $registry->register('fickleness', new FicklenessEffect());
        $registry->register('hesitation', new HesitationEffect());
        $registry->register('nostalgia', new NostalgiaEffect());
        $registry->register('harmony', new HarmonyEffect());
        $registry->register('grief', new GriefEffect());
        $registry->register('angst', new AngstEffect());
        $registry->register('honor', new HonorEffect());
        $registry->register('avoidance', new AvoidanceEffect());
        $registry->register('confusion', new ConfusionEffect());
        $registry->register('rationalization', new RationalizationEffect());
        $registry->register('instability', new InstabilityEffect());

        $registry->register('embarrassment', new HandDiscardValueBoostEffect([4, 5, 6], 5));
        $registry->register('cheer', new HandDiscardValueBoostEffect([0, 2, 4, 6], 5));
        $registry->register('delight', new HandDiscardValueBoostEffect([1, 3, 5], 5));

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
