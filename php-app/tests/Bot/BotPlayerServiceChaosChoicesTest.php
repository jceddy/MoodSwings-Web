<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Bot;

use MoodSwings\Bot\BotChoiceResolver;
use MoodSwings\Bot\BotPlayerService;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosDefaultEffectRegistry;
use MoodSwings\Rules\ChaosEffectRegistry;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Tests\Rules\CatalogFixture;
use PHPUnit\Framework\TestCase;

/**
 * Issue #405 follow-up -- a bug caught live: a bot playing a card
 * carrying an attached chaos effect with a genuinely REQUIRED choice
 * (e.g. chaos_099's own "Choose 0, 1, 2, or 3") had no policy for it at
 * all -- ChaosCardChoiceSchema was added (issue #405 first follow-up) so
 * the FRONTEND could prompt a human for it, but BotPlayerService::
 * buildChoicesForCard() never learned to fill the same field, so
 * PlayerChoices::requireInt()/requireString() threw uncaught inside
 * advanceAutomatedTurns(), crashing the whole request the instant a bot
 * happened to draft one of these ~9 effects onto its own card.
 * buildChoicesForCard() now also runs the attached chaos effect's own
 * ChaosCardChoiceSchema fields through the exact same generic,
 * field-shape-driven BotChoiceResolver the card's own fields already use.
 *
 * These use REAL chaos_XXX effect keys (never synthetic ones) since
 * ChaosCardChoiceSchema::SCHEMA is a static, hand-registered map --
 * forEffectKey() returns [] for anything not listed there, so a made-up
 * key would silently exercise nothing.
 */
final class BotPlayerServiceChaosChoicesTest extends TestCase
{
    use CatalogFixture;

    private BotPlayerService $bot;

    protected function setUp(): void
    {
        $this->bot = new BotPlayerService(new BotChoiceResolver());
    }

    private function boardState(array $hands, array $chaosCatalog, array $chaosEffectIdFor, array $activePlayerIds = [1, 2]): BoardState
    {
        return new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            $activePlayerIds,
            $hands,
            chaosCatalog: $chaosCatalog,
            chaosRegistry: new ChaosEffectRegistry(),
            chaosEffectIdFor: $chaosEffectIdFor,
        );
    }

    public function testChooseActionFillsARequiredChaosValueField(): void
    {
        // chaos_099: required 'value' field, min 0 -- the resolver's own
        // generic 'value' policy always picks the field's own minimum.
        $state = $this->boardState(
            hands: [1 => [55]], // Apathy, no ability of its own
            chaosCatalog: [1 => ['effectKey' => 'chaos_099', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => 'Choose 0, 1, 2, or 3.']],
            chaosEffectIdFor: [55 => 1],
        );

        $action = $this->bot->chooseAction($state, [55], 1);

        self::assertSame(55, $action['card_id']);
        self::assertSame(['chaos' => ['value' => 0]], $action['choices']);
    }

    public function testChooseActionFillsARequiredChaosMoodField(): void
    {
        // chaos_006: required 'mood' field, scope 'any', includes_self
        // true -- with no other mood in play, the card being played is
        // itself the only (and thus chosen) legal candidate.
        $state = $this->boardState(
            hands: [1 => [55]],
            chaosCatalog: [1 => ['effectKey' => 'chaos_006', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => 'Move a mood to the bottom of the deck.']],
            chaosEffectIdFor: [55 => 1],
        );

        $action = $this->bot->chooseAction($state, [55], 1);

        self::assertSame(55, $action['card_id']);
        self::assertSame(['chaos' => ['mood_card_id' => 55]], $action['choices']);
    }

    public function testChooseActionDeclinesAnOptionalChaosEffectByDefault(): void
    {
        // chaos_012: "you may discard..., if you do suppress..." -- both
        // fields optional -- a bot never volunteers for either, matching
        // BotChoiceResolver's own "never volunteer for an optional
        // bonus" bias.
        $state = $this->boardState(
            hands: [1 => [55]],
            chaosCatalog: [1 => ['effectKey' => 'chaos_012', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => 'You may discard a card to suppress a mood.']],
            chaosEffectIdFor: [55 => 1],
        );

        $action = $this->bot->chooseAction($state, [55], 1);

        self::assertSame(55, $action['card_id']);
        self::assertArrayNotHasKey('chaos', $action['choices']);
    }

    public function testChooseActionSkipsACardWhoseAttachedChaosFieldHasNoLegalCandidate(): void
    {
        // chaos_086: required 'player' field scoped 'other' -- with no
        // second seat active (a lone hand), there is no legal opponent
        // to name, so the card must be treated as unplayable this way,
        // same as an unfillable BASE field already is (see
        // testChooseActionSkipsACardItCannotLegallyFillAndTriesTheNextOne()
        // in BotPlayerServiceTest.php).
        $state = $this->boardState(
            hands: [1 => [55, 8]], // Apathy (chaos-carrying, unfillable), Dignity (plain, always playable)
            chaosCatalog: [1 => ['effectKey' => 'chaos_086', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => 'Another player gives you a random hand card.']],
            chaosEffectIdFor: [55 => 1],
            activePlayerIds: [1], // a single active player -- no valid "other" target exists
        );

        $action = $this->bot->chooseAction($state, [55, 8], 1);

        self::assertSame(8, $action['card_id'], 'Apathy should be skipped -- its own attached chaos field cannot legally be filled');
    }

    /**
     * End to end with the REAL registry and the REAL chaos_099
     * implementation -- proves the fix all the way through: the bot's
     * own chosen value actually reaches Chaos099Effect::afterPlaying()
     * via choices['chaos']['value'], and MoodPlayService::playMood()
     * completes without the InvalidChoiceException this bug used to
     * throw.
     */
    public function testABotSuccessfullyPlaysARealCardCarryingChaos099WithoutCrashing(): void
    {
        $chaosRegistry = ChaosDefaultEffectRegistry::build();
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [55], 2 => [5]], // Apathy (chaos_099 attached), Complacency -- both value 4, no ability
            chaosCatalog: [1 => ['effectKey' => 'chaos_099', 'rarity' => 'uncommon', 'shape' => 'after_playing', 'rulesText' => "Choose 0, 1, 2, or 3."]],
            chaosRegistry: $chaosRegistry,
            chaosEffectIdFor: [55 => 1],
        );
        $state->moveHandToInPlay(2, 5); // an in-play mood the bot could (but won't, at value 4) discard via its own value-0-3 choice
        $state->startTurn(1);

        $action = $this->bot->chooseAction($state, [55], 1);
        self::assertNotNull($action);
        self::assertSame(0, $action['choices']['chaos']['value']);

        $service = new MoodPlayService(DefaultEffectRegistry::build(), $chaosRegistry);
        $result = $service->playMood($state, 1, $action['card_id'], new PlayerChoices($action['choices']));

        self::assertFalse($result->isPending);
        // Complacency (value 4) doesn't match the chosen value (0), so it
        // stays in play -- the point here isn't chaos_099's own targeting,
        // it's that the play resolved at all instead of throwing.
        self::assertTrue($state->isInPlay(5));
    }
}
