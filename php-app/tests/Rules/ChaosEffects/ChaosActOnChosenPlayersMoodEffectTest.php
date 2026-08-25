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
 * chaos_007/020/028/048/076/101, all backed by
 * ChaosActOnChosenPlayersMoodEffect -- a maintainer ruling reversing this
 * class's own original design (see its docblock): the acting player now
 * names the target moods directly (`target_mood_ids`) instead of naming
 * players and having the engine pick a random qualifying mood per player.
 * Uses the REAL ChaosDefaultEffectRegistry registrations (not throwaway
 * test doubles, unlike ChaosDraftCompositionTest.php) since the exact
 * $action/$qualifies/$excludeThisCard wiring per effect_key is itself part
 * of what's under test.
 */
final class ChaosActOnChosenPlayersMoodEffectTest extends TestCase
{
    use CatalogFixture;

    /** Fake chaos ids -> the real registered effect_keys under test. */
    private function chaosCatalog(): array
    {
        return [
            1 => ['effectKey' => 'chaos_007', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''], // discard, value >= 5
            2 => ['effectKey' => 'chaos_020', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''], // suppress, no filter
            3 => ['effectKey' => 'chaos_028', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''], // hand, odd value
            4 => ['effectKey' => 'chaos_048', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''], // hand, excludeThisCard
            5 => ['effectKey' => 'chaos_101', 'rarity' => 'common', 'shape' => 'after_playing', 'rulesText' => ''], // discard, value <= 3
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

    private function play(BoardState $state, int $playerId, int $cardId, array $targetMoodIds): void
    {
        $state->startTurn($playerId);
        $service = new MoodPlayService(DefaultEffectRegistry::build(), ChaosDefaultEffectRegistry::build());
        $service->playMood($state, $playerId, $cardId, new PlayerChoices(['chaos' => ['target_mood_ids' => $targetMoodIds]]));
    }

    /**
     * The actual bug reported: with two qualifying moods available for the
     * SAME player, the engine used to pick one at random rather than
     * letting the acting player decide -- proven here by choosing
     * Generosity (120) over Betrayal (56), both owned by player 2 and both
     * qualifying, and confirming Generosity alone was affected.
     */
    public function testChoosingASpecificMoodAffectsExactlyThatOneNotAnotherQualifyingMoodOwnedByTheSamePlayer(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [56, 120]]); // Apathy; Betrayal (6), Generosity (6)
        $state->moveHandToInPlay(2, 56);
        $state->moveHandToInPlay(2, 120);
        $state->attachChaosEffect(55, 1); // chaos_007: discard, value >= 5

        $this->play($state, 1, 55, [120]);

        self::assertFalse($state->isInPlay(120), 'the chosen mood should be discarded');
        self::assertTrue($state->isInPlay(56), 'the other qualifying mood, not chosen, should be untouched');
    }

    public function testDiscardActionAffectsUpToTwoChosenMoodsFromDistinctOwners(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [56], 3 => [120]]); // Apathy; Betrayal (6); Generosity (6)
        $state->moveHandToInPlay(2, 56);
        $state->moveHandToInPlay(3, 120);
        $state->attachChaosEffect(55, 1); // chaos_007

        $this->play($state, 1, 55, [56, 120]);

        self::assertFalse($state->isInPlay(56));
        self::assertFalse($state->isInPlay(120));
        self::assertEqualsCanonicalizing([56, 120], $state->discardPile());
    }

    public function testSuppressActionSuppressesTheChosenMood(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [5]]); // Apathy; Complacency (4, no filter needed)
        $state->moveHandToInPlay(2, 5);
        $state->attachChaosEffect(55, 2); // chaos_020: suppress, no filter

        $this->play($state, 1, 55, [5]);

        self::assertTrue($state->isSuppressed(5));
    }

    public function testHandActionReturnsTheChosenQualifyingMoodToItsOwnersHand(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [7]]); // Apathy; Courage (1, odd)
        $state->moveHandToInPlay(2, 7);
        $state->attachChaosEffect(55, 3); // chaos_028: hand, odd value

        $this->play($state, 1, 55, [7]);

        self::assertFalse($state->isInPlay(7));
        self::assertContains(7, $state->hand(2));
    }

    public function testExcludeThisCardRejectsTargetingItself(): void
    {
        $state = $this->boardState(hands: [1 => [55]]);
        $state->attachChaosEffect(55, 4); // chaos_048: hand, excludeThisCard: true

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 55, [55]);
    }

    public function testQualifiesFilterRejectsANonQualifyingTarget(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [9]]); // Apathy; Discipline (6, value >= 5 required)
        $state->moveHandToInPlay(2, 9);
        $state->attachChaosEffect(55, 5); // chaos_101: discard, value <= 3 -- Discipline's 6 doesn't qualify

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 55, [9]);
    }

    public function testDistinctOwnersConstraintRejectsTwoMoodsFromTheSamePlayer(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [56, 120]]); // Apathy; Betrayal (6), Generosity (6)
        $state->moveHandToInPlay(2, 56);
        $state->moveHandToInPlay(2, 120);
        $state->attachChaosEffect(55, 1); // chaos_007

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 55, [56, 120]);
    }

    public function testChoosingMoreThanTheMaxPlayersIsRejected(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [56], 3 => [120]]);
        $state->moveHandToInPlay(2, 56);
        $state->moveHandToInPlay(3, 120);
        $state->attachChaosEffect(55, 1); // chaos_007, maxPlayers: 2

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 55, [55, 56, 120]);
    }

    public function testChoosingNoMoodsIsLegalAndAffectsNothing(): void
    {
        $state = $this->boardState(hands: [1 => [55], 2 => [56]]);
        $state->moveHandToInPlay(2, 56);
        $state->attachChaosEffect(55, 1); // chaos_007

        $this->play($state, 1, 55, []);

        self::assertTrue($state->isInPlay(56));
        self::assertSame([], $state->discardPile());
    }
}
