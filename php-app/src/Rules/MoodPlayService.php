<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\Exceptions\IllegalPlayException;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;

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

    public function playMood(BoardState $state, int $playerId, int $cardId, PlayerChoices $choices): PlayResult
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
        // "Any mood" means any mood currently in play (on the table, in
        // front of any player) -- not any of the 133 printed card designs
        // in the abstract -- so the target has to already be in play.
        $copiedCardId = $row['effectKey'] === 'creativity' ? $choices->int('copy_card_id') : null;
        if ($copiedCardId !== null && !$state->isInPlay($copiedCardId)) {
            throw new InvalidChoiceException("Card {$copiedCardId} is not currently in play, so Creativity can't copy it");
        }
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

        return $this->resolveAfterPlayingChain($state, $cardId, $playerId, $choices, $choices, 0);
    }

    /**
     * Resolves (or pauses) one afterPlaying() invocation -- either the
     * played card's own, or one of its Duplicity repeats -- and, once
     * that invocation's mutations are fully applied, chains into whatever
     * comes next (another Duplicity repeat, or the final reaction loop).
     * See continueAfterPlayingChain()/finishAfterPlayingChain() below.
     *
     * $topLevelChoices is always the original request's own choices bag
     * (needed later for the reaction loop, which per MoodEffect's own
     * contract always reads it, never an invocation's own bag).
     * $invocationChoices is THIS invocation's own bag -- identical to
     * $topLevelChoices for the card's own afterPlaying(), or the
     * duplicity_repeat_choices sub-bag for a repeat.
     */
    private function resolveAfterPlayingChain(
        BoardState $state,
        int $cardId,
        int $playerId,
        PlayerChoices $topLevelChoices,
        PlayerChoices $invocationChoices,
        int $invocationSeq,
    ): PlayResult {
        $effectiveRow = $state->catalogRow($state->effectiveCardId($cardId));
        if (!$effectiveRow['hasAfterPlaying']) {
            return $this->finishAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices);
        }

        $effectiveEffectKey = $effectiveRow['effectKey'];
        $effect = $this->registry->for($effectiveEffectKey);

        if ($effect instanceof RequiresOpponentDecision) {
            $pendingDecisions = $effect->pendingDecisionsFor($state, $cardId, $playerId, $invocationChoices);
            if ($pendingDecisions !== []) {
                return PlayResult::pending($pendingDecisions, $cardId, $invocationSeq);
            }
            // Nothing to ask for this specific play (e.g. declined, or no
            // qualifying target/candidate) -- same as an ordinary no-op
            // afterPlaying().
            $effect->resolveDecisions($state, $cardId, $playerId, $invocationChoices, []);
        } else {
            $effect->afterPlaying($state, $cardId, $playerId, $invocationChoices);
        }

        return $this->continueAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices, $invocationSeq);
    }

    /**
     * Resumes a play that paused in resolveAfterPlayingChain() once every
     * PendingDecisionRequest from that invocation has an answer -- called
     * by GameService::respondToDecision() once a batch's last row
     * resolves. $answers is keyed by each PendingDecisionRequest's own
     * $key, one PlayerChoices per answer.
     *
     * @param array<string, PlayerChoices> $answers
     */
    public function resolvePendingDecisions(
        BoardState $state,
        int $cardId,
        int $playerId,
        PlayerChoices $topLevelChoices,
        PlayerChoices $invocationChoices,
        int $invocationSeq,
        array $answers,
    ): PlayResult {
        $effectiveEffectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];
        $effect = $this->registry->for($effectiveEffectKey);
        if (!$effect instanceof RequiresOpponentDecision) {
            throw new IllegalPlayException("Effect '{$effectiveEffectKey}' has no pending decisions to resolve");
        }

        $effect->resolveDecisions($state, $cardId, $playerId, $invocationChoices, $answers);

        return $this->continueAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices, $invocationSeq);
    }

    /**
     * Duplicity: "each time you play another mood, you may have that
     * mood's after-playing effect happen an additional time." This needs
     * the registry (which no MoodEffect implementation has access to) to
     * re-invoke the just-played card's own effect, so it's handled
     * directly here rather than through reactToAnotherPlay() -- with a
     * nested sub-choices bag, since the repeat is a fresh decision that
     * usually can't reuse the same choices verbatim (e.g. a specific card
     * already discarded once can't be discarded again). Only the played
     * card's own (invocationSeq 0) afterPlaying() can be repeated once --
     * a repeat of a repeat was never offered by the choices schema either.
     */
    private function continueAfterPlayingChain(
        BoardState $state,
        int $cardId,
        int $playerId,
        PlayerChoices $topLevelChoices,
        int $invocationSeq,
    ): PlayResult {
        $effectiveEffectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];

        if (
            $invocationSeq === 0
            && $effectiveEffectKey !== 'duplicity'
            && $topLevelChoices->bool('duplicity_repeat')
            && $state->playerHasMoodInPlay($playerId, 'duplicity')
        ) {
            return $this->resolveAfterPlayingChain(
                $state,
                $cardId,
                $playerId,
                $topLevelChoices,
                $topLevelChoices->sub('duplicity_repeat_choices'),
                $invocationSeq + 1,
            );
        }

        return $this->finishAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices);
    }

    /**
     * Scorn/Validation's "each time you play another mood" reacts to
     * *this* player's own subsequent plays, using the same top-level
     * PlayerChoices submitted for this play -- see
     * MoodEffect::reactToAnotherPlay(). registry->has() guards against an
     * as-yet-unimplemented card the player happens to also own; for every
     * other (registered) mood this is a no-op inherited from
     * AbstractMoodEffect. Never itself needs another player's input --
     * always the last step once nothing else is pending.
     */
    private function finishAfterPlayingChain(BoardState $state, int $cardId, int $playerId, PlayerChoices $topLevelChoices): PlayResult
    {
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($mood->cardId === $cardId) {
                continue;
            }
            $reactorEffectKey = $state->catalogRow($state->effectiveCardId($mood->cardId))['effectKey'];
            if ($this->registry->has($reactorEffectKey)) {
                $this->registry->for($reactorEffectKey)->reactToAnotherPlay($state, $mood->cardId, $cardId, $playerId, $topLevelChoices);
            }
        }

        return PlayResult::complete();
    }

    /**
     * Whether $cardId, sitting in $playerId's hand right now, could
     * legally be played this instant -- the same guard clauses
     * playMood() checks before any effect-specific choice is even asked
     * for, without actually playing anything. GameService uses this to
     * tell the client which hand cards are worth offering a Play button
     * for at all: e.g. Intimidation's grant only covers the one specific
     * card it revealed (BoardState::hasUsablePlayGrant()), so every other
     * hand card correctly comes back false while that grant is
     * outstanding.
     *
     * If the card has a "to play" cost, that cost also has to be payable
     * in principle: every canPayToPlayCost() implementation only checks
     * board-state feasibility (e.g. Guile needs two *other* hand cards to
     * discard), never the specific choices passed to it, so probing with
     * an empty PlayerChoices is safe here.
     *
     * Creativity is a documented exception: its own raw hasToPlay is
     * always false (it has no printed cost of its own), so this can't
     * account for whatever cost a copied card might turn out to have --
     * copy_card_id is only chosen once the play is actually submitted,
     * the same "resolved client-side in the same request" gap noted on
     * Duplicity's repeat field and Scorn/Validation's reactions (see
     * CardChoiceSchema's docblock). A doomed Creativity-copy attempt
     * still surfaces the usual server-side rejection at submit time.
     */
    public function isPlayable(BoardState $state, int $playerId, int $cardId): bool
    {
        if ($state->currentPlayerId() !== $playerId) {
            return false;
        }
        if (in_array($state->colorOf($cardId), $state->bannedColorsThisRound(), true)) {
            return false;
        }
        if (!$state->hasUsablePlayGrant($cardId, $playerId)) {
            return false;
        }

        $row = $state->catalogRow($cardId);
        if ($row['hasToPlay'] && !$this->registry->for($row['effectKey'])->canPayToPlayCost($state, $cardId, $playerId, new PlayerChoices([]))) {
            return false;
        }

        return true;
    }
}
