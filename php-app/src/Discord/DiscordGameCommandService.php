<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

use MoodSwings\Bot\BotChoiceResolver;
use MoodSwings\Config;
use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\CardCatalog;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Repository\DiscordAccountRepository;
use MoodSwings\Rules\BoardState;
use MoodSwings\SiteUrl;

/**
 * Issue #233: actually playing the game through Discord, not just getting
 * notified (#232, DiscordNotificationChannel) about it. Every response
 * this class builds is EPHEMERAL (flags 64) -- a hand's contents are
 * exactly as private here as they are on the web board, so nothing this
 * class renders is ever visible to anyone but the player who ran the
 * command/clicked the component.
 *
 * SCOPE (deliberately narrower than the web app -- see issue #233's own
 * "needs a decision on scope for a first pass" note):
 * - Format 'standard' (Traditional Duel) ONLY for actually PLAYING a
 *   game turn-by-turn. Team/Closed Team/Duel/draft/chaos_draft formats
 *   all have their own extra state (teammate hand visibility, per-seat
 *   decks, propose/confirm decisions, attached chaos effects, ...) this
 *   class has no rendering for yet -- a game in any other format gets a
 *   plain "open the web app for this" message, same as an unsupported
 *   choice shape below. Power Duel (format 'duel', deck_type
 *   'custom_duel', 'power' rules preset -- see powerDuelMenuMessage()'s
 *   own docblock) is the one deliberate exception: Discord fully covers
 *   INVITING a friend and SUBMITTING a decklist for it (issue #233
 *   follow-up), stopping exactly at the same "open the web app" hand-off
 *   the instant startGame() actually flips it 'in_progress' -- setup, not
 *   play, is this feature's whole scope.
 * - A card is only offered to PLAY here (in the "Play a card" select) if
 *   every one of its own choice_fields, up to MAX_CHOICE_FIELDS total
 *   (see supportedChoiceFields()), is one of SUPPORTED_FIELD_TYPES below.
 *   playableCardOptions() offers a card from EITHER the viewer's own hand
 *   OR the discard pile -- a discard-sourced play grant (Grace/Harmony/
 *   Grief/Angst, or Melancholy's own blanket "play from discard as though
 *   it were your hand") already shows up as `is_playable` on a
 *   `discard_pile` entry exactly the same way it does on a hand entry
 *   (see `GameService::getState()`'s own `serializeCard()`), so no new
 *   state field was needed here -- just reading the zone the web client's
 *   own `renderDiscardPile()` already reads. A `grant_choice` field
 *   (2+ distinct grants covering the same card, e.g. Grace AND Harmony
 *   both active) stays out of SUPPORTED_FIELD_TYPES below, same as every
 *   other still-out-of-scope field type -- that card falls into "needs
 *   the web app" regardless of which zone it's in.
 *   -- covers not just single-target cards (Pride's own
 *   target_player_id, Compulsion's discard_card_id, Hate's optional "you
 *   may put any mood on the bottom of the deck," ...) but also a `multi`
 *   field (Suspicion's "any number of players," Guile's "exactly 2 cards
 *   to discard" -- Discord's own native multi-select, min_values/
 *   max_values, covers this directly, see fieldSelectComponent()) and a
 *   card with a SECOND field (Faith, Guile, Condescension, Guilt,
 *   Regret, Worry, Contempt, Cynicism, Hostility, Hesitation,
 *   Rationalization, Corruption, Fascination -- promptOrPlay() asks them
 *   one at a time, skipping one that doesn't need answering right now,
 *   the same way an optional field with zero legal candidates already
 *   got skipped before this class supported a second field at all).
 *   Still excludes 3+ fields, or a `nested` sub-form (Duplicity's own
 *   repeat offer, any chaos_draft attachment) -- a real, known scope
 *   limit (see php-app/README.md), not a bug; a player who hits it is
 *   pointed at the web app instead.
 * - Every actual rules decision (which candidates are legal for a given
 *   field) reuses BotChoiceResolver's own already-tested
 *   moodFieldCandidates()/playerFieldCandidates()/handCardFieldCandidates()/
 *   discardCardFieldCandidates() against a freshly loaded BoardState,
 *   rather than re-deriving CardChoiceSchema's own filter/scope logic a
 *   third time (web-static/js/game.js's fieldOptions() is the second) --
 *   this class only ever supplies the DISPLAY (embed/component) layer on
 *   top of an already-correct legal-candidate list. A `multi` field's own
 *   cross-selection constraints (CardChoiceSchema's `constraint` key --
 *   distinct_owners, same_color_or_value, ...) are deliberately NOT
 *   re-validated here either -- an illegal combination the select
 *   menu's own candidate list didn't rule out still gets rejected
 *   server-side, surfaced the same way any other failed play already is.
 *
 * Custom_id scheme (colon-delimited, always short enough for Discord's
 * own 100-char cap): `ms:view:{gameId}`, `ms:pass:{gameId}`,
 * `ms:play:{gameId}` (the "play a card" select; its own value is the
 * chosen card id), `ms:playfield:{gameId}:{cardId}:{stepIndex}:{answers}`
 * (that card's own $stepIndex'th field's value select -- $answers is
 * every earlier field's own already-submitted choice, round-tripped
 * through encodeAnswers()/decodeAnswers() since a 2-field card's second
 * select needs to remember the first one's answer across the trip),
 * `ms:decision:{gameId}` (the current pending decision's own single
 * field's value select), `ms:newgame:0`/`ms:newgamebot:0` (starting a
 * practice game -- see below; the trailing `0` is a dummy, never a real
 * game id, kept only so every custom_id parses the same
 * `ms:{verb}:{arg}` shape). A field's own KEY is never encoded in a
 * custom_id (only its already-submitted VALUE, for a 2-field card's
 * first field) -- it's always re-derived server-side from the current
 * board state and $stepIndex.
 *
 * Starting a practice game (reported live, right after this class's own
 * first ship: "Can we add a command to start a game from inside
 * discord?") is the one action here that ISN'T "act on an existing
 * game" -- a `structure` deck_type game needs no deck-building step at
 * all (GameService::createGame()'s own defaults -- format 'standard',
 * deck_type 'structure' -- already produce an immediately-`in_progress`
 * game), and seating a practice bot is just naming its own user id
 * alongside the caller's in createGame()'s `$userIds`, so this is fully
 * completable inside Discord unlike almost everything else this class
 * still points at the web app for. A HUMAN opponent (reported live:
 * "let's add the ability to create a game for another human on the
 * friend list") works the same way -- FriendshipService::listFriends()
 * supplies the picker instead of listPracticeBots(), and createGame()
 * seats that friend's own user id exactly the same as it would a bot's,
 * no invite/accept step (see newFriendGameMessage()'s own docblock for
 * why this earlier note calling it "real design work" turned out not to
 * be true).
 */
final class DiscordGameCommandService
{
    private const SUPPORTED_FORMAT = 'standard';

    /** @see this class's own docblock -- 'nested'/'card_order'/'grant_choice' fall outside scope; every one of these supports `multi` too. */
    private const SUPPORTED_FIELD_TYPES = ['mode', 'value', 'bool', 'mood', 'player', 'hand_card', 'discard_card'];

    private const MAX_SELECT_OPTIONS = 25;

    /**
     * The most choice_fields any hand-playable card actually has --
     * confirmed by walking every effect_key in CardChoiceSchema and
     * counting (Faith, Guile, Fascination, Guilt, Regret, Worry,
     * Contempt, Condescension, Cynicism, Hostility, Hesitation,
     * Rationalization, and Corruption all top out at exactly 2; nothing
     * has 3+). promptOrPlay() walks fields one at a time regardless, so
     * this is purely the "is this card even in scope" gate in
     * supportedChoiceFields() -- a future card with a 3rd field would
     * still just need the web app, the same as `nested`/an unsupported
     * field type already does.
     */
    private const MAX_CHOICE_FIELDS = 2;

    /**
     * The select-menu value for "leave this OPTIONAL field blank" --
     * reported live: Hate's own 'target_mood_id' ("you may put any mood
     * on the bottom of the deck") is `required => false`, and this
     * class's first ship simply never rendered an optional field at all,
     * always playing blank -- indistinguishable, from a player's own
     * seat, from "there was never a choice to make." Distinct from every
     * real candidate value this class ever emits (a game_cards id, a
     * game_player id, a 'mode' option string, or bool's own '0'/'1'), so
     * it's always unambiguous once cast back in castFieldValue().
     */
    private const SKIP_FIELD_VALUE = '__skip__';

    /**
     * Reported live: "can we show the discard pile as an image too?" --
     * the discard pile row appended to the composite board image
     * (discardImageRow()) shows only its own most recent cards, the same
     * "most recent are most relevant" reasoning cardsMessage()'s own
     * discard select already uses, rather than growing the image without
     * bound for a long game's 100+-card pile. Matches
     * BoardImageRenderer::MAX_CARDS_PER_PLAYER's own value so every row in
     * the composite (a player's in-play cards, or this one) stays the same
     * width -- kept as its own constant here (rather than reading that
     * private one) since this class needs the number BEFORE calling
     * render(), to word discardImageRow()'s own label correctly.
     */
    private const MAX_DISCARD_CARDS_SHOWN = 12;

    public function __construct(
        private readonly GameService $games,
        private readonly BoardStateRepository $boardStates,
        private readonly DiscordAccountRepository $accounts,
        private readonly FriendshipService $friendships,
        private readonly UserDecklistService $userDecklists,
        private readonly BotChoiceResolver $choiceResolver = new BotChoiceResolver(),
        private readonly BoardImageRenderer $boardImageRenderer = new BoardImageRenderer(),
    ) {
    }

