<?php

declare(strict_types=1);

namespace MoodSwings\Bot;

use MoodSwings\Rules\BoardState;

/**
 * Turns a live BoardState into a fair, non-omniscient sample of "what the
 * hidden parts of this game might actually look like" from one player's
 * point of view -- the standard "determinization" step search-based AI
 * needs for a hidden-information game (the same idea bridge/hearts/skat
 * engines use): rather than searching the one true (but partially secret)
 * game tree, sample many plausible *fully-visible* worlds consistent with
 * what's actually public, and search each of those instead.
 *
 * What's hidden from $viewerPlayerId, and gets reshuffled here: every
 * OTHER player's hand, AND every deck's still-undrawn cards -- including
 * $viewerPlayerId's own deck. A card game's shuffled deck is face-down to
 * its own owner too: nobody knows their own future draws any more than
 * they know an opponent's hand, so the viewer's own deck is exactly as
 * "hidden" here as anyone else's hand. What's NOT touched: $viewerPlayerId's
 * own hand (the one thing they genuinely do know), the discard pile, and
 * everything currently in play -- both already fully public to every
 * player regardless of format.
 *
 * The resample only ever preserves each hidden zone's own SIZE (hand
 * count, deck count), never which specific cards end up where -- pooling
 * every hidden card together and reshuffling is a reasonable, simple
 * uniform sample of "one possible arrangement consistent with what's
 * publicly known" (hand sizes, deck sizes, and which cards have already
 * been publicly revealed by ending up in play/discard). It does NOT model
 * subtler inference (e.g. "this player didn't discard the exact card an
 * earlier reveal showed they once held") -- a reasonable first-pass
 * simplification, not a claim of perfect Bayesian inference.
 */
final class Determinizer
{
    /**
     * Returns a NEW BoardState (starting from a `clone` of $state, never
     * mutating $state itself) with every hidden zone reshuffled as
     * described above. Deliberately operates on an already-cloned copy at
     * the call site's own discretion is NOT assumed -- this method clones
     * internally, so callers can pass the live BoardState directly and
     * trust the original is left completely untouched either way.
     */
    public function determinize(BoardState $state, int $viewerPlayerId): BoardState
    {
        $result = clone $state;

        $hiddenCardIds = [];

        $handSizes = [];
        foreach ($result->playerOrder() as $playerId) {
            if ($playerId === $viewerPlayerId) {
                continue;
            }
            $hand = $result->hand($playerId);
            $handSizes[$playerId] = count($hand);
            array_push($hiddenCardIds, ...$hand);
        }

        $deckSizes = [];
        if ($result->hasSeparateDecks()) {
            foreach ($result->playerOrder() as $playerId) {
                $deck = $result->deck($playerId);
                $deckSizes[$playerId] = count($deck);
                array_push($hiddenCardIds, ...$deck);
            }
        } else {
            $deck = $result->deck();
            $deckSizes[BoardState::SHARED_DECK_KEY] = count($deck);
            array_push($hiddenCardIds, ...$deck);
        }

        shuffle($hiddenCardIds);

        $newHands = [$viewerPlayerId => $result->hand($viewerPlayerId)];
        $cursor = 0;
        foreach ($handSizes as $playerId => $size) {
            $newHands[$playerId] = array_slice($hiddenCardIds, $cursor, $size);
            $cursor += $size;
        }

        $newDecks = [];
        foreach ($deckSizes as $deckKey => $size) {
            $newDecks[$deckKey] = array_slice($hiddenCardIds, $cursor, $size);
            $cursor += $size;
        }

        $result->redistributeHiddenZones($newHands, $newDecks);

        return $result;
    }
}
