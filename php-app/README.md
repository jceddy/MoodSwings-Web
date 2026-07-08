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
  *latest*). Also resolves a small cluster of "you may score X an extra
  time" cards unconditionally, rather than through an interactive
  scoring-time choice -- since card values are never negative, taking the
  bonus is always at least as good as declining it (Exhilaration, Bliss,
  Enthusiasm, Passion — see below).

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
leaves play), a direction-based simultaneous exchange with every
player at the table (Avoidance/Confusion/Rationalization), and a family
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
own choice among their qualifying moods resolved the same random way as
Instability's, tied to a "give it back if you still have it" cascade
that fires only when the taking card itself leaves play and tracks who
currently holds the taken mood so a later give-away doesn't wrongly
trigger the return (Arrogance — `BoardState`'s `cascadeMoodLeavingPlay()`,
which also finally wires up the long-dormant `clearSuppressionsFrom()`
into every "leaves play" transition, automatically lifting Faith's
suppression too), a fourth ability timing for the handful of cards whose
"while in play" ability is actually "each time you play another mood,
..." — a mandatory suppression paired with an optional color-matched
reaction (Scorn) and an unconditional grant paired with a conditional
reaction to a low-valued play (Validation) — dispatched via
`MoodEffect::reactToAnotherPlay()` using the same `PlayerChoices` already
submitted for the triggering play, since the reaction is the same
player's own decision made in the same request (Duplicity's version of
this — repeating another mood's own after-playing effect with *fresh*
choices — remains out of scope, since there's no way to submit separate
choices for a repeat within one flat choices bag), a mandatory hidden
hand-card choice by another player resolved via a genuine random pick,
same rationale as Instability's public-info one (Compulsion;
Intimidation's optional version, whose resulting grant is restricted to
that one specific card via a new `specific_card_ids` restriction type),
and that same random-choice treatment applied per player at the whole
table at once, discarding every other mood matching any of the
resulting colors regardless of owner (Disillusionment), a genuine
reshuffle-and-redeal of every mood in play (including the card causing
it), reassigning ownership only and never re-triggering after-playing
effects (Chaos), a repeat of another card's own after-playing effect
with a *fresh*, nested sub-choices bag rather than reusing the
triggering play's choices verbatim — needed since e.g. a specific card
already discarded once can't be discarded again — handled directly by
`MoodPlayService` since no `MoodEffect` implementation has access to the
registry it needs to re-invoke another card's effect (Duplicity —
`PlayerChoices::sub()`), and the scoring-time multiplier cluster
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

Known limitations: `GameService` takes `game_player` ids directly and
assumes the caller has already authorized the action -- there's no
HTTP/API endpoint layer in front of it yet.

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
