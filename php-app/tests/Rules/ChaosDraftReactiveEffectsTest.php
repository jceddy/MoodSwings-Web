<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosDefaultEffectRegistry;
use MoodSwings\Rules\ChaosEffectRegistry;
use MoodSwings\Rules\ChaosEffects\Chaos011Effect;
use MoodSwings\Rules\ChaosEffects\Chaos016Effect;
use MoodSwings\Rules\ChaosEffects\Chaos024Effect;
use MoodSwings\Rules\ChaosEffects\Chaos037Effect;
use MoodSwings\Rules\ChaosEffects\Chaos040Effect;
use MoodSwings\Rules\ChaosEffects\Chaos064Effect;
use MoodSwings\Rules\ChaosEffects\Chaos089Effect;
use MoodSwings\Rules\ChaosEffects\Chaos124Effect;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PlayerChoices;
use PHPUnit\Framework\TestCase;

/**
 * Chaos Draft (issue #405): the reactive hooks a small minority of the
 * 133-effect pool needs beyond the base computeValue()/afterPlaying()
 * composition (see ChaosMoodEffect's own class docblock) -- onMoodPlayed/
 * onMoodDiscarded/onMoodSuppressed (dispatched by
 * MoodPlayService::dispatchChaosReactiveHooks(), fired once a play's own
 * full afterPlaying chain finishes) and perpetualTurnStartGrants()'s own
 * immediate same-turn application. Exercises real pool effects (not
 * throwaway test doubles, unlike ChaosDraftCompositionTest.php) since
 * these hooks now have real registered implementations.
 */
final class ChaosDraftReactiveEffectsTest extends TestCase
{
    use CatalogFixture;

    private function catalogWithTokens(): array
    {
        return $this->sampleCatalog() + [
            134 => $this->row('white', 'common', 1, null, 'smugness_token', false, false, false),
            137 => $this->row('red', 'common', 1, null, 'tedium_token', false, false, false),
        ];
    }

    private function chaosCatalogRow(string $effectKey, string $shape): array
    {
        return ['effectKey' => $effectKey, 'rarity' => 'common', 'shape' => $shape, 'rulesText' => ''];
    }

    private function boardState(array $hands = [], array $deck = [], array $chaosCatalog = [], array $chaosEffectIdFor = [], ?ChaosEffectRegistry $chaosRegistry = null): BoardState
    {
        return new BoardState(
            $this->catalogWithTokens(),
            DefaultEffectRegistry::build(),
            [1, 2],
            $hands,
            $deck,
            chaosCatalog: $chaosCatalog,
            chaosRegistry: $chaosRegistry ?? ChaosDefaultEffectRegistry::build(),
            chaosEffectIdFor: $chaosEffectIdFor,
        );
    }

    public function testOnMoodPlayedFiresChaos037DrawWhenOwnerPlaysAnotherMood(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('chaos_037', new Chaos037Effect());

        // Charity (3, already in play, carrying chaos_037) reacts when
        // player 1 plays Apathy (55) -- a second mood, same player.
        $state = $this->boardState(
            hands: [1 => [3, 55]],
            deck: [4],
            chaosCatalog: [1 => $this->chaosCatalogRow('chaos_037', 'while_in_play')],
            chaosEffectIdFor: [3 => 1],
            chaosRegistry: $chaosRegistry,
        );
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 55, new PlayerChoices([]));

