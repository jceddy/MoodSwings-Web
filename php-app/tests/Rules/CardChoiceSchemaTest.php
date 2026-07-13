<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\CardChoiceSchema;
use PHPUnit\Framework\TestCase;

final class CardChoiceSchemaTest extends TestCase
{
    public function testCardsWithNoAbilityNeedNoFields(): void
    {
        self::assertSame([], CardChoiceSchema::forEffectKey(null));
        self::assertSame([], CardChoiceSchema::forEffectKey('nonexistent'));
    }

    public function testPureValueFormulaCardsNeedNoFields(): void
    {
        self::assertSame([], CardChoiceSchema::forEffectKey('chivalry'));
        self::assertSame([], CardChoiceSchema::forEffectKey('discipline'));
    }

    public function testUnconditionalGrantCardsNeedNoFields(): void
    {
        self::assertSame([], CardChoiceSchema::forEffectKey('charity'));
        self::assertSame([], CardChoiceSchema::forEffectKey('chaos'));
        self::assertSame([], CardChoiceSchema::forEffectKey('grace'));
    }

    public function testRestrictedGrantCardsNeedNoFieldsSinceTheRestrictionIsCheckedLater(): void
    {
        self::assertSame([], CardChoiceSchema::forEffectKey('benevolence'));
        self::assertSame([], CardChoiceSchema::forEffectKey('friendliness'));
        self::assertSame([], CardChoiceSchema::forEffectKey('kindness'));
    }

    public function testPlayerFieldForCompulsionRequiresAnotherPlayer(): void
    {
        $fields = CardChoiceSchema::forEffectKey('compulsion');

        self::assertCount(1, $fields);
        self::assertSame('target_player_id', $fields[0]['key']);
        self::assertSame('player', $fields[0]['type']);
        self::assertSame('other', $fields[0]['scope']);
        self::assertTrue($fields[0]['required']);
    }

    public function testMoodFieldForConvictionTargetsAnyMood(): void
    {
        $fields = CardChoiceSchema::forEffectKey('conviction');

        self::assertCount(1, $fields);
        self::assertSame('target_mood_id', $fields[0]['key']);
        self::assertSame('mood', $fields[0]['type']);
        self::assertSame('any', $fields[0]['scope']);
    }

    public function testHandCardFieldForDignityIsOptional(): void
    {
        $fields = CardChoiceSchema::forEffectKey('dignity');

        self::assertCount(1, $fields);
        self::assertSame('discard_card_id', $fields[0]['key']);
        self::assertSame('hand_card', $fields[0]['type']);
        self::assertFalse($fields[0]['required']);
    }

    public function testDiscardPileFieldForNostalgia(): void
    {
        $fields = CardChoiceSchema::forEffectKey('nostalgia');

        self::assertCount(1, $fields);
        self::assertSame('discard_card_id', $fields[0]['key']);
        self::assertSame('discard_card', $fields[0]['type']);
    }

    public function testSameKeyNameMeansDifferentZonesForDifferentCards(): void
    {
        // 'discard_card_id' means a HAND card for Dignity but a
        // DISCARD-PILE card for Nostalgia -- the schema has to disambiguate
        // this per effect_key, not by key name alone.
        $dignityField = CardChoiceSchema::forEffectKey('dignity')[0];
        $nostalgiaField = CardChoiceSchema::forEffectKey('nostalgia')[0];

        self::assertSame('discard_card_id', $dignityField['key']);
        self::assertSame('discard_card_id', $nostalgiaField['key']);
        self::assertNotSame($dignityField['type'], $nostalgiaField['type']);
    }

    public function testModeFieldForWonderListsAllFiveColors(): void
    {
        $fields = CardChoiceSchema::forEffectKey('wonder');

        self::assertCount(1, $fields);
        self::assertSame('mode', $fields[0]['type']);
        self::assertSame(['white', 'blue', 'black', 'red', 'green'], $fields[0]['options']);
    }

    public function testValueFieldForRebellionHasANarrowerRangeThanRepentance(): void
    {
        $rebellion = CardChoiceSchema::forEffectKey('rebellion')[0];
        $repentance = CardChoiceSchema::forEffectKey('repentance')[0];

        self::assertSame('value', $rebellion['type']);
        self::assertSame(0, $rebellion['min']);
        self::assertSame(3, $rebellion['max']);
        self::assertSame(0, $repentance['min']);
        self::assertSame(12, $repentance['max']);
    }

    public function testBoolFieldForRage(): void
    {
        $fields = CardChoiceSchema::forEffectKey('rage');

        self::assertCount(1, $fields);
        self::assertSame('discard_qualifying_moods', $fields[0]['key']);
        self::assertSame('bool', $fields[0]['type']);
    }

