<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Game\DuelDeckRules;
use MoodSwings\Game\Exceptions\GameStateException;
use PHPUnit\Framework\TestCase;

final class DuelDeckRulesTest extends TestCase
{
    /** @return array<int, array{name: string, rarity: string, color: string}> */
    private function catalog(): array
    {
        $colors = ['white', 'blue', 'black', 'red', 'green'];
        $catalog = [];
        // 1-10: common (2 per color, round-robin -- exactly even), 11-15:
        // uncommon (1 per color -- exactly even), 16-18: rare (3 cards,
        // can never split evenly across 5 colors), 19-20: mythic (2 cards,
        // same).
        for ($id = 1; $id <= 10; $id++) {
            $catalog[$id] = ['name' => "Common{$id}", 'rarity' => 'common', 'color' => $colors[($id - 1) % 5]];
        }
        for ($id = 11; $id <= 15; $id++) {
            $catalog[$id] = ['name' => "Uncommon{$id}", 'rarity' => 'uncommon', 'color' => $colors[($id - 11) % 5]];
        }
        for ($id = 16; $id <= 18; $id++) {
            $catalog[$id] = ['name' => "Rare{$id}", 'rarity' => 'rare', 'color' => $colors[($id - 16) % 5]];
        }
        for ($id = 19; $id <= 20; $id++) {
            $catalog[$id] = ['name' => "Mythic{$id}", 'rarity' => 'mythic', 'color' => $colors[($id - 19) % 5]];
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
        self::assertSame([], $rules->evenColorDistributionRarities);
    }

    public function testPowerPresetRequiresAtLeastFifteenSingletonCardsWithAtMostOneMythic(): void
    {
        $rules = DuelDeckRules::forPreset('power');

        self::assertSame(15, $rules->minCards);
        self::assertSame(['mythic' => 1], $rules->rarityLimits);
        self::assertSame(['common' => 1, 'uncommon' => 1, 'rare' => 1, 'mythic' => 1], $rules->duplicateLimits);
        self::assertSame([], $rules->evenColorDistributionRarities);
    }

    public function testJceddys75PresetMatchesTheAggregateRaritySplitAcrossAllFiveColors(): void
    {
        $rules = DuelDeckRules::forPreset('jceddys_75');

        self::assertSame(75, $rules->minCards);
        self::assertSame(['mythic' => 5, 'rare' => 10, 'uncommon' => 20, 'common' => 40], $rules->rarityLimits);
        self::assertSame(['mythic' => 1, 'rare' => 1, 'uncommon' => 2, 'common' => 3], $rules->duplicateLimits);
        self::assertSame(['common', 'uncommon', 'rare', 'mythic'], $rules->evenColorDistributionRarities);
    }

    public function testAnUnknownPresetNameThrows(): void
    {
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('Unknown duel deck rules preset "nonsense"');

        DuelDeckRules::forPreset('nonsense');
    }

    public function testToArrayRoundTripsAllFourRuleValues(): void
    {
        $rules = new DuelDeckRules(20, ['mythic' => 2], ['common' => 3], ['mythic']);

        self::assertSame([
            'min_cards' => 20,
            'rarity_limits' => ['mythic' => 2],
            'duplicate_limits' => ['common' => 3],
            'even_color_distribution_rarities' => ['mythic'],
        ], $rules->toArray());
    }

    public function testValidateAcceptsAnExactlyEvenColorSplit(): void
    {
        $rules = new DuelDeckRules(10, [], [], ['common']);

        // All 10 commons -- exactly 2 of each color.
        $rules->validate(range(1, 10), $this->catalog());
        $this->addToAssertionCount(1);
    }

    public function testValidateRejectsATotalThatCannotSplitEvenlyAcrossFiveColors(): void
    {
        $rules = new DuelDeckRules(9, [], [], ['common']);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage("has 9 common card(s), which can't be split evenly across the 5 colors");

        // 9 commons (id 10, green, omitted) -- not divisible by 5.
        $rules->validate(range(1, 9), $this->catalog());
    }

    public function testValidateRejectsALopsidedColorSplitEvenWhenTheTotalDivides(): void
    {
        $rules = new DuelDeckRules(10, [], [], ['common']);

        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('must have exactly 2 white common card(s) for an even distribution across colors (has 3)');

        // 10 commons total (divisible by 5), but id 1 (white) doubled in
        // place of id 10 (green) -- white=3, green=1, everything else=2.
        $rules->validate([1, 1, 2, 3, 4, 5, 6, 7, 8, 9], $this->catalog());
    }

    public function testEvenColorDistributionIsPerRarityNotGlobal(): void
    {
        $rules = new DuelDeckRules(7, [], [], ['common']);

        // 5 commons, one of each color (evenly split), plus 2 rares --
        // rares can never split evenly across 5 colors (only 3 exist),
        // but the rule only applies to 'common' here, so this is still fine.
        $rules->validate([1, 2, 3, 4, 5, 16, 17], $this->catalog());
        $this->addToAssertionCount(1);
    }
}
