<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\Effects\AltruismEffect;
use MoodSwings\Rules\Effects\AmbitionEffect;
use MoodSwings\Rules\Effects\AngerEffect;
use MoodSwings\Rules\Effects\AngstEffect;
use MoodSwings\Rules\Effects\AnimosityEffect;
use MoodSwings\Rules\Effects\AnxietyEffect;
use MoodSwings\Rules\Effects\ArroganceEffect;
use MoodSwings\Rules\Effects\AvoidanceEffect;
use MoodSwings\Rules\Effects\AweEffect;
use MoodSwings\Rules\Effects\BashfulnessEffect;
use MoodSwings\Rules\Effects\BenevolenceEffect;
use MoodSwings\Rules\Effects\BetrayalEffect;
use MoodSwings\Rules\Effects\BitternessEffect;
use MoodSwings\Rules\Effects\BlissEffect;
use MoodSwings\Rules\Effects\BravadoEffect;
use MoodSwings\Rules\Effects\CelebrationEffect;
use MoodSwings\Rules\Effects\ChaosEffect;
use MoodSwings\Rules\Effects\CharityEffect;
use MoodSwings\Rules\Effects\ChivalryEffect;
use MoodSwings\Rules\Effects\CompulsionEffect;
use MoodSwings\Rules\Effects\CondescensionEffect;
use MoodSwings\Rules\Effects\ConfusionEffect;
use MoodSwings\Rules\Effects\ContemptEffect;
use MoodSwings\Rules\Effects\ConvictionEffect;
use MoodSwings\Rules\Effects\CorruptionEffect;
use MoodSwings\Rules\Effects\CourageEffect;
use MoodSwings\Rules\Effects\CrueltyEffect;
use MoodSwings\Rules\Effects\CuriosityEffect;
use MoodSwings\Rules\Effects\CynicismEffect;
use MoodSwings\Rules\Effects\DenialEffect;
use MoodSwings\Rules\Effects\DeterminationEffect;
use MoodSwings\Rules\Effects\DignityEffect;
use MoodSwings\Rules\Effects\DisillusionmentEffect;
use MoodSwings\Rules\Effects\DisorientationEffect;
use MoodSwings\Rules\Effects\DoubtEffect;
use MoodSwings\Rules\Effects\DuplicityEffect;
use MoodSwings\Rules\Effects\EagernessEffect;
use MoodSwings\Rules\Effects\EncouragementEffect;
use MoodSwings\Rules\Effects\EnthusiasmEffect;
use MoodSwings\Rules\Effects\EnvyEffect;
use MoodSwings\Rules\Effects\EuphoriaEffect;
use MoodSwings\Rules\Effects\ExhilarationEffect;
use MoodSwings\Rules\Effects\FaithEffect;
use MoodSwings\Rules\Effects\FascinationEffect;
use MoodSwings\Rules\Effects\FearEffect;
use MoodSwings\Rules\Effects\FicklenessEffect;
use MoodSwings\Rules\Effects\FondnessEffect;
use MoodSwings\Rules\Effects\FriendlinessEffect;
use MoodSwings\Rules\Effects\FuryEffect;
use MoodSwings\Rules\Effects\GenerosityEffect;
use MoodSwings\Rules\Effects\GluttonyEffect;
use MoodSwings\Rules\Effects\GraceEffect;
use MoodSwings\Rules\Effects\GriefEffect;
use MoodSwings\Rules\Effects\GuileEffect;
use MoodSwings\Rules\Effects\GuiltEffect;
use MoodSwings\Rules\Effects\HandDiscardValueBoostEffect;
use MoodSwings\Rules\Effects\HappinessEffect;
use MoodSwings\Rules\Effects\HarmonyEffect;
use MoodSwings\Rules\Effects\HateEffect;
use MoodSwings\Rules\Effects\HesitationEffect;
use MoodSwings\Rules\Effects\HonorEffect;
use MoodSwings\Rules\Effects\HopeEffect;
use MoodSwings\Rules\Effects\HostilityEffect;
use MoodSwings\Rules\Effects\IdealismEffect;
use MoodSwings\Rules\Effects\ImaginationEffect;
use MoodSwings\Rules\Effects\IndecisivenessEffect;
use MoodSwings\Rules\Effects\InfatuationEffect;
use MoodSwings\Rules\Effects\InsecurityEffect;
use MoodSwings\Rules\Effects\InstabilityEffect;
use MoodSwings\Rules\Effects\IntimidationEffect;
use MoodSwings\Rules\Effects\JoyEffect;
use MoodSwings\Rules\Effects\KindnessEffect;
use MoodSwings\Rules\Effects\LoveEffect;
use MoodSwings\Rules\Effects\MaliceEffect;
use MoodSwings\Rules\Effects\MeeknessEffect;
use MoodSwings\Rules\Effects\MelancholyEffect;
use MoodSwings\Rules\Effects\MiseryEffect;
use MoodSwings\Rules\Effects\NeurosisEffect;
use MoodSwings\Rules\Effects\NostalgiaEffect;
use MoodSwings\Rules\Effects\PacifismEffect;
use MoodSwings\Rules\Effects\PairedColorThresholdEffect;
use MoodSwings\Rules\Effects\PanicEffect;
use MoodSwings\Rules\Effects\ParanoiaEffect;
use MoodSwings\Rules\Effects\PassionEffect;
use MoodSwings\Rules\Effects\PlayedThisRoundValueEffect;
use MoodSwings\Rules\Effects\PrideEffect;
use MoodSwings\Rules\Effects\RageEffect;
use MoodSwings\Rules\Effects\RationalizationEffect;
use MoodSwings\Rules\Effects\RebellionEffect;
use MoodSwings\Rules\Effects\RecklessnessEffect;
use MoodSwings\Rules\Effects\RegretEffect;
use MoodSwings\Rules\Effects\RejectionEffect;
use MoodSwings\Rules\Effects\RepentanceEffect;
use MoodSwings\Rules\Effects\SadnessEffect;
use MoodSwings\Rules\Effects\ScornEffect;
use MoodSwings\Rules\Effects\SelfLoathingEffect;
use MoodSwings\Rules\Effects\SerenityEffect;
use MoodSwings\Rules\Effects\ShameEffect;
use MoodSwings\Rules\Effects\ShockEffect;
use MoodSwings\Rules\Effects\SlothEffect;
use MoodSwings\Rules\Effects\SneakinessEffect;
use MoodSwings\Rules\Effects\SpiteEffect;
use MoodSwings\Rules\Effects\StubbornnessEffect;
use MoodSwings\Rules\Effects\SuperiorityEffect;
use MoodSwings\Rules\Effects\SuspicionEffect;
use MoodSwings\Rules\Effects\ThrillEffect;
use MoodSwings\Rules\Effects\TranquilityEffect;
use MoodSwings\Rules\Effects\TriumphEffect;
use MoodSwings\Rules\Effects\ValidationEffect;
use MoodSwings\Rules\Effects\VanityEffect;
use MoodSwings\Rules\Effects\VulnerabilityEffect;
use MoodSwings\Rules\Effects\WonderEffect;
use MoodSwings\Rules\Effects\WorryEffect;
use MoodSwings\Rules\Effects\WrathEffect;
use MoodSwings\Rules\Effects\ZealEffect;

