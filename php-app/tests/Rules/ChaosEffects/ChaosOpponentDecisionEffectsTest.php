<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosDefaultEffectRegistry;
use MoodSwings\Rules\ChaosEffectRegistry;
use MoodSwings\Rules\ChaosRequiresOpponentDecision;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Tests\Rules\CatalogFixture;
use PHPUnit\Framework\TestCase;

/**
 * chaos_010/029/031/067/068/078/082/086/091/096 (issue #405 follow-up -- a
 * maintainer ruling reversing each class's own original design): the
 * uniformly-random "opponent's own decision" simplification is replaced by
 * a real pause via ChaosRequiresOpponentDecision, for the chaos effects
 * whose printed text has an identically-shaped printed-card precedent
 * already implementing RequiresOpponentDecision. Reported live for
 * chaos_086: "the other player should choose the card from their hand to
 * give to me -- it should not be random."
 *
 * The first 10 tests use the REAL ChaosDefaultEffectRegistry (not
 * throwaway test doubles, unlike ChaosDraftCompositionTest.php) since the
 * exact behavior of each effect_key's own implementation is itself what's
 * under test -- the same convention ChaosActOnChosenPlayersMoodEffectTest.php
 * already established for the previous "let the player choose" fix. Each
 * proves the SAME core property: the specific answer given resolves,
 * deterministically -- never some other legal candidate -- which is
 * exactly what the old array_rand()-based code could never guarantee.
 *
 * The last 2 tests prove the underlying pause MECHANISM itself
 * (MoodPlayService's new 'chaos_effect'-sourced PlayResult::pending()
 * path) using throwaway registrations, mirroring
 * ChaosDraftCompositionTest.php's own style, since that's board-agnostic
 * plumbing rather than any one effect's own behavior.
 */
final class ChaosOpponentDecisionEffectsTest extends TestCase
{
    use CatalogFixture;

    /** Fake chaos ids -> the real registered effect_keys under test. */
    private function chaosCatalog(): array
    {
        return [
            1 => ['effectKey' => 'chaos_086', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''],
            2 => ['effectKey' => 'chaos_067', 'rarity' => 'rare', 'shape' => 'after_playing', 'rulesText' => ''],
            3 => ['effectKey' => 'chaos_082', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => ''],
            4 => ['effectKey' => 'chaos_010', 'rarity' => 'rare', 'shape' => 'after_playing', 'rulesText' => ''],
            5 => ['effectKey' => 'chaos_029', 'rarity' => 'rare', 'shape' => 'after_playing', 'rulesText' => ''],
            6 => ['effectKey' => 'chaos_031', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => ''],
            7 => ['effectKey' => 'chaos_091', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => ''],
            8 => ['effectKey' => 'chaos_068', 'rarity' => 'mythic', 'shape' => 'after_playing', 'rulesText' => ''],
            9 => ['effectKey' => 'chaos_078', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''],
            10 => ['effectKey' => 'chaos_096', 'rarity' => 'rare', 'shape' => 'after_playing', 'rulesText' => ''],
        ];
    }

