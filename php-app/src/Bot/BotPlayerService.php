<?php

declare(strict_types=1);

namespace MoodSwings\Bot;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\CardChoiceSchema;

/**
 * Decides a practice bot's (issue #140) own action -- what to play (and
 * with what choices) or, if nothing's worth playing, that it should pass;
 * and, separately, how to answer a pending decision targeting it.
 * Deliberately "legal, not strategic" -- see BotChoiceResolver's own
 * docblock for the field-filling policy this builds on. GameService is
 * the only caller (see its own "Practice bots" section in
 * php-app/README.md for how this fits into the request lifecycle) --
 * legality itself (MoodPlayService::isPlayable()) is GameService's own
 * call to make, not this class's, since GameService already holds that
 * dependency; $playableCardIds below is expected to already be filtered
 * down to cards $botGamePlayerId could legally play right now.
 */
final class BotPlayerService
{
    public function __construct(
        private readonly BotChoiceResolver $resolver,
    ) {
    }

    /**
     * The highest-printed-value card in $playableCardIds (a plain-and-
     * simple stand-in for "which play matters most" -- see this class's
     * own docblock), with only that card's own REQUIRED choice_fields
     * filled in -- optional ones are left alone (the same "don't
     * volunteer for a bonus/cost nobody asked for" bias BotChoiceResolver
     * itself applies per field), except for BotChoiceResolver's own small
     * ALWAYS_FILLED_OPTIONAL_FIELDS list (Curiosity/Suspicion today),
     * which get filled in anyway via buildChoicesForCard()'s own
     * required-or-forced check. If the highest-value card's own required
     * fields can't all be legally filled (rare -- would mean
     * isPlayable() said yes but some required field still came up
     * empty), the next-highest is tried instead, all the way down to
     * passing if truly nothing works.
     *
     * @param int[] $playableCardIds
     * @return ?array{card_id: int, choices: array<string, mixed>} null means pass.
     */
    public function chooseAction(BoardState $state, array $playableCardIds, int $botGamePlayerId): ?array
    {
        usort(
            $playableCardIds,
            fn (int $a, int $b) => $this->baseValue($state, $b) <=> $this->baseValue($state, $a),
        );

        foreach ($playableCardIds as $cardId) {
            $choices = $this->buildChoicesForCard($state, $cardId, $botGamePlayerId);
            if ($choices !== null) {
                return ['card_id' => $cardId, 'choices' => $choices];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function chooseDecisionAnswer(BoardState $state, array $field, int $botGamePlayerId): array
    {
        $value = $this->resolver->resolve($state, $field, $botGamePlayerId, 0, '');

        return $value === null ? [] : [$field['key'] => $value];
    }

    private function baseValue(BoardState $state, int $cardId): int
    {
        return $state->catalogRow($state->effectiveCardId($cardId))['baseValue'];
    }

    /** @return ?array<string, mixed> */
    private function buildChoicesForCard(BoardState $state, int $cardId, int $botGamePlayerId): ?array
    {
        $effectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];
        $choices = [];

        foreach (CardChoiceSchema::forEffectKey($effectKey) as $field) {
            $required = ($field['required'] ?? false) === true;
            $forced = !$required && $this->resolver->isAlwaysFilledOptionalField($effectKey, $field['key']);
            if (!$required && !$forced) {
                continue;
            }

            $value = $this->resolver->resolve($state, $field, $botGamePlayerId, $cardId, $effectKey);
            if ($value === null) {
                // A required field with no legal value makes the whole
                // card unplayable this way (existing behavior). A forced
                // OPTIONAL field (see ALWAYS_FILLED_OPTIONAL_FIELDS) with
                // no legal candidate -- e.g. Suspicion when every other
                // player's hand is empty -- just stays unfilled instead;
                // the card is still perfectly playable without it, same
                // as if it had never been forced at all.
                if ($required) {
                    return null;
                }

                continue;
            }

            $choices[$field['key']] = $value;
        }

        return $choices;
    }
}