    public function testMultiFieldForDoubtReadsHandCards(): void
    {
        $fields = CardChoiceSchema::forEffectKey('doubt');

        self::assertCount(1, $fields);
        self::assertSame('reveal_card_ids', $fields[0]['key']);
        self::assertSame('hand_card', $fields[0]['type']);
        self::assertTrue($fields[0]['multi']);
    }

    public function testBetrayalOnlyExposesTheRecipientNotTheMoodChoice(): void
    {
        // Which mood to give away isn't an ordinary up-front field --
        // Betrayal is a legal answer to its own choice, but isn't in play
        // yet at the moment this panel is filled out, so BetrayalEffect
        // defers it to a pending decision (see that class's own docblock)
        // instead of a static choice_fields entry here.
        $fields = CardChoiceSchema::forEffectKey('betrayal');

        self::assertCount(1, $fields);
        self::assertSame('recipient_player_id', $fields[0]['key']);
        self::assertSame('player', $fields[0]['type']);
        self::assertSame('other', $fields[0]['scope']);
        self::assertTrue($fields[0]['required']);
    }

    public function testScornAndValidationOnlyExposeTheirOwnPlayNotTheirReaction(): void
    {
        // Scorn's afterPlaying choice (target_mood_id) is exposed; its
        // reactToAnotherPlay choice (scorn_suppress_target, fired when
        // playing a *different* card) is an intentional gap for this pass.
        $scorn = CardChoiceSchema::forEffectKey('scorn');
        self::assertCount(1, $scorn);
        self::assertSame('target_mood_id', $scorn[0]['key']);

        // Validation's afterPlaying grant needs no choice at all; its
        // reaction (validation_extra_play) is the same kind of gap.
        self::assertSame([], CardChoiceSchema::forEffectKey('validation'));
    }

    public function testColorFilterForGuiltMatchesGuiltEffectsOwnCheck(): void
    {
        $fields = CardChoiceSchema::forEffectKey('guilt');

        self::assertSame(['black', 'red'], $fields[1]['filter']['colors']);
    }

    public function testColorFilterForFaithMatchesFaithEffectsOwnCheck(): void
    {
        $fields = CardChoiceSchema::forEffectKey('faith');

        self::assertSame(['green', 'blue'], $fields[0]['filter']['colors']);
    }

    public function testMinValueFilterForCourageMatchesCourageEffectsOwnCheck(): void
    {
        $fields = CardChoiceSchema::forEffectKey('courage');

        self::assertSame(5, $fields[0]['filter']['min_value']);
    }

    public function testMaxValueFilterForShockMatchesShockEffectsOwnCheck(): void
    {
        $fields = CardChoiceSchema::forEffectKey('shock');

        self::assertSame(3, $fields[0]['filter']['max_value']);
    }

    public function testParityFiltersForAnxietyAndSpiteAreOpposite(): void
    {
        self::assertSame('odd', CardChoiceSchema::forEffectKey('anxiety')[0]['filter']['parity']);
        self::assertSame('even', CardChoiceSchema::forEffectKey('spite')[0]['filter']['parity']);
    }

    public function testHasDiceValueFilterForEncouragement(): void
    {
        $fields = CardChoiceSchema::forEffectKey('encouragement');

        self::assertTrue($fields[0]['filter']['has_dice_value']);
    }

    public function testMinMoodCountFilterForCrueltyAndMalice(): void
    {
        self::assertSame(2, CardChoiceSchema::forEffectKey('cruelty')[0]['filter']['min_mood_count']);
        self::assertSame(2, CardChoiceSchema::forEffectKey('malice')[0]['filter']['min_mood_count']);
    }

    public function testMinHandCountFilterForParanoiaSuspicionAndCuriosity(): void
    {
        // Both single-target (Paranoia, Curiosity) and multi-target
        // (Suspicion) player fields need the same "has cards in hand"
        // filter -- confirmed directly against ParanoiaEffect/
        // SuspicionEffect/CuriosityEffect's own InvalidChoiceException checks.
        self::assertSame(1, CardChoiceSchema::forEffectKey('paranoia')[0]['filter']['min_hand_count']);
        self::assertSame(1, CardChoiceSchema::forEffectKey('suspicion')[0]['filter']['min_hand_count']);
        self::assertSame(1, CardChoiceSchema::forEffectKey('curiosity')[0]['filter']['min_hand_count']);
    }