        self::assertContains(4, $state->hand(1));
    }

    public function testOnMoodPlayedDoesNotFireChaos037ForTheCarryingCardsOwnPlay(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('chaos_037', new Chaos037Effect());

        $state = $this->boardState(
            hands: [1 => [3]],
            deck: [4],
            chaosCatalog: [1 => $this->chaosCatalogRow('chaos_037', 'while_in_play')],
            chaosEffectIdFor: [3 => 1],
            chaosRegistry: $chaosRegistry,
        );
        $state->attachChaosEffect(3, 1);
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 3, new PlayerChoices([]));

        self::assertSame([], $state->hand(1));
    }

    public function testOnMoodPlayedFiresChaos040DrawWhenAnOpponentPlaysAMood(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('chaos_040', new Chaos040Effect());

        $state = $this->boardState(
            hands: [1 => [3], 2 => [55]],
            deck: [4],
            chaosCatalog: [1 => $this->chaosCatalogRow('chaos_040', 'while_in_play')],
            chaosEffectIdFor: [3 => 1],
            chaosRegistry: $chaosRegistry,
        );
        $state->moveHandToInPlay(1, 3); // player 1's own mood carries chaos_040
        $state->startTurn(2);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 2, 55, new PlayerChoices([]));

        self::assertContains(4, $state->hand(1));
        self::assertNotContains(4, $state->hand(2));
    }

    public function testOnMoodPlayedFiresChaos016WhenADiceValueMoodEntersPlay(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('chaos_016', new Chaos016Effect());

        // Chivalry (id 4, base 3/alt 5) carries a printed alt/dice value.
        $state = $this->boardState(
            hands: [1 => [3, 4]],
            chaosCatalog: [1 => $this->chaosCatalogRow('chaos_016', 'while_in_play')],
            chaosEffectIdFor: [3 => 1],
            chaosRegistry: $chaosRegistry,
        );
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 4, new PlayerChoices([]));

        $spawned = array_filter($state->moodsInPlay(), static fn ($mood) => $mood->cardId < 0);
        self::assertCount(1, $spawned);
    }

    public function testOnMoodDiscardedFiresChaos011WhenTheCarryingCardItselfIsDiscarded(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('chaos_011', new Chaos011Effect());
        // A second mood whose own afterPlaying discards chaos_011's own
        // carrier -- reuse Denial-style direct BoardState mutation instead
        // of a real card, since only the trigger itself is under test.
        $chaosRegistry->register('discard_target', new class extends AbstractChaosMoodEffect {
            public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
            {
                $state->moveInPlayToDiscard($choices->requireInt('target'));
            }
        });

        $state = $this->boardState(
            hands: [1 => [3, 55]],
            chaosCatalog: [
                1 => $this->chaosCatalogRow('chaos_011', 'while_in_play'),
                2 => $this->chaosCatalogRow('discard_target', 'after_playing'),
            ],
            chaosEffectIdFor: [3 => 1, 55 => 2],
            chaosRegistry: $chaosRegistry,
        );
        $state->moveHandToInPlay(1, 3); // carries chaos_011
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 55, new PlayerChoices(['chaos' => ['target' => 3]]));

        $spawned = array_filter($state->moodsInPlay(), static fn ($mood) => $mood->cardId < 0);
        self::assertCount(2, $spawned);
    }

    public function testOnMoodDiscardedFiresChaos089SpawningTokensEqualToTheDiscardedMoodsValue(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('chaos_089', new Chaos089Effect());
        $chaosRegistry->register('discard_target', new class extends AbstractChaosMoodEffect {
            public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
            {
                $state->moveInPlayToDiscard($choices->requireInt('target'));
            }
        });

        // Chivalry (id 4, base value 3) is player 1's own OTHER mood.
        $state = $this->boardState(
            hands: [1 => [3, 55, 4]],
            chaosCatalog: [
                1 => $this->chaosCatalogRow('chaos_089', 'while_in_play'),
                2 => $this->chaosCatalogRow('discard_target', 'after_playing'),
            ],
            chaosEffectIdFor: [3 => 1, 55 => 2],
            chaosRegistry: $chaosRegistry,
        );
        $state->moveHandToInPlay(1, 3); // carries chaos_089
        $state->moveHandToInPlay(1, 4); // Chivalry, value 3
        $state->startRound(1); // player 1 goes first, so Chivalry stays at its base value (3), not its alt value (5)
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 55, new PlayerChoices(['chaos' => ['target' => 4]]));

        $spawned = array_filter($state->moodsInPlay(), static fn ($mood) => $mood->cardId < 0);
        self::assertCount(3, $spawned); // Chivalry's own value (3) worth of Tedium tokens
    }

    public function testOnMoodSuppressedFiresChaos024(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('chaos_024', new Chaos024Effect());
        $chaosRegistry->register('suppress_target', new class extends AbstractChaosMoodEffect {
            public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
            {
                $state->suppress($choices->requireInt('target'), 'while_source_in_play', $cardId);
            }
        });

        $state = $this->boardState(
            hands: [1 => [3, 55]],
            chaosCatalog: [
                1 => $this->chaosCatalogRow('chaos_024', 'while_in_play'),
                2 => $this->chaosCatalogRow('suppress_target', 'after_playing'),
            ],
            chaosEffectIdFor: [3 => 1, 55 => 2],
            chaosRegistry: $chaosRegistry,
        );
        $state->moveHandToInPlay(1, 3); // carries chaos_024
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 55, new PlayerChoices(['chaos' => ['target' => 3]]));

        $spawned = array_filter($state->moodsInPlay(), static fn ($mood) => $mood->cardId < 0);
        self::assertCount(1, $spawned);
    }

    public function testPerpetualTurnStartGrantsAppliesImmediatelyTheSameTurnChaos124IsPlayed(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('chaos_124', new Chaos124Effect());

        $state = $this->boardState(
            hands: [1 => [3, 55]],
            chaosCatalog: [1 => $this->chaosCatalogRow('chaos_124', 'while_in_play')],
            chaosEffectIdFor: [3 => 1],
            chaosRegistry: $chaosRegistry,
        );
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        // Playing the mood that CARRIES chaos_124 should immediately grant
        // an extra play this same turn ("including the turn you play this
        // mood"), not just starting next turn.
        $service->playMood($state, 1, 3, new PlayerChoices([]));

        self::assertGreaterThanOrEqual(1, $state->playsRemaining());
        // Prove the grant is genuinely usable: playing a second mood this
        // same turn doesn't throw / leaves the request able to complete.
        $result = $service->playMood($state, 1, 55, new PlayerChoices([]));
        self::assertFalse($result->isPending);
    }

    public function testOnMoodDiscardedChaos064ReducesARandomOpponentMoodsValue(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('chaos_064', new Chaos064Effect());
        $chaosRegistry->register('discard_self', new class extends AbstractChaosMoodEffect {
            public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
            {
                $state->moveInPlayToDiscard($cardId);
            }
        });

        // Player 1's Chivalry (4, value 3) carries chaos_064; player 2
        // owns Apathy (55, value 4) as the only possible opponent target.
        // Complacency (5) is a blank vanilla card used purely to carry the
        // throwaway 'discard_self' chaos effect that triggers the
        // reaction under test.
        $state = $this->boardState(
            hands: [1 => [4, 5], 2 => [55]],
            chaosCatalog: [
                1 => $this->chaosCatalogRow('chaos_064', 'while_in_play'),
                2 => $this->chaosCatalogRow('discard_self', 'after_playing'),
            ],
            chaosEffectIdFor: [4 => 1, 5 => 2],
            chaosRegistry: $chaosRegistry,
        );
        $state->moveHandToInPlay(1, 4); // Chivalry, carries chaos_064
        $state->moveHandToInPlay(2, 55); // Apathy, value 4, the only opponent mood
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 5, new PlayerChoices([])); // self-discards, triggering the reaction

        self::assertSame(3, $state->valueOf(55));
    }
}
