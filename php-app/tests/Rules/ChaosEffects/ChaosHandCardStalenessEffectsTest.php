<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules\ChaosEffects;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosDefaultEffectRegistry;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Tests\Rules\CatalogFixture;
use PHPUnit\Framework\TestCase;

/**
 * chaos_008/012/025/036/053/058/087/106/110/111/118 (issue #405 follow-up
 * -- a bug caught live): each reads a hand card from the ACTING PLAYER'S
 * OWN hand as part of the SAME up-front request that plays the host card
 * -- so the chosen card id was validated against whatever the acting
 * player's hand looked like BEFORE the host card's own afterPlaying() ran.
 * Reported live: attaching chaos_058 ("give a card from your hand away")
 * to Rationalization and choosing Rationalization's own "rotate hands"
 * mode threw "Card is not in your hand" instead of letting the player
 * choose which of their NEW (post-rotate) cards to give away --
 * Rationalization's own afterPlaying() had already swapped the acting
 * player's whole hand away by the time the up-front-chosen card id was
 * checked against it. Each of these 11 effects now defers its own hand
 * card choice to a self-targeted `ChaosRequiresOpponentDecision`, asked
 * only once the host card's own afterPlaying() has fully resolved -- see
 * ChaosEffects/ChaosDiscardValueToBoostSelfEffect's own docblock for the
 * full reasoning.
 */
final class ChaosHandCardStalenessEffectsTest extends TestCase
{
    use CatalogFixture;

    /** Fake chaos ids -> the real registered effect_keys under test. */
    private function chaosCatalog(): array
    {
        return [
            1 => ['effectKey' => 'chaos_008', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''], // ChaosDiscardValueToBoostSelfEffect, representative of chaos_008/087/110/111
            2 => ['effectKey' => 'chaos_012', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => ''],
            3 => ['effectKey' => 'chaos_025', 'rarity' => 'rare', 'shape' => 'after_playing', 'rulesText' => ''],
            4 => ['effectKey' => 'chaos_036', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => ''],
            5 => ['effectKey' => 'chaos_053', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''],
            6 => ['effectKey' => 'chaos_058', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''],
            7 => ['effectKey' => 'chaos_106', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''],
            8 => ['effectKey' => 'chaos_118', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => ''],
        ];
    }

