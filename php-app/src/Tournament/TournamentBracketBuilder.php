<?php

declare(strict_types=1);

namespace MoodSwings\Tournament;

/**
 * Pure bracket-shape math -- no database access, no knowledge of
 * TournamentService/GameService. Every method here works purely in
 * terms of SEED NUMBERS (1 = strongest) and abstract (bracket, round,
 * slot) coordinates; the caller (TournamentService) is the one that
 * turns seed numbers into real tournament_participants ids and
 * (bracket, round, slot) coordinates into real inserted row ids --
 * later rounds' matches genuinely don't have real participants yet
 * (their winner isn't decided until an earlier match resolves), so this
 * class only ever fills in round 1 (single/double elimination) with
 * real seed numbers; every later round is pure structure plus wiring.
 *
 * Single/double elimination both pad the participant count up to the
 * next power of two, filling the gap with byes -- the standard approach
 * essentially every bracket tool uses, rather than inventing an
 * irregular-bracket-size scheme. A bye is placed against the weakest
 * real seeds first (see buildSeedOrder()'s own docblock for why the
 * standard recursive seeding placement makes that automatic), so the
 * top seeds are the ones who benefit from a "free win" into round 2.
 */
final class TournamentBracketBuilder
{
    /**
     * @return array{size: int, rounds: list<array{bracket: string, round_number: int, matches: list<array{slot: int, seed1: ?int, seed2: ?int}>}>, advances: list<array{from: array{bracket: string, round_number: int, slot: int}, to: array{bracket: string, round_number: int, slot: int, player: int}, on: string}>}
     */
    public function buildSingleElimination(int $participantCount): array
    {
        if ($participantCount < 2) {
            throw new TournamentStateException('A single-elimination bracket needs at least 2 participants');
        }

        $size = self::nextPowerOfTwo($participantCount);
        $seedOrder = self::buildSeedOrder($size);
        $roundCount = (int) log($size, 2);

        $rounds = [];
        $advances = [];

        // Round 1: real seed numbers (or null for a bye) straight from
        // the seeding order, paired up two-at-a-time in bracket-slot order.
        $round1Matches = [];
        for ($slot = 1, $i = 0; $i < $size; $slot++, $i += 2) {
            $seed1 = $seedOrder[$i] <= $participantCount ? $seedOrder[$i] : null;
            $seed2 = $seedOrder[$i + 1] <= $participantCount ? $seedOrder[$i + 1] : null;
            $round1Matches[] = ['slot' => $slot, 'seed1' => $seed1, 'seed2' => $seed2];
        }
        $rounds[] = ['bracket' => 'single', 'round_number' => 1, 'matches' => $round1Matches];

        // Every later round is pure structure: match i and i+1 in round
        // R feed slot ceil(i/2) in round R+1, no participants known yet.
        for ($round = 2; $round <= $roundCount; $round++) {
            $matchCount = $size / (2 ** $round);
            $matches = [];
            for ($slot = 1; $slot <= $matchCount; $slot++) {
                $matches[] = ['slot' => $slot, 'seed1' => null, 'seed2' => null];
            }
            $rounds[] = ['bracket' => 'single', 'round_number' => $round, 'matches' => $matches];
        }

        for ($round = 1; $round < $roundCount; $round++) {
            $matchCountThisRound = $size / (2 ** $round);
            for ($slot = 1; $slot <= $matchCountThisRound; $slot++) {
                $advances[] = [
                    'from' => ['bracket' => 'single', 'round_number' => $round, 'slot' => $slot],
                    'to' => ['bracket' => 'single', 'round_number' => $round + 1, 'slot' => (int) ceil($slot / 2), 'player' => $slot % 2 === 1 ? 1 : 2],
                    'on' => 'winner',
                ];
            }
        }

        return ['size' => $size, 'rounds' => $rounds, 'advances' => $advances];
    }

