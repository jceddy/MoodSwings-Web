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
 * checking after the move would make color-based restrictions meaningless)
 * -- and, for the same reason, before the "to play" cost (if any) is
 * paid too: a card whose own cost removes the player's own moods (e.g.
 * Regret) could otherwise silently invalidate a color-conditional grant
 * that was legitimately usable right up until that cost was paid -- see
 * playMood()'s own comment just above where the grant is consumed for
 * the full explanation.
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

    /**
     * $cardNames, when given, maps this game's own card instance ids to
     * their printed names (see GameService::cardNamesFor()) purely so the
     * 'grant_source_card_id' validation below can name both cards in its
     * exception message instead of showing their bare ids -- the one
     * user-facing message in this whole class, since every other
     * exception here reports a rules violation a well-behaved client
     * should never actually trigger (see the class docblock: BoardState
     * itself, and so this whole service, is deliberately kept unaware of
     * anything DB-backed, including card names -- left null, as every
     * caller other than GameService does, falls back to the bare id the
     * same way every other exception in this class already does).
     *
     * @param ?array<int, string> $cardNames
     */
    public function playMood(BoardState $state, int $playerId, int $cardId, PlayerChoices $choices, ?array $cardNames = null): PlayResult
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
        $rawCopyTargetId = $row['effectKey'] === 'creativity' ? $choices->int('copy_card_id') : null;
        if ($rawCopyTargetId !== null && !$state->isInPlay($rawCopyTargetId)) {
            throw new InvalidChoiceException("Card {$rawCopyTargetId} is not currently in play, so Creativity can't copy it");
        }
        // Resolved through the WHOLE chain (BoardState::effectiveCardId()),
        // not just the one card actually targeted: copying a Creativity
        // that's itself copying, say, Paranoia is a copy of Paranoia --
        // "an exact copy of that printed card" -- not a copy of Creativity
        // (which would make it just a blank blue 0). Storing the fully
        // resolved id here (rather than the raw target) also means the
        // copy's own identity stays correct even if the intermediate
        // Creativity it was actually pointed at later leaves play.
        $copiedCardId = $rawCopyTargetId !== null ? $state->effectiveCardId($rawCopyTargetId) : null;
        $effectiveRow = $copiedCardId !== null ? $state->catalogRow($copiedCardId) : $row;
        $effectiveEffectKey = $effectiveRow['effectKey'];

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
        //
        // This has to happen BEFORE the cost is paid below, not after: a
        // conditional grant (e.g. Eagerness's/Grace's own "shares a color
        // with one of your moods") is re-checked live against the CURRENT
        // board every time hasUsablePlayGrant()/usableGrants()/
        // useGrantFor() run. A card whose own cost returns/discards the
        // player's own moods -- Regret's "return two of your own moods to
        // hand" is the clearest example -- can, by paying that cost,
        // remove the very moods that made such a grant usable in the
        // first place. hasUsablePlayGrant()'s own gate above already ran
        // against the board as it stood *before* any cost was paid, so
        // consuming the grant against that same pre-cost snapshot (by
        // doing it here, first) keeps both checks consistent; consuming
        // it after paying the cost let a play sail through the initial
        // gate on a grant that then silently failed to actually get
        // consumed, leaving it sitting unconsumed and available for an
        // extra, unearned play afterward.
        $preferredGrantSourceCardId = $choices->int('grant_source_card_id');
        if ($preferredGrantSourceCardId !== null) {
            $usableSourceCardIds = array_map(
                static fn (?array $g) => $g['sourceCardId'] ?? 0,
                $state->usableGrants($cardId, $playerId),
            );
            if (!in_array($preferredGrantSourceCardId, $usableSourceCardIds, true)) {
                $grantLabel = $cardNames[$preferredGrantSourceCardId] ?? "card {$preferredGrantSourceCardId}";
                $cardLabel = $cardNames[$cardId] ?? "card {$cardId}";
                throw new InvalidChoiceException("Grant sourced from {$grantLabel} is not currently usable for playing {$cardLabel}");
            }
        }
        $consumedGrant = $state->useGrantFor($cardId, $playerId, $preferredGrantSourceCardId);

        if ($effectiveRow['hasToPlay']) {
            $effect = $this->registry->for($effectiveEffectKey);
            if (!$effect->canPayToPlayCost($state, $cardId, $playerId, $choices)) {
                throw new IllegalPlayException("Cannot pay the to-play cost for card {$cardId}");
            }
            $effect->payToPlayCost($state, $cardId, $playerId, $choices);
        }

        if ($fromDiscard) {
            $state->moveDiscardToInPlay($playerId, $cardId, $copiedCardId);
        } else {
            $state->moveHandToInPlay($playerId, $cardId, $copiedCardId);
        }

        // Gluttony/Insecurity tag whichever specific card ends up consuming
        // their granted extra play with effectState (e.g. "discard it after
        // scoring") -- see BoardState's 'onUseEffectState' restriction key.
        // Insecurity's own 'afterScoring' payload additionally gets
        // $playerId folded in here (never set by InsecurityEffect itself,
        // which only knows who played INSECURITY, not necessarily who
        // ends up spending the resulting extra play -- the same player in
        // every real game today, since a turn never changes hands mid-
        // grant, but this is still the one place that actually KNOWS for
        // certain) so GameService::applyAfterScoringHooks() can return the
        // card to that specific player's hand rather than whatever
        // ownerOf() says at scoring time -- a real bug reported live:
        // Chaos (or anything else that reassigns a mood's own owner
        // between now and scoring) used to send the card to whoever
        // happened to own it BY THEN instead of "your hand" as the
        // card's own text means it -- the player who actually played it
        // via this grant.
        if ($consumedGrant !== null && isset($consumedGrant['onUseEffectState'])) {
            foreach ($consumedGrant['onUseEffectState'] as $key => $value) {
                if ($key === 'afterScoring' && is_array($value) && !isset($value['playerId'])) {
                    $value['playerId'] = $playerId;
                }
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
     *
     * Duplicity's own repeat-eligibility -- and, the same way and for the
     * same reason, every OTHER card the acting player currently owns in
     * play (this invocation's own reactToAnotherPlay() candidates, see
     * PlayResult's own docblock) -- is snapshotted here into a local
     * variable, before this invocation's own effect gets to mutate
     * anything -- see continueAfterPlayingChain()'s docblock for why the
     * timing matters. This has to happen whether the effect resolves
     * synchronously below or pauses for an opponent's own decision first
     * (RequiresOpponentDecision), since either way
     * continueAfterPlayingChain() eventually needs this invocation's
     * *pre*-mutation count/candidates, not whatever they happen to be by
     * the time it actually runs -- so every PlayResult::pending() below
     * carries both along (persisted as that pause's own
     * game_pending_decision_batches.duplicity_eligible_sources/
     * reactor_candidate_card_ids, see PlayResult's own docblock for why
     * neither can live on BoardState).
     */
    private function resolveAfterPlayingChain(
        BoardState $state,
        int $cardId,
        int $playerId,
        PlayerChoices $topLevelChoices,
        PlayerChoices $invocationChoices,
        int $invocationSeq,
    ): PlayResult {
        $reactorCandidateCardIds = array_values(array_map(
            static fn ($mood) => $mood->cardId,
            array_filter($state->moodsOwnedBy($playerId), static fn ($mood) => $mood->cardId !== $cardId),
        ));

        $effectiveRow = $state->catalogRow($state->effectiveCardId($cardId));
        if (!$effectiveRow['hasAfterPlaying']) {
            return $this->finishAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices, $reactorCandidateCardIds);
        }

        $effectiveEffectKey = $effectiveRow['effectKey'];
        $effect = $this->registry->for($effectiveEffectKey);

        $duplicitySources = $state->countMoodsInPlayWithEffectiveKey($playerId, 'duplicity');
        $eligibleSources = $effectiveEffectKey === 'duplicity' ? $duplicitySources - 1 : $duplicitySources;

        if ($effect instanceof RequiresOpponentDecision) {
            $pendingDecisions = $effect->pendingDecisionsFor($state, $cardId, $playerId, $invocationChoices);
            if ($pendingDecisions !== []) {
                return PlayResult::pending($pendingDecisions, $cardId, $invocationSeq, $invocationChoices, $eligibleSources, $reactorCandidateCardIds);
            }
            // Nothing to ask for this specific play (e.g. declined, or no
            // qualifying target/candidate) -- same as an ordinary no-op
            // afterPlaying().
            $followUpDecisions = $effect->resolveDecisions($state, $cardId, $playerId, $invocationChoices, []);
            if ($followUpDecisions !== []) {
                return PlayResult::pending($followUpDecisions, $cardId, $invocationSeq, $invocationChoices, $eligibleSources, $reactorCandidateCardIds);
            }
        } else {
            $effect->afterPlaying($state, $cardId, $playerId, $invocationChoices);
        }

        return $this->continueAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices, $invocationSeq, $eligibleSources, $reactorCandidateCardIds);
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
     * If resolveDecisions() itself returns a non-empty follow-up (e.g.
     * InstabilityEffect's second round, only askable once the first
     * round's mutation has actually landed), this pauses again with a
     * fresh PlayResult::pending() instead of continuing the chain --
     * $invocationChoices is passed through unchanged, so a later round
     * can still read whatever the first round's own choices bag carried.
     *
     * $duplicityEligibleSources/$reactorCandidateCardIds are this
     * invocation's own pre-mutation snapshots, read back from wherever
     * GameService persisted them when this invocation first paused
     * (game_pending_decision_batches.duplicity_eligible_sources/
     * reactor_candidate_card_ids -- see PlayResult's own docblock) --
     * carried through to continueAfterPlayingChain() unchanged, exactly
     * like $invocationChoices already is, since resolveDecisions() below
     * may itself be what causes $cardId (or one of the reactor
     * candidates) to leave play (e.g. Malice discarding itself as part of
     * its own color cascade) and these values have to survive that
     * regardless.
     *
     * @param array<string, PlayerChoices> $answers
     * @param int[] $reactorCandidateCardIds
     */
    public function resolvePendingDecisions(
        BoardState $state,
        int $cardId,
        int $playerId,
        PlayerChoices $topLevelChoices,
        PlayerChoices $invocationChoices,
        int $invocationSeq,
        array $answers,
        int $duplicityEligibleSources,
        array $reactorCandidateCardIds = [],
    ): PlayResult {
        if (isset($answers[self::DUPLICITY_REPEAT_KEY])) {
            return $this->resolveDuplicityRepeatOffer($state, $cardId, $playerId, $topLevelChoices, $invocationSeq, $answers[self::DUPLICITY_REPEAT_KEY], $reactorCandidateCardIds);
        }

        $effectiveEffectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];
        $effect = $this->registry->for($effectiveEffectKey);
        if (!$effect instanceof RequiresOpponentDecision) {
            throw new IllegalPlayException("Effect '{$effectiveEffectKey}' has no pending decisions to resolve");
        }

        $followUpDecisions = $effect->resolveDecisions($state, $cardId, $playerId, $invocationChoices, $answers);
        if ($followUpDecisions !== []) {
            return PlayResult::pending($followUpDecisions, $cardId, $invocationSeq, $invocationChoices, $duplicityEligibleSources, $reactorCandidateCardIds);
        }

        return $this->continueAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices, $invocationSeq, $duplicityEligibleSources, $reactorCandidateCardIds);
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
     * comparing it against $eligibleSources caps the chain at exactly that
     * many, however many there turn out to be, rather than the old hard
     * "exactly one, ever" limit.
     *
     * $eligibleSources is resolveAfterPlayingChain()'s own snapshot of
     * this invocation's Duplicity count, taken *before* this invocation's
     * effect ran and threaded through as a plain parameter ever since
     * (see PlayResult's own docblock for why it can no longer live on
     * BoardState) -- NOT a fresh recount taken here, after the mutation.
     * This matters for a card like Chaos, which reassigns every in-play
     * mood's owner (including Duplicity itself) as its OWN after-playing
     * effect: per an official ruling, Duplicity's opportunity to repeat
     * is judged at the moment the mood is played, not after that mood's
     * own effect resolves, so gaining OR losing Duplicity control as a
     * side effect of THIS SAME invocation must never change whether a
     * repeat gets offered here. (It also doesn't matter whether the
     * acting player still controls that Duplicity by the time they're
     * actually asked to repeat -- losing it after the fact doesn't
     * retroactively cancel an opportunity that already triggered, and
     * likewise doesn't matter whether $cardId itself is still in play --
     * Anger/Hate discarding/bottom-decking themselves, or Malice
     * discarding itself as part of its own color cascade, must not
     * un-trigger a repeat opportunity that already existed the moment
     * they were played either.) The count is still taken fresh per
     * invocation, not once for the whole chain -- a later repeat's own
     * snapshot naturally reflects whatever the previous invocation's
     * effect just did, since that's the state as it stood right before
     * THIS invocation's own mutation began.
     *
     * The printed text triggers on "ANOTHER mood" -- so a Duplicity-
     * effective source never offers to repeat its OWN just-played
     * instance via itself, but a *different*, already-in-play
     * Duplicity-effective source still can (e.g. playing the real
     * Duplicity while a Creativity is already copying one lets that
     * Creativity offer one repeat of the just-played Duplicity's own
     * "grant an extra play" effect) -- see resolveAfterPlayingChain()'s
     * own subtraction of one in that case, excluding only the just-played
     * card itself, not any other source.
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
    /**
     * @param int[] $reactorCandidateCardIds
     */
    private function continueAfterPlayingChain(
        BoardState $state,
        int $cardId,
        int $playerId,
        PlayerChoices $topLevelChoices,
        int $invocationSeq,
        int $eligibleSources,
        array $reactorCandidateCardIds = [],
    ): PlayResult {
        $effectiveEffectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];

        if ($invocationSeq < $eligibleSources) {
            // The repeat-offer's own answer is resolved directly by
            // resolveDuplicityRepeatOffer() below, which never reads the
            // batch's stored invocation_choices -- $topLevelChoices here
            // is just a harmless, always-valid placeholder to satisfy
            // PlayResult::pending()'s signature. $eligibleSources/
            // $reactorCandidateCardIds both ride along too (unchanged)
            // purely so a still-open repeat offer keeps reporting the
            // same values if this same pause is read back again before
            // being answered -- resolveDecisions() never actually needs
            // either for this particular decision type, since
            // resolveDuplicityRepeatOffer() below always recomputes its
            // own fresh snapshot on repeat (see resolveAfterPlayingChain()),
            // and only needs $reactorCandidateCardIds back at all for its
            // own DECLINE branch, where it's this same, still-current
            // snapshot that applies.
            return PlayResult::pending([$this->duplicityRepeatOfferRequest($playerId, $effectiveEffectKey)], $cardId, $invocationSeq, $topLevelChoices, $eligibleSources, $reactorCandidateCardIds);
        }

        return $this->finishAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices, $reactorCandidateCardIds);
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
     * CompulsionEffect). On decline, $reactorCandidateCardIds is the
     * snapshot THIS invocation was originally paused with (threaded
     * through unchanged, same as $topLevelChoices) -- correct even though
     * more turns/effects may have happened since this pause first opened,
     * since it's still this specific invocation's own pre-mutation
     * candidates that finishAfterPlayingChain() needs.
     *
     * @param int[] $reactorCandidateCardIds
     */
    private function resolveDuplicityRepeatOffer(
        BoardState $state,
        int $cardId,
        int $playerId,
        PlayerChoices $topLevelChoices,
        int $invocationSeq,
        PlayerChoices $repeatAnswer,
        array $reactorCandidateCardIds = [],
    ): PlayResult {
        $repeatBag = $repeatAnswer->sub(self::DUPLICITY_REPEAT_KEY);
        if (!$repeatBag->bool('repeat')) {
            return $this->finishAfterPlayingChain($state, $cardId, $playerId, $topLevelChoices, $reactorCandidateCardIds);
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
     *
     * Iterates $reactorCandidateCardIds -- resolveAfterPlayingChain()'s
     * own pre-mutation snapshot of who was in play right before THIS
     * invocation's own afterPlaying()/resolveDecisions() ran -- rather
     * than a fresh $state->moodsOwnedBy($playerId) query here. A bug
     * caught live: querying fresh meant a card whose own effect returns
     * or discards one of the player's OTHER in-play moods as a side
     * effect (Thrill's "you may put any number of your other moods into
     * your hand" is the clearest example) silently robbed that mood of
     * ever reacting to the very play that displaced it -- e.g. Validation
     * never got the chance to react to Thrill's own play if Thrill's own
     * choice happened to return Validation itself to hand, even though
     * Validation genuinely was in play at the moment Thrill was played.
     * isInPlay() is deliberately NOT re-checked per candidate here --
     * the snapshot alone is the authority for "was this reactor eligible
     * at trigger time", same principle as $duplicityEligibleSources
     * (see PlayResult's own docblock); reactToAnotherPlay() itself
     * doesn't touch the reactor's own zone (only its owner's grants), so
     * there's nothing unsafe about calling it for a candidate that later
     * left play as a result of this same resolution.
     *
     * @param int[] $reactorCandidateCardIds
     */
    private function finishAfterPlayingChain(BoardState $state, int $cardId, int $playerId, PlayerChoices $topLevelChoices, array $reactorCandidateCardIds = []): PlayResult
    {
        foreach ($reactorCandidateCardIds as $reactorCardId) {
            if ($reactorCardId === $cardId) {
                continue;
            }
            $reactorEffectKey = $state->catalogRow($state->effectiveCardId($reactorCardId))['effectKey'];
            if ($this->registry->has($reactorEffectKey)) {
                $this->registry->for($reactorEffectKey)->reactToAnotherPlay($state, $reactorCardId, $cardId, $playerId, $topLevelChoices);
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
     * occupy that hand slot). $copiedCardId is resolved through
     * BoardState::effectiveCardId() first, same as playMood() itself,
     * so copying a Creativity that's copying something else costs
     * whatever that something else costs, not nothing (Creativity's own
     * printed hasToPlay is always false). Side-effect-free and safe to
     * call speculatively, same as isPlayable() above.
     */
    public function canPayCopiedToPlayCost(BoardState $state, int $playerId, int $creativityCardId, int $copiedCardId): bool
    {
        $row = $state->catalogRow($state->effectiveCardId($copiedCardId));
        if (!$row['hasToPlay']) {
            return true;
        }

        return $this->registry->for($row['effectKey'])->canPayToPlayCost($state, $creativityCardId, $playerId, new PlayerChoices([]));
    }
}
