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
plain DDL statements (no stored procedures/triggers with embedded
semicolons) since `bin/migrate.php` splits files on `;`.

## Layout

- `migrations/` — Ordered `.sql` files, one per schema change, applied in
  filename order.
