# php-app

Plain PHP application implementing the MoodSwings-Web simulator, using PDO to
talk to the MySQL database defined in [`../database`](../database).

## Setup

```sh
composer install
cp .env.example .env   # then edit with your local MySQL credentials
```

Apply the database migrations (see [`../database`](../database) for details):

```sh
composer migrate
```

then start the built-in dev server:

```sh
php -S localhost:8000 -t public
```

Visit `http://localhost:8000/health` to verify the app can connect to the
database.

## Layout

- `public/` — Web server document root / front controller.
- `src/` — Application source (PSR-4 autoloaded under `MoodSwings\`).
- `bin/migrate.php` — Applies pending database migrations from
  `../database/migrations/` (see that project's README).
- `tests/` — PHPUnit tests.

## API

All responses are JSON with a `status` field (`ok` or `error`), except
`/verify-email` — that one's opened directly from an emailed link by a
human rather than called by our own JS, so it renders an HTML page
instead.

| Method | Path            | Body                                                          | Notes |
| ------ | --------------- | -------------------------------------------------------------- | ----- |
| GET    | `/health`       | —                                                                | Checks DB connectivity. |
| POST   | `/register`     | `{"username", "email", "password", "phone_number"?}`             | Creates an unverified user and emails a verification link. Username: 3-32 chars (letters/numbers/`_`/`-`); email: valid format; password: 8-72 chars; phone (optional): 7-20 chars, digits/`+`/`-`/`.`/spaces/parens. `409` on duplicate username/email, `400` on validation failure, `502` if the verification email can't be sent (registration is rolled back so you can retry). |
| GET    | `/verify-email` | query param `token`                                              | HTML page (not JSON). On success, auto-redirects to `/` after 5 seconds (plus a manual link). `400` with just a manual link (no auto-redirect) if the token is invalid/expired. |
| POST   | `/resend-verification` | `{"email"}`                                                | Issues a fresh verification link, revoking any prior one, and emails it. Always returns the same generic `200` message regardless of whether the email exists, is already verified, or was rate-limited, so it can't be used to discover which addresses are registered. Limited to once per 60 seconds per account; `400` on invalid email format, `502` if sending fails. |
| POST   | `/login`        | `{"username", "password"}`                                       | `401` on bad credentials, `403` if the email isn't verified yet. |
| POST   | `/logout`       | —                                                                 | Invalidates the current session only (other logged-in devices/sessions are unaffected). |
| GET    | `/me`           | —                                                                 | Returns the current user if authenticated, `401` otherwise. |
| GET    | `/friends`      | —                                                                 | Requires auth. Lists accepted friends (`friend_id`, `friend_username`, `created_at`). |
| GET    | `/friends/invites` | —                                                              | Requires auth. Returns `{"incoming": [...], "outgoing": [...]}`, each entry has `other_user_id`/`other_username`/`created_at`. |
| POST   | `/friends/invite` | `{"username_or_email"}`                                        | Requires auth. Sends a friend request; looks up the target by username first, then email. `404` if no such user, `409` if you already have a request/friendship/block with them (or if you invite yourself) — the message is deliberately generic when they've blocked you, so you aren't told that specifically. |
| POST   | `/friends/respond` | `{"user_id", "action"}`                                        | Requires auth. `action` is `accept`, `decline`, or `block`, responding to the pending invite from `user_id`. Declining just removes the request (not punitive — they can invite you again); blocking permanently prevents future invites from that user. `403` if you try to respond to your own outgoing invite, `404` if there's no such pending invite, `400` for an invalid `action`. |
| POST   | `/friends/remove` | `{"user_id"}`                                                  | Requires auth. Ends an existing (accepted) friendship — either side can do this, and it isn't punitive either (they can send a new request afterward). `404` if you're not currently friends with that user. |
| POST   | `/games`        | `{"opponent_user_ids": [int], "format"?, "wins_needed"?}`        | Requires auth. Creates a game seating you plus `opponent_user_ids` (2-4 players total, `format` defaults to `standard`, `wins_needed` defaults to `3`). `400` if that's more than 4 players or an opponent id doesn't exist. Returns `{"game_id"}`. |
| GET    | `/games`        | —                                                                 | Requires auth. Lists games you're seated in, most recently active first, each with `players` (`user_id`/`username`/`seat_order`) and `is_your_turn`. |
| GET    | `/games/state`  | query param `game_id`                                            | Requires auth; `403` if you're not seated in that game. Full board view: `game`, `players` (with `hand_count`/`total_wins` per seat), `you` (your `game_player_id`, and — once started — your full `hand`), `round` (turn/plays-remaining/banned-colors/`pending_decision`/etc., `null` before the game starts), `in_play`, `discard_pile`, and `deck_count` (never the deck's order). Every serialized card also carries `choice_fields` — see below. |
| POST   | `/games/start`  | `{"game_id"}`                                                     | Requires auth; `403` if you're not seated in that game. Deals hands and begins round 1. `409` if the game isn't `waiting` or has fewer than 2 seated players. |
| POST   | `/games/play`   | `{"game_id", "card_id", "choices"?}`                              | Requires auth; `403` if you're not seated in that game. `choices` is an opaque object passed straight through to the rules engine — its shape (a target player id, a discard, a mode string, etc.) is entirely card-specific; see `src/Rules/PlayerChoices.php` and `CardChoiceSchema` below. `400` on an invalid/missing choice for that card, `409` if it's not your turn, a decision is already pending, or the play is otherwise illegal. Returns `{"round_scored", "game_completed", "winner_game_player_id"?}`, or `{"pending_decision": true}` if the play now needs another player's own answer before it can finish — see `RequiresOpponentDecision` below. |
| POST   | `/games/pass`   | `{"game_id"}`                                                     | Requires auth; `403` if you're not seated in that game. `409` if it's not your turn or a decision is pending. Same return shape as `/games/play`. |
| POST   | `/games/respond` | `{"game_id", "choices"}`                                        | Requires auth; `403` if you're not seated in that game. Answers the one outstanding pending decision targeting you (see `round.pending_decision` in `/games/state`). `409` if you have no decision pending in that game. `400` on an invalid answer. Returns `{"pending_decision": true}` if the batch has other targets still waiting (or a Duplicity repeat of the same card also needs an answer), otherwise the same `{"round_scored", "game_completed", ...}` shape as `/games/play`. |

Auth-requiring routes use the same `session_token` cookie as `/me` (`401` if
missing/invalid). Friendships are stored as one row per pair of users
(see `database/migrations/0002_create_friendships_table.sql`), so each pair
can only ever have a single pending/accepted/blocked relationship — there's
no separate "invite" record that outlives the relationship it represents.

Authentication uses an httpOnly, `Secure`, `SameSite=Lax` cookie
(`session_token`) holding a random token; only its SHA-256 hash is stored in
the `sessions` table (see `database/migrations/0001_baseline.sql`), so a database leak alone
can't be used to log in. Sessions last 30 days and slide forward on each
authenticated request.

Verification links are single-use and expire after 24 hours; email is sent
via SMTP (PHPMailer) using the `SMTP_*` variables in `.env` (see
`.env.example`). `APP_URL` (no trailing slash) is used to build the link,
e.g. `https://example.com/app` if deployed under `/app`. Phone numbers and
verified email are captured for future notification use but nothing sends
notifications yet.

If a verification email fails to send, the real error (e.g. the SMTP
error PHPMailer raised) is appended to `src/mail-errors.log` — a fixed,
predictable location rather than PHP's ambient `error_log()` destination,
which varies by host and isn't always what cPanel's error log page shows.
`src/` already has a deny-all `.htaccess` from the deploy workflow, so
that file isn't web-accessible; check it via cPanel's File Manager or FTP,
not a browser.

## Rules engine

`src/Rules/` implements Mood Swings' actual gameplay -- resolving what a
mood does when it's played, computing scores, and so on -- as a pure
in-memory model with no database dependency, separate from the
account/friends layer above. The core pieces:

- `BoardState` — hands, deck, discard pile, and which moods are in play
  (with their owner, color/value overrides, and suppression state).
  Values are never cached: `valueOf()` always computes fresh from the
  current state, which is what makes the Extended Rules' "apply while-in-play
  effects, then after-playing effects" resolution order work without any
  extra bookkeeping.
- `MoodEffect` (+ `AbstractMoodEffect`) — the interface a card's behavior
  implements, dispatched by `EffectRegistry` on the card's `effect_key`.
  A card only overrides the ability timings it actually has (see
  `cards.has_*_ability`); an unregistered ability throws
  `EffectNotImplementedException` rather than silently doing nothing.
  A fourth method, `reactToAnotherPlay()`, covers the handful of cards
  whose "while in play" ability triggers off the same player's own
  subsequent plays rather than computing a value (Scorn, Validation).
- `MoodPlayService` — resolves playing one mood: pay its to-play cost (if
  any), move it into play, resolve its after-playing effect (if any),
  then let any of the player's other in-play moods react to it.
- `RoundScorer` — sums each player's mood values and settles the win/Hurt
  Feelings tie-breaks (opposite directions: ties for the win go to whoever
  played *earliest* that round, Hurt Feelings ties go to whoever played
  *latest*). Also resolves a small cluster of cards whose "while in play"
  ability multiplies how much of the board counts toward their owner's
  total: two are printed with no "may" at all (Exhilaration, Bliss) and
  stay unconditional, but two are printed as "you may" (Enthusiasm,
  Passion) and, unlike the other two, aren't always correct to take even
  at their best value -- see below.

All 127 cards in the 133-card pool with a printed ability have a
registered effect (see `DefaultEffectRegistry`) — the other 6 have no
ability at all (a flat value card, like Complacency), so there's nothing
for them to register. Chosen along the way to exercise the range of
patterns the engine needs: unconditional/conditional/restricted extra-play grants
(Benevolence, Friendliness, Kindness, Eagerness -- whose condition applies
to whichever card is chosen to use the grant, not to the card that granted
it, checked at the moment the bonus card is played via
`BoardState::hasUsablePlayGrant()`/`useGrantFor()` rather than once when
the grant is created), one-time value overrides paid for by an optional
cost, a global color override, a reusable parameterized effect covering
ten similar cards, multi-target choices (a per-target ceiling, a
combined-total ceiling, and player-scoped uniqueness), deck/hand
manipulation across players (including handing a card directly from one
player's hand to another's), mandatory "to play" costs paid from hand or
from a player's own moods already in play, dynamic values keyed off an
opponent's board state, the discard pile, or who went first this round
(`BoardState::roundFirstPlayerId()`, distinct from whose turn it currently
is), source-tied and end-of-round suppression, a modal single-vs-mass
choice, a "most common color(s) among all moods" board computation, "you
may" effects with a fixed (or condition-filtered) target set rather than
player-chosen ids, a mandatory effect resolved once per player across the
whole table, a range of pure while-in-play value formulas (self-vs-every-
opponent comparisons, a universal or any-opponent threshold, a distinct
color count, parity checks, and a five-color-presence check), a genuinely
random target (rather than another player's informed choice, which the
engine doesn't support resolving mid-play), a pairwise qualifying
condition across two chosen targets, a two-stage optional effect,
stealing a mood directly into the acting player's own hand rather than
returning it to its owner's (`BoardState::moveInPlayToPlayersHand()`), a
second reusable parameterized class for the "discard a qualifying hand
card -> value becomes X" family (`HandDiscardValueBoostEffect`, alongside
`PairedColorThresholdEffect`), a "some color reaches N" check over the
discard pile rather than moods in play, two independent options in one
effect with no cost/reward link between them (unlike the "if you do"
cards elsewhere), a single-pass turn-order distribution from the discard
pile with the remainder shuffled onto the bottom of the deck (Altruism),
a random reveal from a hand feeding a conditional (not automatic)
one-time value override (Curiosity), extra-play grants sourced from the
discard pile instead of hand (Harmony/Grief/Angst — see
`BoardState::isInDiscardPile()`/`moveDiscardToInPlay()`, and
`MoodPlayService`'s zone-aware play resolution), a persistent "who goes
first next round" override that `GameService` consults instead of the
round winner (Honor — see `BoardState::firstPlayerOverride()`, stored
as a per-mood `effectState` key so it self-corrects if that mood ever
leaves play), a direction-based simultaneous exchange with every player
at the table — each player's own informed choice of what to give up
("chooses," not "at random"), queued one decision per player the same
way `RequiresOpponentDecision` already handles a single chosen target
(Avoidance for moods in play, Confusion for hand cards), or (Rationalization's
"rotate" mode) no choice at all since a whole hand transfers rather than
a specific card — and a family
of round-scoring hooks that `GameService` resolves once a round's
scores are computed rather than at play time: a one-shot "after
scoring, do X to this mood" tag, conditional on winning or unconditional
(Bashfulness, Recklessness — `GameService::applyAfterScoringHooks()`),
the same tag applied to whichever specific card ends up consuming an
optional granted extra play rather than the mood that granted it
(Gluttony/Insecurity — an `onUseEffectState` payload on the play grant
itself, applied by `MoodPlayService` when `BoardState::useGrantFor()`
reports which grant a card actually consumed), a "give this mood away,
it returns to you after scoring if still in play" tag (Betrayal;
Recklessness's taken mood), a score swap between two players applied
before the round's winner is determined rather than after
(Sneakiness — `GameService::applyScoreSwaps()`), and a "skip scoring
entirely this round" marker paired with a one-time (as opposed to
Honor's perpetual) first-player override for next round only (Awe —
`GameService::hasSkipScoringMarker()`/`skipScoringAndAdvance()`, and
`BoardState::firstPlayerOverride()`'s `oneTimeFirstPlayerOverride` key),
and an unconditional "the round's winner is awarded an extra win" tag
that doubles `game_rounds.wins_awarded` regardless of who plays it or
who wins (Corruption — `GameService::consumeExtraWinMarker()`). A
separate, reusable "was this mood played this round" tag
(`playedInRound`, stamped on every mood the moment it enters play from
`BoardState::currentRoundNumber()`) backs a round-scoped value formula
shared by two cards with no constructor arguments needed
(Patience/Glee — `PlayedThisRoundValueEffect`), a variable-count
extra-play grant sized to close a mood-count gap with a chosen opponent
computed once at play time (Pride), a widening of which zone a
player's *normal* plays (not just bonus ones) can draw from, special-
cased by `effect_key` inside `BoardState::grantAllows()` the same way
`colorOf()` special-cases Imagination (Melancholy), and a color ban
that applies to every player but only during the single round right
after it's tagged (Doubt — `BoardState::bannedColorsThisRound()`,
checked by `MoodPlayService` before any grant/zone check), a perpetual
"every turn while in play" extra-play grant computed fresh at the start
of each of the owner's turns rather than stored anywhere on the
card itself — unconditional (Hope), restricted to a discard-sourced
color match (Grace), or conditional on another player currently having
more moods in play (Stubbornness) — with the turn the card is actually
played on handled separately since Hope/Grace have no after-playing
ability to hook (`GameService::computeFreshGrants()`, plus
`MoodPlayService`'s same-turn special case), a one-shot "banked" extra
play for a specific player's next turn — however many turns from now
that turns out to be — for another player (Generosity) or yourself
(Joy), consulted by that same `computeFreshGrants()`, and an opponent's
own choice among their qualifying moods — a genuine mid-play pause for
that other player's own answer, see `RequiresOpponentDecision` below —
tied to a "give it back if you still have it" cascade that fires only
when the taking card itself leaves play and tracks who currently holds
the taken mood so a later give-away doesn't wrongly trigger the return
(Arrogance — `BoardState`'s `cascadeMoodLeavingPlay()`, which also
finally wires up the long-dormant `clearSuppressionsFrom()` into every
"leaves play" transition, automatically lifting Faith's suppression
too), a fourth ability timing for the handful of cards whose
"while in play" ability is actually "each time you play another mood,
..." — a mandatory suppression paired with an optional color-matched
reaction (Scorn) and an unconditional grant paired with a conditional
reaction to a low-valued play (Validation) — dispatched via
`MoodEffect::reactToAnotherPlay()` using the same `PlayerChoices` already
submitted for the triggering play, since the reaction is the same
player's own decision made in the same request (Duplicity's version of
this — repeating another mood's own after-playing effect with *fresh*
choices, since a repeat usually can't reuse the same choices verbatim,
e.g. a card already discarded once can't be discarded again — is instead
offered as a genuine mid-play pause targeting the acting player
themselves, one per independent Duplicity-effective source currently in
play; see below), a mandatory hidden
hand-card choice by another player -- their own real answer, paused for
mid-play the same way as Arrogance's (Compulsion; Intimidation's
optional version, whose resulting grant is restricted to that one
specific card via a new `specific_card_ids` restriction type), that same
own-decision treatment applied to a mandatory discard from each of any
number of chosen players' hands, each queued as its own independent
decision with no shared post-processing (Suspicion), again applied
per player at the whole table at once, each player's own chosen color
then discarding every other mood matching any of the resulting colors
regardless of owner (Disillusionment), and once more for a *pair* of
moods rather than a single card -- the one case in this group whose
answer is a multi-select, not a single value (Malice) -- see
`RequiresOpponentDecision` below for all of these -- a genuine
reshuffle-and-redeal of every mood in play (including the card causing
it), reassigning ownership only and never re-triggering after-playing
effects (Chaos), a repeat of another card's own after-playing effect
with a *fresh* pending decision of its own — one per independent
Duplicity-effective source currently in play, since a repeat usually
can't reuse the same choices verbatim (e.g. a card already discarded
once can't be discarded again) — handled directly by `MoodPlayService`
since no `MoodEffect` implementation has access to the registry it
needs to re-invoke another card's effect (Duplicity — see below), and
the scoring-time multiplier cluster
described above (Exhilaration, Bliss — whose color is captured via
`BoardState::stagePrePlayEffectState()` before the card exists as a
`MoodInPlay` to attach `effectState` to normally, since its cost runs
first — Enthusiasm, Passion), a "dice" value — a card's `alt_value`,
used as an alternative to its `base_value` rather than a conditional
override — that replaces a mood's value entirely for as long as it's
tagged, on any one chosen mood in play regardless of owner
(Encouragement) or blanketing every mood its owner controls (Idealism),
resolved directly in `BoardState::valueOf()` rather than through
`computeValue()`, and a single round-wide "was any card discarded this
round" flag rather than anything tied to a specific mood's
`effectState`, since it has to reflect a discard by *any* player,
persisted on `game_rounds` alongside `pending_play_grants` (Vulnerability
— `BoardState::discardedThisRound()`). Every card in the pool with a
printed ability is now implemented.

