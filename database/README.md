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
