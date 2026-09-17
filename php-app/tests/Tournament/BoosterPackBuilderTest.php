<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Tournament;

use MoodSwings\Tournament\BoosterPackBuilder;
use MoodSwings\Tournament\TournamentStateException;
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

    public function testBoosterHasTheGuaranteedRarityCounts(): void
    {
        $rarityOf = [];
        foreach ($this->cardIdsByRarity as $rarity => $ids) {
            foreach ($ids as $id) {
                $rarityOf[$id] = $rarity;
            }
        }

        $booster = $this->builder->buildBooster($this->cardIdsByRarity);

        $counts = ['mythic' => 0, 'rare' => 0, 'uncommon' => 0, 'common' => 0];
        foreach ($booster as $cardId) {
            $counts[$rarityOf[$cardId]]++;
        }

        self::assertSame(['mythic' => 1, 'rare' => 2, 'uncommon' => 4, 'common' => 8], $counts);
    }

    public function testRejectsAPoolTooSmallForARarity(): void
    {
        $this->cardIdsByRarity['mythic'] = [1];
        $this->cardIdsByRarity['rare'] = [101];

        $this->expectException(TournamentStateException::class);
        $this->expectExceptionMessage('Not enough rare cards');
        $this->builder->buildBooster($this->cardIdsByRarity);
    }
}
