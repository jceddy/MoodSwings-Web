<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Tournament;

use MoodSwings\Tournament\GridDraftPodBuilder;
use MoodSwings\Tournament\TournamentStateException;
use PHPUnit\Framework\TestCase;

final class GridDraftPodBuilderTest extends TestCase
{
    private GridDraftPodBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new GridDraftPodBuilder();
    }

    /** @dataProvider participantCounts */
    public function testPodSizesAreEvenAndWithinBounds(int $participantCount, array $expectedSizes): void
    {
        $sizes = $this->builder->podSizes($participantCount);

        self::assertSame($expectedSizes, $sizes);
        self::assertSame($participantCount, array_sum($sizes));
        foreach ($sizes as $size) {
            self::assertGreaterThanOrEqual(2, $size);
            self::assertLessThanOrEqual(4, $size);
        }
    }

    public static function participantCounts(): array
    {
        return [
            '2 players, one pod' => [2, [2]],
            '4 players, one pod' => [4, [4]],
            '5 players splits evenly, not 4+1' => [5, [3, 2]],
            '6 players' => [6, [3, 3]],
            '8 players, two full pods' => [8, [4, 4]],
            '9 players, three pods as even as possible' => [9, [3, 3, 3]],
        ];
    }

    public function testRejectsFewerThanTwoParticipants(): void
    {
        $this->expectException(TournamentStateException::class);
        $this->builder->podSizes(1);
    }
}
