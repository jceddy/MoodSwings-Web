# Repo conventions for Claude

## Announcing a development → main merge

When a `development` → `main` release PR merges (or otherwise announcing
one), summarize what's included in **10 bullet points or fewer** — not a
long prose recap of every constituent PR.

## Versioning (VERSION file / schema_version)

`VERSION` follows `major.minor.patch`. A new full feature (a new
deck_type, a new game mode, anything a "### Feature Name" section of its
own in `php-app/README.md`) bumps the **minor** version and resets patch
to 0 (e.g. `1.28.67` → `1.29.0`). A small fix, tweak, or UI adjustment
bumps the **patch** version only (e.g. `1.29.0` → `1.29.1`). The
accompanying migration's `UPDATE schema_version SET version = ...`
statement always matches whatever `VERSION` was bumped to.