    /**
     * `/moodswings` itself -- Discord's APPLICATION_COMMAND (type 2)
     * interaction. No sub-options in v1: it just finds the caller's own
     * active 'standard' game(s) and either renders the one it finds, asks
     * which of several to view, or explains why there's nothing to show.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleCommand(array $payload): array
    {
        $userId = $this->resolveUserId($payload);
        if ($userId === null) {
            return $this->ephemeralMessage($this->unlinkedAccountMessage());
        }

        $gameIds = $this->activeStandardGameIdsFor($userId);
        if ($gameIds === []) {
            return $this->ephemeralMessage(
                "You don't have an active Traditional game right now. Start or join one at " . SiteUrl::root() . '/game/, or start one below.',
                [$this->utilityButtonsRow()],
            );
        }

        if (count($gameIds) === 1) {
            // Reported live: bot turns weren't happening via Discord at
            // all until the 15-minute cron fallback caught them -- see
            // handleComponent()'s own identical call for the root cause
            // (no equivalent here of the web client's ~4s
            // GET /games/state poll, which calls this on every single
            // poll as a backstop). Opening /moodswings is the other
            // moment, alongside every mutating component click, where a
            // stale bot turn can otherwise just sit there unnoticed --
            // cheap to call even when nothing's actually stuck (see that
            // method's own docblock), and its result is discarded in
            // favor of boardMessage()'s own fresh getState() read either
            // way, same as GET /games/state itself -- including that
            // same route's own best-effort try/catch, since a transient
            // failure here (e.g. lock contention from a concurrent write
            // elsewhere in this same game) must never block the board
            // from rendering at all.
            try {
                $this->games->advanceAutomatedTurns($gameIds[0]);
            } catch (GameStateException) {
                // Best-effort only -- see above.
            }

            return $this->ephemeralMessage(...$this->boardMessage($gameIds[0], $userId));
        }

        $components = [
            ['type' => 1, 'components' => array_map(
                fn (int $gameId) => ['type' => 2, 'style' => 2, 'label' => "Game #{$gameId}", 'custom_id' => "ms:view:{$gameId}"],
                array_slice($gameIds, 0, 4),
            )],
            // A separate row -- Discord caps a single action row at 5
            // components total, and the game-picker row above can
            // already hold 4 on its own.
            $this->utilityButtonsRow(),
        ];

        return $this->ephemeralMessage('You have more than one active Traditional game -- pick one:', components: $components);
    }

    /**
     * Every button/select-menu click -- Discord's MESSAGE_COMPONENT
     * (type 3) interaction. Always responds with type 7 (UPDATE_MESSAGE),
     * refreshing the same ephemeral message in place rather than
     * spawning a new one each click.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleComponent(array $payload): array
    {
        $userId = $this->resolveUserId($payload);
        if ($userId === null) {
            return $this->updateMessage($this->unlinkedAccountMessage());
        }

        $customId = (string) ($payload['data']['custom_id'] ?? '');
        $values = $payload['data']['values'] ?? [];
        $parts = explode(':', $customId);

        if (($parts[0] ?? '') !== 'ms' || !isset($parts[1], $parts[2])) {
            return $this->updateMessage('Something about that action was not recognized -- try running /moodswings again.');
        }

        $verb = $parts[1];
        $gameId = (int) $parts[2];

        try {
            switch ($verb) {
                case 'view':
                    break; // Just re-render below -- nothing to apply first.
                case 'pass':
                    $gamePlayerId = $this->requireSeatedIn($gameId, $userId);
                    $this->games->pass($gameId, $gamePlayerId);
                    break;
                case 'play':
                    $gamePlayerId = $this->requireSeatedIn($gameId, $userId);
                    $cardId = (int) ($values[0] ?? 0);
                    $result = $this->applyPlaySelection($gameId, $userId, $gamePlayerId, $cardId);
                    if ($result !== null) {
                        return $this->updateMessage(...$result);
                    }
                    break;
                case 'playfield':
                    $gamePlayerId = $this->requireSeatedIn($gameId, $userId);
                    $cardId = (int) ($parts[3] ?? 0);
                    $stepIndex = (int) ($parts[4] ?? 0);
                    $priorAnswers = isset($parts[5]) ? $this->decodeAnswers($parts[5]) : [];
                    $result = $this->submitPlayField($gameId, $userId, $gamePlayerId, $cardId, $stepIndex, $priorAnswers, $values);
                    if ($result !== null) {
                        return $this->updateMessage(...$result);
                    }
                    break;
                case 'decision':
                    $gamePlayerId = $this->requireSeatedIn($gameId, $userId);
                    $this->submitDecisionField($gameId, $userId, $gamePlayerId, $values);
                    break;
                case 'newgame':
                    return $this->updateMessage(...$this->newPracticeGameMessage($userId));
                case 'newgamebot':
                    return $this->updateMessage(...$this->createPracticeGameMessage($userId, (int) ($values[0] ?? 0)));
                case 'friendgame':
                    return $this->updateMessage(...$this->newFriendGameMessage($userId));
                case 'friendgamewith':
                    return $this->updateMessage(...$this->createFriendGameMessage($userId, (int) ($values[0] ?? 0)));
                case 'deck':
                    return $this->updateMessage(...$this->deckMenuMessage($userId));
                case 'deckview':
                    return $this->updateMessage(...$this->deckDetailMessage($userId, (int) ($values[0] ?? 0)));
                case 'deckcreateopen':
                    return $this->decklistModalResponse('ms:deckcreatesubmit:0', 'New Decklist', '', '');
                case 'deckeditopen':
                    return $this->deckEditModalResponse($userId, $gameId);
                case 'deckdelete':
                    $this->userDecklists->delete($userId, $gameId);

                    return $this->updateMessage(...$this->deckMenuMessage($userId));
                case 'powerduel':
                    return $this->updateMessage(...$this->powerDuelMenuMessage($userId));
                case 'powerduelinvite':
                    return $this->updateMessage(...$this->newPowerDuelFriendMessage($userId));
                case 'powerduelwith':
                    return $this->updateMessage(...$this->createPowerDuelGameMessage($userId, (int) ($values[0] ?? 0)));
                case 'powerduelbotmenu':
                    return $this->updateMessage(...$this->newPowerDuelBotMessage($userId));
                case 'powerduelbot':
                    return $this->updateMessage(...$this->choosePowerDuelBotDeckMessage($userId, (int) ($values[0] ?? 0)));
                case 'powerduelbotdeck':
                    return $this->updateMessage(...$this->createPowerDuelGameWithBotMessage($userId, $gameId, (int) ($values[0] ?? 0)));
                case 'powerduelgame':
                    return $this->updateMessage(...$this->deckSubmissionPromptMessage($gameId, "Submit your decklist for Game #{$gameId}:"));
                case 'deckchooseopen':
                    return $this->updateMessage(...$this->chooseSavedDeckForGameMessage($userId, $gameId));
                case 'deckchooseforgame':
                    $gamePlayerId = $this->requireSeatedIn($gameId, $userId);
                    $this->games->submitCustomDuelDeck($gameId, $gamePlayerId, null, (int) ($values[0] ?? 0), $userId);

                    return $this->updateMessage(...$this->afterDeckSubmittedMessage($gameId, $userId));
                case 'deckpasteopen':
                    return $this->singleDecklistModalResponse("ms:deckpastesubmit:{$gameId}", 'Submit Decklist');
                case 'cards':
                    return $this->updateMessage(...$this->cardsMessage($gameId, $userId));
                case 'cardhand':
                case 'cardplay':
                case 'carddiscard':
                    $cardId = (int) ($values[0] ?? 0);
                    return $this->updateMessage(...$this->cardDetailMessage($gameId, $userId, $verb, $cardId));
                case 'log':
                    return $this->updateMessage(...$this->gameLogMessage($gameId, $userId));
                default:
                    return $this->updateMessage('Something about that action was not recognized -- try running /moodswings again.');
            }

            // Reported live: bot turns/auto-passes weren't happening at
            // all via Discord until the 15-minute cron fallback caught
            // them -- every equivalent web route (POST /games/pass,
            // /games/play, ...) already calls this right after its own
            // mutation (see GameService::advanceAutomatedTurns()'s own
            // docblock), and the web client's ~4s poll
            // (GET /games/state) ALSO calls it on every single poll as a
            // backstop, so a bot's own turn there gets driven forward
            // even if nothing else does. Discord has neither: no
            // continuous poll, and (until now) no call here either, so a
            // human's own pass/play/decision above just sat there. Only
            // reached for a verb that actually mutated the game
            // (view/pass/play/playfield/decision -- 'play'/'playfield'
            // above already returned early instead of falling through
            // here when there was nothing to advance yet, i.e. a further
            // field select was needed rather than an actual play);
            // 'newgamebot' already calls this itself inside
            // createPracticeGameMessage(), and every other verb returns
            // directly without reaching this line at all.
            $this->games->advanceAutomatedTurns($gameId);
        } catch (\Throwable $e) {
            // Every exception this could realistically catch here
            // (GameStateException, IllegalPlayException,
            // InvalidChoiceException, ...) already carries a
            // player-safe message meant to be shown as-is -- same
            // convention public/index.php's own catch blocks rely on
            // for these exact exception types.
            return $this->updateMessage(...$this->boardMessage($gameId, $userId, "Couldn't do that: " . $e->getMessage()));
        }

        return $this->updateMessage(...$this->boardMessage($gameId, $userId));
    }

    /**
     * The second-and-later step of a multi-field card (Faith, Guile,
     * Condescension, ...) -- $stepIndex is the field just answered by
     * $values, $priorAnswers everything gathered from every step before
     * it (round-tripped through the previous select's own custom_id via
     * encodeAnswers()). Delegates straight to promptOrPlay() for
     * $stepIndex + 1 onward, exactly the same walk applyPlaySelection()
     * itself starts at index 0 -- there's only ever one "ask the next
     * field, or play" operation in this class, just entered at a
     * different index depending on how much has already been answered.
     *
     * @param mixed[] $values
     * @param array<string, mixed> $priorAnswers
     * @return array{0: string, 1: array<int, array<string, mixed>>}|null
     */
    private function submitPlayField(int $gameId, int $userId, int $gamePlayerId, int $cardId, int $stepIndex, array $priorAnswers, array $values): ?array
    {
        $state = $this->games->getState($gameId, $userId);
        $card = $this->findCard([...$state['you']['hand'] ?? [], ...$state['discard_pile'] ?? []], $cardId);
        if ($card === null) {
            throw new GameStateException('That card is no longer playable from your hand or the discard pile.');
        }

        $fields = $this->supportedChoiceFields($card['choice_fields'] ?? []);
        if ($fields === null || !isset($fields[$stepIndex])) {
            throw new GameStateException("That card's own choice can't be answered from Discord anymore -- open the web app.");
        }

        $answers = [...$priorAnswers, ...$this->choicesFor($fields[$stepIndex], $values)];
        $boardState = $this->boardStates->load($gameId);

        return $this->promptOrPlay($gameId, $gamePlayerId, $cardId, $card, $fields, $stepIndex + 1, $answers, $boardState, $state);
    }

    /**
     * @param mixed[] $values
     */
    private function submitDecisionField(int $gameId, int $userId, int $gamePlayerId, array $values): void
    {
        $state = $this->games->getState($gameId, $userId);
        $decision = $state['round']['pending_decision'] ?? null;
        if ($decision === null || !($decision['is_you'] ?? false) || !isset($decision['field'])) {
            throw new GameStateException('That decision is no longer waiting on you.');
        }

        $this->games->respondToDecision($gameId, $gamePlayerId, $this->choicesFor($decision['field'], $values));
    }

    /**
     * A `multi` field's own selected values are ALL of $values (Discord's
     * own multi-select already enforces min/max_values -- see
     * fieldSelectComponent()), cast to ints and included even when empty
     * (an empty array is itself a legal answer -- "choose any number,"
     * PlayerChoices::ints() already treats a missing key the same way).
     * A single-value field keeps castFieldValue()'s own SKIP_FIELD_VALUE
     * handling -- see that constant's docblock.
     *
     * @param mixed[] $values @return array<string, mixed>
     */
    private function choicesFor(array $field, array $values): array
    {
        if (($field['multi'] ?? false) === true) {
            return [$field['key'] => array_map(intval(...), $values)];
        }

        $value = $this->castFieldValue($field, $values);

        return $value !== null ? [$field['key'] => $value] : [];
    }

    /**
     * Plays $cardId outright when it has no supported fields at all, or
     * starts promptOrPlay()'s own field-by-field walk at index 0
     * otherwise. Never returns null AND leaves the card unplayed --
     * either it played, or the caller already has a field-select
     * response to send back.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}|null
     */
    private function applyPlaySelection(int $gameId, int $userId, int $gamePlayerId, int $cardId): ?array
    {
        $state = $this->games->getState($gameId, $userId);
        $card = $this->findCard([...$state['you']['hand'] ?? [], ...$state['discard_pile'] ?? []], $cardId);
        if ($card === null) {
            throw new GameStateException('That card is no longer playable from your hand or the discard pile.');
        }

        $fields = $this->supportedChoiceFields($card['choice_fields'] ?? []);
        if ($fields === null) {
            // playableCardOptions() already keeps a card shaped like this
            // out of the "Play a card" select entirely -- reaching here
            // means a stale/forged interaction outran that check, so this
            // is a real error, not a silent blank play.
            throw new GameStateException("That card's own choice can't be answered from Discord anymore -- open the web app.");
        }

        $boardState = $this->boardStates->load($gameId);

        return $this->promptOrPlay($gameId, $gamePlayerId, $cardId, $card, $fields, 0, [], $boardState, $state);
    }

    /**
     * The one place this class decides "ask another field, or actually
     * play the card" -- walks $fields from $fromIndex, silently leaving
     * blank any field that doesn't need asking right now (a
     * `requires_mode` gate CardChoiceSchema's own docblock documents --
     * Guilt/Contempt/Hesitation's own target_mood_id only matters once
     * their 'mode' field is 'single' -- that $answers doesn't satisfy, or
     * simply zero legal candidates, the same optional_if_no_targets
     * carve-out a single-field card already got before this class ever
     * supported a second one), then stops at the first field that DOES
     * need asking and returns a select for it. Reaching the end of
     * $fields with nothing left to ask actually plays the card with
     * whatever was gathered along the way. $answers accumulates every
     * field's own choicesFor() output, keyed by field key, exactly the
     * shape GameService::playMood() itself expects.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $answers
     * @return array{0: string, 1: array<int, array<string, mixed>>}|null
     */
    private function promptOrPlay(int $gameId, int $gamePlayerId, int $cardId, array $card, array $fields, int $fromIndex, array $answers, BoardState $boardState, array $state): ?array
    {
        for ($stepIndex = $fromIndex; $stepIndex < count($fields); $stepIndex++) {
            $field = $fields[$stepIndex];

            if (isset($field['requires_mode']) && ($answers['mode'] ?? null) !== $field['requires_mode']) {
                continue;
            }

            $options = $this->fieldOptions($boardState, $field, $gamePlayerId, $cardId, $card['effect_key'], $state);
            if ($options === []) {
                continue;
            }

            $customId = "ms:playfield:{$gameId}:{$cardId}:{$stepIndex}:" . $this->encodeAnswers($answers);
            $components = [['type' => 1, 'components' => [$this->fieldSelectComponent($customId, $field, $options)]]];

            return ["Playing **{$card['name']}** -- {$field['label']}:", $components];
        }

        $this->games->playMood($gameId, $gamePlayerId, $cardId, $answers);

        return null;
    }

