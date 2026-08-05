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
instead. Every route except `/health` and `/migrate` can also return `503`
with `{"status": "maintenance", "message"}` (or, for `/verify-email`, an
HTML maintenance page) — see "Maintenance mode" below.

| Method | Path            | Body                                                          | Notes |
| ------ | --------------- | -------------------------------------------------------------- | ----- |
| GET    | `/health`       | —                                                                | Checks DB connectivity. Exempt from maintenance mode (see below) — always reflects real DB health, never `503` for a pending migration. |
| POST   | `/migrate`      | —                                                                 | Requires an `X-Migration-Key` header matching the `MIGRATION_DEPLOY_KEY` secret (compared with `hash_equals()`); `403` if it's missing/wrong or the secret itself isn't configured. Applies any pending `database/migrations/*.sql` files via `MigrationRunner::applyPending()` -- called by `.github/workflows/deploy.yml`/`deploy-dev.yml` right after each deploy's file upload, since production/dev have no shell access of their own to run `bin/migrate.php` directly. Exempt from maintenance mode (see below), same reasoning as `/health`. Returns `{"applied": [string]}` (filenames actually applied this run, `[]` if already up to date); `500` with the underlying error message if a migration fails partway through. See "Auto-applying migrations on deploy" in `database/README.md`. |
| POST   | `/register`     | `{"username", "email", "password", "phone_number"?}`             | Creates an unverified user and emails a verification link. Username: 3-32 chars (letters/numbers/`_`/`-`); email: valid format; password: 8-72 chars; phone (optional): 7-20 chars, digits/`+`/`-`/`.`/spaces/parens. `409` on duplicate username/email, `400` on validation failure, `502` if the verification email can't be sent (registration is rolled back so you can retry). |
| GET    | `/verify-email` | query param `token`                                              | HTML page (not JSON). On success, auto-redirects to `/` after 5 seconds (plus a manual link). `400` with just a manual link (no auto-redirect) if the token is invalid/expired. |
| POST   | `/resend-verification` | `{"email"}`                                                | Issues a fresh verification link, revoking any prior one, and emails it. Always returns the same generic `200` message regardless of whether the email exists, is already verified, or was rate-limited, so it can't be used to discover which addresses are registered. Limited to once per 60 seconds per account; `400` on invalid email format, `502` if sending fails. |
| POST   | `/forgot-password` | `{"email"}`                                                   | Issues a password reset link (valid for 1 hour), revoking any prior one, and emails it -- regardless of the account's verification status. Always returns the same generic `200` message whether or not the email is registered, for the same enumeration-resistance reason as `/resend-verification`; also rate-limited to once per 60 seconds per account. `400` on invalid email format, `502` if sending fails. Unlike `/verify-email`, the emailed link points at the static `reset-password.html` page rather than a GET route here -- see "Password reset" below for why. |
| POST   | `/reset-password` | `{"token", "password"}`                                        | Consumes a password reset token (single-use, same replay-proofing as Discord's OAuth state) and sets the new password (8-72 chars, same rule as registration). Also deletes every one of the account's sessions, logging it out everywhere -- a reset is treated as a signal any existing session may be compromised. `400` if the token is invalid/expired/already used or the password fails validation. See "Password reset" below. |
| POST   | `/login`        | `{"username", "password"}`                                       | `401` on bad credentials, `403` if the email isn't verified yet. |
| POST   | `/logout`       | —                                                                 | Invalidates the current session only (other logged-in devices/sessions are unaffected). |
| GET    | `/me`           | —                                                                 | Returns the current user if authenticated, `401` otherwise. Now includes `share_presence` (issue #110) -- your own current opt-in/out of sharing your online/offline status with others; see "Online/presence indicator" below. |
| GET    | `/friends`      | —                                                                 | Requires auth. Lists accepted friends (`friend_id`, `friend_username`, `created_at`, `presence` -- `'online'`/`'offline'`/`'hidden'`, see "Online/presence indicator" below). |
| GET    | `/friends/invites` | —                                                              | Requires auth. Returns `{"incoming": [...], "outgoing": [...]}`, each entry has `other_user_id`/`other_username`/`created_at`. |
| POST   | `/friends/invite` | `{"username_or_email"}`                                        | Requires auth. Sends a friend request; looks up the target by username first, then email. `404` if no such user, `409` if you already have a request/friendship/block with them (or if you invite yourself) — the message is deliberately generic when they've blocked you, so you aren't told that specifically. |
| POST   | `/friends/respond` | `{"user_id", "action"}`                                        | Requires auth. `action` is `accept`, `decline`, or `block`, responding to the pending invite from `user_id`. Declining just removes the request (not punitive — they can invite you again); blocking permanently prevents future invites from that user. `403` if you try to respond to your own outgoing invite, `404` if there's no such pending invite, `400` for an invalid `action`. |
| POST   | `/friends/remove` | `{"user_id"}`                                                  | Requires auth. Ends an existing (accepted) friendship — either side can do this, and it isn't punitive either (they can send a new request afterward). `404` if you're not currently friends with that user. |
| POST   | `/games`        | `{"opponent_user_ids": [int], "format"?, "wins_needed"?, "deck_type"?, "decklist_text"?, "saved_decklist_id"?, "duel_deck_rules"?, "partner_user_id"?, "quick_draft_pool_source"?, "quick_draft_custom_pool_text"?, "winston_draft_pool_source"?, "winston_draft_custom_pool_text"?, "grid_draft_pool_source"?, "grid_draft_custom_pool_text"?}` | Requires auth. Creates a game seating you plus `opponent_user_ids` (2-4 players total, `format` defaults to `standard` -- one of `standard`/`duel`/`draft`/`team`/`closed_team` -- `wins_needed` defaults to `3`, `deck_type` defaults to `structure` -- one of `structure`/`power`/`jceddys_75`/`custom`/`custom_duel`/`quick_draft`/`winston_draft`/`grid_draft`/`one_of_each`, see below). For `deck_type` `custom`, either `decklist_text` or `saved_decklist_id` is required (the latter loads one of your own or a friend's shared saved decklists instead of parsing text -- see "Saved decklists" below) and both are ignored otherwise. `duel_deck_rules` (`{"preset"?, "min_cards"?, "rarity_limits"?, "duplicate_limits"?, "even_color_distribution_rarities"?}`) is required when `deck_type` is `custom_duel` (see "Custom decklists for Duel games" below) and ignored otherwise. `partner_user_id` is required when `format` is `team` or `closed_team` (one of `opponent_user_ids` -- seated adjacent for `team`, across the table for `closed_team`, see "Open Team Play"/"Closed Team Play" below) and ignored otherwise. `quick_draft_pool_source` (one of `random_48`/`structure`/`jceddys_75`/`one_of_each`/`custom`/`saved_deck`) is required when `deck_type` is `quick_draft`, and `quick_draft_custom_pool_text` is required when that source is `custom` (see "Quick Draft" below) -- both ignored otherwise; when the source is `saved_deck` instead (issue #290), `saved_decklist_id` (the same field `custom` uses) supplies the decklist and `quick_draft_custom_pool_text` is ignored. `winston_draft_pool_source`/`winston_draft_custom_pool_text` are the same pool-source options, required/ignored under the same rules but for `deck_type: 'winston_draft'` (see "Winston Draft" below). `grid_draft_pool_source`/`grid_draft_custom_pool_text` are the same idea for `deck_type: 'grid_draft'`, except `'structure'` isn't a valid choice there (see "Grid Draft" below). `400` if that's more than 4 players or an opponent id doesn't exist, a `duel` game doesn't seat *exactly* 2 players total or a `draft` game doesn't seat 2-4 players total (see "Quick Draft"'s own "Multiplayer" section below, and "Winston Draft"/"Grid Draft" below for those two formats' own multiplayer sections), a `team`/`closed_team` game doesn't seat *exactly* 4 players total or `partner_user_id` is missing/not one of `opponent_user_ids`, `deck_type` is `custom` with `format: 'duel'`, `deck_type` is `custom_duel` with any `format` other than `'duel'`, `format` is `'draft'` with any `deck_type` other than `quick_draft`/`winston_draft`/`grid_draft`, `deck_type` is `quick_draft`/`winston_draft`/`grid_draft` with any `format` other than `'draft'`, `deck_type` is `power` with `format: 'team'`/`'closed_team'` (see "Open Team Play"/"Closed Team Play" below), the decklist/pool itself is invalid (unparseable line, unrecognized card name, too few cards, or -- for `grid_draft` specifically -- a pool source that comes up short of the player count's own target size), or `duel_deck_rules` is missing/invalid (`min_cards` below 7 for a `user_defined` preset); `404`/`403` if `saved_decklist_id` doesn't exist or you can't access it (not yours, not shared with you). Returns `{"game_id"}`. |
| POST   | `/games/decklist` | `{"game_id", "decklist_text"?, "saved_decklist_id"?}`           | Requires auth; `403` if you're not seated in that game. A `custom_duel` game's own two players each call this -- while the game is still `waiting` -- to submit their own decklist, either as pasted/uploaded text or by referencing one of their own or a friend's shared saved decklists (see "Saved decklists" below), validated against the game's own deck-building rules. `400` if the game isn't `custom_duel`, isn't `waiting`, or the decklist violates a rule (too few cards, a rarity/duplicate cap exceeded); `404`/`403` if `saved_decklist_id` doesn't exist or you can't access it. Re-submitting overwrites the previous attempt. See "Custom decklists for Duel games" below. |
| GET    | `/cards/catalog` | —                                                                | Requires auth. Every printed card, hydrated the same way `/decklists/view` hydrates a saved decklist's cards (now including `rarity`, which no other card-view route needed until this one). Not scoped to a game/decklist -- the catalog itself is public knowledge, same reasoning as `/games/log`. Returns `{"cards": [...]}`. Powers the deck builder's (issue #93) own catalog-browsing panel -- see "Deck builder" below. |
| GET    | `/decklists`    | —                                                                 | Requires auth. Returns `{"own": [...], "friends": [{"friend_id", "friend_username", "decklists": [...]}]}` -- summaries only (`id`/`name`/`card_count`/`sideboard_card_count`/`visibility`/`created_at`/`updated_at`, never card contents). `friends` only lists friends who have 1+ decks shared with you. See "Saved decklists" below. |
| GET    | `/decklists/view` | query param `id`                                               | Requires auth. Returns `{"decklist": {"id", "name", "visibility", "owner_user_id", "cards": [...], "sideboard_cards": [...]}}` with full hydrated card details. `404` if no such decklist, `403` if it's private and you're not the owner (or `friends`-visible and you're not an accepted friend of the owner). |
| POST   | `/decklists`    | `{"name", "decklist_text"?, "card_ids"?, "sideboard_card_ids"?, "visibility"?}` | Requires auth. Saves a new decklist under your account -- either `decklist_text` (parsed the same way as "Custom decklists" below, capturing an optional Sideboard section too) or already-resolved `card_ids`/`sideboard_card_ids` (used by the draft formats' own "Save deck" button, see "Quick Draft" etc.). `visibility` is `private` (default) or `friends`. `400` if the name is blank, no cards were given, a card id doesn't exist, or `visibility` is invalid. Returns `{"decklist_id"}`. |
| POST   | `/decklists/update` | `{"id", "name", "decklist_text"?, "card_ids"?, "sideboard_card_ids"?, "visibility"?}` | Requires auth. Overwrites an existing decklist's name/contents/visibility in place (no versioning). `404` if no such decklist, `403` if you don't own it, `400` for the same validation as create. |
| POST   | `/decklists/delete` | `{"id"}`                                                      | Requires auth. Permanently deletes one of your own saved decklists. `404` if no such decklist, `403` if you don't own it. |
| POST   | `/games/draft/pick` | `{"game_id", "round", "stage": int, "card_ids": [int, int]}` | Requires auth; `403` if you're not seated in that game. A `quick_draft` match's own per-round blind pick -- `stage` is an integer from 1 to the match's own player count (2 for a 2-player match, same as the old `'draw'`/`'received'` string enum; up to 4 for a multiplayer match, issue #189), identifying which of the round's per-player piles you're currently holding (server-derived from seat rotation, not something the client picks). `409` if the game isn't `quick_draft`, the match isn't currently drafting, `round` isn't the match's current round, `stage` is out of range, `card_ids` isn't exactly 2 cards you're actually eligible to keep for that stage, you've already submitted that stage, or the previous stage isn't yet complete for every seated player. See "Quick Draft" below. Returns `{"stage_completed", "round_advanced", "draft_completed"}`. |
| POST   | `/games/draft/winston-pick` | `{"game_id", "action": "take"\|"pass"}`                  | Requires auth; `403` if you're not seated in that game. A `winston_draft` match's own pile take/pass -- no `card_ids`, since a pile is taken/passed as a whole and the server already knows whose turn it is and which pile is current. `409` if the game isn't `winston_draft`, the match isn't currently drafting, it isn't your turn, or `action` isn't `take`/`pass`. See "Winston Draft" below. Returns `{"action_completed", "turn_advanced", "draft_completed"}`. |
| POST   | `/games/draft/grid-pick` | `{"game_id", "axis": "row"\|"column", "index": int}`        | Requires auth; `403` if you're not seated in that game. A `grid_draft` match's own row/column pick against the current grid -- 3x3 (`index` 0-2) for 2-3 players, 4x4 (`index` 0-3) for exactly 4 players. `409` if the game isn't `grid_draft`, the match isn't currently drafting, it isn't your turn, `axis`/`index` are invalid, or the chosen line has no cards left. See "Grid Draft" below. Returns `{"axis", "index", "cards_taken": [int], "round_completed", "turn_advanced", "draft_completed"}`. |
| POST   | `/games/draft/deck` | `{"game_id", "card_ids": [int]}`                             | Requires auth; `403` if you're not seated in that game. A `quick_draft`/`winston_draft`/`grid_draft` match's own deck trim/sideboard -- used both for the initial trim and every later sideboard between the match's games. `409` if the game isn't `quick_draft`/`winston_draft`/`grid_draft`, the match isn't currently `deck_building`, `card_ids` isn't within that format's min/max size (at least 12, at most however many you actually drafted -- 16 for `quick_draft` at 2/4 players, 18 at 3, since issue #189; varies by how the draft unfolded for `winston_draft`/`grid_draft`) or drawn from your own `drafted_card_ids`. See "Quick Draft"/"Winston Draft"/"Grid Draft" below. |
| POST   | `/games/draft/first-player-choice` | `{"game_id", "play_first": bool}`              | Requires auth; `403` if you're not seated in that game. Only callable once a best-of-three draft match's game 2/3 has actually started -- the loser of the previous game doesn't have to decide who goes first until they can see their own opening hand, and round 1 stays frozen (nobody can play/pass) until they do. Lets them go first themselves (`play_first: true`) or leave the previous winner going first again (`play_first: false`); either answer permanently unfreezes the round. `409` if the game isn't `quick_draft`/`winston_draft`/`grid_draft`, hasn't started yet, is game 1 of its match (nothing to base the choice on), the calling user wasn't the previous game's loser, or the decision was already made. See "Quick Draft"/"Winston Draft"/"Grid Draft" below. |
| POST   | `/games/team-decision` | `{"game_id", "action", ...}`                              | Requires auth; `403` if you're not seated in that game; `409` if the game isn't `team`/`closed_team` format or has no open team decision. `action: 'propose'` takes `{"proposed_game_player_id"}` (any candidate teammate may propose); `action: 'confirm'` takes `{"approve": bool}` (the OTHER teammate approves or rejects the pending proposal). See "Open Team Play"/"Closed Team Play" below. Same return shape as `/games/play` once a proposal is confirmed; otherwise `{"round_scored": false, "game_completed": false}` (propose, or a rejected confirm sent back to 'propose'). |
| POST   | `/games/initial-pass` | `{"game_id", "card_ids": [int, int]}`                        | Requires auth; `403` if you're not seated in that game; `409` if the game isn't `closed_team`, `card_ids` isn't exactly 2 distinct cards currently in your hand, or you've already submitted your pass this game. `closed_team`'s own pregame mechanic -- see "Closed Team Play" below. Returns `{"round_scored": false, "game_completed": false, "pending_decision": bool}` (`pending_decision` is `true` until all 4 players have submitted). |
| GET    | `/games`        | —                                                                 | Requires auth. Lists games you're seated in that still belong in the main lobby -- every `waiting`/`in_progress` game, plus a `completed`/`abandoned` one ONLY if it's still part of a best-of-three draft match (`quick_draft`/`winston_draft`/`grid_draft`) that isn't itself fully decided yet (see "Past games" below); every other `completed`/`abandoned` game has moved to `GET /games/past` instead. `waiting`/`in_progress` games always sort above still-current-`completed`/`abandoned` ones regardless of recency, most-recently-active first within each of those two tiers -- each with `players` (`user_id`/`username`/`seat_order`), `is_your_turn`, `is_awaiting_your_response` (a delayed choice is on you specifically -- a Compulsion-style pending decision targeting you, your team's own turn_order/draw_recipient decision needing your propose/confirm, `closed_team`'s still-unsubmitted pregame card pass, or -- for a best-of-three draft match's game 2/3 -- being the previous game's loser while round 1 is still frozen awaiting your own `setPlayFirstNextMatchGame()` call; see `isAwaitingResponseFrom()`/`isAwaitingFirstPlayerChoiceFrom()` -- unlike `is_your_turn`, none of these require it to actually be your own turn), `current_turn_username` (whichever seated player `current_turn_game_player_id` actually belongs to, by username -- null whenever the game isn't `in_progress` or the round is between turns, e.g. an Open Team Play `turn_order` decision still open), `awaiting_response_usernames` (the generalized, all-players version of `is_awaiting_your_response` -- every seated player `isAwaitingResponseFrom()` currently returns `true` for, which can be more than one at once, e.g. `closed_team`'s pregame card pass before every player has submitted; for a still-`waiting` `quick_draft`/`winston_draft`/`grid_draft` game, both `current_turn_username`/`is_your_turn`/`is_awaiting_your_response` stay at their game-less-in-progress defaults but `awaiting_response_usernames` is instead populated by `draftAwaitingResponseUsernames()` -- both players at once for quick_draft's own simultaneous-blind draw/received pick stages until each has submitted, or exactly whoever's turn it currently is for winston_draft's/grid_draft's single active turn player, or whoever hasn't yet submitted a deck once the match reaches `deck_building`), `winner_usernames` (empty until the game actually completes; both teammates' for a team-format win, same "credit the whole winning team" logic `GET /games/state`'s own field of the same name uses), and all four of `created_at`/`started_at`/`last_move_at`/`completed_at` (see "Game timestamps" below). `quick_draft`/`winston_draft`/`grid_draft` games additionally carry `draft_match_id`, `match_game_number`, and `draft_match` (`{"status", "your_wins", "opponent_wins", "games_to_win", "winner_username", "players"}`, `winner_username` only set once the match's own status is `completed`, `players` -- issue #189 -- every seated player's own `user_id`/`username`/`wins`/`is_you`, the field a 3-4 player Quick Draft match's own scoreline should actually be read from since `your_wins`/`opponent_wins` only ever reflect the first non-viewer seat) -- all three `null` for every other `deck_type`. The lobby UI uses these to group a match's up-to-3 games together and show the match's own result once it's decided; see "Quick Draft"/"Winston Draft"/"Grid Draft" below. |
| GET    | `/games/past`   | —                                                                 | Requires auth. The complement of `GET /games` above: every `completed`/`abandoned` game NOT still tied to an undecided draft match -- i.e. exactly the games `GET /games` excludes. Same row shape as `GET /games` (`GameService::gameSummaryFor()` hydrates both), sorted most-recently-completed first rather than by actionability, since nothing here is actionable. See "Past games" below. |
| GET    | `/games/state`  | query param `game_id`                                            | Requires auth; `403` if you're not seated in that game. Full board view: `game`, `players` (with `hand_count`/`total_wins`/`team_id`/`presence` -- `'online'`/`'offline'`/`'hidden'`, see "Online/presence indicator" below -- per seat), `you` (your `game_player_id`, and — once started — your full `hand`), `round` (turn/plays-remaining/banned-colors/`pending_decision`/etc., `null` before the game starts), `in_play`, `discard_pile`, and `deck_count` (never the deck's order). Every serialized card also carries `choice_fields` — see below. `team`/`closed_team` format games additionally get `teams` and `team_decision` (both `null` otherwise) and `you.teammate_game_player_id` -- see "Open Team Play"/"Closed Team Play" below. `you.teammate_hand` is only ever populated for `team` (Open Team Play's own "open information" premise); `closed_team` games additionally get `initial_card_pass` (`null` once every player has submitted their pregame card pass). `chat_messages` (issue #109) is the game's full in-game chat history so far, oldest first -- omitted entirely for a still-`waiting` game, the same early return that keeps `round`/`in_play`/etc. absent then too (chat only ever gets appended to via `POST /games/chat` once `in_progress`, see "In-game chat" below). `quick_draft` games additionally get `game.match_game_number` and a `quick_draft` field (both `null` for every other deck_type, and populated regardless of `game.status` -- see "Quick Draft" below); `winston_draft`/`grid_draft` games likewise get `game.match_game_number` and a `winston_draft`/`grid_draft` field -- see "Winston Draft"/"Grid Draft" below. |
| GET    | `/games/log`    | query params `game_id`, `code`?                                   | Requires auth; `403` unless you're seated in that game OR authorized to spectate it (issue #128 -- same `canSpectateGame()` check `GET /games/spectate/state`/`GET /games/deck` use). The entire `game_events` log for this game, oldest first, unbounded (issue #98) -- unlike `/games/state`'s own `recent_events`, which is newest-first and capped at 15. Each entry is `{"id", "created_at", "round_number", "event_type", "acting_game_player_id", "acting_username", "card_id", "card_name", "details", "description"}` -- `description` is the same `describeEvent()`-rendered text `recent_events` itself uses; the rest is raw enough for a genuine offline export (see "Game log" below). No per-viewer filtering -- every event is already visible to every seated player (and now every spectator) regardless of who triggered it. See `GameService::fullEventLog()`. |
| GET    | `/games/deck`   | query params `game_id`, `code`?                                   | Requires auth; `403` unless you're seated in that game OR authorized to spectate it (issue #128 -- friends with a seated player, or `code` matches the game's own spectate code; same `canSpectateGame()` check `GET /games/spectate/state` uses). A shared-deck game's entire deck (issue #197) -- every `deck_type` except `custom_duel`/`quick_draft`/`winston_draft`/`grid_draft`, where each player has their own deck rather than one shared pool (see `GameService::isSharedDeckType()`). Returns `{"cards": [...]}`, hydrated the same way `/decklists/view` hydrates a saved decklist's cards, sorted white/blue/black/red/green then alphabetically by name within a color. `409` if the game's `deck_type` has no single shared deck, or the game is still `waiting` (nothing dealt yet). See "Shared deck view" below. |
| GET    | `/games/export` | query param `game_id`                                             | Requires auth; `403` if you're not seated in that game -- deliberately narrower than `/games/log` above (no spectator path), since this is a personal offline archive rather than a shareable view. A raw, complete dump of every row related to this game (issue #99), across every table with any FK relationship to `games.id` -- not the curated, human-readable view `/games/log` already provides. Returns `{"export": {...}}`; see `GameService::exportGameData()` and "Download complete game data" below for the full shape. |
| POST   | `/games/start`  | `{"game_id"}`                                                     | Requires auth; `403` if you're not seated in that game. Deals hands and begins round 1. `409` if the game isn't `waiting` or has fewer than 2 seated players. |
| POST   | `/games/play`   | `{"game_id", "card_id", "choices"?}`                              | Requires auth; `403` if you're not seated in that game. `choices` is an opaque object passed straight through to the rules engine — its shape (a target player id, a discard, a mode string, etc.) is entirely card-specific; see `src/Rules/PlayerChoices.php` and `CardChoiceSchema` below. `400` on an invalid/missing choice for that card, `409` if it's not your turn, a decision is already pending, or the play is otherwise illegal. Returns `{"round_scored", "game_completed", "winner_game_player_id"?}`, or `{"pending_decision": true}` if the play now needs another player's own answer before it can finish — see `RequiresOpponentDecision` below. |
| POST   | `/games/pass`   | `{"game_id"}`                                                     | Requires auth; `403` if you're not seated in that game. `409` if it's not your turn or a decision is pending. Same return shape as `/games/play`. |
| POST   | `/games/respond` | `{"game_id", "choices"}`                                        | Requires auth; `403` if you're not seated in that game. Answers the one outstanding pending decision targeting you (see `round.pending_decision` in `/games/state`). `409` if you have no decision pending in that game. `400` on an invalid answer. Returns `{"pending_decision": true}` if the batch has other targets still waiting (or a Duplicity repeat of the same card also needs an answer), otherwise the same `{"round_scored", "game_completed", ...}` shape as `/games/play`. |
| POST   | `/games/resign` | `{"game_id"}`                                                     | Requires auth; `403` if you're not seated in that game. `409` if the game isn't `in_progress` (unless it's a `quick_draft`/`winston_draft`/`grid_draft` match still `'waiting'` through drafting/deck-building -- see "Resigning from a draft match" below), you've already resigned, or a decision is pending. Gives up instead of playing the game/draft out -- see "Resigning" below. Returns `{"round_scored": false, "game_completed", "winner_game_player_id"?}`. |
| GET    | `/games/notes`  | query param `game_id`                                             | Requires auth; `403` if you're not seated in that game. Returns `{"note_text"}` -- your own private note for that seat (issue #258), `""` if you've never saved one. Always readable, regardless of the game's status. See "In-game notepad" below. |
| POST   | `/games/notes`  | `{"game_id", "note_text"}`                                         | Requires auth; `403` if you're not seated in that game. `409` if the game isn't `in_progress` -- the note stays visible but read-only once a game ends. `400` if `note_text` is over 20,000 characters. See "In-game notepad" below. |
| POST   | `/games/chat`   | `{"game_id", "message_text", "channel"?}`                          | Requires auth; `403` if you're not seated in that game. No matching `GET` -- messages are delivered piggybacked on `GET /games/state`'s own `chat_messages` field instead (issue #109), not a dedicated fetch. `409` if the game isn't `in_progress`, or `channel` is `"team"` for a format with no team channel (see "In-game chat" below) or you're not on a team in this game. `400` if `message_text` is empty (after trimming) or over 500 characters. `channel` defaults to `"table"` if omitted. |
| GET    | `/games/spectatable` | —                                                             | Requires auth. Lists any friend's game that's currently `in_progress` and you're not seated in yourself, same shape as `GET /games` rows (minus the viewer-scoped fields, `draft_match` always `null`). See "Spectator mode" below. |
| POST   | `/games/spectate/code` | `{"game_id"}`                                              | Requires auth; `403` if you're not seated in that game. Returns `{"code"}` -- that game's own share code (an existing one if already minted, else a freshly generated one). See "Spectator mode" below. |
| POST   | `/games/spectate/resolve` | `{"code"}`                                              | Requires auth. `404` if no game has that code, or it's `waiting`/`abandoned`. Returns `{"game_id"}` for the frontend to navigate with. See "Spectator mode" below. |
| GET    | `/games/spectate/state` | query params `game_id`, `code`?                        | Requires auth; deliberately does **not** require you to be seated in that game -- see "Spectator mode" below for its own authorization rule. `403` unless you're friends with a seated player or `code` matches the game's own spectate code; `400` if the game is `waiting`/`abandoned`. Same shape as `GET /games/state`, minus `you`, `team_decision`'s propose/confirm affordances, and any draft-match internals -- plus, once the game is `completed`, every player's `hand` is additionally revealed (there's nothing left to hide once the outcome is decided). `chat_messages` is always `[]` here -- unlike `recent_events`, in-game chat (issue #109) is never visible to a spectator, seated players only. |
| GET    | `/games/replay/state` | query params `game_id`, `event_id`, `code`?              | Requires auth; `403` unless you're seated in that game OR authorized to spectate it (same `canSpectateGame()` check `GET /games/spectate/state`/`GET /games/log` use). `400` if the game isn't `completed` yet, or `event_id` doesn't belong to it. The board exactly as it looked immediately after `event_id` finished -- same shape as `GET /games/spectate/state`, but with `current_turn_game_player_id`/`pending_decision`/`plays_remaining`/`play_grants`/team-and-draft fields all `null` (there's no "current round" for a past event) and every hand always revealed. See "Watch replay" below. |
| GET    | `/games/draft-pool` | query params `game_id`, `code`?                             | Requires auth; `403` unless you're seated in that game OR authorized to spectate it (same `canSpectateGame()` check `GET /games/log`/`GET /games/replay/state` use). `409` if `game_id` isn't part of a draft match, or its match hasn't `completed` yet. Returns `{"draft_match_id", "players": [{"user_id", "username", "cards": [...]}], "undrafted_cards": [...]}` -- the whole Quick/Winston/Grid Draft match's shared `pool_card_ids`, sectioned by which player's `drafted_card_ids` each card ended up in, plus whatever nobody drafted (issue #314). See "View draft pool" below. |
| GET    | `/user/stats`   | —                                                                 | Requires auth. Returns `{"username", "stats": {"game_wins", "game_losses", "game_win_percentage", "match_wins", "match_losses", "match_win_percentage"}}` -- your own lifetime totals only (issue #106), all-zero (percentages `null`) for a user with no completed games/matches yet. See "Lifetime stats" below. |
| GET    | `/stats/cards`  | —                                                                 | Requires auth only -- server-wide aggregate data (issue #315), not tied to any one player, so no game/friendship check. Returns `{"cards": [{"catalog_card_id", "name", "set_code", "collector_number", "rarity", "color", "times_in_deck", "deck_win_rate", "times_played", "play_win_rate", "quick_draft": {"average", "count"}, "winston_draft": {...}, "grid_draft": {...}}, ...]}`, one entry per catalog card, all-zero/null defaults for a card nothing has happened to yet. See "Card statistics" below. |
| POST   | `/user/presence-preference` | `{"share_presence": bool}`                             | Requires auth. Opts you in/out of sharing your own online/offline status with friends and fellow game players (issue #110) -- write-only, since the current value already rides on `GET /me`'s own user object. `400` if `share_presence` is missing. See "Online/presence indicator" below. |
| GET    | `/notifications/vapid-public-key` | —                                                | No auth required -- the VAPID public key isn't secret (that's the point of asymmetric VAPID auth), same reasoning as `/cards/catalog` being public. Returns `{"public_key"}` (empty string if the server has none configured). See "Browser push notifications" below. |
| POST   | `/notifications/subscribe` | `{"endpoint", "keys": {"p256dh", "auth"}}`                | Requires auth. Stores (or updates, if the endpoint's already known) a `PushSubscription` for the current user. `400` if `endpoint`/`keys.p256dh`/`keys.auth` are missing. See "Browser push notifications" below. |
| POST   | `/notifications/unsubscribe` | `{"endpoint"}`                                          | Requires auth. Removes the current user's subscription for that endpoint, if any (silently a no-op otherwise). |
| GET    | `/notifications/preferences` | —                                                        | Requires auth. Returns `{"preferences": {"notify_your_turn", "notify_friend_request", "notify_game_finished", "notify_chat_message", "disable_cooldown"}}` -- the four `notify_*` toggles default `true`, `disable_cooldown` defaults `false`, for a user who's never changed them. |
| POST   | `/notifications/preferences` | `{"notify_your_turn"?, "notify_friend_request"?, "notify_game_finished"?, "notify_chat_message"?, "disable_cooldown"?}` | Requires auth. Upserts the current user's preferences (each `notify_*` field defaults to `true` if omitted, `disable_cooldown` defaults to `false`); returns the saved `{"preferences"}`. See "Browser push notifications" below for what `disable_cooldown` does, "In-game chat" above for `notify_chat_message`. |
| GET    | `/discord/status` | —                                                             | Requires auth. Returns `{"linked", "discord_username"}` (the latter `null` if unlinked). See "Discord" below. |
| GET    | `/discord/oauth/start` | —                                                        | Requires auth. Not a JSON endpoint -- a `302` straight to Discord's own OAuth2 consent screen. Meant for browser navigation (a link/button), not `fetch()`. See "Discord" below. |
| GET    | `/discord/oauth/callback` | `code`, `state` (query params, set by Discord's own redirect) | Requires auth. Not a JSON endpoint -- a `302` back to the lobby, `?discord_linked=1` on success or `?discord_link_error=<message>` on failure. See "Discord" below. |
| POST   | `/discord/unlink` | —                                                                | Requires auth. Removes the current user's Discord link, if any (silently a no-op otherwise). |
| POST   | `/discord/interactions` | raw Discord interaction payload                            | **Not** session-cookie authenticated -- called by Discord itself, verified via `X-Signature-Ed25519`/`X-Signature-Timestamp` instead (`401` if it doesn't verify). See "Discord" below. |

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
e.g. `https://example.com/app` if deployed under `/app`. Phone numbers are
still captured but nothing sends SMS -- see "Browser push notifications"
below for what does send notifications today (issue #108).

If a verification email fails to send, the real error (e.g. the SMTP
error PHPMailer raised) is appended to `src/mail-errors.log` — a fixed,
predictable location rather than PHP's ambient `error_log()` destination,
which varies by host and isn't always what cPanel's error log page shows.
`src/` already has a deny-all `.htaccess` from the deploy workflow, so
that file isn't web-accessible; check it via cPanel's File Manager or FTP,
not a browser.

## Maintenance mode

Production applies database migrations automatically as part of each
deploy, via `POST /migrate` (see "Auto-applying migrations on deploy" in
`database/README.md`) — but that request lands moments after the file
upload finishes, not atomically with it, and falls back to a manual
phpMyAdmin paste entirely if `MIGRATION_DEPLOY_KEY` isn't configured.
`src/Maintenance/MaintenanceGate.php` closes that gap: every request
(except `/health`/`/migrate`, see below) compares the deployed `VERSION`
file against a `version` value stored in the `schema_version` table
(`database/README.md`) and, on any mismatch, responds `503` with
`{"status": "maintenance", "message": "..."}` instead of running the
route — see `apiRequest()` in `web-static/js/app.js` for how the frontend
reacts to that. A missing `schema_version` table (the state right after
this feature's own migration deploys but before it's been applied) or any
other DB error also triggers maintenance mode — self-bootstrapping, and
exactly the condition this feature exists to catch. An unreadable
`VERSION` file instead fails *open* (allows traffic), since blocking every
user over an unrelated file-read glitch would be worse than the problem
being solved. This makes `database/README.md`'s migration convention a
hard requirement: any change that includes a migration must also bump
`VERSION`, and the migration itself must update `schema_version` to match
as its *last* statement.

`/health` is deliberately exempt — the deploy workflows' post-deploy smoke
test (`curl -fsS ".../app/health"`, no `continue-on-error`) would hard-fail
every migration-containing deploy otherwise. `/migrate` is exempt for a
sharper reason: its entire purpose is to resolve the exact
`VERSION`-vs-`schema_version` mismatch this gate checks for, so gating it
behind that same check would make it unreachable exactly when it's
needed. `/verify-email` is also exempt from the generic JSON gate, but
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

## Password reset

`POST /forgot-password` / `POST /reset-password` let a user recover their
account without contacting support. Modeled directly on the existing email
verification flow (`AuthService::resendVerificationEmail()`/`verifyEmail()`
and `EmailVerificationRepository`), with the token itself generated and
hashed the same way (`bin2hex(random_bytes(32))`, `hash('sha256', ...)`
stored in a `CHAR(64)` column) via a new `password_resets` table/
`PasswordResetRepository` — see `database/README.md`.

The one deliberate difference from `/verify-email`: that route is a
token-consuming `GET`, opened directly from the emailed link. A password
reset link uses the same pattern instead but the token isn't consumed on
`GET` — the emailed link points at the static frontend page
`reset-password.html` (via `SiteUrl::root()`, not `APP_URL`, since it's a
static page rather than a PHP route), which reads `?token=` from
`location.search` on load but doesn't submit it anywhere until the user
actually chooses a new password via `POST /reset-password`. A GET route
would be vulnerable to corporate email-security scanners that pre-fetch
links in inbound mail — since `PasswordResetRepository::consumeValid()`
is single-use (same select-then-delete pattern as
`DiscordOAuthStateRepository::consumeValid()`), a scanner's pre-fetch
would silently burn the real token before the user ever opens the email.
Because there's no new GET route, this feature also needs no
`MaintenanceGate` carve-out (unlike `/verify-email`'s own exemption
above).

Requesting a reset works regardless of the account's verification status
(unlike resending a verification email, which is a no-op once verified) —
someone who never finished verifying can still recover access to change
their password. `AuthService::resetPassword()` deletes every one of the
user's sessions on success (`SessionRepository::deleteAllForUser()`), so
a password reset also logs the account out everywhere, treating the
reset request itself as a signal any existing session may be
compromised.

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
whoever actually played it), a single self-renewing extra-play permission
that stays active for the rest of the turn for as long as a chosen
opponent has more moods in play than the acting player — re-checked live
before every subsequent play rather than pre-counted as a fixed gap, so a
play that itself closes the gap (e.g. Hate reducing that opponent's mood
count) is still allowed as long as the gap was open going into it, and the
permission survives even if the card that granted it later leaves play,
since it's an "after playing this card" effect rather than a "while in
play" one (Pride — `'requiresBehindPlayer'` in `BoardState::grantIsActive()`,
see `RequiresOpponentDecision` below for how the opponent is chosen), a widening of which zone a
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
`MoodPlayService`'s same-turn special case, plus
`BoardState::giveInPlayToPlayer()`'s own mid-turn case: gaining control
of an in-play Hope/Grace via any steal/give effect — Recklessness,
Instability, Guile, Betrayal, Arrogance, Avoidance, Chaos — grants the
same bonus the instant it happens, if it lands on whoever's turn is
currently active), a one-shot "banked" extra
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
`array_rand()`, and returning any further `PendingDecisionRequest`s that
only become askable once this round's own mutation has landed (`[]` for
every implementer except `InstabilityEffect` — see below). `MoodPlayService::playMood()` returns a `PlayResult`
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

Disillusionment's own printed text is a "may" ("each player MAY choose a
color"), so every queued `chosen_color_*` field is `required: false` --
declining contributes no color at all rather than forcing a pick.
`collectAnswers()` always writes one `PlayerChoices` entry per requested
key regardless of whether that player answered or declined (a decline's
own row still resolves, just with a `null` value) -- `resolveDecisions()`
reads each one via the nullable `->string($key)` and treats `null` as "no
color chosen", not via checking whether the key is merely present (every
key always is).

For a `required: true` field (Fury's/Suspicion's/Confusion's own discard
or hand-card choice, unlike Disillusionment's optional color),
`respondToDecision()` rejects a missing/null answer with a `400`
*before* writing anything, rather than persisting that row as resolved
and letting a `required` accessor (`requireInt()`/`requireString()`)
throw later inside `resolveDecisions()`. That later-throw shape used to
be a real bug: once persisted, a bad answer can only surface once some
*other*, later player's own answer happens to complete the batch --
`resolvePendingDecisions()` throwing at that point rolls back the whole
transaction, including that later player's own perfectly valid answer
(see `respondToDecision()`'s `catch (Throwable $e) { $pdo->rollBack();
throw $e; }`), and the resulting error names the first player's field
key, not the actual responder's -- leaving the batch permanently stuck
with no way for anyone to complete it. Validating at intake means a bad
submission is rejected immediately, attributed to whoever actually sent
it, and a `required` row can never again reach `resolved_at` with a
`null` answer.

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
reason, but its own two decisions have a genuine data dependency the other
eleven implementers don't: the *second* choice ("give one of your own
moods back") can't be legally offered — not merely excluded by a special
case, but actually absent from the board — until the *first* choice's own
mutation (the opponent handing over the taken mood) has actually happened,
since "one of your moods" has to include whatever was just received.
Bundling both into one `pendingDecisionsFor()` batch (the original design)
got this wrong two ways at once: the taken mood's ownership transfer was
deferred inside `resolveDecisions()` until *both* answers were already in,
so even with the two requests correctly step-ordered, the acting player's
own field was always computed and rendered against the *pre-transfer*
board — and `resolveDecisions()` additionally had an explicit
`$givenCardId === $takenCardId` exclusion blocking the one answer that
would've been correct once the mood genuinely was theirs. `resolveDecisions()`
is why `RequiresOpponentDecision`'s own return type is `array` (of
`PendingDecisionRequest`s) rather than `void`: almost every implementer
still returns `[]` (fully resolved), but `InstabilityEffect` now runs as
two genuinely sequential rounds within that one method, told apart by
which key `$answers` contains. Round 1 (`taken_mood_id` present) validates
and applies the opponent's own choice — `$state->giveInPlayToPlayer()`
runs immediately, right here, not deferred — then *returns* a new
`PendingDecisionRequest` for `given_mood_id` (targeting the acting player,
`type: mood, scope: own`, `required: true`) instead of that request ever
having been part of the original batch. `MoodPlayService` treats a
non-empty return from `resolveDecisions()` exactly like a non-empty
return from `pendingDecisionsFor()` — pausing again with a fresh
`PlayResult::pending()` — so `GameService::respondToDecision()` needed no
changes at all: it already handles `$result->isPending` generically,
writing a brand new `game_pending_decision_batches` row (same
`invocation_seq`, since this isn't a Duplicity repeat) whose field is
computed from the *already-mutated* board. By the time round 2 (`given_mood_id`
present, `taken_mood_id` absent) actually runs, the just-received mood
already shows up as one of the acting player's own moods like any other —
handing it straight back is a legal, offered answer, not a special case
to unblock. Round 2 recovers the opponent's id (no longer present in its
own `$answers`) by elimination over this invocation's own
`candidate_mood_ids` choice: whichever of the two original candidates
*isn't* owned by the acting player is necessarily still the opponent's,
since one game-wide lock and one open decision batch per round guarantee
nothing else can interleave and change that card's ownership in between.

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

Repentance's own `type: 'value'` field needs the same as-if-already-in-play
treatment, for the same underlying reason: its 0-12 range
(`allow_extra_values`) is only a practical default matching the highest
printed base value in the catalog, not a rule the card's own "choose a
number" text actually enforces, and a count-scaling mood (Euphoria's "+1
per mood in play, including itself," or Vanity/Sloth/Sadness/Envy) can
genuinely exceed 12 once Repentance itself becomes one more mood in play.
`GameService::withExtraOutOfRangeValues()` scans every mood currently in
play, computes each one's `valueOfAsIfAlsoInPlay()` value, and attaches
any that land above the field's own `max` as `extra_values` — which
`game.js`'s `buildFieldWidget()` appends to the picker's ordinary
`min`-`max` range. `RepentanceEffect` itself mirrors this at the actual
play-time validation: Repentance is already in play by the time
`afterPlaying()` runs, so a value above 12 is legal exactly when some
mood currently in play actually has it (computed the same way the
effect's own suppression loop already does), not an arbitrarily large
number a client could otherwise submit. Rebellion's own `type: 'value'`
field is deliberately never widened this way — its 0-3 range comes
directly from its printed rules text ("choose 0, 1, 2, or 3"), a real
rule rather than a practical default.

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

Scorn's `reactToAnotherPlay()` choice (`scorn_suppress_target`) doesn't fit
that per-card schema, since it fires while playing a *different* card,
triggered by a mood the acting player already has in play —
`CardChoiceSchema::reactionTemplate()` holds its field shape, and
`GameService::serializeCard()` appends it to each of the *viewer's own*
hand cards when `BoardState::playerHasMoodInPlay()` says the viewer has
Scorn in play, narrowing its filter to that card's own color (mirroring
`ScornEffect`'s "shares a color with the just-played card" check).
Validation's own `reactToAnotherPlay()` needs no such field at all: both
of its grants — the unconditional one from its own `afterPlaying()` and
the "while in play" one triggered by a subsequent 0-or-1-valued play —
are unconditional, matching every other extra-play card (Charity, Hope,
Grace, etc.); `ValidationEffect` just calls `grantExtraPlay()` outright
whenever the played card's base value is 0 or 1, no player choice
involved (a past version of this reaction was mistakenly gated behind an
opt-in `validation_extra_play` checkbox, which meant a chained reaction —
using Validation's own granted play to play a second 0-or-1-valued card —
silently never re-granted unless that checkbox happened to be checked;
fixed by making the grant unconditional, the same way its first grant
already was). Every serialized card also carries `base_value` and
`alt_value` (the printed/dice values, distinct from the possibly-different
live `value` a card in play might have) for client-side reasoning and for
display in the frontend's card detail dialog.

Duplicity's repeat mechanic — after any card's own `afterPlaying()`
resolves, if the acting player has Duplicity in play, they may have that
same `afterPlaying()` run a *second* time with a fresh, independent set of
choices, e.g. a card discarded once can't be discarded again on the
repeat — is implemented as a genuine mid-play pause, reusing the exact
same `PendingDecisionRequest`/`game_pending_decision_batches` machinery
built for the nine `RequiresOpponentDecision` cards above, except the
`PendingDecisionRequest`'s `targetPlayerId` is the *acting* player
themselves rather than an opponent. Each `afterPlaying()` invocation gets
its own independent choices, but for a "while in play" effect that stores
what it was told into its own `effectState` (rather than a one-time
action like a discard), that per-invocation choice has to *accumulate*
across invocations rather than the later one clobbering the earlier —
ruled for `WonderEffect`, whose repeated color choice must ADD to (not
replace) the original one, so it stores every chosen color in a list
('colors') and counts a match against any of them, instead of overwriting
a single scalar. `MoodPlayService::continueAfterPlayingChain()`
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
one from the Creativity's repeat of it.

That count is snapshotted by `MoodPlayService::resolveAfterPlayingChain()`
*before* the current invocation's own `afterPlaying()`/`resolveDecisions()`
gets to mutate anything (`BoardState::setDuplicityEligibleSources()`/
`duplicityEligibleSources()`, piggybacking on the played card's own
`effectState` bag so it automatically survives an opponent-decision pause
the same way any other `effectState` does, but deliberately bypassing
`recordEffectStateChange()` so this pure bookkeeping value never leaks
into the event log's `effect_state_changes`) — not recomputed live from
current board state once that invocation's effect has already run. This
matters for a card like Chaos, which reassigns every in-play mood's owner
(including Duplicity itself) as its OWN after-playing effect: per an
official ruling, Duplicity's opportunity to repeat is judged at the
moment the mood is played, not after that mood's own effect resolves, so
Chaos's own shuffle handing Duplicity control to (or taking it away from)
the very player who just played it must never change whether a repeat
gets offered for *that* play — it doesn't matter whether they still
control Duplicity by the time they're actually asked. The pending
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
`reactionFields()` (Scorn) this class already calls for an
ordinary hand card, parameterized by the candidate's *effective*
color/catalog row (`catalogRow(effectiveCardId($candidateCardId))`) --
Duplicity's own repeat is no longer part of this precomputed bundle at
all, since it's now a post-play pause rather than a field on the play
itself. `cost_payable` mirrors `MoodPlayService::playMood()`'s own
to-play-cost check (`canPayCopiedToPlayCost()`, also resolved through
`effectiveCardId()` the same way), passing Creativity's own card id --
not the candidate's -- as the effect's `$cardId`, matching what
`payMood()` itself does (`GuileEffect`/`BlissEffect` exclude that id
from the hand, and Creativity is what's actually occupying that hand
slot). The client swaps in the matching bundle, plus the candidate's own
already-serialized `choice_fields` (its own "to play" cost and
after-playing choices, read from the same flat top-level `choices` bag a
normal play of that card would use), as `copy_card_id` changes -- see
`web-static/README.md`. `MoodPlayService`'s repeat/reaction/pending-decision
machinery needed no changes at all to support this: it was already
effective-aware end to end (`BoardState::effectiveCardId()`), so a
Creativity copy of, say, Compulsion already paused for the target's own
real choice the same way a real Compulsion would, even before the panel
could offer `target_player_id` to ask for one.

Copying a Creativity that's itself copying something resolves through
the WHOLE chain, per a rules judge ruling: "an exact copy of that
printed card" means whatever real (non-`creativity`) card started the
chain, so a Creativity copying another Creativity that's copying, say,
Paranoia is a copy of Paranoia -- color, value, to-play cost, and
after-playing ability all included -- not "a blank blue 0" copy of
literal Creativity. `MoodPlayService::playMood()` resolves the raw
`copy_card_id` through `BoardState::effectiveCardId()` (which itself
walks the whole chain, not just one hop) before computing anything else
from it, and stores that fully-resolved id as the new mood's own
`copiedCardId` -- so the copy's identity stays correct even if the
Creativity it was actually pointed at later leaves play, the same
permanence a direct copy of a non-Creativity card already had.
`canPayCopiedToPlayCost()` and `creativityCopySimulation()` above both
resolve the same way, so the choices panel already previews a
copy-of-a-copy correctly before the play is even submitted.

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

`'while_source_in_play'`'s name is a slight misnomer: the card text on
all five of Faith/Guilt/Meekness/Pacifism/Shame actually reads "for as
long as you have this mood" — i.e. while the player who played it still
*owns* it, not merely while it's in play under anyone's control. A
steal/give-away effect (Recklessness, Instability, Guile, Betrayal,
Arrogance, Avoidance, Chaos) reassigning the source card's owner via
`BoardState::giveInPlayToPlayer()` therefore lifts any
`'while_source_in_play'` suppression it's the source of at that same
moment, exactly as if the card had left play outright
(`clearSuppressionsFrom($cardId, 'while_source_in_play')`) — the original
caster no longer "has" it once someone else does, even though it's still
sitting in play. This is scoped to that one expiry only: an
`'end_of_round'` suppression sourced by the same stolen card (Scorn) is
left alone, since its own duration has nothing to do with who currently
owns Scorn.

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
candidate) -- `is_playable`/`choice_fields`/Scorn's reaction field are now
correctly computed for a discard-pile card too,
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

The same event also calls out Honor (or Awe's own one-time version)
overriding who goes first next round -- normally that's simply whoever
just won, so it's silent; `finishScoringAndAdvance()` folds
`BoardState::firstPlayerOverride()`'s result into the same `details` as
`first_player_override_game_player_id`, but only when it actually differs
from the round's winner (and there IS a next round -- unused if the
override coincides with the win that ends the game), and
`describeRoundScored()` appends "; Charlie goes first next round instead
of the round's winner" when it's set. Awe's own "skip scoring entirely"
branch (`skipScoringAndAdvance()`, no winner to already imply anything)
logs the same field unconditionally and gets its own shorter phrasing,
"; Charlie goes first next round" -- see its own `if ($details['skipped']
?? false)` branch in `describeRoundScored()`.

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
copies get played in a single turn. The one grant this never applies to is
`startTurn()`'s own first, ordinary base play -- it's stored as a bare
`null`, which `describePlayGrant()` reads as "Your normal turn" rather than
a granted extra play from any specific card. Hurt Feelings' own *second*
base play (see `startTurn()`'s `hasHurtFeelings` param / `computeFreshGrants()`'s
`baseCount`) is deliberately **not** a second bare `null` -- that would
render as an indistinguishable second "Your normal turn" entry in
`round.play_grants`, reading as though the player simply had two ordinary
turns rather than one turn plus a bonus. It's instead tagged `'sourceLabel'
=> 'Hurt Feelings'`, a sibling to `'sourceCardId'` for grants that aren't
attributable to any specific card -- `sourceCardNameFor()` checks it first,
so `describePlayGrant()` renders it as "An extra play from Hurt Feelings"
through the exact same `describeGrantDetails()` wording every card-sourced
grant already uses. This also means using that specific play now populates
`grant_used` on the resulting `mood_played` event (previously, consuming
the bare-`null` base allowance never did, by design -- see
`$pendingGrantUsed`'s own docblock), so the recent-plays log calls out
"(using an extra play from Hurt Feelings)" on whichever card was actually
played with it, instead of that play silently looking like an ordinary
second play. `round.play_grants` itself always describes whoever's turn it
currently is, not the viewer specifically -- the frontend's own "Plays
left" indicator stays hidden entirely unless it's actually the viewer's
turn (see `web-static/README.md`), rather than showing another player's
own outstanding plays as if they were the viewer's.

Hope's and Grace's own grants -- the same-turn one (`MoodPlayService`,
the moment either card enters play), every future turn's perpetual one
(`computeFreshGrants()`), and the mid-turn one for gaining control of an
already-in-play Hope/Grace via a steal/give effect
(`BoardState::giveInPlayToPlayer()`; e.g. Recklessness's own "take one of
your opponents' moods" landing on a Hope, or Instability/Guile/Betrayal/
Arrogance/Avoidance/Chaos doing the same -- only fires when the new owner
is whoever's turn is currently active, and never for a no-op transfer
where the owner doesn't actually change, e.g. Instability giving a mood
to itself) -- all three also carry `'requiresSourceInPlay' => true`
alongside their `sourceCardId`. Unlike an
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

Pride's own grant carries a different tag, `'requiresBehindPlayer' =>`
the chosen opponent's player id, and is the deliberate opposite of
`'requiresSourceInPlay'` in exactly the respect that matters: per
published rulings, Pride is an "after playing this card" effect, not a
"while in play" one, so its grant must persist even if Pride itself later
leaves play (e.g. discarded as a cost to Infatuation), unlike Hope/Grace
above. What it depends on instead is a live comparison of mood counts --
`grantIsActive()` returns `false` for it the moment the chosen opponent no
longer has strictly more moods in play than whoever's turn it currently is
(`BoardState::currentPlayerId()`; `$playGrants` is reset every turn, so
this is always the player Pride's own grant belongs to) -- re-run fresh on
every `playsRemaining()`/`hasUsablePlayGrant()`/`useGrantFor()` call rather
than decided once when Pride resolved. `useGrantFor()` also never removes
this particular grant from `$playGrants` on use, the one exception to its
otherwise-unconditional "spend it" behavior, since it isn't a one-shot
extra play at all but a standing permission that keeps re-qualifying
itself. Because eligibility is checked going into a play, not after that
play resolves, a play that itself closes the gap (e.g. Hate, "put a card
on the bottom of the deck", played against the chosen opponent) is still
allowed to happen -- `MoodPlayService::playMood()` already checks and
consumes the relevant grant before moving the card into play or resolving
its own effect either way, matching the ruling that you may play such a
card as long as the opponent was still ahead at the moment you committed
to it, even though it doesn't stay ahead once that same card resolves.

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
'base'` -- the ordinary base allowance and Hurt Feelings' own second base
play both lack a `sourceCardId`, so they collapse into a single entry here
too, since neither restricts what's playable and so they're functionally
indistinguishable to a player choosing between them, even though their
`round.play_grants` descriptions still differ). `GameService::serializeCard()`
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
`playsRemaining()` in a way that's hard to notice after the fact. That
exception's message names both cards involved (e.g. "Grant sourced from
Hope is not currently usable for playing Complacency") rather than their
opaque in-game ids -- `MoodPlayService::playMood()` takes an optional
`$cardNames` map (`cardId => name`) purely for this one message, since
BoardState/MoodPlayService are otherwise deliberately unaware of
anything DB-backed; `GameService::playMood()` is the only caller that
passes one (its own `cardNamesFor($gameId)`), so every other caller
(direct tests included) still gets the bare-id message this always had.

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
one of these:

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
- `quick_draft` -- for `format: 'draft'` games only (see "Draft format"
  below): both players draft their own 16-card pool live from a shared
  card pool, then play a best-of-three match built from it (see "Quick
  Draft" below) -- like `custom_duel`,
  `deckCardIdsFor()` explicitly refuses to build this one (a
  `\LogicException`), since each player's own deck lives on
  `draft_match_players.deck_card_ids`, not anywhere this method's `$game`
  argument alone can resolve; `startGame()` reads it directly via
  `requireDraftDecksSubmitted()` instead.
- `winston_draft` -- also `format: 'draft'` only: an alternating,
  single-active-player pile draft (see "Winston Draft" below) rather than
  Quick Draft's simultaneous pack-passing, but the same story otherwise --
  `deckCardIdsFor()` refuses to build this one too, since each player's
  own deck lives on `draft_match_players.deck_card_ids` just as it does for
  `quick_draft`; `startGame()` reads it via the same
  `requireDraftDecksSubmitted()`.
- `grid_draft` -- also `format: 'draft'` only: 2-4 players (issue #189)
  each draft from a shared pool (54/72/96 cards for 2/3/4 players) by
  taking a whole row or column of a grid (3x3 for 2-3 players, 4x4 for
  exactly 4), dealt fresh over 6 rounds (4 for exactly 4 players, so each
  player picks first in exactly 1 round) (see "Grid Draft" below) -- same
  story again as `quick_draft`/
  `winston_draft`: `deckCardIdsFor()` refuses to build this one too, and
  `startGame()` reads it via the same `requireDraftDecksSubmitted()`.
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

### Draft format

`format: 'draft'` (migration `0028`) is "duel-shaped" (see "Duel: separate
per-player decks" above) but scoped to a different set of deck_type
values -- ones that build a player's deck through some kind of live
drafting process rather than an already-built pool/decklist, as opposed to
`format: 'duel'`'s algorithmically-assembled/self-submitted ones.
`quick_draft` (below) was the first such deck_type; `winston_draft` and
`grid_draft` (also below) followed, reusing as much of its own
infrastructure as possible -- `createGame()` rejects a `'draft'` game with
any other deck_type, and rejects `deck_type: 'quick_draft'`/`'winston_draft'`/
`'grid_draft'` under any format other than `'draft'`. `quick_draft` started
out as a `duel` deck_type during issue #88's own development, then was
split into its own format once a second draft-style deck type was planned --
none of which are expected to ever make sense under `'duel'` itself, whose
own deck_type roster (`structure`/`power`/`jceddys_75`/`custom_duel`/
`one_of_each`) is expected to stay exactly what it is.

### Saved decklists

Issue #92: lets a user save a decklist to their account as a first-class,
reusable object, rather than only ever supplying `custom_deck_card_ids`
scoped to a single `games`/`game_players` row via the `custom`/
`custom_duel` deck_type flows above. Backed by a new `user_decklists`
table (migration `0038`) -- `user_id`, `name`, `card_ids` (JSON), nullable
`sideboard_card_ids` (JSON), and `visibility` (`private`/`friends`, no
third "public to everyone" tier -- the "Share with friends" checkbox on
the draft formats' own "Save deck" button, see "Quick Draft" below, means
exactly this `friends` value).

`MoodSwings\Repository\UserDecklistRepository` is a plain CRUD layer;
`MoodSwings\Deck\UserDecklistService` (constructed with that repository
plus `FriendshipService`, which gained a new `areFriends(int, int): bool`
helper for this) owns validation and authorization: `create()`/`update()`
accept either raw `decklistText` (parsed via the same `DecklistParser`
the `custom` deck_type uses, now also capturing an optional `Sideboard`
section into `sideboardCardIds` instead of discarding it) or
already-resolved `cardIds`/`sideboardCardIds` arrays; `listForViewer()`
returns the caller's own decks plus, grouped by friend, every
`friends`-visible deck belonging to an accepted friend (a friend with
none are omitted from the list entirely); `view()`/`cardIdsForUse()`
authorize the owner or, for a `friends`-visible deck, any accepted
friend of the owner.

Catalog-loading/hydration (`loadCardCatalog()`'s name-resolution map and
`serializeCatalogCards()`'s catalog-only card view, previously private
`GameService` methods) were extracted into a new stateless
`MoodSwings\Game\CardCatalog` class (`load()`/`serialize()`) so
`UserDecklistService` can reuse them without depending on the whole of
`GameService`; `GameService`'s own two methods are now one-line
delegations, so none of their existing call sites changed. `serialize()`
also includes each card's `set_code`/`collector_number` (joined from
`card_sets`/`sets`, picking the row with the lowest `sets.id` if a card
ever belongs to more than one -- every card belongs to exactly one,
`MSW`, today; `collector_number` itself is migration `0039`'s addition
to `card_sets`, see "Sets" in `database/README.md`) -- fields
`buildCardThumb()`/`openCardDetail()` don't read, but the Decks dialog's
"Edit"/"Duplicate"/"Download" flows do (see `buildDecklistText()`/
`buildDecklistCardsText()` in `web-static/js/game.js`), to reconstruct a
saved deck's decklist text in `DecklistParser`'s own
`"1 Name (SET) NUMBER"` format when populating `#decks-form-text` for
editing or building a downloadable `.txt` file, so those actions work
with something the user can actually read/adjust instead of a blank
field backed silently by stashed ids. Like everywhere else that format
is accepted, both `(SET)` and `NUMBER` are purely cosmetic on reparse:
`DecklistParser` ignores both, matching by name alone.

`GameService::createGame()`'s `'custom'` branch and
`submitCustomDuelDeck()` each accept a new optional `savedDecklistId`
parameter as an alternative to `decklistText` -- when given, card ids
come from `UserDecklistService::cardIdsForUse()` (with the same
ownership/friend-sharing authorization check `view()` uses) instead of
`DecklistParser::parse()`, then flow through the exact same downstream
validation (the `15*(N-1)` minimum-card-count check for `custom`,
`DuelDeckRules::validate()` for `custom_duel`) as a freshly-typed
decklist would.

The draft formats' own "Save deck" button (see "Quick Draft"/"Winston
Draft"/"Grid Draft" below) does *not* go through `GameService` at all --
the frontend already knows its own selection's resolved card ids
client-side (see `renderDraftDeckBuilding()` in `web-static/js/game.js`),
so it POSTs directly to `POST /decklists` with `card_ids` (the current
selection) and `sideboard_card_ids` (every drafted card *not* currently
selected -- the "optional sideboard" the issue asks for, derived as the
selection's complement rather than picked via any separate UI).

### Deck builder

Issue #93: a card-by-card alternative to typing/pasting a decklist for
the Decks dialog's own save/edit form -- browse the full card catalog
(`GET /cards/catalog`, added for this: every printed card, hydrated via
`CardCatalog::serialize()`, now including `rarity` alongside the fields
that method already returned), click a card to add it to the deck under
construction, then save through the exact same `POST /decklists`/
`POST /decklists/update` the paste/upload form already uses -- no new
save endpoint needed, since a saved decklist is just a `card_ids` array
regardless of how it was assembled.

Everything else -- filtering the catalog by set/color/rarity/name-or-
rules-text substring, multi-key sorting the deck under construction by
color/rarity/name, and restricting which cards a chosen format allows
adding -- is entirely client-side (`web-static/js/game.js`'s "Deck
builder" section), the same duplication-with-a-mirrors-comment
convention `DECK_TYPE_DESCRIPTIONS` already uses for prose rather than a
matching backend endpoint: `DECK_BUILDER_FORMATS`' four options
(`free_form`/`power`/`structure`/`jceddys_75`) mirror
`GameService::buildPowerDeckCardIds()`/`buildStructureDeckCardIds()`/
`buildJceddys75DeckCardIds()`'s own card counts/rarity splits/copy caps.
The format selector only gates which cards `canAddCardToBuilderDeck()`
lets you click "+ Add" on *while building* -- a saved decklist has no
`format` column of its own (same as one built by pasting text), so
switching formats mid-build doesn't retroactively strip anything already
added, only further additions. `web-static/README.md`'s "Deck builder"
section covers the dialog/UI side in more detail.

### Quick Draft

`deck_type: 'quick_draft'` (issue #88) is the first `format: 'draft'` deck
type: instead of picking a pre-built pool or submitting an already-built
decklist, both players draft their decks live from a shared card pool,
then play a best-of-three match with sideboarding between games. This is
the largest deviation from every other deck_type's shape -- it's the only
one where deck-acquisition data has to survive across up to 3 separate
`games` rows (one per game of the match) rather than living entirely
within one, so it gets its own match-level tables (migration `0027`)
instead of columns on `games`/`game_players`.

**Data model** -- `draft_matches` (one row per match: `pool_source`,
`pool_card_ids` -- the shared up-to-48-card pool, `status`
`'drafting'`/`'deck_building'`/`'completed'`, `current_round`,
`winner_user_id`), `draft_match_players` (one row per `(draft_match_id,
user_id)` -- keyed by **user_id**, not `game_player_id`, since that id is
scoped to a single `games` row and this data spans up to 3: the fixed
16-card `drafted_card_ids` result of the draft, the player's current
12-16 card `deck_card_ids`, and this match's own `wins` counter), and
`draft_round_picks` (one row per `(draft_match_id, user_id, round_number)`
-- `drawn_card_ids` (the 6 cards dealt that round), `kept_from_draw_ids`/
`kept_from_received_ids` (each round's two blind sub-steps, see below)).
`games` gets two nullable columns, `draft_match_id` and
`match_game_number` (1/2/3), linking each game of the match back to its
one shared `draft_matches` row.

Passed cards (= drawn minus kept_from_draw), received cards (= the
OPPONENT's own passed cards that same round), and discarded cards (=
received minus kept_from_received) are all **derived, never stored** --
`GameService::draftDerivedState()` recomputes them from the three stored
columns above every time they're needed, the same "recompute from source
rows" approach `BoardStateRepository` already takes for board state
generally. At most 8 `draft_round_picks` rows ever exist per match (4
rounds x 2 players), cheap to scan in full every time.

**Pool sources** (`buildQuickDraftPool()`, dispatched at `createGame()`
time via `quick_draft_pool_source`) -- `random_48` (48 random *distinct*
catalog cards), `structure` (reuses `buildStructureDeckCardIds()`'s own
45-card pool as-is), `jceddys_75` (reuses `buildJceddys75DeckCardIds()`'s
own 75-card pool as-is -- except at exactly 4 players, where
`buildJceddys150DeckCardIds()`'s own 150-card pool is used instead, since
75 falls short of every 4-player target this source is ever used for; see
"Multiplayer" below), `one_of_each` (the full 133-card catalog), `custom` (a pool of 45+ cards in
the same decklist-line format `custom` decks use, parsed via the same
`DecklistParser`, minimum 45 rather than that deck_type's player-count-scaled
minimum, and with no use for whatever optional deck name the format's own
"About" block might carry -- a draft pool isn't a named deck), or
`saved_deck` (issue #290: one of the creator's own saved decklists --
`resolveSavedDeckDraftPool()`, reusing `UserDecklistService::cardIdsForUse()`
the same way the `custom` deck_type's own `saved_decklist_id` already does
for a whole game's deck; preserves whatever per-card quantities the
decklist was saved with, subject to the same 45-card minimum as `custom`).
Whatever the source produces, anything over 48 cards is randomly truncated
down to exactly 48 before drafting starts.

**Multiset correctness** -- pools/hands can legally contain duplicate
catalog card ids (a `custom` pool may list "2 Charity"; the other three
sources never do). `array_diff()`/`array_intersect()` are unsafe for any
of the pool/drawn/kept/passed/discarded computations above -- they remove
*every* matching value, not one instance, silently destroying a
legitimate duplicate. `multisetSubtract()` is the one helper all of that
math goes through instead (loop the cards to remove, `array_search()` +
`unset()` exactly one matching key per removal, reindex).

**The draft itself** (`submitQuickDraftPick()`, `POST /games/draft/pick`)
-- 4 rounds (`QUICK_DRAFT_ROUNDS`), each with two blind sub-steps modeled
on Closed Team Play's own initial card-pass mechanic (immediate
per-submission write, then check both parties done -- see
`submitInitialCardPass()`), just with no `game_cards` ownership-transfer
step (there's nothing to transfer -- drafting happens entirely before
`startGame()`, so no `game_cards` rows exist yet for pool/pack cards):

1. **`stage: 'draw'`** -- both players draw 6 fresh cards from whatever of
   the pool hasn't been drawn in an earlier round, and each keeps 2 of
   their own; the other 4 are (implicitly, by not being kept) passed to
   their opponent.
2. **`stage: 'received'`** -- only once BOTH players have submitted stage
   `'draw'` are "the cards you received" determined (the derived
   complement of what your opponent kept) -- each player keeps 2 of those
   4, permanently discarding the other 2.

Each stage is a one-time, unrevisable submission -- neither player can
see the other's choice for a stage until they've submitted their own for
it. After 4 rounds, each player has kept 16 cards (2+2 per round x 4);
`finalizeQuickDraft()` writes that union to `drafted_card_ids` and flips
the match to `'deck_building'`.

**Pool-too-small handling** (`dealQuickDraftRound()`) -- only relevant for
the 45-card `structure` pool, or a `custom` pool sized 45-47: by round 4
the remaining undrawn pool would be short of the 12 cards that round
needs. Before dealing any round where the remaining pool is short, enough
already-discarded cards (from any earlier round, either player) are
randomly selected and shuffled back in to top it up to 12 -- replicating
the physical game's own "reshuffle 3 discards back in" workaround for a
45-card box, generalized to whatever the actual shortfall is.

**Deck building and sideboarding** (`submitDraftDeck()`, `POST
/games/draft/deck`) -- once the draft finishes, each player trims their
fixed 16-card `drafted_card_ids` down to a 12-16 card `deck_card_ids`
before game 1 can start (`startGame()`'s `requireDraftDecksSubmitted()`
gate, mirroring `requireCustomDuelDecksSubmitted()` but reading
`draft_match_players` by `user_id` instead of `game_players.custom_deck_card_ids`).
The very first trim and every later sideboard between the match's games
are the exact same operation against the same `'deck_building'` status --
there's no "first trim" vs. "a sideboard" distinction worth making. This
endpoint/method pair is shared verbatim with Winston Draft (below) --
`requireDraftDecksSubmitted()`/`submitDraftDeck()` are parameterized by
min/max deck size per format rather than duplicated.

The deck-building screen's own "Save deck..." button (issue #92) is
unrelated to `submitDraftDeck()`/this match at all -- it saves a
standalone copy of the current selection as a first-class, reusable
decklist (see "Saved decklists" above), independent of whether it's ever
actually submitted for this game. It POSTs straight to `POST /decklists`
with `card_ids` (the selection) and `sideboard_card_ids` (every drafted
card *not* selected), so no new `GameService` method was needed for it.

**Match progression** (`advanceDraftMatch()`, called from
`finishScoringAndAdvance()` the moment a game completes) -- credits the
winner's own user with a match win; at 2 wins (`DRAFT_GAMES_TO_WIN`)
the match itself is `'completed'`, otherwise the next game in the match is
created (same 2 seats, same `format`/`deck_type`/`wins_needed`,
`match_game_number + 1`) and the match resets to `'deck_building'`. Both
players' `deck_card_ids` are explicitly nulled out here -- without that, a
leftover value from the game that just finished would silently satisfy
`startGame()`'s own "deck submitted" gate for the next game, skipping the
required sideboard step entirely. Whatever `deck_card_ids` held right
before that null-out is copied to `previous_deck_card_ids`
(`draft_match_players`, migration `0029`) first, purely so the frontend's
new sideboard picker can pre-select it as a starting point instead of
defaulting to every drafted card and forcing a full retrim from scratch
before every single game -- it plays no part in `startGame()`'s own
"deck submitted" gate, which still only ever looks at `deck_card_ids`.

**Who goes first** (`resolveFirstPlayerId()`, `setPlayFirstNextMatchGame()`,
`firstPlayerDecisionStateFor()`) -- game 1 of a match still gets a
uniform coin flip (`startGame()`'s default for every non-draft-match
game too). Games 2/3 are the one exception, and per a rules
clarification the previous game's loser doesn't have to decide who
goes first until they can see their own opening hand -- so unlike a
pre-start preference, this is resolved *after* `startGame()`, not
before it. `startGame()` still picks a placeholder first player for
games 2/3 (`previousMatchGameWinnerUserId()`, same as always), but
creates round 1 frozen (`current_turn_game_player_id NULL`,
`plays_remaining 0`, `pending_play_grants '[]'`, mirroring `team`/
`closed_team`'s own frozen-round-1 pattern) instead of immediately
playable -- hands are dealt, nobody (including the placeholder "first"
player) can play or pass, until the loser explicitly decides.
`setPlayFirstNextMatchGame(gameId, userId, bool $playFirst)` (`POST
/games/draft/first-player-choice`, `{"game_id", "play_first": bool}`)
is only callable once that game has actually started; `$playFirst`
true sends the loser out first themselves, false leaves the
placeholder (the previous winner) going first again -- either answer
is a real, round-unfreezing decision (`computeFreshGrants()` +
`updateRoundTurnState()`, the same pair `submitInitialCardPass()` uses
to unfreeze `closed_team`'s own round 1), not a "did nothing" default,
and it's permanent -- calling it again once decided throws. `games.
first_player_choice_user_id` still just records whoever ends up going
first, for parity with the old field. `getState()`'s own top-level
`first_player_decision` field is non-null only while round 1 is still
frozen waiting on this (`null` for game 1, and null again once
resolved): `you_are_previous_loser` and `default_user_id` let the
frontend show the loser two buttons ("I'll go first" / "let so-and-so
go first again") and show the winner a waiting status, both reading
from the same field. The decision also gets its own `describeEvent()`
case (`'draft_match_first_player_decided'` -- "{loser} will go first
this game"), the same way `team_turn_order_decided`/
`team_draw_recipient_decided` get their own phrasing rather than
falling through to the generic "{actor} played {card}" default.

**State exposure** -- `getState()`'s own `game.match_game_number` and
`quick_draft` field (`null` for every other deck_type) are populated
regardless of the game's own `status` (a Quick Draft match's drafting/
deck_building phases both happen while the game itself is still
`'waiting'`): `quick_draft.{your_wins, opponent_wins, games_to_win}` is
the always-present match scoreline, plus whichever one of `drafting`
(current round, your pack/stage, everything you've kept so far) or
`deck_building` (your 16 drafted cards, current deck selection, both
players' submission status) is currently live. Pool/pack/drafted cards
are serialized via a catalog-only view (`serializeCatalogCards()`) rather
than `serializeCard()`, which requires a live `BoardState` + a
`game_cards.id` that don't exist yet for cards that haven't been dealt
into a game -- shaped to the same fields `buildCardThumb()`/
`openCardDetail()` already read, with every in-play-only field defaulted
to false/null, so the frontend reuses those two functions unchanged.
Never exposes the opponent's own drafted/kept/received cards -- only the
viewer's own.

`quick_draft.next_game_id` is `null` except in the one specific window
where it matters: viewing a game whose own `status` is `'completed'` but
whose match isn't -- i.e. `advanceDraftMatch()` has already created
the next game in the match. Lets the frontend offer a direct "Go to next
game" link from a just-finished game's own board, rather than making the
player go back to the lobby and pick the new `'waiting'` row out by hand.
Winston Draft's own `winston_draft.next_game_id` (below) works identically.

**Lobby grouping** -- `GET /games` (see the API table above) tags every
`quick_draft`/`winston_draft` game with `draft_match_id`/`match_game_number`/
`draft_match`, purely so the frontend can visually group a match's
up-to-3 `games` rows together instead of listing them as unrelated games,
and show the match's own result once it's decided. `draft_match` (renamed
from `quick_draft_match` once Winston Draft became a second consumer --
its shape is identical regardless of which draft variant the match
belongs to) is deliberately a separate, leaner query (`draftMatchSummaryFor()`)
from `quick_draft`'s own `getState()` field above -- that one also needs
drafted/deck/previous-deck card ids for the deck-building sub-state, which
every row in a lobby listing would otherwise pay for and never use.
`draft_match.winner_username` is only set once the match itself (not
just the individual game) is `'completed'`.

**Multiplayer (issue #189)** -- Quick Draft, Winston Draft, and Grid
Draft all support 2-4 players (`createGame()`'s `isDuelShapedFormat()`
gate widens specifically for `format: 'draft'` + `deck_type` in
`['quick_draft', 'winston_draft', 'grid_draft']`; `'duel'` itself stays
locked to exactly 2). Everything above still holds for a 2-player match;
this section covers what changes for 3-4 (Winston Draft's own and Grid
Draft's own multiplayer mechanics -- turn rotation, pool sizing, and
each format's own take on the sub-12-card shortfall -- are covered in
their own sections below rather than repeated here):

- **Pile size and stage count generalize together.** A round no longer
  deals a fixed 6-card pack with 2 fixed sub-steps -- every player is
  dealt their own `quickDraftPileSize($playerCount)` = `2N + 2`-card
  pile (8 for 3 players, 10 for 4), and each pile is worked through
  exactly `$playerCount` **stages** (an integer 1..N, not the old
  `'draw'`/`'received'` string enum) instead of 2 -- one keep-2 decision
  per stage, made by a different seated player each time. Since a pile
  loses 2 cards per stage and starts at `2N + 2`, it always empties to
  exactly 2 (discarded) after N stages -- meaning every pile completes
  one full lap of every seated player before running out, degenerating
  cleanly to the original 2-stage behavior at N=2.
- **Seat rotation, not opponent lookup.** With only 2 players "pass to
  the other one" needed no bookkeeping; with 3-4, whose pile a player
  holds at a given stage is real seat-index arithmetic
  (`submitQuickDraftPick()`'s own docblock has the formula), and the
  direction alternates right/left/right per round
  (`quickDraftPassDirection()`). Because every pile advances in
  lockstep, "stage" is a single round-wide counter: nobody can act on
  stage N+1 until every pile has finished stage N, generalizing the
  original "received cards aren't determined until both players submit
  their draw pick" gate.
- **New schema for N-stage picks.** `draft_round_picks` still only
  records each pile's initial `drawn_card_ids` (unchanged); the old
  `kept_from_draw_ids`/`kept_from_received_ids`/`submitted_draw_at`/
  `submitted_received_at` columns (fixed at 2 stages) are replaced by a
  new table, `draft_pile_stage_picks` (migration `0060`) -- one row per
  `(draft_match_id, round_number, pile_owner_user_id, stage_number)`,
  recording `holder_user_id` (whoever actually held the pile at that
  stage -- equal to `pile_owner_user_id` only at stage 1) and
  `kept_card_ids`. `finalizeQuickDraft()` now unions every
  `kept_card_ids` a player was ever the `holder_user_id` for, across
  every stage of every round, into their `drafted_card_ids` -- most of
  what a player nets comes from piles other seats passed to them, not
  just their own.
- **Pool size scales with player count.** `quickDraftPoolTargetSize()`
  is 24 cards per seated player (48/72/96 for 2/3/4), and
  `quickDraftRounds()` is chosen per player count so everyone clears at
  least a 16-card pool: 4 rounds for 2 players (16 total), 3 rounds for
  3 (18 total), 2 rounds for 4 (16 total). `structure`'s 45-card pool is
  doubled (2 copies concatenated, then truncated down to the target if
  over) for 3-4 players, same as Winston Draft's own `structure` handling
  -- a single 45-card copy falls short of either target even with
  `dealQuickDraftRound()`'s own discard-reshuffle top-up, since there's
  nothing yet to reshuffle before round 1 is even dealt (this was a real
  bug, not a deliberate design choice: an earlier version of this pool
  source left `structure` undoubled for Quick Draft, silently producing a
  4-player draft whose custom-pool floor -- see below -- a `structure`
  pool of the same player count didn't itself have to meet). The doubled
  90-card pool is still 6 cards short of the 4-player target (96) --
  that residual gap is what the discard-reshuffle top-up actually
  covers, not the entire 45-to-96 shortfall. `jceddys_75` is the one
  pool source that never needs the reshuffle top-up at all: at exactly 4
  players (96 needed), `buildDraftPool()` itself swaps in
  `buildJceddys150DeckCardIds()`'s own 150-card pool instead of the plain
  75-card one (see "jceddy's 150 Card deck" below), comfortably covering
  that target outright. `quickDraftMinCustomPoolSize()` (the floor for a
  `'custom'` pool) is one structure-deck's worth (45) per 2 seated
  players, rounding up -- 45 for 2p, 90 for 3-4p -- matching exactly what
  the doubled built-in `structure` pool source now actually produces.
- **jceddy's 150 Card deck** (`buildJceddys150DeckCardIds()`,
  `JCEDDYS_150_DECK_RARITY_SPEC`) -- shared by Quick Draft's and Grid
  Draft's own 4-player `jceddys_75` pool sourcing (both go through the
  same `buildDraftPool()`). Not a literal doubling of jceddy's 75 Card
  deck's own 75-card pool (which would just duplicate the same 75 card
  ids) -- its own themed 150-card construction, every one of jceddy's 75
  Card deck's own per-color count/max-copies pairs doubled: 2 Mythics, 4
  *different* Rares, 8 Uncommons (up to 2 copies of any one), and 16
  Commons (up to 3 copies of any one) per color, 30 cards per color, 150
  total across the same 5 colors (10 Mythics/20 Rares/40 Uncommons/80
  Commons overall) -- matching the spec given for issue #189.
- **No fixed max deck size.** `submitDraftDeck()`/`quickDraftStateFor()`
  no longer cap Quick Draft's own deck at a flat 16 -- like Winston
  Draft and Grid Draft, the max is simply however many cards that player
  actually drafted (16 for 2p/4p, 18 for 3p). A player is always free to
  trim further down to the shared `QUICK_DRAFT_MIN_DECK_SIZE` (12) floor.
- **3-4 player matches are single-game.** `draftGamesToWin($playerCount)`
  returns 1 for more than 2 players (still 2 for exactly 2) -- the
  `draft_matches`/`draft_match_players` wrapper is reused as-is rather
  than duplicated, just with a games-to-win of 1, so a 3-4 player match
  completes the moment its one game does; `advanceDraftMatch()`,
  `draftMatchSummaryFor()`, and `quickDraftStateFor()` all read this
  instead of the flat `DRAFT_GAMES_TO_WIN` constant.
- **API shape.** `POST /games/draft/pick`'s `stage` field is now an
  integer (1..the match's own player count) instead of the string
  `'draw'`/`'received'`. `getState()`'s `quick_draft` field gains a
  `players` array (every seated player's own `user_id`/`username`/
  `wins`/`is_you`) alongside the existing `your_wins`/`opponent_wins`
  (kept, but only ever reflect the first non-viewer seat for 3-4
  players -- a multiplayer-aware frontend should read `players`
  instead); `quick_draft.drafting` gains `total_stages`/`pass_direction`
  and replaces the old `stage`/`awaiting_opponent_*` string enum with an
  integer `stage` plus a `status` of `'picking'`/`'awaiting_others'`;
  `quick_draft.deck_building` gains `other_players` (every other seated
  player's own `user_id`/`username`/`submitted`), with
  `opponent_submitted` kept as a single-value fallback from the first of
  them. `draft_match` (the lobby-grouping summary) gains the same
  `players` array.

### Winston Draft

`deck_type: 'winston_draft'` (issue #89) is the second `format: 'draft'`
deck type, reusing as much of Quick Draft's own infrastructure as
possible: the same `draft_matches`/`draft_match_players` tables (`format`
stays `'draft'`; `games.deck_type = 'winston_draft'` is the only thing
distinguishing which variant a match belongs to), the same best-of-three
match-progression hook (`advanceDraftMatch()`), and the same deck-building/
sideboard endpoint (`submitDraftDeck()`). What's genuinely different is the
draft mechanic itself: instead of Quick Draft's simultaneous blind
pack-passing, Winston Draft is a strictly alternating, single-active-
player pile game with zero simultaneity.

**The mechanic** -- a shared pool (`winstonDraftPoolTargetSize($playerCount)`
-- 45/70/90 for 2/3/4 players, the 2-player case matching the physical
rules' own "Total number of cards drafted: 45" and the Structure deck's
own size) is shuffled and dealt into 3 face-down piles of 1 card each, off
the top of the remaining deck. Players strictly alternate turns; each
turn always starts at Pile 1, then 2, then 3, in fixed order:

1. Look at the current pile (only the active player sees its contents --
   its *size* is visible to both players even face-down, like a real
   stack of cards).
2. **Take**: claim every card in the pile into your own
   `drafted_card_ids` (picks are written incrementally, one decision at a
   time -- there's no Quick-Draft-style "finalize at the end" step). The
   pile refills with 1 fresh card from the deck, if the deck has one.
   Your turn ends.
3. **Pass**: the pile grows by 1 fresh card from the deck, if able, and
   you look at the next pile. Declining Pile 3 is followed by a
   mandatory, non-optional draw of the deck's top card, if any --
   crucially, Pile 3's own "if able" replenish happens *first*, so if the
   deck has only 1 card left when Pile 3 is declined, that card goes to
   the replenish and the mandatory draw gets nothing. This card is seen
   only by the acting player. Your turn ends either way.

**Termination** -- the draft ends the instant the deck and all 3 piles are
*simultaneously* empty, checked after every take/pass/auto-draw (a `take`
against an already-empty deck can end the draft mid-turn without ever
reaching Pile 3 -- a pile can hold cards without having been drafted yet,
so "the deck is empty" alone is never sufficient). Because every pick is
written the moment it happens, `finalizeWinstonDraft()` has nothing left
to compute once the draft ends -- it only handles the sub-12-card auto-loss
check below and flips the match to `'deck_building'`.

**Data model** -- a new `draft_winston_state` table (migration `0032`, one
row per match) holds the mutable pile/deck/turn state:
`remaining_deck_card_ids` (JSON, ordered -- front = top of deck),
`pile_1_card_ids`/`pile_2_card_ids`/`pile_3_card_ids`, `current_player_user_id`,
`current_pile_number`. Unlike Quick Draft's own deliberate avoidance of a
mutable "remaining pool" blob (that design note was specifically about
*simultaneous blind* submissions racing to update the same row -- see
"Quick Draft" above), Winston Draft has no simultaneity at all: exactly
one player acts at a time, so a plain mutable row, protected by the same
per-game `withGameLock()` every draft mutation already uses, is both
simpler and just as safe here.

**Pool building** -- `buildWinstonDraftPool()` is a thin wrapper around the
same `buildDraftPool()` Quick Draft's own `buildQuickDraftPool()` uses,
parameterized with `winstonDraftPoolTargetSize($playerCount)`/
`winstonDraftMinCustomPoolSize($playerCount)` (45/70/90 for 2/3/4 players,
the latter equal to the former -- there's no reshuffle-top-up mechanic the
way Quick Draft's 45-vs-48 gap needed one, so an undersized custom pool is
rejected outright at creation time) instead of Quick Draft's own flat
48/45 -- same 6 pool sources
(`random_48`/`structure`/`jceddys_75`/`one_of_each`/`custom`/`saved_deck`).
Two of those sources need help reaching the larger 3-4 player targets, both
handled inside the shared `buildDraftPool()` rather than duplicated here:
`structure`'s own fixed 45-card pool is doubled (2 copies concatenated,
then narrowed back down to the target) whenever `$playerCount > 2` --
passed as `buildDraftPool()`'s `$doubleStructureForMultiplayer` flag, set
`true` only by Winston Draft's own call site, so Quick Draft's and Grid
Draft's own `structure`/`grid_draft` handling is completely unaffected --
and `jceddys_75` swaps to `buildJceddys150DeckCardIds()`'s own 150-card
pool at exactly 4 players, the same swap Quick Draft's and Grid Draft's
own 4-player `jceddys_75` case already gets (see "jceddy's 150 Card deck"
above). No reshuffle-top-up mechanic is needed for the base case either:
Winston's minimum *is* its target size, and the physical rules already
treat "the deck runs out" as a normal, expected event rather than a
shortfall to correct.

**The draft itself** (`submitWinstonDraftPick()`, `POST
/games/draft/winston-pick {game_id, action: 'take'|'pass'}`) -- no
`card_ids` needed, since a pile is taken/passed as a whole and the server
already knows both whose turn it is and which pile is current. Rejects
the request if it isn't `$userId`'s turn or the match isn't `'drafting'`.
For 3-4 players, whoever acts next is seat-index rotation
(`$seatIndex = array_search($userId, $userIds, true)`, next is
`$userIds[($seatIndex + 1) % $playerCount]`) rather than a fixed
2-player toggle -- mathematically identical to the original toggle when
`$playerCount === 2`, so existing 2-player games are unaffected. The pile
mechanic itself (3 fixed piles, take/pass across them in order, mandatory
auto-draw on declining pile 3) is unchanged for any player count -- only
whose turn it is generalizes.

**Short players at 3-4 (issue #189)** (`finalizeWinstonDraft()`) -- the
physical rules are explicit for 2 players: "If you don't have twelve
cards, you will automatically lose any game." For 3-4, only the players
who actually came up short of `WINSTON_MIN_DECK_SIZE` (12) are excluded --
the rest proceed to `deck_building` as a smaller match, rather than the
whole match ending the moment even one player is short:
- **0 short players** -- proceeds to `deck_building` exactly as before,
  for any player count.
- **Exactly 1 survivor** (either the original 2-player case, or 3-4
  players minus everyone-but-one coming up short) -- degenerates to the
  original rule: the match completes immediately with the survivor as
  `winner_user_id`, and the match's own game-1 row (already inserted
  synchronously back at `createGame()` time) is marked `'abandoned'`
  rather than left stuck `'waiting'` forever. `recordMatchCompletionStats()`
  runs *before* the short players' rows are removed (see below), so they
  still get `match_losses` credited exactly as a 2-player auto-loss
  always has.
- **2+ survivors** -- no match-level outcome is recorded at all (there's
  no winner yet); each short player is removed via
  `removeShortWinstonDraftPlayer()`, which deletes their
  `draft_match_players` and `game_players` rows outright (safe because
  game 1 hasn't started yet at this point -- no `game_cards`/`game_rounds`
  row anywhere references that seat, and every `seat_order`-driven query
  already orders by the column rather than indexing arithmetically off
  its value, so the gap it leaves behind is harmless). The survivors then
  proceed to `deck_building` as a genuine (N - short-count)-player match,
  as if the short players were never part of it -- no stats are
  attributed to them for this outcome.

No dedicated frontend handling was needed for either terminal path (0 or
1 survivor) -- both reuse the exact same "match/game completed, show the
winner" rendering the lobby and board already use for every other format;
the 2+-survivors path likewise reuses the existing `deck_building`
rendering unchanged, just for fewer seats than the match started with.

**State exposure** -- `getState()`'s `winston_draft` field (`null` for
every other deck_type) mirrors `quick_draft`'s own shape (an always-present
match scoreline plus whichever of `drafting`/`deck_building` is currently
live), plus a `players` array (every seated player's own `user_id`/
`username`/`wins`/`is_you`) and `games_to_win` now resolved via the same
`draftGamesToWin($playerCount)` helper Quick Draft uses, rather than a
hardcoded constant. `drafting` (`winstonDraftDraftingStateFor()`) has
genuinely different contents from Quick Draft's own: `is_your_turn`,
`current_turn_username` (whichever seated player acts next -- for 3-4
players, "not your turn" alone doesn't say whose turn it actually is),
`current_pile_number`, `pile_sizes` (an array of 3 ints, always visible to
every player), `remaining_deck_count`, `current_pile_cards` (populated
only when it's your turn -- `[]` otherwise, never leaking the pile you
can't currently see), `drafted_so_far` (always your own accumulated
picks, never anyone else's), and an `other_players` array (issue #189 --
every OTHER seated player's own `user_id`/`username`/
`drafted_card_count`/`last_take_pile_number`/`last_drew_from_deck`),
alongside the original singular `opponent_last_take_pile_number`/
`opponent_last_drew_from_deck`/`opponent_drafted_card_count` fields kept
as a backward-compatible fallback (derived from `other_players[0]`). All
of these are safe to expose without ever revealing what's actually on any
card: which numbered pile a player last claimed (or that they declined
everything and drew from the deck instead), and how many cards they've
accumulated, are all things a real opponent watching across the table
would already see for themselves (a taken pile's height and a rival's
growing stack of face-down cards are physically visible, unlike what's
printed on them). Tracked on
`draft_winston_state.last_draft_action_by_user_id` (a JSON map, `user_id
=> pile_number | "deck"`, migration `0035`, widened by `0036`) rather
than a single "the last action, whoever it was" column -- turns strictly
rotate through every seat and any player can pass any number of times
before eventually ending their turn, so from another player's own
perspective "their last action" can be several turns back, and only a
per-user_id lookup answers that correctly. `submitWinstonDraftPick()`
updates this map on both turn-ending outcomes: a `'take'` records the
pile number, and a `'pass'` on pile 3 (which always ends the turn, take
or no take from the auto-draw) records the string `"deck"` -- a plain
`'pass'` on pile 1 or 2 leaves it untouched, since that doesn't end the
turn. `deck_building` is the exact same shared shape Quick Draft uses
(`draftDeckBuildingStateFor()`), just called with `WINSTON_MIN_DECK_SIZE`
(12) and no fixed max -- `max_deck_size` resolves to however many cards
that specific player actually drafted, since the total varies by how the
pile draft unfolds (unlike Quick Draft's guaranteed-per-player count).

### Grid Draft

`deck_type: 'grid_draft'` (issue #188) is the third `format: 'draft'` deck
type, reusing the same `draft_matches`/`draft_match_players` tables and
best-of-three/deck-building infrastructure as Quick Draft and Winston Draft
(`games.deck_type = 'grid_draft'` is the only thing distinguishing which
variant a match belongs to). What's genuinely different is the draft
mechanic: a grid of face-up cards, dealt fresh every round, with each
player in turn taking an entire row or column.

**Grid size and rounds vary by player count** -- `gridDraftGridSize($playerCount)`
is 4 for exactly 4 players, 3 otherwise (2-3 players), and
`gridDraftRounds($playerCount)` is 4 for exactly 4 players, 6 otherwise.
The 4-player case (4x4 grid over 4 rounds) was chosen specifically so that
seat-rotation of who picks first (`($firstPickerSeatIndex + 1) % $playerCount`,
see below) gives each of the 4 players first pick in exactly 1 of the 4
rounds -- the original 3x3-over-6-rounds shape, kept for 2-3 players, would
have given a 4th player first pick in only 1.5 rounds on average, an
uneven distribution. `gridDraftCardsPerRound($playerCount)` is the grid's
own cell count (`gridDraftGridSize($playerCount) ** 2` -- 9 or 16), and
`gridDraftRefillBatchSize($playerCount)` (a row/column's own length) is
just `gridDraftGridSize($playerCount)` again (3 or 4).

**The mechanic** -- a shared pool of exactly `gridDraftPoolTargetSize($playerCount)`
cards (54/72/96 for 2/3/4 players) is shuffled once at the start of the
match. Over exactly `gridDraftRounds($playerCount)` rounds,
`gridDraftCardsPerRound($playerCount)` cards are dealt face-up into the
grid, refilled as the round's picks happen (see below) so the pool always
runs out exactly when the final round is dealt, with no remainder to
reshuffle or top up (unlike Quick Draft's round-4 top-up, or any pool-size
shortfall handling at all). Round 1's first picker is chosen at random;
every subsequent round, seats rotate one position
(`($firstPickerSeatIndex + 1) % $playerCount`) so first-pick duty is
shared evenly across the match. Each round has exactly `$playerCount`
sequential picks, one per seated player in turn:

1. Each pick takes an entire row or column -- however many of its cells
   are still non-null (the grid's own cells are the only source of truth
   for this, no axis/index bookkeeping needed -- see below).
2. **Refilling (issue #189)**: after a pick, the cells it just cleared are
   immediately refilled with fresh cards from the deck, *except* for the
   round's last two picks -- `picksThisRound <= max($playerCount - 2, 0)`.
   For 2 players this is never true past pick 1 (`max(0, 0) == 0`), so a
   2-player round still behaves exactly as before: the first pick always
   takes a full row/column from a freshly-dealt grid, and the second pick
   takes whatever's left with nothing ever refilled mid-round. For 3
   players, only the round's first pick refills; for 4, the first two
   picks do. This is the only mathematically consistent reading of the
   spec's own stated pool sizes (54/72/96 -- i.e. 9/12/24 cards drawn per
   round for 2/3/4 players).
3. Whatever remains in the grid at the round's end is simply discarded --
   never reshuffled back into the pool, unlike Winston Draft's own
   pile-and-deck cards.

**Deriving a pick's card count** -- rather than store which axis/index a
previous pick used and compare it against the current pick's own choice,
each of the grid's cells is tracked as JSON `null` the instant any player
takes it (`draft_grid_state.grid_card_ids`, a `gridDraftCardsPerRound($playerCount)`-element
row-major array, index = row * `gridDraftGridSize($playerCount)` + column).
A pick's own card count is then just however many of its target cells are
still non-null at the moment it's made, derived purely by counting, with
no axis-comparison logic anywhere -- this generalized cleanly from 2
players to N without touching the overlap math at all. A pick that would
take 0 cards (choosing a line already fully cleared and not yet refilled)
is rejected with a `409`.

**Data model** -- a new `draft_grid_state` table (migration `0034`, one row
per match) holds: `remaining_deck_card_ids` (the not-yet-dealt portion of
the pool), `current_round`, `grid_card_ids` (the current round's cells,
row-major, `null` for a taken-and-not-yet-refilled cell),
`first_picker_user_id` (whoever goes first *this* round),
`picks_this_round` (migration `0060`, issue #189 -- how many picks have
happened so far this round, reset to 0 at the start of every round;
replaces the original `first_pick_axis`/`first_pick_index` columns, which
only ever existed to derive a single "is this the round's first or second
pick" boolean and were never read by the actual overlap math above -- a
plain N-generalizable counter does the same job for any player count), and
`current_turn_user_id` (whoever acts next -- advanced by seat-index
rotation, `($seatIndex + 1) % $playerCount`, the same formula degenerating
to the original 2-player toggle when `$playerCount == 2`). Like Winston
Draft (and unlike Quick Draft's simultaneous blind picks), Grid Draft has
no simultaneity -- exactly one player acts at a time -- so a plain mutable
row behind the same per-game `withGameLock()` every draft mutation already
uses is both simpler and just as safe here.

**Pool building** -- `buildGridDraftPool()` wraps the same shared
`buildDraftPool()` Quick Draft/Winston Draft use, parameterized with
`gridDraftPoolTargetSize($playerCount)`/`gridDraftMinCustomPoolSize($playerCount)`
(both 54/72/96 for 2/3/4 players). Unlike the other two draft variants, a
pool source that comes up short of the target isn't merely allowed through
and dealt with (there's no top-up mechanism to fall back on) --
`buildGridDraftPool()` explicitly rejects any pool under the target with a
`409`. This specifically excludes the `'structure'` pool source (45 cards,
short even of the 2-player 54 minimum) from Grid Draft, even though the
same `pool_source` enum column is shared with Quick Draft/Winston Draft,
both of which accept it fine. `jceddys_75` doesn't need this rejection at
4 players -- `buildDraftPool()` itself already swaps in jceddy's 150 Card
deck's own 150-card pool for that case (see "jceddy's 150 Card deck" in
"Quick Draft"'s own "Multiplayer" section above), the same swap Quick
Draft's own 4-player `jceddys_75` pool goes through -- 2-3 players use the
plain 75-card pool, already large enough.

**The draft itself** (`submitGridDraftPick()`, `POST /games/draft/grid-pick
{game_id, axis: 'row'|'column', index: int}`) -- rejects the request if it
isn't `$userId`'s turn, the match isn't `'drafting'`, `axis`/`index` are
invalid (`index` must be within `0` to `gridDraftGridSize($playerCount) - 1`),
or the chosen line has 0 cards left. Completing a round's last pick either
deals the next round's fresh grid (rotating who picks first) or, after the
match's final round (`gridDraftRounds($playerCount)`), ends the draft and
flips the match to `'deck_building'` -- there's no auto-loss path the way
Winston Draft has, since Grid Draft's mechanic always yields well above
`GRID_DRAFT_MIN_DECK_SIZE` (12) cards per player.

**State exposure** -- `getState()`'s `grid_draft` field (`null` for every
other deck_type) mirrors `quick_draft`'s/`winston_draft`'s own shape (an
always-present match scoreline, a `players` array for every seated player,
plus whichever of `drafting`/`deck_building` is currently live; `games_to_win`
is `draftGamesToWin($playerCount)`, same as the other two draft variants).
`drafting` (`gridDraftDraftingStateFor()`) is `is_your_turn`,
`current_turn_username` (whoever `current_turn_user_id` actually is -- for
a 3-4 player match, "not your turn" alone doesn't say whose turn it is),
`current_round`, `total_rounds` (`gridDraftRounds($playerCount)`),
`grid_size` (`gridDraftGridSize($playerCount)` -- 4 for exactly 4 players,
3 otherwise, so the frontend knows the grid's own dimensions),
`first_picker_user_id`, `picks_this_round`, `total_picks_per_round` (the
seated player count), `grid_cards` (all `grid_size ** 2` cells, always
fully visible to every player -- unlike Winston Draft's face-down piles, a
dealt grid is face-up on the table -- with a `null` entry for any cell
already taken and not yet refilled this round), `remaining_deck_count`,
`drafted_so_far` (your own accumulated picks), and
`other_players_drafted_so_far` (issue #189 -- an array of every *other*
seated player's own `user_id`/`username`/`drafted_so_far`, 1 entry for a
2-player match, up to 3 for 3-4 players; `opponent_drafted_so_far` is kept
as a single-value fallback, the first of them). Unlike Quick Draft's/
Winston Draft's own `drafted_so_far` (each strictly the viewer's own picks,
never anyone else's -- their drawn packs/piles are genuinely hidden), Grid
Draft is open information end to end: every card any player has ever
drafted was already visible to everyone else the moment it was dealt into
the face-up grid, so there's no game-integrity reason to hide anyone's own
drafted-so-far list from anyone else. `deck_building` is the same shared
shape Quick Draft/Winston Draft use (`draftDeckBuildingStateFor()`), called
with `GRID_DRAFT_MIN_DECK_SIZE` (12) and no fixed max, same rationale as
Winston Draft's own open-ended range -- and, for 3-4 players, the same
`other_players` array Quick Draft's own multiplayer support added.

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

### Past games (issue #84)

`GET /games` used to return every game you're seated in, `completed`
ones included -- forever, with no way to clear them out. `listGamesForUser()`
now excludes `completed` (and, per a later follow-up, `abandoned`) games
(`GameService::listGamesForUser()`), and a new `listPastGamesForUser()`
returns exactly the complement (`GET /games/past`), so a finished game
always shows up in exactly one of the two lists, never both and never
neither.

The one exception: a `completed`/`abandoned` game that's still part of a
best-of-three `quick_draft`/`winston_draft`/`grid_draft` match whose OTHER
game(s) haven't decided the match yet stays in `GET /games` rather than
moving to `GET /games/past` -- a finished game 1 of an in-progress match is
still very much part of what's currently being played, not history. This is
implemented with a single check against `draft_matches.status`: that
column only ever reaches `'completed'` once a match winner is actually
determined (see "Quick Draft"/"Winston Draft"/"Grid Draft" below,
and `draftMatchSummaryFor()`'s own docblock) -- it has no
`'in_progress'`-equivalent value, staying at `'deck_building'` for the
entire duration each individual game is being played. So `GET /games`'s
own query is: every `waiting`/`in_progress` game, plus a `completed`/
`abandoned` one only if `draft_match_id IS NOT NULL AND
draft_matches.status != 'completed'`. `GET /games/past` is the exact
opposite condition. Once the match's last game decides a winner, both
(or all three) of its games move to `GET /games/past` together in the
same instant `draft_matches.status` flips to `'completed'` -- the lobby
UI's own match-grouping (see "Lobby grouping" above) renders identically
in either list, so nothing about a decided match's own display changes,
only which list it's found in.

An `'abandoned'` game (a Quick/Winston/Grid Draft match that ended with no
game ever actually played -- a mid-drafting resignation, or every player
coming up short of the deck-building minimum) moves to `GET /games/past`
the same way, and just as immediately: `resignFromDraftMatch()`/
`abandonDraftMatch()`/`finalizeWinstonDraft()` all flip
`draft_matches.status` to `'completed'` in the very same statement that
marks the game `'abandoned'`, so the draft-match-undecided carve-out above
never actually applies to it in practice -- it's simply gone from the main
lobby and in Past games from the moment the resignation happens, same as a
naturally-completed game with no sibling in progress. It has no
`completed_at` of its own (only the `draft_matches` row does), so it sorts
by `last_move_at` in `GET /games/past` instead.

### Cleanup cron (issue #84)

Past games alone doesn't actually delete anything -- an old game just
moves to a different list forever. `bin/expire_and_delete_stale_games.php`
(meant to run once a day via cron) is the follow-up that actually cleans
up the database, in two passes over the same staleness definition every
other "how stale is this game" check in this file already uses
(`COALESCE(last_move_at, started_at, created_at)`):

1. **`GameService::deleteStaleCompletedGames(int $olderThanDays = 7)`** --
   permanently `DELETE`s every `'completed'` game whose last activity is
   older than `$olderThanDays`. An outcome that's already settled and
   hasn't been looked at in over a week has nothing left worth keeping.
   `DELETE FROM games` cascades (`ON DELETE CASCADE`, directly or
   transitively via `game_players`/`game_rounds`/
   `game_pending_decision_batches`) to every other per-game table --
   `game_players`, `game_rounds`, `game_round_scores`, `game_cards`,
   `game_events`, `game_pending_decision_batches`, `game_team_decisions`,
   `game_initial_card_passes`, `game_notes` -- see `database/README.md`.
   A Quick/Winston/Grid Draft match (`draft_matches`) has no FK pointing
   back to `games`, so it's untouched by that cascade; this method
   separately deletes any `draft_matches` row left with zero games still
   referencing it (which itself cascades to
   `draft_match_players`/`draft_round_picks`/`draft_winston_state`/
   `draft_grid_state`), so a match never outlives every game that was
   ever part of it.
2. **`GameService::expireStaleActiveGames(int $olderThanDays = 7)`** --
   any game NOT already `'completed'` (so `'waiting'`/`'in_progress'`/
   `'abandoned'`) whose last activity is ALSO older than
   `$olderThanDays` gets force-ended instead of deleted outright: a
   `'game_expired'` event is logged (`describeEvent()` renders it as
   "This game was automatically ended due to inactivity" -- its own
   explicit `match` arm, since `acting_game_player_id`/`card_id` are both
   `null` and the generic "{actor} played {card}" default would
   otherwise misrender it) and `status` flips straight to `'completed'`
   with `completed_at` set, but no winner (`winner_game_player_id`/
   `winner_team_id` are both left `null`) -- `gameSummaryFor()`'s/the
   board's own "Game over" line already handle a completed game with no
   winner cleanly (same code path a resigned/tied game can already hit).
   Deliberately does NOT try to gracefully wind down whatever mid-round
   state the game was frozen in (an open `game_rounds` row, a pending
   decision, cards still in hand/in play) the way `resignGame()` does for
   a player-initiated resignation -- nobody is coming back to finish a
   game this stale, so there's nothing left to reconcile, just a status
   flip. The now-completed game composes automatically with everything
   above: it moves straight to `GET /games/past` on its own (or stays in
   `GET /games` if it's mid an undecided draft match, exactly like a
   naturally-completed game 1 would), and will itself become eligible for
   deletion by pass 1 above on some future run once it's been stale for
   long enough in turn. Each candidate game is re-checked for staleness a
   second time from inside `withGameLock()` right before mutating it, in
   case a player's own action raced with the cron between the initial
   `SELECT` and the lock being acquired.

Both methods return an `int` count of games affected, and
`bin/expire_and_delete_stale_games.php` prints a one-line summary.
Example crontab line (once daily, 3am):

```
0 3 * * * /usr/bin/php /path/to/php-app/bin/expire_and_delete_stale_games.php >> /var/log/moodswings-game-cleanup.log 2>&1
```

### Game log (issue #98)

`GET /games/log` (`GameService::fullEventLog()`) returns a game's entire
`game_events` history, oldest first, with no limit and no pagination --
`GET /games/state`'s own `recent_events` field (`recentEvents()`) exists
purely as a "smallish panel" for the board's own "Recent plays" section
(newest first, hardcoded to the last 15), and was never meant to be the
only way to see what happened earlier in a long game. The two share the
same `describeEvent()` rendering, so their phrasing can never drift out
of sync -- `fullEventLog()` just additionally exposes each entry's own
raw `event_type`/`round_number`/`acting_game_player_id`/`card_id`/
`details` alongside the resolved `acting_username`/`card_name` and the
rendered `description`, since it's also the one response the frontend's
"download data" button turns directly into a JSON export (see
"Game log" in `web-static/README.md`) -- worth including enough raw data
for that export to be genuinely useful offline, not just a repeat of the
text the "copy text"/"download text" buttons already cover. No
per-viewer filtering, same as `recentEvents()` itself (every event is
already visible to every seated player regardless of who triggered it,
e.g. Paranoia's own reveal) -- `requireGamePlayer()`'s ordinary seated
check is the only access control this needs.

A typical game's event count (rarely more than a few hundred rows even
for a long multiplayer game) is small enough that returning the whole
thing in one response was judged simpler than adding pagination for a
case that doesn't actually need it.

### Download complete game data (issue #99)

`GET /games/export` (`GameService::exportGameData()`) is a different
scope than "Game log" above: that one is a curated, human-readable
rendering of `game_events` alone; this one is a raw, complete dump of
every DB row related to a game, across every table with any FK
relationship (direct or transitive) to `games.id` --
`game_players`/`game_rounds`/`game_round_scores`/`game_cards`/
`game_events`/`game_pending_decision_batches`/`game_pending_decisions`/
`game_team_decisions`/`game_initial_card_passes`, plus the requesting
player's own `game_notes` row (never another seated player's -- see
below) and, for a Quick/Winston/Grid Draft game, a `draft_match` section.
Directly motivated by a future "clean up old completed/abandoned games"
feature (issue #84): once that lands, this needs to already exist so a
player who cares about a specific game has a way to keep an offline copy
before it's gone.

Access is deliberately narrower than every other "view this game" route:
seated players only (`requireGamePlayer()`, the same gate `GET
/games/state` uses), no spectator path at all -- this is meant as one
player's own personal archive, not a shareable view.

Every JSON-typed column (`game_cards.effect_state`,
`game_events.details`, `game_pending_decisions.field`/`answer`, every
`*_card_ids`/`*_game_player_ids` column, etc.) is decoded into a real
nested value rather than left as an escaped string -- MySQL's `JSON`
column type has no native PDO binding, so a plain `SELECT *` would
otherwise return each of these as JSON-inside-JSON. Which columns get
this treatment is an explicit per-table list
(`GameService::JSON_COLUMNS_BY_TABLE`) rather than a heuristic ("decode
any string starting with `{`/`[`"), specifically so a private note
(`game_notes.note_text`) that happens to look like JSON is never
misinterpreted.

`game_notes` is scoped to only the *requesting* player's own note --
it's a deliberately private per-seat scratchpad (see
`GameNoteRepository`), and triggering an export has no business
revealing what a different seated player privately wrote to themselves.

For a Quick/Winston/Grid Draft game (`games.draft_match_id` set), the
export additionally includes `draft_match`/`draft_match_players`/
`draft_round_picks`/`draft_winston_state`/`draft_grid_state`. One
`draft_matches` row is shared across up to 3 `games` rows in a
best-of-three match (see migration 0027's own docblock), so this section
reflects the *whole* match's draft history, not just the exported game's
own slice of it -- the sibling games' own `games`/`game_players`/etc rows
are never included, only the requested game's.

The lobby's "Download data" button (next to "View log") fetches this and
hands it straight to the same `downloadFile()` helper the game log's own
"download data" button already uses, saving it as
`game-<id>-export.json`. Only shown for a `'completed'` game, matching
"Watch replay"'s own gating -- a frontend-only restriction (the endpoint
itself has no such check), since archiving is meant for a game that's
truly done, not one whose data is still changing.

### Shared deck view (issue #197)

`GET /games/deck` (`GameService::viewSharedDeck()`) returns every card in
a shared-deck game's single deck -- every `deck_type` where the whole
table draws from one pool rather than each player having their own
(`structure`/`power`/`jceddys_75`/`custom`/`one_of_each`, as opposed to
`custom_duel` and the three draft-based deck_types, which each give every
player their own separate deck and have no single "the deck" for this to
show -- see `GameService::isSharedDeckType()`). Today, the board only
ever shows a deck *count* (`Deck: N cards left`) and, for `custom`, the
deck's name -- never its actual contents.

Read back from the game's own persisted `game_cards` rows across every
zone (`deck`/`hand`/`in_play`/`discard` combined = the entire deck, since
every row is created once at `POST /games/start` time and nothing is
ever added to `game_cards` afterward -- see `BoardStateRepository`'s own
docblock), **not** recomputed by re-calling `buildStructureDeckCardIds()`
etc. -- those pick randomly, so calling them again after the game has
already started would show a different random deck than the one actually
dealt, not the real one. (`custom`'s own `custom_deck_card_ids` is
already stable without this, but reading `game_cards` uniformly here is
simpler than special-casing it.) `409` if the `deck_type` has no single
shared deck, or the game is still `waiting` -- nothing's been dealt yet,
so there's no deck to show.

Authorized the same way as any seated player's own board view, plus
spectators (issue #128) -- `viewSharedDeck()` itself takes no viewer at
all, so once `canSpectateGame()` (see "Spectator mode" below) lets someone
through, they see exactly the same deck contents a seated player would.

Sorted white/blue/black/red/green, then alphabetically by name within a
color, so the same deck always lists the same way regardless of what
order its cards happened to get dealt/shuffled in -- not a per-viewer
concern the way `GET /games/state` is, so (like `GET /games/log`
immediately above) this only needs `requireGamePlayer()`'s ordinary
seated check, nothing further. A duplicate catalog card (e.g. a
`jceddys_75` deck's own up-to-2-copies-per-card slots, see
`randomCardIdsWithCopyLimit()`) appears as two separate entries, matching
`GET /decklists/view`'s own convention for a saved decklist rather than
collapsing into one entry with a count.

See "Shared deck view" in `web-static/README.md` for the frontend side
(the board's own "View decklist" button, and the lobby list's per-row
one).

### Resigning

`POST /games/resign` (`GameService::resignGame()`) lets a seated player
give up on an `in_progress` game instead of playing it out. What happens
next depends on the format and how many players are left:

- **2-player games** (`duel`, and `draft`'s own `quick_draft`/
  `winston_draft`) **and every team-format game** (`team`, `closed_team`
  -- always exactly two opposing sides; a 2v2 team is atomic, so there's
  no partial-team version of this) **complete the whole game
  immediately**, crediting whoever's left -- the opposing team via
  `winner_team_id` (with a representative `winner_game_player_id`, same
  convention as a normal team win -- see "Open Team Play" below), or the
  sole remaining player otherwise. This works exactly like a normal
  round-ending win (`completed_at`/`winner_*` set, `advanceDraftMatch()`
  run for a `quick_draft`/`winston_draft` game so best-of-three match
  progression still advances correctly on a resign-induced win), except
  the round in progress is *abandoned* (`game_rounds.status = 'abandoned'`,
  a status introduced by migration `0033` specifically for this) rather
  than actually scored.
- **`standard` format is the one case that supports 3-4 players**, and
  for that case resigning does **not** end the game: the resigning
  player is marked out (`game_players.resigned_at`), their future turns
  are automatically skipped, and they're permanently excluded from ever
  being credited a round or game win -- but everyone else keeps playing
  toward a normal `wins_needed` finish. This only actually reduces the
  active player count by one at a time; if resignations eventually leave
  only one active player, the next one completes the game the same way
  a 2-player game's own resignation always has.

Every play/pass already gates on `currentRound()` finding an
`'in_progress'` round for the game -- an immediate-completion resign
abandons that round specifically so nothing can be played against an
already-finished game afterward. The "continue without them" path never
needs that: the round stays `'in_progress'`, but `advanceTurn()`'s own
turn-order (`turnOrderForRound()`) is filtered to active (non-resigned)
players, so a resigned player is simply never handed a turn, and
`finishScoringAndAdvance()`'s winner/Hurt Feelings selection is narrowed
the same way so they can never be picked as either, no matter how their
own board state happens to score. Resigning while a decision is pending
is disallowed (mirrors `playMood()`/`pass()`'s own
`assertNoPendingDecision()` gate) -- resolve the decision first.

For the "continue without them" `standard` 3-4 player path specifically
(the immediate-completion paths above just end the game outright, so
there's no ongoing board for a resigned player to keep interacting with),
`GameService`/`BoardState` also make sure a resigned player stops being a
live participant in every other sense a card effect can reach:

- **Their in-play moods and hand both go to the bottom of their own
  deck.** `GameService::resignGame()` calls
  `removeResignedPlayerCardsFromBoard()` right before skipping their turn,
  which moves every mood they own via `moveInPlayToBottomOfDeck()` and
  every card in their hand via `moveHandToBottomOfDeck()` -- not the
  discard pile, since a resignation isn't a scoring event and shouldn't
  feed discard-pile-driven effects (Altruism, Corruption, etc.) the way an
  ordinary discard would. `moodsOwnedBy()`/`hand()` both already return a
  snapshot copy (PHP array value semantics), so looping over either one
  stays safe even though the two move methods mutate $state's own
  underlying maps as they go.
- **They can never be chosen as a card effect's target.** `BoardState`
  gets a new `resignedPlayerIds` constructor param (`game_players.id` of
  every resigned seat, threaded in by `BoardStateRepository::load()` from
  `game_players.resigned_at`, empty and therefore a no-op for every game
  with no resignations) and three new methods built on it: `isResigned()`,
  `activePlayerOrder()` (`playerOrder()` minus resigned seats, relative
  order preserved), and `activeNeighbor()` (below). Every `Effects/*.php`
  class's own "is this a legal player target" check
  (`in_array($id, $state->playerOrder(), true)`) now checks
  `activePlayerOrder()` instead, and every "ask every player something"
  loop (Disillusionment's color-choice queue, Avoidance/Confusion's
  per-player give-a-card(/mood) decisions, Fury's per-player discard
  choice, Pride's "players with more moods than you" candidate list) now
  sources from `activePlayerOrder()` too, so a resigned player is neither
  offered as a choice nor asked anything.
- **A decision that would freeze the round waiting on them never gets
  created.** This falls directly out of the previous point: every
  `RequiresOpponentDecision` implementer that targets "a player" or "every
  player" now excludes resigned seats from that same candidate set, so a
  pending decision batch is never created naming a player who has no way
  to ever answer it.
- **"Pass to the next player" effects skip over them.** `Avoidance`
  (moods), `Confusion` (hand cards), and `Rationalization`'s `rotate` mode
  (whole hands) each used to compute their own left/right neighbor with
  identical inline `%count` seat-index arithmetic against the raw
  `playerOrder()`. That's now centralized in
  `BoardState::activeNeighbor(int $playerId, string $direction): ?int`,
  which walks `activePlayerOrder()` instead -- a resigned player's
  "neighbor" is simply the next still-active seat in that direction, so a
  pass that would have landed on them continues on to whoever's next
  instead. Returns `null` if `$playerId` isn't currently active, or if
  fewer than 2 players are still active (nowhere to pass to) -- both of
  those effects treat `null` as "nothing to give this player," the same
  as an ordinary empty hand/no-moods skip.
- **`activeNeighbor()`'s `'left'`/`'right'` match a real table, not raw
  seat-index direction.** `'left'` is the next seat forward in seat order
  (index + 1); `'right'` is the previous seat (index - 1). This matches
  `GameService::rotate()`'s own "ascending seat_order is clockwise"
  convention: at a physical round table, the next player clockwise from
  you (i.e. the next player to act) sits at your own left hand -- the
  same reason the positional in-play board (issue #124,
  `inPlayZoneAssignments()` in `game.js`) draws that same next-turn-order
  seat at the viewer's own screen-left. Before a fix, `activeNeighbor()`
  had these backwards (`'right'` was next-turn-order), so choosing
  "right" on Avoidance/Confusion/Rationalization sent the pass to
  whoever the board showed on the viewer's *left*, and vice versa --
  reported as a board-rendering bug, but the board was already correct;
  the rules engine's own left/right labels were inverted relative to it.
- The frontend's own `fieldOptions()` (`case 'player'` in `game.js`)
  additionally filters out any player already flagged `resigned` in
  `getState()`'s response, so a resigned player never even appears as a
  selectable option client-side -- purely a UI convenience layered on top
  of the server-side enforcement above, which is what actually matters.

#### Resigning from a draft match (issue #144)

Everything above only ever applied once a game reaches `'in_progress'` --
`resignGame()` used to throw outright for a `quick_draft`/`winston_draft`/
`grid_draft` match still sitting `'waiting'` through its own drafting or
deck-building phase, since there was no way to give up before a real game
even started. `resignGame()` now checks for this case FIRST, before its
existing `status !== 'in_progress'` guard: a `'waiting'` game whose
`deck_type` is one of the three draft types and has a `draft_match_id`
delegates to a new private `resignFromDraftMatch()` instead, which splits
on exactly where the match currently is:

- **Still `'drafting'`.** No graceful "drop this player, everyone else
  keeps going" is possible here -- Quick Draft's own pile-passing math
  (`submitQuickDraftPick()`'s `$playerCount`-dependent seat-index
  arithmetic) is fixed for the whole round the instant it's dealt, and
  Winston/Grid Draft each have exactly one player's turn "in flight" at a
  time with no defined meaning for handing their half-finished turn to
  someone else. So a mid-drafting resignation always ends the WHOLE match
  for every seated player, via a new `abandonDraftMatch()` helper:
  `draft_matches.status = 'completed'` with `winner_user_id = NULL`, and
  the match's own currently-`'waiting'` `games` row (passed in as the
  exact `$gameId` resigning, not hardcoded to `match_game_number = 1` --
  see below) becomes `'abandoned'`.
- **`'deck_building'`.** Drafting itself has already fully finished, so
  there's no shared pile/turn state left to disrupt -- each player's own
  deck submission is a completely independent operation. This is exactly
  `finalizeWinstonDraft()`'s own "a short player is dropped, survivors
  continue" scenario (see below), so it's handled identically: 2+
  remaining players just continue as their own (N-1)-player match, via a
  renamed/generalized `removeDraftMatchPlayer()` (was
  `removeShortWinstonDraftPlayer()`, used by both callers now); exactly 1
  remaining player wins the match outright
  (`recordMatchCompletionStats()`, same as `finalizeWinstonDraft()`'s own
  1-survivor branch, plus the same `abandonDraftMatch()`-style
  `games`/`draft_matches` updates); 0 remaining (every other player had
  already resigned first) abandons the match the same way the
  `'drafting'` branch above does.

Both `abandonDraftMatch()` and `removeDraftMatchPlayer()` are shared with
`finalizeWinstonDraft()`'s own pre-existing short-player-exclusion logic
(see "Winston Draft multiplayer" below) rather than duplicated -- a
mid-drafting resignation and "everyone came up short of
`WINSTON_MIN_DECK_SIZE`" are the same outcome (whole match abandoned, no
winner), and a deck_building-phase resignation and "one player came up
short" are the same outcome (that one player dropped, survivors
continue). `abandonDraftMatch()` takes `$gameId` explicitly (not
hardcoded to `match_game_number = 1`) because a resignation between games
of an already-underway best-of-three draft match -- `advanceDraftMatch()`
resets `draft_matches.status` back to `'deck_building'` and inserts a
fresh `'waiting'` games row for each new `match_game_number` -- needs to
abandon THAT specific game, not game 1's own long-since-completed row.

A new `'draft_resigned'` event type is logged (`card_id` is always
`null`, same as `'game_expired'`) and gets its own `describeEvent()`
phrasing ("{player} resigned from the draft") for the same reason
`'game_expired'` needs its own -- the generic "{actor} played {card}"
template doesn't fit an event with no card involved.

**Expiry cleanup (the other half of issue #144).** None of the four
draft-progressing methods (`submitQuickDraftPick()`, `submitDraftDeck()`,
`submitWinstonDraftPick()`, `submitGridDraftPick()`) used to call
`touchLastMoveAt()` the way `playMood()`/`pass()`/`respondToDecision()`
already did -- meaning an actively-drafted-but-slow match could be wrongly
swept by `expireStaleActiveGames()`/`bin/expire_and_delete_stale_games.php`
(see "Cleanup cron" above) even with genuine recent activity, since
`games.last_move_at` would just sit at whatever it was when the game
row was created (often `NULL`). All four now stamp it on every successful
pick/deck-submission, the same way the four resignable "real game"
methods already do.

### Spectator mode

Issue #128. Any logged-in user can watch a game they aren't seated in,
either because they're friends with a seated player or because they hold
that game's own share code -- login is required either way; there's no
anonymous access. Two ways in:

- **Friends' games.** `GET /games/spectatable`
  (`GameService::listFriendsInProgressGames()`) lists every game
  currently `in_progress` that at least one of your accepted friends is
  seated in and you aren't -- a friend's `waiting` game (nothing dealt
  yet) or `completed` game isn't listed here, though a completed one
  remains reachable via its own code (below).
- **A shared code.** Any seated player can mint their game's own share
  code via `POST /games/spectate/code`
  (`GameService::getOrCreateSpectateCode()`) -- an 8-hex-character code
  (`games.spectate_code`, migration `0043`) generated lazily on first
  request and reused after that, not pre-populated for every game.
  Holding the code is itself the authorization; there's no additional
  friendship check. Anyone can resolve a code to a game id via
  `POST /games/spectate/resolve` (`GameService::resolveSpectateCode()`)
  without needing to already know which game it belongs to.

`GET /games/spectate/state` (`GameService::getSpectatorState()`) is the
actual board view, and is deliberately the one `game_id`-taking route in
this app that does **not** call `requireGamePlayer()` -- every other
route's authorization is "are you seated in this game," which a
spectator by definition isn't. Its own rule -- enforced by `index.php`'s
own `canSpectateGame()` helper, not `GameService` (kept decoupled from
`FriendshipService` the same way the rest of `GameService` has no
friendship awareness) -- is: friends with at least one seated player, OR
the request's own `code` query param matches the game's `spectate_code`.
Authorization is checked first regardless of status; only once it passes
does `getSpectatorState()` itself reject a still-`waiting` (nothing dealt
yet) or `abandoned` (nothing worth watching) game with a `400`.
`GET /games/deck` (see "Shared deck view" below) and `GET /games/log`
(see "Game log" below) both reuse the same `canSpectateGame()` check for
a spectator who isn't seated, so "View decklist"/"View log" work for a
spectator too, not just a game's own players.

`getState()`'s roughly 300-line body (card/round/effect serialization)
is now a shared `private buildGameState(int $gameId, ?int $viewerUserId,
?int $viewerGamePlayerId, bool $revealAllHands = false)`, called by both
`getState()` (a thin wrapper resolving the real seated player, unchanged
behavior) and `getSpectatorState()` (`$viewerGamePlayerId = null`). A
spectator's response omits `you` entirely (there's no player point of
view to report), and every seat gains a `deck_count` (previously only
computed for the viewer's own seat -- this incidentally also fixes a
real gap where a duel-format opponent's own deck size wasn't visible to
the *other real player* either). Team-format teammate-hand/draft-match
internals are skipped for a spectator the same way they're skipped for
any viewer not party to them.

**While the game is still `in_progress`, a spectator sees only public
information** -- no hand contents for anyone, matching what an opponent
currently can't see either. **Once the game is `completed`,
`$revealAllHands` additionally populates every seated player's `hand`**
in full, since nothing competitive remains to hide once the outcome is
decided. This mirrors how the frontend already treats in-play/discard
cards as fine to inspect read-only regardless of turn -- a completed
game's hands are just as safe to show. Since a spectator has no "you,"
`web-static/js/game.js`'s existing board renderer runs with a
`state.you` stub (`{game_player_id: null, hand: [], is_your_turn:
false}`) synthesized client-side, so its "your turn"/"waiting on
another player" text instead resolves the actual current-turn player's
username from `state.players`, and every `state.you.*`-driven control
(Play/Pass buttons, the first-player choice panel, etc.) degrades to
hidden/disabled rather than crashing.

Only *public* information is exposed for a live game in this first
implementation. A follow-up "tournament spectator mode" issue tracks a
separate, trusted-viewer-only mode that would additionally reveal hands
and pending-decision internals for a still-`in_progress` game (for
casting/streaming) -- deliberately not built on top of the plain
`spectate_code` mechanism above, since that mechanism's entire premise
is that holding a code never reveals hands before the game ends.

Once a spectated game reaches `completed`, the frontend hands off from
this live single-snapshot view into "Watch replay"'s own step controls
(see below) -- no server-side change needed, since `GET /games/log` and
`GET /games/replay/state` already authorize a spectator the same way
`GET /games/spectate/state` does. See "Spectator mode" in
`web-static/README.md` for the client-side switch.

### Watch replay (issue #240)

Step through a *completed* game move-by-move -- the actual board (in-play
zones, discard, hands, scores) as it looked immediately after any past
event, not just the final state. The issue left "snapshot vs. replay"
undecided; this landed on **full forward replay of recorded facts**, not
re-executed `Effects/*.php` logic and not stored per-event snapshots: for
"board as of event N," derive the game's *genesis* (round-1 starting
hands/decks, before any event exists) by walking the whole `game_events`
log **in reverse** from the final `game_cards` state, undoing every
recorded fact, then walk **forward** from genesis re-applying those same
facts up to event N. At this game's scale (rarely more than a few hundred
events per game -- see "Game log" above) a full reverse-then-forward walk
per request is cheap enough that no caching/incremental-snapshot
infrastructure was needed.

`ReplayStateBuilder` (`genesis()`/`stateAsOf()`) does the reconstruction
and hands back a real `BoardState`; `GameService::replayStateAsOf()` then
runs it through `serializeReplaySnapshot()`, a private sibling of
`buildGameState()` that returns the same top-level shape
`getSpectatorState()` does (`you: {game_player_id: null}`, every hand
always revealed) but with every live-round-only field
(`current_turn_game_player_id`, `pending_decision`, `plays_remaining`,
`play_grants`, team/draft fields) nulled out -- there is no "current
round" for a specific past event. It reuses `buildGameState()`'s
stateless building blocks (`serializeCard()`, `scoringEffectEntries()`,
`boardEffectEntries()`, `boardPointTotalFor()`, `affectingEntries()`,
`temporaryOwnershipInfo()`) verbatim rather than duplicating them, so
`renderBoard()` needed zero frontend changes to display a replay
snapshot. `GET /games/replay/state` authorizes identically to
`GET /games/log` (seated player OR `canSpectateGame()`, see "Spectator
mode" above) and rejects a non-`completed` game or an `event_id` from a
different game with `400`. The frontend's step control reuses the
existing `GET /games/log` for the steppable event list -- no separate
endpoint for that part.

**The frontend's first step is genesis itself, not the first play.**
`replayStateAsOf(int $gameId, int $eventId)` treats `$eventId === 0` as a
sentinel for genesis -- real `game_events` rows are auto-increment ids
starting at 1, so 0 can never collide with a genuine event --
`ReplayStateBuilder::stateAsOf()` short-circuits straight to `genesis()`
in that case. This lets the frontend's steppable event list simply
prepend a synthetic "Step 1" entry with `id: 0` ahead of the real events
(see "Watch replay" in `web-static/README.md`), so opening a replay
always starts at round-1's dealt hands -- both players' opening hand
visible, nothing yet played -- rather than jumping straight to the first
recorded play.

`serializeReplaySnapshot()`'s `recent_events` field (the same "Recent
plays" data `GET /games/state` exposes) is capped to events at or before
the step being viewed (`recentEvents($gameId, $players, 15, $eventId)`'s
new `$upToEventId` parameter, folded into a plain `id <= :up_to_event_id`
SQL filter) rather than the game's own most-recent 15 overall -- so each
replay step's "Recent plays" section shows exactly what a live player
would have seen at that specific moment, never events from later in the
game. Genesis's `$eventId` of 0 naturally yields zero rows here (`id <=
0` matches nothing), correctly showing nothing has happened yet.

**Why genesis needs no new event.** Defined precisely as "state
immediately before `game_events` row #1," genesis needs no dedicated
"game started" log entry: `startGame()`'s deal and `closed_team`'s blind
initial card pass (`submitInitialCardPass()`) both complete strictly
before any round's first play is ever logged, so a reverse walk lands
exactly on the right starting point for either format with no special
casing -- see `ReplayStateBuilder`'s own docblock. Because nothing can be
in play or discarded before the game's first play is ever logged,
genesis's own reverse walk only needs to track bare zone membership
(hand/deck/discard) -- never suppression/effect-state/who-owned-a-mood-
while-it-was-in-play, all of which are guaranteed already back to "not in
play at all" once every event has been undone. `stateAsOf()`'s forward
walk, by contrast, tracks full in-play fidelity (owner, `copiedCardId`,
suppression, effect-state), since that's exactly what rendering a
specific point in history needs.

**The one real gap this required fixing**: `BoardState::drawCard()` used
to record only the drawing player's id, not the card id -- a deliberate
privacy choice so a live opponent reading `GET /games/log` mid-game can't
learn what a player privately drew. That made a card sitting untouched in
a hand at game end ambiguous between "dealt there at genesis" and "drawn
there later, never played," breaking reverse genesis-derivation. Fixed by
recording the card id too, but **`fullEventLog()` redacts
`details.draws[].card_id` for any game that isn't yet `completed`**,
preserving the existing live-game privacy guarantee exactly while making
the real card id available to replay (which only ever operates on
`completed` games, where hands are already fully revealed to spectators
anyway).

**Suppression and effect-state changes also needed a historical trail.**
`BoardState::suppress()`/`clearSuppressionsFrom()`/
`clearEndOfRoundSuppressions()`/`setEffectState()`/`clearEffectState()`
used to mutate a mood's suppression/effect-state bag with no record
beyond the final persisted value. Two new queues,
`$pendingSuppressionChanges`/`consumeSuppressionChanges()` and
`$pendingEffectStateChanges`/`consumeEffectStateChanges()`, mirror the
existing `$pendingCardMoves`/`consumeCardMoves()` pattern (a clear that
was already a no-op emits nothing); `moveHandToInPlay()`/
`moveDiscardToInPlay()` also queue one effect-state entry per key of a
freshly-played mood's initial effect-state bag (`playedFromZone`, any
cost-time staged state like Bliss's `blissColor`). `GameService::
withCardHistory()` folds both queues into `details` as
`suppression_changes`/`effect_state_changes`, the same way `card_moves`/
`ownership_changes` already are.

**A subtlety caught during implementation, not by any test**: a
Duplicity-triggered repeat re-tags `details['played_from']` on the *same*
card (read from the mood's own permanently-persisted `playedFromZone`
effect-state), which would otherwise look like a second "entering play"
event. `applyEventForward()`'s forward walk guards on
`!isset($inPlay[$cardId])` -- only the chronologically-first occurrence
with an empty in-play slot actually triggers entering-play logic; the
reverse walk uses a precomputed "first `played_from` event id per card"
map so only that exact event id triggers the "eject from in-play" undo
step.

Out of scope, documented rather than silently dropped: **draft-phase
pick-by-pick replay** (`quick_draft`'s `draft_round_picks` table actually
has enough data for this already; `winston_draft`/`grid_draft` delete
their own draft-state tables on completion and would need their own
persisted picks-log first -- a natural, separate follow-up) and
**team-format propose/reject intermediate steps** (no board-state impact,
only the final confirmed choice is logged).

The frontend reuses the board renderer entirely -- see "Watch replay" in
`web-static/README.md` for the step-control UI.

### View draft pool (issue #314)

Once a Quick/Winston/Grid Draft match is `completed`, `GET /games/draft-pool`
(`GameService::draftMatchPoolView()`) answers "what was the shared pool, and
who drafted what from it" -- useful for reviewing/comparing draft decisions
after the fact, similar in spirit to "Watch replay" above. Both source
columns it reads already outlive any single one of the match's up-to-3
`games` rows: `draft_matches.pool_card_ids` (the whole shared pool, fixed at
match creation) and each `draft_match_players.drafted_card_ids` (the fixed
result of the draft itself). Deliberately reads `drafted_card_ids`, not
`deck_card_ids` -- the latter is a player-chosen, game-to-game-changeable
*subset* of the former (see `submitDraftDeck()`'s own docblock), and "what
did you draft" is a different question than "what's in your current deck."
Authorization mirrors `GET /games/log`/`GET /games/replay/state` (seated
player or `canSpectateGame()`); the actual "only once the match is
completed" gate is a `409` from `draftMatchPoolView()` itself, since the
requester genuinely is authorized to view the game, the pool data just isn't
final yet.

A pool card belonging to nobody is a real, expected outcome for at least two
of the three formats, not an edge case: Quick Draft discards exactly 2 cards
per pile per round by design, and Grid Draft discards whatever's left in the
grid at the end of every round; Winston Draft normally drafts its entire
pool (the draft only ends once the deck and all 3 piles are simultaneously
empty), though even it can leave cards unclaimed if a short player was ever
dropped via `removeDraftMatchPlayer()`. `undrafted_cards` is computed via
the same `multisetSubtract()` every other pool/pick accounting in
`GameService` already uses (`pool_card_ids` minus the concatenation of every
player's own `drafted_card_ids`), so a duplicate catalog id (a custom pool
listing 2 of the same card) is still subtracted one-for-one correctly.

The frontend's "View draft pool" button lives on a completed match's own
group header in the games list (see `buildMatchGroupRow()` in
`web-static/README.md`) rather than on any individual game row, since the
pool is shared across the whole match, not scoped to one of its games. See
"View draft pool" in `web-static/README.md` for the dialog itself.

### Browser push notifications (issue #108)

First pass at issue #108's notification system: real-time browser push
for three event types --

- **"It's your turn"**, covering every "waiting on you" state the lobby's
  own `is_awaiting_your_response`/`awaiting_response_usernames` fields
  recognize (see `GET /games` above and `isAwaitingResponseFrom()`), not
  just an ordinary turn advance:
  - `game_rounds.current_turn_game_player_id` actually changing to a new
    player (see `GameService::updateRoundTurnState()`'s own docblock -- a
    same-player extra play from a banked grant or a Duplicity repeat is
    deliberately *not* re-notified).
  - A brand new round being created with a real (non-null)
    `current_turn_game_player_id` already set from the moment it's
    inserted -- round 1 of an ordinary (non-team) game in `startGame()`,
    an ordinary round-to-round handoff in `finishScoringAndAdvance()`,
    and Awe's non-team skip-scoring path in `skipScoringAndAdvance()`.
    These `INSERT INTO game_rounds` statements don't go through
    `updateRoundTurnState()`'s own `UPDATE` (there's no existing row yet
    to update), so each calls `notifyItsYourTurn()` directly right after
    the insert -- otherwise every round-to-round handoff would silently
    never notify, even though same-round turn advances already did.
  - A fresh pending-decision batch targeting a player (e.g. Compulsion
    asking an opponent which card to give up -- see
    `GameService::writePendingBatch()`/`notifyPendingDecisionTargets()`).
  - A fresh team `turn_order`/`draw_recipient` decision -- every candidate
    teammate is notified at once, since either may propose (see
    `createTeamDecision()`).
  - `closed_team`'s pregame blind card pass -- all 4 seated players are
    notified the moment the game starts, since every one of them owes a
    pass before round 1 can unfreeze (see `startGame()`'s own
    `closed_team` branch).
  - A best-of-three draft match's game 2/3 starting frozen on
    `setPlayFirstNextMatchGame()` -- only the previous game's loser is
    notified, since they're the only one who can actually act (see
    `startGame()`'s own `match_game_number > 1` branch and
    `isAwaitingFirstPlayerChoiceFrom()`).
  - A Quick Draft/Winston Draft/Grid Draft match's own "waiting on you"
    states during `drafting`/`deck_building` -- the same states
    `draftAwaitingResponseUsernames()` already surfaces to the lobby (see
    `GET /games` above), but pushed the moment they arise rather than
    only polled for: a fresh Quick Draft round dealt or its
    received-stage unlocking (`dealQuickDraftRound()`/
    `submitQuickDraftPick()`), a Winston/Grid Draft turn handed to the
    other player (`initializeWinstonDraft()`/`submitWinstonDraftPick()`,
    `initializeGridDraft()`/`submitGridDraftPick()`), and every format's
    own transition into `deck_building` -- an initial trim right after
    drafting finishes, or a later sideboard between the match's up-to-3
    games (`finalizeQuickDraft()`/`finalizeWinstonDraft()`/
    `submitGridDraftPick()`'s own round-6 branch,
    `advanceDraftMatch()`). Since this data is keyed by `user_id` rather
    than `game_player_id` (a match's drafted/deck state outlives any one
    of its `games` rows -- see migration `0027`), these route through a
    separate `notifyDraftUsersItsYourTurn()` helper that skips
    `notifyGamePlayersItsYourTurn()`'s own `game_players` lookup instead
    of reusing it directly.

  Each of these carries its own notification `tag` (`turn`/`decision`/
  `team-decision`/`initial-pass`/`first-player-choice`/`draft-pick`/
  `draft-deck`) so the OS never collapses two different "your turn"
  reasons for the same game into one notification -- see
  `GameService::notifyGamePlayersItsYourTurn()`/
  `notifyDraftUsersItsYourTurn()`.
- **"Friend request received"** -- sent from the `POST /friends/invite`
  route handler once `FriendshipService::sendInvite()` succeeds. Its own
  click-through `url` is `/game/?open_friends=1`, not `/friends/` (there's
  no standalone friends page -- the friends UI is a `<dialog>` on the
  lobby itself) -- `game.js`'s startup IIFE opens that dialog when it
  sees `open_friends=1`, the same way `?spectate_game_id` already jumps
  straight into spectator mode.
- **"A game you're in just finished"** -- sent to every winning and losing
  user from `GameService::recordGameCompletionStats()`, the single method
  already called from every code path that completes a game (see its own
  docblock) -- **except** whichever player's own move/response/resignation
  is what just completed it. That player is still credited a lifetime
  win/loss like everyone else; they just don't get a push telling them
  something they're already looking at on screen. Every completion path
  threads its own caller's `game_player_id` down to
  `recordGameCompletionStats()`'s `$excludeGamePlayerId` for this --
  `playMood()`/`pass()`/`respondToDecision()`'s own `$gamePlayerId`, or
  `resignGame()`'s own resigning player -- through `finishPlay()`/
  `advanceTurn()`/`advanceTeamTurn()`/`scoreRoundAndAdvance()`/
  `finishScoringAndAdvance()`/`finishTeamScoringAndAdvance()`, none of
  which otherwise cared who was asking.

Discord notifications (issue #232) reuse this same trigger/preference
design as a second delivery channel, rather than a parallel one -- see
"Discord" below for both account linking and how the Discord DM itself
gets sent.

**`NotificationService`** (`src/Notifications/NotificationService.php`,
formerly `PushNotificationService` back when browser push was the only
channel that existed) owns the trigger/preference/cooldown/queue
orchestration described in this whole section, and fans each decided-on
notification out to every configured `NotificationChannel`
(`PushNotificationChannel`, `Discord\DiscordNotificationChannel`) --
each channel only decides *how* to deliver to a user who's already
cleared the shared preference/cooldown check, never *whether* to. A
channel with nothing to deliver to for this particular user (no push
subscription, no linked Discord account) or nothing configured at all
(no VAPID keys, no bot token) returns `false` from its own `send()`
rather than being treated as a failure -- a player linked to both gets
one push *and* one Discord DM per event, a player linked to only one
gets just that one, and a player linked to neither still doesn't error,
just delivers nothing. One channel throwing is caught and logged without
stopping the others from being tried.

**5-minute cooldown per (user, game), with a queue-and-replace fallback**:
`NotificationService::notify()` checks whether that user was already
notified about this specific *scope* within the last 5 minutes
(`NotificationCooldownRepository::wasNotifiedRecently()`/`markNotified()`,
backed by the `notification_cooldowns` table added in migration `0049`)
-- otherwise a player actively working through several turns/decisions in
a row would get one notification per event. `NotificationScope` defines
what a scope is: `forGame($gameId)` for any it's-your-turn/game-finished
notification (regardless of which of the several tag suffixes triggered
it -- an ordinary turn, a Compulsion-style decision, a team decision,
etc. all share one scope per game), or the constant `FRIEND_REQUEST` for
friend requests, which aren't tied to any game. Scoping this way (rather
than one cooldown per user covering every game at once) means a player
active in several games simultaneously can still get more than one round
of notifications within 5 minutes overall, but never more than one round
within 5 minutes about any *one* specific game. The cooldown (and the
queued-notification cleanup below) is only stamped once at least one
channel actually delivered -- an event nobody could be reached about on
any channel doesn't burn the cooldown, so the next attempt still gets a
real shot instead of being silently queued behind it.

Rather than simply dropping a notification that arrives during its
scope's cooldown, it's queued instead (`QueuedNotificationRepository`,
backed by the `queued_notifications` table added in migration `0048` and
re-keyed by migration `0049`) and delivered later by a cron flush.
`queued_notifications`' primary key is `(user_id, scope)` -- one row per
user *per scope* -- so `enqueue()`'s `INSERT ... ON DUPLICATE KEY UPDATE`
naturally *replaces* whatever was queued for that same scope before,
without touching a different scope's own queued row: a player busy in
game A for 20 minutes ends up with exactly one queued notification for
game A reflecting whatever was truly last there, while a notification
that arrived for game B in the meantime is queued separately and
delivered on its own. `bin/send_queued_notifications.php` (meant to run
every ~15 minutes via cron) calls
`NotificationService::flushQueuedNotifications()`, which walks every
queued row *at least as old as the same 5-minute `COOLDOWN_SECONDS`*
(`QueuedNotificationRepository::dueForFlush()`) -- a row queued more
recently is left alone for a later flush, so a cron run landing moments
after something was queued doesn't deliver it before the player's had a
fair chance to clear it themselves (see below) -- re-checks that user's
preference at flush time (they may have turned it off since queueing --
this is why the rendered payload's `preference_key` is stored alongside
it), sends if still eligible, and clears the row either way. A live
(non-queued) send also clears any leftover queued row for that same
(user, scope) first, since a fresher notification about that same game
(or the same friend-request scope) just went out and an older queued
reminder would otherwise still arrive stale on top of it.

Separately, `GameService::clearQueuedNotificationForGamePlayer()` (called
from `playMood()`, `pass()`, `resignGame()`, `respondToDecision()`,
`submitInitialCardPass()`, `proposeTeamDecision()`, `confirmTeamDecision()`,
and `setPlayFirstNextMatchGame()`) and the `/friends/respond` route handler
(`NotificationService::clearQueuedFriendRequest()`) clear a queued
notification early, the moment the player actually takes the action it
would have reminded them about -- so the cron flush never delivers a
stale "it's your turn" for a turn already taken.
`QueuedNotificationRepository::clearForGameIfMatches()`/
`clearFriendRequestForUser()` delete by exact scope match (`game:{id}` or
`friend_request`), so clearing one game's queued reminder can never touch
a different game's, or a friend request's.

**Opting out of the cooldown entirely**: a `disable_cooldown` preference
(migration `0051`, defaulting `false` -- the cooldown stays on for every
existing user until they explicitly turn it off) lets a player receive
every notification they're otherwise eligible for immediately, even
several in quick succession, rather than being throttled to at most one
per scope every 5 minutes. `NotificationService::notify()` simply skips
the `wasNotifiedRecently()` check (and therefore the queue branch)
outright when this preference is on, so a user with it enabled never
gets a notification queued behind another one -- every eligible event
either delivers right away or (only for the same reasons an ordinary
send might not: preference off, nothing to deliver to, VAPID
unconfigured) doesn't happen at all, never delayed to a later cron flush.

**Architecture**: the standard three-part Web Push stack -- Push API
(`PushManager.subscribe()`) + Notifications API (`ServiceWorkerRegistration
.showNotification()`) + a Service Worker (`web-static/service-worker.js`,
registered at the site root so it can show/handle a notification
regardless of which page happens to be open). See "Browser push
notifications" in `web-static/README.md` for the frontend half.

**Backend** (`minishlink/web-push`, added via Composer):
`src/Notifications/PushNotificationChannel.php` wraps the library's
`WebPush` client. Every `notifyXxx()` method is a deliberate best-effort,
fire-and-forget call -- a stale/expired subscription, an unreachable push
service, or VAPID keys not being configured at all (e.g. local dev) must
never fail the request that triggered it, so `notify()`'s whole body (and
each row of `flushQueuedNotifications()`'s loop individually) is wrapped
in `try`/`catch (\Throwable)`, logging to `src/notification-errors.log`
(same convention as `logMailError()`/`MaintenanceGate`'s own error logs)
rather than letting anything propagate. A subscription the push service
reports as gone-for-good (HTTP 404/410 --
`MessageSentReport::isSubscriptionExpired()`) is pruned from
`push_subscriptions` automatically on the next send attempt.

Every other reason `notify()` might not actually deliver anything --
the recipient's own preference for that event type is off, they have no
subscriptions on file, VAPID keys aren't configured, the send is inside
its 5-minute cooldown and gets queued instead -- is also logged to the
same file, since none of these are exceptions (nothing to `catch`) and
were previously silent, making a "nothing happened, and I don't know
why" report undiagnosable from the log alone. Likewise, `WebPush::flush()`
returns one `MessageSentReport` per subscription rather than throwing
per-message, so a failed send that isn't a plain expired-subscription
(a VAPID key mismatch, an auth error, a malformed payload, the push
service itself erroring) used to be dropped in `sendNow()`'s own loop
with zero trace -- indistinguishable from a quiet success. It's now
logged too, with the report's own reason, HTTP status, and response
body.

`minishlink/web-push` itself only declares `psr/http-client` as an
interface dependency, not a concrete implementation -- `composer.json`
also requires `guzzlehttp/guzzle` + `php-http/guzzle7-adapter` (the exact
pair the library's own test suite uses) so `php-http/discovery` actually
finds a PSR-18 client at runtime. Without one of these, every send throws
`Http\Discovery\Exception\NotFoundException` ("No PSR-18 clients
found..."), which is exactly the kind of failure the `try`/`catch` above
now keeps from ever reaching the triggering request.

**Storage** (migrations `0048_add_push_notifications.sql` and
`0049_scope_notification_cooldown_and_queue_by_game.sql`): `push_subscriptions`
(one row per subscribed browser/device per user -- `endpoint`/`p256dh_key`/
`auth_key`, exactly what `PushSubscription.toJSON()` returns; uniqueness is
enforced on a SHA-256 `endpoint_hash` rather than the raw `endpoint` column,
since push-service endpoint URLs can run past reasonable index key-length
limits); `notification_preferences` (one row per user, four boolean
columns -- `notify_your_turn`/`notify_friend_request`/`notify_game_finished`/
`notify_chat_message` (migration `0079`, issue #109) -- created lazily the
first time a user changes a setting; a user with no row yet gets all-`true`
defaults from `NotificationPreferenceRepository::forUser()`);
`notification_cooldowns` (one row per `(user_id, scope)` pair, `last_notified_at`
-- see `NotificationCooldownRepository`); and `queued_notifications` (one
row per `(user_id, scope)` pair, holding the rendered title/body/url/tag
and originating `preference_key` for whatever notification about that
scope is currently delayed by the cooldown -- see above).

**Config**: `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`/`VAPID_SUBJECT` in `.env`
(see `.env.example`), read via the same `Config::get()` pattern `Mailer.php`
uses for `SMTP_*`. `VAPID_SUBJECT` is a `mailto:`/`https://` URL identifying
the sender, per the Web Push protocol. Generate a key pair with the
`web-push` npm CLI or `minishlink/web-push`'s own `VAPID::createVapidKeys()`.
`GET /notifications/vapid-public-key` hands the public half to the
frontend; if the server has no keys configured, `PushNotificationChannel`
silently no-ops every send rather than erroring.

### Discord (issue #232)

Three parts: account linking, the Interactions Endpoint plumbing, and
actually sending a notification as a Discord DM
(`Discord\DiscordNotificationChannel`, one of the `NotificationChannel`s
`NotificationService` fans a notification out to -- see "Browser push
notifications" above for the shared trigger/preference/cooldown/queue
orchestration both channels sit behind). A slash-command/button-driven
"play the game via Discord" is still out of scope here -- that's issue
#233's own territory, and the only interaction type
`DiscordInteractionsService` handles today is still just `PING`.

**Account linking** is Discord's standard OAuth2 authorization-code flow,
`identify` scope only (`DiscordOAuthService`) -- `GET /discord/oauth/start`
(requires auth) redirects the browser straight to Discord's own consent
screen; `GET /discord/oauth/callback` exchanges the returned `code` for an
access token, reads the player's Discord user id/username from
`GET /users/@me`, and links it to the current session's user via
`DiscordAccountRepository::link()` (migration `0050`'s `discord_accounts`
table, one row per MoodSwings user). The OAuth `state` param is
CSRF-protected the same shape `email_verifications` uses for its own
mailed tokens -- a random value, only its SHA-256 hash persisted
(`discord_oauth_states`, migration `0050`), single-use
(`DiscordOAuthStateRepository::consumeValid()` deletes on read) and
short-lived (10 minutes), and checked against the *same* user id that
requested it (a state issued for user A completing the callback while
somehow logged in as user B is rejected, never silently linked to B).
Nothing from the OAuth exchange itself is retained past that one request
-- no access/refresh token is stored -- since every actual notification
later sends through the Application's own **bot token** against the REST
API, never the player's own OAuth grant. On success (or a
`DiscordLinkException`), `/discord/oauth/callback` redirects the browser
back to the lobby (`?discord_linked=1` or `?discord_link_error=<message>`)
at the *site's own domain root* (`/game/`), not `APP_URL` -- unlike
`/discord/oauth/callback` itself (a PHP route, correctly under `APP_URL`'s
own `/app` prefix), `/game/` is the static frontend, served from the
domain root. `siteRootUrl()` in `index.php` gets this right: it prefers
the optional `SITE_URL` config value when set, otherwise derives it by
stripping `APP_URL`'s own trailing `/app`.

Unlike the `identify` scope's own OAuth-only requirements, actually
letting the bot DM a linked player later needs the Discord Application
registered in the Developer Portal as installable directly to a user's
own account ("User Install", under the Installation tab) -- this is what
lets a DM be opened without the bot sharing a server with that player.
That installation happens as a side effect of the same OAuth consent
screen `buildAuthorizeUrl()` sends the player to; no separate scope is
requested for it here.

**The Interactions Endpoint** (`DiscordInteractionsService`, mounted at
`POST /discord/interactions`) is Discord's HTTP-based alternative to a
persistent gateway/WebSocket bot connection: every slash command/button/
modal interaction Discord ever sends this app arrives as a single signed
POST here, so the whole "bot" runs as an ordinary request in the same
Apache/PHP process model as the rest of `php-app/` -- no separate
long-running process to deploy or keep alive. Every request is
Ed25519-signature-verified (`sodium_crypto_sign_verify_detached()` over
the exact raw body, no new Composer dependency needed --
`ext-sodium` ships with PHP) against `DISCORD_PUBLIC_KEY` before its JSON
is even parsed -- a request that fails verification gets a bare `401`,
never a rendered response. The only interaction type handled so far is
`PING` (Discord's own one-time "is this endpoint alive and correctly
verified" check, sent the moment the Interactions Endpoint URL is saved
in the Developer Portal), answered with a bare `PONG` -- no slash command
is registered yet (that's issue #233's own "play the game via Discord"
territory), so nothing else is ever actually sent here today. Every
rejected request (missing/malformed signature headers, no
`DISCORD_PUBLIC_KEY` configured, a signature that doesn't verify, or --
defensively -- `ext-sodium` itself unavailable) logs why to its own
`discord-errors.log` (same convention as `notification-errors.log`), so a
failed "verify this URL" attempt in the Developer Portal is actually
diagnosable from the deployed site alone.

**The Interactions Endpoint URL must include this deployment's own path
prefix, not just its domain** -- `php-app/` (and therefore every route in
the table above, `/discord/interactions` included) is commonly deployed
under a subfolder like `/app` rather than a domain's own document root
(see `APP_URL`'s own convention, e.g. `https://example.com/app`); the
Developer Portal has no notion of that prefix on its own, so it has to be
typed in explicitly, e.g. `https://example.com/app/discord/interactions`.
Pointing it at the bare domain instead fails the Portal's own live
verification with "The specified interactions endpoint url could not be
verified" -- indistinguishable, from the Portal's side, from every other
possible failure, but never something `discord-errors.log` will show
anything for, since the request never reaches this app's own routing at
all.

**Sending a notification** (`Discord\DiscordNotificationChannel`) uses
the Application's own bot token, never a player's OAuth grant --
consistent with `DiscordOAuthService`'s own docblock on why nothing from
that exchange is retained. A DM is two REST calls every time (Discord has
no persistent-channel concept to cache here): `POST /users/@me/channels`
with the recipient's Discord user id opens (or re-opens -- idempotent)
a DM channel, then `POST /channels/{id}/messages` sends into it. A user
with no linked Discord account, or a deployment with no
`DISCORD_BOT_TOKEN` configured, is a no-op from this channel's own
`send()` (`false`, not an exception) -- `NotificationService` treats that
exactly like `PushNotificationChannel` returning `false` for a user with
no push subscription, not a failure. The message body resolves
`$payload`'s relative `url` (e.g. `/game/?id=7`) against `SiteUrl::root()`
first, since Discord (unlike the Service Worker handling a push payload's
`url` directly) has no notion of a relative in-app link. Every rejection
or send failure logs to the same `discord-errors.log`
`DiscordInteractionsService` already writes to.

**Config**: `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`,
`DISCORD_PUBLIC_KEY`, `DISCORD_BOT_TOKEN` in `.env`, read the same
`Config::get()` way `VAPID_*`/`SMTP_*` are -- from the Developer Portal's
General Information (Application ID, Public Key) and Bot (Token) tabs,
plus OAuth2 → General (Client Secret).

**Two separate Discord Applications, one per environment** -- unlike
`VAPID_*`, which is shared freely across dev/prod, a Discord Application
only ever has one Interactions Endpoint URL and one set of OAuth2
redirect URIs, so one Application can't cleanly serve both environments
at once. Dev and prod each get their own Application (own Application
ID/Public Key/Bot Token/Client Secret, own OAuth2 redirect URI pointing
at that environment's own `/discord/oauth/callback`, own Interactions
Endpoint URL) -- the same reasoning `DB_*`/`FTP_*` already get a
`DEV_`-prefixed secret pair while `SMTP_*`/`VAPID_*` don't:
`deploy-dev.yml` reads `DEV_DISCORD_CLIENT_ID`/`DEV_DISCORD_CLIENT_SECRET`/
`DEV_DISCORD_PUBLIC_KEY`/`DEV_DISCORD_BOT_TOKEN` into the same
unprefixed `DISCORD_*` `.env` keys `deploy.yml` writes from the
unprefixed secrets -- the application code itself has no notion of
"which environment," same as every other `Config::get()` value.

### Lifetime stats

Issue #106: a running per-user total of game wins/losses (every format)
and match wins/losses (`quick_draft`/`winston_draft`/`grid_draft` best-of-
three matches -- the only "match" grouping that exists yet; non-draft
best-of-three (#90) and tournament standings (#91) both stay out of scope
until those exist), backed by a new `user_lifetime_stats` table
(migration `0042`) rather than a query re-aggregating `games`/
`draft_matches` on every read. That distinction matters specifically
because old game history is expected to get cleaned up eventually --
once it is, a live aggregate would silently start under-reporting, while
an incrementally-maintained counter keeps whatever total it already
accumulated. The table itself is backfilled once, by the migration's own
`INSERT ... SELECT` against existing history; every game/match completed
afterward increments it going forward via the two methods below, never
re-derives it.

`GameService::recordGameCompletionStats(int $gameId, int
$winnerGamePlayerId, ?int $winnerTeamId)` runs from every code path that
sets `games.status = 'completed'`
(`completeGameByResignation()`, the non-team and team round-scoring
completions in `finishScoringAndAdvance()`/`finishTeamScoringAndAdvance()`)
-- for a team-format win it credits *every* seat sharing `$winnerTeamId`,
not just the one representative `$winnerGamePlayerId` (see "Open Team
Play" for why that representative is never itself the authoritative
record of who won). `GameService::recordMatchCompletionStats(int
$draftMatchId, int $winnerUserId)` runs from both places a draft
match's own status becomes `'completed'`: `advanceDraftMatch()`'s
ordinary 2-0 finish, and `finalizeWinstonDraft()`'s under-12-cards
auto-loss branch -- the latter completes the *match* without game 1 ever
completing, so it contributes to `match_wins`/`match_losses` but not
`game_wins`/`game_losses` for that pairing. Both private methods funnel
through `bumpLifetimeStats(array $userIds, string $column)`, a single
`INSERT ... ON DUPLICATE KEY UPDATE ... = ... + 1` per user id -- there's
no row at all for a user nothing has ever happened to; `GameService::
lifetimeStatsFor(int $userId): array{game_wins, game_losses,
game_win_percentage, match_wins, match_losses, match_win_percentage}`
(backing `GET /user/stats`) reads that back as all-zero (percentages
`null`) rather than requiring a row to exist first. The percentage
fields are computed here, server-side, rather than in the frontend --
`(int) round($wins / ($wins + $losses) * 100)`, or `null` when
`$wins + $losses === 0` so a brand-new user sees no percentage at all
instead of a misleading "0%".

The frontend surfaces this on a new dedicated page,
`web-static/user/index.html`/`user.js` (a "User info" button sits next
to Friends/Decks/Log out on the lobby page) -- see "User info" in
`web-static/README.md`. Deliberately its own page rather than a dialog:
the issue explicitly asks for a page that can grow (tournament
standings once #91 lands, per-format breakdowns, etc.), and lifetime
stats are the first section on it, not the only thing it will ever show.
Each record renders as `wins-losses`, or `wins-losses (NN%)` once the
percentage is non-null.

### Card statistics (issue #315)

Server-wide, 17lands-style aggregate data -- not tied to any one
player -- per catalog card (`cards.id`, never a per-game instance id):
how many completed games' decks it ended up in and how many of those
were won, how many times it was actually played and how many of those
games were won, and (for Quick/Winston/Grid Draft) an average
"how early was this taken" signal per format. Backed by a new
`card_stats` table (migration `0070`) and a new `MoodSwings\Stats\
CardStatsService` class -- deliberately its own small service (mirroring
the `UserDecklistService`/`ReplayStateBuilder` precedent) rather than
more private methods on the already-large `GameService`, and injected
into it as an optional constructor dependency (defaulting to
`new CardStatsService()`, same pattern as `PresenceService`/
`GameNoteRepository`) so no existing call site needed to change.

Like `user_lifetime_stats`, every stat is written incrementally as it
happens rather than computed by re-reading historical game data --
necessary here, not just an optimization: a `completed` game is
permanently deleted after 7 days (`deleteStaleCompletedGames()`), and
Winston/Grid Draft's own pick state (`draft_winston_state`/
`draft_grid_state`) is deleted the instant each draft finishes, so
there'd be nothing left to read from by the time anyone looked at a
stats page.

Two independent groups of stats, updated from two different kinds of
hook point:

- **Deck-membership/played-card win rates**
  (`CardStatsService::recordGameCompletion()`) are only knowable once a
  game's outcome is decided, so there's a single hook at the end of
  `GameService::recordGameCompletionStats()` -- the one method every
  game-completion code path already funnels through (see "Lifetime
  stats" above), so no other call site needed touching. For each seated
  player, `SELECT DISTINCT owner_game_player_id, card_id FROM game_cards`
  gives "which catalog cards ended up in their deck" -- pre-assigned
  decks (duel/draft formats) have this set for the whole deck from
  `startGame()` onward; shared-pool formats only count cards actually
  drawn (an undrawn deck card, still `owner_game_player_id IS NULL` at
  game end, was never really part of that player's realized deck).
  `DISTINCT` because a deck can legally contain duplicate copies of a
  catalog card (issue #109) -- a duplicate still only counts once toward
  "how many decks this card ended up in", not once per copy. "Played" is
  `game_events.event_type = 'mood_played'`, joined through
  `game_events.card_id -> game_cards.id -> game_cards.card_id` for the
  catalog id, `DISTINCT`-deduped the same way (a card played twice in one
  game via Duplicity's repeat mechanic still only counts once).
- **Draft pick-position signal**
  (`recordQuickDraftPick()`/`recordWinstonDraftPick()`/
  `recordGridDraftPick()`) is knowable the instant a pick happens,
  independent of the eventual game/match outcome (same as 17lands' own
  ATA, a pure draft signal) -- and for Winston/Grid Draft, deferring
  wouldn't even be possible, since their own pick state is gone by match
  completion. Each is called directly from its own pick-submission
  method instead:
  - Quick Draft (`submitQuickDraftPick()`): `(round_number - 1) *
    playerCount + stage_number` -- a genuine ATA-style ordinal, since
    `draft_pile_stage_picks` already records exactly which (round,
    stage) a card was kept at.
  - Winston Draft (`submitWinstonDraftPick()`): the pile's own size at
    the moment of taking (a small pile means it was taken fresh; a large
    one means it was passed around and grew first) -- there's no
    persisted per-pick position for this format otherwise, so this is
    computed live from `count($newlyDrafted)` right where the pick
    happens. Also covers the forced pile-3-decline deck-draw, with an
    implicit pile size of 1 (always freshest, since nobody else has seen
    it).
  - Grid Draft (`submitGridDraftPick()`): the round the pick happened in
    (`draft_grid_state.current_round`, read before it's ever
    incremented).

  These three numbers are on different scales and never compared across
  formats (same as 17lands' own ATA, which is always within-format), so
  each gets its own `*_sum`/`*_count` column pair on `card_stats` rather
  than one shared value.

`CardStatsService::allCardStats()` (backing `GET /stats/cards`) is the
read path: every catalog card via `CardCatalog::load()` (the same
card-catalog source `GameService`/`UserDecklistService` already share),
each with its recorded stats or all-zero/null defaults for a card
nothing has happened to yet -- same "no row means zero" shape
`lifetimeStatsFor()` already uses. Also includes `set_code`/
`collector_number` per card via a new `CardCatalog::loadSetInfo()`
helper (factored out of `CardCatalog::serialize()`'s own per-card
subquery, same "lowest `sets.id` row" tiebreak, but for the whole
catalog at once and without `serialize()`'s heavier per-card fields the
stats page has no use for) -- the frontend's own set filter and Set/#
columns read straight off this without a second round trip. Deliberately
no minimum-sample-size filtering: every card shows its raw counts
alongside any rate/average, so a low-sample stat is visible as such (a
handful of games on a small server) rather than hidden or flagged.

The frontend surfaces this on a new dedicated page,
`web-static/stats/index.html`/`stats.js` (a "Stats" button sits next to
Spectate on the lobby page) -- see "Card statistics" in
`web-static/README.md`.

### Online/presence indicator (issue #110)

Shows whether a friend or fellow game player is actually around right
now versus playing async -- surfaced on the friends list and a game's
own Players list, both of which already render a username, so this is
an indicator alongside an existing element rather than a new page.

"Online" is derived cheaply from `sessions.last_seen_at` -- already
touched to `NOW()` on every authenticated request (see
`AuthService::currentUser()`/`SessionRepository::touch()`) -- rather
than a new heartbeat/websocket signal: `PresenceService` (`src/
Presence/PresenceService.php`) treats a user as online if any of their
currently-valid (non-expired) sessions were active within the last
`ONLINE_THRESHOLD_SECONDS` (120). This is coarser than "has an open tab
right now," but both the lobby and a game board already poll every 4
seconds while open (see "Game timestamps"'s own polling description),
so it tracks genuinely active use closely in practice without any new
infrastructure. `SessionRepository::lastSeenAtForUsers(int[] $userIds):
array<int, string>` is the one query behind this -- a single `MAX(
last_seen_at) ... GROUP BY user_id` covering every user in the request
at once (a user logged in on more than one device/tab is `MAX()`-ed
across all of theirs, not any single session row), rather than one
query per row.

A user can opt out of sharing this signal at all --
`users.share_presence` (migration `0053`, default `1`/shared) -- surfaced
to a viewer as a third, distinct `'hidden'` status rather than silently
folded into `'offline'`, so "this person turned presence off" reads
differently from "this person just isn't active right now."
`PresenceService::statusesFor(array<int, bool> $sharePresenceByUserId):
array<int, string>` is the single method every caller goes through --
takes each user's own `share_presence` flag (callers already have it,
from whatever query fetched those users -- `FriendshipRepository::
listAcceptedForUser()`'s and `GameService::buildGameState()`'s own
`users` joins each grow one more column for this) and returns
`'online'`/`'offline'`/`'hidden'` per user id. `GET /friends`'s own rows
each get a `presence` field this way (`FriendshipService::listFriends()`);
`GET /games/state`'s `players` rows do too (`GameService::
buildGameState()`) -- deliberately *not* `GET /games/replay/state`'s own
separate players loop (`serializeReplaySnapshot()`), since "was this
player online" is meaningless for a moment frozen in a completed game's
past.

The toggle itself lives on the "User info" page (`web-static/
user/index.html`/`user.js`, see "Lifetime stats" above for why that page
exists) rather than the lobby's own Notifications dialog -- this is a
privacy/visibility setting about the account itself, not a notification
preference. `GET /me`'s own user object already carries the current
`share_presence` value (`AuthService::currentUser()`), so the page needs
no separate fetch to initialize the checkbox; `POST /user/presence-
preference` (`UserRepository::setSharePresence()`) saves a change,
auto-submitted on toggle the same way the Notifications dialog's own
checkboxes save immediately on `change` rather than needing a separate
Save button.

Frontend rendering (`web-static/js/game.js`): the friends list and the
board's own Players list both use the same `buildPlayerFlag()`/
`buildStatIcon()` icon convention issue #143 introduced for went-
first/on-turn/pending-decision flags -- a filled dot (`--color-success`)
for online, the same shape defaulting to `--color-muted` for offline,
and a distinct eye-slash icon (not just a different color) for hidden,
so a colorblind viewer -- or anyone glancing quickly -- can tell
"offline" and "opted out" apart by shape, not only color.

### In-game notepad (issue #258)

A small freeform scratchpad for jotting down private reads/reminders
during a game -- who's bluffing, what's already been played, a plan for
next round -- never shared with anyone else at the table, including
teammates. Per-game rather than persistent across every game a player's
ever in (the issue's own scope), and keyed directly on `game_players.id`
(migration `0054`'s `game_notes` table, `UNIQUE KEY` on
`game_player_id`) rather than a separate `(user_id, game_id)` compound --
a seat already uniquely identifies "this player, in this game," the same
way `resigned_at`/`custom_deck_name`/the initial card pass all hang off
`game_player_id` rather than inventing their own key. `GameNoteRepository`
(`src/Repository/GameNoteRepository.php`) is a two-method repository:
`findByGamePlayerId(int): ?string` and `upsert(int, string): void` (an
`INSERT ... ON DUPLICATE KEY UPDATE`, so the row is lazily created on
first save rather than provisioned up front for every seat).

`GameService::getNote(int $gamePlayerId): string` (empty string, not
`null`, if nothing's ever been saved -- one less null-check for both the
HTTP layer and the frontend) and `GameService::saveNote(int $gameId, int
$gamePlayerId, string $noteText): void` are the only two entry points.
`saveNote()` enforces a `MAX_NOTE_LENGTH` of 20,000 characters (checked
in PHP via `mb_strlen()` -- the column itself is `MEDIUMTEXT`,
effectively unbounded at the DB layer, so this is purely an
application-level sanity limit) and throws `GameStateException` unless
the game is still `in_progress`. That gate is deliberate: once a game
reaches a terminal status (`completed` or `abandoned`) the note becomes
**read-only**, matching how a resigned/finished game locks out every
other board action -- but `getNote()` has no such gate, so the note
itself stays fully readable forever; only further edits are refused.
`GET /games/notes`/`POST /games/notes` (see the API table above) are
thin wrappers around these two methods, both behind the same
`requireGamePlayer()` seat check every other per-player game route uses.

Frontend (`web-static/game/index.html`/`web-static/js/game.js`): a
"Notes" button next to the existing "View log"/"View decklist" buttons
opens `#game-notes-dialog`, matching that same established dialog
pattern rather than a persistent inline panel (only reachable from that
game's own board, never a separate cross-game notes page, per the
issue's own "per-game, not persistent" scope). Typing into the textarea
autosaves on a 1-second debounce (`saveGameNote()` in `app.js`) rather
than needing an explicit Save button; closing the dialog with an edit
still pending flushes it immediately rather than discarding it. Once the
game's own status isn't `in_progress`, the textarea is disabled and a
"This game has ended, so your notes are read-only" message is shown
instead -- the previously-saved text is still loaded and displayed, just
not editable, mirroring the backend's own read-but-not-write rule.

### In-game chat (issue #109)

Lets players seated in a game send each other text messages while
playing, rather than needing an out-of-band channel to talk during a
match. Unlike the notepad above (private, one row per seat, upsert-only),
chat is genuinely many-rows-per-seat and shared -- migration `0079`'s
`game_chat_messages` table is shaped like `game_events` rather than
`game_notes` for that reason: a `BIGINT` `id` as the ordering key, a plain
FK straight to `games.id` (`ON DELETE CASCADE`, so it automatically
participates in the 7-day stale-game deletion cascade -- see "Cleanup
cron" below -- with no extra cron-script code needed), no `UNIQUE`
constraint. Reset per individual `games.id` rather than persisting across
a best-of-three Quick/Winston/Grid Draft match's up-to-3 games, matching
`game_notes`/`game_events` rather than `draft_matches`/
`draft_match_players` (the only tables that actually span a whole match).

Delivery deliberately piggybacks on the existing `GET /games/state` poll
(the frontend's `pollTimer`, every 4 seconds) rather than a dedicated
polling endpoint or a load-once-per-dialog-open fetch -- this codebase has
no precedent anywhere for a delta/since-id fetch, so `chat_messages`
always carries the whole conversation so far, the same "just re-fetch
everything" pattern every other poll/dialog-open in the app already uses.
A typical game's message count stays small enough (short-lived, deleted
with the game after 7 days) that this is simpler than adding a delta
mechanism. Gated the same way `game_notes` is -- `requireGamePlayer()`
only, no `canSpectateGame()` fallback -- so a spectator (watching via
share code) can never read or send chat, unlike `game_events`/`GET
/games/log`'s own more permissive spectator-visible gating. `getState()`
always returns `chat_messages` once the game is `in_progress`/`completed`
(`[]` if nothing's been sent yet); `getSpectatorState()` always returns
`[]` regardless, since `buildGameState()`'s single shared builder only
ever populates it when there's a real seated viewer.

Open Team Play (`format: 'team'` only -- deliberately NOT Closed Team
Play, see below) additionally gets a private teammate-only channel
alongside the whole-table one -- the first team-scoped read/write path
anywhere in this schema. `channel` (`ENUM('table', 'team')`) plus `team_id`
together drive it: `'table'` messages are always visible to every seated
player; `'team'` messages are only ever inserted for format `'team'`
where the sender actually has a `game_players.team_id` (`GameStateException`
otherwise, "This game has no team channel." / "You are not on a team in
this game."), and only visible to seats sharing that same `team_id`.
`closed_team` games get no `'team'` channel at all --
`GameService::postChatMessage()` deliberately checks `$game['format'] ===
'team'` rather than the shared `isTeamFormat()` predicate ($format ===
'team' || $format === 'closed_team') every OTHER team-format branch in
this file uses, since Closed Team Play's entire premise is that
information stays closed between teammates (see "Closed Team Play"
above, point 4) -- a private out-of-band channel there would undercut the
one thing that format is actually testing. Open Team Play has no such
restriction (partners already see each other's hands via
`you.teammate_hand`), so a private channel there is just a convenience,
not a rules violation.
`team_id` is stored directly on the message row rather than only
resolvable via a join back through `sender_game_player_id`, keeping the
read-side filter (`WHERE channel = 'table' OR (channel = 'team' AND
team_id = :viewer_team_id)`, `GameChatRepository::messagesFor()`) a single
indexed lookup rather than a join on every 4-second poll. A `NULL`
`$viewerTeamId` (every non-team format) naturally excludes every `'team'`
row via SQL's own `NULL`-comparison semantics, so no special-casing is
needed for the (overwhelmingly common) non-team case.

`GameService::postChatMessage(int $gameId, int $senderGamePlayerId,
string $channel, string $messageText): void` is the only write path:
`GameStateException` unless the game is `in_progress` (matching
`saveNote()`'s own "while playing" gate -- a completed/abandoned game has
no one left mid-conversation to send to), `InvalidArgumentException` if
`$messageText` is empty after trimming or over `MAX_CHAT_MESSAGE_LENGTH`
(500 characters -- much shorter than the notepad's 20,000, since a chat
message is meant to be read live by another player rather than held as a
private scratchpad). No additional send-rate-limiter beyond that length
cap -- friends-only, seated-players-only access keeps the abuse surface
low, and there's no existing precedent anywhere in this codebase for
throttling a primary write (only for throttling notification *delivery*,
see `NotificationService`'s own cooldown/queue). On success, fires a
best-effort `notifyNewChatMessage()` (issue #108's notification system) to
every OTHER seat the message is actually visible to -- never the sender,
never the opposing team for a `'team'`-channel message -- sharing
`NotificationScope::forGame($gameId)` with `notifyYourTurn()`/
`notifyGameFinished()` rather than its own cooldown scope, the same way
those two already share one bucket per game instead of one each.
`notify_chat_message` (migration `0079`, default on) is its own
notification preference, independent of the other three.

`GameService::exportGameData()`'s own `game_chat_messages` section applies
the identical `'table'`-or-own-`team_id` filter `messagesFor()` uses
rather than a raw unfiltered dump -- an export triggered by one player has
no business revealing what the OTHER team privately said to each other,
the same reasoning `game_notes`' own per-requester scoping there already
follows.

`POST /games/chat` (see the API table above) is the only new route -- no
`GET /games/chat`, since messages are delivered via `GET /games/state`'s
own `chat_messages` field instead.

Frontend (`web-static/game/index.html`/`web-static/js/game.js`): a "Chat"
button next to "Notes"/"View log" opens `#game-chat-dialog`, matching that
same established dialog pattern. Rather than fetching its own data on
open, `renderChat()` re-renders the dialog's message list from
`currentState.chat_messages` every `refreshBoard()` poll tick while it's
open, so an open chat dialog updates live without the player needing to
close and reopen it. A small notification dot on the "Chat" button itself
(`.has-unread-chat`, mirroring `#friends-button`'s own `.has-friend-request`
dot) lights up when the currently-viewed game has messages the player
hasn't seen yet (dialog never opened, or closed before catching up), and
clears the moment the dialog is opened. The channel `<select>` only
appears for `format: 'team'` games -- NOT `closed_team` too, unlike every
other team-format UI check in this frontend; every other format only
ever has `'table'`. Every message is rendered via `Element.append(string)`/
`textContent`, never `innerHTML` -- free-text chat rendered back to other
users is this issue's own flagged XSS surface, and this is the same
text-node-only convention the rest of the frontend already follows for
every other piece of user-supplied text on the board.

### Duel: separate per-player decks

`format: 'duel'` and `format: 'draft'` (see "Draft format" below) are the
only physical rules difference `format` actually makes (every other format
value is cosmetic, just echoed back and displayed as a label) -- both are
"duel-shaped": each of the game's exactly-2 players draws from -- and
bottoms cards onto -- their *own* deck rather than a single shared one.
`GameService::isDuelShapedFormat(string $format): bool` (`$format === 'duel'
|| $format === 'draft'`) is the single helper both `createGame()` (the
exactly-2-players check, `GameStateException` "A {format} game must have
exactly 2 players") and `startGame()` (the per-player-deck-dealing branch)
consult, so the two formats can never drift out of sync with each other.
`BoardStateRepository::load()`'s own `$hasSeparateDecks` check is the same
condition again, one level down, for exactly the same reason.

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

`GameService::applyAfterScoringHooks()` resolves a mood's own inherent
`afterScoring` tag (Bashfulness/Gluttony/Insecurity/Recklessness) *before*
a foreign `returnsToOwnerAfterScoring` tag placed on that same card by a
different source (Betrayal; Recklessness's own steal), per a rules-committee
ruling on the case where a single physical card ends up carrying both at
once -- e.g. Recklessness steals an opponent's mood, then gets given away
via Betrayal, so Recklessness itself carries both its own unconditional
"bottom this mood and draw" and Betrayal's "give it back" at the same time.
The ruling: "after scoring" effects resolve per card's *current* controller,
in turn order, so Recklessness's own effect -- unconditional, "while in
play" -- always gets to run first for whoever controls it at that moment;
only once that's settled does a foreign "give it back ... if it's still in
play" get a chance, and by then the card may already have left play
entirely. The previous ordering had this backwards (foreign return resolved
first), which let a "give it back" tag reclaim a mood a moment before that
mood's own unconditional effect would have removed it from play for good --
producing a spurious, ruling-contradicting ownership-change log entry and
crediting the wrong player with Recklessness's own draw. `isInPlay($cardId)`
is now checked immediately before `giveInPlayToPlayer()` in the
`returnsToOwnerAfterScoring` branch, so a foreign return correctly no-ops
once the card's own effect has already taken it out of play, implementing
the "if it's still in play" qualifier printed on both cards.

A follow-up ruling from the same rules committee spelled out the FULL
general framework the paragraph above only covers half of: "after scoring"
effects resolve player by player, in the order they went this round, and
if a single player's own cards carry two or more such effects at once, THAT
PLAYER chooses the order they resolve in. `applyAfterScoringHooks()` now
implements this in full. `pendingAfterScoringGroups()` determines, for
every pending `afterScoring`/`returnsToOwnerAfterScoring` tag, which player
it currently "belongs to" for this purpose -- a self-tag belongs to
whoever currently controls the tagged card itself; a foreign
`returnsToOwnerAfterScoring` tag belongs to whoever currently controls its
own `sourceCardId` (Betrayal, or the Recklessness that stole this mood in
the first place) -- since that's whose printed ability actually created
it, regardless of who's currently holding the affected card. This is why
Recklessness given away via Betrayal still splits across BOTH players even
though it's one physical card (its own self-tag belongs to whoever now
holds Recklessness; Betrayal's foreign tag on that same card belongs to
whoever still holds Betrayal) -- matching "in your example different
players have each card" from the original ruling even in the case where,
physically, there's only one card. When a single controller ends up with
2+ pending cards this way, `scoreRoundAndAdvance()`/`finishScoringAndAdvance()`
pause the round on a new `AFTER_SCORING_ORDER_DECISION_TYPE`
(`'after_scoring_order'`) decision -- structurally identical to
Enthusiasm's/Passion's own scoring-time decisions (same
`game_pending_decision_batches`/`game_pending_decisions` machinery), but a
genuinely new *kind* of pause: those two gate BEFORE `RoundScorer::score()`
ever runs (they feed its own inputs), while this one can only be asked
AFTER scores/winner are final, since a conditional self-tag's "did you
win" answer has to be known before anything about ordering makes sense.
`determineRoundWinner()` is the one place that answer gets computed,
shared by the order-decision check and the real score/persist tail that
runs once no more decisions remain, so the two can never disagree about
who won. Nothing about the resolution order actually changes the FINAL
board state with any card implemented today (every pending effect acts on
an independent target, so there's no way for one to influence another) --
the decision exists for rules fidelity and player agency, not because any
currently achievable outcome depends on it. The field itself
(`type: 'card_order'`) is new -- unlike every other pending-decision field,
it isn't backed by a `CardChoiceSchema::reactionTemplate()` entry, since
it's not a printed reaction on any one card; it's an engine-level question
that only exists once 2+ of a single player's own after-scoring cards are
pending at once. The frontend renders it as an up/down-reorderable list
(`.card-order-field` in `game.js`/`style.css`) rather than the usual
`<select>`-based widget, since "pick a permutation of a fixed set" doesn't
fit any of the existing field types. Each entry in the field's `cards`
array also carries a `description` string (built by
`afterScoringEffectDescription()`) explaining what that specific pending
effect actually does and, for a foreign `returnsToOwnerAfterScoring` tag,
which card caused it and who it returns to (e.g. "Returns to Alice after
scoring (taken via Betrayal)."), so the player doesn't need to already
know every card's printed rules text to make an informed choice between
same-named or unfamiliar cards.

The pause is skipped outright, however, on whichever round actually
finishes the game (`wins_needed` reached) -- there's no next round for a
chosen order to ever matter to, so asking would just stall the game's
last action on a decision whose only visible effect, per the paragraph
above, is which of several equally-legal orderings a completed game's
final board state happens to show. `roundWouldCompleteGame()` predicts
this *before* `finishScoringAndAdvance()`/`finishTeamScoringAndAdvance()`
write any of this round's own rows, reusing the exact same
`totalWinsFor()`/`totalWinsForTeam()` + `wins_needed` comparison (and a
non-mutating peek at Corruption's extra-win marker via
`hasExtraWinMarker()`) those methods make for real further down, so the
two are guaranteed to agree. When the prediction says the game is about
to end, `finishScoringAndAdvance()` never calls
`nextUnresolvedAfterScoringOrderDecision()` at all -- every pending
after-scoring card still resolves in `applyAfterScoringHooks()` exactly
as it always does, just always via that method's own no-decision-made
default (ascending `cardId`) order, the same fallback already used for
any player who was never asked in the first place.

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

`MigrationRunnerTest` exercises `MigrationRunner::applyPending()` (shared
by `bin/migrate.php` and `POST /migrate`, see "Maintenance mode" above and
"Auto-applying migrations on deploy" in `database/README.md`) against its
own scratch fixture directory rather than the real `database/migrations/`
-- that directory only ever grows and every file in it is already applied
to `TEST_DB` by the time any test runs, so there'd be nothing left to
actually apply. Its fixture filenames are namespaced
(`zzz_migration_runner_test_*`) so they can never collide with a real
migration's own name, and clean themselves out of the shared
`schema_migrations` table in `tearDown()`.

The test suite truncates `users`/`sessions`/`email_verifications`/
`friendships` in that database before each test, so never point it at a
database with real data.