    private function boardState(array $hands = [], array $deck = []): BoardState
    {
        return new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            $hands,
            $deck,
            chaosCatalog: $this->chaosCatalog(),
            chaosRegistry: ChaosDefaultEffectRegistry::build(),
        );
    }

    private function service(): MoodPlayService
    {
        return new MoodPlayService(DefaultEffectRegistry::build(), ChaosDefaultEffectRegistry::build());
    }

    /**
     * The exact reported bug: chaos_058 attached to the real Rationalization
     * (catalog id 49), whose own "rotate hands" mode replaces the acting
     * player's ENTIRE hand as part of its own afterPlaying(). Before this
     * fix, choosing a pre-rotate hand card to also give away via chaos_058
     * threw InvalidChoiceException; now the player is asked AFTER the
     * rotate, using their new hand.
     */
    public function testChaos058AttachedToRationalizationCanGiveAwayACardChosenAfterRotateResolves(): void
    {
        $state = $this->boardState(hands: [1 => [49, 7], 2 => [3], 3 => [5]]); // Rationalization+Courage; Charity; Complacency
        $state->attachChaosEffect(49, 6); // chaos_058
        $state->startTurn(1);

        $choices = new PlayerChoices([
            'mode' => 'rotate',
            'direction' => 'left',
            'chaos' => ['recipient_player_id' => 2],
        ]);

        $result = $this->service()->playMood($state, 1, 49, $choices);

        self::assertTrue($result->isPending);
        self::assertSame('chaos_effect', $result->pendingSource);

        // Rotate already happened: player 1 now holds Complacency (5), a
        // card they never had before this play -- NOT Courage (7), which
        // has already moved on to another player.
        self::assertSame([5], $state->hand(1));

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 49, 1, $choices, $result->invocationChoices, 0,
            ['hand_card_id' => new PlayerChoices(['hand_card_id' => 5])], // the NEW, post-rotate card
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame([], $state->hand(1));
        self::assertContains(5, $state->hand(2), 'the post-rotate card was successfully given away');
        self::assertSame(6, $state->valueOf(49));
        self::assertSame(6, $state->chaosValueOverrideOf(49));
        self::assertNull($state->effectState(49, 'valueOverride'), 'must never set valueOverride -- that would incorrectly trigger the 180-degree value_locked rotation');
    }

    public function testChaos058PausesOnlyAfterConfirmingARecipient(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [3]]); // Apathy, no ability of its own
        $state->attachChaosEffect(55, 6);
        $state->startTurn(1);

        $result = $this->service()->playMood($state, 1, 55, new PlayerChoices([]));

        self::assertFalse($result->isPending, 'declining the up-front recipient means no pause at all');
        self::assertSame([], $state->hand(1));
    }

    public function testChaos012PausesAndBundlesBothFieldsInOneNestedDecision(): void
    {
        $state = $this->boardState(hands: [1 => [55, 33], 2 => [7]]); // Apathy (carrier); Curiosity (blue); Courage
        $state->moveHandToInPlay(2, 7);
        $state->attachChaosEffect(55, 2); // chaos_012
        $state->startTurn(1);

        $result = $this->service()->playMood($state, 1, 55, new PlayerChoices([]));

        self::assertTrue($result->isPending);
        $decision = $result->pendingDecisions[0];
        self::assertSame('discard_and_suppress', $decision->key);
        self::assertSame('nested', $decision->field['type']);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, new PlayerChoices([]), $result->invocationChoices, 0,
            ['discard_and_suppress' => new PlayerChoices(['discard_and_suppress' => ['discard_card_id' => 33, 'suppress_mood_card_id' => 7]])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertContains(33, $state->discardPile());
        self::assertTrue($state->isSuppressed(7));
    }

    public function testChaos025DiscardsAndSuppressesMatchingColor(): void
    {
        $state = $this->boardState(hands: [1 => [55, 7], 2 => [3], 3 => [5]]); // Apathy; Courage (white); Charity (white, in play); Complacency (white, in play)
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 5);
        $state->attachChaosEffect(55, 3); // chaos_025
        $state->startTurn(1);

        $result = $this->service()->playMood($state, 1, 55, new PlayerChoices([]));
        self::assertTrue($result->isPending);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, new PlayerChoices([]), $result->invocationChoices, 0,
            ['discard_card_id' => new PlayerChoices(['discard_card_id' => 7])], // Courage, white
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertContains(7, $state->discardPile());
        self::assertTrue($state->isSuppressed(3));
        self::assertTrue($state->isSuppressed(5));
    }

    public function testChaos036RevealsMultipleCardsAndBansColors(): void
    {
        $state = $this->boardState(hands: [1 => [55, 7, 33]], deck: [3, 5]); // Apathy; Courage (white); Curiosity (blue) -- deck to redraw from
        $state->attachChaosEffect(55, 4); // chaos_036
        $state->startTurn(1);

        $result = $this->service()->playMood($state, 1, 55, new PlayerChoices([]));
        self::assertTrue($result->isPending);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, new PlayerChoices([]), $result->invocationChoices, 0,
            ['hand_card_ids' => new PlayerChoices(['hand_card_ids' => [7, 33]])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertNotContains(7, $state->hand(1));
        self::assertNotContains(33, $state->hand(1));
        self::assertContains(3, $state->hand(1));
        self::assertContains(5, $state->hand(1));
    }

    public function testChaos053GrantsExtraPlayAfterDiscard(): void
    {
        $state = $this->boardState(hands: [1 => [55, 7]]);
        $state->attachChaosEffect(55, 5); // chaos_053
        $state->startTurn(1);

        $result = $this->service()->playMood($state, 1, 55, new PlayerChoices([]));
        self::assertTrue($result->isPending);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, new PlayerChoices([]), $result->invocationChoices, 0,
            ['discard_card_id' => new PlayerChoices(['discard_card_id' => 7])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertContains(7, $state->discardPile());
        self::assertSame(1, $state->playsRemaining());
    }

    public function testChaos106BottomDecksAndDraws(): void
    {
        $state = $this->boardState(hands: [1 => [55, 7]], deck: [3]);
        $state->attachChaosEffect(55, 7); // chaos_106
        $state->startTurn(1);

        $result = $this->service()->playMood($state, 1, 55, new PlayerChoices([]));
        self::assertTrue($result->isPending);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, new PlayerChoices([]), $result->invocationChoices, 0,
            ['hand_card_id' => new PlayerChoices(['hand_card_id' => 7])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertNotContains(7, $state->hand(1));
        self::assertContains(3, $state->hand(1));
    }

    public function testChaos118GivesAwayAQualifyingCardAfterConfirmingRecipient(): void
    {
        $state = $this->boardState(hands: [1 => [55, 33], 2 => []]); // Apathy; Curiosity (blue)
        $state->attachChaosEffect(55, 8); // chaos_118
        $state->startTurn(1);

        $choices = new PlayerChoices(['chaos' => ['recipient_player_id' => 2]]);
        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            ['hand_card_id' => new PlayerChoices(['hand_card_id' => 33])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertContains(33, $state->hand(2));
        self::assertSame(7, $state->valueOf(55));
        self::assertSame(7, $state->chaosValueOverrideOf(55));
        self::assertNull($state->effectState(55, 'valueOverride'), 'must never set valueOverride -- that would incorrectly trigger the 180-degree value_locked rotation');
    }

    public function testChaos118RejectsANonQualifyingColor(): void
    {
        $state = $this->boardState(hands: [1 => [55, 7], 2 => []]); // Apathy; Courage (white -- doesn't qualify)
        $state->attachChaosEffect(55, 8);
        $state->startTurn(1);

        $choices = new PlayerChoices(['chaos' => ['recipient_player_id' => 2]]);
        $result = $this->service()->playMood($state, 1, 55, $choices);

        $this->expectException(InvalidChoiceException::class);
        $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            ['hand_card_id' => new PlayerChoices(['hand_card_id' => 7])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );
    }

    public function testChaosDiscardValueToBoostSelfEffectBoostsValueAfterDiscarding(): void
    {
        $state = $this->boardState(hands: [1 => [55, 3]]); // Apathy (value 4); Charity (base value 1, qualifies)
        $state->attachChaosEffect(55, 1); // chaos_008
        $state->startTurn(1);

        $result = $this->service()->playMood($state, 1, 55, new PlayerChoices([]));
        self::assertTrue($result->isPending);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, new PlayerChoices([]), $result->invocationChoices, 0,
            ['discard_card_id' => new PlayerChoices(['discard_card_id' => 3])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertContains(3, $state->discardPile());
        self::assertSame(5, $state->valueOf(55));
        self::assertSame(5, $state->chaosValueOverrideOf(55));
        self::assertNull($state->effectState(55, 'valueOverride'), 'must never set valueOverride -- that would incorrectly trigger the 180-degree value_locked rotation');
    }

    public function testChaosDiscardValueToBoostSelfEffectDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [55, 3]]);
        $state->attachChaosEffect(55, 1);
        $state->startTurn(1);

        $result = $this->service()->playMood($state, 1, 55, new PlayerChoices([]));
        self::assertTrue($result->isPending, 'still pauses to ask, even though the answer may be a decline');

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, new PlayerChoices([]), $result->invocationChoices, 0,
            ['discard_card_id' => new PlayerChoices(['discard_card_id' => null])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame([3], $state->hand(1));
        self::assertSame(4, $state->valueOf(55));
    }
}
