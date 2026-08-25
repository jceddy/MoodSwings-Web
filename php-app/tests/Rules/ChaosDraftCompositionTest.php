<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosEffectRegistry;
use MoodSwings\Rules\ChaosMoodEffect;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PlayerChoices;
use PHPUnit\Framework\TestCase;

/**
 * Chaos Draft (issue #405) stage 1: the composition layer that lets an
 * attached chaos effect fire alongside a card's own printed ability,
 * before any of the 133 curated effects themselves exist yet. Uses small
 * throwaway ChaosMoodEffect implementations rather than real ones, the
 * same way the rest of this test suite exercises BoardState/MoodPlayService
 * mechanics independent of any specific card.
 */
final class ChaosDraftCompositionTest extends TestCase
{
    use CatalogFixture;

    private function chaosCatalog(): array
    {
        return [
            1 => ['effectKey' => 'plus_three', 'rarity' => 'common', 'shape' => 'while_in_play', 'rulesText' => 'While in play, this mood\'s value increases by 3.'],
            2 => ['effectKey' => 'double_it', 'rarity' => 'rare', 'shape' => 'while_in_play', 'rulesText' => 'While in play, this mood\'s value is doubled.'],
            3 => ['effectKey' => 'draw_a_card', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => 'After playing this mood, draw a card.'],
        ];
    }

    /** Migration 0183's own five token cards -- not part of CatalogFixture's own "hand-picked slice", which predates Chaos Draft, so merged in here instead of touching that shared fixture. */
    private function catalogWithTokens(): array
    {
        return $this->sampleCatalog() + [
            134 => $this->row('white', 'common', 1, null, 'smugness_token', false, false, false),
            137 => $this->row('red', 'common', 1, null, 'tedium_token', false, false, false),
        ];
    }

    private function boardState(array $hands = [], array $deck = [], ?ChaosEffectRegistry $chaosRegistry = null, array $chaosEffectIdFor = []): BoardState
    {
        return new BoardState(
            $this->catalogWithTokens(),
            DefaultEffectRegistry::build(),
            [1, 2],
            $hands,
            $deck,
            chaosCatalog: $this->chaosCatalog(),
            chaosRegistry: $chaosRegistry ?? new ChaosEffectRegistry(),
            chaosEffectIdFor: $chaosEffectIdFor,
        );
    }

    public function testAttachChaosEffectAndReadItBack(): void
    {
        $state = $this->boardState(hands: [1 => [3]]); // Charity
        $state->moveHandToInPlay(1, 3);

        self::assertNull($state->chaosEffectRow(3));

        $state->attachChaosEffect(3, 1);

        self::assertSame('plus_three', $state->chaosEffectKeyFor(3));
        self::assertSame('common', $state->chaosEffectRow(3)['rarity']);
    }

    public function testAttachingASecondChaosEffectOverwritesTheFirst(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->attachChaosEffect(3, 1);
        $state->attachChaosEffect(3, 2);

        self::assertSame('double_it', $state->chaosEffectKeyFor(3));
    }

    public function testValueOfPipelinesAnAttachedWhileInPlayEffectOnTopOfAPlainBaseValue(): void
    {
        // Apathy (id 55, value 4, no printed ability of its own).
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('plus_three', new class extends AbstractChaosMoodEffect {
            public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
            {
                return $incomingValue + 3;
            }
        });

        $state = $this->boardState(hands: [1 => [55]], chaosRegistry: $chaosRegistry);
        $state->moveHandToInPlay(1, 55);
        $state->attachChaosEffect(55, 1);

        self::assertSame(7, $state->valueOf(55));
    }

    /**
     * Chivalry (id 4, base 3/alt 5, "this mood's value is 5 if you
     * didn't go first this round") -- with player 1 NOT going first, its
     * own printed ability already computes 5; the attached chaos effect
     * then doubles THAT result to 10, proving the printed ability's own
     * computation ran in full first rather than being bypassed.
     */
    public function testValueOfPipelinesAnAttachedEffectOnTopOfTheCardsOwnWhileInPlayComputation(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('double_it', new class extends AbstractChaosMoodEffect {
            public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
            {
                return $incomingValue * 2;
            }
        });

        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [4]],
            chaosCatalog: $this->chaosCatalog(),
            chaosRegistry: $chaosRegistry,
        );
        $state->moveHandToInPlay(1, 4);
        $state->attachChaosEffect(4, 2);
        $state->startRound(2);

        self::assertSame(10, $state->valueOf(4));
    }

    public function testChaosEffectRowReturnsNullWithNothingAttached(): void
    {
        $state = $this->boardState(hands: [1 => [55]]);
        $state->moveHandToInPlay(1, 55);

        self::assertSame(4, $state->valueOf(55));
    }

    public function testSpawnMoodInPlayConjuresATokenWithACorrectCatalogRow(): void
    {
        $state = $this->boardState();

        $tokenId = $state->spawnMoodInPlay(134, 1); // Smugness (white, value 1)

        self::assertLessThan(0, $tokenId);
        self::assertTrue($state->isInPlay($tokenId));
        self::assertSame(1, $state->valueOf($tokenId));
        self::assertSame('white', $state->colorOf($tokenId));
        self::assertSame(1, $state->ownerOf($tokenId));
    }

    public function testSpawningTwoTokensOfTheSameCatalogCardGivesEachItsOwnDistinctId(): void
    {
        $state = $this->boardState();

        $first = $state->spawnMoodInPlay(137, 1); // Tedium
        $second = $state->spawnMoodInPlay(137, 1);

        self::assertNotSame($first, $second);
        self::assertCount(2, $state->moodsInPlay());
    }

    /**
     * Charity (id 3) grants an extra play via its own printed
     * afterPlaying(); a chaos effect attached to it also fires,
     * confirmed by a side effect (drawing a card) neither the base
     * effect nor the play itself would otherwise cause -- "stacks with,
     * doesn't replace" the card's own printed ability.
     */
    public function testAttachedAfterPlayingChaosEffectFiresAlongsideTheCardsOwnBaseEffect(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('draw_a_card', new class extends AbstractChaosMoodEffect {
            public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
            {
                $state->drawCard($playerId);
            }
        });

        $state = $this->boardState(
            hands: [1 => [3]],
            deck: [55],
            chaosRegistry: $chaosRegistry,
        );
        $state->attachChaosEffect(3, 3);
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $result = $service->playMood($state, 1, 3, new PlayerChoices([]));

        self::assertFalse($result->isPending);
        self::assertSame(1, $state->playsRemaining()); // Charity's own extra-play grant still applied
        self::assertContains(55, $state->hand(1)); // the attached chaos effect's own draw also applied
    }

    public function testChaosSubBagIsolatesTheAttachedEffectsOwnChoicesFromTheBaseEffect(): void
    {
        $seenChaosChoice = null;
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('draw_a_card', new class ($seenChaosChoice) extends AbstractChaosMoodEffect {
            public function __construct(private mixed &$seen)
            {
            }

            public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
            {
                $this->seen = $choices->string('note');
            }
        });

        $state = $this->boardState(hands: [1 => [55]], chaosRegistry: $chaosRegistry); // Apathy, no base afterPlaying
        $state->attachChaosEffect(55, 3);
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 55, new PlayerChoices(['chaos' => ['note' => 'hello']]));

        self::assertSame('hello', $seenChaosChoice);
    }
}
