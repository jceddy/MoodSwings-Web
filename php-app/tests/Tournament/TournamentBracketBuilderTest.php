<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Tournament;

use MoodSwings\Tournament\TournamentBracketBuilder;
use MoodSwings\Tournament\TournamentStateException;
use PHPUnit\Framework\TestCase;

final class TournamentBracketBuilderTest extends TestCase
{
    private TournamentBracketBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TournamentBracketBuilder();
    }

    /** @dataProvider participantCounts */
    public function testSingleEliminationShape(int $participantCount): void
    {
        $plan = $this->builder->buildSingleElimination($participantCount);
        $size = $plan['size'];

        self::assertGreaterThanOrEqual($participantCount, $size);
        self::assertSame($size, 2 ** (int) log($size, 2), 'size must be a power of two');

        $roundCount = (int) log($size, 2);
        self::assertCount($roundCount, $plan['rounds']);

        foreach ($plan['rounds'] as $round) {
            $expectedMatches = $size / (2 ** $round['round_number']);
            self::assertCount((int) $expectedMatches, $round['matches'], "round {$round['round_number']} match count");
        }

        // Round 1: exactly (size - participantCount) bye slots, and no
        // match has both slots empty.
        $round1 = $plan['rounds'][0]['matches'];
        $byeSlots = 0;
        foreach ($round1 as $match) {
            self::assertFalse($match['seed1'] === null && $match['seed2'] === null, 'no match may be bye-vs-bye');
            if ($match['seed1'] === null) {
                $byeSlots++;
            }
            if ($match['seed2'] === null) {
                $byeSlots++;
            }
        }
        self::assertSame($size - $participantCount, $byeSlots);

        // Every seed 1..participantCount appears exactly once across round 1.
        $seen = [];
        foreach ($round1 as $match) {
            foreach ([$match['seed1'], $match['seed2']] as $seed) {
                if ($seed !== null) {
                    self::assertArrayNotHasKey($seed, $seen, "seed {$seed} appears twice");
                    $seen[$seed] = true;
                }
            }
        }
        self::assertSame(range(1, $participantCount), self::sortedKeys($seen));

        // Every match except the final has exactly one outgoing advance,
        // and every (round>1) match slot receives exactly one inbound
        // advance per player position.
        self::assertCount($size - 2, $plan['advances']);
        $this->assertNoCoordinateCollisions($plan['advances']);
        $this->assertEveryLaterMatchFullyWired($plan['rounds'], $plan['advances'], ['single']);
    }

    public static function participantCounts(): array
    {
        return array_map(static fn (int $n): array => [$n], [2, 3, 4, 5, 6, 7, 8, 9, 16]);
    }

    public function testSingleEliminationRejectsFewerThanTwo(): void
    {
        $this->expectException(TournamentStateException::class);
        $this->builder->buildSingleElimination(1);
    }

    /** @dataProvider doubleEliminationParticipantCounts */
    public function testDoubleEliminationShape(int $participantCount): void
    {
        $plan = $this->builder->buildDoubleElimination($participantCount);
        $size = $plan['size'];
        $winnerRounds = (int) log($size, 2);
        $loserRoundCount = 2 * $winnerRounds - 2;

        $byBracket = [];
        foreach ($plan['rounds'] as $round) {
            $byBracket[$round['bracket']][$round['round_number']] = $round;
        }

        self::assertCount($winnerRounds, $byBracket['single']);
        self::assertCount($loserRoundCount, $byBracket['losers']);
        self::assertCount(2, $byBracket['grand_final']);
        self::assertCount(1, $byBracket['grand_final'][1]['matches']);
        self::assertCount(1, $byBracket['grand_final'][2]['matches']);

        foreach ($byBracket['losers'] as $roundNumber => $round) {
            $j = (int) ceil($roundNumber / 2);
            $expected = $size / (2 ** ($j + 1));
            self::assertCount((int) $expected, $round['matches'], "losers round {$roundNumber} match count");
        }

        $this->assertNoCoordinateCollisions($plan['advances']);

        // Every losers-bracket match, and every winners-bracket match
        // beyond round 1, must have exactly one inbound advance per
        // player slot -- nothing left permanently unfillable, nothing
        // double-targeted. Grand final round 2 (the conditional bracket
        // reset) is populated dynamically by TournamentService, not by
        // static wiring, so it's excluded here.
        $this->assertEveryLaterMatchFullyWired($byBracket['single'], $plan['advances'], ['single']);
        $this->assertEveryLaterMatchFullyWired($byBracket['losers'], $plan['advances'], ['single', 'losers']);

        // Every winners-bracket match (all rounds, including round 1)
        // has an outgoing 'loser' edge into the losers bracket.
        $loserEdgesFrom = [];
        foreach ($plan['advances'] as $advance) {
            if ($advance['on'] === 'loser') {
                $loserEdgesFrom[] = "{$advance['from']['bracket']}:{$advance['from']['round_number']}:{$advance['from']['slot']}";
            }
        }
        $winnersMatchCount = 0;
        foreach ($byBracket['single'] as $round) {
            $winnersMatchCount += count($round['matches']);
        }
        self::assertCount($winnersMatchCount, array_unique($loserEdgesFrom));

        // The grand final is fed by exactly the winners-bracket champion
        // (player 1) and the losers-bracket champion (player 2).
        $grandFinalEdges = array_values(array_filter(
            $plan['advances'],
            static fn (array $a): bool => $a['to']['bracket'] === 'grand_final'
        ));
        self::assertCount(2, $grandFinalEdges);
        $players = array_map(static fn (array $a): int => $a['to']['player'], $grandFinalEdges);
        sort($players);
        self::assertSame([1, 2], $players);
    }

    public static function doubleEliminationParticipantCounts(): array
    {
        return array_map(static fn (int $n): array => [$n], [4, 5, 6, 7, 8, 9, 16]);
    }

    public function testDoubleEliminationRejectsFewerThanFour(): void
    {
        $this->expectException(TournamentStateException::class);
        $this->builder->buildDoubleElimination(3);
    }

    public function testSwissPairingsAvoidsRepeatsAndAssignsBye(): void
    {
        // 5 active participants (odd -- someone must sit out), one pair
        // (1,2) already played.
        $wins = [1 => 2, 2 => 1, 3 => 1, 4 => 0, 5 => 0];
        $alreadyPlayed = [TournamentBracketBuilder::pairKey(1, 2) => true];

        $result = $this->builder->swissPairings($wins, $alreadyPlayed, priorByes: []);

        self::assertNotNull($result['bye']);
        // The bye goes to the lowest standing among eligible players.
        self::assertSame(5, $result['bye']);

        $paired = [];
        foreach ($result['pairs'] as [$a, $b]) {
            self::assertNotSame($a, $b);
            self::assertArrayNotHasKey($a, $paired);
            self::assertArrayNotHasKey($b, $paired);
            $paired[$a] = true;
            $paired[$b] = true;
            self::assertArrayNotHasKey(TournamentBracketBuilder::pairKey($a, $b), $alreadyPlayed, 'must not repeat an already-played pairing');
        }
        self::assertCount(4, $paired);
    }

    public function testSwissPairingsWithEvenCountHasNoBye(): void
    {
        $wins = [1 => 1, 2 => 1, 3 => 0, 4 => 0];
        $result = $this->builder->swissPairings($wins, alreadyPlayed: []);

        self::assertNull($result['bye']);
        self::assertCount(2, $result['pairs']);
    }

    private function assertNoCoordinateCollisions(array $advances): void
    {
        $targeted = [];
        foreach ($advances as $advance) {
            $key = "{$advance['to']['bracket']}:{$advance['to']['round_number']}:{$advance['to']['slot']}:{$advance['to']['player']}";
            self::assertArrayNotHasKey($key, $targeted, "slot {$key} targeted by more than one advance");
            $targeted[$key] = true;
        }
    }

    /**
     * Every match in a round beyond round 1 of the given brackets must
     * have exactly one inbound advance for player 1 and exactly one for
     * player 2 -- i.e. it will genuinely end up with two participants
     * once earlier matches resolve, never stuck permanently short.
     */
    private function assertEveryLaterMatchFullyWired(array $rounds, array $advances, array $brackets): void
    {
        $inbound = [];
        foreach ($advances as $advance) {
            if (!in_array($advance['to']['bracket'], $brackets, true)) {
                continue;
            }
            $key = "{$advance['to']['bracket']}:{$advance['to']['round_number']}:{$advance['to']['slot']}";
            $inbound[$key][$advance['to']['player']] = true;
        }

        foreach ($rounds as $round) {
            if (!in_array($round['bracket'], $brackets, true) || $round['round_number'] === 1) {
                continue;
            }
            foreach ($round['matches'] as $match) {
                $key = "{$round['bracket']}:{$round['round_number']}:{$match['slot']}";
                self::assertArrayHasKey($key, $inbound, "{$key} has no inbound advances at all");
                self::assertArrayHasKey(1, $inbound[$key], "{$key} missing player 1 advance");
                self::assertArrayHasKey(2, $inbound[$key], "{$key} missing player 2 advance");
            }
        }
    }

    private static function sortedKeys(array $assoc): array
    {
        $keys = array_map(intval(...), array_keys($assoc));
        sort($keys);

        return $keys;
    }
}