    public function testPrideExposesNoImmediateFields(): void
    {
        // Which player to target isn't an ordinary up-front field -- the
        // candidate list depends on Pride's own mood count, which isn't
        // knowable until Pride is actually in play. See PrideEffect, which
        // defers this choice to a pending decision the acting player
        // answers immediately after Pride has actually entered play.
        self::assertSame([], CardChoiceSchema::forEffectKey('pride'));
    }

    public function testValuesFilterDistinguishesEachHandDiscardValueBoostCard(): void
    {
        // Dignity, Embarrassment, Cheer, and Delight all discard a hand
        // card to boost this mood's value, but each qualifies on a
        // different, non-overlapping set of base values.
        self::assertSame([0, 1, 2, 3], CardChoiceSchema::forEffectKey('dignity')[0]['filter']['values']);
        self::assertSame([4, 5, 6], CardChoiceSchema::forEffectKey('embarrassment')[0]['filter']['values']);
        self::assertSame([0, 2, 4, 6], CardChoiceSchema::forEffectKey('cheer')[0]['filter']['values']);
        self::assertSame([1, 3, 5], CardChoiceSchema::forEffectKey('delight')[0]['filter']['values']);
    }

    public function testColorFilterForFascinationsGivenHandCard(): void
    {
        $fields = CardChoiceSchema::forEffectKey('fascination');

        self::assertSame(['blue', 'black'], $fields[0]['filter']['colors']);
    }

    public function testArroganceHasNoFilterSinceAnOpponentWithNoQualifyingMoodIsStillALegalNoOpChoice(): void
    {
        // Unlike e.g. Malice, ArroganceEffect never throws for an opponent
        // with no white/blue mood -- it just silently does nothing. So the
        // dropdown must not filter opponents out on that basis.
        $fields = CardChoiceSchema::forEffectKey('arrogance');

        self::assertArrayNotHasKey('filter', $fields[0]);
    }

    public function testExactCountForGuileMatchesItsMandatoryTwoCardCost(): void
    {
        $fields = CardChoiceSchema::forEffectKey('guile');

        self::assertSame(['min' => 2, 'max' => 2], $fields[0]['count']);
    }

    public function testOpenEndedMinimumCountForSelfLoathingAndNeurosis(): void
    {
        // "One or more" -- a minimum with no upper bound.
        self::assertSame(['min' => 1], CardChoiceSchema::forEffectKey('self_loathing')[0]['count']);
        self::assertSame(['min' => 1], CardChoiceSchema::forEffectKey('neurosis')[0]['count']);
    }

    public function testZeroOrExactlyTwoCountForRejectionDenialAndInstability(): void
    {
        // These are optional effects that either do nothing (0 chosen) or
        // require exactly 2 -- never 1 -- matching each effect's own
        // "if ($targets === []) return; if (count($targets) !== 2) throw".
        foreach (['rejection', 'denial', 'instability'] as $effectKey) {
            $field = CardChoiceSchema::forEffectKey($effectKey)[0];
            self::assertSame(2, $field['count']['min'], "{$effectKey} min");
            self::assertSame(2, $field['count']['max'], "{$effectKey} max");
            self::assertTrue($field['count']['zero_ok'], "{$effectKey} zero_ok");
        }
    }

    public function testUpToTwoCountForCourageFamilyCards(): void
    {
        // "Choose up to two players" cards -- 0, 1, or 2 selections, all legal.
        foreach (['courage', 'anxiety', 'spite', 'shock', 'pacifism', 'panic'] as $effectKey) {
            $field = CardChoiceSchema::forEffectKey($effectKey)[0];
            self::assertSame(['max' => 2], $field['count'], "{$effectKey} count");
        }
    }

    public function testDistinctOwnersConstraintForTheCourageFamily(): void
    {
        // Each of these explicitly tracks $affectedOwners and throws "can
        // only affect one mood per chosen player" -- Panic included, even
        // though its rules text doesn't spell out the per-player limit as
        // plainly as the others.
        foreach (['courage', 'anxiety', 'spite', 'shock', 'pacifism', 'panic'] as $effectKey) {
            $field = CardChoiceSchema::forEffectKey($effectKey)[0];
            self::assertSame(['type' => 'distinct_owners'], $field['constraint'], "{$effectKey} constraint");
        }
    }

    public function testHostilityAndWorrySecondStageHaveNoDistinctOwnersConstraint(): void
    {
        // Unlike Courage/Anxiety/etc., Hostility's and Worry's secondary
        // target_mood_ids never track $affectedOwners -- only a max count.
        $hostility = CardChoiceSchema::forEffectKey('hostility')[1];
        $worry = CardChoiceSchema::forEffectKey('worry')[1];

        self::assertSame(['max' => 2], $hostility['count']);
        self::assertArrayNotHasKey('constraint', $hostility);
        self::assertSame(['max' => 2], $worry['count']);
        self::assertArrayNotHasKey('constraint', $worry);
    }

