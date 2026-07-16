# database

MySQL schema for MoodSwings-Web, managed as an ordered set of migrations in
[`migrations/`](migrations/) rather than a single schema dump — new schema
changes ship as small incremental files you can apply on top of whatever's
already there, instead of re-running the whole thing.

## Applying migrations

**Local development / tests:** point `php-app`'s `.env` (or `DB_*`
environment variables) at the target database, then run:

```sh
cd php-app
composer migrate
```

This applies whichever migrations in `migrations/` haven't been run yet,
tracked in a `schema_migrations` table, and is safe to re-run at any time —
already-applied migrations are skipped.

**Production (Bluehost, no shell access):** apply new migration files
manually via phpMyAdmin's SQL tab, in filename order — each one is a plain
`.sql` file you can paste and run directly. `0001_baseline.sql` is the
schema as of when this migrations workflow was introduced; if your database
was already provisioned before this (i.e. you'd previously run the old
`schema.sql`), you don't need to run it — just start from whatever
migration comes next.

**Standing up a brand-new database** (e.g. the dev domain's own database —
see "Development environment setup" in the top-level README): rather than
pasting/running 20+ individual migration files by hand, paste
[`consolidated/0001-0020_consolidated.sql`](consolidated/0001-0020_consolidated.sql)
once instead — every statement from `0001` through `0020`, in order,
followed by a `schema_migrations` table pre-populated with all 20
filenames so a later `composer migrate` correctly picks up from `0021`
onward rather than re-running any of them. Only use it against a genuinely
empty database (see the file's own header for why); once your schema
history grows past `0020`, apply whichever migrations came after it
individually, the same as always.

## Adding a new migration

Add a new file named `NNNN_description.sql` (next sequential 4-digit
number) containing only the incremental change (e.g. an `ALTER TABLE`),
not a full schema dump. Migrations are immutable once merged — never edit
one that's already shipped; add a new one instead. Keep each migration to
plain DDL statements (no stored procedures/triggers) — and if a migration
seeds data (like `0003`'s card catalog `INSERT`s), make sure none of the
values contain a literal semicolon — since `bin/migrate.php` splits files
on `;`.

**Every migration that changes the schema must also bump the root
[`VERSION`](../VERSION) file, and must end with an `UPDATE schema_version
SET version = 'X.Y.Z' WHERE id = 1;` matching that same bump** (see
"Application/system" below and `MaintenanceGate` in `php-app/README.md`).
This must be the file's **last statement** — MySQL/InnoDB DDL isn't
transactional (every `ALTER`/`CREATE` implicitly commits on its own), and
production migrations are pasted by hand into phpMyAdmin with no
atomicity guarantee, so a partial/failed paste needs to leave
`schema_version` still reporting the *old* version (keeping the app in
maintenance mode) rather than falsely reporting the new one against a
half-migrated schema.

## Layout

- `migrations/` — Ordered `.sql` files, one per schema change, applied in
  filename order.
- `consolidated/` — Point-in-time, hand-maintained snapshots merging a
  range of `migrations/` files into one script for standing up a fresh
  database in a single paste (see "Applying migrations" above). Outside
  `migrations/` deliberately, so `bin/migrate.php` (which only globs
  `*.sql` directly inside `migrations/`) never picks these up as if they
  were migrations of their own.

## Schema overview

- **Accounts/friends** (`0001`, `0002`): `users`, `sessions`,
  `email_verifications`, `friendships`.
- **Card catalog** (`0003`): `cards` — reference data for the full 133-card
  Mood Swings pool (name, color, rarity, value(s), printed rules text, and
  which of the three ability timings it has). Seeded once by the migration
  itself, not written by the app. See the migration file's header comment
  for what's deliberately *not* in there (Hurt Feelings, the Love Headliner
  treatment) and why.
- **Sets** (`0015`): `sets` (a printed product, identified by a short code —
  e.g. `MSW`/"Mood Swings", the only one that exists today) and `card_sets`,
  a many-to-many join between `cards` and `sets` rather than a `set_id`
  column directly on `cards`, since a card is expected to eventually
  reappear in a later set (a reprint, a crossover product, etc.) even
  though every card belongs to exactly one Set today. Seeded once by the
  migration itself, linking all 133 existing cards to `MSW`.
- **Games** (`0004`): `games`, `game_players`, `game_rounds`,
  `game_round_scores`, `game_cards`, `game_events` — a played match, its
  seated players, its rounds and their scores, where every physical card
  currently is (deck/hand/in-play/discard, ownership, suppression), and an
  append-only log of every action taken so a game can be studied after the
  fact. See that migration's comments for the reasoning behind each design
  choice (e.g. why Hurt Feelings is a round attribute and not a card, and
  why history is a separate event log rather than derived from the
  current-state tables). `games` tracks four points in its life --
  `created_at`/`started_at`/`completed_at` from `0004` itself, plus
  `last_move_at` (`0017`, stamped after every successful play/pass/decision
  response) -- see "Game timestamps" in `php-app/README.md`. `0016` adds
  `deck_type = 'jceddys_75'`, a fourth algorithmically-assembled pool (15
  cards per color -- 1 Mythic, 2 Rares, 4 Uncommons, 8 Commons -- 75 total)
  alongside `structure`/`power`/`one_of_each`. `0018` adds
  `deck_type = 'custom'` plus `custom_deck_name`/`custom_deck_card_ids`,
  letting a Traditional game's creator supply their own decklist instead of
  one of the algorithmically-assembled pools -- see "Custom decklists" in
  `php-app/README.md`. `0019` adds `deck_type = 'custom_duel'` plus
  `custom_duel_rules_preset`/`custom_duel_min_cards`/
  `custom_duel_rarity_limits`/`custom_duel_duplicate_limits` on `games`
  (the deck-building rules a Duel game's creator defines) and
  `custom_deck_name`/`custom_deck_card_ids` on `game_players` (each duel
  player's own submitted decklist, validated against those rules) -- see
  "Custom decklists for Duel games" in `php-app/README.md`. `0020` adds
  `custom_duel_even_color_distribution_rarities` on `games`, an optional
  fourth `custom_duel` rule requiring a given rarity's cards be split evenly
  across all 5 colors -- see "Custom decklists for Duel games" in
  `php-app/README.md`.

  This covers the data model only. The actual rules engine (resolving each
  card's effect, turn/phase flow, scoring) lives in `php-app/` -- see its
  own README, in particular `src/Rules/` (the card-effect/board-state
  engine, built incrementally against `cards.rules_text` and keyed off
  `cards.effect_key`) and `src/Game/GameService.php` (the game/session layer
  on top of it).
- **Application/system** (`0021`): `schema_version` — a single row
  (`id = 1`) recording which `VERSION` the currently-applied schema
  satisfies. Compared against the deployed `VERSION` file on every request
  by `MaintenanceGate` (see `php-app/README.md`), so the app shows a
  maintenance page instead of running against a schema a migration hasn't
  been manually applied to yet — see "Adding a new migration" above for the
  convention every future schema-changing migration must follow to keep
  this working.
- **Open Team Play** (`0022`): adds `winner_team_id` to `games`/
  `game_rounds`, `team_turn_1_game_player_id`/`team_turn_2_game_player_id`
  to `game_rounds`, and a new `game_team_decisions` table (a propose/confirm
  state machine for a team's live "which of us takes this turn" and
  "which of us gets the shared draw" choices — a card-effect decision has a
  `played_card_id` to hang off of, these don't, hence a dedicated table
  rather than reusing `game_pending_decision_batches`). `format = 'team'`
  and `game_players.team_id` themselves already existed, unused, since
  `0004` — see "Open Team Play" in `php-app/README.md` for the format
  itself. Reuses `0011`'s "NULL is distinct in a UNIQUE index" trick
  (`active_marker`) to guarantee at most one open `game_team_decisions` row
  per round.
- **Closed Team Play** (`0023`): adds `format = 'closed_team'` (partners
  seated across from each other rather than adjacent, hands stay private,
  and only one "who leads this round" choice exists per round instead of
  Open Team Play's two forced turn placements — see "Closed Team Play" in
  `php-app/README.md`). Reuses `0022`'s `game_team_decisions` table as-is
  for that one leader choice and the shared-draw choice; needs no
  equivalent of `team_turn_1/2_game_player_id` since the chosen leader is
  written straight into `game_rounds.first_game_player_id`. Also adds
  `game_initial_card_passes`, a new table for this format's own pregame
  mechanic with no Open Team Play analog: every player blindly passes 2
  cards to their teammate before round 1 can begin — one row per player
  per game, inserted the moment they submit (so their choice can never be
  revised once their teammate's own hand is visible).
- **Shock targeting fix** (`0024`): no schema change — a pure
  `UPDATE schema_version` to keep it in sync with a `VERSION` bump for a
  backend bug fix (Shock and 5 other cards' `choice_fields` could never
  offer a target whose value only qualifies once the played card itself
  is counted — see `GameService::withSimulatedMoodCandidates()`). Every
  prior `VERSION` bump so far rode along with an actual schema-changing
  migration; this is the first one that doesn't, since `MaintenanceGate`
  would otherwise show maintenance mode after deploy purely from the
  version mismatch, with no real pending migration to explain it.
- **Hope perpetual-grant fix** (`0025`): another schema-less
  `UPDATE schema_version`, same rationale as `0024` — Hope's (and Grace's/
  Stubbornness's) perpetual extra-play grant only ever looked up the
  first qualifying in-play mood each turn, so two independent copies
  granted just one extra play instead of two (see
  `GameService::effectiveSourceCardIds()`).
- **Play grant choice/visibility/loss-logging** (`0026`): another
  schema-less `UPDATE schema_version`, same rationale as `0024`/`0025` — a
  batch of extra-play-grant work: letting a player choose which
  outstanding grant to spend when 2+ would cover the same play
  (`BoardState::usableGrants()`/`grant_source_card_id`), surfacing whether
  an in-play Hope/Grace still has an unused grant
  (`has_unused_play_grant`), and logging it to the game's event log when a
  Hope's/Grace's own grant is lost unused because its source card left
  play before it was spent (`BoardState::consumeGrantsLost()`) — see
  `php-app/README.md`.
- **Quick Draft** (`0027`): adds `deck_type = 'quick_draft'` plus
  `draft_match_id`/`match_game_number` on `games`, and three new tables —
  `draft_matches` (one row per best-of-three match: pool config, drafting/
  deck_building/completed status, current round, winner), `draft_match_players`
  (per `(draft_match_id, user_id)`: the fixed 16-card `drafted_card_ids`
  result of the draft, the player's current 14-16 card `deck_card_ids`, and
  this match's own win counter — keyed by `user_id` rather than
  `game_player_id` since this data spans up to 3 separate `games` rows, one
  per game of the match), and `draft_round_picks` (one row per player per
  draft round: cards drawn and kept at each of the round's two blind
  sub-steps — passed/received/discarded cards are derived from these at
  read time, never stored separately). See "Quick Draft" in
  `php-app/README.md`.
- **Draft format** (`0028`): adds `format = 'draft'` — functionally
  identical to `'duel'` (same 2-player, separate-per-player-deck rules
  engine), but scoped to deck_type values that build a deck through some
  kind of live drafting process. `quick_draft` (`0027`, previously gated
  on `format = 'duel'`) is the first such deck_type and, for now, the only
  one `'draft'` supports; more are expected to join it later. See "Quick
  Draft" in `php-app/README.md`.
- **Quick Draft sideboard prefill** (`0029`): adds
  `draft_match_players.previous_deck_card_ids` — a copy of whatever
  `deck_card_ids` held right before it's nulled out for the next game in
  the match, so the frontend's sideboard picker can default to the deck
  the player actually used last game instead of every drafted card. Plays
  no part in `startGame()`'s "deck submitted" gate, which still only ever
  looks at `deck_card_ids`. See "Quick Draft" in `php-app/README.md`.
