<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;

/** Celebration: "While in play, this mood's value is 7 if there are more colors among your moods than among each other player's moods." */
final class CelebrationEffect extends AbstractMoodEffect
{
    public function computeValue(BoardState $state, int $cardId): int
    {
        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $ownerId = $state->ownerOf($cardId);
        $ownerColors = $this->distinctColorCount($state, $ownerId);

        foreach ($state->playerOrder() as $playerId) {
            if ($playerId !== $ownerId && $this->distinctColorCount($state, $playerId) >= $ownerColors) {
                return $row['baseValue'];
            }
        }

        return $row['altValue'];
    }

    private function distinctColorCount(BoardState $state, int $playerId): int
    {
        $colors = [];
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            $colors[$state->colorOf($mood->cardId)] = true;
        }

        return count($colors);
    }
}
