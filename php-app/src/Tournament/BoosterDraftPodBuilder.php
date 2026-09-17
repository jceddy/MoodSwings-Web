<?php

declare(strict_types=1);

namespace MoodSwings\Tournament;

/**
 * Pure pod-partitioning math for Booster Draft -- no database access. A
 * pod is a group of up to 8 participants who draft together (see
 * BoosterPackBuilder/TournamentPodRepository); a field larger than 8
 * splits into multiple pods, sized as evenly as possible (differing by
 * at most 1) rather than filling each pod to 8 before starting a new
 * one, so nobody drafts in a near-empty final pod. TournamentService is
 * the one that turns these bare sizes into real tournament_pods/
 * tournament_pod_participants rows, assigning a random seat_order within
 * each pod -- this class only ever returns how many participants go in
 * each pod, in no particular order.
 */
final class BoosterDraftPodBuilder
{
    private const MAX_POD_SIZE = 8;

    /** @return int[] each pod's own participant count, summing to $participantCount, every value 2-8 */
    public function podSizes(int $participantCount): array
    {
        if ($participantCount < 2) {
            throw new TournamentStateException('A Booster Draft pod needs at least 2 participants');
        }

        $podCount = (int) ceil($participantCount / self::MAX_POD_SIZE);
        $baseSize = intdiv($participantCount, $podCount);
        $remainder = $participantCount % $podCount;

        // The first $remainder pods get one extra participant each, so
        // sizes differ by at most 1 -- e.g. 9 participants -> [5, 4], not
        // [8, 1].
        $sizes = [];
        for ($i = 0; $i < $podCount; $i++) {
            $sizes[] = $baseSize + ($i < $remainder ? 1 : 0);
        }

        return $sizes;
    }

    /**
     * Booster passing's own circulation math: a booster opened by
     * $openerSeat moves exactly one seat every round, 'left' (+1) or
     * 'right' (-1) -- so at $round (1-indexed; round 1 is still at its
     * own opener), it's held by seat ($openerSeat + ($round - 1)) for
     * 'left', or ($openerSeat - ($round - 1)) for 'right', both wrapped
     * into 0..$podSize-1. Every seat ends up holding each of the
     * $podSize boosters moving in a given direction for exactly one
     * round over the course of its own 15-round lifetime (15 being the
     * booster's own card count -- see BoosterPackBuilder), so after all
     * 15 rounds every player has picked exactly 15 cards from that
     * direction's collective boosters, 30 total across both directions,
     * regardless of $podSize.
     */
    public static function seatHoldingBooster(int $openerSeat, string $direction, int $round, int $podSize): int
    {
        $shift = $round - 1;
        $seat = $direction === 'left' ? $openerSeat + $shift : $openerSeat - $shift;

        return (($seat % $podSize) + $podSize) % $podSize;
    }

    /** The exact inverse of seatHoldingBooster(): which opener's own booster (for $direction) $holderSeat currently holds at $round. */
    public static function openerSeatHeldBy(int $holderSeat, string $direction, int $round, int $podSize): int
    {
        $shift = $round - 1;
        $seat = $direction === 'left' ? $holderSeat - $shift : $holderSeat + $shift;

        return (($seat % $podSize) + $podSize) % $podSize;
    }
}
