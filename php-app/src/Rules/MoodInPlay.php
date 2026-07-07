<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * A single physical card currently in play. cardId is always the card's
 * *printed* identity (see BoardState::effectiveCardId() for why that's
 * distinct from copiedCardId), so effects that care what a card actually
 * is -- e.g. copying a Creativity copies whatever it's a copy of, not
 * Creativity itself -- resolve correctly.
 *
 * effectState is a small per-mood bag for whatever a card's own effect
 * needs to remember about the choice made when it was played (Imagination's
 * chosen color, Honor's chosen player, a one-time value bump from Dignity),
 * since those choices can't be recomputed later -- they're facts about
 * *this instance* of the card, not derivable from the rest of the board.
 */
final class MoodInPlay
{
    /** @param array<string, mixed> $effectState */
    public function __construct(
        public readonly int $cardId,
        public int $ownerId,
        public ?int $copiedCardId = null,
        public bool $isSuppressed = false,
        public ?string $suppressionExpiry = null,
        public ?int $suppressionSourceCardId = null,
        public array $effectState = [],
    ) {
    }
}
