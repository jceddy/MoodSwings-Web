<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * A card's gameplay behavior, dispatched by cards.effect_key. The three
 * methods correspond directly to the three ability timings from the
 * Extended Rules ("To play this card", "While in play", "After playing
 * this mood"); a card only needs to override the ones its
 * has_*_ability flags say it has -- see AbstractMoodEffect for the
 * no-op/default-value defaults every other method falls back to.
 *
 * reactToAnotherPlay() is a fourth, narrower hook for the small handful
 * of cards whose "while in play" ability is actually "each time you play
 * another mood, ..." (Scorn, Validation) -- see MoodPlayService.
 */
interface MoodEffect
{
    /**
     * "To play this card" -- can the cost be paid at all? Checked before
     * the mood is moved into play; if false, the play is illegal.
     */
    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool;

    /**
     * Pays the "to play this card" cost. Called after canPayToPlayCost()
     * confirms it's payable, before the mood is moved into play.
     */
    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void;

    /**
     * "While in play" -- this mood's current score value. Only consulted
     * when the card has no one-time value override stored in its
     * effectState (see BoardState::setValueOverride()); called fresh every
     * time a value is needed, never cached, so it naturally reflects
     * whatever else is currently in play.
     */
    public function computeValue(BoardState $state, int $cardId): int;

    /**
     * "After playing this mood" -- resolved once, immediately after the
     * mood enters play (and after "while in play" effects have been
     * applied to the board, per the Extended Rules' resolution order).
     */
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void;

    /**
     * Called on every one of $playerId's *other* moods that were already
     * in play at the moment $playerId played $playedCardId -- judged as
     * of right BEFORE $playedCardId's own afterPlaying()/resolveDecisions()
     * runs, not after (see MoodPlayService::resolveAfterPlayingChain()'s
     * own docblock and PlayResult's $reactorCandidateCardIds): a card
     * whose own effect returns or discards one of the player's other
     * in-play moods as a side effect (Thrill is the clearest example)
     * must not rob that mood of the chance to react to the very play
     * that displaced it. $reactorCardId is the reacting mood (this
     * effect's own card), distinct from $playedCardId, and may itself no
     * longer be in play by the time this actually runs. Reads its
     * optional choices from the same PlayerChoices submitted for the
     * triggering play, since the reaction is the same player's own
     * decision, made in the same request.
     */
    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void;
}
