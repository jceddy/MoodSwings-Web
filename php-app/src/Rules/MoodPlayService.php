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
    /**
     * The key Duplicity's own "repeat again?" pending decision is filed
     * under -- reserved, never used by any card's own RequiresOpponentDecision
     * key (see the 7 existing implementations' own private KEY constants),
     * so resolvePendingDecisions() can tell a repeat-offer answer apart
     * from an ordinary opponent decision's just by checking for it.
     */
    private const DUPLICITY_REPEAT_KEY = 'duplicity_repeat';

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

        // 'grant_source_card_id' (see GameService::grantChoiceOptions()) is
        // only ever offered/submitted when 2+ distinct grants would
        // actually work for this play, and is optional even then -- left
        // unset, useGrantFor() falls back to its old "whichever comes
        // first" behavior. Validated explicitly against usableGrants()
        // (not just handed straight to useGrantFor()) so a stale or
        // fabricated preference (the one grant it names having since been
        // consumed or lost -- see BoardState::grantIsActive()) is rejected
        // outright rather than silently falling through to consuming some
        // *other* grant the player never chose.
        $preferredGrantSourceCardId = $choices->int('grant_source_card_id');
        if ($preferredGrantSourceCardId !== null) {
            $usableSourceCardIds = array_map(
                static fn (?array $g) => $g['sourceCardId'] ?? 0,
                $state->usableGrants($cardId, $playerId),
            );
            if (!in_array($preferredGrantSourceCardId, $usableSourceCardIds, true)) {
                throw new InvalidChoiceException("Grant sourced from card {$preferredGrantSourceCardId} is not currently usable for playing card {$cardId}");
            }
        }

        $consumedGrant = $state->useGrantFor($cardId, $playerId, $preferredGrantSourceCardId);
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
            $state->grantExtraPlay(1, ['requiresSourceInPlay' => true], $cardId);
        } elseif ($effectiveEffectKey === 'grace') {
            $state->grantExtraPlay(1, ['type' => 'shares_color_with_your_moods', 'source' => 'discard', 'requiresSourceInPlay' => true], $cardId);
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
     * $topLevelChoices for the card's own afterPlaying(), or the answered
     * repeat-offer's own "choices" sub-bag for a repeat (see
     * resolveDuplicityRepeatOffer()).
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
                return PlayResult::pending($pendingDecisions, $cardId, $invocationSeq, $invocationChoices);
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
     * Resumes a play that paused in resolveAfterPlayingChain() (an
     * opponent's own decision) or continueAfterPlayingChain() (Duplicity's
     * "repeat again?" offer, answered by the acting player themselves)
     * once every PendingDecisionRequest from that invocation has an
     * answer -- called by GameService::respondToDecision() once a batch's
     * last row resolves. $answers is keyed by each PendingDecisionRequest's
     * own $key, one PlayerChoices per answer.
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
        if (isset($answers[self::DUPLICITY_REPEAT_KEY])) {
            return $this->resolveDuplicityRepeatOffer($state, $cardId, $playerId, $topLevelChoices, $invocationSeq, $answers[self::DUPLICITY_REPEAT_KEY]);
        }

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
     * directly here rather than through reactToAnotherPlay(). Every mood
     * the acting player owns whose EFFECTIVE effect key is 'duplicity'
     * (a real Duplicity, or a Creativity currently copying one) grants its
     * own independent repeat -- $invocationSeq already counts how many
     * repeats have happened so far (1 = after the first repeat, etc.), so
     * comparing it against the current count of such moods in play caps
     * the chain at exactly that many, however many there turn out to be,
     * rather than the old hard "exactly one, ever" limit.
     *
     * The printed text triggers on "ANOTHER mood" -- so a Duplicity-
     * effective source never offers to repeat its OWN just-played
     * instance via itself, but a *different*, already-in-play
     * Duplicity-effective source still can (e.g. playing the real
     * Duplicity while a Creativity is already copying one lets that
     * Creativity offer one repeat of the just-played Duplicity's own
     * "grant an extra play" effect). Since the just-played card itself
     * is already counted in $duplicitySources once it's a
     * Duplicity-effective card in its own right, subtracting one from the
     * eligible count in that case excludes only itself, not any other
     * source.
     *
     * Rather than a flat pre-submitted boolean (the old design), this is
     * itself a pending decision targeting the ACTING player -- see
     * PendingDecisionRequest's own docblock for why a same-player decision
     * still needs the same durable pause: the player might not want to
     * commit to every repeat's choices up front, especially since a later
     * repeat's own valid candidates can depend on what an earlier one just
     * did (e.g. a card an earlier repeat discarded is no longer a valid
     * hand-card choice for a later one) -- something only the server, not
     * a pre-rendered form, can know for certain at each step.
     */
    private function continueAfterPlayingChain(
        BoardState $state,
        int $cardId,
        int $playerId,
        PlayerChoices $topLevelChoices,
        int $invocationSeq,
    ): PlayResult {
        $effectiveEffectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];
        $duplicitySources = $state->countMoodsInPlayWithEffectiveKey($playerId, 'duplicity');
        $eligibleSources = $effectiveEffectKey === 'duplicity' ? $duplicitySources - 1 : $duplicitySources;

        if ($invocationSeq < $eligibleSources) {
            // The repeat-offer's own answer is resolved directly by
            // resolveDuplicityRepeatOffer() below, which never reads the
            // batch's stored invocation_choices -- $topLevelChoices here
            // is just a harmless, always-valid placeholder to satisfy
            // PlayResult::pending()'s signature.
            return PlayResult::pending([$this->duplicityRepeatOfferRequest($playerId, $effectiveEffectKey)], $cardId, $invocationSeq, $topLevelChoices);
        }

        return $this->finishAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices);
    }

    /**
     * Builds Duplicity's own "repeat again?" PendingDecisionRequest: a
     * single nested field wrapping a plain "repeat?" checkbox
     * (CardChoiceSchema::REACTIONS['duplicity']'s own label, reused so the
     * wording matches what a still-in-progress play's own hand-card panel
     * already used before this pause-based redesign) alongside a second
     * nested "choices" field carrying $effectiveEffectKey's own
     * afterPlayingFields() -- the same shape the repeated card's own
     * after-playing choices always take, cost fields excluded since a
     * repeat never re-pays a "to play" cost.
     */
    private function duplicityRepeatOfferRequest(int $playerId, string $effectiveEffectKey): PendingDecisionRequest
    {
        $template = CardChoiceSchema::reactionTemplate('duplicity');

        return new PendingDecisionRequest(
            key: self::DUPLICITY_REPEAT_KEY,
            targetPlayerId: $playerId,
            decisionType: 'duplicity_repeat_offer',
            field: [
                'key' => self::DUPLICITY_REPEAT_KEY,
                'type' => 'nested',
                'required' => false,
                'label' => $template['label'],
                'fields' => [
                    ['key' => 'repeat', 'type' => 'bool', 'required' => false, 'label' => 'Repeat again?'],
                    [
                        'key' => 'choices',
                        'type' => 'nested',
                        'required' => false,
                        'label' => 'Choices for the repeat (only used if repeating above)',
                        'fields' => CardChoiceSchema::afterPlayingFields($effectiveEffectKey),
                    ],
                ],
            ],
        );
    }

    /**
     * Resolves Duplicity's own "repeat again?" answer -- $repeatAnswer is
     * keyed the same way collectAnswers() keys every other answer (by the
     * PendingDecisionRequest's own $key), so reading it back out needs the
     * same key again, matching every RequiresOpponentDecision
     * implementation's own resolveDecisions() convention exactly (see e.g.
     * CompulsionEffect).
     */
    private function resolveDuplicityRepeatOffer(
        BoardState $state,
        int $cardId,
        int $playerId,
        PlayerChoices $topLevelChoices,
        int $invocationSeq,
        PlayerChoices $repeatAnswer,
    ): PlayResult {
        $repeatBag = $repeatAnswer->sub(self::DUPLICITY_REPEAT_KEY);
        if (!$repeatBag->bool('repeat')) {
            return $this->finishAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices);
        }

        return $this->resolveAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices, $repeatBag->sub('choices'), $invocationSeq + 1);
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
     * Creativity is a partial exception: its own raw hasToPlay is always
     * false (it has no printed cost of its own), so this can't account
     * for whatever cost a copied card might turn out to have -- that's
     * still correct here, since Creativity itself is always offered a
     * Play button regardless of what it might end up copying.
     * GameService's copy_simulation (via canPayCopiedToPlayCost() below)
     * covers the narrower, copy_card_id-specific question once the panel
     * is actually open, dynamically, without a round trip.
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

    /**
     * Whether $creativityCardId (still in $playerId's hand right now,
     * about to be played as a copy of $copiedCardId) could pay
     * $copiedCardId's own "to play" cost -- mirrors playMood()'s own
     * cost check exactly, including passing $creativityCardId (not
     * $copiedCardId) as the effect's own $cardId, since that's what
     * playMood() itself does (GuileEffect/BlissEffect's canPayToPlayCost()
     * exclude that id from the hand -- Creativity's own id is correct
     * there, since Creativity is what's actually being played and will
     * occupy that hand slot). Side-effect-free and safe to call
     * speculatively, same as isPlayable() above.
     */
    public function canPayCopiedToPlayCost(BoardState $state, int $playerId, int $creativityCardId, int $copiedCardId): bool
    {
        $row = $state->catalogRow($copiedCardId);
        if (!$row['hasToPlay']) {
            return true;
        }

        return $this->registry->for($row['effectKey'])->canPayToPlayCost($state, $creativityCardId, $playerId, new PlayerChoices([]));
    }
}
