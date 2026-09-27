<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

use MoodSwings\Bot\BotChoiceResolver;
use MoodSwings\Config;
use MoodSwings\Game\BoardStateRepository;
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
 * - Format 'standard' (Traditional Duel) ONLY. Team/Closed Team/Duel/
 *   draft/chaos_draft formats all have their own extra state (teammate
 *   hand visibility, per-seat decks, propose/confirm decisions, attached
 *   chaos effects, ...) this class has no rendering for yet -- a game in
 *   any other format gets a plain "open the web app for this" message,
 *   same as an unsupported choice shape below.
 * - A card is only offered to PLAY here (in the "Play a card" select) if
 *   every one of its own choice_fields, up to MAX_CHOICE_FIELDS total
 *   (see supportedChoiceFields()), is one of SUPPORTED_FIELD_TYPES below
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
 * still points at the web app for. Never offers a HUMAN opponent here --
 * that would need Discord's own way to pick/invite another linked
 * player, real design work this class's docblock already flags as out
 * of scope for a first pass.
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

    public function __construct(
        private readonly GameService $games,
        private readonly BoardStateRepository $boardStates,
        private readonly DiscordAccountRepository $accounts,
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
                "You don't have an active Traditional game right now. Start or join one at " . SiteUrl::root() . '/game/, or start a practice game below.',
                [['type' => 1, 'components' => [$this->newGameButton()]]],
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

        $components = [['type' => 1, 'components' => [
            ...array_map(
                fn (int $gameId) => ['type' => 2, 'style' => 2, 'label' => "Game #{$gameId}", 'custom_id' => "ms:view:{$gameId}"],
                array_slice($gameIds, 0, 4),
            ),
            $this->newGameButton(),
        ]]];

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
        $card = $this->findCard($state['you']['hand'] ?? [], $cardId);
        if ($card === null) {
            throw new GameStateException('That card is no longer in your hand.');
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
        $card = $this->findCard($state['you']['hand'] ?? [], $cardId);
        if ($card === null) {
            throw new GameStateException('That card is no longer in your hand.');
        }

        $fields = $this->supportedChoiceFields($card['choice_fields'] ?? []);
        if ($fields === null) {
            // playableHandOptions() already keeps a card shaped like this
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
     * only for now"), so the 3rd tuple element below carries exactly one
     * embed, only for an 'in_progress' game with at least one mood
     * actually in play, pointing at boardImageUrl()'s own signed,
     * unauthenticated endpoint (see its docblock for why that's needed
     * at all).
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
            [$playOptions, $unsupportedNames] = $this->playableHandOptions($state);
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
            $this->newGameButton(),
        ]];

        if ($notice !== null) {
            array_unshift($lines, $notice);
        }

        $embeds = $state['in_play'] === [] ? [] : [['image' => ['url' => $this->boardImageUrl($gameId)]]];

        return [implode("\n", $lines), $components, $embeds];
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

    /** @return array<string, mixed> */
    private function newGameButton(): array
    {
        return ['type' => 2, 'style' => 2, 'label' => 'New Practice Game', 'custom_id' => 'ms:newgame:0'];
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
     */
    public function boardImageUrl(int $gameId): string
    {
        return rtrim((string) Config::get('APP_URL', ''), '/') . "/discord/board-image?game_id={$gameId}&sig=" . $this->signBoardImage($gameId);
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

        return $this->boardImageRenderer->render($players);
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
     * Splits the viewer's own hand into cards this class can offer to
     * play directly (a select option) vs. ones that need the web app --
     * more than MAX_CHOICE_FIELDS choice_fields, or any unsupported one
     * among them (see supportedChoiceFields()). Every field is consulted
     * here regardless of required/optional/multi -- an OPTIONAL field
     * (Hate's own "you may put any mood on the bottom of the deck") is
     * just as much a real in-game choice as a required one, see this
     * class's own SKIP_FIELD_VALUE docblock for the bug report that
     * caught an earlier required-only check silently always leaving it
     * blank.
     *
     * @param array<string, mixed> $state
     * @return array{0: array<int, array{label: string, value: string}>, 1: string[]}
     */
    private function playableHandOptions(array $state): array
    {
        $options = [];
        $unsupported = [];

        foreach ($state['you']['hand'] as $card) {
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

            $options[] = ['label' => $this->cardLabel($card), 'value' => (string) $card['card_id']];
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
