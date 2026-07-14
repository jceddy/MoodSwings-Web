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

    /**
     * Regression test: Shock's own "value of 3 or less" choice_fields
     * candidate list used to look stale for a target like Ambivalence
     * whose paired-color-threshold value only drops once the played card
     * (Shock itself, red) tips the count -- see
     * BoardState::valueOfAsIfAlsoInPlay()'s own docblock.
     */
    public function testValueOfAsIfAlsoInPlayReflectsThePairedColorThresholdTheHypotheticalCardWouldTip(): void
    {
        $state = $this->boardState(hands: [1 => [101], 2 => [27, 125]]); // Shock (red), Ambivalence (blue), Joy (green)
        $state->moveHandToInPlay(2, 125); // Joy in play -- 1 red/green mood so far
        $state->moveHandToInPlay(2, 27); // Ambivalence in play -- threshold not met yet

        self::assertSame(6, $state->valueOf(27)); // base value, only 1 red/green mood (Joy) in play

        // As if Shock (still in hand, red) were also in play: 2 red/green moods now -- threshold met.
        self::assertSame(3, $state->valueOfAsIfAlsoInPlay(27, 101, 1));

        // No lasting side effects: the real board is untouched.
        self::assertSame(6, $state->valueOf(27));
        self::assertFalse($state->isInPlay(101));
        self::assertTrue($state->isInHand(1, 101));
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

    /**
     * Hope's (and Grace's) own grant -- unlike an ordinary one -- is lost
     * outright if the specific Hope that created it leaves play before a
     * player gets around to using it, not merely left un-attributed to a
     * name. See BoardState::grantIsActive()'s own docblock for why
     * Stubbornness's grant is deliberately exempt from this (see the
     * companion test below).
     */
    public function testHopeSourcedGrantIsLostIfHopeLeavesPlayBeforeItsUsed(): void
    {
        $state = $this->boardState(hands: [1 => [124, 3]]); // Hope, a plain hand card
        $state->startTurn(1);
        $state->moveHandToInPlay(1, 124); // Hope enters play
        $state->grantExtraPlay(1, ['requiresSourceInPlay' => true], sourceCardId: 124); // Hope's own same-turn bonus

        self::assertSame(2, $state->playsRemaining()); // base turn + Hope's bonus

        $state->moveInPlayToDiscard(124); // some effect removes Hope from play before the bonus is used

        self::assertSame(1, $state->playsRemaining()); // Hope's own grant is gone, not merely un-attributed
        self::assertNull($state->useGrantFor(3, 1)); // only the base allowance (null restriction) is left to consume
        self::assertSame(0, $state->playsRemaining());
    }

    /**
     * Contrast with the test above: a grant with no 'requiresSourceInPlay'
     * tag (e.g. Stubbornness's own perpetual grant -- see
     * GameService::computeFreshGrants()) persists for the rest of the turn
     * even after whatever card granted it leaves play, since nothing ties
     * its survival to that card's continued presence the way Hope's/
     * Grace's "while in play" phrasing does.
     */
    public function testGrantWithoutRequiresSourceInPlayPersistsAfterItsSourceLeavesPlay(): void
    {
        $state = $this->boardState(hands: [1 => [102, 3]]); // Stubbornness, a plain hand card
        $state->startTurn(1);
        $state->moveHandToInPlay(1, 102); // Stubbornness enters play
        $state->grantExtraPlay(1, null, sourceCardId: 102); // Stubbornness's own perpetual grant, no requiresSourceInPlay

        self::assertSame(2, $state->playsRemaining());

        $state->moveInPlayToDiscard(102); // Stubbornness itself later leaves play

        self::assertSame(2, $state->playsRemaining()); // the grant it already created is unaffected
    }

    /**
     * With a plain base allowance plus one Hope-sourced grant both able to
     * cover the same play, usableGrants() must surface both as distinct
     * choices -- this is the data GameService::grantChoiceOptions() offers
     * a player as "which extra play do you want to use for this card".
     */
    public function testUsableGrantsReturnsEachDistinctSourceSeparately(): void
    {
        $state = $this->boardState(hands: [1 => [124, 3]]); // Hope, a plain hand card
        $state->startTurn(1);
        $state->moveHandToInPlay(1, 124); // Hope enters play
        $state->grantExtraPlay(1, ['requiresSourceInPlay' => true], sourceCardId: 124); // Hope's own bonus

        $grants = $state->usableGrants(3, 1);

        self::assertCount(2, $grants);
        self::assertNull($grants[0]); // the base allowance
        self::assertSame(['requiresSourceInPlay' => true, 'sourceCardId' => 124], $grants[1]);
    }

    /**
     * Hurt Feelings grants a second, entirely unrestricted base-style play
     * (see startTurn()'s hasHurtFeelings param) -- two bare-null entries in
     * $playGrants that are indistinguishable to a player choosing between
     * them, so usableGrants() must collapse them into a single entry
     * rather than offering a nonsensical "which null do you want" choice.
     */
    public function testUsableGrantsCollapsesMultipleBaseAllowances(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->startTurn(1, hasHurtFeelings: true);

        self::assertSame(2, $state->playsRemaining());
        self::assertCount(1, $state->usableGrants(3, 1));
    }

    /**
     * The player-facing counterpart to usableGrants(): given a preference
     * (here, Hope's own card id), useGrantFor() must consume that specific
     * grant even though the base allowance would have matched first.
     */
    public function testUseGrantForWithPreferredSourceCardIdConsumesThatSpecificGrant(): void
    {
        $state = $this->boardState(hands: [1 => [124, 3]]);
        $state->startTurn(1);
        $state->moveHandToInPlay(1, 124);
        $state->grantExtraPlay(1, ['requiresSourceInPlay' => true], sourceCardId: 124);

        $consumed = $state->useGrantFor(3, 1, preferredSourceCardId: 124);

        self::assertSame(['requiresSourceInPlay' => true, 'sourceCardId' => 124], $consumed);
        self::assertSame(1, $state->playsRemaining()); // only the base allowance is left
    }

    /**
     * 0 is the sentinel usableGrants()/grantChoiceOptions() use for "the
     * base allowance" (it has no 'sourceCardId' of its own) -- confirms
     * useGrantFor() honors that same sentinel rather than treating a
     * preference of 0 as "no preference".
     */
    public function testUseGrantForWithPreferredSourceCardIdZeroConsumesBaseAllowance(): void
    {
        $state = $this->boardState(hands: [1 => [124, 3]]);
        $state->startTurn(1);
        $state->moveHandToInPlay(1, 124);
        $state->grantExtraPlay(1, ['requiresSourceInPlay' => true], sourceCardId: 124);

        $consumed = $state->useGrantFor(3, 1, preferredSourceCardId: 0);

        self::assertNull($consumed); // the base allowance's own restriction is bare null
        self::assertSame(1, $state->playsRemaining()); // Hope's grant is still outstanding
    }

    /**
     * A stale or fabricated preference naming a grant that isn't actually
     * usable right now must not fall back to silently consuming some
     * *other* grant -- see MoodPlayService::playMood()'s own validation,
     * which relies on this to reject such a preference outright instead of
     * ever reaching useGrantFor() with it.
     */
    public function testUseGrantForWithNonMatchingPreferredSourceCardIdConsumesNothing(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->startTurn(1);

        $consumed = $state->useGrantFor(3, 1, preferredSourceCardId: 999);

        self::assertNull($consumed);
        self::assertSame(1, $state->playsRemaining()); // the base allowance is untouched
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

    public function testCatalogRowFallsBackToTreatingAnUnmappedCardIdAsAlreadyBeingACatalogId(): void
    {
        // No $catalogCardIdFor supplied -- every pure in-memory test relies
        // on this default, since a literal like 5 doubles as both the
        // instance id and the catalog id when the two never diverge (i.e.
        // every game except a duel with a genuinely duplicated card).
        $state = $this->boardState();

        self::assertSame('complacency', $state->catalogRow(5)['effectKey']);
    }

    public function testTwoDifferentPhysicalCardsSharingACatalogIdCanBothBeInPlaySimultaneously(): void
    {
        // 1001 and 1002 are two different physical copies of catalog card 5
        // (Complacency, white, value 4) -- the scenario a 'duel' game's two
        // independently-built decks can now produce, which the old
        // catalog-id-as-identity model made structurally impossible (a
        // second moveHandToInPlay() for the same catalog id would have
        // silently overwritten the first one's MoodInPlay).
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [1001], 2 => [1002]],
            catalogCardIdFor: [1001 => 5, 1002 => 5],
        );

        $state->moveHandToInPlay(1, 1001);
        $state->moveHandToInPlay(2, 1002);

        self::assertTrue($state->isInPlay(1001));
        self::assertTrue($state->isInPlay(1002));
        self::assertSame(1, $state->ownerOf(1001));
        self::assertSame(2, $state->ownerOf(1002));
        self::assertSame(4, $state->valueOf(1001));
        self::assertSame(4, $state->valueOf(1002));
    }

    public function testRemoveFromDiscardDisambiguatesTwoPhysicalCardsSharingACatalogId(): void
    {
        // Same duplicated-catalog-card setup as above, but in the discard
        // pile -- the old model's removeFromDiscard() did a first-match
        // array_search() by catalog id and would have removed whichever
        // entry happened to come first, then wiped the single shared
        // $discardOwners entry for that catalog id regardless of which
        // physical card was actually meant.
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            discard: [1001, 1002],
            discardOwners: [1001 => 1, 1002 => 2],
            catalogCardIdFor: [1001 => 5, 1002 => 5],
        );

        $state->moveDiscardToBottomOfDeck(1001);

        self::assertFalse($state->isInDiscardPile(1001));
        self::assertTrue($state->isInDiscardPile(1002));
        self::assertNull($state->discardOwnerOf(1001));
        self::assertSame(2, $state->discardOwnerOf(1002));
        self::assertSame([1001], $state->deck());
    }
}
