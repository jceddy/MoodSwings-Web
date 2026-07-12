<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Game\DuelDeckRules;
use MoodSwings\Game\Exceptions\GameStateException;
use PHPUnit\Framework\TestCase;

final class DuelDeckRulesTest extends TestCase
{
    /** @return array<int, array{name: string, rarity: string}> */
    private function catalog(): array
    {
        $catalog = [];
        // 1-10: common, 11-15: uncommon, 16-18: rare, 19-20: mythic.
        for ($id = 1; $id <= 10; $id++) {
            $catalog[$id] = ['name' => "Common{$id}", 'rarity' => 'common'];
        }
        for ($id = 11; $id <= 15; $id++) {
            $catalog[$id] = ['name' => "Uncommon{$id}", 'rarity' => 'uncommon'];
        }
        for ($id = 16; $id <= 18; $id++) {
            $catalog[$id] = ['name' => "Rare{$id}", 'rarity' => 'rare'];
        }
        for ($id = 19; $id <= 20; $id++) {
            $catalog[$id] = ['name' => "Mythic{$id}", 'rarity' => 'mythic'];
        }

        return $catalog;
    }

    public function testAMinCardsBelowSevenIsRejectedAtConstruction(): void
    {
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('cannot be lower than 7');

        new DuelDeckRules(6);
    }

    public function testSevenIsAnAcceptableMinimum(): void
    {
        $rules = new DuelDeckRules(7);

        self::assertSame(7, $rules->minCards);
    }

    public function testValidateAcceptsADeckMeetingEveryRule(): void
    {
        $rules = new DuelDeckRules(10, ['common' => 5], ['common' => 2]);

        // 5 commons (id 1 doubled, within the 2-copy cap), 3 uncommons, 1 rare, 1 mythic -- 10 total.
        $rules->validate([1, 1, 2, 3, 4, 11, 12, 13, 16, 19], $this->catalog());
        $this->addToAssertionCount(1);
    }

    public function testValidateRejectsTooFewCards(): void
    {
        $rules = new DuelDeckRules(10);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('has only 3 card(s), but at least 10 are required');

        $rules->validate([1, 2, 3], $this->catalog());
    }

    public function testValidateRejectsExceedingARarityLimit(): void
    {
        $rules = new DuelDeckRules(7, ['common' => 3]);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('has 4 common card(s), but at most 3 common card(s) are allowed');

        $rules->validate([1, 2, 3, 4, 11, 12, 16], $this->catalog());
    }

    public function testValidateRejectsExceedingADuplicateLimit(): void
    {
        $rules = new DuelDeckRules(7, [], ['common' => 2]);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('has 3 copies of "Common1" (common), but at most 2 copies of any common card are allowed');

        $rules->validate([1, 1, 1, 2, 3, 4, 5], $this->catalog());
    }

    public function testValidateSingularCopyWordingWhenTheLimitIsOne(): void
    {
        $rules = new DuelDeckRules(7, [], ['mythic' => 1]);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('but at most 1 copy of any mythic card are allowed');

        $rules->validate([19, 19, 1, 2, 3, 4, 5], $this->catalog());
    }

    public function testAnUnrestrictedRarityHasNoCapAtAll(): void
    {
        $rules = new DuelDeckRules(7, ['mythic' => 1]);

        // 6 commons plus 1 mythic -- common is never mentioned in
        // $rarityLimits, so any number of commons is fine.
        $rules->validate([1, 2, 3, 4, 5, 6, 19], $this->catalog());
        $this->addToAssertionCount(1);
    }

    public function testStructurePresetMatchesTheStructureDeckGeneratorsExactSplit(): void
    {
        $rules = DuelDeckRules::forPreset('structure');

        self::assertSame(45, $rules->minCards);
        self::assertSame(['common' => 23, 'uncommon' => 14, 'rare' => 6, 'mythic' => 2], $rules->rarityLimits);
        self::assertSame(['common' => 1, 'uncommon' => 1, 'rare' => 1, 'mythic' => 1], $rules->duplicateLimits);
    }

    public function testPowerPresetRequiresAtLeastFifteenSingletonCardsWithAtMostOneMythic(): void
    {
        $rules = DuelDeckRules::forPreset('power');

        self::assertSame(15, $rules->minCards);
        self::assertSame(['mythic' => 1], $rules->rarityLimits);
        self::assertSame(['common' => 1, 'uncommon' => 1, 'rare' => 1, 'mythic' => 1], $rules->duplicateLimits);
    }

    public function testJceddys75PresetMatchesTheAggregateRaritySplitAcrossAllFiveColors(): void
    {
        $rules = DuelDeckRules::forPreset('jceddys_75');

        self::assertSame(75, $rules->minCards);
        self::assertSame(['mythic' => 5, 'rare' => 10, 'uncommon' => 20, 'common' => 40], $rules->rarityLimits);
        self::assertSame(['mythic' => 1, 'rare' => 1, 'uncommon' => 2, 'common' => 3], $rules->duplicateLimits);
    }

    public function testAnUnknownPresetNameThrows(): void
    {
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('Unknown duel deck rules preset "nonsense"');

        DuelDeckRules::forPreset('nonsense');
    }

    public function testToArrayRoundTripsTheThreeRuleValues(): void
    {
        $rules = new DuelDeckRules(20, ['mythic' => 2], ['common' => 3]);

        self::assertSame([
            'min_cards' => 20,
            'rarity_limits' => ['mythic' => 2],
            'duplicate_limits' => ['common' => 3],
        ], $rules->toArray());
    }
}
