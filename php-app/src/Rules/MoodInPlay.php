<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * A single physical card currently in play. cardId and copiedCardId are
 * both per-game *instance* ids (game_cards.id once loaded from a real
 * game), not catalog ids -- a 'duel' game gives each player their own
 * complete deck, so the same printed card can exist twice in one game, and
 * only an instance id can tell two such cards apart. cardId identifies
 * *this* physical card; copiedCardId (Creativity's "play as a copy of a
 * mood currently in play") identifies whichever *other* physical card this
 * one is currently acting as, so effects that care what a card actually is
 * -- e.g. copying a Creativity copies whatever it's a copy of, not
 * Creativity itself -- resolve correctly. Both are only ever translated
 * back to a catalog id (for name/color/value/rules text) via
 * BoardState::catalogRow()/effectiveCardId().
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