    public function testSameColorOrValueConstraintForRejectionAndDenial(): void
    {
        self::assertSame(
            ['type' => 'same_color_or_value'],
            CardChoiceSchema::forEffectKey('rejection')[0]['constraint']
        );
        self::assertSame(
            ['type' => 'same_color_or_value'],
            CardChoiceSchema::forEffectKey('denial')[0]['constraint']
        );
    }

    public function testSameOwnerConstraintForInstability(): void
    {
        $fields = CardChoiceSchema::forEffectKey('instability');

        self::assertSame(['type' => 'same_owner'], $fields[0]['constraint']);
    }

    public function testMaxTotalValueConstraintForAnger(): void
    {
        $fields = CardChoiceSchema::forEffectKey('anger');

        self::assertSame(['type' => 'max_total_value', 'max' => 5], $fields[0]['constraint']);
        // No count cap -- Anger allows any number of moods, bounded only
        // by their combined value, unlike the Courage family's flat max of 2.
        self::assertArrayNotHasKey('count', $fields[0]);
    }

    public function testCrueltyIndecisivenessAndSuspicionHaveNoCountOrConstraintSinceTheyAllowAnyNumber(): void
    {
        foreach (['cruelty', 'indecisiveness', 'suspicion', 'doubt', 'thrill'] as $effectKey) {
            $field = CardChoiceSchema::forEffectKey($effectKey)[0];
            self::assertArrayNotHasKey('count', $field, "{$effectKey} count");
            self::assertArrayNotHasKey('constraint', $field, "{$effectKey} constraint");
        }
    }

    public function testReactionTemplateForScornMatchesScornEffectsOwnKey(): void
    {
        $template = CardChoiceSchema::reactionTemplate('scorn');

        self::assertSame('scorn_suppress_target', $template['key']);
        self::assertSame('mood', $template['type']);
        self::assertFalse($template['required']);
        // The template has no filter of its own -- GameService fills one
        // in per played card, since "must share a color" only makes sense
        // once a specific card's color is known.
        self::assertArrayNotHasKey('filter', $template);
    }

    public function testReactionTemplateForValidationMatchesValidationEffectsOwnKey(): void
    {
        $template = CardChoiceSchema::reactionTemplate('validation');

        self::assertSame('validation_extra_play', $template['key']);
        self::assertSame('bool', $template['type']);
        self::assertFalse($template['required']);
    }

    public function testReactionTemplateIsNullForAnyOtherEffectKey(): void
    {
        self::assertNull(CardChoiceSchema::reactionTemplate('compulsion'));
        self::assertNull(CardChoiceSchema::reactionTemplate('nonexistent'));
    }

    public function testCopyCardIdFieldForCreativityTargetsAnyMoodInPlay(): void
    {
        $fields = CardChoiceSchema::forEffectKey('creativity');

        self::assertCount(1, $fields);
        self::assertSame('copy_card_id', $fields[0]['key']);
        self::assertSame('mood', $fields[0]['type']);
        self::assertSame('any', $fields[0]['scope']);
        self::assertFalse($fields[0]['required']);
    }

    public function testReactionTemplateForDuplicityMatchesDuplicitysRepeatKey(): void
    {
        $template = CardChoiceSchema::reactionTemplate('duplicity');

        self::assertSame('duplicity_repeat', $template['key']);
        self::assertSame('bool', $template['type']);
        self::assertFalse($template['required']);
    }

    public function testAfterPlayingFieldsExcludesGuilesCostFieldButKeepsItsTargetField(): void
    {
        $fields = CardChoiceSchema::afterPlayingFields('guile');

        self::assertCount(1, $fields);
        self::assertSame('target_mood_id', $fields[0]['key']);
    }

    public function testAfterPlayingFieldsExcludesRegretsCostFieldButKeepsItsTargetField(): void
    {
        $fields = CardChoiceSchema::afterPlayingFields('regret');

        self::assertCount(1, $fields);
        self::assertSame('target_mood_id', $fields[0]['key']);
    }

    public function testAfterPlayingFieldsMatchesForEffectKeyForCardsWithNoCostField(): void
    {
        // Most cards have no 'stage' => 'cost' field at all, so
        // afterPlayingFields() should be a no-op filter for them.
        self::assertSame(CardChoiceSchema::forEffectKey('betrayal'), CardChoiceSchema::afterPlayingFields('betrayal'));
        self::assertSame([], CardChoiceSchema::afterPlayingFields('charity'));
    }
}
