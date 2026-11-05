<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * "After playing this mood -- [If <condition>,] you may play an additional mood this turn [if it
 * <restriction>]." The grant is simply given when the condition holds (declining it means not
 * playing the extra mood, like Charity's). $restriction is the usual BoardState::grantExtraPlay()
 * descriptor (a printed-number list, a discard-pile source, ...); $onUseEffectState tags whichever
 * mood ends up using the grant (Fervor's "after scoring, put that mood into the discard pile").
 *
 * $condition: null (always), or one of
 *  - 'color_pair' + $colors: two or more moods (any owner) of those colors are in play
 *  - 'own_hand_fewer_than_each_other': you hold fewer cards than every other player
 */
final class ConditionalExtraPlayEffect extends AbstractMoodEffect
{
    /**
     * @param string[] $colors
     * @param ?array<string, mixed> $restriction
     * @param ?array<string, mixed> $onUseEffectState
     */
    public function __construct(
        private readonly ?string $condition = null,
        private readonly array $colors = [],
        private readonly ?array $restriction = null,
        private readonly ?array $onUseEffectState = null,
    ) {
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$this->conditionHolds($state, $playerId)) {
            return;
        }

        $restriction = $this->restriction ?? [];
        if ($this->onUseEffectState !== null) {
            $restriction['onUseEffectState'] = $this->onUseEffectState;
        }

        $state->grantExtraPlay(1, $restriction === [] ? null : $restriction, sourceCardId: $cardId);
    }

    private function conditionHolds(BoardState $state, int $playerId): bool
    {
        return match ($this->condition) {
            null => true,
            'color_pair' => CustomEffectSupport::countMoodsOfColors($state, $this->colors) >= 2,
            'own_hand_fewer_than_each_other' => CustomEffectSupport::handSizeBeatsEachOther($state, $playerId, fewer: true),
        };
    }
}
