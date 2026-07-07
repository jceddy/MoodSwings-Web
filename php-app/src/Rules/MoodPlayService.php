<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\Exceptions\IllegalPlayException;

/**
 * Resolves playing a single mood from a player's hand (or, for a grant
 * sourced from the discard pile -- see below -- the discard pile),
 * following the Extended Rules' order: pay the "to play this card" cost
 * (if any), move the card into play, then resolve its "after playing this
 * mood" effect (if any). "While in play" effects aren't a separate step
 * here -- they're never cached (see BoardState::valueOf()), so they're
 * always automatically up to date without needing to be explicitly
 * reapplied.
 *
 * Some "play an additional mood" grants are restricted to cards meeting
 * some condition (e.g. Benevolence, Friendliness, Kindness, Eagerness) or
 * sourced from the discard pile instead of hand (e.g. Harmony, Grief,
 * Angst) rather than unconditional like Charity's -- see
 * BoardState::hasUsablePlayGrant()/useGrantFor(). Those checks (and the
 * grant consumption itself) have to happen before the card is moved into
 * play, since the restriction is evaluated against the board as it stood
 * *before* this play (a card always "shares a color with itself", so
 * checking after the move would make color-based restrictions meaningless).
 */
final class MoodPlayService
{
    public function __construct(private readonly EffectRegistry $registry)
    {
    }

    public function playMood(BoardState $state, int $playerId, int $cardId, PlayerChoices $choices): void
    {
        if ($state->currentPlayerId() !== $playerId) {
            throw new IllegalPlayException("It is not player {$playerId}'s turn");
        }
        // Doubt bans specific colors for everyone during the round after
        // it's played, regardless of whose turn it is or which grant would
        // otherwise permit the play -- see BoardState::bannedColorsThisRound().
        if (in_array($state->colorOf($cardId), $state->bannedColorsThisRound(), true)) {
            throw new IllegalPlayException("Card {$cardId}'s color is banned from being played this round");
        }
        if (!$state->hasUsablePlayGrant($cardId, $playerId)) {
            throw new IllegalPlayException("Player {$playerId} has no plays remaining this turn that allow playing card {$cardId}");
        }

        // A card is playable either from hand (the common case) or, if a
        // discard-sourced grant permits it, from the discard pile --
        // hasUsablePlayGrant() above already confirmed one of these
        // actually applies to $cardId's current zone.
        $fromDiscard = $state->isInDiscardPile($cardId);
        if (!$fromDiscard && !$state->isInHand($playerId, $cardId)) {
            throw new IllegalPlayException("Card {$cardId} is not in player {$playerId}'s hand or the discard pile");
        }

        $row = $state->catalogRow($cardId);

        // Creativity is the only card in the game with none of the three
        // ability types; instead, playing it lets you choose to play it as
        // an exact copy of any mood, "including dice, color, and
        // abilities" -- so a Creativity-copy pays the copied card's to-play
        // cost and resolves its after-playing effect too, not Creativity's
        // own (nonexistent) ones. Every other card is always just itself.
        $copiedCardId = $row['effectKey'] === 'creativity' ? $choices->int('copy_card_id') : null;
        $effectiveRow = $copiedCardId !== null ? $state->catalogRow($copiedCardId) : $row;
        $effectiveEffectKey = $effectiveRow['effectKey'];

        if ($effectiveRow['hasToPlay']) {
            $effect = $this->registry->for($effectiveEffectKey);
            if (!$effect->canPayToPlayCost($state, $cardId, $playerId, $choices)) {
                throw new IllegalPlayException("Cannot pay the to-play cost for card {$cardId}");
            }
            $effect->payToPlayCost($state, $cardId, $playerId, $choices);
        }

        $consumedGrant = $state->useGrantFor($cardId, $playerId);
        if ($fromDiscard) {
            $state->moveDiscardToInPlay($playerId, $cardId, $copiedCardId);
        } else {
            $state->moveHandToInPlay($playerId, $cardId, $copiedCardId);
        }

        // Gluttony/Insecurity tag whichever specific card ends up consuming
        // their granted extra play with effectState (e.g. "discard it after
        // scoring") -- see BoardState's 'onUseEffectState' restriction key.
        if ($consumedGrant !== null && isset($consumedGrant['onUseEffectState'])) {
            foreach ($consumedGrant['onUseEffectState'] as $key => $value) {
                $state->setEffectState($cardId, $key, $value);
            }
        }

        // Hope/Grace's "you may play an additional mood during each of
        // your turns, including the turn you play this mood" has no
        // after-playing ability of its own to hook -- their whole ability
        // is "while in play" -- so the same-turn half of it is granted
        // here, the moment either card enters play. GameService's
        // computeFreshGrants() covers every turn after this one, for as
        // long as the card stays in play.
        if ($effectiveEffectKey === 'hope') {
            $state->grantExtraPlay(1);
        } elseif ($effectiveEffectKey === 'grace') {
            $state->grantExtraPlay(1, ['type' => 'shares_color_with_your_moods', 'source' => 'discard']);
        }

        if ($effectiveRow['hasAfterPlaying']) {
            $this->registry->for($effectiveEffectKey)->afterPlaying($state, $cardId, $playerId, $choices);
        }

        // Scorn/Validation's "each time you play another mood" reacts to
        // *this* player's own subsequent plays, using the same
        // PlayerChoices already submitted for this play -- see
        // MoodEffect::reactToAnotherPlay(). registry->has() guards against
        // an as-yet-unimplemented card the player happens to also own;
        // for every other (registered) mood this is a no-op inherited
        // from AbstractMoodEffect.
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($mood->cardId === $cardId) {
                continue;
            }
            $reactorEffectKey = $state->catalogRow($state->effectiveCardId($mood->cardId))['effectKey'];
            if ($this->registry->has($reactorEffectKey)) {
                $this->registry->for($reactorEffectKey)->reactToAnotherPlay($state, $mood->cardId, $cardId, $playerId, $choices);
            }
        }
    }
}
