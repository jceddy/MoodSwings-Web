<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\ChaosCardChoiceSchema;
use PHPUnit\Framework\TestCase;

/**
 * See ChaosCardChoiceSchema's own docblock for why this exists: an
 * attached chaos effect needs the same "what does this need to ask the
 * player" schema a base card's own effect_key already gets from
 * CardChoiceSchema, or GameService::serializeCard() has nothing to expose
 * and the frontend never renders a prompt at all -- exactly the bug
 * reported for chaos_035 (issue #405 follow-up).
 */
final class ChaosCardChoiceSchemaTest extends TestCase
{
    public function testAnUnregisteredOrNullEffectKeyNeedsNoFields(): void
    {
        self::assertSame([], ChaosCardChoiceSchema::forEffectKey(null));
        self::assertSame([], ChaosCardChoiceSchema::forEffectKey('nonexistent'));
    }

    public function testAChaosEffectThatNeverReadsPlayerChoicesHasNoEntry(): void
    {
        // chaos_006's own class DOES read a choice -- pick a plain
        // while_in_play-shaped key instead to prove "no entry" isn't
        // just "I forgot to check": chaos_001 is a pure computeValue()
        // formula effect, reading nothing from PlayerChoices at all.
        self::assertSame([], ChaosCardChoiceSchema::forEffectKey('chaos_001'));
    }

    public function testChaos035sValueFieldMatchesTheReportedBug(): void
    {
        $fields = ChaosCardChoiceSchema::forEffectKey('chaos_035');

        self::assertCount(1, $fields);
        self::assertSame('value', $fields[0]['key']);
        self::assertSame('value', $fields[0]['type']);
        self::assertFalse($fields[0]['required']);
        self::assertSame(0, $fields[0]['min']);
        self::assertSame(12, $fields[0]['max']);
    }

    public function testAMultiFieldChaosEffectExposesBothFieldsInOrder(): void
    {
        // chaos_012 used to be this test's own example, but it now defers
        // its own choice entirely (issue #405 follow-up -- see this
        // class's own docblock) -- chaos_062 (a DISCARD PILE card, not a
        // hand card, so unaffected by that fix) still has the same
        // "optional field + its own required-if-used companion" shape.
        $fields = ChaosCardChoiceSchema::forEffectKey('chaos_062');

        self::assertCount(2, $fields);
        self::assertSame('discard_card_id', $fields[0]['key']);
        self::assertSame('opponent_player_id', $fields[1]['key']);
        // Not statically 'required' -- Chaos062Effect only reads it once
        // discard_card_id is also given, the same "required if the
        // companion optional field above is used" pattern CardChoiceSchema's
        // own faith/guile entries already use.
        self::assertFalse($fields[1]['required']);
    }

    public function testAReusableParameterizedClasssRegisteredKeysAreCovered(): void
    {
        // ChaosActOnChosenPlayersMoodEffect backs chaos_007/020/028/048/076/101
        // -- each registration gets its own schema entry keyed by its own
        // effect_key, not by class.
        foreach (['chaos_007', 'chaos_020', 'chaos_028', 'chaos_048', 'chaos_076', 'chaos_101'] as $effectKey) {
            $fields = ChaosCardChoiceSchema::forEffectKey($effectKey);
            self::assertCount(1, $fields, "{$effectKey} should have exactly one field");
            self::assertSame('target_mood_ids', $fields[0]['key']);
            self::assertTrue($fields[0]['multi']);
        }
    }
}