    private function boardState(array $hands = []): BoardState
    {
        return new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            $hands,
            chaosCatalog: $this->chaosCatalog(),
            chaosRegistry: ChaosDefaultEffectRegistry::build(),
        );
    }

    private function service(): MoodPlayService
    {
        return new MoodPlayService(DefaultEffectRegistry::build(), ChaosDefaultEffectRegistry::build());
    }

    public function testChaos086PausesForTheTargetsOwnChoiceThenResolvesTheTransfer(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [3, 7]]); // Apathy; Charity, Courage
        $state->attachChaosEffect(55, 1); // chaos_086
        $state->startTurn(1);
        $choices = new PlayerChoices(['chaos' => ['target_player_id' => 2]]);

        $result = $this->service()->playMood($state, 1, 55, $choices);

        self::assertTrue($result->isPending);
        self::assertSame('chaos_effect', $result->pendingSource);
        $decision = $result->pendingDecisions[0];
        self::assertSame('given_card_id', $decision->key);
        self::assertSame(2, $decision->targetPlayerId);
        self::assertSame('chaos_086_give_card', $decision->decisionType);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            ['given_card_id' => new PlayerChoices(['given_card_id' => 3])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame([3], $state->hand(1), 'the specifically chosen card, not a random one');
        self::assertSame([7], $state->hand(2));
    }

    public function testChaos067GivesTheSpecificallyRevealedCardNotARandomOne(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [3, 7]]);
        $state->attachChaosEffect(55, 2); // chaos_067
        $state->startTurn(1);
        $choices = new PlayerChoices(['chaos' => ['target_player_id' => 2]]);

        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            ['revealed_card_id' => new PlayerChoices(['revealed_card_id' => 7])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame([7], $state->hand(1));
        self::assertSame([3], $state->hand(2));
    }

    public function testChaos082TakesTheOpponentsSpecificallyChosenQualifyingMood(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [7, 33]]); // Apathy; Courage (white), Curiosity (blue)
        $state->moveHandToInPlay(2, 7);
        $state->moveHandToInPlay(2, 33);
        $state->attachChaosEffect(55, 3); // chaos_082
        $state->startTurn(1);
        $choices = new PlayerChoices(['chaos' => ['opponent_player_id' => 2]]);

        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            ['chosen_mood_id' => new PlayerChoices(['chosen_mood_id' => 33])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame(1, $state->ownerOf(33), 'the specifically chosen mood, not a random one');
        self::assertSame(2, $state->ownerOf(7));
    }

    public function testChaos010PausesForEveryPlayersOwnColorChoiceThenDiscardsMatches(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [7], 3 => [33]]); // Apathy; Courage (white); Curiosity (blue)
        $state->moveHandToInPlay(2, 7);
        $state->moveHandToInPlay(3, 33);
        $state->attachChaosEffect(55, 4); // chaos_010
        $state->startTurn(1);
        $choices = new PlayerChoices([]);

        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);
        self::assertCount(3, $result->pendingDecisions, 'every active player, including the acting player, is queued');

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            [
                'chosen_color_2' => new PlayerChoices(['chosen_color_2' => 'white']),
                'chosen_color_3' => new PlayerChoices(['chosen_color_3' => null]),
                'chosen_color_1' => new PlayerChoices(['chosen_color_1' => null]),
            ],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertFalse($state->isInPlay(7), 'white, matching the one chosen color');
        self::assertTrue($state->isInPlay(33), 'blue, no color was chosen for it');
    }

    public function testChaos029GivesEachPlayersSpecificallyChosenMoodToTheirNeighbor(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [7], 3 => [33]]);
        $state->moveHandToInPlay(2, 7);
        $state->moveHandToInPlay(3, 33);
        $state->attachChaosEffect(55, 5); // chaos_029
        $state->startTurn(1);
        $choices = new PlayerChoices(['chaos' => ['direction' => 'left']]);

        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);
        self::assertCount(3, $result->pendingDecisions);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            [
                'given_mood_id_1' => new PlayerChoices(['given_mood_id_1' => 55]),
                'given_mood_id_2' => new PlayerChoices(['given_mood_id_2' => 7]),
                'given_mood_id_3' => new PlayerChoices(['given_mood_id_3' => 33]),
            ],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame(2, $state->ownerOf(55), "player 1's own mood moved to their left neighbor");
        self::assertSame(3, $state->ownerOf(7));
        self::assertSame(1, $state->ownerOf(33));
    }

    public function testChaos031GivesEachPlayersSpecificallyChosenCardToTheirNeighbor(): void
    {
        $state = $this->boardState(hands: [1 => [55, 3], 2 => [7], 3 => [33]]); // player 1 keeps a hand card to give
        $state->attachChaosEffect(55, 6); // chaos_031
        $state->startTurn(1);
        $choices = new PlayerChoices(['chaos' => ['direction' => 'left']]);

        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);
        self::assertCount(3, $result->pendingDecisions);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            [
                'given_card_id_1' => new PlayerChoices(['given_card_id_1' => 3]),
                'given_card_id_2' => new PlayerChoices(['given_card_id_2' => 7]),
                'given_card_id_3' => new PlayerChoices(['given_card_id_3' => 33]),
            ],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame([3], $state->hand(2));
        self::assertSame([7], $state->hand(3));
        self::assertSame([33], $state->hand(1));
    }

    public function testChaos091DiscardsTheSpecificallyChosenTiedForHighestMood(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [7, 3]]); // Apathy; Courage (1), Charity (1) -- tied
        $state->moveHandToInPlay(2, 7);
        $state->moveHandToInPlay(2, 3);
        $state->attachChaosEffect(55, 7); // chaos_091
        $state->startTurn(1);
        $choices = new PlayerChoices([]);

        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            [
                'discarded_mood_id_1' => new PlayerChoices(['discarded_mood_id_1' => 55]),
                'discarded_mood_id_2' => new PlayerChoices(['discarded_mood_id_2' => 3]),
            ],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertFalse($state->isInPlay(3), 'the specifically chosen tied-for-highest mood');
        self::assertTrue($state->isInPlay(7), 'the OTHER tied-for-highest mood, not chosen, stays');
    }

    public function testChaos068DiscardsTheChosenMoodsAndEverySharedColor(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [7, 3, 33]]); // Courage(white), Charity(white), Curiosity(blue)
        $state->moveHandToInPlay(2, 7);
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(2, 33);
        $state->attachChaosEffect(55, 8); // chaos_068
        $state->startTurn(1);
        $choices = new PlayerChoices(['chaos' => ['target_player_id' => 2]]);

        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);
        $decision = $result->pendingDecisions[0];
        self::assertSame('chosen_mood_ids', $decision->key);
        self::assertTrue($decision->field['multi']);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            ['chosen_mood_ids' => new PlayerChoices(['chosen_mood_ids' => [7, 33]])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertFalse($state->isInPlay(7));
        self::assertFalse($state->isInPlay(33));
        self::assertFalse($state->isInPlay(3), 'Charity (white) shares a color with chosen Courage (white), so it discards too');
        self::assertTrue($state->isInPlay(55), "the acting player's own carrier mood is untouched (black)");
    }

    public function testChaos078EachChosenPlayerDiscardsTheirOwnSpecificallyChosenCard(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [7, 3], 3 => [33]]);
        $state->attachChaosEffect(55, 9); // chaos_078
        $state->startTurn(1);
        $choices = new PlayerChoices(['chaos' => ['target_player_ids' => [2, 3]]]);

        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);
        self::assertCount(2, $result->pendingDecisions);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            [
                'discarded_card_id_2' => new PlayerChoices(['discarded_card_id_2' => 3]),
                'discarded_card_id_3' => new PlayerChoices(['discarded_card_id_3' => 33]),
            ],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame([7], $state->hand(2), 'the specifically chosen card (3) discarded, the other (7) kept');
        self::assertSame([], $state->hand(3));
        self::assertContains(3, $state->discardPile());
        self::assertContains(33, $state->discardPile());
    }

    public function testChaos096OpponentChoosesWhichOfTheTwoNamedMoodsToGiveUp(): void
    {
        $state = $this->boardState(hands: [1 => [55, 3], 2 => [7, 33]]);
        $state->moveHandToInPlay(1, 3); // Charity, already in play -- the acting player's own "give back" mood
        $state->moveHandToInPlay(2, 7);
        $state->moveHandToInPlay(2, 33);
        $state->attachChaosEffect(55, 10); // chaos_096
        $state->startTurn(1);
        $choices = new PlayerChoices(['chaos' => ['opponent_mood_card_ids' => [7, 33], 'own_mood_card_id' => 3]]);

        $result = $this->service()->playMood($state, 1, 55, $choices);
        self::assertTrue($result->isPending);
        $decision = $result->pendingDecisions[0];
        self::assertSame(2, $decision->targetPlayerId);
        self::assertEqualsCanonicalizing([7, 33], $decision->field['candidate_card_ids']);

        $finalResult = $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            ['given_mood_id' => new PlayerChoices(['given_mood_id' => 33])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame(1, $state->ownerOf(33), 'the specifically chosen mood, not a random one of the two named');
        self::assertSame(2, $state->ownerOf(7), 'the other named mood, not chosen, stays with the opponent');
        self::assertSame(2, $state->ownerOf(3), "the acting player's own mood, given back");
    }

    public function testChaos096RejectsAnAnswerNotAmongTheTwoNamedMoods(): void
    {
        $state = $this->boardState(hands: [1 => [55, 3], 2 => [7, 33]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(2, 7);
        $state->moveHandToInPlay(2, 33);
        $state->attachChaosEffect(55, 10);
        $state->startTurn(1);
        $choices = new PlayerChoices(['chaos' => ['opponent_mood_card_ids' => [7, 33], 'own_mood_card_id' => 3]]);

        $result = $this->service()->playMood($state, 1, 55, $choices);

        $this->expectException(InvalidChoiceException::class);
        $this->service()->resolvePendingDecisions(
            $state, 55, 1, $choices, $result->invocationChoices, 0,
            ['given_mood_id' => new PlayerChoices(['given_mood_id' => 3])], // not one of the two named
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );
    }

    /**
     * The "no base afterPlaying" pause path (resolveAfterPlayingChain()'s
     * own early-return branch) -- proves the pause carries pendingSource:
     * 'chaos_effect' and, once resolved, finishes directly with no bogus
     * Duplicity repeat offer (a card with no base afterPlaying() never
     * gets one, chaos pause or not).
     */
    public function testAttachedChaosEffectWithNoBaseAfterPlayingPausesThenFinishesWithNoRepeatOffer(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('pausing_effect', new class extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision {
            public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
            {
                return [new PendingDecisionRequest(
                    key: 'answer',
                    targetPlayerId: 2,
                    decisionType: 'test_pause',
                    field: ['key' => 'answer', 'type' => 'bool', 'required' => false, 'label' => 'x'],
                )];
            }

            public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
            {
                $state->drawCard($playerId);

                return [];
            }
        });

        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [55]], // Apathy -- no base afterPlaying
            deck: [7],
            chaosCatalog: [1 => ['effectKey' => 'pausing_effect', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => '']],
            chaosRegistry: $chaosRegistry,
        );
        $state->attachChaosEffect(55, 1);
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $result = $service->playMood($state, 1, 55, new PlayerChoices([]));

        self::assertTrue($result->isPending);
        self::assertSame('chaos_effect', $result->pendingSource);
        self::assertSame(0, $result->duplicityEligibleSources);

        $finalResult = $service->resolvePendingDecisions(
            $state, 55, 1, new PlayerChoices([]), $result->invocationChoices, 0,
            ['answer' => new PlayerChoices(['answer' => true])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertFalse($finalResult->isPending, 'no base afterPlaying() means no Duplicity repeat opportunity, chaos pause or not');
        self::assertContains(7, $state->hand(1), "resolveDecisions()'s own side effect applied");
    }

    /**
     * The "continueAfterPlayingChain" pause path (base card DOES have its
     * own afterPlaying()) -- proves that once the chaos pause resolves,
     * the Duplicity-repeat-offer check still runs correctly afterward
     * (continueAfterChaosResolved()), rather than being skipped or
     * re-running resolveAttachedChaosAfterPlaying() a second time.
     */
    public function testAttachedChaosEffectPauseOnACardWithABaseEffectStillOffersARepeatAfterwards(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('pausing_effect', new class extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision {
            public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
            {
                return [new PendingDecisionRequest(
                    key: 'answer',
                    targetPlayerId: 2,
                    decisionType: 'test_pause',
                    field: ['key' => 'answer', 'type' => 'bool', 'required' => false, 'label' => 'x'],
                )];
            }

            public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
            {
                return [];
            }
        });

        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [3, 37]], // Charity (own afterPlaying: grants an extra play), Duplicity
            chaosCatalog: [1 => ['effectKey' => 'pausing_effect', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => '']],
            chaosRegistry: $chaosRegistry,
        );
        $state->attachChaosEffect(3, 1); // attached to Charity, not Duplicity
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 37, new PlayerChoices([])); // Duplicity enters play first

        $result = $service->playMood($state, 1, 3, new PlayerChoices([])); // Charity, chaos attached
        self::assertTrue($result->isPending);
        self::assertSame('chaos_effect', $result->pendingSource);
        self::assertSame(1, $result->duplicityEligibleSources, 'the real Duplicity already in play');

        $afterChaosResolves = $service->resolvePendingDecisions(
            $state, 3, 1, new PlayerChoices([]), $result->invocationChoices, 0,
            ['answer' => new PlayerChoices(['answer' => true])],
            $result->duplicityEligibleSources, [], 'chaos_effect',
        );

        self::assertTrue($afterChaosResolves->isPending, 'the Duplicity repeat offer should still appear after the chaos pause resolves');
        self::assertSame('duplicity_repeat', $afterChaosResolves->pendingDecisions[0]->key);
    }
}
