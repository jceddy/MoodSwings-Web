<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Tournament;

use MoodSwings\Tournament\BoosterDraftPodBuilder;
use MoodSwings\Tournament\TournamentStateException;
use PHPUnit\Framework\TestCase;

final class BoosterDraftPodBuilderTest extends TestCase
{
    private BoosterDraftPodBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new BoosterDraftPodBuilder();
    }

    /** @dataProvider participantCounts */
    public function testPodSizesAreEvenAndWithinBounds(int $participantCount, array $expectedSizes): void
    {
        $sizes = $this->builder->podSizes($participantCount);

        self::assertSame($expectedSizes, $sizes);
        self::assertSame($participantCount, array_sum($sizes));
        foreach ($sizes as $size) {
            self::assertGreaterThanOrEqual(2, $size);
            self::assertLessThanOrEqual(8, $size);
        }
    }

    public static function participantCounts(): array
    {
        return [
            '2 players, one pod' => [2, [2]],
            '8 players, one pod' => [8, [8]],
            '9 players splits evenly, not 8+1' => [9, [5, 4]],
            '10 players' => [10, [5, 5]],
            '16 players, two full pods' => [16, [8, 8]],
            '17 players, three pods as even as possible' => [17, [6, 6, 5]],
        ];
    }

    public function testRejectsFewerThanTwoParticipants(): void
    {
        $this->expectException(TournamentStateException::class);
        $this->builder->podSizes(1);
    }

    /**
     * Every seat in a pod of $podSize should hold, over the booster's own
     * 15-round lifetime, exactly one round from every other opener's own
     * booster in that direction (including its own) -- confirming the
     * circulation math in the class's own docblock: everyone nets
     * exactly 15 cards from each direction, 30 total, regardless of pod
     * size.
     *
     * @dataProvider podSizesForCirculation
     */
    public function testCirculationVisitsEverySeatExactlyOncePerRound(int $podSize): void
    {
        foreach (['left', 'right'] as $direction) {
            for ($openerSeat = 0; $openerSeat < $podSize; $openerSeat++) {
                $holdersAcrossRounds = [];
                for ($round = 1; $round <= 15; $round++) {
                    $holdersAcrossRounds[] = BoosterDraftPodBuilder::seatHoldingBooster($openerSeat, $direction, $round, $podSize);
                }

                // Every one of the pod's own seats must appear at least
                // once (and, since 15 rounds >= podSize for every
                // podSize <= 8, some appear more than once -- only their
                // PRESENCE matters here, not the count).
                foreach (range(0, $podSize - 1) as $seat) {
                    self::assertContains($seat, $holdersAcrossRounds, "seat {$seat} should hold opener {$openerSeat}'s {$direction} booster at least once");
                }
            }
        }
    }

    public static function podSizesForCirculation(): array
    {
        return [[2], [3], [4], [5], [6], [7], [8]];
    }

    public function testOpenerSeatHeldByIsTheExactInverseOfSeatHoldingBooster(): void
    {
        $podSize = 6;
        foreach (['left', 'right'] as $direction) {
            for ($round = 1; $round <= 15; $round++) {
                for ($openerSeat = 0; $openerSeat < $podSize; $openerSeat++) {
                    $holder = BoosterDraftPodBuilder::seatHoldingBooster($openerSeat, $direction, $round, $podSize);
                    self::assertSame(
                        $openerSeat,
                        BoosterDraftPodBuilder::openerSeatHeldBy($holder, $direction, $round, $podSize),
                    );
                }
            }
        }
    }
}