    /**
     * The select component for one field, either kind -- a `multi` field
     * (Suspicion's "any number of players," Guile's "exactly 2 cards")
     * gets Discord's own native multi-select (min_values/max_values)
     * instead of the single-value Skip sentinel: not selecting anything,
     * when min_values is 0, IS the "leave this optional field blank"
     * signal, so there's no need for a fake option occupying a real slot
     * the way withSkipOptionIfOptional() adds for a single-select field.
     * min_values honors a `count.min` (Guile's "exactly 2," Malice's
     * decision-side "choose two") over the plain required/optional flag
     * where CardChoiceSchema sets one, except when `count.zero_ok` is
     * set (Rejection/Denial's own "0 or exactly 2") -- there, 0 is
     * always legal regardless of count.min, so min_values stays 0. This
     * doesn't attempt every other constraint CardChoiceSchema can carry
     * (same_color_or_value, distinct_owners, ...) -- same as this
     * class's own docblock already says about not re-deriving that
     * logic a third time, an illegal combination the UI didn't rule out
     * still gets rejected server-side, surfaced the same way any other
     * failed play already is.
     *
     * @param array<int, array{label: string, value: string}> $options
     * @return array<string, mixed>
     */
    private function fieldSelectComponent(string $customId, array $field, array $options): array
    {
        if (($field['multi'] ?? false) !== true) {
            return [
                'type' => 3,
                'custom_id' => $customId,
                'placeholder' => $field['label'] ?? 'Choose one',
                'options' => $this->withSkipOptionIfOptional($field, $options),
            ];
        }

        $options = array_slice($options, 0, self::MAX_SELECT_OPTIONS);
        $count = $field['count'] ?? [];
        $max = min(count($options), (int) ($count['max'] ?? count($options)));
        $min = ($count['zero_ok'] ?? false) === true
            ? 0
            : (int) ($count['min'] ?? (($field['required'] ?? false) === true ? 1 : 0));

        return [
            'type' => 3,
            'custom_id' => $customId,
            'placeholder' => $field['label'] ?? 'Choose one or more',
            'options' => $options,
            'min_values' => min($min, $max),
            'max_values' => $max,
        ];
    }