    /**
     * Double elimination: an identical winners bracket to
     * buildSingleElimination() (a loss there drops to the losers
     * bracket instead of eliminating outright), a losers bracket built
     * from the standard "minor/major round" pattern (see this class's
     * own docblock for the shape), and a two-match grand final slot
     * (grand_final round 1 is always played; round 2 -- the "bracket
     * reset" -- only actually gets a game if the losers-bracket
     * finalist wins round 1, since the winners-bracket finalist should
     * lose only once across the whole event to be eliminated -- see
     * TournamentService's own handling of round 2 there).
     *
     * Non-power-of-two fields need byes in the LOSERS bracket too, not
     * just the winners bracket -- a winners-bracket bye produces no
     * loser to drop down at all, so whichever losers-bracket slot would
     * have received that "phantom" loser needs to skip waiting for it.
     * This is handled by computing, structurally (from $participantCount
     * alone, independent of any actual game result), how many real
     * entrants (0, 1, or 2) arrive at every losers-bracket slot:
     *
     * - A winners-bracket round-1 match contributes a loser only if it's
     *   REAL (both seeds present); a bye contributes nothing. Every
     *   winners-bracket round beyond round 1 is always real (a bye's own
     *   winner is still one real advancing player, so round 2 onward
     *   always receives two real inputs -- see buildSingleElimination()'s
     *   own docblock), so it always contributes a loser.
     * - A losers "minor" round slot sums two contributing sources (each
     *   0 or 1); a losers "major" round slot sums one contributing
     *   losers-bracket source (0 or 1) plus one GUARANTEED winners-bracket
     *   loser (always 1) -- so a major-round slot is never 0.
     *
     * A slot totaling 0 is skipped entirely (no row, no outgoing edge --
     * downstream slots simply receive one fewer inbound edge, which is
     * exactly how TournamentService::advanceInto() tells a bye slot
     * (still short one participant once its only edge fires, permanently)
     * apart from a real one (two edges, both must fire). A slot totaling
     * 1 still gets a row -- a genuine bye once its lone participant
     * actually arrives -- but only one inbound edge; a slot totaling 2
     * is an ordinary real match with two inbound edges (or, for winners
     * round 1 only, two real seeds assigned directly).
     */
    public function buildDoubleElimination(int $participantCount): array
    {
        if ($participantCount < 4) {
            throw new TournamentStateException('A double-elimination bracket needs at least 4 participants');
        }

        $winners = $this->buildSingleElimination($participantCount);
        $size = $winners['size'];
        $winnerBracketRounds = (int) log($size, 2);

        $rounds = $winners['rounds'];
        $advances = [];

        // Re-derive the winners-bracket advances (winner-only, same
        // shape as single elimination) -- losers are wired separately
        // below.
        foreach ($winners['advances'] as $advance) {
            $advances[] = $advance;
        }

        // Winners-bracket round 1's own entrant count per slot (2 = real,
        // produces a loser; 1 = bye, produces none) -- read straight off
        // the seeds buildSingleElimination() already assigned.
        $wbRound1EntrantCount = [];
        foreach ($winners['rounds'][0]['matches'] as $match) {
            $wbRound1EntrantCount[$match['slot']] = ($match['seed1'] !== null ? 1 : 0) + ($match['seed2'] !== null ? 1 : 0);
        }

        $losersRoundCount = 2 * $winnerBracketRounds - 2;

        // Forward pass: derive every losers-bracket slot's own entrant
        // count from earlier rounds' counts (and, for major rounds, the
        // always-real winners-bracket loser feeding in) before creating
        // a single row -- see this method's own docblock for the exact
        // recursion.
        $lbEntrantCounts = [];
        for ($lbRound = 1; $lbRound <= $losersRoundCount; $lbRound++) {
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
                        $prevMajor = $lbEntrantCounts[$lbRound - 1];
                        $a = ($prevMajor[2 * $slot - 1] ?? 0) >= 1 ? 1 : 0;
                        $b = ($prevMajor[2 * $slot] ?? 0) >= 1 ? 1 : 0;
                    }
                    $counts[$slot] = $a + $b;
                } else {
                    $prevMinor = $lbEntrantCounts[$lbRound - 1];
                    $fromLosers = ($prevMinor[$slot] ?? 0) >= 1 ? 1 : 0;
                    $counts[$slot] = $fromLosers + 1; // + the guaranteed winners-bracket loser
                }
            }
            $lbEntrantCounts[$lbRound] = $counts;
        }

        $loserRounds = [];
        for ($lbRound = 1; $lbRound <= $losersRoundCount; $lbRound++) {
            $matches = [];
            foreach ($lbEntrantCounts[$lbRound] as $slot => $count) {
                if ($count === 0) {
                    continue; // no real entrant will ever reach this slot -- no row at all
                }
                $matches[] = ['slot' => $slot, 'seed1' => null, 'seed2' => null];
            }
            $loserRounds[] = ['bracket' => 'losers', 'round_number' => $lbRound, 'matches' => $matches];
        }
        $rounds = [...$rounds, ...$loserRounds];

        // Winners-bracket round r's losers feed the losers bracket.
        // Round 1's losers feed LB round 1 (a "minor" round pairing
        // them against each other) -- but only from a REAL round-1
        // match; a bye produces no loser to send at all. Every later WB
        // round r's losers feed the matching "major" LB round instead
        // (see the class docblock's j/r correspondence), always real.
        for ($wbRound = 1; $wbRound <= $winnerBracketRounds; $wbRound++) {
            $wbMatchCount = $size / (2 ** $wbRound);

            if ($wbRound === 1) {
                for ($slot = 1; $slot <= $wbMatchCount; $slot++) {
                    if ($wbRound1EntrantCount[$slot] < 2) {
                        continue; // a bye -- no loser produced
                    }
                    $advances[] = [
                        'from' => ['bracket' => 'single', 'round_number' => 1, 'slot' => $slot],
                        'to' => ['bracket' => 'losers', 'round_number' => 1, 'slot' => (int) ceil($slot / 2), 'player' => $slot % 2 === 1 ? 1 : 2],
                        'on' => 'loser',
                    ];
                }

                continue;
            }

            // WB round r (r >= 2)'s losers feed LB "major" round 2*(r-1),
            // one-to-one against that round's incoming minor-round winners.
            $lbMajorRound = 2 * ($wbRound - 1);
            for ($slot = 1; $slot <= $wbMatchCount; $slot++) {
                $advances[] = [
                    'from' => ['bracket' => 'single', 'round_number' => $wbRound, 'slot' => $slot],
                    'to' => ['bracket' => 'losers', 'round_number' => $lbMajorRound, 'slot' => $slot, 'player' => 2],
                    'on' => 'loser',
                ];
            }
        }

        // Losers-bracket internal advancement: a minor round's winner
        // feeds the very next (major) round's player 1 slot one-to-one;
        // a major round's winner feeds the next minor round (halving)
        // exactly like single elimination's own winner-only wiring.
        // Skipped for any slot with a 0 entrant count above -- it has no
        // row (and so nothing to advance a winner FROM) at all.
        for ($lbRound = 1; $lbRound < $losersRoundCount; $lbRound++) {
            $isMinor = $lbRound % 2 === 1;

            foreach ($lbEntrantCounts[$lbRound] as $slot => $count) {
                if ($count === 0) {
                    continue;
                }
                $advances[] = [
                    'from' => ['bracket' => 'losers', 'round_number' => $lbRound, 'slot' => $slot],
                    'to' => $isMinor
                        ? ['bracket' => 'losers', 'round_number' => $lbRound + 1, 'slot' => $slot, 'player' => 1]
                        : ['bracket' => 'losers', 'round_number' => $lbRound + 1, 'slot' => (int) ceil($slot / 2), 'player' => $slot % 2 === 1 ? 1 : 2],
                    'on' => 'winner',
                ];
            }
        }

        // Grand final: winners-bracket champion vs losers-bracket
        // champion. Round 2 is the bracket-reset match, only ever
        // played out if round 1's loser was the winners-bracket
        // champion (their first and only loss) -- see TournamentService.
        $rounds[] = ['bracket' => 'grand_final', 'round_number' => 1, 'matches' => [['slot' => 1, 'seed1' => null, 'seed2' => null]]];
        $rounds[] = ['bracket' => 'grand_final', 'round_number' => 2, 'matches' => [['slot' => 1, 'seed1' => null, 'seed2' => null]]];

        $advances[] = [
            'from' => ['bracket' => 'single', 'round_number' => $winnerBracketRounds, 'slot' => 1],
            'to' => ['bracket' => 'grand_final', 'round_number' => 1, 'slot' => 1, 'player' => 1],
            'on' => 'winner',
        ];
        $advances[] = [
            'from' => ['bracket' => 'losers', 'round_number' => $losersRoundCount, 'slot' => 1],
            'to' => ['bracket' => 'grand_final', 'round_number' => 1, 'slot' => 1, 'player' => 2],
            'on' => 'winner',
        ];

        return ['size' => $size, 'rounds' => $rounds, 'advances' => $advances];
    }

    /**
     * One Swiss round's pairings, given each active participant's
     * current win count and the set of pairs already played.
     * Participants are grouped by win count (highest first) and paired
     * within/adjacent to their own group, skipping any pairing already
     * played -- a simple "greedy nearest-score, no repeat" matcher
     * rather than a fully optimal one, which is standard for a casual
     * TCG tournament tool (real Swiss software solves this as a
     * min-weight matching problem; that's overkill here).
     *
     * @param array<int, int> $wins participant id => win count so far
     * @param array<string, true> $alreadyPlayed set of "min-max" id pair
     *   keys (see pairKey()) already matched in an earlier round
     * @param array<int> $priorByes participant ids who've already had a
     *   bye this event -- skipped when choosing who sits out this time
     *   unless everyone remaining has already had one
     * @return array{pairs: list<array{0: int, 1: int}>, bye: ?int}
     */
    public function swissPairings(array $wins, array $alreadyPlayed, array $priorByes = []): array
    {
        $remaining = array_keys($wins);
        usort($remaining, static fn (int $a, int $b): int => $wins[$b] <=> $wins[$a]);

        $bye = null;
        if (count($remaining) % 2 === 1) {
            $withoutPriorBye = array_values(array_filter($remaining, static fn (int $id): bool => !in_array($id, $priorByes, true)));
            $candidates = $withoutPriorBye !== [] ? $withoutPriorBye : $remaining;
            // Lowest standing among the eligible candidates sits out --
            // the standard Swiss convention that a bye should never cost
            // a contender who might otherwise have won the event.
            $bye = $candidates[count($candidates) - 1];
            $remaining = array_values(array_filter($remaining, static fn (int $id): bool => $id !== $bye));
        }

        $pairs = [];
        $unpaired = $remaining;
        while ($unpaired !== []) {
            $a = array_shift($unpaired);
            $opponentIndex = null;
            foreach ($unpaired as $index => $b) {
                if (!isset($alreadyPlayed[self::pairKey($a, $b)])) {
                    $opponentIndex = $index;
                    break;
                }
            }
            // Every remaining candidate has already been played (small
            // field, many rounds) -- fall back to the closest-standing
            // opponent anyway rather than leaving anyone unpaired.
            if ($opponentIndex === null) {
                $opponentIndex = 0;
            }
            $b = $unpaired[$opponentIndex];
            unset($unpaired[$opponentIndex]);
            $unpaired = array_values($unpaired);
            $pairs[] = [$a, $b];
        }

        return ['pairs' => $pairs, 'bye' => $bye];
    }

    public static function pairKey(int $a, int $b): string
    {
        return $a < $b ? "{$a}:{$b}" : "{$b}:{$a}";
    }

    private static function nextPowerOfTwo(int $n): int
    {
        $size = 1;
        while ($size < $n) {
            $size *= 2;
        }

        return $size;
    }

    /**
     * Standard recursive bracket seeding (1 vs n, 2 vs n-1, ... in a
     * shape that keeps top seeds apart as long as possible) -- e.g. for
     * n=8: [1, 8, 4, 5, 2, 7, 3, 6], pairing up as (1v8, 4v5, 2v7, 3v6).
     * Doubles as bye placement for free: byes are always the WORST
     * (highest-numbered, padded-in) seeds, and since every pair here is
     * always one seed from the top half (1..n/2, always real -- see
     * buildSingleElimination()'s own docblock for why a real
     * participant count always exceeds n/2) and one from the bottom
     * half, a bye (only ever a bottom-half seed) can never be paired
     * against another bye.
     */
    private static function buildSeedOrder(int $size): array
    {
        $order = [1];
        while (count($order) < $size) {
            $n = count($order) * 2;
            $next = [];
            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $n + 1 - $seed;
            }
            $order = $next;
        }

        return $order;
    }
}
