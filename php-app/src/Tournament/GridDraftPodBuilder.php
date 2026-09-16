<?php

declare(strict_types=1);

namespace MoodSwings\Tournament;

/**
 * Pure pod-partitioning math for Grid Draft's "Pod draft (once)"
 * tournament option (issue #91 follow-up) -- no database access. A pod
 * is a group of up to 4 participants who play one ordinary Grid Draft
 * game together to build a personal pool (see TournamentService's own
 * startGridDraftPods()) -- 4 rather than BoosterDraftPodBuilder's own 8,
 * since Grid Draft's own drafting mechanic (GameService::gridDraftRounds())
 * only supports up to 4 simultaneous drafters at all (a 3x3 grid for 2-3
 * players, a 4x4 grid for exactly 4). A field larger than 4 splits into
 * multiple pods, sized as evenly as possible (differing by at most 1)
 * rather than filling each pod to 4 before starting a new one, so nobody
 * drafts in a near-empty final pod.
 */
final class GridDraftPodBuilder
{
    private const MAX_POD_SIZE = 4;

    /** @return int[] each pod's own participant count, summing to $participantCount, every value 2-4 */
    public function podSizes(int $participantCount): array
    {
        if ($participantCount < 2) {
            throw new TournamentStateException('A Grid Draft pod needs at least 2 participants');
        }

        $podCount = (int) ceil($participantCount / self::MAX_POD_SIZE);
        $baseSize = intdiv($participantCount, $podCount);
        $remainder = $participantCount % $podCount;

        // The first $remainder pods get one extra participant each, so
        // sizes differ by at most 1 -- e.g. 5 participants -> [3, 2], not
        // [4, 1].
        $sizes = [];
        for ($i = 0; $i < $podCount; $i++) {
            $sizes[] = $baseSize + ($i < $remainder ? 1 : 0);
        }

        return $sizes;
    }
}
