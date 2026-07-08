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
}
