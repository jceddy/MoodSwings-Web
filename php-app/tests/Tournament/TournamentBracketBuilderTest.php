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

        $this->assertNoCoordinateCollisions($plan['advances']);

        // Independently re-derive, from $participantCount/$size alone
        // (the same recursion TournamentBracketBuilder's own docblock
        // describes, reasoned through fresh here rather than copied from
        // its implementation), exactly which losers-bracket slots should
        // exist at all and how many real entrants (1 = a bye, 2 = a real
        // match) each one structurally expects -- then cross-check the
        // builder's actual output against it, slot by slot.
        $wbRound1EntrantCount = [];
        foreach ($byBracket['single'][1]['matches'] as $match) {
            $wbRound1EntrantCount[$match['slot']] = ($match['seed1'] !== null ? 1 : 0) + ($match['seed2'] !== null ? 1 : 0);
        }

        $expectedLbCounts = [];
        for ($lbRound = 1; $lbRound <= $loserRoundCount; $lbRound++) {
            $j = (int) ceil($lbRound / 2);
            $matchCount = $size / (2 ** ($j + 1));
            $isMinor = $lbRound % 2 === 1;
            $counts = [];
            for ($slot = 1; $slot <= $matchCount; $slot++) {
                if ($isMinor) {
                    if ($lbRound === 1) {
                        $a = $wbRound1EntrantCount[2 * $slot - 1] === 2 ? 1 : 0;
                        $b = $wbRound1EntrantCount[2 * $slot] === 2 ? 1 : 0;
                    } else {
                        $prevMajor = $expectedLbCounts[$lbRound - 1];
                        $a = ($prevMajor[2 * $slot - 1] ?? 0) >= 1 ? 1 : 0;
                        $b = ($prevMajor[2 * $slot] ?? 0) >= 1 ? 1 : 0;
                    }
                    $counts[$slot] = $a + $b;
                } else {
                    $prevMinor = $expectedLbCounts[$lbRound - 1];
                    $counts[$slot] = (($prevMinor[$slot] ?? 0) >= 1 ? 1 : 0) + 1;
                }
            }
            $expectedLbCounts[$lbRound] = $counts;
        }

        // Major rounds (the ones fed by a guaranteed winners-bracket
        // loser) can never be empty; only a minor round can be.
        foreach ($expectedLbCounts as $lbRound => $counts) {
            if ($lbRound % 2 === 0) {
                foreach ($counts as $slot => $count) {
                    self::assertGreaterThanOrEqual(1, $count, "losers round {$lbRound} slot {$slot} (a major round) must never be empty");
                }
            }
        }

        foreach ($expectedLbCounts as $lbRound => $counts) {
            $actualSlots = array_map(static fn (array $m): int => $m['slot'], $byBracket['losers'][$lbRound]['matches']);
            sort($actualSlots);
            $expectedSlots = array_keys(array_filter($counts, static fn (int $c): bool => $c > 0));
            sort($expectedSlots);
            self::assertSame($expectedSlots, $actualSlots, "losers round {$lbRound}'s existing slots");
        }

        // Inbound-edge count per (bracket, round, slot) target, across
        // ALL advances (winner and loser edges alike).
        $inboundCount = [];
        foreach ($plan['advances'] as $advance) {
            $key = "{$advance['to']['bracket']}:{$advance['to']['round_number']}:{$advance['to']['slot']}";
            $inboundCount[$key] = ($inboundCount[$key] ?? 0) + 1;
        }

        // Winners-bracket round 2+ matches are always real (2 inbound edges).
        for ($wbRound = 2; $wbRound <= $winnerRounds; $wbRound++) {
            foreach ($byBracket['single'][$wbRound]['matches'] as $match) {
                $key = "single:{$wbRound}:{$match['slot']}";
                self::assertSame(2, $inboundCount[$key] ?? 0, "{$key} must have exactly 2 inbound edges");
            }
        }

        // Every existing losers-bracket slot's actual inbound edge count
        // must match its independently-derived expected entrant count.
        foreach ($expectedLbCounts as $lbRound => $counts) {
            foreach ($counts as $slot => $expectedCount) {
                if ($expectedCount === 0) {
                    continue;
                }
                $key = "losers:{$lbRound}:{$slot}";
                self::assertSame($expectedCount, $inboundCount[$key] ?? 0, "{$key} inbound edge count");
            }
        }

        // Every winners-bracket REAL match (round 1 real matches, and
        // every round 2+ match unconditionally) has an outgoing 'loser'
        // edge; a round-1 BYE match must not.
        $loserEdgesFrom = [];
        foreach ($plan['advances'] as $advance) {
            if ($advance['on'] === 'loser') {
                $loserEdgesFrom[] = "{$advance['from']['bracket']}:{$advance['from']['round_number']}:{$advance['from']['slot']}";
            }
        }
        foreach ($byBracket['single'][1]['matches'] as $match) {
            $key = "single:1:{$match['slot']}";
            if ($wbRound1EntrantCount[$match['slot']] === 2) {
                self::assertContains($key, $loserEdgesFrom, "{$key} (a real round-1 match) must feed a loser into the losers bracket");
            } else {
                self::assertNotContains($key, $loserEdgesFrom, "{$key} (a bye) must not produce a loser");
            }
        }
        for ($wbRound = 2; $wbRound <= $winnerRounds; $wbRound++) {
            foreach ($byBracket['single'][$wbRound]['matches'] as $match) {
                self::assertContains("single:{$wbRound}:{$match['slot']}", $loserEdgesFrom);
            }
        }

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
        return array_map(static fn (int $n): array => [$n], [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17]);
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
