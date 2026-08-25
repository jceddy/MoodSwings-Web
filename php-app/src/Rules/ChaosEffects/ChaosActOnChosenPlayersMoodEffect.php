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
 * one of their moods [matching some filter]." The rules text never lets
 * the chosen player pick WHICH of their own qualifying moods is affected
 * (unlike a card that names a specific target mood), so -- mirroring
 * Effects/CrueltyEffect.php's own "a random one of their moods" reading
 * for the same "no real choice offered" shape -- this picks uniformly at
 * random among each chosen player's own qualifying moods, matching the
 * "an opponent's own decision becomes randomized" convention this whole
 * effect pool follows (see ChaosMoodEffect's own docblock).
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
        $chosenPlayerIds = array_unique($choices->ints('target_player_ids'));
        if (count($chosenPlayerIds) > $this->maxPlayers) {
            throw new InvalidChoiceException('Too many players chosen');
        }

        foreach ($chosenPlayerIds as $targetPlayerId) {
            if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
                throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
            }
        }

        foreach ($chosenPlayerIds as $targetPlayerId) {
            $candidates = [];
            foreach ($state->moodsOwnedBy($targetPlayerId) as $mood) {
                if ($this->excludeThisCard && $mood->cardId === $cardId) {
                    continue;
                }
                if ($this->qualifies !== null && !($this->qualifies)($state, $mood->cardId)) {
                    continue;
                }
                $candidates[$mood->cardId] = true;
            }

            if ($candidates === []) {
                continue;
            }

            $targetCardId = array_rand($candidates);
            match ($this->action) {
                'discard' => $state->moveInPlayToDiscard($targetCardId),
                'suppress' => $state->suppress($targetCardId, 'while_source_in_play', $cardId),
                'hand' => $state->moveInPlayToPlayersHand($targetCardId, $targetPlayerId),
            };
        }
    }
}