/**
 * Wires up every MoodEffect for the full 133-card pool -- every card with
 * a printed ability now has one, exercising the range of patterns the
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
 * of the round winner (Honor -- see BoardState::firstPlayerOverride()), a
 * direction-based simultaneous exchange with every player at the table --
 * each player's own informed choice of what to give up, queued one per
 * player the same way RequiresOpponentDecision already handles a single
 * chosen target (Avoidance for moods in play, Confusion for hand cards),
 * a mandatory per-player discard of the player's own choosing rather than
 * an exchange, narrowed to only the mood(s) tied for that player's own
 * highest value (Fury), or (Rationalization's "rotate" mode) no choice at
 * all since a whole hand transfers, not a specific card -- and a family
 * of round-scoring
 * hooks resolved by GameService after a round's scores are computed --
 * a one-shot "after scoring, do X to this mood" tag (Bashfulness's
 * conditional-on-winning bottom-of-deck-and-draw; Recklessness's
 * unconditional one; Gluttony/Insecurity's version applied to whichever
 * specific card ends up consuming their granted extra play, via the
 * grant's own 'onUseEffectState' key rather than the mood that granted
 * it), a "give this mood away, it returns to you after scoring if still
 * in play" tag (Betrayal; Recklessness's taken mood), a score swap
 * between two players applied before the round's winner is determined
 * (Sneakiness), a "skip scoring entirely this round" marker paired with a
 * one-time (as opposed to Honor's perpetual) first-player override for
 * next round only (Awe), and an unconditional "the round's winner gets
 * an extra win" tag (Corruption -- GameService::consumeExtraWinMarker()),
 * a round-scoped "this mood's value changes if you played it this round"
 * pair sharing one stateless class (Patience/Glee -- the 'playedInRound'
 * tag every mood is stamped with on entering play, and
 * BoardState::currentRoundNumber()), a variable-count extra-play grant
 * sized to close a mood-count gap with a chosen opponent (Pride), an
 * "as though it were in your hand" widening of which zone a player's
 * normal plays can draw from, special-cased by effect_key the same way
 * BoardState::colorOf() special-cases Imagination (Melancholy), and a
 * next-round-only color ban fed by the same 'playedInRound' tag (Doubt --
 * see BoardState::bannedColorsThisRound()), a perpetual "every turn while
 * in play" extra-play grant -- unconditional (Hope), restricted to a
 * discard-sourced color match (Grace), or conditional on another
 * player's mood count checked fresh each turn (Stubbornness) -- computed
 * by GameService::computeFreshGrants() for every turn after the one the
 * card is played (with the same-turn case granted directly by
 * MoodPlayService, since Hope/Grace have no after-playing ability to
 * hook), a one-shot "banked" extra play for a specific player's next
 * turn however many turns from now that is (Generosity/Joy -- the same
 * computeFreshGrants() consulting a 'banksExtraPlayForPlayerId' tag), and
 * an opponent's own choice among their qualifying moods -- resolved the
 * same random way as Instability's -- tied to a "give it back if you
 * still have it" cascade that fires when the taking card itself leaves
 * play, tracking who currently holds the taken mood so a later give-away
 * doesn't wrongly trigger the return (Arrogance -- BoardState's
 * cascadeMoodLeavingPlay()), a fourth ability timing for the handful of
 * cards whose "while in play" ability is actually "each time you play
 * another mood, ..." -- a mandatory suppression paired with an optional
 * color-matched reaction (Scorn) and an unconditional grant paired with
 * a conditional reaction to a low-valued play (Validation) -- dispatched
 * via MoodEffect::reactToAnotherPlay() using the same PlayerChoices
 * already submitted for the triggering play, since the reaction is the
 * same player's own decision made in the same request, a mandatory
 * hidden hand-card choice by another (non-acting) player resolved via a
 * genuine random pick, same rationale as Instability's public-info
 * one (Compulsion; Intimidation's optional version, whose resulting
 * grant is restricted to that one specific card via the
 * 'specific_card_ids' restriction type), the same random-choice
 * treatment applied per player at the whole table at once, discarding
 * every other mood matching any of the resulting colors regardless of
 * owner (Disillusionment), a genuine reshuffle-and-redeal of every mood
 * in play (including the card causing it), reassigning ownership only
 * and never re-triggering after-playing effects (Chaos), a repeat of
 * another card's own after-playing effect with a *fresh*, nested
 * sub-choices bag rather than reusing the triggering play's choices
 * verbatim -- needed since e.g. a specific card already discarded once
 * can't be discarded again -- handled directly by MoodPlayService since
 * no MoodEffect implementation has access to the registry it needs to
 * re-invoke another card's effect (Duplicity -- PlayerChoices::sub()),
 * and a small cluster of scoring-time multiplier bonuses resolved by
 * RoundScorer::score() -- two printed with no "may" at all, so applied
 * unconditionally: doubling the owner's whole total (Exhilaration) and
 * tripling the owner's moods sharing a color with whatever card paid its
 * cost (Bliss, via a pre-play-staged effectState color captured before
 * the card exists as a MoodInPlay -- see BoardState::
 * stagePrePlayEffectState()); two printed as "you may" and resolved from
 * an explicit scoring-time decision instead (GameService's own pause,
 * mirroring the mid-play RequiresOpponentDecision pattern below, since
 * taking the highest-valued mood isn't always correct once a card like
 * Sneakiness can swap the resulting score away) -- the single
 * highest-valued mood among the owner's own (Enthusiasm) or a specific
 * chosen opponent mood (Passion), a "dice" value (a card's alt_value, used as
 * an alternative to its base_value rather than a conditional override)
 * that overrides a mood's value entirely for as long as it's tagged --
 * on any one chosen mood, not just the acting player's own
 * (Encouragement), or blanketing every mood its owner controls
 * (Idealism) -- see BoardState::valueOf()'s dice-value handling, and a
 * single round-wide "was any card discarded this round" flag (rather
 * than anything tied to a specific mood's effectState, since it has to
 * reflect a discard by *any* player) persisted alongside
 * pending_play_grants (Vulnerability -- BoardState::discardedThisRound(),
 * game_rounds.discarded_this_round).
 *
 * This is every card in the 133-card pool that has a printed ability --
 * the only ones left unregistered have no ability at all (a flat value
 * card, like Complacency), so there's nothing for EffectRegistry to
 * dispatch. registry->for() throws EffectNotImplementedException if an
 * unregistered effect_key is ever actually invoked, which would only
 * happen for a genuinely new/mistyped effect_key at this point.
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
        $registry->register('bashfulness', new BashfulnessEffect());
        $registry->register('betrayal', new BetrayalEffect());
        $registry->register('sneakiness', new SneakinessEffect());
        $registry->register('awe', new AweEffect());
        $registry->register('recklessness', new RecklessnessEffect());
        $registry->register('gluttony', new GluttonyEffect());
        $registry->register('insecurity', new InsecurityEffect());
        $registry->register('pride', new PrideEffect());
        $registry->register('corruption', new CorruptionEffect());
        $registry->register('melancholy', new MelancholyEffect());
        $registry->register('doubt', new DoubtEffect());
        $registry->register('hope', new HopeEffect());
        $registry->register('grace', new GraceEffect());
        $registry->register('stubbornness', new StubbornnessEffect());
        $registry->register('generosity', new GenerosityEffect());
        $registry->register('joy', new JoyEffect());
        $registry->register('arrogance', new ArroganceEffect());
        $registry->register('scorn', new ScornEffect());
        $registry->register('validation', new ValidationEffect());
        $registry->register('compulsion', new CompulsionEffect());
        $registry->register('intimidation', new IntimidationEffect());
        $registry->register('disillusionment', new DisillusionmentEffect());
        $registry->register('duplicity', new DuplicityEffect());
        $registry->register('chaos', new ChaosEffect());
        $registry->register('exhilaration', new ExhilarationEffect());
        $registry->register('bliss', new BlissEffect());
        $registry->register('enthusiasm', new EnthusiasmEffect());
        $registry->register('passion', new PassionEffect());
        $registry->register('encouragement', new EncouragementEffect());
        $registry->register('idealism', new IdealismEffect());
        $registry->register('vulnerability', new VulnerabilityEffect());

        $registry->register('embarrassment', new HandDiscardValueBoostEffect([4, 5, 6], 5));
        $registry->register('cheer', new HandDiscardValueBoostEffect([0, 2, 4, 6], 5));
        $registry->register('delight', new HandDiscardValueBoostEffect([1, 3, 5], 5));

        $registry->register('patience', new PlayedThisRoundValueEffect());
        $registry->register('glee', new PlayedThisRoundValueEffect());

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
