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

    public function testTwoFieldCardForBetrayalNeedsAMoodAndAPlayer(): void
    {
        $fields = CardChoiceSchema::forEffectKey('betrayal');

        self::assertCount(2, $fields);
        self::assertSame('mood', $fields[0]['type']);
        self::assertSame('own', $fields[0]['scope']);
        self::assertSame('player', $fields[1]['type']);
        self::assertSame('other', $fields[1]['scope']);
        self::assertTrue($fields[0]['required']);
        self::assertTrue($fields[1]['required']);
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

    public function testMoreMoodsThanViewerFilterForPride(): void
    {
        $fields = CardChoiceSchema::forEffectKey('pride');

        self::assertTrue($fields[0]['filter']['more_moods_than_viewer']);
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
}
