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
 * - Format 'standard' (Traditional Duel), and format 'duel' restricted to
 *   exactly 2 seated players, for actually PLAYING a game turn-by-turn --
 *   see isPlayableFormat(). Reported live after Power Duel's own first
 *   ship let a player create and start a 'duel' game but not play it:
 *   "I was able to initiate the game in the discord client, but not able
 *   to actually play it." Confirmed by re-reading GameService::getState()
 *   that a 2-player 'duel' game's own state shape is byte-for-byte
 *   identical to a 2-player 'standard' game's -- every format-conditional
 *   branch in buildGameState() is gated on 'team'/'closed_team'/'puzzle',
 *   never 'duel' -- so every rendering/interaction method already in this
 *   class (boardMessage(), playableCardOptions(), promptOrPlay(),
 *   cardsMessage(), gameLogMessage(), ...) needed no changes at all to
 *   support it. Team/Closed Team/3-4 player Duel/draft/chaos_draft
 *   formats all still have their own extra state (teammate hand
 *   visibility, per-seat decks, propose/confirm decisions, attached chaos
 *   effects, ...) this class has no rendering for -- a game in any of
 *   those still gets a plain "open the web app for this" message, same as
 *   an unsupported choice shape below. Power Duel (format 'duel',
 *   deck_type 'custom_duel', 'power' rules preset -- see
 *   powerDuelMenuMessage()'s own docblock) needs no separate carve-out
 *   here: it's just format 'duel' under the hood, and Discord never
 *   creates a sideboarding Power Duel itself, so it starts rendering
 *   the instant its 2 seats have both submitted a deck and startGame()
 *   flips it 'in_progress' -- setup AND play, not just setup.
 * - Best of three (Practice, Friend and Power Duel games offer it at
 *   creation; Sealed Deck is always one): the board is labeled "Game N
 *   of 3" with the match standing (matchSummary()); a finished game
 *   offers "Next game" (`ms:startgame:{nextGameId}`, since
 *   advanceGameMatch()/advanceDraftMatch() create game N+1 'waiting' and
 *   nothing starts it); and games 2/3 show "I'll go first"/"Let them go
 *   first" (`ms:firstplay`/`ms:firstdraw`) to the previous game's loser
 *   while round 1 is frozen (getState()'s 'first_player_decision'). A
 *   Power Duel match never offers sideboarding from Discord
 *   (allowSideboarding stays off), so its decks carry over untouched.
 * - Sealed Deck (format 'draft', deck_type 'sealed_deck', 2 players only
 *   -- isSealedDeckGame()): created vs a practice bot or a friend; while
 *   'waiting' the board is a deck-building screen (sealedDeckBuildingMessage():
 *   the pool grouped by color, then "Use suggested deck" -- the same
 *   chooseDraftDeck() heuristic practice bots build with, via
 *   GameService::suggestDraftDeck() -- "Build deck" (a modal prefilled
 *   with the pool/current/previous deck as a plain decklist to edit,
 *   submitted through GameService::submitDraftDeckFromText()) and, for
 *   games 2/3, "Keep same deck"). The game starts once both decks are in
 *   (startSealedIfReady(): the server never auto-starts a human-vs-human
 *   draft-family game, so whichever click lands last does it), then plays
 *   like any other 2-player game. 3-4 player sealed stays web-only.
 * - Quick Draft (deck_type 'quick_draft', 2 players only -- same
 *   isDraftMatchGame()): created from the Limited menu vs a practice bot
 *   or a friend with a pool source of the creator's choice. While the
 *   match is 'drafting' the board is a pick screen (quickDraftPickMessage():
 *   the pile you hold and a select of exactly 2 cards to keep, submitted
 *   through GameService::submitQuickDraftPick()); once drafting ends it
 *   is the very same deck-building screen as Sealed Deck (getState()'s
 *   'quick_draft' carries the same deck_building shape), then a
 *   best-of-three. 3-4 player Quick Draft stays web-only.
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
 *   own `renderDiscardPile()` already reads. A `grant_choice` field (2+
 *   distinct simultaneously-active, unrestricted extra-play grants, e.g.
 *   two copies of Validation both currently usable -- reported live: a
 *   player's entire hand showed "needs the web app" with no card-specific
 *   cause) is offered the same as a `mode` field -- see
 *   `GameService::grantChoiceOptions()`'s own docblock for why its
 *   options are already-labeled `{value, label}` pairs rather than
 *   candidates this class has to look up and describe itself.
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
    /** @see this class's own docblock -- 'nested'/'card_order' fall outside scope; every one of these supports `multi` too (except 'grant_choice', which is never `multi` -- see GameService::grantChoiceOptions()). */
    private const SUPPORTED_FIELD_TYPES = ['mode', 'value', 'bool', 'mood', 'player', 'hand_card', 'discard_card', 'grant_choice'];

    private const MAX_SELECT_OPTIONS = 25;

    /**
     * The pool-picking draft modes offered from Discord, keyed by their
     * custom_id verb prefix: engine deck_type, createGame()'s own pool-
     * source parameter, and the pool sources that need no extra input.
     * Grid Draft leaves out 'structure' -- its 45 cards are short of the
     * 54-card target and Grid Draft has no top-up mechanism.
     */
    private const DRAFT_MODES = [
        'qd' => [
            'label' => 'Quick Draft',
            'deck_type' => 'quick_draft',
            'pool_param' => 'quickDraftPoolSource',
            'intro' => 'Quick Draft: each round you each get a pile of cards, keep 2, and pass the rest -- then build a deck of at least 12 from what you kept and play a best-of-three match.',
            'pools' => [
                'random_48' => 'Random 48 cards',
                'structure' => 'Structure deck (45 cards)',
                'jceddys_75' => "jceddy's 75",
                'one_of_each' => 'One of each card',
            ],
        ],
        'gd' => [
            'label' => 'Grid Draft',
            'deck_type' => 'grid_draft',
            'pool_param' => 'gridDraftPoolSource',
            'intro' => 'Grid Draft: each round a 3x3 grid of face-up cards is dealt and you alternate taking a whole row or column -- then build a deck of at least 12 from what you took and play a best-of-three match.',
            'pools' => [
                'random_48' => 'Random cards',
                'jceddys_75' => "jceddy's 75",
                'one_of_each' => 'One of each card',
            ],
        ],
    ];

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
     * field type already does. A prepended `grant_choice` field (see
     * GameService::serializeCard()) counts against this same total -- a
     * 2-field card played while 2+ unrestricted grants are simultaneously
     * active would need 3 total and still falls into "needs the web app";
     * a 0- or 1-field card gains a `grant_choice` field for free within
     * the existing cap.
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
        private readonly GridImageRenderer $gridImageRenderer = new GridImageRenderer(),
    ) {
    }

    /**
     * `/moodswings` itself -- Discord's APPLICATION_COMMAND (type 2)
     * interaction. No sub-options in v1: it just finds the caller's own
     * active playable game(s) -- see isPlayableFormat() -- and either
     * renders the one it finds, asks which of several to view, or
     * explains why there's nothing to show.
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

        $gameIds = $this->activePlayableGameIdsFor($userId);
        if ($gameIds === []) {
            return $this->ephemeralMessage(
                "You don't have an active game right now. Start or join one at " . SiteUrl::root() . '/game/, or start one below.',
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

        return $this->ephemeralMessage(...$this->gamePickerMessage($gameIds));
    }

    /**
     * The "pick one of your active games" screen -- the root view with 2+
     * games, and what every board's "All Games" button (`ms:games:0`)
     * returns to. Up to 4 rows of 5 game buttons (20 games); the
     * utility row takes the fifth row.
     *
     * @param int[] $gameIds
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function gamePickerMessage(array $gameIds): array
    {
        $components = [];
        foreach (array_chunk(array_slice($gameIds, 0, 20), 5) as $row) {
            $components[] = ['type' => 1, 'components' => array_map(
                fn (int $gameId) => ['type' => 2, 'style' => 2, 'label' => "Game #{$gameId}", 'custom_id' => "ms:view:{$gameId}"],
                $row,
            )];
        }
        $components[] = $this->utilityButtonsRow();

        return ['You have more than one active game -- pick one:', $components];
    }

    /**
     * boardMessage() plus, when the player has 2+ active games, an "All
     * Games" button back to the picker -- beside Refresh when that row has
     * room, else on its own row (skipped if Discord's 5-row cap is hit).
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function boardMessage(int $gameId, int $userId, ?string $notice = null): array
    {
        $result = $this->boardMessageBody($gameId, $userId, $notice);
        $gameIds = $this->activePlayableGameIdsFor($userId);
        if (count($gameIds) < 2) {
            return $result;
        }

        $button = ['type' => 2, 'style' => 2, 'label' => 'All Games', 'custom_id' => 'ms:games:0'];
        $rows = $result[1];
        foreach ($rows as $index => $row) {
            $buttons = $row['components'] ?? [];
            if (count($buttons) >= 5 || ($buttons[0]['type'] ?? null) !== 2) {
                continue;
            }
            foreach ($buttons as $candidate) {
                if (str_starts_with((string) ($candidate['custom_id'] ?? ''), 'ms:view:')) {
                    $rows[$index]['components'][] = $button;
                    $result[1] = $rows;

                    return $result;
                }
            }
        }
        if (count($rows) < 5) {
            $rows[] = ['type' => 1, 'components' => [$button]];
            $result[1] = $rows;
        }

        return $result;
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
                    // Nothing to apply -- except a Sealed Deck game whose
                    // last deck just landed from the other player's own
                    // click, which only this (or any later) client action
                    // can actually start. See startSealedIfReady().
                    if ($this->sealedDecksAllSubmitted($this->games->getState($gameId, $userId))) {
                        $this->startSealedIfReady($gameId);
                    }
                    break;
                case 'advanceturn':
                    // Clears turn_pending_acknowledgment; the fall-through
                    // advanceAutomatedTurns() below also lets an
                    // empty-hand auto-pass (blocked until now) fire.
                    $gamePlayerId = $this->requireSeatedIn($gameId, $userId);
                    $this->games->acknowledgeTurnStart($gameId, $gamePlayerId);
                    break;
                case 'startgame':
                    // Game 2/3 of a best of three (advanceGameMatch()/
                    // advanceDraftMatch() create it 'waiting'; nothing
                    // starts it). A Sealed Deck next game still needs its
                    // decks, so startGame() just throws and the board
                    // below shows the deck-building screen.
                    $this->requireSeatedIn($gameId, $userId);
                    $this->startSealedIfReady($gameId);
                    break;
                case 'firstplay':
                case 'firstdraw':
                    $this->requireSeatedIn($gameId, $userId);
                    $this->games->setPlayFirstNextMatchGame($gameId, $userId, $verb === 'firstplay');
                    break;
                case 'sealedsuggest':
                    $this->requireSeatedIn($gameId, $userId);
                    $this->games->submitDraftDeck($gameId, $userId, $this->games->suggestDraftDeck($gameId, $userId));
                    $this->startSealedIfReady($gameId);
                    break;
                case 'sealedpreview':
                    $this->requireSeatedIn($gameId, $userId);

                    return $this->updateMessage(...$this->suggestedDeckPreviewMessage($gameId, $userId));
                case 'sealedkeep':
                    $this->requireSeatedIn($gameId, $userId);
                    $deckBuilding = $this->sealedDeckBuildingState($this->games->getState($gameId, $userId));
                    $previous = $deckBuilding['previous_deck_card_ids'] ?? null;
                    if ($previous === null) {
                        throw new GameStateException('There is no previous deck to keep.');
                    }
                    $this->games->submitDraftDeck($gameId, $userId, $previous);
                    $this->startSealedIfReady($gameId);
                    break;
                case 'sealedbuild':
                    $this->requireSeatedIn($gameId, $userId);

                    return $this->sealedBuildModalResponse($gameId, $userId);
                case 'games':
                    $gameIds = $this->activePlayableGameIdsFor($userId);
                    if ($gameIds === []) {
                        return $this->updateMessage(
                            "You don't have an active game right now. Start or join one at " . SiteUrl::root() . '/game/, or start one below.',
                            [$this->utilityButtonsRow()],
                        );
                    }
                    if (count($gameIds) === 1) {
                        return $this->updateMessage(...$this->boardMessage($gameIds[0], $userId));
                    }

                    return $this->updateMessage(...$this->gamePickerMessage($gameIds));
                case 'drafts':
                    return $this->updateMessage(...$this->draftsMenuMessage());
                case 'qd':
                case 'gd':
                    return $this->updateMessage(...$this->draftPoolMenuMessage($verb));
                case 'qdpool':
                case 'gdpool':
                    return $this->updateMessage(...$this->draftOpponentMessage(substr($verb, 0, 2), (string) ($values[0] ?? '')));
                case 'qdbotmenu':
                case 'gdbotmenu':
                    return $this->updateMessage(...$this->draftBotMessage(substr($verb, 0, 2), $userId, (string) ($parts[3] ?? '')));
                case 'qdbot':
                case 'gdbot':
                case 'qdwith':
                case 'gdwith':
                    return $this->updateMessage(...$this->createPoolDraftGameMessage(substr($verb, 0, 2), $userId, (int) ($values[0] ?? 0), (string) ($parts[3] ?? '')));
                case 'qdinvite':
                case 'gdinvite':
                    return $this->updateMessage(...$this->draftFriendMessage(substr($verb, 0, 2), $userId, (string) ($parts[3] ?? '')));
                case 'gdpick':
                    $this->requireSeatedIn($gameId, $userId);
                    [$axis, $lineIndex] = array_pad(explode(':', (string) ($values[0] ?? '')), 2, '');
                    $this->games->submitGridDraftPick($gameId, $userId, $axis, (int) $lineIndex);
                    break;
                case 'gddrafted':
                    $this->requireSeatedIn($gameId, $userId);

                    return $this->updateMessage(...$this->gridDraftedMessage($gameId, $userId));
                case 'qdpick':
                    $this->requireSeatedIn($gameId, $userId);
                    $cardIds = array_map(static fn ($v): int => (int) explode('_', (string) $v)[0], $values);
                    $this->games->submitQuickDraftPick($gameId, $userId, (int) ($parts[3] ?? 0), (int) ($parts[4] ?? 0), $cardIds);
                    break;
                case 'sealed':
                    return $this->updateMessage(...$this->sealedDeckMenuMessage());
                case 'sealedbotmenu':
                    return $this->updateMessage(...$this->newSealedBotMessage($userId));
                case 'sealedbot':
                    return $this->updateMessage(...$this->createSealedGameMessage($userId, (int) ($values[0] ?? 0)));
                case 'sealedinvite':
                    return $this->updateMessage(...$this->newSealedFriendMessage($userId));
                case 'sealedwith':
                    return $this->updateMessage(...$this->createSealedGameMessage($userId, (int) ($values[0] ?? 0)));
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
                    return $this->updateMessage(...$this->gameModeMessage('Practice game', 'newgamemode'));
                case 'newgamemode':
                    return $this->updateMessage(...$this->newPracticeGameMessage($userId, $gameId === 1));
                case 'newgamebot':
                    return $this->updateMessage(...$this->createPracticeGameMessage($userId, (int) ($values[0] ?? 0), $gameId === 1));
                case 'friendgame':
                    return $this->updateMessage(...$this->gameModeMessage('Friend game', 'friendgamemode'));
                case 'friendgamemode':
                    return $this->updateMessage(...$this->newFriendGameMessage($userId, $gameId === 1));
                case 'friendgamewith':
                    return $this->updateMessage(...$this->createFriendGameMessage($userId, (int) ($values[0] ?? 0), $gameId === 1));
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
                    return $this->updateMessage(...$this->newPowerDuelFriendMessage($userId, $gameId === 1));
                case 'powerduelwith':
                    return $this->updateMessage(...$this->createPowerDuelGameMessage($userId, (int) ($values[0] ?? 0), $gameId === 1));
                case 'powerduelbotmenu':
                    return $this->updateMessage(...$this->newPowerDuelBotMessage($userId, $gameId === 1));
                case 'powerduelbot':
                    return $this->updateMessage(...$this->choosePowerDuelBotDeckMessage($userId, (int) ($values[0] ?? 0), $gameId === 1));
                case 'powerduelbotdeck':
                    // arg = the bot's user id; parts[3] = 1 for best of three.
                    return $this->updateMessage(...$this->createPowerDuelGameWithBotMessage($userId, $gameId, (int) ($values[0] ?? 0), ($parts[3] ?? '0') === '1'));
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

        // Unlike every other optional field here, skipping a grant_choice
        // doesn't mean "play without this effect" -- the play still
        // happens, using MoodPlayService::playMood()'s own "whichever
        // grant comes first" fallback (see its own docblock) since none
        // was named.
        $skipLabel = $field['type'] === 'grant_choice'
            ? 'Skip -- use whichever grant comes first'
            : 'Skip -- play without this effect';
        array_unshift($options, ['label' => $skipLabel, 'value' => self::SKIP_FIELD_VALUE]);

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
     * Whether this class knows how to render/play $format turn-by-turn --
     * see this class's own SCOPE docblock. 'standard' always qualifies;
     * 'duel' qualifies only with exactly 2 seated players, since
     * GameService::getState() returns a shape identical to a 2-player
     * 'standard' game only in that case (a 3-4 player 'duel' game, and
     * every other format -- team/closed_team/draft/chaos_draft/puzzle --
     * has its own extra state this class has no rendering for yet).
     */
    private function isPlayableFormat(string $format, int $playerCount, ?string $deckType = null): bool
    {
        return $format === 'standard'
            || ($format === 'duel' && $playerCount === 2)
            || $this->isDraftMatchGame($format, $deckType, $playerCount);
    }

    /**
     * Sealed Deck / Quick Draft (format 'draft', deck_type 'sealed_deck' or
     * 'quick_draft') -- 2 seats only. Once both decks are in and
     * startGame() runs it plays exactly like any other 2-player game
     * (getState()'s own shape matches), but while still 'waiting' it needs
     * screens of its own (sealedDeckBuildingMessage(), and for Quick
     * Draft's drafting phase quickDraftPickMessage()) instead of a board.
     */
    private function isDraftMatchGame(string $format, ?string $deckType, int $playerCount): bool
    {
        return $format === 'draft' && in_array($deckType, ['sealed_deck', 'quick_draft', 'grid_draft'], true) && $playerCount === 2;
    }

    /**
     * getState()'s draft-match sub-state -- 'sealed_deck' or 'quick_draft'
     * (the other is null); both share the match standing and
     * deck_building shape.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private function draftMatchState(array $state): ?array
    {
        return $state['sealed_deck'] ?? $state['quick_draft'] ?? $state['grid_draft'] ?? null;
    }

    private function draftModeLabel(?string $deckType): string
    {
        return match ($deckType) {
            'quick_draft' => 'Quick Draft',
            'grid_draft' => 'Grid Draft',
            default => 'Sealed Deck',
        };
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
    private function boardMessageBody(int $gameId, int $userId, ?string $notice = null): array
    {
        try {
            $state = $this->games->getState($gameId, $userId);
        } catch (GameStateException $e) {
            return [$e->getMessage(), []];
        }

        $game = $state['game'];
        $webUrl = SiteUrl::root() . "/game/?id={$gameId}";

        if (!$this->isPlayableFormat($game['format'], count($state['players']), $game['deck_type'])) {
            return ["Game #{$gameId} is a '{$game['format']}' game -- Discord only supports Traditional games so far. Open it in the web app: {$webUrl}", []];
        }

        $match = $this->matchSummary($state);

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
            // 2-player formats this class supports, but the same
            // field the web board's own "Game over" banner already reads.
            $winnerText = $game['winner_usernames'] !== []
                ? implode(' & ', $game['winner_usernames']) . ' won'
                : 'nobody won';
            $scoreLine = implode(', ', array_map(
                fn (array $player) => "{$player['username']}: {$player['total_wins']} round(s) won",
                $state['players'],
            ));
            $lines = ["Game #{$gameId} is complete -- {$winnerText}! ({$scoreLine})"];
            $components = [];

            // A best-of-three match: say where it stands, and -- while it
            // isn't over -- hand the player straight to the next game
            // (advanceGameMatch()/advanceDraftMatch() already created it,
            // 'waiting'; nothing starts it until a client does).
            if ($match !== null) {
                $lines[] = $this->matchStandingLine($match, $game['status']);
                if ($match['next_game_id'] !== null) {
                    $components[] = ['type' => 1, 'components' => [
                        ['type' => 2, 'style' => 1, 'label' => 'Next game', 'custom_id' => "ms:startgame:{$match['next_game_id']}"],
                    ]];
                }
            }
            $lines[] = "Open it in the web app: {$webUrl}";

            return [implode("\n", $lines), $components];
        }

        if ($game['status'] === 'waiting') {
            return $this->waitingGameMessage($gameId, $userId, $state, $match, $notice);
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
        $lines = ["**Game #{$gameId}**" . ($match !== null ? ' -- ' . $this->matchHeaderLabel($match) : '') . ' -- ' . implode(', ', $scoreLines)];
        if ($match !== null) {
            $lines[] = $this->matchStandingLine($match, $game['status']);
        }
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

        $firstPlayerDecision = $state['first_player_decision'] ?? null;

        if ($firstPlayerDecision !== null) {
            // Games 2/3 of a best-of-three: round 1 stays frozen until the
            // previous game's loser picks who goes first (already decided
            // by advanceAutomatedTurns() when that loser is a bot).
            if ($firstPlayerDecision['you_are_previous_loser']) {
                $lines[] = 'You lost the last game, so you choose who goes first this game.';
                $components[] = ['type' => 1, 'components' => [
                    ['type' => 2, 'style' => 1, 'label' => 'I\'ll go first', 'custom_id' => "ms:firstplay:{$gameId}"],
                    ['type' => 2, 'style' => 2, 'label' => 'Let them go first', 'custom_id' => "ms:firstdraw:{$gameId}"],
                ]];
            } else {
                $lines[] = 'Waiting on your opponent to choose who goes first this game.';
            }
        } elseif ($decision !== null) {
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
        } elseif (($you['is_your_turn'] ?? false) && ($you['turn_pending_acknowledgment'] ?? false)) {
            // The opt-in "pause at the start of your turn" setting: play and
            // pass are rejected server-side (assertTurnAcknowledged()) until
            // the player clicks Advance Turn, so offering them here instead
            // would leave the game stuck behind an error. The board above
            // is already the paused view (before after-scoring effects).
            $lines[] = "It's your turn -- review the board, then advance when you're ready.";
            $components[] = ['type' => 1, 'components' => [
                ['type' => 2, 'style' => 1, 'label' => 'Advance Turn', 'custom_id' => "ms:advanceturn:{$gameId}"],
            ]];
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
     * The best-of-three standing for $state, or null for a plain single
     * game. Two engines feed it -- getState()'s own 'game_match' (Duel /
     * Traditional / team, the game_matches wrapper) and 'sealed_deck'
     * (draft_matches; sealed is always a best of three) -- which both
     * carry the same your_wins/opponent_wins/games_to_win/status/
     * next_game_id fields, so everything downstream reads this one shape.
     *
     * @param array<string, mixed> $state
     * @return array{game_number: int, your_wins: int, opponent_wins: int, games_to_win: int, status: string, next_game_id: ?int}|null
     */
    private function matchSummary(array $state): ?array
    {
        $source = $state['game_match'] ?? $this->draftMatchState($state);
        if ($source === null) {
            return null;
        }

        return [
            'game_number' => (int) ($state['game']['match_game_number'] ?? $source['match_game_number'] ?? 1),
            'your_wins' => (int) $source['your_wins'],
            'opponent_wins' => (int) $source['opponent_wins'],
            'games_to_win' => (int) $source['games_to_win'],
            'status' => (string) $source['status'],
            'next_game_id' => isset($source['next_game_id']) ? (int) $source['next_game_id'] : null,
        ];
    }

    /** @param array{game_number: int, games_to_win: int} $match */
    private function matchHeaderLabel(array $match): string
    {
        $totalGames = $match['games_to_win'] * 2 - 1;

        return "Game {$match['game_number']} of {$totalGames}";
    }

    /** @param array{game_number: int, your_wins: int, opponent_wins: int, games_to_win: int, status: string} $match */
    private function matchStandingLine(array $match, string $gameStatus): string
    {
        $standing = "Best of three: you {$match['your_wins']} - {$match['opponent_wins']} opponent (first to {$match['games_to_win']}).";
        if ($match['your_wins'] >= $match['games_to_win']) {
            return $standing . ' You won the match!';
        }
        if ($match['opponent_wins'] >= $match['games_to_win']) {
            return $standing . ' You lost the match.';
        }

        return $standing;
    }

    /**
     * Whether a game in listGamesForUser()'s own summary shape is
     * 'waiting' on something the player can act on from Discord -- a
     * Sealed Deck still in (or done with) deck-building, or game 2/3 of a
     * best-of-three that advanceGameMatch() created but nobody has
     * started yet. A fresh game 1 is excluded: every one of those is
     * either started right at creation, or is a Power Duel (surfaced by
     * its own menu, powerDuelMenuMessage()).
     *
     * @param array<string, mixed> $game
     */
    private function waitingGameNeedsAction(array $game): bool
    {
        if ($game['status'] !== 'waiting') {
            return false;
        }

        return in_array($game['deck_type'], ['sealed_deck', 'quick_draft', 'grid_draft'], true) || ((int) ($game['match_game_number'] ?? 1)) > 1;
    }

    /**
     * boardMessage() for a game still 'waiting' -- the Sealed Deck
     * deck-building screen, the "start game N" prompt for game 2/3 of a
     * best-of-three, or a Power Duel still needing a decklist. Anything
     * else waiting (a web-created lobby game, say) keeps the plain "open
     * it in the web app" message.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $match
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function waitingGameMessage(int $gameId, int $userId, array $state, ?array $match, ?string $notice): array
    {
        $game = $state['game'];
        $webUrl = SiteUrl::root() . "/game/?id={$gameId}";
        $prefix = $notice !== null ? $notice . "\n" : '';

        $drafting = $state['quick_draft']['drafting'] ?? null;
        if ($drafting !== null) {
            [$content, $components] = $this->quickDraftPickMessage($gameId, $state, $match, $drafting);

            return [$prefix . $content, $components];
        }

        $gridDrafting = $state['grid_draft']['drafting'] ?? null;
        if ($gridDrafting !== null) {
            [$content, $components, $embeds] = $this->gridDraftPickMessage($gameId, $state, $match, $gridDrafting);

            return [$prefix . $content, $components, $embeds];
        }

        $deckBuilding = $this->draftMatchState($state)['deck_building'] ?? null;
        if ($deckBuilding !== null) {
            [$content, $components] = $this->sealedDeckBuildingMessage($gameId, $state, $match, $deckBuilding);

            return [$prefix . $content, $components];
        }

        if ($game['deck_type'] === 'custom_duel' && $this->games->customDuelDeckStillNeededFrom($gameId, $userId)) {
            return $this->deckSubmissionPromptMessage($gameId, $prefix . "Game #{$gameId} needs your decklist:");
        }

        if ($match !== null && $match['game_number'] > 1) {
            return [
                $prefix . "**Game #{$gameId}** -- " . $this->matchHeaderLabel($match) . ' is ready.' . "\n" . $this->matchStandingLine($match, 'waiting'),
                [['type' => 1, 'components' => [
                    ['type' => 2, 'style' => 1, 'label' => 'Start game', 'custom_id' => "ms:startgame:{$gameId}"],
                ]]],
            ];
        }

        return [$prefix . "Game #{$gameId} is '{$game['status']}'. Open it in the web app: {$webUrl}", []];
    }

    /**
     * The Sealed Deck deck-building screen: the viewer's own pool grouped
     * by color, then whichever of "use the suggested deck" / "build one
     * by hand" / (games 2 and 3) "keep last game's deck" still applies.
     * The web app's click-to-toggle builder has no Discord equivalent, so
     * hand-building is a modal prefilled with the pool (or the previous/
     * current deck) as a plain decklist to trim down and resubmit -- see
     * sealedBuildModalResponse().
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $match
     * @param array<string, mixed> $deckBuilding getState()'s sealed_deck.deck_building
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function sealedDeckBuildingMessage(int $gameId, array $state, ?array $match, array $deckBuilding): array
    {
        $label = $match !== null ? $this->matchHeaderLabel($match) : 'Game 1 of 3';
        $lines = ["**Game #{$gameId}** -- " . $this->draftModeLabel($state['game']['deck_type'] ?? null) . ", {$label}"];
        if ($match !== null) {
            $lines[] = $this->matchStandingLine($match, 'waiting');
        }

        $cards = $deckBuilding['drafted_cards'];
        $lines[] = 'Your pool (' . count($cards) . ' cards):';
        $lines[] = $this->sealedPoolSummary($cards);

        $minSize = (int) $deckBuilding['min_deck_size'];
        $youSubmitted = (bool) $deckBuilding['you_submitted'];
        $opponentNames = array_map(
            static fn (array $other): string => (string) ($other['username'] ?? 'your opponent'),
            $deckBuilding['other_players'],
        );
        $opponent = $opponentNames[0] ?? 'your opponent';

        $buttons = [];
        if ($youSubmitted) {
            $lines[] = 'Your deck is in (' . count($deckBuilding['deck_card_ids']) . " cards). Waiting on {$opponent} to submit theirs.";
            $buttons[] = ['type' => 2, 'style' => 2, 'label' => 'Change deck', 'custom_id' => "ms:sealedbuild:{$gameId}"];
        } else {
            $lines[] = "Choose a deck of {$minSize}-{$deckBuilding['max_deck_size']} cards from your pool.";
            $buttons[] = ['type' => 2, 'style' => 1, 'label' => 'Use suggested deck', 'custom_id' => "ms:sealedsuggest:{$gameId}"];
            $buttons[] = ['type' => 2, 'style' => 2, 'label' => 'Preview suggested deck', 'custom_id' => "ms:sealedpreview:{$gameId}"];
            $buttons[] = ['type' => 2, 'style' => 2, 'label' => 'Build deck', 'custom_id' => "ms:sealedbuild:{$gameId}"];
            if ($deckBuilding['previous_deck_card_ids'] !== null) {
                $buttons[] = ['type' => 2, 'style' => 2, 'label' => 'Keep same deck', 'custom_id' => "ms:sealedkeep:{$gameId}"];
            }
        }
        $buttons[] = ['type' => 2, 'style' => 2, 'label' => 'Refresh', 'custom_id' => "ms:view:{$gameId}"];

        return [implode("\n", $lines), [
            ['type' => 1, 'components' => $buttons],
            $this->utilityButtonsRow(),
        ]];
    }

    /**
     * "Preview suggested deck": the same suggestDraftDeck() the "Use
     * suggested deck" button submits (deterministic for a given pool), shown
     * grouped by color with the leftover count, before anything is
     * committed. "Use this deck" is `ms:sealedsuggest`; "Back" is the
     * ordinary deck-building screen.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function suggestedDeckPreviewMessage(int $gameId, int $userId): array
    {
        $deckBuilding = $this->sealedDeckBuildingState($this->games->getState($gameId, $userId));
        if ($deckBuilding === null) {
            throw new GameStateException("Game #{$gameId} isn't waiting on a deck.");
        }

        $poolById = [];
        foreach ($deckBuilding['drafted_cards'] as $card) {
            $poolById[(int) $card['card_id']] ??= $card;
        }
        $suggested = $this->games->suggestDraftDeck($gameId, $userId);
        $cards = array_map(static fn (int $cardId): array => $poolById[$cardId], $suggested);
        $left = count($deckBuilding['drafted_cards']) - count($cards);

        $content = "**Game #{$gameId}** -- suggested deck (" . count($cards) . " cards, {$left} left in your pool):\n"
            . $this->sealedPoolSummary($cards);

        return [mb_substr($content, 0, 2000), [
            ['type' => 1, 'components' => [
                ['type' => 2, 'style' => 1, 'label' => 'Use this deck', 'custom_id' => "ms:sealedsuggest:{$gameId}"],
                ['type' => 2, 'style' => 2, 'label' => 'Back', 'custom_id' => "ms:view:{$gameId}"],
            ]],
            $this->utilityButtonsRow(),
        ]];
    }

    /**
     * @param array<int, array<string, mixed>> $cards serialized catalog cards (duplicates included)
     */
    private function sealedPoolSummary(array $cards): string
    {
        $byColor = [];
        foreach ($cards as $card) {
            $key = $card['name'] . ' (' . $card['value'] . ')';
            $byColor[ucfirst((string) $card['color'])][$key] = ($byColor[ucfirst((string) $card['color'])][$key] ?? 0) + 1;
        }
        ksort($byColor);

        $lines = [];
        foreach ($byColor as $color => $counts) {
            ksort($counts);
            $entries = [];
            foreach ($counts as $name => $count) {
                $entries[] = $count > 1 ? "{$name} x{$count}" : $name;
            }
            $lines[] = "{$color}: " . implode(', ', $entries);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null sealed_deck.deck_building, only while this game is still 'waiting' on decks
     */
    private function sealedDeckBuildingState(array $state): ?array
    {
        return ($state['game']['status'] ?? null) === 'waiting' ? ($this->draftMatchState($state)['deck_building'] ?? null) : null;
    }

    /**
     * The MODAL behind "Build deck"/"Change deck": a decklist to edit --
     * already-submitted deck if there is one, else the previous game's
     * deck (sealed is a best of three; decks usually carry over with a
     * tweak), else the whole pool to trim down to at least the minimum.
     *
     * @return array<string, mixed>
     */
    private function sealedBuildModalResponse(int $gameId, int $userId): array
    {
        $deckBuilding = $this->sealedDeckBuildingState($this->games->getState($gameId, $userId));
        if ($deckBuilding === null) {
            throw new GameStateException("Game #{$gameId} isn't waiting on a deck.");
        }

        $cardIds = $deckBuilding['deck_card_ids']
            ?? $deckBuilding['previous_deck_card_ids']
            ?? array_map(static fn (array $card): int => (int) $card['card_id'], $deckBuilding['drafted_cards']);

        return ['type' => 9, 'data' => [
            'custom_id' => "ms:sealedbuildsubmit:{$gameId}",
            'title' => 'Build your deck',
            'components' => [
                ['type' => 1, 'components' => [[
                    'type' => 4,
                    'custom_id' => 'decklist',
                    'style' => 2,
                    'label' => 'Deck (min ' . $deckBuilding['min_deck_size'] . ' cards, from your pool)',
                    'value' => $this->decklistToText($cardIds, []),
                    'max_length' => 4000,
                    'required' => true,
                ]]],
            ],
        ]];
    }

    /**
     * Starts a Sealed Deck game whose every seat has now submitted --
     * startGame() is the single source of truth for "ready", throwing
     * until they have, and the server never auto-starts a human-vs-human
     * draft-family game on its own (the web client's own poll does it),
     * so whichever player's click lands last has to. Silent otherwise.
     */
    private function startSealedIfReady(int $gameId): void
    {
        try {
            $this->games->startGame($gameId);
        } catch (GameStateException) {
            // Not everyone has submitted yet, or already started.
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function sealedDecksAllSubmitted(array $state): bool
    {
        $deckBuilding = $this->sealedDeckBuildingState($state);
        if ($deckBuilding === null || !$deckBuilding['you_submitted']) {
            return false;
        }
        foreach ($deckBuilding['other_players'] as $other) {
            if (!$other['submitted']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Quick Draft's pick screen: the pile the viewer currently holds and
     * a select of exactly 2 cards to keep (the rest go on to the next
     * player). custom_id `ms:qdpick:{gameId}:{round}:{stage}` pins the
     * submission to this exact pile -- a stale click on an old screen is
     * rejected by the engine rather than applied to a later pile. Waiting
     * on the opponent just shows what's been kept so far and a Refresh.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $match
     * @param array<string, mixed> $drafting getState()'s quick_draft.drafting
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function quickDraftPickMessage(int $gameId, array $state, ?array $match, array $drafting): array
    {
        $lines = ["**Game #{$gameId}** -- Quick Draft, round {$drafting['round']} of {$drafting['total_rounds']}, pick {$drafting['stage']} of {$drafting['total_stages']}"];

        $kept = $drafting['kept_so_far'];
        $lines[] = 'Kept so far (' . count($kept) . ' cards):' . ($kept === [] ? ' none yet' : '');
        if ($kept !== []) {
            $lines[] = $this->sealedPoolSummary($kept);
        }

        $buttons = [];
        $components = [];
        if ($drafting['status'] === 'picking') {
            $pack = $drafting['pack'];
            $keepCount = min(2, count($pack));
            $lines[] = '';
            $lines[] = "Choose {$keepCount} to keep -- the rest are passed on:";
            $options = [];
            foreach ($pack as $index => $card) {
                $lines[] = "- **{$card['name']}** ({$card['value']}, " . ucfirst((string) $card['color']) . '): ' . (string) $card['rules_text'];
                $options[] = [
                    'label' => mb_substr("{$card['name']} ({$card['value']}, " . ucfirst((string) $card['color']) . ')', 0, 100),
                    'value' => $card['card_id'] . '_' . $index,
                ];
            }
            $components[] = ['type' => 1, 'components' => [[
                'type' => 3,
                'custom_id' => "ms:qdpick:{$gameId}:{$drafting['round']}:{$drafting['stage']}",
                'placeholder' => "Pick {$keepCount} card(s) to keep...",
                'min_values' => $keepCount,
                'max_values' => $keepCount,
                'options' => array_slice($options, 0, self::MAX_SELECT_OPTIONS),
            ]]];
        } else {
            $lines[] = 'Waiting on the other player to pick.';
        }
        $buttons[] = ['type' => 2, 'style' => 2, 'label' => 'Refresh', 'custom_id' => "ms:view:{$gameId}"];
        $components[] = ['type' => 1, 'components' => $buttons];
        $components[] = $this->utilityButtonsRow();

        return [mb_substr(implode("\n", $lines), 0, 2000), $components];
    }

    /**
     * Grid Draft's pick screen: the picture of the current grid (numbered
     * arrows along the left for rows and the bottom for columns --
     * gridImageUrl()), a one-line tally of what each player has taken so
     * far, and -- on the viewer's turn -- a select of the rows/columns that
     * still have cards, each described by what it would take. The full
     * per-player lists are one button away (gridDraftedMessage()).
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $match
     * @param array<string, mixed> $drafting getState()'s grid_draft.drafting
     * @return array{0: string, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     */
    private function gridDraftPickMessage(int $gameId, array $state, ?array $match, array $drafting): array
    {
        $size = (int) $drafting['grid_size'];
        $cells = $drafting['grid_cards'];

        $lines = ["**Game #{$gameId}** -- Grid Draft, round {$drafting['current_round']} of {$drafting['total_rounds']}, pick " . ($drafting['picks_this_round'] + 1) . " of {$drafting['total_picks_per_round']}"];
        if ($match !== null) {
            $lines[] = $this->matchStandingLine($match, 'waiting');
        }

        $you = $drafting['drafted_so_far'];
        $tally = 'Drafted so far -- you: ' . $this->draftedTally($you);
        foreach ($drafting['other_players_drafted_so_far'] as $other) {
            $tally .= '; ' . ($other['username'] ?? 'opponent') . ': ' . $this->draftedTally($other['drafted_so_far']);
        }
        $lines[] = $tally;

        $components = [];
        if ($drafting['is_your_turn']) {
            $lines[] = 'Your pick -- choose a row (arrows on the left) or a column (arrows along the bottom) by its number.';
            $options = [];
            foreach (['row', 'column'] as $axis) {
                for ($i = 0; $i < $size; $i++) {
                    $names = [];
                    for ($j = 0; $j < $size; $j++) {
                        $cell = $cells[$axis === 'row' ? $i * $size + $j : $j * $size + $i];
                        if ($cell !== null) {
                            $names[] = $cell['name'];
                        }
                    }
                    if ($names === []) {
                        continue;
                    }
                    $label = ucfirst($axis) . ' ' . ($i + 1);
                    $options[] = [
                        'label' => $label,
                        'value' => "{$axis}:{$i}",
                        'description' => mb_substr(implode(', ', $names) . ' (' . count($names) . ' card' . (count($names) === 1 ? '' : 's') . ')', 0, 100),
                    ];
                }
            }
            $components[] = ['type' => 1, 'components' => [[
                'type' => 3,
                'custom_id' => "ms:gdpick:{$gameId}",
                'placeholder' => 'Take a row or column...',
                'options' => $options,
            ]]];
        } else {
            $lines[] = 'Waiting on ' . ($drafting['current_turn_username'] ?? 'the other player') . ' to pick.';
        }

        $components[] = ['type' => 1, 'components' => [
            ['type' => 2, 'style' => 2, 'label' => 'Refresh', 'custom_id' => "ms:view:{$gameId}"],
            ['type' => 2, 'style' => 2, 'label' => 'Drafted Cards', 'custom_id' => "ms:gddrafted:{$gameId}"],
        ]];
        $components[] = $this->utilityButtonsRow();

        $cellIds = array_map(static fn (?array $cell): ?int => $cell === null ? null : (int) $cell['catalog_card_id'], $cells);

        return [mb_substr(implode("\n", $lines), 0, 2000), $components, [['image' => ['url' => $this->gridImageUrl($gameId, $cellIds)]]]];
    }

    /**
     * "N cards (Red 3, Blue 2, ...)" -- the one-line summary of a drafted
     * pile shown on every Grid Draft screen.
     *
     * @param array<int, array<string, mixed>> $cards
     */
    private function draftedTally(array $cards): string
    {
        if ($cards === []) {
            return '0 cards';
        }

        $byColor = [];
        foreach ($cards as $card) {
            $color = ucfirst((string) $card['color']);
            $byColor[$color] = ($byColor[$color] ?? 0) + 1;
        }
        ksort($byColor);

        return count($cards) . ' cards (' . implode(', ', array_map(static fn (string $c, int $n): string => "{$c} {$n}", array_keys($byColor), $byColor)) . ')';
    }

    /**
     * The "Drafted Cards" button's screen: every card each player has
     * taken so far, grouped by color (Grid Draft is open information --
     * the opponent's picks were face-up on the table).
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function gridDraftedMessage(int $gameId, int $userId): array
    {
        $drafting = $this->games->getState($gameId, $userId)['grid_draft']['drafting'] ?? null;
        if ($drafting === null) {
            return $this->boardMessage($gameId, $userId);
        }

        $sections = ["**Game #{$gameId}** -- drafted so far", "**You** (" . $this->draftedTally($drafting['drafted_so_far']) . '):' . "\n" . ($drafting['drafted_so_far'] === [] ? 'nothing yet' : $this->sealedPoolSummary($drafting['drafted_so_far']))];
        foreach ($drafting['other_players_drafted_so_far'] as $other) {
            $sections[] = '**' . ($other['username'] ?? 'Opponent') . '** (' . $this->draftedTally($other['drafted_so_far']) . '):' . "\n" . ($other['drafted_so_far'] === [] ? 'nothing yet' : $this->sealedPoolSummary($other['drafted_so_far']));
        }

        return [mb_substr(implode("\n\n", $sections), 0, 2000), [
            ['type' => 1, 'components' => [
                ['type' => 2, 'style' => 1, 'label' => 'Back to the grid', 'custom_id' => "ms:view:{$gameId}"],
            ]],
            $this->utilityButtonsRow(),
        ]];
    }

    /**
     * The signed, UNAUTHENTICATED URL of the Grid Draft grid picture --
     * the same shape and reasoning as boardImageUrl() (Discord's servers
     * fetch an embed image with no session), but the cells themselves ride
     * in the URL (`cells` = catalog card ids in row-major order, empty for
     * a taken cell) and are covered by the signature, so the image is a
     * pure function of the URL: an older message keeps showing the grid it
     * was posted with, and nothing about a game can be read by guessing a
     * game id. A grid is open information to every seated player anyway.
     *
     * @param array<int, int|null> $cellCardIds
     */
    public function gridImageUrl(int $gameId, array $cellCardIds): string
    {
        $cells = implode(',', array_map(static fn (?int $id): string => $id === null ? '' : (string) $id, $cellCardIds));

        return rtrim((string) Config::get('APP_URL', ''), '/') . "/discord/grid-image?game_id={$gameId}&cells=" . rawurlencode($cells) . '&sig=' . $this->signGridImage($gameId, $cells);
    }

    public function verifyGridImageSignature(int $gameId, string $cells, string $signature): bool
    {
        return hash_equals($this->signGridImage($gameId, $cells), $signature);
    }

    private function signGridImage(int $gameId, string $cells): string
    {
        return hash_hmac('sha256', "grid:{$gameId}:{$cells}", (string) Config::get('DISCORD_CLIENT_SECRET', ''));
    }

    /**
     * The PNG for a (signature-verified) `cells` value, or null for
     * anything that isn't a 2x2-4x4 square of card ids/blanks.
     */
    public function renderGridImage(string $cells): ?string
    {
        $parts = explode(',', $cells);
        $size = (int) round(sqrt(count($parts)));
        if ($size < 2 || $size > 4 || $size * $size !== count($parts)) {
            return null;
        }

        $ids = array_map(static fn (string $part): ?int => $part === '' ? null : (int) $part, $parts);
        $present = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null && $id > 0)));
        try {
            $catalog = $present === [] ? [] : \MoodSwings\Game\CardCatalog::serialize($present);
        } catch (GameStateException) {
            return null;
        }
        $cardsById = [];
        foreach ($catalog as $card) {
            $cardsById[(int) $card['catalog_card_id']] = $card;
        }

        $gridCells = [];
        foreach ($ids as $id) {
            if ($id === null || !isset($cardsById[$id])) {
                $gridCells[] = null;
                continue;
            }
            $gridCells[] = ['path' => $this->cardArtFilePath($cardsById[$id]), 'name' => (string) $cardsById[$id]['name']];
        }

        return $this->gridImageRenderer->render($gridCells, $size);
    }

    /**
     * Pool-picking draft entry points (Quick Draft `qd`, Grid Draft `gd`):
     * choose the card pool, then vs a practice bot or a friend. The pool
     * source rides along as the custom_id's 4th part through the whole
     * flow: `ms:{mode}:0` (pool select) -> `ms:{mode}pool:0` (values[0] =
     * pool) -> `ms:{mode}botmenu:0:{pool}` -> `ms:{mode}bot:0:{pool}`
     * (values[0] = bot user id), or `ms:{mode}invite:0:{pool}` ->
     * `ms:{mode}with:0:{pool}` (values[0] = friend). See DRAFT_MODES.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function draftPoolMenuMessage(string $mode): array
    {
        $config = self::DRAFT_MODES[$mode];
        $options = [];
        foreach ($config['pools'] as $value => $label) {
            $options[] = ['label' => $label, 'value' => $value];
        }

        return [
            $config['intro'] . ' Choose the card pool:',
            [['type' => 1, 'components' => [[
                'type' => 3,
                'custom_id' => "ms:{$mode}pool:0",
                'placeholder' => 'Choose a card pool...',
                'options' => $options,
            ]]]],
        ];
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function draftOpponentMessage(string $mode, string $pool): array
    {
        $config = self::DRAFT_MODES[$mode];
        if (!isset($config['pools'][$pool])) {
            return ['Unknown card pool.', []];
        }

        return [
            "{$config['label']} ({$config['pools'][$pool]}). Who do you want to play?",
            [['type' => 1, 'components' => [
                ['type' => 2, 'style' => 1, 'label' => 'vs Practice Bot', 'custom_id' => "ms:{$mode}botmenu:0:{$pool}"],
                ['type' => 2, 'style' => 2, 'label' => 'vs a Friend', 'custom_id' => "ms:{$mode}invite:0:{$pool}"],
            ]]],
        ];
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function draftBotMessage(string $mode, int $userId, string $pool): array
    {
        $bots = $this->games->listPracticeBots();
        if ($bots === []) {
            return ['No practice bots are configured on this deployment.', []];
        }
        if (count($bots) === 1) {
            return $this->createPoolDraftGameMessage($mode, $userId, $bots[0]['user_id'], $pool);
        }

        $options = array_map(
            fn (array $bot) => ['label' => $bot['username'], 'value' => (string) $bot['user_id']],
            array_slice($bots, 0, self::MAX_SELECT_OPTIONS),
        );

        return ['Choose a practice bot for ' . self::DRAFT_MODES[$mode]['label'] . ':', [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => "ms:{$mode}bot:0:{$pool}",
            'placeholder' => 'Choose a practice bot...',
            'options' => $options,
        ]]]]];
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function draftFriendMessage(string $mode, int $userId, string $pool): array
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

        return ['Choose a friend for ' . self::DRAFT_MODES[$mode]['label'] . ':', [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => "ms:{$mode}with:0:{$pool}",
            'placeholder' => 'Choose a friend...',
            'options' => $options,
        ]]]]];
    }

    /**
     * Creates the draft match and shows its first screen. The
     * advanceAutomatedTurns() call lets a practice bot act right away (a
     * pick, or -- for Grid Draft when the bot happens to pick first -- its
     * row/column), a no-op against a human opponent.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function createPoolDraftGameMessage(string $mode, int $userId, int $opponentUserId, string $pool): array
    {
        $config = self::DRAFT_MODES[$mode];
        if (!isset($config['pools'][$pool])) {
            return ['Unknown card pool.', []];
        }

        try {
            $gameId = $this->games->createGame($userId, [$userId, $opponentUserId], ...[
                'format' => 'draft',
                'deckType' => $config['deck_type'],
                $config['pool_param'] => $pool,
            ]);
            $this->games->advanceAutomatedTurns($gameId);
        } catch (\Throwable $e) {
            return ["Couldn't start a {$config['label']} game: " . $e->getMessage(), []];
        }

        return $this->boardMessage($gameId, $userId);
    }

    /**
     * The Limited menu behind the utility row's "Limited" button.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function draftsMenuMessage(): array
    {
        return [
            'Which Limited format do you want to play?',
            [['type' => 1, 'components' => [
                ['type' => 2, 'style' => 1, 'label' => 'Sealed Deck', 'custom_id' => 'ms:sealed:0'],
                ['type' => 2, 'style' => 1, 'label' => 'Quick Draft', 'custom_id' => 'ms:qd:0'],
                ['type' => 2, 'style' => 1, 'label' => 'Grid Draft', 'custom_id' => 'ms:gd:0'],
            ]]],
        ];
    }

    /**
     * Sealed Deck entry point -- format 'draft', deck_type 'sealed_deck',
     * 2 players. Always a best of three (the engine makes every 2-player
     * draft-family match one), so there's no mode to pick; the creator
     * just chooses a practice bot or a friend. Custom_id scheme:
     * `ms:sealed:0` (this menu), `ms:sealedbotmenu:0` -> `ms:sealedbot:0`
     * (values[0] = bot user id), `ms:sealedinvite:0` ->
     * `ms:sealedwith:0` (values[0] = friend user id), then per game
     * `ms:sealedsuggest:{gameId}`, `ms:sealedbuild:{gameId}` (opens the
     * modal; `ms:sealedbuildsubmit:{gameId}` is its MODAL_SUBMIT) and
     * `ms:sealedkeep:{gameId}`.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function sealedDeckMenuMessage(): array
    {
        return [
            "Sealed Deck: you each get a fresh pool of 45 cards and build a deck of at least 12 from it, then play a best-of-three match. Who do you want to play?",
            [['type' => 1, 'components' => [
                ['type' => 2, 'style' => 1, 'label' => 'vs Practice Bot', 'custom_id' => 'ms:sealedbotmenu:0'],
                ['type' => 2, 'style' => 2, 'label' => 'vs a Friend', 'custom_id' => 'ms:sealedinvite:0'],
            ]]],
        ];
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function newSealedBotMessage(int $userId): array
    {
        $bots = $this->games->listPracticeBots();
        if ($bots === []) {
            return ['No practice bots are configured on this deployment.', []];
        }

        if (count($bots) === 1) {
            return $this->createSealedGameMessage($userId, $bots[0]['user_id']);
        }

        $options = array_map(
            fn (array $bot) => ['label' => $bot['username'], 'value' => (string) $bot['user_id']],
            array_slice($bots, 0, self::MAX_SELECT_OPTIONS),
        );

        return ['Choose a practice bot for Sealed Deck:', [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => 'ms:sealedbot:0',
            'placeholder' => 'Choose a practice bot...',
            'options' => $options,
        ]]]]];
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function newSealedFriendMessage(int $userId): array
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

        return ['Choose a friend for Sealed Deck:', [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => 'ms:sealedwith:0',
            'placeholder' => 'Choose a friend...',
            'options' => $options,
        ]]]]];
    }

    /**
     * Creates the match and shows its deck-building screen. No startGame()
     * here -- every seat still has to build a deck first. The
     * advanceAutomatedTurns() call is what makes a practice bot build and
     * submit its own deck right away (a no-op against a human opponent).
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>, 2?: array<int, array<string, mixed>>}
     */
    private function createSealedGameMessage(int $userId, int $opponentUserId): array
    {
        try {
            $gameId = $this->games->createGame($userId, [$userId, $opponentUserId], format: 'draft', deckType: 'sealed_deck');
            $this->games->advanceAutomatedTurns($gameId);
        } catch (\Throwable $e) {
            return ["Couldn't start a Sealed Deck game: " . $e->getMessage(), []];
        }

        return $this->boardMessage($gameId, $userId);
    }

    /**
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function newPracticeGameMessage(int $userId, bool $bestOfThree = false): array
    {
        $bots = $this->games->listPracticeBots();
        if ($bots === []) {
            return ['No practice bots are configured on this deployment.', []];
        }

        if (count($bots) === 1) {
            return $this->createPracticeGameMessage($userId, $bots[0]['user_id'], $bestOfThree);
        }

        $options = array_map(
            fn (array $bot) => ['label' => $bot['username'], 'value' => (string) $bot['user_id']],
            array_slice($bots, 0, self::MAX_SELECT_OPTIONS),
        );

        $components = [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => 'ms:newgamebot:' . ($bestOfThree ? '1' : '0'),
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
    private function createPracticeGameMessage(int $userId, int $botUserId, bool $bestOfThree = false): array
    {
        try {
            $gameId = $this->games->createGame($userId, [$userId, $botUserId], bestOfThree: $bestOfThree);
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
    private function newFriendGameMessage(int $userId, bool $bestOfThree = false): array
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
            'custom_id' => 'ms:friendgamewith:' . ($bestOfThree ? '1' : '0'),
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
    private function createFriendGameMessage(int $userId, int $opponentUserId, bool $bestOfThree = false): array
    {
        try {
            $gameId = $this->games->createGame($userId, [$userId, $opponentUserId], bestOfThree: $bestOfThree);
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
     * same reasoning: real design work turns out not to be needed). This
     * section covers setup/deck-submission only -- once both sides have
     * submitted and startGame() actually flips the game 'in_progress',
     * boardMessage()'s own isPlayableFormat() check (see this class's own
     * SCOPE docblock) is what renders the actual board from there; Power
     * Duel needs no separate handling there since it's just format
     * 'duel' with exactly 2 seats.
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
            ['type' => 2, 'style' => 2, 'label' => 'Best of 3: Friend', 'custom_id' => 'ms:powerduelinvite:1'],
            ['type' => 2, 'style' => 2, 'label' => 'Best of 3: Bot', 'custom_id' => 'ms:powerduelbotmenu:1'],
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
    private function newPowerDuelFriendMessage(int $userId, bool $bestOfThree = false): array
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
            'custom_id' => 'ms:powerduelwith:' . ($bestOfThree ? '1' : '0'),
            'placeholder' => 'Choose a friend...',
            'options' => $options,
        ]]]];

        return [($bestOfThree ? 'Choose a friend for a best-of-three Power Duel:' : 'Choose a friend for a Power Duel:'), $components];
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
    private function createPowerDuelGameMessage(int $userId, int $opponentUserId, bool $bestOfThree = false): array
    {
        try {
            $gameId = $this->games->createGame($userId, [$userId, $opponentUserId], format: 'duel', deckType: 'custom_duel', duelDeckRules: ['preset' => 'power'], bestOfThree: $bestOfThree);
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
    private function newPowerDuelBotMessage(int $userId, bool $bestOfThree = false): array
    {
        $bots = $this->games->listPracticeBots();
        if ($bots === []) {
            return ['No practice bots are configured on this deployment.', []];
        }

        if (count($bots) === 1) {
            return $this->choosePowerDuelBotDeckMessage($userId, $bots[0]['user_id'], $bestOfThree);
        }

        $options = array_map(
            fn (array $bot) => ['label' => $bot['username'], 'value' => (string) $bot['user_id']],
            array_slice($bots, 0, self::MAX_SELECT_OPTIONS),
        );

        $components = [['type' => 1, 'components' => [[
            'type' => 3,
            'custom_id' => 'ms:powerduelbot:' . ($bestOfThree ? '1' : '0'),
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
    private function choosePowerDuelBotDeckMessage(int $userId, int $botUserId, bool $bestOfThree = false): array
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
            'custom_id' => "ms:powerduelbotdeck:{$botUserId}" . ($bestOfThree ? ':1' : ''),
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
    private function createPowerDuelGameWithBotMessage(int $userId, int $botUserId, int $botDecklistId, bool $bestOfThree = false): array
    {
        try {
            $gameId = $this->games->createGame(
                $userId,
                [$userId, $botUserId],
                format: 'duel',
                deckType: 'custom_duel',
                duelDeckRules: ['preset' => 'power'],
                botSavedDecklistId: $botDecklistId,
                bestOfThree: $bestOfThree,
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
     * Once it succeeds, boardMessage()'s own isPlayableFormat() check
     * takes over from here and renders the real board -- see this
     * class's own SCOPE docblock for why a Power Duel needs no separate
     * handling to become playable the instant it starts.
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
                case 'sealedbuildsubmit':
                    $this->requireSeatedIn($arg, $userId);
                    $this->games->submitDraftDeckFromText($arg, $userId, $fields['decklist'] ?? '');
                    $this->startSealedIfReady($arg);
                    $this->games->advanceAutomatedTurns($arg);

                    return $this->updateMessage(...$this->boardMessage($arg, $userId));
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
            ['type' => 2, 'style' => 2, 'label' => 'Limited', 'custom_id' => 'ms:drafts:0'],
        ]];
    }

    /**
     * The "single game or best of three?" step in front of the Practice
     * and Friend pickers. $nextVerb gets the answer as its arg (0 single,
     * 1 best of three) -- Power Duel skips this step, offering its own
     * best-of-three entries right on its menu instead.
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function gameModeMessage(string $title, string $nextVerb): array
    {
        return ["{$title}: a single game, or a best-of-three match?", [['type' => 1, 'components' => [
            ['type' => 2, 'style' => 1, 'label' => 'Single game', 'custom_id' => "ms:{$nextVerb}:0"],
            ['type' => 2, 'style' => 2, 'label' => 'Best of three', 'custom_id' => "ms:{$nextVerb}:1"],
        ]]]];
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
     * class's own isPlayableFormat() (see this class's own docblock).
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
            // GameService::grantChoiceOptions() already returns each usable
            // grant's own {value, label} pair fully described (source card
            // name and restriction, if any) -- unlike every other field
            // type above, there's no live board lookup left to do here,
            // just the same value/label split every other branch produces.
            'grant_choice' => array_column($field['options'] ?? [], 'value'),
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
        $grantLabels = array_column($field['options'] ?? [], 'label', 'value');

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
                'grant_choice' => $grantLabels[$candidate] ?? "Grant #{$candidate}",
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
    private function activePlayableGameIdsFor(int $userId): array
    {
        $gameIds = [];
        foreach ($this->games->listGamesForUser($userId) as $game) {
            if ($this->isPlayableFormat($game['format'], count($game['players']), $game['deck_type'])
                && ($game['status'] === 'in_progress' || $this->waitingGameNeedsAction($game))) {
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
