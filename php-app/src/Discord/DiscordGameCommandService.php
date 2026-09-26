<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

use MoodSwings\Bot\BotChoiceResolver;
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
 * V1 SCOPE (deliberately narrow -- see issue #233's own "needs a decision
 * on scope for a first pass" note):
 * - Format 'standard' (Traditional Duel) ONLY. Team/Closed Team/Duel/
 *   draft/chaos_draft formats all have their own extra state (teammate
 *   hand visibility, per-seat decks, propose/confirm decisions, attached
 *   chaos effects, ...) this class has no rendering for yet -- a game in
 *   any other format gets a plain "open the web app for this" message,
 *   same as an unsupported choice shape below.
 * - A card is only offered to PLAY here (in the "Play a card" select) if
 *   every one of its own REQUIRED choice_fields (or the pending
 *   decision's own single field) is one of SUPPORTED_FIELD_TYPES below
 *   and not itself a `multi` field -- covers most single-target cards
 *   (Pride's own target_player_id, Compulsion's discard_card_id, ...) but
 *   deliberately excludes anything needing more than one field filled in,
 *   a `multi`/checkbox-style selection, or a `nested` sub-form
 *   (Duplicity's own repeat offer, any chaos_draft attachment). An
 *   OPTIONAL field is never rendered at all here -- a card with one is
 *   simply played without it, the same "start simple" scope cut. Both
 *   gaps are real, known v1 limitations (see php-app/README.md), not
 *   bugs -- a player who hits either is pointed at the web app instead.
 * - Every actual rules decision (which candidates are legal for a given
 *   field) reuses BotChoiceResolver's own already-tested
 *   moodFieldCandidates()/playerFieldCandidates()/handCardFieldCandidates()/
 *   discardCardFieldCandidates() against a freshly loaded BoardState,
 *   rather than re-deriving CardChoiceSchema's own filter/scope logic a
 *   third time (web-static/js/game.js's fieldOptions() is the second) --
 *   this class only ever supplies the DISPLAY (embed/component) layer on
 *   top of an already-correct legal-candidate list.
 *
 * Custom_id scheme (colon-delimited, always short enough for Discord's
 * own 100-char cap): `ms:view:{gameId}`, `ms:pass:{gameId}`,
 * `ms:play:{gameId}` (the "play a card" select; its own value is the
 * chosen card id), `ms:playfield:{gameId}:{cardId}` (that card's own
 * single required field's value select), `ms:decision:{gameId}` (the
 * current pending decision's own single field's value select). A
 * field's own key is never encoded in a custom_id -- it's always
 * re-derived server-side from the current board state (the card/decision
 * can only ever have exactly the one supported field this class already
 * chose to render), so there's nothing to carry across the round trip
 * besides which game and (for a card) which card.
 */
final class DiscordGameCommandService
{
    private const SUPPORTED_FORMAT = 'standard';

    /** @see this class's own docblock -- 'nested'/'card_order'/'grant_choice' and every `multi` field fall outside v1. */
    private const SUPPORTED_FIELD_TYPES = ['mode', 'value', 'bool', 'mood', 'player', 'hand_card', 'discard_card'];

    private const MAX_SELECT_OPTIONS = 25;

    public function __construct(
        private readonly GameService $games,
        private readonly BoardStateRepository $boardStates,
        private readonly DiscordAccountRepository $accounts,
        private readonly BotChoiceResolver $choiceResolver = new BotChoiceResolver(),
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
            return $this->ephemeralMessage("You don't have an active Traditional game right now. Start or join one at " . SiteUrl::root() . '/game/');
        }

        if (count($gameIds) === 1) {
            return $this->ephemeralMessage(...$this->boardMessage($gameIds[0], $userId));
        }

        $components = [['type' => 1, 'components' => array_map(
            fn (int $gameId) => ['type' => 2, 'style' => 2, 'label' => "Game #{$gameId}", 'custom_id' => "ms:view:{$gameId}"],
            array_slice($gameIds, 0, 5),
        )]];

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
                    $this->submitPlayField($gameId, $userId, $gamePlayerId, $cardId, $values);
                    break;
                case 'decision':
                    $gamePlayerId = $this->requireSeatedIn($gameId, $userId);
                    $this->submitDecisionField($gameId, $userId, $gamePlayerId, $values);
                    break;
                default:
                    return $this->updateMessage('Something about that action was not recognized -- try running /moodswings again.');
            }
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
     * @param mixed[] $values
     */
    private function submitPlayField(int $gameId, int $userId, int $gamePlayerId, int $cardId, array $values): void
    {
        $state = $this->games->getState($gameId, $userId);
        $card = $this->findCard($state['you']['hand'] ?? [], $cardId);
        if ($card === null) {
            throw new GameStateException('That card is no longer in your hand.');
        }

        $field = $this->singleRequiredSupportedField($card['choice_fields'] ?? []);
        if ($field === null) {
            throw new GameStateException("That card's own choice can't be answered from Discord anymore -- open the web app.");
        }

        $this->games->playMood($gameId, $gamePlayerId, $cardId, [$field['key'] => $this->castFieldValue($field, $values)]);
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

        $this->games->respondToDecision($gameId, $gamePlayerId, [$decision['field']['key'] => $this->castFieldValue($decision['field'], $values)]);
    }

    /**
     * Plays $cardId outright when it needs no supported field filled in,
     * or returns a boardMessage()-shaped tuple asking for that one field
     * instead. Never returns null AND leaves the card unplayed -- either
     * it played, or the caller already has a field-select response to
     * send back.
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

        $field = $this->singleRequiredSupportedField($card['choice_fields'] ?? []);
        if ($field === null) {
            $this->games->playMood($gameId, $gamePlayerId, $cardId, []);

            return null;
        }

        $boardState = $this->boardStates->load($gameId);
        $options = $this->fieldOptions($boardState, $field, $gamePlayerId, $cardId, $card['effect_key'], $state);
        if ($options === []) {
            // No legal candidate exists right now (optional_if_no_targets'
            // own "if literally nothing qualifies, the field just doesn't
            // apply" carve-out, mirrored here for a field this class
            // otherwise treats as required) -- play with the field left
            // unfilled rather than dead-ending on an empty select menu.
            $this->games->playMood($gameId, $gamePlayerId, $cardId, []);

            return null;
        }

        $components = [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => "ms:playfield:{$gameId}:{$cardId}",
            'placeholder' => $field['label'] ?? 'Choose one',
            'options' => $options,
        ]]]];

        return ["Playing **{$card['name']}** -- {$field['label']}:", $components];
    }

    /**
     * The embed + components for $gameId as $userId currently sees it --
     * shared by every command/component response that ends in "show the
     * board" (which is almost all of them). $notice, when given, is
     * shown as an extra embed field above the board (an error message
     * from a just-failed action, most often).
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
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

        if ($game['status'] !== 'in_progress') {
            return ["Game #{$gameId} is '{$game['status']}'. Open it in the web app: {$webUrl}", []];
        }

        $usernames = [];
        $scoreLines = [];
        foreach ($state['players'] as $player) {
            $usernames[$player['game_player_id']] = $player['username'];
            $scoreLines[] = "{$player['username']}: {$player['total_score']}";
        }

        $round = $state['round'];
        $you = $state['you'];
        $lines = ["**Game #{$gameId}** -- " . implode(', ', $scoreLines)];

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
                        $components[] = ['type' => 1, 'components' => [[
                            'type' => 3,
                            'custom_id' => "ms:decision:{$gameId}",
                            'placeholder' => $field['label'] ?? 'Choose one',
                            'options' => $options,
                        ]]];
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
            fn (array $card) => "{$card['name']} ({$card['value']})",
            $you['hand'],
        )));

        $components[] = ['type' => 1, 'components' => [
            ['type' => 2, 'style' => 2, 'label' => 'Refresh', 'custom_id' => "ms:view:{$gameId}"],
            ['type' => 2, 'style' => 5, 'label' => 'Open in browser', 'url' => $webUrl],
        ]];

        if ($notice !== null) {
            array_unshift($lines, $notice);
        }

        return [implode("\n", $lines), $components];
    }

    /**
     * Splits the viewer's own hand into cards this class can offer to
     * play directly (a select option) vs. ones that need the web app --
     * anything with more than one required field, or a required field
     * whose type this class doesn't support. Optional fields are never
     * consulted here (see this class's own docblock) -- a card offered
     * here always plays with every optional field left blank.
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

            $requiredFields = array_values(array_filter($card['choice_fields'] ?? [], fn (array $f) => ($f['required'] ?? false) === true));
            if (count($requiredFields) > 1 || (count($requiredFields) === 1 && !$this->isSupportedField($requiredFields[0]))) {
                $unsupported[] = $card['name'];
                continue;
            }

            if (count($options) >= self::MAX_SELECT_OPTIONS) {
                continue;
            }

            $options[] = ['label' => "{$card['name']} ({$card['value']})", 'value' => (string) $card['card_id']];
        }

        return [$options, $unsupported];
    }

    private function isSupportedField(array $field): bool
    {
        return in_array($field['type'] ?? null, self::SUPPORTED_FIELD_TYPES, true) && ($field['multi'] ?? false) !== true;
    }

    /** @param array<int, array<string, mixed>> $choiceFields */
    private function singleRequiredSupportedField(array $choiceFields): ?array
    {
        $required = array_values(array_filter($choiceFields, fn (array $f) => ($f['required'] ?? false) === true));
        if (count($required) !== 1 || !$this->isSupportedField($required[0])) {
            return null;
        }

        return $required[0];
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
            $cardNames[$card['card_id']] = "{$card['name']} ({$card['value']})";
        }
        $usernames = [];
        foreach ($state['players'] as $player) {
            $usernames[$player['game_player_id']] = $player['username'];
        }

        $options = [];
        foreach (array_slice($candidates, 0, self::MAX_SELECT_OPTIONS) as $candidate) {
            $label = match ($field['type']) {
                'mode' => (string) $candidate,
                'bool' => $candidate === 1 ? 'Yes' : 'No',
                'mood', 'hand_card', 'discard_card' => $cardNames[$candidate] ?? "Card #{$candidate}",
                'player' => $usernames[$candidate] ?? "Player #{$candidate}",
                default => (string) $candidate,
            };
            $options[] = ['label' => $label, 'value' => (string) $candidate];
        }

        return $options;
    }

    /** @param mixed[] $values */
    private function castFieldValue(array $field, array $values): mixed
    {
        $raw = $values[0] ?? null;

        return match ($field['type']) {
            'mode' => (string) $raw,
            'bool' => $raw === '1',
            default => (int) $raw,
        };
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
     * @return array<string, mixed>
     */
    private function ephemeralMessage(string $content, array $components = []): array
    {
        return ['type' => 4, 'data' => ['content' => $content, 'components' => $components, 'flags' => 64]];
    }

    /**
     * @param array<int, array<string, mixed>> $components
     * @return array<string, mixed>
     */
    private function updateMessage(string $content, array $components = []): array
    {
        return ['type' => 7, 'data' => ['content' => $content, 'components' => $components, 'flags' => 64]];
    }
}
