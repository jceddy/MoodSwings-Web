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
instead. Every route except `/health` can also return `503` with
`{"status": "maintenance", "message"}` (or, for `/verify-email`, an HTML
maintenance page) — see "Maintenance mode" below.

| Method | Path            | Body                                                          | Notes |
| ------ | --------------- | -------------------------------------------------------------- | ----- |
| GET    | `/health`       | —                                                                | Checks DB connectivity. Exempt from maintenance mode (see below) — always reflects real DB health, never `503` for a pending migration. |
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
| POST   | `/games`        | `{"opponent_user_ids": [int], "format"?, "wins_needed"?, "deck_type"?, "decklist_text"?, "duel_deck_rules"?, "partner_user_id"?}` | Requires auth. Creates a game seating you plus `opponent_user_ids` (2-4 players total, `format` defaults to `standard` -- one of `standard`/`duel`/`team`/`closed_team` -- `wins_needed` defaults to `3`, `deck_type` defaults to `structure` -- one of `structure`/`power`/`jceddys_75`/`custom`/`custom_duel`/`one_of_each`, see below). `decklist_text` is required when `deck_type` is `custom` (see "Custom decklists" below) and ignored otherwise. `duel_deck_rules` (`{"preset"?, "min_cards"?, "rarity_limits"?, "duplicate_limits"?, "even_color_distribution_rarities"?}`) is required when `deck_type` is `custom_duel` (see "Custom decklists for Duel games" below) and ignored otherwise. `partner_user_id` is required when `format` is `team` or `closed_team` (one of `opponent_user_ids` -- seated adjacent for `team`, across the table for `closed_team`, see "Open Team Play"/"Closed Team Play" below) and ignored otherwise. `400` if that's more than 4 players or an opponent id doesn't exist, a `duel` game doesn't seat *exactly* 2 players total, a `team`/`closed_team` game doesn't seat *exactly* 4 players total or `partner_user_id` is missing/not one of `opponent_user_ids`, `deck_type` is `custom` with `format: 'duel'`, `deck_type` is `custom_duel` with any `format` other than `'duel'`, `deck_type` is `power` with `format: 'team'`/`'closed_team'` (see "Open Team Play"/"Closed Team Play" below), the decklist itself is invalid (unparseable line, unrecognized card name, too few cards for the table), or `duel_deck_rules` is missing/invalid (`min_cards` below 7 for a `user_defined` preset). Returns `{"game_id"}`. |
| POST   | `/games/decklist` | `{"game_id", "decklist_text"}`                                  | Requires auth; `403` if you're not seated in that game. A `custom_duel` game's own two players each call this -- while the game is still `waiting` -- to submit their own decklist, validated against the game's own deck-building rules. `400` if the game isn't `custom_duel`, isn't `waiting`, or the decklist violates a rule (too few cards, a rarity/duplicate cap exceeded). Re-submitting overwrites the previous attempt. See "Custom decklists for Duel games" below. |
| POST   | `/games/team-decision` | `{"game_id", "action", ...}`                              | Requires auth; `403` if you're not seated in that game; `409` if the game isn't `team`/`closed_team` format or has no open team decision. `action: 'propose'` takes `{"proposed_game_player_id"}` (any candidate teammate may propose); `action: 'confirm'` takes `{"approve": bool}` (the OTHER teammate approves or rejects the pending proposal). See "Open Team Play"/"Closed Team Play" below. Same return shape as `/games/play` once a proposal is confirmed; otherwise `{"round_scored": false, "game_completed": false}` (propose, or a rejected confirm sent back to 'propose'). |
| POST   | `/games/initial-pass` | `{"game_id", "card_ids": [int, int]}`                        | Requires auth; `403` if you're not seated in that game; `409` if the game isn't `closed_team`, `card_ids` isn't exactly 2 distinct cards currently in your hand, or you've already submitted your pass this game. `closed_team`'s own pregame mechanic -- see "Closed Team Play" below. Returns `{"round_scored": false, "game_completed": false, "pending_decision": bool}` (`pending_decision` is `true` until all 4 players have submitted). |
| GET    | `/games`        | —                                                                 | Requires auth. Lists games you're seated in -- `waiting`/`in_progress` games always sort above `completed` (or `abandoned`) ones regardless of recency, most-recently-active first within each of those two tiers -- each with `players` (`user_id`/`username`/`seat_order`), `is_your_turn`, and all four of `created_at`/`started_at`/`last_move_at`/`completed_at` (see "Game timestamps" below). |
| GET    | `/games/state`  | query param `game_id`                                            | Requires auth; `403` if you're not seated in that game. Full board view: `game`, `players` (with `hand_count`/`total_wins`/`team_id` per seat), `you` (your `game_player_id`, and — once started — your full `hand`), `round` (turn/plays-remaining/banned-colors/`pending_decision`/etc., `null` before the game starts), `in_play`, `discard_pile`, and `deck_count` (never the deck's order). Every serialized card also carries `choice_fields` — see below. `team`/`closed_team` format games additionally get `teams` and `team_decision` (both `null` otherwise) and `you.teammate_game_player_id` -- see "Open Team Play"/"Closed Team Play" below. `you.teammate_hand` is only ever populated for `team` (Open Team Play's own "open information" premise); `closed_team` games additionally get `initial_card_pass` (`null` once every player has submitted their pregame card pass). |
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

## Maintenance mode

Production applies database migrations by hand (see "Deployment" in the
top-level README — GitHub Actions can't reach Bluehost's MySQL directly),
typically shortly after a code deploy rather than atomically with it.
`src/Maintenance/MaintenanceGate.php` closes that gap: every request
(except `/health`, see below) compares the deployed `VERSION` file against
a `version` value stored in the `schema_version` table (`database/README.md`)
and, on any mismatch, responds `503` with `{"status": "maintenance",
"message": "..."}` instead of running the route — see `apiRequest()` in
`web-static/js/app.js` for how the frontend reacts to that. A missing
`schema_version` table (the state right after this feature's own migration
deploys but before it's been applied) or any other DB error also triggers
maintenance mode — self-bootstrapping, and exactly the condition this
feature exists to catch. An unreadable `VERSION` file instead fails
*open* (allows traffic), since blocking every user over an unrelated
file-read glitch would be worse than the problem being solved. This makes
`database/README.md`'s migration convention a hard requirement: any
change that includes a migration must also bump `VERSION`, and the
migration itself must update `schema_version` to match as its *last*
statement.

`/health` is deliberately exempt — the deploy workflows' post-deploy smoke
test (`curl -fsS ".../app/health"`, no `continue-on-error`) would hard-fail
every migration-containing deploy otherwise, even though "deploy code,
apply the migration by hand shortly after" is the documented, intentional
workflow. `/verify-email` is also exempt from the generic JSON gate, but
not skipped — since it renders an HTML page for a human clicking an
emailed link rather than JSON for our own JS, its own route block checks
`MaintenanceGate::activeMessage()` itself and responds via `respondHtml()`
instead.

`MaintenanceGate::check(string $deployedVersion): ?string` takes the
deployed version as a parameter (rather than only reading the real file)
so the comparison itself is unit/integration-testable without touching the
real repo-root `VERSION` file — see `tests/Maintenance/MaintenanceGateTest.php`.
The real production entry point, `activeMessage()`, resolves `VERSION`'s
path relative to its own file location, trying both the deployed layout
(`dist/VERSION`, a sibling of `dist/src`) and a local-checkout layout
(`VERSION` one level above `php-app/`) — see the class's own docblock for
why a single hardcoded `dirname()` depth (e.g. copying `bin/migrate.php`'s)
doesn't work here, since `bin/` and `src/Maintenance/` sit at different
relative depths from the repo root. A DB failure on the check (table
missing vs. genuinely unreachable) is logged to `src/maintenance-errors.log`
(same non-web-accessible-location precedent as `mail-errors.log` above) so
the two remain distinguishable after the fact, even though both produce
the same generic client-facing message.

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
`BoardState::currentRoundNumber()`, alongside `playedByPlayerId` —
whoever actually played it, immutable even once ownership itself
changes) backs a round-scoped value formula shared by two cards with no
constructor arguments needed (Patience/Glee — `PlayedThisRoundValueEffect`,
which checks `playedByPlayerId` against `BoardState::ownerOf()` as well as
the round number, since "you played it this round" means whoever
*currently* has it — a mood that changes hands mid-round via Guile/
Instability/Betrayal/Recklessness/Arrogance/Avoidance/Chaos'
`giveInPlayToPlayer()` no longer qualifies for its new owner even though
it's the same round, and the bonus resumes if it's ever handed back to
whoever actually played it), a variable-count
extra-play grant sized to close a mood-count gap with a chosen opponent,
computed once the acting player's own deferred choice of who to target is
answered (Pride — see `RequiresOpponentDecision` below), a widening of which zone a
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

`BetrayalEffect` is an eleventh `RequiresOpponentDecision` implementer (of
twelve, now that `PrideEffect` is a twelfth -- see below), for
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

`InstabilityEffect` reuses this exact same self-give pattern for the same
reason, but as a *second* step in its own batch rather than Betrayal's
only one: its printed text ("they choose one of those moods and give it
to you, then you give them one of your moods") doesn't exclude Instability
itself from "one of your moods" either, but `given_mood_id` used to be
collected up front alongside `candidate_mood_ids` -- before Instability
had actually entered play, so it could never legally be offered. Now
`pendingDecisionsFor()` returns *two* `PendingDecisionRequest`s: step 0 is
unchanged (`taken_mood_id`, targeting the opponent, choosing which
candidate to give up), step 1 targets the *acting* player (`given_mood_id`,
`type: mood, scope: own`, `required: true`) and is only answerable once
step 0 resolves, by which point Instability is already in play and
legitimately included as a candidate -- the same step-ordering/multiple-
different-targets machinery Suspicion's/Disillusionment's own multi-player
queues already exercise, just with two steps instead of several. Always
asked once the exchange is initiated (no further "may" once two candidates
are chosen), so `resolveDecisions()` only skips the whole thing when
`pendingDecisionsFor()` itself already returned `[]` for a fully-declined
play.

`PrideEffect` is a twelfth implementer, self-targeted the same way as
Betrayal/Instability's own deferred step, but for a different reason again:
nothing about Pride's own card was ever unofferable the way Betrayal/
Instability's were -- the problem is the *candidate list of players*.
"More moods than you" can't be evaluated correctly at the moment an
ordinary choices panel would be filled out, since Pride is still in hand
one mood short of what its own comparison needs -- a player who currently
has strictly more moods, but would only tie once Pride itself counts,
would otherwise look like a legal target. (An earlier version of this
choice stayed an ordinary up-front `choice_fields` entry with a
hand-written `more_moods_than_viewer` filter in `game.js` that manually
added 1 to the viewer's own count to compensate -- correct, but a fragile
duplicate of `PrideEffect`'s own arithmetic that had to be kept in sync by
hand.) `pendingDecisionsFor()` now builds the qualifying player list
against the real post-play board (Pride already counted) and sends it down
explicitly as the field's own `candidate_player_ids` -- a new
`fieldOptions()` case in `game.js`, mirroring the `candidate_card_ids`
handling Instability's own multi-select field already needed -- so the
frontend never has to duplicate the comparison at all. `required: false`
(Pride's own "you may choose" — omitted entirely if nobody currently
qualifies, per `pendingDecisionsFor()` returning `[]`), so declining is
answered the same way Enthusiasm's/Passion's own optional scoring
decisions already are: submit no value for the field.

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

A `type: 'mood'` field's candidates are usually drawn from `state.in_play`
directly and filtered client-side by `field.filter`, rather than embedded
in the field itself — except Instability's/Fury's/Pride's own precomputed
`candidate_card_ids` (pending-decision fields, see above) and, for the
same reason, every still-in-hand card's own `min_value`/`max_value`/
`parity`-filtered field (Courage, Anxiety, Spite, Shock, Worry, Hostility):
`GameService::withSimulatedMoodCandidates()` attaches a server-computed
`candidate_card_ids` to these too, which `game.js`'s `fieldOptions()`
already treats as authoritative and skips its own `field.filter` check for
(the same mechanism the pending-decision fields rely on). This exists
because a candidate's *own* value can depend on the very card about to be
played: Ambivalence (and the nine other `PairedColorThresholdEffect`
cards) reads "3 if there are two or more red and/or green moods" off
whatever's *currently* in play, but playing a red Shock alongside an
already-in-play green mood only tips that count to 2 once Shock is
actually in play — which `MoodPlayService::playMood()` does moments
before calling `ShockEffect::afterPlaying()`, so the rules engine already
gets it right by the time it matters. Filtering client-side by each
candidate's *pre-play* `state.in_play[].value` doesn't: a target that only
qualifies once the played card counts would look permanently ineligible.
`BoardState::valueOfAsIfAlsoInPlay()` is what actually recomputes this
correctly — it inserts a throwaway `MoodInPlay` for the card about to be
played, calls the ordinary `valueOf()`, and removes it again (a
`try`/`finally`, so a mid-computation exception can't leave it behind) —
`withSimulatedMoodCandidates()` uses it in place of each candidate's own
`value` when checking `filter`, replicating every other constraint the
field would otherwise apply (self-exclusion, `own`/`other` scope, a
`colors` filter if present) so the result is a drop-in, fully correct
replacement, not just a value re-check.

This candidate-embedding is also what makes a
`'duel' game's two identical catalog cards (see "Card identity" above) a
real UI problem for `distinct_owners` fields specifically: Pacifism's own
"one per chosen player" constraint is impossible to satisfy correctly if a
player can't tell which of two identically-named candidates belongs to
which owner in the first place. There's no dedicated server-side field for
this -- `state.in_play[].owner_game_player_id` (always present) is enough
for a client to label each option unambiguously — but the discard pile
needed one added: `state.discard_pile[].last_owner_game_player_id`/
`last_owner_name` (`BoardState::discardOwnerOf()`, `null` if untracked)
exist purely so a `type: 'discard_card'` field (Corruption's cycle,
Cynicism's/Nostalgia's own discard choices) can disambiguate the same way,
since the discard pile itself has no *current* owner to read — this
matters beyond cosmetics for Corruption specifically, since cycling a
discard-pile card bottoms it onto its *owner's* deck in a duel, so picking
the wrong physical card among two identical ones sends it to the wrong
player's deck.

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

Sharing the `pending_decision_created` event type with every mid-play
decision above means Enthusiasm's/Passion's own event needs different
`describeEvent()` phrasing, not the same one: the card triggering it has
been sitting in play since some earlier turn, not just played this
instant, so the ordinary "{actor} played {card} ..., waiting on a
response" template would misleadingly read as though the player just
played a second copy of the card. Both `writeScoringDecisionBatch()` call
sites (the round-end check above and the "another scoring decision still
outstanding" chain inside `respondToDecision()`) tag their own
`logEvent()` call with `['scoring_trigger' => true]` instead of `[]`,
which `describeEvent()` checks to pick "{card}'s scoring effect
triggered, waiting on a response from {actor}" instead.

`round.scoring_effects` is a related but separate field: unlike
`scoring_preview` (only present while an Enthusiasm/Passion decision is
actually outstanding), this is always computed the moment a round exists,
so a player can see how scoring will play out *before* the round even
ends. Built by `GameService::scoringEffectEntries()`, it's one
`{card_id, card_name, owner_game_player_id, description}` entry per
in-play mood whose ability changes how this round scores — Bliss and
Exhilaration (always, for as long as they stay in play), Enthusiasm and
Passion (likewise, since their "you may" option recurs every round), and
Sneakiness/Awe/Corruption (only for as long as their one-time
round-scoped `effectState` tag stays set — `swapScoreWithPlayerId`/
`skipScoringThisRound`/`awardsExtraWin` — since `applyScoreSwaps()`/
`skipScoringAndAdvance()`/`consumeExtraWinMarker()` each clear their own
tag once the round it covers actually scores, so a stale Sneakiness from
three rounds ago never lingers here). None of this is hidden information
— an in-play card and the choice it was played with are both already
public — so every viewer sees the same list. The `effect_key` lookup goes
through `BoardState::effectiveCardId()`, mirroring `RoundScorer::score()`'s
own check, so a Creativity copy of one of these cards is picked up the
same way it actually contributes to the score.

Every in-play mood also carries `bliss_discard_color` — `null` for every
card except an in-play Bliss, which reads it from its own `blissColor`
`effectState` (the color of whatever was discarded to pay its cost,
captured once at play time — see `BlissEffect::payToPlayCost()`) so the
client can show *which* color it's currently tripling without the player
having to remember what they discarded.

`round.board_effects` is `scoring_effects`' sibling for non-scoring
board-wide reshaping: same `{card_id, card_name, owner_game_player_id,
description}` shape, built by `GameService::boardEffectEntries()`, but for
an in-play mood whose "while in play" ability changes what every mood *is*
rather than what it's worth. Today that's just Imagination — "While in
play, all moods are the chosen color and no other colors" — read from its
own `color` `effectState` (set by `ImaginationEffect::afterPlaying()`, the
same tag `BoardState::colorOf()` already consults for every color-counting
effect); an in-play Imagination with no `color` tagged yet (a test-only
state a real play can't produce) is simply omitted. The two lists are kept
separate rather than merged because they answer different questions —
`scoring_effects` is "how will this round's score come out,"
`board_effects` is "what do the cards on the table actually look like
right now" — so a future card that does both would appear in both lists,
not force a shared description format to cover two different concerns.

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

Once an in-play Creativity is actually copying something, `serializeCard()`
displays it AS the copied mood rather than as Creativity: `name`,
`effect_key`, and `rules_text` all switch from Creativity's own (raw,
ability-less) catalog row to `catalogRow(effectiveCardId($cardId))`'s --
the same lookup `color`/`base_value`/`alt_value` already used -- so an
in-play Creativity copying Serenity reads and behaves as "Serenity"
everywhere, including `bliss_discard_color` (below) if it copied Bliss
specifically, since `BlissEffect::payToPlayCost()` always tags
`blissColor` on the *playing* card's own id regardless of what it copies.
A new `is_creativity_copy` boolean (true only for an in-play Creativity
with a real `copiedCardId` -- false for a "blank," uncopied Creativity,
whose `effectiveCardId()` just returns itself) is exposed alongside so the
client can still say *which* card is doing the copying, since the raw
printed identity is otherwise invisible once the display switches over.
`choice_fields` and `copy_simulation` are deliberately exempt from this
switch -- both describe what's available when *playing* Creativity from
hand, which its own printed `creativity` effect_key always governs
regardless of what it later copies once in play.

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
`GameService::cardNamesFor()`. A source is only ever present for a
`'while_source_in_play'` suppression (Faith/Guilt/Meekness/Pacifism/Shame,
and Scorn's own version, which uses `'end_of_round'` *with* a source);
Repentance's blanket `'end_of_round'` suppression never tracks one, since
the suppression doesn't need to watch for anything leaving play to know
when to lift — it just expires at the round boundary regardless
(`BoardState::clearEndOfRoundSuppressions()`).

Every in-play mood also carries `value_locked` -- true once a permanent
one-time "after playing this mood, ... this mood's value becomes N"
trigger (Dignity, Delight, Cynicism, and 7 other cards -- every one that
calls `BoardState::setValueOverride()`) has actually fired, as opposed to
a continuously recomputed "while in play" value (Determination): both
kinds of card can end up with `value === alt_value`, but only the former
locks it in via `effectState['valueOverride']`, which `valueOf()` checks
first and unconditionally returns once set. The frontend uses this to
rotate the card art 180 degrees, matching a suppressed mood's own 90
degree rotation -- see "Card art rendering" in `web-static/README.md`.

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

Moving that call earlier also uncovered a genuinely redundant event
`respondToDecision()` used to always log afterward: once the whole chain
finishes (`$result->isPending` false), it used to close with its own
`mood_played` event -- but by that point, `resolvePendingDecisions()` has
already run its full course (including any Scorn/Validation reaction
loop), so `pending_decision_resolved`'s own `withCardHistory()` call,
logged moments earlier, has *already* drained every `card_moves`/
`ownership_changes`/`revealed_card_ids` entry that resolution produced.
The closing `mood_played` event's own `$details` would then only ever
contain the submitted choices already shown once on the original
`pending_decision_created` event -- a plain "played {card} ({choices})"
duplicate with nothing new to say, for every single opponent-decision
play (e.g. Betrayal's own "played Betrayal from hand (recipient player:
Bob)" appearing a second time, unchanged, right after "A response to
Betrayal was resolved" already described what actually happened). That
closing `logEvent()` call is gone; `respondToDecision()` now just returns
`finishPlay()` once the batch is fully resolved, relying entirely on the
`pending_decision_created` (announces the play) and `pending_decision_resolved`
(the last responder's own answer plus every card_moves/ownership_changes
entry the resolution produced) pair to tell the whole story. `playMood()`'s
own `mood_played` event, for a play that never pauses at all, is
unaffected -- there, it's the *only* event for that play, not a duplicate
of one already logged moments earlier.

Four more pieces of history round out "anything that changes about a card
(or a player's outstanding plays) gets logged, not just what a player
submitted":

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
- **Drawing a card -- who, never what.** `BoardState::drawCard()` now
  appends the drawing player's id (only) to a new `$pendingDraws` list on
  every successful draw (Zeal, Doubt, Paranoia, Corruption, Conviction,
  Hate, Rationalization's own after-playing draws, plus the "each
  non-winning player draws a card" `finishScoringAndAdvance()` already ran
  at every round's end), drained by `consumeDraws()` and folded into
  `withCardHistory()` under `draws` alongside the three fields above.
  Deliberately the *one* exception to "record what actually happened,
  not just what was chosen" this whole section otherwise follows:
  `drawCard()`'s own docblock already explained why entering a hand was
  never recorded here at all (unlike every other zone, it was never
  previously public) -- `$pendingDraws` doesn't change that, it just
  finally surfaces the fact that *a* draw happened, without violating the
  reason the card itself still stays hidden. `describeEvent()` renders
  each entry as its own "Alice drew a card" segment, one per draw (not
  grouped/counted, matching `card_moves`/`ownership_changes` above),
  since e.g. Corruption can draw the same player up to two cards in one
  event.
- **An extra play grant, at both ends of its life.** `BoardState::
  grantExtraPlay()` now also appends each restriction descriptor it
  creates to a new `$pendingGrantsCreated` list (mirroring what it
  already pushes onto `$playGrants` itself), drained by
  `consumeGrantsCreated()` -- so the moment Charity/Fear/Validation/
  Duplicity/etc. grants a bonus play, that event's own description gains
  an "Alice was granted an extra play from Charity" segment per grant,
  reusing the same source/zone/restriction wording `describePlayGrant()`
  already renders for an *outstanding* grant in `round.play_grants`
  (extracted into a shared `describeGrantDetails()` helper both now call).
  Symmetrically, `BoardState::useGrantFor()` now records the restriction
  it actually consumed into `$pendingGrantUsed` -- but only when it's a
  genuine granted extra play, never the ordinary null-restriction base
  allowance every turn already starts with -- drained by
  `consumeGrantUsed()` and folded into `details` as `grant_used`, which
  `describeEvent()` appends directly onto the *same* `mood_played`/
  `pending_decision_created` line as `played_from`, e.g. "Bob played
  Apathy from hand (using an extra play from Charity)" -- distinct from,
  and logged well after, the "was granted" line above, which only
  announces a grant's existence, not that it was ever used. Deliberately
  never populated by `computeFreshGrants()`'s own perpetual (Hope/Grace/
  Stubbornness) or banked (Generosity/Joy) recomputation at the start of a
  future turn, since that bypasses `grantExtraPlay()` entirely -- logging
  those would mean re-announcing the same ongoing "while in play" ability
  every single turn it's still in effect, not a one-time event worth a
  history line the way an immediate, same-turn grant is.

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
"Round scored (Alice: 12, Bob: 8) -- Alice won". In a 3+ player game, this
also calls out who Hurt Feelings goes to next round: `scoreRoundAndAdvance()`
folds its already-computed `$hurtFeelingsHolder` into this same event's
`details` as `hurt_feelings_game_player_id`, and `describeRoundScored()`
appends "; Charlie has Hurt Feelings next round" when it's non-null --
otherwise the only way to learn who holds it was the players-list
indicator (see "Hurt Feelings" above), which only ever shows the *current*
round's holder, never who just received it.

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
play) but never read by `grantAllows()` itself, purely a UI concern.
`computeFreshGrants()` -- which recomputes Hope/Grace/Stubbornness's
perpetual grants and any banked Generosity/Joy grant fresh at the start of
every turn, bypassing `grantExtraPlay()` entirely since there's no one-time
card play to attribute the bonus to -- attaches the same `sourceCardId` to
each of those via a small `effectiveSourceCardIds()` helper, so they name
their source exactly like any other grant instead of collapsing into "Your
normal turn". That helper resolves through `BoardState::effectiveCardId()`,
so a Creativity currently copying Hope attributes its bonus play to the
copied Hope's own instance id, matching how `serializeCard()` already shows
that same Creativity as "Hope" everywhere else. It returns *every*
qualifying mood a player owns, not just the first -- two independent real
Hopes (a duplicate printed card across a duel game's two separate decks,
or an intentionally duplicate-including custom deck) each contribute their
own perpetual grant every turn, the same way `MoodPlayService` already
grants one same-turn bonus per Hope actually played regardless of how many
copies get played in a single turn. The one grant this never
applies to is `startTurn()`'s own base allowance (1, or 2 with Hurt
Feelings) -- it's stored as a bare `null`, which `describePlayGrant()`
reads as "Your normal turn" rather than a granted extra play from any
specific card. `round.play_grants` itself
always describes whoever's turn it currently is, not the viewer
specifically -- the frontend's own "Plays left" indicator stays hidden
entirely unless it's actually the viewer's turn (see `web-static/README.md`),
rather than showing another player's own outstanding plays as if they were
the viewer's.

Hope's and Grace's own grants -- both the same-turn one
(`MoodPlayService`, the moment either card enters play) and every future
turn's perpetual one (`computeFreshGrants()`) -- also carry
`'requiresSourceInPlay' => true` alongside their `sourceCardId`. Unlike an
ordinary grant, one tagged this way is lost outright, not merely
un-attributed, if that specific Hope or Grace leaves play (discarded,
returned to hand, etc.) before a player gets around to actually using the
play it granted -- `BoardState::grantIsActive()` is consulted by
`playsRemaining()`, `pendingPlayGrants()`, `hasUsablePlayGrant()`, and
`useGrantFor()` alike, so a dead grant disappears from the "Plays left"
count and can never be the one consumed to play a card, without needing
to actively prune `$playGrants` the instant the source leaves play (its
entry just sits inert from then on). Stubbornness's own perpetual grant
is deliberately exempt -- its text grants a play "at the start of your
turn" outright, with nothing tying the grant's survival to Stubbornness's
own continued presence the way Hope's/Grace's "while in play" phrasing
does, so once granted, it persists for that turn even if Stubbornness
itself is later discarded. Neither the base allowance nor a banked
Generosity/Joy grant carry this tag either, both unaffected by the
distinction.

Losing a grant this way is silent from `playsRemaining()`'s own
perspective -- it just reads one lower, with nothing to say why -- so
`BoardState::cascadeMoodLeavingPlay()` (already the one place every
move-out-of-play method funnels through) additionally records it via
`$pendingGrantsLost`/`consumeGrantsLost()`, the same consume-before-
logging convention `$pendingGrantsCreated`/`$pendingGrantUsed` already use.
`GameService::withCardHistory()` folds whatever it returns into the
current event's `details['grants_lost']`, and `describeEvent()` appends
"{player} lost an extra play from Hope -- its source left play before it
was used" to that event's description (reusing `describeGrantDetails()`,
the same wording a newly created or just-used grant already gets),
attributed to `$actor` for the same reason `grants_created` already is:
`$playGrants` only ever holds whoever's turn is currently active, so
whoever's move triggered the card leaving play is always the same player
the lost grant belonged to. This surfaces in the game's event log (`GET
/games/state`'s `recent_events`) on whatever play or response actually
moved the source card out of play -- e.g. Bravado discarding a player's own
Hope as its own cost logs both "was granted an extra play from Bravado"
and "lost an extra play from Hope" on that same `mood_played` event -- so a
player never has to reverse-engineer a suddenly-missing extra play from
`plays_remaining` alone. Never populated for an ordinary grant (Stubbornness's,
a banked Generosity/Joy grant, or the base allowance), since none of those
are tied to their source card's continued presence in the first place --
see `grantIsActive()` above.

Every `sourceCardId` above is always a per-game *instance* id
(`game_cards.id`, same as `MoodInPlay::$cardId` -- see its own docblock),
never a catalog id -- two independent real Hopes each carry their own
distinct `sourceCardId`, exactly like `testTwoIndependentHopesEachGrantTheirOwnPerpetualExtraPlay()`
already exercises. This is what makes it meaningful to let a player choose
*which* grant to spend when more than one would cover the same play (see
`usableGrants()`/`grant_source_card_id` below) -- the choice is always
between specific physical cards, never ambiguous "some Hope or other"
options.

When 2+ outstanding grants would each independently permit playing a given
card -- most commonly two Hopes/Graces both still armed, or one plus the
base allowance -- `BoardState::useGrantFor()` used to just consume
whichever came first in `$playGrants`' own order, giving the player no say
over which one got spent even though it matters (a Hope-sourced grant is
lost outright if that Hope later leaves play before its bonus is used --
see above -- so spending the more fragile grant first can matter).
`BoardState::usableGrants(int $cardId, int $playerId)` returns every
currently-usable grant for that card, deduplicated by `sourceCardId` (`??
'base'`, since the base allowance's own bare `null`s -- there can be 2 with
Hurt Feelings -- are indistinguishable to a player choosing between them
and so collapse into a single entry). `GameService::serializeCard()`
prepends a `grant_source_card_id` choice field (`type: 'grant_choice'`,
`required: false`) whenever this returns 2+ entries, one option per grant,
reusing `describePlayGrant()`'s own description text verbatim as each
option's label and its `source_card_id` (`0` standing in for the base
allowance, which has none of its own) as the option's value -- so "An
extra play from Hope" never needs to be written twice. Submitting
`grant_source_card_id` is optional even when the field is offered (left
absent, `MoodPlayService::playMood()` falls back to the old "whichever
comes first" behavior via `useGrantFor()`'s new optional
`$preferredSourceCardId` parameter) but, if given, is validated against
`usableGrants()` before being honored -- a stale or fabricated preference
(naming a grant that's since been consumed or lost) throws
`InvalidChoiceException` rather than silently falling through to spend
some *other* grant the player never chose, which would otherwise corrupt
`playsRemaining()` in a way that's hard to notice after the fact.

Each in-play mood's own serialization also carries `has_unused_play_grant`
-- whether that specific card currently has an active, not-yet-consumed
play grant it's responsible for (cross-referenced against
`BoardState::pendingPlayGrants()`'s own `sourceCardId`s). Most useful for
an in-play Hope/Grace, since losing track of whether its own bonus is
still available actually matters (see `grantIsActive()` above) -- the
frontend surfaces this in the card detail dialog (see
`web-static/README.md`). It's only ever `true` during that mood's own
owner's turn: a future turn's perpetual Hope/Grace bonus doesn't exist as a
grant at all until `computeFreshGrants()` creates it fresh when that turn
starts, so this reads `false` the rest of the time, not as a limitation but
because there's genuinely nothing outstanding to flag yet.

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
  drawing a card (skipped entirely for the round that pushes the winner to
  `wins_needed` -- there's no next round for that card to matter in, and a
  player shouldn't draw one off the round that just ended the game), game
  completion once a player reaches `wins_needed`,
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

### Deck types

`deck_type` (chosen once at `createGame()` time, like `format`, and read by
`startGame()` when the deck is actually assembled -- nothing about which
cards a game ends up with is decided before then) picks which pool of
cards a game draws from, via `GameService::deckCardIdsFor()`'s dispatch to
one of six builders:

- `structure` (the default) -- `buildStructureDeckCardIds()` assembles a
  randomly-drawn, singleton 45-card deck matching a new physical box's own
  printed rarity distribution (`STRUCTURE_DECK_RARITY_COUNTS`: 23 common,
  14 uncommon, 6 rare, 2 mythic), one rarity at a time so the mix is exact
  rather than merely likely.
- `power` -- `buildPowerDeckCardIds()` assembles a smaller, faster
  15-card deck: exactly one random Mythic (drawn first, on its own, so
  it's guaranteed rather than merely likely) plus
  `POWER_DECK_NON_MYTHIC_COUNT` (14) more cards drawn uniformly at random
  from every non-Mythic card in the catalog pooled together -- unlike
  `structure`, nothing beyond that single Mythic is guaranteed about the
  other 14's own rarity mix.
- `jceddys_75` -- `buildJceddys75DeckCardIds()` assembles a 75-card deck
  built independently per color (`JCEDDYS_75_DECK_COLORS`), 15 cards each:
  1 random Mythic, 2 *different* random Rares, 4 random Uncommons (up to 2
  copies of any one), and 8 random Commons (up to 3 copies of any one) --
  `JCEDDYS_75_DECK_RARITY_SPEC`'s `count`/`max_copies` pairs. Unlike
  `structure`'s/`power`'s always-singleton pools, this one deliberately
  allows a bounded number of repeats within the Uncommon/Rare/Common tiers
  (Mythics and Rares stay singleton -- a 1-copy cap forces that). Built by
  `randomCardIdsWithCopyLimit()`: expand a color/rarity's own card pool
  into `max_copies` copies of each id, shuffle, take the first `count` --
  so no id can ever exceed its cap while every id still has an equal
  chance of being picked, and `max_copies=1` (Mythic/Rare) degenerates to
  an ordinary without-replacement draw.
- `custom` -- the creator supplies their own decklist (see "Custom
  decklists" below) instead of one of the algorithmically-assembled pools
  above; `customDeckCardIds()` just reads back the card ids
  `createGame()` already parsed and validated. Only supported for
  Traditional (non-`duel`) games.
- `custom_duel` -- for `duel` games only: each of the two players supplies
  their *own* decklist against deck-building rules the creator defines
  (see "Custom decklists for Duel games" below) -- unlike every other
  deck_type, `deckCardIdsFor()` explicitly refuses to build this one
  (a `\LogicException`, not a `GameStateException` -- this is a
  programmer error, not a user-facing one), since there's no single "the"
  deck for a `custom_duel` game the way there is for every other type;
  `startGame()` reads each player's own submitted deck directly instead.
- `one_of_each` -- the full 133-card pool, one copy of every printed card,
  unchanged from the only option that existed before `deck_type` did.

`structure`, `power`, and `one_of_each` are always singleton within one
deck (no repeated card ids); `jceddys_75` and `custom` are the exceptions
-- `custom`'s repeat behavior is whatever the creator's own decklist says.
`custom_duel` is whatever each player's own decklist says, same as
`custom`, but constrained by the creator's own rules (see below).
`deck_type` was named `standard` before `power` existed, when there was
only one alternative to `one_of_each` to distinguish it from; it was
renamed `structure` once a second small-deck option needed a name of its
own too. A game created before that rename still has `deck_type =
'standard'` rows in the database migrated forward to `'structure'` by
migration `0014`, so no existing game's own deck type silently changed.

### Custom decklists

`deck_type: 'custom'` lets a Traditional game's creator supply their own
decklist -- either uploaded as a text file or pasted into a form field,
both of which just become the same `decklist_text` string by the time it
reaches `createGame()`. Only supported for `format: 'standard'`
(`GameStateException` if `format: 'duel'` -- a duel already needs each
player to have their own *algorithmically-built* deck, and letting one
player supply a decklist for both would break that symmetry).

Parsing and validation both happen once, at `createGame()` time, via
`DecklistParser` (`src/Game/DecklistParser.php`) -- a pure, DB-free class
(the catalog's own case-insensitive name-to-id map is constructor-injected
by `GameService::parseCustomDecklist()`, which builds it from a plain
`SELECT id, name FROM cards`) so the format's own grammar is fully
unit-testable without a database. The fully-resolved outcome -- an
optional deck name plus the flat list of catalog card ids (one entry per
copy) -- is what actually gets stored, in `games.custom_deck_name` /
`games.custom_deck_card_ids` (migration `0018`), not the raw decklist
text itself; `startGame()` never re-parses anything, it just reads the
already-resolved ids back via `customDeckCardIds()`. This also means a
decklist error (an unrecognized card, too few cards for the table) surfaces
immediately as a `400` from `POST /games`, rather than only once the game
is actually started.

The decklist format is line-oriented:

- An optional `About` block, only recognized as the file's very first
  line, holds `<field name> <field data>` metadata lines until a blank
  line ends it. The only field currently read is `Name` (truncated to 120
  characters, matching `custom_deck_name`'s column width) -- any other
  field is silently ignored rather than rejected, so a decklist exported
  by some other tool with extra metadata fields still parses. No `About`
  block (or no `Name` line within it) leaves the deck name `null`, and the
  client shows "Uploaded Deck" in that case (see `web-static/js/game.js`'s
  `renderBoard()`).
- Each remaining line up to the next blank line is one card entry: an
  optional leading `<count>` (default 1 if omitted), the card name, and an
  optional trailing `(SET CODE)` and/or card number -- both silently
  ignored today (only one set exists), but accepted so a decklist copied
  from an export tool that includes them still parses. Card names resolve
  case-insensitively against the catalog's own `UNIQUE KEY uq_cards_name`.
- A single blank line ends the main deck. Everything after it (an optional
  `Sideboard` header line, plus more card lines in the same format) is
  parsed no further and simply discarded -- sideboards aren't supported by
  any game feature yet, so there's nothing to do with them.

The minimum card count follows the same "15 cards, plus 15 more per player
beyond the first two" rule the feature was specified with -- `15 * (N -
1)` for `N` players, i.e. 15/30/45/60 for 2/3/4 players (`self::MAX_PLAYERS`
caps `N` at 4 anyway). `DecklistParser` itself is player-count-agnostic
(it has no idea how many players the game has); `GameService::createGame()`
checks the resolved card count against that formula after parsing.

### Custom decklists for Duel games

`deck_type: 'custom_duel'` is Duel's own version of `custom` -- instead of
the whole table sharing one creator-supplied decklist, each of the two
duel players supplies their *own* (same file/paste format, same
`DecklistParser`), and the creator additionally defines the deck-building
*rules* both players' decklists must satisfy. Two structural differences
from `custom` drive the rest of this section: there's no single decklist
to parse at `createGame()` time (nothing is parsed until each player
submits their own), and the rules themselves -- not just the resulting
card ids -- have to be persisted, since `submitCustomDuelDeck()` needs
them again for every later submission attempt.

**Rules (`DuelDeckRules`, `src/Game/DuelDeckRules.php`)** -- a pure value
object holding four things, matching exactly what the feature was
specified with:

- `minCards` -- the deck's own minimum card count, floored at 7
  (`GameStateException` if lower -- enforced in the constructor, so it's
  impossible to construct an under-the-floor instance at all, whether
  from a preset or a user-defined value).
- `rarityLimits` -- an optional-per-rarity `{rarity: max count}` map; a
  missing rarity means no restriction on how many cards of that rarity
  the deck can have.
- `duplicateLimits` -- an optional-per-rarity `{rarity: max copies}` map;
  a missing rarity means no restriction on how many copies of any single
  card of that rarity the deck can have.
- `evenColorDistributionRarities` -- an optional list of rarities that
  must be split evenly across all 5 colors: for each listed rarity, that
  rarity's own total card count must be divisible by 5, and each color
  must contribute exactly total/5 of them. A rarity absent from the list
  has no such requirement (its cards can skew toward any color mix). This
  is the one rule of the four expressed as a plain list rather than a
  `{rarity: value}` map, since it's a flag, not a count.

`validate(cardIds, catalogById)` checks a resolved decklist against all
four at once, throwing a `GameStateException` naming the exact violation
(too few cards; too many of a rarity; too many copies of a named card; a
rarity's total that can't split into 5 equal groups; a specific color
over/under its expected share) -- the same "surface the real problem, not
a generic rejection" approach `DecklistParser`'s own errors take.

**Presets (`DuelDeckRules::forPreset()`)** -- the creator picks `structure`,
`power`, `jceddys_75`, or `user_defined` (`games.custom_duel_rules_preset`,
purely for display -- the *resolved* values are what's actually enforced).
The first three approximate `buildStructureDeckCardIds()`/
`buildPowerDeckCardIds()`/`buildJceddys75DeckCardIds()`'s own generators as
closely as this rule shape allows:

- `structure` and `jceddys_75` land on an **exact** match on rarity split.
  Both cap every rarity, and set `minCards` to the *sum* of those caps --
  a deck meeting the minimum while respecting every individual cap is
  mathematically forced to hit each cap exactly (if any rarity fell
  short, the total couldn't reach `minCards` without another rarity
  exceeding its own cap), so these two presets reproduce the generators'
  own exact rarity splits without needing anything more expressive than
  "cap + minimum." `jceddys_75`'s own per-color counts (1 Mythic/2 Rare/4
  Uncommon/8 Common per color) are summed across all 5 colors into
  aggregate rarity limits (5/10/20/40) -- on their own these limits have
  no notion of color, only rarity, so `jceddys_75` additionally locks
  `evenColorDistributionRarities` to all four rarities, matching the real
  generator's own "N per color, for every color" guarantee exactly rather
  than just its aggregate rarity counts.
- `power` is only an **approximation**: the real generator guarantees
  exactly one Mythic among 15 singleton cards pooled from every other
  rarity, but this rule shape has no way to *require* a rarity be present
  (only cap it) -- the closest available rule is "at least 15 cards, at
  most 1 Mythic, singleton," which a Mythic-less 15-card deck would still
  pass even though the real Power generator could never produce one.

Picking a preset locks its four values in verbatim at `createGame()`
time, ignoring whatever `min_cards`/`rarity_limits`/`duplicate_limits`/
`even_color_distribution_rarities` the client also sent alongside it
(`GameService::resolveDuelDeckRules()`) -- `user_defined` is the only
preset where those client-supplied values are actually used, each
rarity's entry sanitized by `sanitizeRarityMap()`/`sanitizeRarityList()`
(coerced to int for the two maps; a blank/missing rarity is dropped
rather than treated as a literal cap of 0 or an enabled flag).

**Submission flow** -- `createGame()` only stores the resolved rules
(`games.custom_duel_min_cards`/`custom_duel_rarity_limits`/
`custom_duel_duplicate_limits`/`custom_duel_even_color_distribution_rarities`);
the game sits in `waiting` with neither
player's `game_players.custom_deck_card_ids` set yet. Each seated player
calls `POST /games/decklist` (`GameService::submitCustomDuelDeck()`) with
their own decklist text -- parsed via the same `DecklistParser` `custom`
uses, then validated against the stored `DuelDeckRules` -- and the
resolved name/card ids are written to that player's own `game_players`
row. Re-submitting before the game starts simply overwrites the previous
attempt (there's no reason to keep a superseded one around). `startGame()`
refuses (`GameStateException`) to deal for a `custom_duel` game until
*both* seats have a non-null `custom_deck_card_ids`
(`requireCustomDuelDecksSubmitted()`) -- when it does deal, each player's
own submitted cards are shuffled and dealt from independently, exactly
like every other duel deck_type's own per-player pool, just sourced from
that player's submission instead of a builder function.

**State exposure** -- `getState()`'s own `game.duel_deck_rules` (`null`
for every other deck_type) carries the resolved preset/min_cards/
rarity_limits/duplicate_limits so a client can render/validate against
them before submitting. Each entry in `players` carries that player's own
`custom_deck_name` and a `deck_submitted` boolean -- deliberately *not*
the decklist's own card ids or raw text, so a `custom_duel` game's waiting
room can show "Alice submitted, waiting on Bob" without leaking either
player's decklist contents to their opponent before the game starts.

### Open Team Play

`format: 'team'` seats exactly 4 players as two teams of two, sitting next
to their partner (`GameService::TEAM_PLAYER_COUNT`). The creator picks
their 3 opponents as usual, plus one `partner_user_id` from among them;
`seatOrderForTeamGame()` reorders the seating to
`[creator, partner, ...the other two]` so seat order alone determines
pairing, and `game_players.team_id` (`0`/`1`, provisioned back in
migration `0004` but unused until now) is assigned `seat_order >= 2 ? 1 :
0`. Teammates have "open information": each can see the other's hand
(`getState()`'s `you.teammate_game_player_id`/`you.teammate_hand`) as well
as their own, but still have separate hands/plays -- scoring is what
actually combines them (below). Hurt Feelings (the 3+ player
last-place-gets-an-extra-play mechanic) never applies in this format.
`45-card minimum` is enforced by restricting `deck_type` rather than a new
size check: `power` (15 cards) is rejected outright
(`GameService::MIN_TEAM_DECK_SIZE`); `structure` is exactly 45 cards
already, and `custom`'s own existing minimum formula
(`15 * (playerCount - 1)`) already comes out to 45 at 4 players, so
neither needed new code.

**Turn order** isn't a fixed seat rotation like every other format's own
`rotate($this->seatOrder(...), $round['first_game_player_id'])` -- each
round, turns 1 and 2 are each a team's own live choice of which member
goes, and turns 3 and 4 are forced (whichever teammate on each team
HASN'T gone yet this round, derived from `team_id` membership rather than
stored anywhere -- see `turnOrderForRound()`, the one shared helper used
everywhere turn order is needed, including the Enthusiasm/Passion
scoring-decision resume path that used to unconditionally call the old
seat-rotation logic even here). Which team goes first each round is
randomized for round 1 (`startGame()`) and is simply whichever team just
won the previous round afterward (or, on a tie, whichever team played
first -- see scoring below); `games_rounds.first_game_player_id` stores
one representative member of that team, not necessarily who actually ends
up taking turn 1.

**Propose/confirm** -- the rules call for "the two players of a team"
to jointly decide who goes (and, at round end, who gets the losing team's
shared draw), but the engine needs one definite answer. A new
`game_team_decisions` table (not the existing `game_pending_decision_batches`
machinery, which is tightly coupled to a card's own `played_card_id` and
has no notion of a round-start/round-end decision with no card behind it)
holds a `phase` of `'propose'` or `'confirm'`: either teammate calls
`POST /games/team-decision` with `action: 'propose'` to name a candidate,
then the OTHER teammate must `action: 'confirm'` with `approve: true`
(locks it in) or `false` (rejects, sending the row back to `'propose'`
with the prior proposal cleared -- either teammate, including the
original proposer, can propose again). The same `active_marker`
generated-column trick migration `0011` used for
`game_pending_decision_batches` guarantees at most one open
`game_team_decisions` row per round (`uq_team_decisions_one_open_per_round`),
and `activeTeamDecision()` looks it up per-*game* rather than per-round,
since a `draw_recipient` decision belongs to the round that just finished
scoring, not whatever round (if any) has been created since.

**Freezing** -- `current_turn_game_player_id` (nullable since migration
`0006`) is simply left/set `NULL` whenever no one has an actual turn to
take right now, reusing the exact same "frozen round blocks Play/Pass"
mechanism the engine already had for card-effect pending decisions --
`MoodPlayService::playMood()`/`pass()` needed zero changes. Team 2's own
turn_order decision is opened immediately once team 1's resolves
(`applyTurnOrderDecision()`), rather than waiting for team 1's chosen
player to actually play turn 1 first -- so team 2 is free to answer early.
If they do, resolving their decision must NOT hand them the turn
prematurely; only once team 1's player actually finishes turn 1 does
`advanceTeamTurn()` check whether team 2 already answered (unfreezing
straight to their choice) or still hasn't (freezing to wait for them) --
getting this backwards was a real bug caught during manual verification:
team 2 answering early used to silently clobber
`current_turn_game_player_id` to their own choice immediately, skipping
team 1's own turn 1 entirely.

**Scoring** -- every existing per-player card-effect mechanism
(`RoundScorer::score()`, Sneakiness's score swaps, Corruption's extra-win
marker, etc.) runs completely unchanged; team format only changes how the
resulting per-player scores are *interpreted* afterward
(`finishTeamScoringAndAdvance()`): each team's two members' scores are
added together, the higher team total wins the round (a tie goes to
whichever team played first this round), and the losing team gets a
single shared draw for the round -- represented as its own
`draw_recipient` team decision on the round that just scored, resolved
the same propose/confirm way, with the actual draw + the next round's own
`turn_order` decision deferred until it resolves
(`applyDrawRecipientDecision()`) so at most one `game_team_decisions` row
is ever open across the whole game at once. A card asking "did you win?"
(Bashfulness) means "did your team win" here --
`applyAfterScoringHooks()` was generalized from a single `int $winnerId`
to `array $winningGamePlayerIds` to cover this, non-team callers just pass
a 1-element array. Awe's "skip scoring, choose who goes first" effect has
its own separate code path (`skipScoringAndAdvance()`, bypassing
`finishScoringAndAdvance()` entirely) that needed its own team-aware
branch too, for the same reason.

**Card interactions** -- `BoardState::isTeammate(int $a, int $b): bool`
(always `false` for every non-team game, and for a player compared
against themselves) is the one new primitive the whole rules engine
needed for team format: an existing `$playerId !== $ownerId`-style
self-exclusion check just gets `&& !$state->isTeammate($ownerId,
$playerId)` added alongside it wherever a card's printed text singles out
an "opponent" specifically, since a teammate isn't one. `BoardStateRepository::load()`
populates it from `game_players.team_id` (empty map for every other
format). This isn't blanket "exclude teammates in team format" -- most
"choose a player"/"choose another player" cards never said "opponent" in
the first place and already included teammates as valid targets before
team format existed, so those needed no change at all:

- **Excludes a teammate** (the printed text says "opponent"):
  Animosity (a teammate's hand size never triggers its bonus value),
  Cruelty, Cynicism, Envy (a teammate is never the "moodiest opponent"),
  Generosity, Guile, Indecisiveness, Regret, and Sneakiness.
- **Already included a teammate, unchanged** (the printed text says
  "another player"/"any player", never "opponent"): Compulsion,
  Condescension, Fascination, Intimidation, and Malice (whose own
  printed text has no restriction at all -- it already permitted
  targeting yourself too, in every format).
- **Never needed a fix** for a different reason: Sloth and Grace already
  only ever look at whichever specific player's turn/hand is actually
  being evaluated (`BoardState::hand($ownerId)` /
  `sharesColorWithOwnMoods($cardId, $playerId)`), never "every other
  player," so a teammate's hand/moods were never counted even before team
  format existed. Stubbornness's own text says "if ANOTHER PLAYER has
  more moods than you" -- no "opponent" wording -- so a teammate's higher
  mood count correctly still grants its bonus, exactly as it always did.
- **Chivalry/Triumph** care whether the OWNER personally went first this
  round, not which team did -- a genuinely different bug, unrelated to
  `isTeammate()`. `game_rounds.first_game_player_id`, for a team game,
  only identifies a representative member of whichever team went first
  (see `startGame()`'s own comment above); the actual player who took
  turn 1 is `team_turn_1_game_player_id`. `BoardStateRepository::load()`
  feeds Chivalry/Triumph's `roundFirstPlayerId()` from
  `team_turn_1_game_player_id` once it's known (falling back to
  `first_game_player_id` only for the brief window before either team has
  decided anything, when nothing can be in play yet regardless). Getting
  this wrong was a real bug caught live: a Chivalry owned by the round's
  team-0 representative read as "you went first" (base value) even when
  their own teammate -- not them -- was the one who'd actually taken
  turn 1.

Every one of the exclusions above (and Chivalry/Triumph's own fix) has
PHPUnit coverage in `MoodPlayServiceTest`/`GameServiceIntegrationTest`.
The player/mood-target cards that exclude a teammate also carry
`excludes_teammate: true` on their own `choice_fields` entry (see
`CardChoiceSchema.php`'s own docblock) so the New Game board's dropdown
never even offers the teammate as a choice, rather than only rejecting it
server-side once submitted.

**Winner display** -- `getState()`'s `game.winner_usernames` (an array)
replaces the old single `winner_username`: for a team-format win it holds
BOTH teammates on the winning team (looked up by `winner_team_id`, not
just the single representative `winner_game_player_id` that
`finishTeamScoringAndAdvance()` still stores for FK/internal purposes),
so the frontend's "Game over" banner credits the whole winning team
(`teamalice & teambob won`) rather than crediting just whichever teammate
happened to score higher that round. Non-team games fall back to the
single winner's username, same as before.

**Team-decision wording for the non-deciding team** -- `getState()`'s
`team_decision` is the same object for every viewer in the game,
including the team that ISN'T making the decision (its `can_propose`/
`can_confirm` are simply both `false` for them). `game.js`'s
`renderTeamDecision()` used to always say "Your team's turn" and "Waiting
for your teammate to confirm..." regardless of whether the viewer was
actually on the deciding team, which read as flatly wrong (and
confusing) from the other team's side. It now compares `decision.team_id`
against the viewer's own `team_id` (from `state.players`) and shows
neutral, correctly-attributed wording ("Opposing team's turn", "Waiting
for teamdave's team to confirm...") when the viewer isn't a candidate on
that decision.

**Players list "went first this round" badge** -- had the same
representative-vs-actual-player confusion as the Chivalry/Triumph bug
above: it used to key off `round.first_game_player_id`, which for a team
game only identifies a representative member of whichever TEAM went
first, so the badge could land on either teammate rather than the one who
actually took turn 1. `getState()`'s `round` now also exposes
`went_first_game_player_id` (`BoardState::roundFirstPlayerId()` --
the same value Chivalry/Triumph already keyed off of, so it also already
accounts for an Honor override) and the frontend badge uses that instead.

### Closed Team Play

`format: 'closed_team'` (issue #87) is Open Team Play's sibling variant --
the same 4-player 2v2 structure, sharing most of its schema
(`game_players.team_id`, `winner_team_id`, the `game_team_decisions`
table) and every card-effect exclusion (`BoardState::isTeammate()` is
format-agnostic -- it only ever compares `team_id`, never seat adjacency,
so all 9 teammate-excluding cards and the Chivalry/Triumph fix already
work correctly here with zero changes). It differs in four concrete ways:

1. **Seating** -- partners sit ACROSS the table (`seatOrderForClosedTeamGame()`:
   creator seat 0, one opponent seat 1, the chosen partner seat 2, the
   last opponent seat 3, `team_id = seat_order % 2`) rather than Open Team
   Play's adjacent seats 0/1 vs. 2/3. This is the one piece that makes
   everything else so much simpler: a plain clockwise seat rotation
   already alternates between teams on its own, so this format needs NONE
   of Open Team Play's `team_turn_1/2_game_player_id` machinery or
   `advanceTeamTurn()`'s forced-turn logic -- `advanceTurn()`'s ordinary
   non-`'team'` branch (`rotate($this->seatOrder($gameId),
   $round['first_game_player_id'])`) already does the right thing
   unmodified, PROVIDED `first_game_player_id` is kept accurate (see
   "Turn order" below).
2. **Turn order** -- round 1's leader is simply randomized
   (`startGame()`'s own uniform `array_rand()` pick, same primitive every
   non-team format already uses -- no `game_team_decisions` row exists for
   round 1 at all). From round 2 onward, the winning team gets exactly
   ONE live choice (who leads), reusing `game_team_decisions`'
   `'turn_order'` propose/confirm machinery -- but resolved by
   `applyClosedTeamLeaderDecision()` rather than Open Team Play's
   `applyTurnOrderDecision()`: it writes the chosen player straight into
   `game_rounds.first_game_player_id` (no `team_turn_1/2` columns exist
   for this format) and unfreezes the round immediately, never opening a
   second decision. `confirmTeamDecision()` picks between the two handlers
   based on the game's own `format` whenever `decision_type` is
   `'turn_order'`.
3. **Pregame card pass** -- this format's own mechanic with no Open Team
   Play analog: after everyone's dealt their 5-card starting hand, every
   player must pass exactly 2 cards to their teammate, face down, BEFORE
   seeing what their own teammate passed them. `POST /games/initial-pass`
   -> `GameService::submitInitialCardPass()` inserts the caller's own row
   into the new `game_initial_card_passes` table (migration `0023`) --
   locking their choice in immediately, which is what actually makes the
   exchange blind, since it can never be revised once their teammate's
   hand becomes visible to them. The moment BOTH members of a team have a
   row, that team's own actual transfer applies right then (a plain
   `owner_game_player_id` reassignment on the 4 `game_cards` rows
   involved -- independent of the other team's own pace); only once ALL 4
   players have submitted does round 1's already-randomly-chosen leader
   (from point 2 above) actually get unfrozen. `getState()`'s
   `initial_card_pass` (`{you_submitted, submitted_game_player_ids}`,
   `null` once everyone's done) lets the frontend show "choose 2 cards" or
   "waiting for X, Y" without ever revealing which 2 cards anyone chose.
4. **Information stays closed** -- unlike Open Team Play, `getState()`
   never populates `you.teammate_hand` for this format (only
   `you.teammate_game_player_id`, so the UI can still label who your
   partner is without exposing their hand). The `teammate-hand-section`
   in `web-static/game/index.html` simply never gets data to render for
   `closed_team`, so no extra guard was needed there.

Everything else -- team-aggregated scoring (`finishTeamScoringAndAdvance()`,
already `team_id`-only and reused verbatim once its own format check
widened to cover both formats), ties going to whichever team played first,
the losing team's single shared draw (`applyDrawRecipientDecision()`,
also reused verbatim), `winner_usernames` crediting both teammates, and
the team-decision panel's viewer-aware "Your team's turn"/"Opposing
team's turn" wording -- is exactly the same code Open Team Play already
uses, gated by a shared `GameService::isTeamFormat($format)` predicate
(`$format === 'team' || $format === 'closed_team'`) wherever the two
formats' behavior is identical, rather than a second parallel
implementation.

### Game timestamps

`games` tracks four points in a game's life, each set exactly once by a
single well-defined transition rather than inferred after the fact:
`created_at` (the row's own default, `createGame()`), `started_at`
(`startGame()`, once hands are dealt and round 1 begins), `last_move_at`
(`touchLastMoveAt()`, after every successful `playMood()`/`pass()`/
`respondToDecision()` call -- see that method's own docblock for why it
wraps the whole call rather than threading through every nested private
method those three delegate to, and why a request that throws before
completing never counts as a move), and `completed_at` (alongside
`winner_game_player_id`, once a player reaches `wins_needed`). All four are
`NULL` until their own transition happens, and `listGamesForUser()` is
`SELECT *`-backed (`fetchGame()`), so any of them being unset for a given
game (e.g. `last_move_at` on a `waiting` game nothing has happened in yet)
is expected, not a bug. `last_move_at` is also what the lobby list itself
sorts by within its two status tiers -- see `GET /games` in the API table
above.

### Duel: separate per-player decks

`format: 'duel'` is the one physical rules difference `format` actually
makes (every other format value is cosmetic, just echoed back and
displayed as a label). In a duel, each of the game's exactly-2 players
draws from -- and bottoms cards onto -- their *own* deck rather than a
single shared one; `createGame()` rejects any `duel` game that doesn't
seat exactly 2 total players (`GameStateException`, "A duel game must have
exactly 2 players") since the split-deck model below only makes sense for
two.

- `BoardState` generalizes its single flat deck into `array<int, int[]>
  $decks` keyed by a "deck key": either `BoardState::SHARED_DECK_KEY` (the
  common case for every non-duel format -- a sentinel `0`, safe because
  `game_players.id` auto-increments from 1) or each seated player's own
  `game_player_id` for a duel. A `hasSeparateDecks` constructor flag picks
  which; `deck(?int $playerId = null)` takes an optional viewer id --
  omitting it works for shared-deck games (any id resolves to the same
  pool) but throws if omitted for a duel, since there's no single "the
  deck" to hand back without knowing whose. `drawCard($playerId)` always
  pulls from that specific player's own deck in a duel, so a player can
  never draw from -- or exhaust -- their opponent's.
- Cards that go to the bottom of a deck always go to their *owner's* deck,
  not the acting player's: `moveHandToBottomOfDeck($playerId, $cardId)`
  bottoms into that player's own deck (the hand it came from);
  `moveInPlayToBottomOfDeck($cardId)` bottoms into the in-play mood's
  *current* owner's deck (Conviction, Hate); `moveDiscardToBottomOfDeck($cardId)`
  bottoms into the discarded card's *original* owner's deck (Altruism,
  Corruption), tracked via a `$discardOwners` map (`cardId => last-known
  owner`) populated whenever a card enters the discard pile and cleared the
  moment it leaves, however it leaves. The discard pile itself stays a
  single shared, unordered pool in every format, duel included -- only the
  *routing* of a card bottomed *from* it is per-owner, not the pile's
  contents or visibility. None of the 8 effect classes that call these
  methods (Altruism, Conviction, Corruption, Doubt, Hate, Paranoia,
  Rationalization, Zeal) needed any change -- every call site already
  passed exactly the information `BoardState` needs to route correctly.
- `startGame()` gives each duel player their own *complete* deck, built and
  shuffled completely independently -- `deckCardIdsFor()`'s own dispatch
  (`buildStructureDeckCardIds()`, `buildPowerDeckCardIds()`, or
  `range(1, TOTAL_CARDS)` for `'one_of_each'` -- see "Deck types" below), the
  exact same one a single-player game uses, called once per player rather
  than once for the whole table -- with each player's starting hand dealt
  from their own pool, not a shared one. This means the *same* catalog card
  can legitimately end up in both players' pools at once (certain for
  `'one_of_each'`, likely for `'structure'`/`'power'`) -- see "Card
  identity: catalog id vs. per-game instance id" below for how the engine
  tells two such cards apart.
- Persistence reuses `game_cards.owner_game_player_id` (already nullable,
  already present) for both zones: `null` for a shared deck/discard row,
  the owning player's `game_player_id` for a duel deck row or any
  known-owner discard row. `BoardStateRepository::load()` looks up the
  game's `format` up front to decide whether to bucket loaded `deck` rows
  by owner or into one shared pool -- deliberately not inferred from the
  rows themselves, since an empty deck at some point in the game would
  make that inference ambiguous.
- `GameService::getState()`'s `deck_count` is the *viewing* player's own
  deck size in a duel (it differs per player) and the shared pool's size
  otherwise, unchanged from before.

### Card identity: catalog id vs. per-game instance id

Every other game format keeps `cards.id` (1-133, "the catalog id") unique
per game -- a shared or split-shared deck can never contain the same
printed card twice, so catalog id alone was always enough to identify a
specific physical card within a game. Duel's independent-per-player decks
break that: since each player draws from their *own* full pool, the same
catalog card can now exist twice in one game simultaneously (one per
player), and `game_cards` no longer enforces otherwise (its old `UNIQUE KEY
uq_game_cards_card (game_id, card_id)` was dropped in migration `0013` in
favor of a plain index).

Card identity throughout the whole system -- `BoardState`'s hands/decks/
discard pile/in-play zone, every choice a player submits (`hand_card_id`,
`target_mood_id`, `discard_card_ids`, Creativity's `copy_card_id`, etc.),
and every `card_id` field the API returns -- is therefore `game_cards.id`
(the row's own surrogate primary key, which already existed solely to
resolve `suppression_source_game_card_id`'s self-reference before this),
not the catalog id. `copied_card_id` (Creativity "playing as a copy of a
mood currently in play") is a per-game instance id for the same reason --
it names a specific physical card on the board, not a printed card in the
abstract.

`BoardState::catalogRow(int $cardId)` is the *only* place in the whole
Rules engine that ever reads catalog data (name/color/base value/rules
text) directly -- no `Effects/*.php` class touches a catalog array itself,
every one of them goes through `catalogRow()`/`valueOf()`/`colorOf()` --
which is what let this become a one-method change: a new `$catalogCardIdFor`
constructor param (`array<int, int>`, instance id => catalog id) that
`catalogRow()` consults, falling back to treating `$cardId` as already
being a catalog id when no mapping entry exists. That fallback means every
game/test where instance and catalog id never diverge (i.e. everything
except a duel with a genuinely duplicated card) needs no mapping at all --
confirmed by the ~350 pure in-memory Rules-layer tests, none of which
supply `$catalogCardIdFor`, all of which kept passing unmodified.
`BoardStateRepository::load()` builds the real mapping for a live game from
each loaded `game_cards` row's own `id`/`card_id` pair.

`BoardState::catalogCardId(int $cardId): int` exposes that same resolution
publicly (`catalogRow()` itself only returns the catalog *row*, not the id
it resolved to) -- used by `GameService::serializeCard()` to add a
`catalog_card_id` field to every serialized card, alongside the existing
instance-id `card_id`. This is the one place the API surface needs a real
catalog id: card art is keyed by `cards.id`, not by the per-game instance
id (see "Assets" in `web-static/README.md`), so the frontend builds each
card's art URL from `catalog_card_id` + a client-side slugification of
`name`. For a Creativity copy, `catalog_card_id` resolves to the *copied*
card's catalog id, matching `name`/`rules_text`'s own switch, so the art
shown always matches whatever mood is actually being displayed.

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

`MaintenanceGateTest` (see "Maintenance mode" above) follows the same
pattern, but drops/recreates `schema_version` itself in `setUp()`/`tearDown()`
rather than assuming it's already present, since "the table doesn't exist"
is itself one of the states under test. Its `testActiveMessageReadsTheRealVersionFile`
case exercises the real `deployedVersion()`/`activeMessage()` path against
whatever `VERSION` file is actually on disk, rather than only the
injected-string `check()` path — the two resolve `VERSION`'s location
differently (see `MaintenanceGate`'s docblock), so this is the one test
that would have caught the path-resolution bug an earlier draft of that
class had.

The test suite truncates `users`/`sessions`/`email_verifications`/
`friendships` in that database before each test, so never point it at a
database with real data.
