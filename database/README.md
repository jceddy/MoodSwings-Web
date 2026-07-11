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
  response) -- see "Game timestamps" in `php-app/README.md`. `0018` adds
  `deck_type = 'custom'` plus `custom_deck_name`/`custom_deck_card_ids`,
  letting a Traditional game's creator supply their own decklist instead of
  one of the algorithmically-assembled pools -- see "Custom decklists" in
  `php-app/README.md`.
  
  This covers the data model only — the actual rules engine (resolving
  each card's effect, turn/phase flow, scoring) is future work, built
  incrementally against `cards.rules_text` and keyed off `cards.effect_key`.
