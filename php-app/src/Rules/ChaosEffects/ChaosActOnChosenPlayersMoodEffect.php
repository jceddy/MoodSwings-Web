<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use Closure;
use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_007/020/028/048/076/101: "After playing this mood, choose up to
 * two players. For each chosen player, [discard/suppress/return to hand]
 * one of their moods [matching some filter]." Mirrors the printed cards
 * with the exact same shape (Courage/chaos_007, Pacifism/chaos_020,
 * Anxiety/chaos_028, Panic/chaos_048, Spite/chaos_076, Shock/chaos_101)
 * -- a maintainer ruling, reversing this class's own original design: it
 * used to read a `target_player_ids` field and pick a uniformly-random
 * qualifying mood per chosen player (the same "an opponent's own
 * decision becomes randomized" reading Effects/CrueltyEffect.php's own
 * docblock uses for a genuinely different shape -- HERE, unlike Cruelty,
 * it's the ACTING player naming a target, not an opponent making their
 * own choice, so there's no real ambiguity to randomize away). Now reads
 * `target_mood_ids` directly instead -- the concrete target moods (up to
 * $maxPlayers, one per owner) rather than "players" plus a separate
 * "which of their moods" step, since that's exactly the information
 * needed to resolve the effect; see CourageEffect's own docblock for the
 * identical reasoning on the printed-card side, whose validation this
 * mirrors.
 */
final class ChaosActOnChosenPlayersMoodEffect extends AbstractChaosMoodEffect
{
    /** @param ?Closure(BoardState, int): bool $qualifies */
    public function __construct(
        private readonly string $action,
        private readonly int $maxPlayers,
        private readonly ?Closure $qualifies = null,
        private readonly bool $excludeThisCard = false,
    ) {
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = array_unique($choices->ints('target_mood_ids'));
        if (count($targets) > $this->maxPlayers) {
            throw new InvalidChoiceException('Too many moods chosen');
        }

        $affectedOwners = [];
        foreach ($targets as $targetCardId) {
            if ($this->excludeThisCard && $targetCardId === $cardId) {
                throw new InvalidChoiceException('This mood cannot target itself');
            }
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }
            if ($this->qualifies !== null && !($this->qualifies)($state, $targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} does not qualify");
            }

            $owner = $state->ownerOf($targetCardId);
            if (isset($affectedOwners[$owner])) {
                throw new InvalidChoiceException('Only one mood per chosen player is allowed');
            }
            $affectedOwners[$owner] = true;
        }

        foreach ($targets as $targetCardId) {
            match ($this->action) {
                'discard' => $state->moveInPlayToDiscard($targetCardId),
                'suppress' => $state->suppress($targetCardId, 'while_source_in_play', $cardId),
                'hand' => $state->moveInPlayToHand($targetCardId),
            };
        }
    }
}
