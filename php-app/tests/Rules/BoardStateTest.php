<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use InvalidArgumentException;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use PHPUnit\Framework\TestCase;

final class BoardStateTest extends TestCase
{
    use CatalogFixture;

    private function boardState(
        array $hands = [],
        array $deck = [],
        array $discard = [],
        bool $hasSeparateDecks = false,
        array $discardOwners = [],
    ): BoardState {
        return new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            $hands,
            $deck,
            $discard,
            $hasSeparateDecks,
            $discardOwners,
        );
    }

    public function testMoveHandToInPlayAndBack(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);

        self::assertTrue($state->isInHand(1, 3));
        self::assertFalse($state->isInPlay(3));

        $state->moveHandToInPlay(1, 3);

        self::assertFalse($state->isInHand(1, 3));
        self::assertTrue($state->isInPlay(3));
        self::assertSame(1, $state->ownerOf(3));

        $state->moveInPlayToHand(3);

        self::assertTrue($state->isInHand(1, 3));
        self::assertFalse($state->isInPlay(3));
    }

    public function testMoveInPlayToDiscard(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);

        $state->moveInPlayToDiscard(3);

        self::assertFalse($state->isInPlay(3));
        self::assertSame([3], $state->discardPile());
    }

    public function testMoveInPlayToBottomOfDeck(): void
    {
        $state = $this->boardState(hands: [1 => [3]], deck: [99]);
        $state->moveHandToInPlay(1, 3);

        $state->moveInPlayToBottomOfDeck(3);

        self::assertFalse($state->isInPlay(3));
        self::assertSame([99, 3], $state->deck());
    }

    public function testMoveHandToDiscardAndBottomOfDeck(): void
    {
        $state = $this->boardState(hands: [1 => [3, 7]], deck: [99]);

        $state->moveHandToDiscard(1, 3);
        self::assertSame([3], $state->discardPile());
        self::assertFalse($state->isInHand(1, 3));

        $state->moveHandToBottomOfDeck(1, 7);
        self::assertSame([99, 7], $state->deck());
        self::assertFalse($state->isInHand(1, 7));
    }

    public function testMoveDiscardToHand(): void
    {
        $state = $this->boardState(discard: [3]);

        $state->moveDiscardToHand(2, 3);

        self::assertTrue($state->isInHand(2, 3));
        self::assertSame([], $state->discardPile());
    }

    public function testGiveInPlayToPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);

        $state->giveInPlayToPlayer(3, 2);

        self::assertSame(2, $state->ownerOf(3));
    }

    public function testDrawCardMovesTopOfDeckToHand(): void
    {
        $state = $this->boardState(deck: [10, 20, 30]);

        $drawn = $state->drawCard(1);

        self::assertSame(10, $drawn);
        self::assertSame([20, 30], $state->deck());
        self::assertTrue($state->isInHand(1, 10));
    }

    public function testDrawCardFromEmptyDeckReturnsNull(): void
    {
        $state = $this->boardState();

        self::assertNull($state->drawCard(1));
    }

    public function testMoveInPlayToDiscardRecordsCardMove(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);

        $state->moveInPlayToDiscard(3);

        self::assertSame(
            [['card_id' => 3, 'from_zone' => 'play', 'to_zone' => 'discard', 'from_player_id' => null, 'to_player_id' => null]],
            $state->consumeCardMoves(),
        );
    }

    public function testMoveInPlayToHandRecordsOwnerAsToPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);

        $state->moveInPlayToHand(3);

        self::assertSame(
            [['card_id' => 3, 'from_zone' => 'play', 'to_zone' => 'hand', 'from_player_id' => null, 'to_player_id' => 1]],
            $state->consumeCardMoves(),
        );
    }

    public function testMoveInPlayToPlayersHandRecordsNewOwnerAsToPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);

        $state->moveInPlayToPlayersHand(3, 2);

        self::assertSame(
            [['card_id' => 3, 'from_zone' => 'play', 'to_zone' => 'hand', 'from_player_id' => null, 'to_player_id' => 2]],
            $state->consumeCardMoves(),
        );
    }

    public function testMoveHandToDiscardRecordsFromPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);

        $state->moveHandToDiscard(1, 3);

        self::assertSame(
            [['card_id' => 3, 'from_zone' => 'hand', 'to_zone' => 'discard', 'from_player_id' => 1, 'to_player_id' => null]],
            $state->consumeCardMoves(),
        );
    }

    public function testGiveHandCardToPlayerRecordsBothPlayers(): void
    {
        $state = $this->boardState(hands: [1 => [3], 2 => []]);

        $state->giveHandCardToPlayer(1, 2, 3);

        self::assertSame(
            [['card_id' => 3, 'from_zone' => 'hand', 'to_zone' => 'hand', 'from_player_id' => 1, 'to_player_id' => 2]],
            $state->consumeCardMoves(),
        );
    }

    public function testMoveHandToInPlayDoesNotRecordACardMove(): void
    {
        // The card actually being played -- already implicit in whichever
        // mood_played/pending_decision_created event GameService logs for
        // this play, via that event's own card_id, so recording it again
        // here would just repeat the same fact on every single play.
        $state = $this->boardState(hands: [1 => [3]]);

        $state->moveHandToInPlay(1, 3);

        self::assertSame([], $state->consumeCardMoves());
    }

    public function testDrawCardDoesNotRecordACardMove(): void
    {
        // Unlike every other zone a card can move through here, a hand a
        // card is drawn into was never previously public -- recording it
        // would leak which card a player drew to every other player's game
        // history.
        $state = $this->boardState(deck: [10]);

        $state->drawCard(1);

        self::assertSame([], $state->consumeCardMoves());
    }

    public function testConsumeCardMovesClearsAccumulatedMoves(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveInPlayToDiscard(3);

        self::assertNotSame([], $state->consumeCardMoves());
        self::assertSame([], $state->consumeCardMoves());
    }

    public function testMoveHandToInPlayTagsPlayedFromZoneAsHand(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);

        $state->moveHandToInPlay(1, 3);

        self::assertSame('hand', $state->effectState(3, 'playedFromZone'));
    }

    public function testMoveDiscardToInPlayTagsPlayedFromZoneAsDiscard(): void
    {
        $state = $this->boardState(discard: [3]);

        $state->moveDiscardToInPlay(1, 3);

        self::assertSame('discard', $state->effectState(3, 'playedFromZone'));
    }

    public function testGiveInPlayToPlayerRecordsAnOwnershipChange(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);

        $state->giveInPlayToPlayer(3, 2);

        self::assertSame(
            [['card_id' => 3, 'from_player_id' => 1, 'to_player_id' => 2]],
            $state->consumeOwnershipChanges(),
        );
    }

    public function testConsumeOwnershipChangesClearsAccumulatedChanges(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);
        $state->giveInPlayToPlayer(3, 2);

        self::assertNotSame([], $state->consumeOwnershipChanges());
        self::assertSame([], $state->consumeOwnershipChanges());
    }

    public function testMovingUnknownCardOutOfHandThrows(): void
    {
        $state = $this->boardState(hands: [1 => []]);

        $this->expectException(InvalidArgumentException::class);
        $state->moveHandToInPlay(1, 999);
    }

    public function testSuppressionZeroesValueRegardlessOfUnderlyingValue(): void
    {
        $state = $this->boardState(hands: [1 => [56]]); // Betrayal, flat base value 6
        $state->moveHandToInPlay(1, 56);

        self::assertSame(6, $state->valueOf(56));

        $state->suppress(56, 'end_of_round');
        self::assertTrue($state->isSuppressed(56));
        self::assertSame(0, $state->valueOf(56));

        $state->clearEndOfRoundSuppressions();
        self::assertFalse($state->isSuppressed(56));
        self::assertSame(6, $state->valueOf(56));
    }

    public function testClearSuppressionsFromSource(): void
    {
        $state = $this->boardState(hands: [1 => [56]]);
        $state->moveHandToInPlay(1, 56);
        $state->suppress(56, 'while_source_in_play', sourceCardId: 42);

        $state->clearSuppressionsFrom(42);

        self::assertFalse($state->isSuppressed(56));
    }

    public function testColorOfWithoutImaginationReturnsCatalogColor(): void
    {
        $state = $this->boardState(hands: [1 => [56]]);
        $state->moveHandToInPlay(1, 56);

        self::assertSame('black', $state->colorOf(56));
    }

    public function testColorOfWithImaginationInPlayReturnsChosenColor(): void
    {
        $state = $this->boardState(hands: [1 => [42, 56]]);
        $state->moveHandToInPlay(1, 42); // Imagination
        $state->setEffectState(42, 'color', 'green');
        $state->moveHandToInPlay(1, 56); // Betrayal, printed black

        self::assertSame('green', $state->colorOf(56));
        self::assertSame('green', $state->colorOf(42));
    }

    public function testValueOverrideTakesPrecedenceOverBaseValue(): void
    {
        $state = $this->boardState(hands: [1 => [8]]);
        $state->moveHandToInPlay(1, 8); // Dignity, base 3

        self::assertSame(3, $state->valueOf(8));

        $state->setValueOverride(8, 5);

        self::assertSame(5, $state->valueOf(8));
    }

    public function testEffectiveCardIdFollowsCopiedCard(): void
    {
        $state = $this->boardState(hands: [1 => [32]]);
        $state->moveHandToInPlay(1, 32, copiedCardId: 3); // Creativity copying Charity

        self::assertSame(3, $state->effectiveCardId(32));
        self::assertSame(1, $state->valueOf(32)); // Charity's base value
    }

    public function testTurnAndPlaysRemaining(): void
    {
        $state = $this->boardState();

        $state->startTurn(1);
        self::assertSame(1, $state->currentPlayerId());
        self::assertSame(1, $state->playsRemaining());

        $state->grantExtraPlay();
        self::assertSame(2, $state->playsRemaining());

        $state->consumePlay();
        self::assertSame(1, $state->playsRemaining());

        $state->consumePlay();
        $state->consumePlay(); // should not go negative
        self::assertSame(0, $state->playsRemaining());
    }

    public function testStartTurnWithHurtFeelingsGrantsTwoPlays(): void
    {
        $state = $this->boardState();

        $state->startTurn(1, hasHurtFeelings: true);

        self::assertSame(2, $state->playsRemaining());
    }

    public function testDrawCardRecordsTheDrawingPlayerNotTheCard(): void
    {
        // Unlike every other zone move, drawing is only ever recorded as
        // "who drew", never "what" -- see $pendingDraws' own docblock.
        $state = $this->boardState(deck: [10]);

        $state->drawCard(1);

        self::assertSame([1], $state->consumeDraws());
    }

    public function testDrawCardFromAnEmptyDeckDoesNotRecordADraw(): void
    {
        $state = $this->boardState();

        $state->drawCard(1);

        self::assertSame([], $state->consumeDraws());
    }

    public function testConsumeDrawsClearsAccumulatedDraws(): void
    {
        $state = $this->boardState(deck: [10, 20]);

        $state->drawCard(1);
        $state->drawCard(2);

        self::assertSame([1, 2], $state->consumeDraws());
        self::assertSame([], $state->consumeDraws());
    }

    public function testGrantExtraPlayRecordsEachGrantedUnit(): void
    {
        $state = $this->boardState();

        $state->grantExtraPlay(2, ['type' => 'shares_color_with_your_moods'], sourceCardId: 3);

        self::assertSame(
            [
                ['type' => 'shares_color_with_your_moods', 'sourceCardId' => 3],
                ['type' => 'shares_color_with_your_moods', 'sourceCardId' => 3],
            ],
            $state->consumeGrantsCreated(),
        );
    }

    public function testConsumeGrantsCreatedClearsAccumulatedGrants(): void
    {
        $state = $this->boardState();

        $state->grantExtraPlay(sourceCardId: 3);

        self::assertNotSame([], $state->consumeGrantsCreated());
        self::assertSame([], $state->consumeGrantsCreated());
    }

    public function testUseGrantForRecordsAConsumedRestrictedGrant(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->startTurn(1);
        $state->grantExtraPlay(sourceCardId: 106); // an extra, unconditional grant on top of the base turn

        $state->useGrantFor(3, 1); // consumes the base allowance first (null restriction)

        self::assertNull($state->consumeGrantUsed());

        $state->useGrantFor(3, 1); // consumes the granted extra play

        self::assertSame(['sourceCardId' => 106], $state->consumeGrantUsed());
    }

    public function testConsumeGrantUsedClearsAfterReading(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->startTurn(1);
        $state->grantExtraPlay(sourceCardId: 106);
        $state->useGrantFor(3, 1); // base allowance
        $state->useGrantFor(3, 1); // the granted extra play

        self::assertNotNull($state->consumeGrantUsed());
        self::assertNull($state->consumeGrantUsed());
    }

    public function testDeckWithNoPlayerIdReturnsTheSharedDeckWhenDecksAreNotSeparate(): void
    {
        $state = $this->boardState(deck: [10, 20, 30]);

        self::assertSame([10, 20, 30], $state->deck());
        self::assertSame([10, 20, 30], $state->deck(1)); // any player resolves to the same shared pool
        self::assertSame([10, 20, 30], $state->deck(2));
    }

    public function testDeckWithNoPlayerIdThrowsWhenDecksAreSeparate(): void
    {
        $state = $this->boardState(deck: [1 => [10], 2 => [20]], hasSeparateDecks: true);

        $this->expectException(InvalidArgumentException::class);
        $state->deck();
    }

    public function testDrawCardPullsFromThatPlayersOwnDeckWhenDecksAreSeparate(): void
    {
        $state = $this->boardState(deck: [1 => [10], 2 => [20]], hasSeparateDecks: true);

        self::assertSame(10, $state->drawCard(1));
        self::assertSame(20, $state->drawCard(2));
        self::assertTrue($state->isInHand(1, 10));
        self::assertTrue($state->isInHand(2, 20));
        self::assertFalse($state->isInHand(1, 20));
        self::assertFalse($state->isInHand(2, 10));
    }

    public function testDrawCardFromAnEmptyOwnDeckReturnsNullEvenWhenAnotherPlayersDeckHasCards(): void
    {
        $state = $this->boardState(deck: [1 => [], 2 => [20]], hasSeparateDecks: true);

        self::assertNull($state->drawCard(1));
        self::assertFalse($state->isInHand(1, 20));
    }

    public function testMoveHandToBottomOfDeckBottomsIntoThatPlayersOwnDeckWhenSeparate(): void
    {
        $state = $this->boardState(hands: [1 => [3]], deck: [1 => [], 2 => []], hasSeparateDecks: true);

        $state->moveHandToBottomOfDeck(1, 3);

        self::assertSame([3], $state->deck(1));
        self::assertSame([], $state->deck(2));
    }

    public function testMoveInPlayToBottomOfDeckBottomsIntoTheMoodsOwnersDeckWhenSeparate(): void
    {
        $state = $this->boardState(hands: [2 => [3]], deck: [1 => [], 2 => []], hasSeparateDecks: true);
        $state->moveHandToInPlay(2, 3);

        $state->moveInPlayToBottomOfDeck(3);

        self::assertSame([3], $state->deck(2));
        self::assertSame([], $state->deck(1));
    }

    /**
     * Per the ruling this codebase follows: the discard pile itself stays
     * one shared pool even in a 'duel' game, but a card leaving it for the
     * bottom of a deck still goes to its own last owner's deck -- never
     * whichever player happened to be playing Corruption/Altruism.
     */
    public function testMoveDiscardToBottomOfDeckBottomsIntoTheDiscardedCardsOwnersDeckNotTheActingPlayers(): void
    {
        $state = $this->boardState(hands: [2 => [3]], deck: [1 => [], 2 => []], hasSeparateDecks: true);
        $state->moveHandToDiscard(2, 3);

        $state->moveDiscardToBottomOfDeck(3); // no acting-player parameter exists at all

        self::assertSame([3], $state->deck(2));
        self::assertSame([], $state->deck(1));
    }

    public function testDiscardPileStaysASingleSharedPoolEvenWhenDecksAreSeparate(): void
    {
        $state = $this->boardState(hands: [1 => [3], 2 => [9]], hasSeparateDecks: true);

        $state->moveHandToDiscard(1, 3);
        $state->moveHandToDiscard(2, 9);

        self::assertSame([3, 9], $state->discardPile());
    }

    public function testDiscardOwnerOfTracksAndClearsOnceTheCardLeavesTheDiscardPile(): void
    {
        $state = $this->boardState(hands: [1 => [3]], hasSeparateDecks: true);
        $state->moveHandToDiscard(1, 3);

        self::assertSame(1, $state->discardOwnerOf(3));

        $state->moveDiscardToHand(1, 3);

        self::assertNull($state->discardOwnerOf(3));
    }

    public function testDiscardOwnerOfReflectsTheMoodsOwnerWhenDiscardedFromPlay(): void
    {
        $state = $this->boardState(hands: [2 => [3]], hasSeparateDecks: true);
        $state->moveHandToInPlay(2, 3);
        $state->giveInPlayToPlayer(3, 1); // now owned by 1, not 2

        $state->moveInPlayToDiscard(3);

        self::assertSame(1, $state->discardOwnerOf(3));
    }

    public function testHasSeparateDecksAndDecksAccessorsReflectASeparateGame(): void
    {
        $state = $this->boardState(deck: [1 => [10], 2 => [20]], hasSeparateDecks: true);

        self::assertTrue($state->hasSeparateDecks());
        self::assertSame([1 => [10], 2 => [20]], $state->decks());
    }

    public function testHasSeparateDecksAndDecksAccessorsReflectASharedGame(): void
    {
        $state = $this->boardState(deck: [10, 20]);

        self::assertFalse($state->hasSeparateDecks());
        self::assertSame([BoardState::SHARED_DECK_KEY => [10, 20]], $state->decks());
    }

    public function testCatalogRowThrowsForUnknownCard(): void
    {
        $state = $this->boardState();

        $this->expectException(InvalidArgumentException::class);
        $state->catalogRow(999999);
    }
}
