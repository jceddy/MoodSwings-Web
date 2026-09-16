<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Tournament;

use MoodSwings\Tournament\BoosterPackBuilder;
use PHPUnit\Framework\TestCase;

final class BoosterPackBuilderTest extends TestCase
{
    private BoosterPackBuilder $builder;

    /** @var array<string, int[]> a synthetic catalog with plenty of distinct ids per rarity -- real card counts, just not real card ids. */
    private array $cardIdsByRarity;

    protected function setUp(): void
    {
        $this->builder = new BoosterPackBuilder();
        $this->cardIdsByRarity = [
            'mythic' => range(1, 20),
            'rare' => range(101, 140),
            'uncommon' => range(201, 260),
            'common' => range(301, 400),
        ];
    }

    public function testBoosterHasExactlyFifteenDistinctCards(): void
    {
        $booster = $this->builder->buildBooster($this->cardIdsByRarity);

        self::assertCount(15, $booster);
        self::assertCount(15, array_unique($booster), 'no card should repeat within a single booster');
    }

    /**
     * Slots are fixed-rarity or fixed-odds (see the class's own
     * docblock) -- runs many boosters and asserts every card landed in
     * an EXPECTED rarity for its own slot, then separately (since a
     * single run could get unlucky) that both branches of each
     * probabilistic slot show up somewhere across the whole run.
     */
    public function testSlotRaritiesMatchTheDocumentedOdds(): void
    {
        $rarityOf = [];
        foreach ($this->cardIdsByRarity as $rarity => $ids) {
            foreach ($ids as $id) {
                $rarityOf[$id] = $rarity;
            }
        }

        $slot1Rarities = [];
        $slot3Rarities = [];
        $slot8Rarities = [];

        for ($i = 0; $i < 300; $i++) {
            $booster = $this->builder->buildBooster($this->cardIdsByRarity);

            self::assertContains($rarityOf[$booster[0]], ['mythic', 'rare'], 'slot 1 must be mythic or rare');
            self::assertSame('rare', $rarityOf[$booster[1]], 'slot 2 is always rare');
            self::assertContains($rarityOf[$booster[2]], ['rare', 'uncommon'], 'slot 3 must be rare or uncommon');
            for ($slot = 3; $slot < 7; $slot++) {
                self::assertSame('uncommon', $rarityOf[$booster[$slot]], "slot " . ($slot + 1) . " is always uncommon");
            }
            self::assertContains($rarityOf[$booster[7]], ['uncommon', 'common'], 'slot 8 must be uncommon or common');
            for ($slot = 8; $slot < 15; $slot++) {
                self::assertSame('common', $rarityOf[$booster[$slot]], "slot " . ($slot + 1) . " is always common");
            }

            $slot1Rarities[$rarityOf[$booster[0]]] = true;
            $slot3Rarities[$rarityOf[$booster[2]]] = true;
            $slot8Rarities[$rarityOf[$booster[7]]] = true;
        }

        self::assertCount(2, $slot1Rarities, 'both mythic and rare should appear in slot 1 across 300 boosters');
        self::assertCount(2, $slot3Rarities, 'both rare and uncommon should appear in slot 3 across 300 boosters');
        self::assertCount(2, $slot8Rarities, 'both uncommon and common should appear in slot 8 across 300 boosters');
    }
}