Ten cards' printed text hands a real decision to a player other than
whoever's turn it is (Arrogance, Compulsion, Disillusionment, Instability,
Intimidation, Malice, Suspicion — see above), or "chooses"/"each player
chooses" without saying "at random" (Avoidance, Confusion, Fury — every
player with a qualifying mood/hand card gets their own queued decision,
including the acting player themselves, unlike the other seven's
single-or-several *other* players). Fury's own queued field additionally
narrows each player's candidates to only the mood(s) tied for *that
player's own* highest value, computed fresh at both queue-time and
resolve-time rather than filtered by any static color/value rule (see
`candidate_card_ids` below). Since a play resolves within one
HTTP request from the acting player alone, these implement the optional
`RequiresOpponentDecision` interface (deliberately not part of
`MoodEffect` itself — only these ten implement it) instead of
`afterPlaying()`: `pendingDecisionsFor()` is the same pre-decision
validation/candidate-computation code as before, but returns a queue of
`PendingDecisionRequest`s (one per player who needs to answer — more than
one for Suspicion/Disillusionment's per-chosen-player queues, or
Avoidance/Confusion/Fury's per-everyone-with-a-qualifying-card ones) instead
of picking randomly; `resolveDecisions()` is the old post-decision
mutation code, reading each answer by its own request key instead of
`array_rand()`. `MoodPlayService::playMood()` returns a `PlayResult`
rather than `void`: `isPending: true` the moment any decision is
outstanding, at which point the played card is already fully in play
(cost paid, grant spent) but nothing past that point has happened yet —
nothing in any of the nine mutates before its own decision point, so
there's never a partial mutation to unwind. `GameService::respondToDecision()` (`POST
/games/respond`) is the resume entry point: it records one target's
answer, and once a batch's last row is in, calls the new
`MoodPlayService::resolvePendingDecisions()`, persisted across the pause
in two new tables (`game_pending_decision_batches`/
`game_pending_decisions`, migration `0010`). While any decision is
outstanding the whole round is frozen — `playMood()`/`pass()` both check
for one first and reject with `409` — nobody, including the acting
player, can play or pass until the targeted player answers; there's no
timeout or escape hatch, matching a casual game's existing tolerance for
an idle match. That check is a plain `SELECT` ahead of `writePendingBatch()`'s
own `INSERT`, so it can't by itself stop two concurrent requests for the
same round (the same player's two open tabs, or a play racing a
`respondToDecision()` that itself uncovers a chained decision) from both
passing it before either one's batch exists — migration `0011`'s
`uq_pending_batches_one_open_per_round` unique index (on `game_round_id`
plus a generated column that's `NULL` for every resolved batch and a
constant for the one still open, if any) closes that window at the
database level: the loser of the race gets a duplicate-key error,
translated by `writePendingBatch()`'s own catch into the same
`GameStateException` the non-racing check throws, rather than silently
creating a second, simultaneously-open batch.

`BetrayalEffect` is an eleventh `RequiresOpponentDecision` implementer, for
a different reason than the other ten: nothing about Betrayal's own printed
text ("give one of your moods to another player") excludes giving Betrayal
itself away, but that mood can't be offered as an ordinary `choice_fields`
entry the way "one of your own moods" is for almost every other card --
Betrayal is still sitting in the player's *hand* at the moment an ordinary
choices panel is filled out, so a field sourced from the current board
could never legally include it as a candidate. `pendingDecisionsFor()`
returns exactly one `PendingDecisionRequest` with `targetPlayerId` set to
the *acting* player themselves (not an opponent -- the same self-targeting
`PendingDecisionRequest`'s own docblock already documents for Duplicity's
repeat-offer, just via this general interface instead of that offer's own
bespoke `MoodPlayService` code path), asked the instant Betrayal has
actually entered play; by then `target_mood_id`'s own field (`type: mood,
scope: own`, sourced from the live board the same way any other in-play
mood choice already is) legitimately includes Betrayal, since it's already
there. Never declined (no "may" in the printed text, unlike Arrogance's own
optional trigger), so `pendingDecisionsFor()` never returns `[]` here --
`recipient_player_id` stays an ordinary up-front `choice_fields` entry
(submitted, and validated, before the pause), since which *other player*
to target has no equivalent problem. The frontend needed no changes at all
for this: the pending-decision response panel already renders any decision
type other than `duplicity_repeat_offer` (Betrayal's own `betrayal_give_mood`
included) with no candidate-exclusion placeholder, so nothing had to be
taught that this one card's own decision is a legal answer to itself.

Migration 0011 only ever closed the pending-batch-specific half of a
broader gap: `playMood()`/`pass()`/`respondToDecision()` each load a
`BoardState`, mutate it in memory, and save it back across one or more
separate SQL transactions, with nothing stopping a second request for the
*same game* from interleaving somewhere in the middle and clobbering the
first's changes when both eventually save -- the same player's two open
tabs, most plausibly. `GameService::withGameLock()` closes this properly:
a MySQL named lock (`GET_LOCK`/`RELEASE_LOCK`), keyed by game id and held
for a request's *entire* duration via a closure rather than scoped to any
one SQL transaction, wraps all three entry points, serializing every
mutation for a game without requiring their already-nontrivial internal
transaction structure (several sequential transactions per request in
some paths, e.g. a chained scoring decision) to change at all. Named
locks are session-scoped, not transaction-scoped, and MariaDB releases
them automatically if a connection dies, so a crashed request can't wedge
a game forever; the timeout (`$gameLockTimeoutSeconds`, generous by
default, constructor-overridable for tests) is a backstop against a
stuck/slow request, not a number a normal request should ever approach.
With this in place, migration 0011's own constraint is now a
defense-in-depth backstop rather than the primary defense against the
race it was built for -- the lock already prevents two requests for the
same game from ever running their bodies concurrently in the first
place. Each target's own prompt reuses the *same* field shapes
`CardChoiceSchema` already defines for the acting player's own choices
(a `mood`/`hand_card`/`mode` field, evaluated from the responder's own
perspective) — the one new shape is `candidate_card_ids` (Instability,
Fury), an explicit pre-computed option list rather than a scope/filter
derivation, since Instability's two candidates come from another
player's live choice (not a rule) and Fury's "tied for that player's own
highest value" set can't be expressed as a static color/value filter at
all. `GameService::getState()` exposes the active
decision as `round.pending_decision`, including the actual prompt
(`field`) only to its target — the same hidden-hand-information scoping
opponents' hands already get.

`CardChoiceSchema::forEffectKey()` describes, per `effect_key`, exactly
which `PlayerChoices` keys a card's effect reads (a target player, a mood
in play, a hand card, a discard-pile card, a fixed mode string, a raw
value, or a yes/no flag) so a client can render a form tailored to the
specific card being played rather than one form covering every card's
possible fields. It's keyed by `effect_key` rather than by the raw key
name on purpose — the same key (`discard_card_id`) means a *hand* card for
Dignity/Bliss/Cheer but a *discard-pile* card for Nostalgia/Cynicism, so a
key-name-only scheme would conflate the two. Each field can also carry a
`filter` (colors, a value range/parity, a fixed set of qualifying values, a
required dice/alt value, or a minimum hand/mood count on a candidate
player) narrowing a dropdown to choices the effect will actually accept —
mirroring that effect class's own `InvalidChoiceException` checks exactly
(e.g. Guilt's `filter: {colors: [black, red]}` matches
`GuiltEffect::QUALIFYING_COLORS`). A field with no `filter` has no such
narrowing. Multi-select fields (`ints()`-backed) can also carry a `count`
(min/max/an optional `zero_ok` for effects that are legal empty but
otherwise need an exact number) and a `constraint` — `same_color_or_value`
(Denial/Rejection), `same_owner` (Instability's two candidate moods),
`distinct_owners` (Courage/Anxiety/Spite/Shock/Pacifism/Panic's "one per
chosen player"), or `max_total_value` (Anger) — each mirroring that
effect's own cross-candidate `InvalidChoiceException` check so a client can
validate a selection before ever submitting it. `GameService::serializeCard()`
attaches each card's `choice_fields` (plus `has_dice_value`, needed for
Encouragement's filter) to the JSON returned by `GET /games/state`.

Scorn's and Validation's `reactToAnotherPlay()` choices (`scorn_suppress_target`,
`validation_extra_play`) don't fit that per-card schema, since they fire
while playing a *different* card, triggered by a mood the acting player
already has in play — `CardChoiceSchema::reactionTemplate()` holds their
field shape, and `GameService::serializeCard()` appends the applicable one
to each of the *viewer's own* hand cards when `BoardState::playerHasMoodInPlay()`
says the viewer has that reactor in play, filling in the one detail each
needs to know about the specific card being offered: Scorn's filter is
narrowed to that card's own color (mirroring `ScornEffect`'s "shares a
color with the just-played card" check), and Validation's field is
included at all only when that card's base value is 0 or 1 (mirroring
`ValidationEffect`'s own no-op-otherwise check). Every serialized card also
carries `base_value` and `alt_value` (the printed/dice values, distinct
from the possibly-different live `value` a card in play might have) for
exactly this kind of client-side reasoning, and for display in the
frontend's card detail dialog.

Duplicity's repeat mechanic — after any card's own `afterPlaying()`
resolves, if the acting player has Duplicity in play, they may have that
same `afterPlaying()` run a *second* time with a fresh, independent set of
choices, e.g. a card discarded once can't be discarded again on the
repeat — is implemented as a genuine mid-play pause, reusing the exact
same `PendingDecisionRequest`/`game_pending_decision_batches` machinery
built for the nine `RequiresOpponentDecision` cards above, except the
`PendingDecisionRequest`'s `targetPlayerId` is the *acting* player
themselves rather than an opponent. `MoodPlayService::continueAfterPlayingChain()`
offers the repeat whenever `$invocationSeq` is still below the number of
the acting player's own in-play moods currently Duplicity-effective
(`BoardState::countMoodsInPlayWithEffectiveKey($playerId, 'duplicity')` —
a real Duplicity, or a Creativity currently copying one, via
`effectiveCardId()`) — so each independent source in play grants its own
chained repeat, rather than the old hard one-repeat-ever cap. The printed
text triggers on playing *another* mood, so when the just-played card is
itself Duplicity-effective it's excluded from that count by one (it can
never repeat its own instance via itself), but every *other*
Duplicity-effective source already in play still offers its own repeat —
e.g. playing the real Duplicity while a Creativity already copies one
still nets two extra-play grants total, one from the original play and
one from the Creativity's repeat of it. The pending
decision's `field` is a `type: 'nested'` shape — a `repeat` boolean plus a
`choices` sub-field wrapping the played card's own `afterPlayingFields()`
(`stage: 'cost'` fields filtered out, since a repeat only re-invokes
`afterPlaying()`, never `payToPlayCost()`) — resolved by
`MoodPlayService::resolveDuplicityRepeatOffer()`, which reads it via
`PlayerChoices::sub('duplicity_repeat')` the same way every other
`RequiresOpponentDecision` answer is unwrapped. Because the repeat is now
just another paused decision the player answers through
`POST /games/respond`, `GameService` needs no Duplicity-specific
serialization at all — the old `duplicityFields()` is gone; a card's
`choice_fields` describe only its own play, and any repeat offer arrives
later via `round.pending_decision`, exactly like Compulsion's or
Arrogance's.

Enthusiasm's and Passion's own "you may" scoring-time bonuses (see
`RoundScorer` above) reuse this same pause-and-respond mechanism too, but
triggered from round-end rather than mid-play: `GameService::
scoreRoundAndAdvance()` checks, before computing a final score, whether
any in-play Enthusiasm/Passion owner hasn't yet answered this round's
decision for it (`nextUnresolvedScoringDecision()`, derived fresh from
live board state plus whatever `game_pending_decision_batches` rows are
already resolved this round rather than a persisted queue) and, if so,
pauses for that one player exactly like a mid-play decision — one batch
per card, each with a single self-targeted row, chained the same way a
Duplicity repeat chains into its next one. Unlike Exhilaration/Bliss
(printed with no "may" at all, so always applied automatically),
declining Enthusiasm/Passion can be genuinely correct: Sneakiness swaps
its owner's *entire* final score with a chosen opponent's without
touching the opponent's own total, so accepting a scoring bonus you're
about to hand to someone else via that swap only helps them, never you.
`RoundScorer::score()`'s `$scoringDecisions` parameter (`cardId =>`
resolved bonus, defaulting to 0 for anything not yet answered) is what
lets the *same* method serve as both the final score and a live preview
while decisions are still outstanding — exposed as `round.scoring_preview`
(scores-so-far plus any active Sneakiness swap targets, visible to every
viewer since final scores aren't hidden information) so answering
"should I take this bonus" is never a guess. `finishScoringAndAdvance()`
holds the actual score/persist/advance logic with no transaction
management of its own, callable either from `scoreRoundAndAdvance()`'s
own transaction (the common case, no decision needed) or directly inside
`respondToDecision()`'s already-open one once the last outstanding
scoring decision resolves.

Creativity's "play as a copy of any mood" choice (`copy_card_id`, read from
the top-level choices, resolved entirely server-side in `MoodPlayService`)
means any mood currently *in play* — visible on the table, not any of the
133 printed card designs in the abstract — so it's exposed as an ordinary
`type: 'mood'`, `scope: 'any'` field (the same shape Conviction uses),
whose options are naturally already scoped to `BoardState::moodsInPlay()`
like every other `mood` field. `MoodPlayService::playMood()` enforces the
same restriction server-side with `BoardState::isInPlay($copiedCardId)`,
throwing `InvalidChoiceException` for a `copy_card_id` that isn't
currently on the table.

Since `copy_card_id` is only chosen once Creativity's own panel is open --
after the rest of `choice_fields` has already been computed against
Creativity's own (ability-less) raw catalog row -- the server additionally
precomputes, per candidate mood currently in play,
`copy_simulation[$candidateCardId] = {extra_fields, cost_payable}`
(`GameService::creativityCopySimulation()`), reusing the exact same
`reactionFields()` (Scorn/Validation) this class already calls for an
ordinary hand card, just parameterized by the *candidate's* own raw
color/base value/catalog row instead of Creativity's — Duplicity's own
repeat is no longer part of this precomputed bundle at all, since it's
now a post-play pause rather than a field on the play itself.
`cost_payable`
mirrors `MoodPlayService::playMood()`'s own to-play-cost check
(`canPayCopiedToPlayCost()`), passing Creativity's own card id -- not the
candidate's -- as the effect's `$cardId`, matching what `payMood()` itself
does (`GuileEffect`/`BlissEffect` exclude that id from the hand, and
Creativity is what's actually occupying that hand slot). The client swaps
in the matching bundle, plus the candidate's own already-serialized
`choice_fields` (its own "to play" cost and after-playing choices, read
from the same flat top-level `choices` bag a normal play of that card
would use), as `copy_card_id` changes -- see `web-static/README.md`.
`MoodPlayService`'s repeat/reaction/pending-decision machinery needed no
changes at all to support this: it was already effective-aware end to
end (`BoardState::effectiveCardId()`), so a Creativity copy of, say,
Compulsion already paused for the target's own real choice the same way
a real Compulsion would, even before the panel could offer
`target_player_id` to ask for one.

Each of the viewer's own hand cards also carries `is_playable`
(`MoodPlayService::isPlayable()`), so a client can grey out cards that
can't legally be played *right now* without having to reimplement the
rules engine's own play-legality checks: it mirrors `playMood()`'s guard
clauses that run before any effect-specific choice is even asked for —
whose turn it is, whether the card's color is banned this round
(`BoardState::bannedColorsThisRound()`), and whether any outstanding play
grant actually covers this *specific* card (e.g. Intimidation's grant
only covers the one card it revealed — every other hand card correctly
comes back `false` while that grant is outstanding). If the card has a
"to play" cost, that cost also has to be payable in principle — every
`canPayToPlayCost()` implementation only checks board-state feasibility
(e.g. Guile needs two *other* hand cards to discard), never the specific
choices passed to it, so probing with an empty `PlayerChoices` is safe.
Creativity is a partial exception here: its own raw `hasToPlay` is always
`false`, so `is_playable` -- which only ever asks "should this hand card's
button be clickable at all" -- correctly stays permissive regardless of
what it might end up copying. The narrower, copy_card_id-specific
question ("could *this* candidate's own cost actually be paid right
now") is `copy_simulation`'s `cost_payable` instead (see above), checked
dynamically once the panel is open and a candidate is chosen, via
`MoodPlayService::canPayCopiedToPlayCost()`. A doomed Creativity-copy
attempt still surfaces the usual server-side rejection at submit time
regardless.

Every in-play mood also carries `is_suppressed` plus, when suppressed,
`suppression_expiry` (`'while_source_in_play'` or `'end_of_round'`) and
`suppressed_by_card_id`/`suppressed_by_name` — the suppressing mood's id
and name, resolved from `BoardState`'s `suppressionSourceCardId`/
`cardCatalogNames()`. A source is only ever present for a
`'while_source_in_play'` suppression (Faith/Guilt/Meekness/Pacifism/Shame,
and Scorn's own version, which uses `'end_of_round'` *with* a source);
Repentance's blanket `'end_of_round'` suppression never tracks one, since
the suppression doesn't need to watch for anything leaving play to know
when to lift — it just expires at the round boundary regardless
(`BoardState::clearEndOfRoundSuppressions()`).

Suppression isn't the only "one in-play mood affects another" relationship
worth surfacing: a mood with a printed dice value (`has_dice_value`) can
have it overridden by Encouragement (one specific chosen mood,
`boostedMoodId`) or Idealism (blanket, every mood its owner controls) --
see `BoardState::diceValueBoosterCardId()`, which `valueOf()` already
called internally before this was exposed for UI purposes, just returning
`bool` under its previous name (`diceValueApplies()`). Each in-play mood
now carries `boosted_by_card_id`/`boosted_by_name` (the reverse of
`suppressed_by_*`, computed the same way, `null` unless a booster currently
applies) and `affecting` -- an array of `{card_id, name, relationship}`
naming every OTHER in-play mood this one is currently suppressing
(`relationship: 'suppressed'`, via the new `BoardState::
suppressedByCardId()`, the reverse lookup of `suppressionSourceCardId`) or
dice-value-boosting (`relationship: 'dice_value'`, one entry for
Encouragement's single target, several for Idealism's blanket one -- both
fall out of the same `diceValueBoosterCardId()` check against every other
candidate, no special-casing needed). See `GameService::
affectingEntries()`.

Every in-play mood also carries `temporary_ownership` -- `null` unless its
current owner only holds it temporarily, in which case
`{original_owner_game_player_id, original_owner_name, source_card_id,
source_card_name, reverts}` names which card caused the change, who owned
it before, and when it reverts. `reverts` is `'when_source_leaves_play'`
for Arrogance's own steal (reading its `returnsToOwnerIfCardLeavesPlay`
effectState tag) or `'after_scoring'` for Betrayal's/Recklessness's "give
it back later" (reading `returnsToOwnerAfterScoring`, whose shape changed
from a bare owner id to `{sourceCardId, ownerId}` specifically so this
method has a card to name -- `GameService::applyAfterScoringHooks()`,
the only other reader, just pulls `ownerId` back out same as before). See
`GameService::temporaryOwnershipInfo()`. Every OTHER `giveInPlayToPlayer()`
caller (Guile, Instability, Avoidance, Chaos) is a permanent trade with no
such tag, so this is `null` for those -- the change is still visible in
game history (see the `ownership_changes` section above), just without
this popup-specific "when does it end" detail.

`GameService::getState()`'s `players` mapping now carries `total_score`
alongside the existing `total_wins` -- a pure quality-of-life "add up the
numbers on the board for me" figure: the live sum of `BoardState::
valueOf()` across every mood a player currently owns in play (see the new
`boardPointTotalFor()`), not anything persisted or accumulated across
rounds. It moves with the board -- a mood entering/leaving play, or its
value changing mid-round (suppression, a dice-value boost, Imagination's
recolor, ...) -- but does NOT reset to 0 just because a round scores:
`finishScoringAndAdvance()` never clears the board on its own, only
specific cards' own `afterScoring` tags (Bashfulness/Gluttony/Insecurity/
Recklessness) remove anything, so an ordinary mood with no such tag
carries its value straight into the next round's own total, exactly as
`RoundScorer::score()` itself would count it if the round ended again
right now. This was originally built as a running total pulled from
`game_round_scores` (corrected the same day it shipped, once testing
showed a live board snapshot -- "how many points would I score right
now" -- is what a "don't make me add up my own cards" indicator actually
needs); `total_wins` is still the only place round-victory history is
summarized.

`GameService::getState()`'s `discard_pile` mapping now passes the viewer's
own game-player id to `serializeCard()` the same way `hand` already does
(previously omitted, since nothing in the discard pile was ever a play
candidate) -- `is_playable`/`choice_fields`/Scorn's and Validation's
reaction fields are now correctly computed for a discard-pile card too,
covering the rare case a discard-sourced extra play (Angst/Harmony/Grief)
or Melancholy's blanket "play from the discard pile as though it were your
hand" actually makes one playable for the rest of the current turn (see
`BoardState::grantAllows()`'s `'source' => 'discard'` handling, which
already supported this server-side -- only the state response and the
frontend's discard-pile click handling were missing).

`GameService::getState()` also carries `recent_events` -- the last 15
`game_events` rows for the game, newest first, each reduced to a single
ready-to-display `description` string (`GameService::describeEvent()`).
This exists specifically to close a hidden-information gap `mood_played`'s
own event logging otherwise leaves open: its `details` column has always
only ever held the *choices a player submitted*, never what an effect
*actually did* with them, which is indistinguishable for almost every card
(the outcome is a deterministic function of the choices) but not for
Paranoia/Curiosity -- both pick uniformly at random which of a *target's*
hand cards to reveal, with `array_rand()`'s result never appearing
anywhere in `$choices` at all. Once that single HTTP response is gone, no
player who wasn't the one who submitted the play -- including, for
Paranoia, the very player whose card got revealed -- had any way to ever
learn what it was. `BoardState::recordRevealedCard()`/
`consumeRevealedCardIds()` closes that: both effects record the id they
picked; `GameService::logEvent()` reads it back (via the shared
`withCardHistory()` helper, see below) immediately before the play's own
`mood_played` event is logged and folds it into that event's `details` as
`revealed_card_ids`, which `describeEvent()` then expands into "revealing X
from Y's hand" using the same `target_player_id` choice both cards already
share.

`BoardState::consumeCardMoves()` closes a related but distinct gap:
`recordRevealedCard()` above only ever existed for the one thing a history
entry has to recover on its own (hidden information nothing else would ever
reveal), but a *different* card was still true even for every other
`array_rand()` user (Cruelty/Indecisiveness/Altruism) and for a multi-target
`RequiresOpponentDecision` batch (Malice's color cascade, Confusion,
Disillusionment, Suspicion): the actual zone move never showed up in
history at all, even though nothing about it was secret -- the card was
already publicly visible in play or the discard pile before the move. Every
`BoardState` method that moves a card between zones (except
`moveHandToInPlay()`/`moveDiscardToInPlay()`, always the card actually
being played and so already implicit in the event's own `card_id`, and
`drawCard()`, the one zone a card moves into that's never previously
public -- see its own docblock) now calls a private `recordMove()` that
appends a `{card_id, from_zone, to_zone, from_player_id, to_player_id}`
entry to an in-memory list, regardless of whether a random pick, a
resolved opponent decision, or a submitted choice caused it. `logEvent()`'s
new `withCardHistory()` helper (folding in both `consumeRevealedCardIds()`
and `consumeCardMoves()`, replacing the old, reveal-only
`withRevealedCards()`) drains that list into every event's own `details`
under `card_moves`, and `describeEvent()` renders each entry as e.g. "Anger
moved from play to the discard pile" or "Envy moved from play to Bob's
hand" -- unconditionally, regardless of event type (so `round_scored`'s own
after-scoring hooks -- see below -- get this too) and regardless of whether
the same card was already named for a different reason in the choice
summary above (that summary says a card was *chosen*; this says where it
actually *went*, which isn't always the same information). For a
multi-target pending-decision batch, this also happens to fix a second gap
for free: `respondToDecision()` only ever logs the *last* responder's own
submitted answer as the `pending_decision_resolved` event's `details`
(every earlier target's answer was already durably written to
`game_pending_decisions` when they responded, not repeated here) -- but
since every target's move happens in the same `resolveDecisions()` call,
right before that one event is logged, `card_moves` ends up carrying every
target's own move regardless, not just the last one's.

The one call site this couldn't help was moved to make it work at all:
`respondToDecision()` used to log `pending_decision_resolved` immediately
after marking the batch resolved, *before* calling
`MoodPlayService::resolvePendingDecisions()` -- at that point, resolution
hasn't happened yet, so there would be nothing for `consumeCardMoves()` to
find. That log call now happens right after `resolvePendingDecisions()`
instead, with the same `BoardState` passed through so its own accumulated
moves are captured.

Two more pieces of history round out "anything that changes about a card
gets logged, not just what a player submitted":

- **Which zone a card was played from.** `mood_played`/
  `pending_decision_created` now say e.g. "Alice played Harmony from hand"
  or "Alice played Grief's bonus target from discard" -- necessary since a
  discard-sourced play grant (Angst/Harmony/Grief) or Melancholy's blanket
  "play from the discard pile as though it were your hand" means a play's
  own source zone isn't always the obvious default. Unlike `card_moves`/
  `revealed_card_ids` above, this doesn't need a per-request consume/clear
  step: `BoardState::moveHandToInPlay()`/`moveDiscardToInPlay()` tag the
  newly-in-play mood with a `playedFromZone` effectState key (`'hand'` or
  `'discard'`), the same way they already tag `playedInRound` -- ordinary,
  permanently-persisted effectState, so it's still there to read from
  `GameService::withPlayedFrom()` even for a play that pauses on a
  `RequiresOpponentDecision` and only actually finishes several requests
  later (the mood is already sitting in play with its tag by the time
  anyone resolves the decision). Deliberately scoped to only the two event
  types that actually announce a play -- a scoring-time
  `pending_decision_created` (Enthusiasm/Passion) is never about a card
  freshly entering play, so `withPlayedFrom()` is only ever called at the
  4 call sites that are (the initial pending pause/immediate `mood_played`
  in `playMood()`, and the chained pending pause/final `mood_played` once
  a decision resolves in `respondToDecision()`), rather than folded into
  every event automatically the way `withCardHistory()`'s three fields are.
- **Every ownership reassignment.** `BoardState::giveInPlayToPlayer()` now
  records a `{card_id, from_player_id, to_player_id}` entry (mirroring
  `recordMove()`'s own convention) into a new `$pendingOwnershipChanges`
  list, drained by `consumeOwnershipChanges()` and folded into
  `withCardHistory()` alongside `card_moves`/`revealed_card_ids` -- so
  every card whose owner changes (Guile, Instability, Avoidance, Chaos's
  full reshuffle, Arrogance's steal, Betrayal's/Recklessness's "give it
  back later" and that swap's own eventual reversal) shows up as its own
  "X changed ownership from Bob to Alice" line, tracked completely
  independently of `card_moves` -- a card's zone and its owner can each
  change without the other (most of the cards above never move the mood
  out of play at all).

Beyond the reveal-specific handling above, `describeEvent()` also appends a
generic summary of whatever was actually submitted for a `mood_played`/
`pending_decision_created`/`pending_decision_resolved` event -- "target
player: Bob", "given card: Charity" -- via `describeChoices()`/
`describeChoiceEntry()`. This is deliberately driven by each choice/answer
key's own *naming convention* (`player_id(s)`/`mood_id(s)`/`card_id(s)`
appearing anywhere in the key names what kind of id it is -- checked with
`str_contains()`, not an anchored trailing match, since Suspicion's own key
is the bare `player_ids` with no leading `target_`/`opponent_` the way
every other card's own player-id choice key has) rather than
`CardChoiceSchema`, since a `pending_decision_resolved` event's answer is
keyed by a field name generated dynamically per target (e.g. Confusion's
`given_card_id_169`), never one of `CardChoiceSchema`'s own static field
definitions -- the same generic key-shape convention still applies
regardless, so one heuristic covers every card's own choices and every
pending decision's own answer without needing per-card knowledge here at
all. `humanizeChoiceKey()` special-cases one specific key prefix,
`discard_mood_id(s)`, as "mood moved from play to discard" rather than its
own generic "discard mood" -- distinct enough from every other `discard_*`
choice (Dignity's `discard_card_id`, etc., all of which discard a *hand*
card, and still read fine as the generic "discard card") that leaving it
unlabeled read as the same familiar hand-to-discard action instead.

`describeEvent()`'s `round_scored` case is `describeRoundScored()`, not
just a static string -- every player's own final score (`$details['scores']`,
already logged, just previously unused for display) plus who won, e.g.
"Round scored (Alice: 12, Bob: 8) -- Alice won".

`round.play_grants` is a similar reminder-text pass over
`BoardState::pendingPlayGrants()` (already persisted as
`game_rounds.pending_play_grants`, but never previously surfaced to the
client at all beyond its own plain count, `plays_remaining`) --
`GameService::describePlayGrant()` renders each outstanding grant as e.g.
"An extra play from Charity" or "An extra play from Angst from the discard
pile", so a "Plays left" indicator can explain *why* a play is still
available, not just that one is. This needed `grantExtraPlay()` itself to
start tracking provenance: it now takes an optional `$sourceCardId`,
folded into the stored restriction descriptor as `'sourceCardId'` --
passed by all 21 of its call sites (every card that ever grants an extra
play) but never read by `grantAllows()` itself, purely a UI concern. The
one grant this never applies to is `startTurn()`'s own base allowance (1,
or 2 with Hurt Feelings) -- it's stored as a bare `null`, same as always,
which `describePlayGrant()` reads as "Your normal turn" rather than a
granted extra play from any specific card. `round.play_grants` itself
always describes whoever's turn it currently is, not the viewer
specifically -- the frontend's own "Plays left" indicator stays hidden
entirely unless it's actually the viewer's turn (see `web-static/README.md`),
rather than showing another player's own outstanding plays as if they were
the viewer's.

Each in-play mood's serialization also carries `base_color` alongside its
current `color` -- the printed color, ignoring Imagination's "while in
play, all moods are the chosen color" blanket override (or, for a
Creativity copy, the *copied* card's own printed color, matching how
`base_value` already resolves against the copied card rather than
Creativity's own colorless-in-spirit row). Silently identical to `color`
the overwhelming majority of the time; worth a look only when Imagination
is actually in play.

## Game layer

`src/Game/` wires the pure rules engine above to the
`games`/`game_players`/`game_rounds`/`game_round_scores`/`game_cards`/
`game_events` tables, since a real game spans many separate HTTP
request/response round trips with no process alive in between to hold a
`BoardState` in memory.

- `BoardStateRepository` — the only place the rules engine touches the
  database. `load()` reconstructs a `BoardState` from `game_cards`/
  `game_players`; `save()` rewrites every one of a game's `game_cards`
  rows (cheap enough at 133 cards per game, and avoids having to track
  which rows a given effect touched). Suppression's self-referencing
  source id is resolved in a second pass after the main upsert, since it
  points at another row's surrogate id that doesn't exist until after
  that row's insert/update has run. Turn state includes
  `game_rounds.pending_play_grants`, `first_game_player_id`,
  `round_number`, and `discarded_this_round`, since a *restricted*
  extra-play grant (see above), who went first this round
  (Chivalry/Triumph), which round a mood was played in
  (Patience/Glee/Doubt), and whether any card has been discarded this
  round yet (Vulnerability) all have to survive being reloaded fresh on
  the next request just as much as whose turn it is does.
- `GameService` — one method per player-facing action (`createGame`,
  `startGame`, `playMood`, `pass`), each loading state, delegating to the
  rules engine, persisting the result, and appending a `game_events` row,
  all within a single request. Turn advancement, round scoring (via
  `RoundScorer`), Hurt Feelings assignment (3+ player games only), losers
  drawing a card, game completion once a player reaches `wins_needed`,
  the round-scoring hooks described above (score swaps, after-scoring
  tags, Awe's skip-scoring branch, and Corruption's extra-win marker),
  and every fresh turn's play grants (`computeFreshGrants()`, layering
  Hope/Grace/Stubbornness's perpetual grants and Generosity/Joy's banked
  ones on top of the usual unconditional base) are all handled internally
  as one play or pass ripples through to the end of a round if it's the
  last play of the game.

The `/games*` routes above are the HTTP layer in front of this: they
resolve the authenticated user to their `game_player_id` for a given game
(`GameService::gamePlayerIdFor()`) before ever calling `playMood`/`pass`/
`startGame`, and `GameService::getState()` curates a `BoardState` into
JSON for rendering -- hiding opponents' hands (only `hand_count` is
exposed) and the deck's order (only `deck_count` is), while leaving the
discard pile fully visible since it's public information in the physical
game too.

## Tests

Unit tests run without a database. The `AuthIntegrationTest` suite exercises
registration/login/session-tracking against a real MySQL-compatible
database and is skipped automatically if one isn't reachable. To run it
locally, provision a throwaway database with the migration runner:

```sh
DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=moodswings_test DB_USER=root DB_PASSWORD= \
composer migrate
```

then point the tests at it via environment variables (all optional, shown
with their defaults):

```sh
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_NAME=moodswings_test \
TEST_DB_USER=root TEST_DB_PASSWORD= vendor/bin/phpunit
```

The test suite truncates `users`/`sessions`/`email_verifications`/
`friendships` in that database before each test, so never point it at a
database with real data.