    /**
     * Round-trips a multi-field card's own earlier answer(s) through the
     * next field's select custom_id -- bounded to at most
     * MAX_CHOICE_FIELDS - 1 accumulated keys (never more than 1 today),
     * each a small int/string/int[]/bool value, so this comfortably
     * fits well under Discord's own 100-char custom_id cap alongside the
     * `ms:playfield:{gameId}:{cardId}:{stepIndex}:` prefix. URL-safe
     * (no `+`/`/`/`=`) so it never collides with the colon-delimited
     * parsing handleComponent() already does on the rest of the id.
     *
     * @param array<string, mixed> $answers
     */
    private function encodeAnswers(array $answers): string
    {
        return rtrim(strtr(base64_encode(json_encode($answers)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function decodeAnswers(string $encoded): array
    {
        $padded = str_pad(strtr($encoded, '-_', '+/'), (int) (4 * ceil(strlen($encoded) / 4)), '=');
        $decoded = json_decode(base64_decode($padded), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array{label: string, value: string}> $options
     * @return array<int, array{label: string, value: string}>
     */
    private function withSkipOptionIfOptional(array $field, array $options): array
    {
        if (($field['required'] ?? false) === true) {
            return $options;
        }

        array_unshift($options, ['label' => 'Skip -- play without this effect', 'value' => self::SKIP_FIELD_VALUE]);

        // fieldOptions() itself already capped the real candidates at
        // MAX_SELECT_OPTIONS -- re-capping AFTER prepending Skip (rather
        // than reserving a slot up front, before knowing whether this
        // specific field even needs one) keeps that cap the single
        // source of truth for "how many real candidates," at the cost of
        // this array_slice, never actually exceeding Discord's own
        // 25-option limit on a select menu.
        return array_slice($options, 0, self::MAX_SELECT_OPTIONS);
    }

    /**
     * The embed + components for $gameId as $userId currently sees it --
     * shared by every command/component response that ends in "show the
     * board" (which is almost all of them). $notice, when given, is
     * shown as an extra embed field above the board (an error message
     * from a just-failed action, most often).
     *
     * Reported live: "would it be possible to use some kind of image
     * library to render, say, the cards in play as a single image to
     * embed in the game display message?" -- followed by explicit
     * scoping decisions ("directly in the main board message," "in-play
     * only for now"), so the 3rd tuple element below carries an embed
     * whenever there's a mood in play or a discard pile to show, pointing
     * at boardImageUrl()'s own signed, unauthenticated endpoint (see its
     * docblock for why that's needed at all).
     *
     * Two later follow-ups extend this same tuple element: "can we show
     * the discard pile as an image too?" -- folded into the SAME composite
     * image as an extra labeled row (discardImageRow()) rather than a
     * second embed, since it's public information exactly like in-play
     * cards are (see that method's own docblock for the row's own cap/
     * labeling); and "show the active user's hand as a composite image,
     * labeled 'your hand'" -- a SEPARATE embed/endpoint instead
     * (handImageUrl()), since a hand is private, per-viewer information
     * that can never share the public board image's own
     * signed-by-game-id-alone URL (see handImageUrl()'s own docblock).
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>, 2?: array<int, array<string, mixed>>}
     */
    private function boardMessage(int $gameId, int $userId, ?string $notice = null): array
    {
        try {
            $state = $this->games->getState($gameId, $userId);
        } catch (GameStateException $e) {
            return [$e->getMessage(), []];
        }

        $game = $state['game'];
        $webUrl = SiteUrl::root() . "/game/?id={$gameId}";

        if ($game['format'] !== self::SUPPORTED_FORMAT) {
            return ["Game #{$gameId} is a '{$game['format']}' game -- Discord only supports Traditional games so far. Open it in the web app: {$webUrl}", []];
        }

        if ($game['status'] === 'completed') {
            // Reported live: "the ephemeral message announcing the game
            // ending should mention who the winner was" -- this used to
            // fall into the generic "is '{$status}'" message below (every
            // action's own handleComponent() catch-all re-renders the
            // board via boardMessage() right after it runs, so the very
            // last thing a player who just won saw was a plain "Game #X
            // is 'completed'," with the actual result nowhere on screen).
            // winner_usernames (not the single winner_game_player_id) is
            // format 'team's own "both teammates" list -- moot for the
            // 'standard'-only format this class supports, but the same
            // field the web board's own "Game over" banner already reads.
            $winnerText = $game['winner_usernames'] !== []
                ? implode(' & ', $game['winner_usernames']) . ' won'
                : 'nobody won';
            $scoreLine = implode(', ', array_map(
                fn (array $player) => "{$player['username']}: {$player['total_wins']} round(s) won",
                $state['players'],
            ));

            return ["Game #{$gameId} is complete -- {$winnerText}! ({$scoreLine}) Open it in the web app: {$webUrl}", []];
        }

        if ($game['status'] !== 'in_progress') {
            return ["Game #{$gameId} is '{$game['status']}'. Open it in the web app: {$webUrl}", []];
        }

        $usernames = [];
        $scoreLines = [];
        foreach ($state['players'] as $player) {
            $usernames[$player['game_player_id']] = $player['username'];
            // Reported live: "we need to show ... number of rounds each
            // player has won so far, number of cards each player had in
            // hand" -- both already public information the web board
            // shows (players[].total_wins/hand_count, see buildGameState()),
            // just never surfaced here alongside the score.
            $scoreLines[] = "{$player['username']}: {$player['total_score']} pts, {$player['total_wins']} round(s) won, {$player['hand_count']} card(s) in hand";
        }

        $round = $state['round'];
        $you = $state['you'];
        $lines = ["**Game #{$gameId}** -- " . implode(', ', $scoreLines)];
        // Reported live: "the discord client game display needs to show
        // which player went first this round" -- went_first_game_player_id
        // (not the round's own bare first_game_player_id) is deliberately
        // used here: for format 'team' that column only ever names a
        // representative member of whichever team went first, not
        // necessarily the player who actually did (see
        // BoardState::roundFirstPlayerId()'s own docblock) -- moot for the
        // 'standard'-only format this class supports today, but this way
        // nothing here needs revisiting if that ever changes.
        $wentFirstUsername = $usernames[$round['went_first_game_player_id']] ?? 'nobody yet';
        $lines[] = "Round {$round['round_number']} -- {$wentFirstUsername} went first.";
        $lines[] = $this->inPlaySummary($state);
        $lines[] = $this->discardPileSummary($state);

        $decision = $round['pending_decision'] ?? null;
        $components = [];

        if ($decision !== null) {
            if ($decision['is_you'] ?? false) {
                $lines[] = "Waiting on YOUR response to {$decision['played_card_name']}.";
                $field = $decision['field'] ?? null;
                if ($field !== null && $this->isSupportedField($field)) {
                    $boardState = $this->boardStates->load($gameId);
                    $options = $this->fieldOptions($boardState, $field, $you['game_player_id'], 0, '', $state);
                    if ($options !== []) {
                        $components[] = ['type' => 1, 'components' => [
                            $this->fieldSelectComponent("ms:decision:{$gameId}", $field, $options),
                        ]];
                    } else {
                        $lines[] = "This needs more than Discord supports yet -- open the web app: {$webUrl}";
                    }
                } else {
                    $lines[] = "This needs more than Discord supports yet -- open the web app: {$webUrl}";
                }
            } else {
                $waitingOn = $usernames[$decision['target_game_player_id']] ?? 'another player';
                $lines[] = "Waiting on {$waitingOn} to respond to {$decision['played_card_name']}.";
            }
        } elseif ($you['is_your_turn'] ?? false) {
            $lines[] = "It's your turn -- {$round['plays_remaining']} play(s) remaining.";
            [$playOptions, $unsupportedNames] = $this->playableCardOptions($state);
            if ($playOptions !== []) {
                $components[] = ['type' => 1, 'components' => [[
                    'type' => 3,
                    'custom_id' => "ms:play:{$gameId}",
                    'placeholder' => 'Play a card...',
                    'options' => $playOptions,
                ]]];
            }
            $components[] = ['type' => 1, 'components' => [['type' => 2, 'style' => 2, 'label' => 'Pass', 'custom_id' => "ms:pass:{$gameId}"]]];
            if ($unsupportedNames !== []) {
                $lines[] = 'Needs the web app to play: ' . implode(', ', $unsupportedNames);
            }
        } else {
            $currentUsername = $round['current_turn_game_player_id'] !== null ? ($usernames[$round['current_turn_game_player_id']] ?? 'another player') : 'nobody yet';
            $lines[] = "Waiting on {$currentUsername}'s turn.";
        }

        $lines[] = 'Your hand: ' . ($you['hand'] === [] ? '(empty)' : implode(', ', array_map(
            fn (array $card) => $this->cardLabel($card),
            $you['hand'],
        )));

        $components[] = ['type' => 1, 'components' => [
            ['type' => 2, 'style' => 2, 'label' => 'Refresh', 'custom_id' => "ms:view:{$gameId}"],
            ['type' => 2, 'style' => 2, 'label' => 'View Cards', 'custom_id' => "ms:cards:{$gameId}"],
            ['type' => 2, 'style' => 2, 'label' => 'Game Log', 'custom_id' => "ms:log:{$gameId}"],
            ['type' => 2, 'style' => 5, 'label' => 'Open in browser', 'url' => $webUrl],
        ]];
        // Its own row -- the row above is already at Discord's own
        // 5-components-per-row cap.
        $components[] = $this->utilityButtonsRow();

        if ($notice !== null) {
            array_unshift($lines, $notice);
        }

        // Reported live: "the discord in play image is not being
        // updated as moods are played in the game" -- boardImageUrl()'s
        // own URL used to be a bare function of $gameId alone, identical
        // on every single render for the same game; Discord's own CDN
        // (like any HTTP client) caches an embed image by URL, so once
        // it fetched the composite image for THIS game once, every later
        // updateMessage() with that same unchanged URL just kept
        // reusing the stale cached copy, no matter how many moods got
        // played afterward. boardImageCacheKey() below appends a query
        // param that changes exactly when the rendered picture actually
        // would (see its own docblock) -- NOT part of the HMAC signature
        // itself (verifyBoardImageSignature() below still only ever
        // covers $gameId), so it's purely a cache-busting hint for
        // Discord's own fetch, never anything the server itself trusts
        // for authorization.
        $embeds = [];
        if ($state['in_play'] !== [] || $state['discard_pile'] !== []) {
            $embeds[] = ['image' => ['url' => $this->boardImageUrl($gameId, $this->boardImageCacheKey($state['in_play'], $state['discard_pile']))]];
        }
        // Reported live: "show the active user's hand as a composite
        // image, labeled 'your hand'" -- its own embed/endpoint, never
        // folded into the board image above the way the discard pile was
        // (see handImageUrl()'s own docblock for why a hand can't share
        // that public, signed-by-game-id-alone URL).
        if ($you['hand'] !== []) {
            $embeds[] = ['image' => ['url' => $this->handImageUrl($you['game_player_id'], $this->handImageCacheKey($you['hand']))]];
        }

        return [implode("\n", $lines), $components, $embeds];
    }

    /**
     * A short fingerprint of exactly what BoardImageRenderer actually
     * draws from $inPlay -- which card art file each cell shows
     * (catalog_card_id), whose row it's in (owner_game_player_id), and
     * its own current-value badge (value) -- appended to boardImageUrl()'s
     * own URL purely so Discord's own CDN treats a genuinely different
     * board as a genuinely different resource to (re)fetch, while an
     * unchanged board (a plain "Refresh" click, re-running /moodswings
     * with nothing new having happened) keeps the exact same URL and
     * lets Discord keep serving its own already-cached copy instead of
     * needlessly re-fetching. `card_id` alone would already change
     * whenever a DIFFERENT card enters/leaves play, but a Creativity
     * copy's own catalog_card_id/value can change on the SAME card_id
     * (its copy target changing) without ever leaving play, so both
     * still need including. Truncated to 12 hex chars -- this only ever
     * needs to be "different when the board is," not cryptographically
     * unique; the real access control is verifyBoardImageSignature()'s
     * own HMAC, computed over $gameId alone, never this value.
     *
     * $discardPile is folded into this same fingerprint (only its own
     * most recent MAX_DISCARD_CARDS_SHOWN, matching discardImageRow()'s
     * own slice -- an older discard falling further off-screen either way
     * shouldn't force a needless re-fetch of a picture that would render
     * identically) since a card discarded straight from hand (Compulsion's
     * own target, a `discard_card` effect, ...) changes the discard pile
     * with $inPlay staying completely untouched -- without this, that
     * case would silently repeat this method's own earlier in-play-only
     * bug (see the docblock above the call site) for the discard row
     * specifically.
     *
     * @param array<int, array<string, mixed>> $inPlay
     * @param array<int, array<string, mixed>> $discardPile
     */
    private function boardImageCacheKey(array $inPlay, array $discardPile): string
    {
        $signature = [
            array_map(
                static fn (array $card) => [$card['card_id'], $card['catalog_card_id'], $card['owner_game_player_id'], $card['value']],
                $inPlay,
            ),
            array_map(
                static fn (array $card) => [$card['card_id'], $card['catalog_card_id'], $card['value']],
                array_slice($discardPile, -self::MAX_DISCARD_CARDS_SHOWN),
            ),
        ];

        return substr(md5(json_encode($signature)), 0, 12);
    }

    /**
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function newPracticeGameMessage(int $userId): array
    {
        $bots = $this->games->listPracticeBots();
        if ($bots === []) {
            return ['No practice bots are configured on this deployment.', []];
        }

        if (count($bots) === 1) {
            return $this->createPracticeGameMessage($userId, $bots[0]['user_id']);
        }

        $options = array_map(
            fn (array $bot) => ['label' => $bot['username'], 'value' => (string) $bot['user_id']],
            array_slice($bots, 0, self::MAX_SELECT_OPTIONS),
        );

        $components = [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => 'ms:newgamebot:0',
            'placeholder' => 'Choose a practice bot...',
            'options' => $options,
        ]]]];

        return ['Choose a practice bot to play against:', $components];
    }

    /**
     * Creates the practice game and hands straight back to boardMessage()
     * for the very same game. createGame() alone only ever leaves a game
     * 'waiting' (see its own docblock) -- startGame() is what actually
     * deals every seat's deck_type 'structure' cards and flips it to
     * 'in_progress', and advanceAutomatedTurns() covers the case where
     * the very first turn already belongs to the bot itself (or an
     * auto-passed empty hand) -- the same two-call sequence
     * `POST /games/start` already runs for a web-created game, just
     * without the extra HTTP round trip. Errors here (e.g. $botUserId no
     * longer a valid practice bot) get their own plain message rather
     * than falling through to boardMessage() for a game id that may not
     * even exist.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function createPracticeGameMessage(int $userId, int $botUserId): array
    {
        try {
            $gameId = $this->games->createGame($userId, [$userId, $botUserId]);
            $this->games->startGame($gameId);
            $this->games->advanceAutomatedTurns($gameId);
        } catch (\Throwable $e) {
            return ["Couldn't start a practice game: " . $e->getMessage(), []];
        }

        return $this->boardMessage($gameId, $userId);
    }

    /**
     * Reported live: "let's add the ability to create a game for another
     * human on the friend list" -- this class's own docblock originally
     * flagged a human opponent as needing "Discord's own way to
     * pick/invite another linked player, real design work... out of
     * scope for a first pass." That design turns out simpler than it
     * sounds: `createGame()` seats ANY user id immediately (same as
     * `createPracticeGameMessage()` already does for a bot's own user
     * id) -- the friend doesn't need to already be Discord-linked, or
     * present in this interaction at all, the same way the web app's own
     * New Game dialog friend-picker seats a friend who isn't online
     * right now. `FriendshipService::listFriends()` -- accepted
     * friendships only, same "on the friend list" the web app's own
     * picker uses -- supplies the candidates; picking one goes straight
     * to createFriendGameMessage() below, no separate invite/accept step
     * (matching the web app's own createGame() flow, which has none
     * either -- see that method's own docblock).
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function newFriendGameMessage(int $userId): array
    {
        $friends = $this->friendships->listFriends($userId);
        if ($friends === []) {
            return [
                'You don\'t have any friends added yet. Add one at ' . SiteUrl::root() . '/game/?open_friends=1, then try again.',
                [],
            ];
        }

        $options = array_map(
            fn (array $friend) => ['label' => $friend['friend_username'], 'value' => (string) $friend['friend_id']],
            array_slice($friends, 0, self::MAX_SELECT_OPTIONS),
        );

        $components = [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => 'ms:friendgamewith:0',
            'placeholder' => 'Choose a friend...',
            'options' => $options,
        ]]]];

        return ['Choose a friend to play against:', $components];
    }

    /**
     * Completes newFriendGameMessage()'s own picker -- same
     * createGame()/startGame()/advanceAutomatedTurns() sequence
     * createPracticeGameMessage() already runs (see that method's own
     * docblock for why all three), just seating $opponentUserId instead
     * of a bot's own user id. advanceAutomatedTurns() is still worth
     * calling even though a human opponent is never a bot -- see its own
     * docblock's other trigger, a default-on empty-hand auto-pass,
     * which applies to ANY seated player, not just bots. Unlike a
     * practice bot, the opponent here isn't present to take a first
     * turn of their own -- boardMessage() already renders that as an
     * ordinary "waiting on {username}'s turn" (or, on the roughly half
     * of coin flips where $userId goes first instead, their own hand and
     * playable options), the exact same board a fresh web-created game
     * against this same friend would show.
     *
     * $opponentUserId is trusted here the same way `POST /games`
     * trusts its own `opponent_user_ids` body param (see that route's
     * own docblock) -- ANY user id createGame() is given gets seated
     * immediately, friend or not, matching that route's own "the
     * friends-only picker is a UI convenience, not an authorization
     * boundary" design throughout the app. A stale/tampered value here
     * fails no differently than it would there.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function createFriendGameMessage(int $userId, int $opponentUserId): array
    {
        try {
            $gameId = $this->games->createGame($userId, [$userId, $opponentUserId]);
            $this->games->startGame($gameId);
            $this->games->advanceAutomatedTurns($gameId);
        } catch (\Throwable $e) {
            return ["Couldn't start a game: " . $e->getMessage(), []];
        }

        return $this->boardMessage($gameId, $userId);
    }

    /**
     * Power Duel via Discord (issue #233 follow-up, reported live: "add
     * support for a constructed format... let people submit their deck
     * list or choose from one they've previously saved"). Unlike the
     * 'standard'/'structure' games above, `format => 'duel'` with
     * `deck_type => 'custom_duel'` under the 'power' rules preset (≥15
     * cards, singleton, ≤1 Mythic -- see DuelDeckRules::forPreset())
     * leaves createGame() in status 'waiting' with BOTH seats deckless --
     * there's no auto-dealt deck for it to fall back on the way
     * 'structure' games have. Deliberately scoped to friend invites only
     * (matches createFriendGameMessage()'s own no-invite-step design,
     * same reasoning: real design work turns out not to be needed), and
     * to setup/deck-submission only -- once both sides have submitted and
     * startGame() actually flips the game 'in_progress', boardMessage()'s
     * own existing SUPPORTED_FORMAT check already sends the player to the
     * web app to actually play it out, exactly the same "needs more than
     * Discord supports yet" treatment Team/Closed Team/draft formats
     * already get. Nothing here teaches this class to render a 'duel'
     * format board turn-by-turn -- that's a separate, bigger scope
     * decision this feature doesn't need to make.
     *
     * Custom_id scheme, all new for this feature: `ms:powerduel:0` (the
     * root menu, listing the caller's own active Power Duel games),
     * `ms:powerduelinvite:0` (friend picker), `ms:powerduelwith:0`
     * (creates the game against the chosen friend), `ms:powerduelgame:
     * {gameId}` (re-opens the deck-submission prompt for an
     * already-created game still needing the caller's own deck),
     * `ms:deckchooseopen:{gameId}`/`ms:deckchooseforgame:{gameId}`
     * (choose a saved decklist for that seat), `ms:deckpasteopen:
     * {gameId}` (opens the paste-a-decklist MODAL) /
     * `ms:deckpastesubmit:{gameId}` (that modal's own MODAL_SUBMIT).
     */
    private function powerDuelMenuMessage(int $userId): array
    {
        $games = $this->activePowerDuelGamesFor($userId);

        $lines = [];
        $actionButtons = [];
        foreach (array_slice($games, 0, 4) as $game) {
            $opponent = $this->otherPlayerUsername($game, $userId);

            if ($game['status'] === 'waiting' && $this->games->customDuelDeckStillNeededFrom($game['id'], $userId)) {
                $lines[] = "Game #{$game['id']} vs {$opponent}: needs your decklist.";
                $actionButtons[] = ['type' => 2, 'style' => 1, 'label' => "Submit deck (#{$game['id']})", 'custom_id' => "ms:powerduelgame:{$game['id']}"];
            } elseif ($game['status'] === 'waiting') {
                $lines[] = "Game #{$game['id']} vs {$opponent}: waiting on their decklist.";
            } else {
                $lines[] = "Game #{$game['id']} vs {$opponent}: {$game['status']}.";
            }
        }
        if ($games === []) {
            $lines[] = 'No active Power Duel games yet.';
        }

        $components = [];
        foreach (array_chunk($actionButtons, 5) as $row) {
            $components[] = ['type' => 1, 'components' => $row];
        }
        $components[] = ['type' => 1, 'components' => [
            ['type' => 2, 'style' => 2, 'label' => 'Invite a Friend', 'custom_id' => 'ms:powerduelinvite:0'],
            ['type' => 2, 'style' => 2, 'label' => 'vs Practice Bot', 'custom_id' => 'ms:powerduelbotmenu:0'],
        ]];

        return [implode("\n", $lines), $components];
    }

    /** @return array<int, array<string, mixed>> */
    private function activePowerDuelGamesFor(int $userId): array
    {
        return array_values(array_filter(
            $this->games->listGamesForUser($userId),
            static fn (array $game): bool => $game['format'] === 'duel'
                && $game['deck_type'] === 'custom_duel'
                && $game['custom_duel_rules_preset'] === 'power',
        ));
    }

    /** @param array<string, mixed> $game */
    private function otherPlayerUsername(array $game, int $viewerUserId): string
    {
        foreach ($game['players'] as $player) {
            if ($player['user_id'] !== $viewerUserId) {
                return $player['username'];
            }
        }

        return 'your opponent';
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function newPowerDuelFriendMessage(int $userId): array
    {
        $friends = $this->friendships->listFriends($userId);
        if ($friends === []) {
            return [
                'You don\'t have any friends added yet. Add one at ' . SiteUrl::root() . '/game/?open_friends=1, then try again.',
                [],
            ];
        }

        $options = array_map(
            fn (array $friend) => ['label' => $friend['friend_username'], 'value' => (string) $friend['friend_id']],
            array_slice($friends, 0, self::MAX_SELECT_OPTIONS),
        );

        $components = [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => 'ms:powerduelwith:0',
            'placeholder' => 'Choose a friend...',
            'options' => $options,
        ]]]];

        return ['Choose a friend for a Power Duel:', $components];
    }

    /**
     * Same "no invite/accept step" reasoning as createFriendGameMessage()
     * -- createGame() seats $opponentUserId immediately -- but stops
     * there instead of also calling startGame(): a custom_duel game has
     * no deck to deal yet, so the very next step is prompting the caller
     * for their own (deckSubmissionPromptMessage() below). The invited
     * friend gets no notification from THIS call -- only once the caller
     * actually submits a deck does notifyRemainingCustomDuelDecksNeeded()
     * (GameService::submitCustomDuelDeck()'s own hook) tell them a game
     * is waiting on them, the same "nothing to notify about until there's
     * actually something to act on" reasoning bare game creation follows
     * everywhere else in this app.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function createPowerDuelGameMessage(int $userId, int $opponentUserId): array
    {
        try {
            $gameId = $this->games->createGame($userId, [$userId, $opponentUserId], format: 'duel', deckType: 'custom_duel', duelDeckRules: ['preset' => 'power']);
        } catch (\Throwable $e) {
            return ["Couldn't start a Power Duel: " . $e->getMessage(), []];
        }

        return $this->deckSubmissionPromptMessage($gameId, "Power Duel created (Game #{$gameId})! Submit your own decklist to get it started:");
    }

    /**
     * Power Duel vs. a practice bot (reported live: "I want to be able to
     * create a power duel game with a bot opponent, as well"). Unlike a
     * friend, a bot never calls submitCustomDuelDeck() for itself -- there
     * has to be a real decklist supplied for its seat at createGame() time
     * or that call throws (see createGame()'s own docblock: "A decklist
     * for each seated practice bot is required for a custom_duel game").
     * Rather than asking the caller to type/paste a decklist ON THE BOT'S
     * BEHALF, this reuses their own saved-decklist picker for its seat too
     * (`botSavedDecklistId` -- the exact param the web app's own New Game
     * dialog already feeds from its per-bot "Use a saved deck" select when
     * deck_type is custom_duel) -- no random/generated-deck logic needed,
     * and the caller always knows exactly what the bot is playing.
     *
     * Custom_id scheme: `ms:powerduelbotmenu:0` (bot picker, this method),
     * `ms:powerduelbot:0` (values[0] = chosen bot's user id) ->
     * choosePowerDuelBotDeckMessage(), `ms:powerduelbotdeck:{botUserId}`
     * (values[0] = chosen saved decklist id) ->
     * createPowerDuelGameWithBotMessage().
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function newPowerDuelBotMessage(int $userId): array
    {
        $bots = $this->games->listPracticeBots();
        if ($bots === []) {
            return ['No practice bots are configured on this deployment.', []];
        }

        if (count($bots) === 1) {
            return $this->choosePowerDuelBotDeckMessage($userId, $bots[0]['user_id']);
        }

        $options = array_map(
            fn (array $bot) => ['label' => $bot['username'], 'value' => (string) $bot['user_id']],
            array_slice($bots, 0, self::MAX_SELECT_OPTIONS),
        );

        $components = [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => 'ms:powerduelbot:0',
            'placeholder' => 'Choose a practice bot...',
            'options' => $options,
        ]]]];

        return ['Choose a practice bot for a Power Duel:', $components];
    }

    /**
     * The bot's OWN deck for the game about to be created -- picked from
     * the caller's own saved decklists (listForViewer()'s own 'own' key,
     * same as every other saved-decklist picker in this class), since
     * there's no random-deck generator in this codebase to fall back on.
     * An empty list points at My Decks instead of offering a dead end.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function choosePowerDuelBotDeckMessage(int $userId, int $botUserId): array
    {
        $decklists = $this->userDecklists->listForViewer($userId)['own'];
        if ($decklists === []) {
            return [
                'You have no saved decklists yet -- save one from My Decks first, then start this Power Duel again.',
                [['type' => 1, 'components' => [$this->myDecksButton()]]],
            ];
        }

        $options = array_map(
            fn (array $d) => ['label' => "{$d['name']} ({$d['card_count']} cards)", 'value' => (string) $d['id']],
            array_slice($decklists, 0, self::MAX_SELECT_OPTIONS),
        );

        $components = [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => "ms:powerduelbotdeck:{$botUserId}",
            'placeholder' => "Choose the bot's decklist...",
            'options' => $options,
        ]]]];

        return ["Choose a decklist for the bot to play:", $components];
    }

    /**
     * Completes the bot-picker/bot-deck-picker flow above -- createGame()
     * resolves $botDecklistId into a real submitCustomDuelDeck() call for
     * the bot's own seat SYNCHRONOUSLY, inside its own transaction (see
     * that method's own docblock), so unlike createPowerDuelGameMessage()
     * (the human-vs-human path) the bot's side of this game is already
     * fully set up the instant this returns -- only the caller's own
     * decklist is still needed, via the exact same
     * deckSubmissionPromptMessage() the friend flow already ends with.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function createPowerDuelGameWithBotMessage(int $userId, int $botUserId, int $botDecklistId): array
    {
        try {
            $gameId = $this->games->createGame(
                $userId,
                [$userId, $botUserId],
                format: 'duel',
                deckType: 'custom_duel',
                duelDeckRules: ['preset' => 'power'],
                botSavedDecklistId: $botDecklistId,
            );
        } catch (\Throwable $e) {
            return ["Couldn't start a Power Duel: " . $e->getMessage(), []];
        }

        return $this->deckSubmissionPromptMessage($gameId, "Power Duel created (Game #{$gameId})! Submit your own decklist to get it started:");
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function deckSubmissionPromptMessage(int $gameId, string $intro): array
    {
        $components = [['type' => 1, 'components' => [
            ['type' => 2, 'style' => 1, 'label' => 'Choose Saved Deck', 'custom_id' => "ms:deckchooseopen:{$gameId}"],
            ['type' => 2, 'style' => 2, 'label' => 'Paste New Decklist', 'custom_id' => "ms:deckpasteopen:{$gameId}"],
        ]]];

        return [$intro, $components];
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function chooseSavedDeckForGameMessage(int $userId, int $gameId): array
    {
        $decklists = $this->userDecklists->listForViewer($userId)['own'];
        if ($decklists === []) {
            return [
                'You have no saved decklists yet -- paste a new one instead.',
                [['type' => 1, 'components' => [['type' => 2, 'style' => 2, 'label' => 'Paste New Decklist', 'custom_id' => "ms:deckpasteopen:{$gameId}"]]]],
            ];
        }

        $options = array_map(
            fn (array $d) => ['label' => "{$d['name']} ({$d['card_count']} cards)", 'value' => (string) $d['id']],
            array_slice($decklists, 0, self::MAX_SELECT_OPTIONS),
        );

        $components = [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => "ms:deckchooseforgame:{$gameId}",
            'placeholder' => 'Choose a decklist...',
            'options' => $options,
        ]]]];

        return ['Choose a saved decklist for this game:', $components];
    }

    /**
     * The common tail of every "a deck was just submitted for $gameId"
     * path (a saved decklist picked, or a pasted one just parsed) --
     * startGame() itself is the only signal needed for whether the OTHER
     * seat has submitted yet: it throws GameStateException (caught,
     * swallowed) until every seat has, so catching it IS the "still
     * waiting on your opponent" case, never a real error to surface.
     * Once it succeeds, boardMessage()'s own existing SUPPORTED_FORMAT
     * check takes over -- see this section's own docblock for why
     * nothing further is needed here to hand off to the web app.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>, 2?: array<int, array<string, mixed>>}
     */
    private function afterDeckSubmittedMessage(int $gameId, int $userId): array
    {
        try {
            $this->games->startGame($gameId);
        } catch (GameStateException) {
            return ["Deck submitted for Game #{$gameId}! Waiting on your opponent to submit theirs.", []];
        }

        $this->games->advanceAutomatedTurns($gameId);

        return $this->boardMessage($gameId, $userId);
    }

    /**
     * "My Decks" (issue #233 follow-up, reported live: "if they upload a
     * text decklist that they could at least save it and update it from
     * the Discord interface") -- a standalone deck-management menu,
     * reachable independent of any specific game, wrapping
     * UserDecklistService exactly the way this class already wraps
     * GameService for everything else. Only the caller's OWN decklists
     * (listForViewer()'s own 'own' key) -- a friend's shared decklists
     * are for USING in a game (submitCustomDuelDeck()'s own
     * $savedDecklistId), not managing here.
     *
     * Custom_id scheme: `ms:deck:0` (this menu), `ms:deckview:0` (a
     * select; its value is the chosen decklist id), `ms:deckcreateopen:0`
     * / `ms:deckeditopen:{decklistId}` (open the create/edit MODAL --
     * see decklistModalResponse()), `ms:deckdelete:{decklistId}`.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function deckMenuMessage(int $userId): array
    {
        $decklists = $this->userDecklists->listForViewer($userId)['own'];
        $newDecklistButton = ['type' => 2, 'style' => 1, 'label' => 'New Decklist', 'custom_id' => 'ms:deckcreateopen:0'];

        if ($decklists === []) {
            return ['You have no saved decklists yet.', [['type' => 1, 'components' => [$newDecklistButton]]]];
        }

        $options = array_map(
            fn (array $d) => ['label' => "{$d['name']} ({$d['card_count']} cards)", 'value' => (string) $d['id']],
            array_slice($decklists, 0, self::MAX_SELECT_OPTIONS),
        );

        $components = [
            ['type' => 1, 'components' => [[
                'type' => 3,
                'custom_id' => 'ms:deckview:0',
                'placeholder' => 'Choose a decklist...',
                'options' => $options,
            ]]],
            ['type' => 1, 'components' => [$newDecklistButton]],
        ];

        return ['Your saved decklists:', $components];
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function deckDetailMessage(int $userId, int $decklistId): array
    {
        try {
            $deck = $this->userDecklists->cardIdsForUse($userId, $decklistId);
        } catch (\Throwable $e) {
            return ["Couldn't open that decklist: " . $e->getMessage(), []];
        }

        $lines = ['**' . ($deck['name'] ?? 'Untitled deck') . '** -- ' . count($deck['cardIds']) . ' card(s)'];
        if ($deck['sideboardCardIds'] !== []) {
            $lines[] = count($deck['sideboardCardIds']) . ' sideboard card(s)';
        }

        $components = [['type' => 1, 'components' => [
            ['type' => 2, 'style' => 1, 'label' => 'Edit', 'custom_id' => "ms:deckeditopen:{$decklistId}"],
            ['type' => 2, 'style' => 4, 'label' => 'Delete', 'custom_id' => "ms:deckdelete:{$decklistId}"],
            ['type' => 2, 'style' => 2, 'label' => 'Back', 'custom_id' => 'ms:deck:0'],
        ]]];

        return [implode("\n", $lines), $components];
    }

    /**
     * Opens the edit MODAL pre-filled with $decklistId's own current
     * contents -- decklistToText() is the exact inverse of
     * DecklistParser::parse() (grouped/counted, "N CardName" per line),
     * so re-submitting the modal unchanged round-trips to the same
     * cardIds it started from.
     *
     * @return array<string, mixed>
     */
    private function deckEditModalResponse(int $userId, int $decklistId): array
    {
        try {
            $deck = $this->userDecklists->cardIdsForUse($userId, $decklistId);
        } catch (\Throwable $e) {
            return $this->updateMessage("Couldn't open that decklist: " . $e->getMessage());
        }

        return $this->decklistModalResponse(
            "ms:deckeditsubmit:{$decklistId}",
            'Edit Decklist',
            $deck['name'] ?? '',
            $this->decklistToText($deck['cardIds'], $deck['sideboardCardIds']),
        );
    }

    /**
     * The inverse of DecklistParser::parse() -- $cardIds/$sideboardCardIds
     * legally contain the same catalog id more than once (a deck's own
     * "2 Joy"), so this groups and counts rather than emitting one line
     * per array entry. No "About"/"Name" header emitted here (unlike the
     * text format DecklistParser itself also accepts) -- the modal this
     * feeds already has its own separate "Deck name" field, so folding a
     * second copy of the name into the decklist body text itself would
     * just be redundant.
     *
     * @param int[] $cardIds
     * @param int[] $sideboardCardIds
     */
    private function decklistToText(array $cardIds, array $sideboardCardIds): string
    {
        $rowsById = CardCatalog::load()['rowsById'];
        $lines = $this->countedCardLines($cardIds, $rowsById);

        if ($sideboardCardIds !== []) {
            $lines[] = '';
            $lines[] = 'Sideboard';
            $lines = [...$lines, ...$this->countedCardLines($sideboardCardIds, $rowsById)];
        }

        return implode("\n", $lines);
    }

    /**
     * @param int[] $cardIds
     * @param array<int, array<string, mixed>> $rowsById
     * @return string[]
     */
    private function countedCardLines(array $cardIds, array $rowsById): array
    {
        $counts = [];
        foreach ($cardIds as $cardId) {
            $counts[$cardId] = ($counts[$cardId] ?? 0) + 1;
        }

        $lines = [];
        foreach ($counts as $cardId => $count) {
            $lines[] = "{$count} " . $rowsById[$cardId]['name'];
        }

        return $lines;
    }

    /**
     * The MODAL (interaction response type 9) Discord shows for "New
     * Decklist"/"Edit" -- two separate text inputs (a short "Deck name"
     * and a paragraph "Decklist," 4000 chars, comfortably covering even a
     * generous Power Duel list plus sideboard) rather than folding the
     * name into the decklist text's own optional "About/Name" header the
     * way a raw file upload would -- Discord's own modal already gives a
     * natural separate field for it, so there's no reason to also make
     * users type a two-line header block by hand.
     *
     * @return array<string, mixed>
     */
    private function decklistModalResponse(string $customId, string $title, string $prefillName, string $prefillDecklist): array
    {
        return ['type' => 9, 'data' => [
            'custom_id' => $customId,
            'title' => $title,
            'components' => [
                ['type' => 1, 'components' => [[
                    'type' => 4,
                    'custom_id' => 'name',
                    'style' => 1,
                    'label' => 'Deck name',
                    'value' => $prefillName,
                    'max_length' => 120,
                    'required' => true,
                ]]],
                ['type' => 1, 'components' => [[
                    'type' => 4,
                    'custom_id' => 'decklist',
                    'style' => 2,
                    'label' => 'Decklist (e.g. "2 Joy", one card per line)',
                    'value' => $prefillDecklist,
                    'max_length' => 4000,
                    'required' => true,
                ]]],
            ],
        ]];
    }

    /**
     * The single-field variant, for pasting a decklist directly into a
     * pending Power Duel seat -- submitCustomDuelDeck() has no separate
     * "deck name" parameter of its own to set from a second field here
     * (unlike UserDecklistService::create()/update()), so there's nothing
     * for a second input to feed.
     *
     * @return array<string, mixed>
     */
    private function singleDecklistModalResponse(string $customId, string $title): array
    {
        return ['type' => 9, 'data' => [
            'custom_id' => $customId,
            'title' => $title,
            'components' => [
                ['type' => 1, 'components' => [[
                    'type' => 4,
                    'custom_id' => 'decklist',
                    'style' => 2,
                    'label' => 'Decklist (e.g. "2 Joy", one card per line)',
                    'value' => '',
                    'max_length' => 4000,
                    'required' => true,
                ]]],
            ],
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string> custom_id => submitted value, for
     *     every text input in a MODAL_SUBMIT payload's own components
     */
    private function modalFieldValues(array $payload): array
    {
        $values = [];
        foreach ($payload['data']['components'] ?? [] as $row) {
            foreach ($row['components'] ?? [] as $field) {
                if (isset($field['custom_id'])) {
                    $values[$field['custom_id']] = (string) ($field['value'] ?? '');
                }
            }
        }

        return $values;
    }

    /**
     * MODAL_SUBMIT (Discord interaction type 5) -- see
     * DiscordInteractionsService::handle()'s own docblock for why this is
     * a whole separate interaction type from handleComponent()'s
     * MESSAGE_COMPONENT (a modal's own text inputs arrive nested under
     * `data.components`, never `data.values`). Always responds with type
     * 7 (UPDATE_MESSAGE) -- every modal this class ever opens is itself
     * opened FROM a component click on an existing ephemeral message
     * (never a fresh slash command), so updating that same message in
     * place is the right response here too, same convention
     * handleComponent() already follows for everything else.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleModalSubmit(array $payload): array
    {
        $userId = $this->resolveUserId($payload);
        if ($userId === null) {
            return $this->updateMessage($this->unlinkedAccountMessage());
        }

        $customId = (string) ($payload['data']['custom_id'] ?? '');
        $parts = explode(':', $customId);
        if (($parts[0] ?? '') !== 'ms' || !isset($parts[1], $parts[2])) {
            return $this->updateMessage('Something about that action was not recognized -- try running /moodswings again.');
        }

        $verb = $parts[1];
        $arg = (int) $parts[2];
        $fields = $this->modalFieldValues($payload);

        try {
            switch ($verb) {
                case 'deckcreatesubmit':
                    $decklistId = $this->userDecklists->create($userId, $fields['name'] ?? '', $fields['decklist'] ?? '', null, null, 'private');

                    return $this->updateMessage(...$this->deckDetailMessage($userId, $decklistId));
                case 'deckeditsubmit':
                    $this->userDecklists->update($userId, $arg, $fields['name'] ?? '', $fields['decklist'] ?? '', null, null, 'private');

                    return $this->updateMessage(...$this->deckDetailMessage($userId, $arg));
                case 'deckpastesubmit':
                    $gamePlayerId = $this->requireSeatedIn($arg, $userId);
                    $this->games->submitCustomDuelDeck($arg, $gamePlayerId, $fields['decklist'] ?? '', null, $userId);

                    return $this->updateMessage(...$this->afterDeckSubmittedMessage($arg, $userId));
                default:
                    return $this->updateMessage('Something about that action was not recognized -- try running /moodswings again.');
            }
        } catch (\Throwable $e) {
            return $this->updateMessage("Couldn't do that: " . $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function newGameButton(): array
    {
        return ['type' => 2, 'style' => 2, 'label' => 'New Practice Game', 'custom_id' => 'ms:newgame:0'];
    }

    /** @return array<string, mixed> */
    private function inviteFriendButton(): array
    {
        return ['type' => 2, 'style' => 2, 'label' => 'Invite a Friend', 'custom_id' => 'ms:friendgame:0'];
    }

    /** @return array<string, mixed> */
    private function myDecksButton(): array
    {
        return ['type' => 2, 'style' => 2, 'label' => 'My Decks', 'custom_id' => 'ms:deck:0'];
    }

    /** @return array<string, mixed> */
    private function powerDuelButton(): array
    {
        return ['type' => 2, 'style' => 2, 'label' => 'Power Duel', 'custom_id' => 'ms:powerduel:0'];
    }

    /**
     * The row of "start/manage something new" buttons appended to every
     * message this class ever shows that has room for it (the no-active-
     * game message, the multi-game picker, and every ordinary board
     * render) -- factored out once "My Decks"/"Power Duel" joined the
     * original "New Practice Game"/"Invite a Friend" pair below, so all
     * three call sites stay in sync automatically rather than needing the
     * same 4-button array kept hand-in-sync in three places. 4 buttons is
     * still comfortably under Discord's own 5-per-row cap.
     *
     * @return array<string, mixed>
     */
    private function utilityButtonsRow(): array
    {
        return ['type' => 1, 'components' => [
            $this->newGameButton(),
            $this->inviteFriendButton(),
            $this->myDecksButton(),
            $this->powerDuelButton(),
        ]];
    }

    /**
     * Reported live right after the "New Practice Game" button shipped:
     * "we need to be able to see what cards are in play" -- every prior
     * boardMessage() only ever showed the viewer's OWN hand, never the
     * board itself, making a mood-targeting choice (Hate's "put any mood
     * on the bottom of the deck," Conviction's own self-targetable
     * equivalent, ...) close to a guess. In-play cards are public
     * information (unlike a hand), so this is shown to every viewer the
     * same way regardless of whose turn it is.
     *
     * @param array<string, mixed> $state
     */
    private function inPlaySummary(array $state): string
    {
        $byOwner = [];
        foreach ($state['in_play'] as $card) {
            $byOwner[$card['owner_game_player_id']][] = $this->cardLabel($card);
        }

        $lines = [];
        foreach ($state['players'] as $player) {
            $cards = $byOwner[$player['game_player_id']] ?? [];
            $lines[] = "{$player['username']}'s moods in play: " . ($cards === [] ? '(none)' : implode(', ', $cards));
        }

        return implode("\n", $lines);
    }

    /**
     * Reported live: "the user needs to be able to see the discard pile
     * in the discord client" -- the "View Cards" button (cardsMessage())
     * already lets a player look up ONE discard-pile card's own full
     * detail, but there was still no way to see the discard pile AT A
     * GLANCE the way inPlaySummary() already does for moods in play, so
     * checking whether a given card is even still available (Corruption's
     * own "cycle discard-pile cards," a `discard_card` field's own
     * candidate list, ...) meant opening that browse screen and paging
     * through one select menu. Public information (unlike a hand) the
     * same way in-play cards are, so this is shown to every viewer
     * regardless of whose turn it is. Capped defensively (unlike
     * inPlaySummary(), which never gets long enough to need it) -- a
     * long game's discard pile can run to 100+ cards, which would
     * otherwise risk pushing this single line, on top of everything else
     * boardMessage() already shows, past Discord's own 2000-char message
     * content limit.
     *
     * @param array<string, mixed> $state
     */
    private function discardPileSummary(array $state): string
    {
        $pile = $state['discard_pile'] ?? [];
        if ($pile === []) {
            return 'Discard pile: (empty)';
        }

        $names = array_map(fn (array $card) => $this->cardLabel($card), $pile);
        $line = 'Discard pile (' . count($pile) . '): ' . implode(', ', $names);

        if (strlen($line) > 900) {
            $line = substr($line, 0, 897) . '...';
        }

        return $line;
    }

    /**
     * Reported live: "some way to view the card details for the cards in
     * hand/play/discard" -- boardMessage() only ever shows a bare
     * "Name (value)" for each card, never CardCatalog's own rules_text.
     * One select menu per zone (hand/in_play/discard), each capped at
     * MAX_SELECT_OPTIONS the same way every other select in this class
     * is -- a long game's discard pile can exceed that, so it keeps the
     * MOST RECENT discards (array_slice's negative length) rather than
     * the earliest ones, since those are the ones a player is actually
     * likely to want to check on.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function cardsMessage(int $gameId, int $userId): array
    {
        try {
            $state = $this->games->getState($gameId, $userId);
        } catch (GameStateException $e) {
            return [$e->getMessage(), []];
        }

        $usernames = [];
        foreach ($state['players'] as $player) {
            $usernames[$player['game_player_id']] = $player['username'];
        }

        $components = [];

        $handOptions = array_map(
            fn (array $card) => ['label' => $this->cardLabel($card), 'value' => (string) $card['card_id']],
            array_slice($state['you']['hand'] ?? [], 0, self::MAX_SELECT_OPTIONS),
        );
        if ($handOptions !== []) {
            $components[] = ['type' => 1, 'components' => [[
                'type' => 3, 'custom_id' => "ms:cardhand:{$gameId}", 'placeholder' => 'View a card in your hand...', 'options' => $handOptions,
            ]]];
        }

        $inPlayOptions = array_map(
            fn (array $card) => ['label' => $this->cardLabel($card) . ' -- ' . ($usernames[$card['owner_game_player_id']] ?? '?'), 'value' => (string) $card['card_id']],
            array_slice($state['in_play'] ?? [], 0, self::MAX_SELECT_OPTIONS),
        );
        if ($inPlayOptions !== []) {
            $components[] = ['type' => 1, 'components' => [[
                'type' => 3, 'custom_id' => "ms:cardplay:{$gameId}", 'placeholder' => 'View a card in play...', 'options' => $inPlayOptions,
            ]]];
        }

        $discardOptions = array_map(
            fn (array $card) => ['label' => $this->cardLabel($card) . ' -- ' . ($card['last_owner_name'] ?? '?'), 'value' => (string) $card['card_id']],
            array_slice($state['discard_pile'] ?? [], -self::MAX_SELECT_OPTIONS),
        );
        if ($discardOptions !== []) {
            $components[] = ['type' => 1, 'components' => [[
                'type' => 3, 'custom_id' => "ms:carddiscard:{$gameId}", 'placeholder' => 'View a card in the discard pile...', 'options' => $discardOptions,
            ]]];
        }

        $components[] = ['type' => 1, 'components' => [['type' => 2, 'style' => 2, 'label' => 'Back to board', 'custom_id' => "ms:view:{$gameId}"]]];

        $lines = ["**Game #{$gameId}** -- pick a card to view its details:"];
        if ($handOptions === [] && $inPlayOptions === [] && $discardOptions === []) {
            $lines[] = '(no cards to show right now)';
        }

        return [implode("\n", $lines), $components];
    }

    /**
     * The single card $cardId's own catalog detail (name/value/color/
     * rules_text) -- $zone picks which of the three arrays fieldOptions()'s
     * own three custom_id verbs (cardhand/cardplay/carddiscard) searches,
     * since the same card id could otherwise collide across zones (a
     * discarded card and an unrelated card still in hand are different
     * game_cards rows, but this keeps the lookup scoped to exactly the
     * list the player actually picked from either way).
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>, 2?: array<int, array<string, mixed>>}
     */
    private function cardDetailMessage(int $gameId, int $userId, string $zone, int $cardId): array
    {
        try {
            $state = $this->games->getState($gameId, $userId);
        } catch (GameStateException $e) {
            return [$e->getMessage(), []];
        }

        $cards = match ($zone) {
            'cardhand' => $state['you']['hand'] ?? [],
            'cardplay' => $state['in_play'] ?? [],
            'carddiscard' => $state['discard_pile'] ?? [],
            default => [],
        };

        $card = $this->findCard($cards, $cardId);
        if ($card === null) {
            // The card moved zones (played, drawn back, etc.) between
            // opening this select and picking from it -- rather than a
            // dead-end error, just show the browse screen again with
            // whatever's actually there now. cardsMessage() only ever
            // returns a 2-element tuple (no embeds of its own), which
            // updateMessage()'s own optional third $embeds parameter
            // already tolerates being omitted from.
            return $this->cardsMessage($gameId, $userId);
        }

        $lines = ['**' . $this->cardLabel($card) . '**'];
        if (($card['rules_text'] ?? '') !== '') {
            $lines[] = $card['rules_text'];
        }

        $components = [['type' => 1, 'components' => [['type' => 2, 'style' => 2, 'label' => 'Back', 'custom_id' => "ms:cards:{$gameId}"]]]];

        // Reported live: "let's add the card image to the card detail
        // display" -- one embed, one image, for exactly the single-card
        // view this fits (see cardArtUrl()'s own docblock for why a
        // multi-card list doesn't get the same treatment).
        $embeds = [['image' => ['url' => $this->cardArtUrl($card)]]];

        return [implode("\n", $lines), $components, $embeds];
    }

    /**
     * The same MSW-print card art URL web-static/js/game.js's own
     * defaultCardArtUrl() builds (issue #233 follow-up: "is there any way
     * we can show card thumbnails instead of text?") -- these .webp files
     * are ordinary public static assets (no auth), so Discord's own
     * servers can fetch one directly for an embed's image.url the same
     * way a browser already does for the web board. Only used for
     * cardDetailMessage()'s own SINGLE-card view -- Discord caps a
     * message at 10 embeds total, each holding at most one image, so a
     * multi-card list (a hand, the whole in-play board) can't get the
     * same treatment without either breaking down past ~10 cards or a
     * far bulkier one-embed-per-card layout. See boardImageUrl() below
     * for the composite-image alternative that scope decision led to.
     *
     * @param array<string, mixed> $card
     */
    private function cardArtUrl(array $card): string
    {
        return SiteUrl::root() . '/img' . $this->cardArtRelativePath($card);
    }

    /**
     * Shared by cardArtUrl() (the public URL Discord's own servers fetch)
     * and cardArtFilePath() (the local disk path this server itself reads
     * for the composite board image) -- the same
     * `/cards/MSW/{catalog_card_id}-{slug}.webp` suffix either way, just
     * rooted differently.
     *
     * @param array<string, mixed> $card
     */
    private function cardArtRelativePath(array $card): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $card['name'])), '-');

        return "/cards/MSW/{$card['catalog_card_id']}-{$slug}.webp";
    }

    /**
     * Locates $card's own MSW-print .webp file ON DISK (unlike
     * cardArtUrl(), which only ever builds a public URL) so
     * renderBoardImage() can decode it directly via GD instead of this
     * server making an HTTP request back to its own public URL. Probes
     * two candidate paths because local dev and production disagree on
     * where web-static/img/ sits relative to THIS file: production's
     * deploy.yml flattens web-static/'s own contents straight into the
     * doc root alongside src/ (dist/img/... is dist/src/'s own sibling --
     * the same relative depth dirname(__DIR__, 2) already reaches bin/ at,
     * see GameService::launchTacticalBotSearchJob()'s own precedent), but
     * locally web-static/ is a sibling of php-app/ ITSELF, one level
     * shallower than that -- so a single hardcoded dirname(__DIR__, N)
     * can't resolve both, and this just tries both instead. Returns null
     * (never throws) for a card whose art is missing on THIS deployment --
     * BoardImageRenderer already tolerates a shorter list than the
     * in-play card count, same as a decode failure.
     *
     * @param array<string, mixed> $card
     */
    private function cardArtFilePath(array $card): ?string
    {
        $relative = $this->cardArtRelativePath($card);

        foreach ([
            dirname(__DIR__, 2) . '/img' . $relative,
            dirname(__DIR__, 3) . '/web-static/img' . $relative,
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The signed, UNAUTHENTICATED URL boardMessage() embeds for the
     * composite in-play board image -- unlike every other route this
     * class's own responses point at (the web app itself, always behind
     * the viewer's own session), Discord's servers fetch an embed's
     * image.url directly, with no session cookie of the viewer's to send,
     * so this can't be a normal `requireAuth()`-gated route. Reported
     * live in response: "how should the board-image URL be protected
     * from guessing/enumeration?" -- a bare game id alone would let
     * anyone who can guess/enumerate one view that game's in-play board
     * (never hands, but still not public the way a card's own art is),
     * so this reuses the existing DISCORD_CLIENT_SECRET (rather than a
     * new dedicated secret, or shipping unsigned) as an HMAC key over the
     * game id -- see verifyBoardImageSignature(), the new
     * `/discord/board-image` route's own gate in public/index.php.
     *
     * Reported live: the embed showed up blank in Discord -- this used
     * SiteUrl::root() (the bare domain, for linking to STATIC frontend
     * assets like cardArtUrl()'s own .webp files) instead of APP_URL
     * (which includes the PHP app's own '/app' path prefix on shared
     * hosting -- see SiteUrl's own docblock). `/discord/board-image` is
     * a public/index.php ROUTE, the same category as
     * DiscordOAuthService::redirectUri()'s own `/discord/oauth/callback`
     * link, which already builds off Config::get('APP_URL') for exactly
     * this reason -- SiteUrl::root() pointed Discord's own fetch at the
     * bare domain, missing the '/app' prefix entirely, a 404 Discord
     * just renders as no image at all.
     *
     * $cacheKey (see boardImageCacheKey()'s own docblock for what it's
     * made of and why) is deliberately NOT fed into signBoardImage()
     * below -- it's a plain, unsigned query param purely for Discord's
     * own HTTP caching, never anything the `/discord/board-image` route
     * itself trusts for authorization (that route always re-renders
     * fresh from the CURRENT database state regardless of what this
     * says, the same as it always has).
     */
    public function boardImageUrl(int $gameId, string $cacheKey): string
    {
        return rtrim((string) Config::get('APP_URL', ''), '/') . "/discord/board-image?game_id={$gameId}&sig=" . $this->signBoardImage($gameId) . '&v=' . rawurlencode($cacheKey);
    }

    /** @see boardImageUrl()'s own docblock for why this exists at all. */
    public function verifyBoardImageSignature(int $gameId, string $signature): bool
    {
        return hash_equals($this->signBoardImage($gameId), $signature);
    }

    private function signBoardImage(int $gameId): string
    {
        return hash_hmac('sha256', (string) $gameId, (string) Config::get('DISCORD_CLIENT_SECRET', ''));
    }

    /**
     * The signed, UNAUTHENTICATED URL boardMessage() embeds for the
     * active user's OWN composite hand image -- reported live: "show the
     * active user's hand as a composite image, labeled 'your hand.' I
     * suspect that this requires a separate image since a composite would
     * be problematic with hidden information." That instinct is exactly
     * right, and is why this is its own endpoint/signature rather than
     * boardImageUrl()'s own (public, in-play/discard) URL with an extra
     * flag: a hand is PRIVATE, unlike anything else boardImageUrl() ever
     * renders, so its signature is keyed to $gamePlayerId (one single
     * seat) rather than $gameId (every seat's shared public board) --
     * knowing one player's own signed hand-image URL must never also
     * satisfy another player's, or the other seat's own hand, even in the
     * SAME game. signHandImage() below also HMACs a distinct `hand:`-
     * prefixed message (not a bare id the way signBoardImage() does) --
     * cheap domain separation so this signature can never coincide with a
     * board-image one even in principle (e.g. a $gameId that happens to
     * equal some $gamePlayerId).
     *
     * This doesn't (and, over plain HTTP image URLs Discord's own servers
     * fetch with no viewer session attached, largely can't) prevent the
     * player who legitimately receives this URL from copying and sharing
     * it further -- exactly as true of a screenshot of their own hand.
     * What it DOES prevent is anyone else discovering or guessing another
     * player's own hand-image URL from the outside, the same guarantee
     * boardImageUrl()'s own signature gives the public board image against
     * enumeration.
     */
    public function handImageUrl(int $gamePlayerId, string $cacheKey): string
    {
        return rtrim((string) Config::get('APP_URL', ''), '/') . "/discord/hand-image?gp={$gamePlayerId}&sig=" . $this->signHandImage($gamePlayerId) . '&v=' . rawurlencode($cacheKey);
    }

    /** @see handImageUrl()'s own docblock for why this exists at all. */
    public function verifyHandImageSignature(int $gamePlayerId, string $signature): bool
    {
        return hash_equals($this->signHandImage($gamePlayerId), $signature);
    }

    private function signHandImage(int $gamePlayerId): string
    {
        return hash_hmac('sha256', "hand:{$gamePlayerId}", (string) Config::get('DISCORD_CLIENT_SECRET', ''));
    }

    /**
     * A short fingerprint of $hand's own contents -- see
     * boardImageCacheKey()'s own docblock for the identical reasoning
     * (Discord's own CDN caches an embed image by URL, so this needs to
     * change exactly when the rendered picture actually would: a card
     * played out of hand, drawn into it, or a Creativity copy's own
     * current print changing without the card itself leaving the hand).
     * Not part of signHandImage()'s own HMAC -- purely a cache-busting
     * hint, never anything `/discord/hand-image` itself trusts for
     * authorization.
     *
     * @param array<int, array<string, mixed>> $hand
     */
    private function handImageCacheKey(array $hand): string
    {
        $signature = array_map(
            static fn (array $card) => [$card['card_id'], $card['catalog_card_id'], $card['value']],
            $hand,
        );

        return substr(md5(json_encode($signature)), 0, 12);
    }

    /**
     * The actual PNG bytes for $gamePlayerId's own current hand -- called
     * by the new `/discord/hand-image` route in public/index.php only
     * after verifyHandImageSignature() already passed, so this itself does
     * no authorization of its own. Uses GameService::getHandForGamePlayer()
     * (see that method's own docblock) rather than getState(), since
     * there's no per-viewer session here for the signed URL's request to
     * carry -- $gamePlayerId, already verified by the signature, stands in
     * for it. Returns null (never throws) for a $gamePlayerId that no
     * longer exists, or an empty/all-missing-art hand -- either way the
     * caller responds 404 rather than serving a broken image, same as
     * renderBoardImage()'s own contract.
     *
     * Reuses BoardImageRenderer::render() completely unchanged -- a single
     * row labeled "Your Hand," the exact same per-row layout/value-badge
     * treatment a player's own in-play row already gets (see that class's
     * own docblock for why nothing there needed to change to support
     * this).
     */
    public function renderHandImage(int $gamePlayerId): ?string
    {
        try {
            $hand = $this->games->getHandForGamePlayer($gamePlayerId);
        } catch (GameStateException) {
            return null;
        }

        $cards = [];
        foreach ($hand as $card) {
            $path = $this->cardArtFilePath($card);
            if ($path !== null) {
                $cards[] = ['path' => $path, 'value' => (int) $card['value'], 'base_value' => (int) $card['base_value']];
            }
        }

        return $this->boardImageRenderer->render([['username' => 'Your Hand', 'cards' => $cards]]);
    }

    /**
     * The actual PNG bytes for $gameId's composite in-play board image --
     * called by the new `/discord/board-image` route in public/index.php
     * only after verifyBoardImageSignature() already passed, so this
     * itself does no authorization of its own. Uses getSpectatorState()
     * (public information -- moods in play, never a hand) rather than
     * getState(), since there's no per-viewer session here for the
     * signed URL's request to carry the way every other method in this
     * class has $userId for. Returns null (never throws) for a game
     * that's gone/still 'waiting'/'abandoned' (getSpectatorState() itself
     * rejects those), or one with nothing currently in play -- either
     * way the caller responds 404 rather than serving a broken image.
     *
     * Reported live: "would it be possible to arrange the cards similarly
     * to how we do in the web client, including the player's names,
     * badges on the cards to indicate current value, etc." -- groups
     * $state['in_play'] by owner_game_player_id the exact same way
     * boardMessage()'s own inPlaySummary() already does for its text
     * listing (same $state['players'] seat order, same "a player with
     * nothing in play gets no row at all"), so BoardImageRenderer's own
     * layout matches that listing's structure instead of an
     * undifferentiated grid. `value`/`base_value` ride along per card
     * for BoardImageRenderer's own current-value badge -- see its
     * docblock for why that mirrors buildCardThumb()'s identical check
     * rather than every other badge that function can also show (chaos
     * delta/override, Copy, recolor, suppressed, ...): those only ever
     * apply to a chaos_draft-format game, entirely out of scope for this
     * class's own 'standard'-only SUPPORTED_FORMAT (see this class's own
     * docblock).
     */
    public function renderBoardImage(int $gameId): ?string
    {
        try {
            $state = $this->games->getSpectatorState($gameId);
        } catch (GameStateException) {
            return null;
        }

        $cardsByOwner = [];
        foreach ($state['in_play'] ?? [] as $card) {
            $cardsByOwner[$card['owner_game_player_id']][] = $card;
        }

        $players = [];
        foreach ($state['players'] as $player) {
            $cards = [];
            foreach ($cardsByOwner[$player['game_player_id']] ?? [] as $card) {
                $path = $this->cardArtFilePath($card);
                if ($path !== null) {
                    $cards[] = ['path' => $path, 'value' => (int) $card['value'], 'base_value' => (int) $card['base_value']];
                }
            }

            if ($cards !== []) {
                $players[] = ['username' => $player['username'], 'cards' => $cards];
            }
        }

        $discardRow = $this->discardImageRow($state['discard_pile'] ?? []);
        if ($discardRow !== null) {
            // Appended as an ordinary extra "player row" -- BoardImageRenderer
            // itself has no notion of "player" vs. "discard pile," it just
            // draws whatever label/cards pairs it's given (see its own
            // docblock), so a synthetic row here needs no changes there at
            // all.
            $players[] = $discardRow;
        }

        return $this->boardImageRenderer->render($players);
    }

    /**
     * Reported live: "can we show the discard pile as an image, as well?
     * It could be part of the same image that the play area is in, with
     * clearly delineated/labeled zones." -- folded into the SAME composite
     * image as one more labeled row (rather than a second embed), the same
     * public information inPlaySummary()/discardPileSummary() already show
     * as text regardless of whose turn it is. Capped to the most recent
     * MAX_DISCARD_CARDS_SHOWN (a long game's pile can run to 100+ cards --
     * see discardPileSummary()'s own docblock) -- the label says so
     * whenever that cap actually truncated anything, so "why don't I see
     * my card from 40 plays ago" is self-explanatory rather than looking
     * like a bug.
     *
     * @param array<int, array<string, mixed>> $pile
     * @return array{username: string, cards: array<int, array{path: string, value: int, base_value: int}>}|null
     */
    private function discardImageRow(array $pile): ?array
    {
        if ($pile === []) {
            return null;
        }

        $shown = array_slice($pile, -self::MAX_DISCARD_CARDS_SHOWN);
        $cards = [];
        foreach ($shown as $card) {
            $path = $this->cardArtFilePath($card);
            if ($path !== null) {
                $cards[] = ['path' => $path, 'value' => (int) $card['value'], 'base_value' => (int) $card['base_value']];
            }
        }

        if ($cards === []) {
            return null;
        }

        $label = count($shown) < count($pile)
            ? 'Discard Pile (' . count($shown) . ' of ' . count($pile) . ', most recent)'
            : 'Discard Pile (' . count($pile) . ')';

        return ['username' => $label, 'cards' => $cards];
    }

    /**
     * Reported live alongside cardsMessage() above: "some way to view the
     * text game log" -- reuses getState()'s own already-bounded
     * recent_events (GameService::recentEvents(), capped at 15 rows,
     * newest first) rather than the unbounded fullEventLog(), since a
     * long game's full log could badly overflow Discord's own 2000-char
     * message content cap; the trailing substr() below is a second,
     * defensive cap for the rare case even 15 rows of unusually verbose
     * descriptions still would.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function gameLogMessage(int $gameId, int $userId): array
    {
        try {
            $state = $this->games->getState($gameId, $userId);
        } catch (GameStateException $e) {
            return [$e->getMessage(), []];
        }

        $lines = ["**Game #{$gameId}** -- recent plays (newest first):"];
        $events = $state['recent_events'] ?? [];
        if ($events === []) {
            $lines[] = '(nothing has happened yet)';
        } else {
            foreach ($events as $event) {
                $lines[] = "- {$event['description']}";
            }
        }

        $content = implode("\n", $lines);
        if (strlen($content) > 1900) {
            $content = substr($content, 0, 1897) . '...';
        }

        $components = [['type' => 1, 'components' => [['type' => 2, 'style' => 2, 'label' => 'Back to board', 'custom_id' => "ms:view:{$gameId}"]]]];

        return [$content, $components];
    }

    /**
     * Splits every card this class could offer to PLAY -- the viewer's
     * own hand, plus (reported live: "the discord client needs to
     * support playing cards from discard when allowed to by Grace or
     * similar effects") the discard pile -- into ones it can offer
     * directly (a select option) vs. ones that need the web app: more
     * than MAX_CHOICE_FIELDS choice_fields, or any unsupported one among
     * them (see supportedChoiceFields()). Every field is consulted here
     * regardless of required/optional/multi -- an OPTIONAL field (Hate's
     * own "you may put any mood on the bottom of the deck") is just as
     * much a real in-game choice as a required one, see this class's own
     * SKIP_FIELD_VALUE docblock for the bug report that caught an
     * earlier required-only check silently always leaving it blank.
     *
     * A discard_pile entry's own `is_playable` already accounts for
     * whether some active play grant (Grace/Harmony/Grief/Angst,
     * Melancholy's own blanket allowance) actually covers it right now --
     * see `BoardState::grantAllows()` -- so this needs no new logic of
     * its own to decide THAT; it only needs to also look at that zone.
     * Most of the time nothing in the discard pile is playable at all, so
     * this returns exactly what it always did. Hand options are listed
     * first, discard options after, each labeled with its own zone so a
     * player picking from a single merged select isn't left guessing
     * which one they chose -- see cardLabel()'s own docblock for the
     * "(value, Color)" suffix this adds "-- from discard" after.
     * MAX_SELECT_OPTIONS is shared across both zones, same cap Discord's
     * own select menu enforces regardless of where the candidates came
     * from.
     *
     * @param array<string, mixed> $state
     * @return array{0: array<int, array{label: string, value: string}>, 1: string[]}
     */
    private function playableCardOptions(array $state): array
    {
        $options = [];
        $unsupported = [];

        $zones = [
            ['cards' => $state['you']['hand'] ?? [], 'suffix' => ''],
            ['cards' => $state['discard_pile'] ?? [], 'suffix' => ' -- from discard'],
        ];

        foreach ($zones as $zone) {
            foreach ($zone['cards'] as $card) {
                if (!($card['is_playable'] ?? false)) {
                    continue;
                }

                if ($this->supportedChoiceFields($card['choice_fields'] ?? []) === null) {
                    $unsupported[] = $card['name'];
                    continue;
                }

                if (count($options) >= self::MAX_SELECT_OPTIONS) {
                    continue;
                }

                $options[] = ['label' => $this->cardLabel($card) . $zone['suffix'], 'value' => (string) $card['card_id']];
            }
        }

        return [$options, $unsupported];
    }

    /**
     * A `multi` field (Suspicion's "any number of players," Guile's
     * "exactly 2 cards to discard," ...) is just as supported as a
     * single-value one now -- see fieldSelectComponent()'s own docblock
     * for how Discord's native multi-select (min_values/max_values)
     * covers it without needing a Skip sentinel the way an optional
     * single-value field does.
     */
    private function isSupportedField(array $field): bool
    {
        return in_array($field['type'] ?? null, self::SUPPORTED_FIELD_TYPES, true);
    }

    /**
     * The fields this class will render for a card/decision, one at a
     * time (promptOrPlay()) -- up to MAX_CHOICE_FIELDS total (every
     * hand-playable card tops out at 2 -- Faith/Guile/Condescension/
     * Guilt/Regret/Worry/Contempt/Cynicism/Hostility/Hesitation/
     * Rationalization/Corruption/Fascination, confirmed by walking
     * CardChoiceSchema's own full field list), each of a supported type.
     * A card's own 0-field case (nothing to ask at all) returns `[]`, not
     * null -- still "supported," just with nothing to prompt for. Only
     * 3+ fields, a `nested` sub-form, or a lone unsupported field type
     * still fall outside this class's scope -- "needs the web app"
     * either way.
     *
     * @param array<int, array<string, mixed>> $choiceFields
     * @return array<int, array<string, mixed>>|null
     */
    private function supportedChoiceFields(array $choiceFields): ?array
    {
        if (count($choiceFields) > self::MAX_CHOICE_FIELDS) {
            return null;
        }

        foreach ($choiceFields as $field) {
            if (!$this->isSupportedField($field)) {
                return null;
            }
        }

        return $choiceFields;
    }

    /**
     * The select-menu options for one field, using BotChoiceResolver's
     * own already-tested candidate enumeration against a live
     * BoardState -- see this class's own docblock for why that's reused
     * rather than re-derived. $cardId is the card currently being played
     * (0 for a pending-decision answer, same convention
     * BotChoiceResolver::resolve() itself uses).
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function fieldOptions(BoardState $boardState, array $field, int $actingGamePlayerId, int $cardId, string $effectKey, array $state): array
    {
        $candidates = match ($field['type']) {
            'mode' => $field['options'] ?? [],
            'value' => range((int) ($field['min'] ?? 0), min((int) ($field['max'] ?? 0), (int) ($field['min'] ?? 0) + self::MAX_SELECT_OPTIONS - 1)),
            'bool' => [1, 0],
            'mood' => $this->choiceResolver->moodFieldCandidates($boardState, $field, $actingGamePlayerId, $cardId),
            'player' => $this->choiceResolver->playerFieldCandidates($boardState, $field, $actingGamePlayerId),
            'hand_card' => $this->choiceResolver->handCardFieldCandidates($boardState, $field, $actingGamePlayerId, $cardId, $effectKey),
            'discard_card' => $this->choiceResolver->discardCardFieldCandidates($boardState, $field, $cardId),
            default => [],
        };

        $cardNames = [];
        foreach (array_merge($state['you']['hand'] ?? [], $state['in_play'] ?? [], $state['discard_pile'] ?? []) as $card) {
            $cardNames[$card['card_id']] = $this->cardLabel($card);
        }
        $usernames = [];
        foreach ($state['players'] as $player) {
            $usernames[$player['game_player_id']] = $player['username'];
        }
        // Reported live alongside inPlaySummary() above: a 'mood' field's
        // own candidates (Hate's "any mood in play," Conviction's own
        // self-targetable equivalent, ...) are just as ambiguous picked
        // blind as the board itself was -- this labels each one with
        // whose mood it is, not just its name/value, the same public
        // information inPlaySummary() now always shows above the board.
        // Absent for a candidate not actually in $state['in_play'] yet
        // (a card still in hand, offered via that field's own
        // includes_self) -- there's nothing to attribute an owner to
        // there, and the plain name is unambiguous anyway (it's always
        // "yourself").
        $moodOwners = [];
        foreach ($state['in_play'] ?? [] as $card) {
            $moodOwners[$card['card_id']] = $usernames[$card['owner_game_player_id']] ?? null;
        }

        $options = [];
        foreach (array_slice($candidates, 0, self::MAX_SELECT_OPTIONS) as $candidate) {
            $label = match ($field['type']) {
                'mode' => (string) $candidate,
                'bool' => $candidate === 1 ? 'Yes' : 'No',
                'mood' => isset($moodOwners[$candidate])
                    ? ($cardNames[$candidate] ?? "Card #{$candidate}") . " -- {$moodOwners[$candidate]}"
                    : ($cardNames[$candidate] ?? "Card #{$candidate}"),
                'hand_card', 'discard_card' => $cardNames[$candidate] ?? "Card #{$candidate}",
                'player' => $usernames[$candidate] ?? "Player #{$candidate}",
                default => (string) $candidate,
            };
            $options[] = ['label' => $label, 'value' => (string) $candidate];
        }

        return $options;
    }

    /** @param mixed[] $values */
    /** Null means "leave this field out of the submitted choices entirely" -- see SKIP_FIELD_VALUE's own docblock. */
    private function castFieldValue(array $field, array $values): mixed
    {
        $raw = $values[0] ?? null;
        if ($raw === self::SKIP_FIELD_VALUE) {
            return null;
        }

        return match ($field['type']) {
            'mode' => (string) $raw,
            'bool' => $raw === '1',
            default => (int) $raw,
        };
    }

    /**
     * "Name (value, Color)" -- reported live: "Let's show the colors of
     * the cards in the discord client as well as the name/value." Every
     * card listing in this class (a hand, in-play summary, discard pile,
     * a select-menu option, ...) built its own "{name} ({value})" string
     * inline before this, so this is the one place that format lives now.
     *
     * @param array<string, mixed> $card
     */
    private function cardLabel(array $card): string
    {
        return "{$card['name']} ({$card['value']}, " . ucfirst((string) $card['color']) . ')';
    }

    /** @param array<int, array<string, mixed>> $hand */
    private function findCard(array $hand, int $cardId): ?array
    {
        foreach ($hand as $card) {
            if ($card['card_id'] === $cardId) {
                return $card;
            }
        }

        return null;
    }

    /** @return int[] */
    private function activeStandardGameIdsFor(int $userId): array
    {
        $gameIds = [];
        foreach ($this->games->listGamesForUser($userId) as $game) {
            if ($game['format'] === self::SUPPORTED_FORMAT && $game['status'] === 'in_progress') {
                $gameIds[] = $game['id'];
            }
        }

        return $gameIds;
    }

    private function requireSeatedIn(int $gameId, int $userId): int
    {
        $gamePlayerId = $this->games->gamePlayerIdFor($gameId, $userId);
        if ($gamePlayerId === null) {
            throw new GameStateException("You're not seated in game #{$gameId}.");
        }

        return $gamePlayerId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveUserId(array $payload): ?int
    {
        $discordUserId = $payload['member']['user']['id'] ?? $payload['user']['id'] ?? null;
        if (!is_string($discordUserId) || $discordUserId === '') {
            return null;
        }

        return $this->accounts->findUserIdByDiscordUserId($discordUserId);
    }

    private function unlinkedAccountMessage(): string
    {
        return "Your Discord account isn't linked to a MoodSwings account yet -- connect it from your account settings at " . SiteUrl::root() . '/game/';
    }

    /**
     * @param array<int, array<string, mixed>> $components
     * @param array<int, array<string, mixed>> $embeds
     * @return array<string, mixed>
     */
    private function ephemeralMessage(string $content, array $components = [], array $embeds = []): array
    {
        return ['type' => 4, 'data' => ['content' => $content, 'components' => $components, 'embeds' => $embeds, 'flags' => 64]];
    }

    /**
     * @param array<int, array<string, mixed>> $components
     * @param array<int, array<string, mixed>> $embeds
     * @return array<string, mixed>
     */
    private function updateMessage(string $content, array $components = [], array $embeds = []): array
    {
        return ['type' => 7, 'data' => ['content' => $content, 'components' => $components, 'embeds' => $embeds, 'flags' => 64]];
    }
}
