# Repo conventions for Claude

## Announcing a development → main merge

When a `development` → `main` release PR merges (or otherwise announcing
one), summarize what's included in **10 bullet points or fewer** — not a
long prose recap of every constituent PR.

This applies to the automated Discord deploy announcement too, not just
a chat-message summary. `deploy.yml`'s "Announce deploy on Discord" step
posts `.github/release-notes/<VERSION>.md` verbatim if it exists, and
otherwise falls back to `auto_changelog.sh`'s flat one-bullet-per-commit
list, which does not respect the 10-bullet limit. So the
`development` → `main` promotion PR itself must include a curated
`.github/release-notes/<VERSION>.md` file (named after the exact
`VERSION` being promoted, ≤10 bullets — see
`.github/release-notes/README.md`) whenever the promotion PR is opened,
not added after the fact.

## Versioning (VERSION file / schema_version)

`VERSION` follows `major.minor.patch`. A new full feature (a new
deck_type, a new game mode, anything a "### Feature Name" section of its
own in `php-app/README.md`) bumps the **minor** version and resets patch
to 0 (e.g. `1.28.67` → `1.29.0`). A small fix, tweak, or UI adjustment
bumps the **patch** version only (e.g. `1.29.0` → `1.29.1`). The
accompanying migration's `UPDATE schema_version SET version = ...`
statement always matches whatever `VERSION` was bumped to.
