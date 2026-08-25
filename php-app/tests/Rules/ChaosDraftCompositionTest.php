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

    /**
     * Duplicity follow-up (issue #405 follow-up -- a bug caught live):
     * "each time you play another mood, you may have that mood's
     * after-playing effect happen an additional time" only ever repeated
     * the card's own PRINTED after-playing effect, never an attached
     * chaos effect layered on top of it -- even though from the player's
     * perspective playing the card triggers both (see
     * testAttachedAfterPlayingChaosEffectFiresAlongsideTheCardsOwnBaseEffect()
     * above for the non-repeated "stacks with" baseline this builds on).
     * Charity (id 3) grants an extra play via its own printed
     * afterPlaying(); with 'draw_a_card' attached, playing it once should
     * draw a card from BOTH the base effect's own extra-play grant AND
     * the attached chaos effect -- and accepting Duplicity's own repeat
     * offer should draw a SECOND card from the attached chaos effect too,
     * not just replay the base grant.
     */
    public function testDuplicityRepeatsAnAttachedAfterPlayingChaosEffectAlongsideTheCardsOwnBaseEffect(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('draw_a_card', new class extends AbstractChaosMoodEffect {
            public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
            {
                $state->drawCard($playerId);
            }
        });

        $state = $this->boardState(
            hands: [1 => [37, 3]], // Duplicity, Charity (own afterPlaying: grants an extra play)
            deck: [55, 5], // two draws expected: the original play's own attached-chaos draw, then the repeat's
            chaosRegistry: $chaosRegistry,
        );
        $state->attachChaosEffect(3, 3); // 'draw_a_card' attached to Charity
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $service->playMood($state, 1, 37, new PlayerChoices([])); // Duplicity

        $result = $service->playMood($state, 1, 3, new PlayerChoices([])); // Charity, chaos attached
        self::assertTrue($result->isPending, "Duplicity should offer to repeat Charity's own after-playing effect");
        self::assertContains(55, $state->hand(1), "the attached chaos effect's own draw should already have applied once");
        self::assertNotContains(5, $state->hand(1), 'the repeat has not been accepted yet');

        $finalResult = $service->resolvePendingDecisions(
            $state, 3, 1, new PlayerChoices([]), new PlayerChoices([]), 0,
            ['duplicity_repeat' => new PlayerChoices(['duplicity_repeat' => ['repeat' => true, 'choices' => []]])],
            0,
        );

        self::assertFalse($finalResult->isPending);
        self::assertContains(55, $state->hand(1));
        self::assertContains(5, $state->hand(1), "the repeat should have fired the attached chaos effect a second time");
    }

    /**
     * Creativity follow-up (issue #405 follow-up -- a maintainer ruling
     * reversing chaosEffectRow()'s own original design): "an exact copy
     * of that printed card... including dice, color, and abilities" now
     * also covers whatever chaos effect is attached to the copied card --
     * confirmed via valueOf(), which pipelines a 'while_in_play' attached
     * effect's own computeValue() the same way testValueOfPipelines*()
     * above already proves for a plain (non-copying) card.
     */
    public function testCreativityCopyingAMoodInheritsItsAttachedWhileInPlayChaosEffect(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('plus_three', new class extends AbstractChaosMoodEffect {
            public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
            {
                return $incomingValue + 3;
            }
        });

        $state = $this->boardState(hands: [1 => [32], 2 => [55]], chaosRegistry: $chaosRegistry); // Creativity; Apathy
        $state->moveHandToInPlay(2, 55); // Apathy (value 4, no ability) -- the copy target
        $state->attachChaosEffect(55, 1); // 'plus_three' attached to Apathy, not Creativity

        $state->moveHandToInPlay(1, 32, 55); // Creativity, copying Apathy

        self::assertSame('plus_three', $state->chaosEffectKeyFor(32), "Creativity's own effective chaos effect should be Apathy's");
        self::assertSame(7, $state->valueOf(32), '4 (copied base value) + 3 (inherited chaos effect)');
    }

    /**
     * The other half of the same ruling: a Creativity NOT currently
     * copying anything (effectiveCardId() is a no-op for it) still keeps
     * its OWN attached chaos effect exactly as before -- nothing to
     * replace it with when there's no copy target.
     */
    public function testCreativityWithNoCopyTargetKeepsItsOwnAttachedChaosEffect(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('plus_three', new class extends AbstractChaosMoodEffect {
            public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
            {
                return $incomingValue + 3;
            }
        });

        $state = $this->boardState(hands: [1 => [32]], chaosRegistry: $chaosRegistry);
        $state->moveHandToInPlay(1, 32); // Creativity, played with no copy target -- "just a blue card worth 0"
        $state->attachChaosEffect(32, 1); // 'plus_three' attached directly to Creativity itself

        self::assertSame('plus_three', $state->chaosEffectKeyFor(32));
        self::assertSame(3, $state->valueOf(32), '0 (own base value) + 3 (own attached chaos effect)');
    }

    /**
     * The maintainer's own confirmed answer to the "which one wins"
     * question this ruling raises: copying is a FULL replacement, not a
     * stack -- Creativity's own separately-attached chaos effect is
     * ignored (not combined) while it's actively copying a card that
     * carries a different one, the same way copying already fully
     * replaces Creativity's own (nonexistent) base value/ability rather
     * than adding to it.
     */
    public function testCreativityCopyingAMoodWithAnAttachedChaosEffectReplacesItsOwnRatherThanStacking(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('plus_three', new class extends AbstractChaosMoodEffect {
            public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
            {
                return $incomingValue + 3;
            }
        });
        $chaosRegistry->register('double_it', new class extends AbstractChaosMoodEffect {
            public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
            {
                return $incomingValue * 2;
            }
        });

        $state = $this->boardState(hands: [1 => [32], 2 => [55]], chaosRegistry: $chaosRegistry);
        $state->moveHandToInPlay(2, 55); // Apathy (value 4) -- the copy target
        $state->attachChaosEffect(55, 2); // 'double_it' attached to Apathy

        $state->moveHandToInPlay(1, 32, 55); // Creativity, copying Apathy
        $state->attachChaosEffect(32, 1); // 'plus_three' attached to Creativity itself -- should be ignored while copying

        self::assertSame('double_it', $state->chaosEffectKeyFor(32), "Apathy's chaos effect should fully replace Creativity's own, not add to it");
        self::assertSame(8, $state->valueOf(32), '4 (copied base value) * 2 (Apathy\'s effect) -- NOT +3 and NOT both applied');
    }

    /**
     * End to end through MoodPlayService: an 'after_playing'-shaped
     * attached chaos effect fires as part of playing Creativity's own
     * copy, the same way testAttachedAfterPlayingChaosEffectFiresAlongsideTheCardsOwnBaseEffect()
     * above already proves for a plain (non-copying) card -- confirming
     * resolveAttachedChaosAfterPlaying()'s own chaosEffectRow($cardId)
     * lookup correctly resolves through Creativity's own copiedCardId.
     */
    public function testCreativityCopyingAMoodAlsoFiresItsAttachedAfterPlayingChaosEffect(): void
    {
        $chaosRegistry = new ChaosEffectRegistry();
        $chaosRegistry->register('draw_a_card', new class extends AbstractChaosMoodEffect {
            public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
            {
                $state->drawCard($playerId);
            }
        });

        $state = $this->boardState(
            hands: [1 => [32], 2 => [55]], // Creativity; Apathy
            deck: [5], // Complacency, drawn by the inherited chaos effect
            chaosRegistry: $chaosRegistry,
        );
        $state->moveHandToInPlay(2, 55); // Apathy -- already in play, the copy target
        $state->attachChaosEffect(55, 3); // 'draw_a_card' attached to Apathy
        $state->startTurn(1);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $result = $service->playMood($state, 1, 32, new PlayerChoices(['copy_card_id' => 55]));

        self::assertFalse($result->isPending);
        self::assertContains(5, $state->hand(1), "Apathy's own attached chaos effect should have fired as part of playing the Creativity copy");
    }
}
