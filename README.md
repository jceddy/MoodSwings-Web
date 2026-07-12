# MoodSwings-Web

A web-based simulator for the Mood Swings TCG.

## Repository structure

This repository is organized into three independent projects:

- [`php-app/`](php-app/) — The PHP application implementing the game/simulator logic.
- [`database/`](database/) — The MySQL schema and related database assets.
- [`web-static/`](web-static/) — Static web content (HTML/CSS/JS/images) served to the browser.

See each project's own README for setup and details.

## Branching & environments

Two long-lived branches, each deploying to its own domain:

- **`development`** — the integration branch. Feature/fix PRs merge here.
  Every merge auto-deploys to the dev domain via
  `.github/workflows/deploy-dev.yml`, so the dev site always reflects the
  latest merged work.
- **`main`** — production. Deploys to the live domain via
  `.github/workflows/deploy.yml`, unchanged from before `development`
  existed. `main` only moves forward via a periodic `development` -> `main`
  pull request, promoting a batch of already-merged, already-dev-tested
  changes to production on a controlled schedule rather than on every
  individual merge.

The two deploy workflows are otherwise identical (same build/artifact
steps) and read entirely separate `DEV_`-prefixed secrets/variables (see
"Development environment setup" below) so configuring, or misconfiguring,
the dev environment can never touch production's already-live credentials.

## Versioning

The three sub-projects deploy together as one site (see "Deployment" below),
so they share a single product version rather than each having their own —
tracked in the [`VERSION`](VERSION) file at the repo root, currently
`0.1.0`. Follows [Semantic Versioning](https://semver.org/)
(`MAJOR.MINOR.PATCH`), interpreted for this project as:

- **MAJOR** — a breaking change to the game/save data model that makes
  existing in-progress games or saved decklists incompatible (e.g. a
  migration that isn't purely additive), or a breaking change to the public
  API surface.
- **MINOR** — a backward-compatible new feature (a new card mechanic, game
  format, deck type, etc.).
- **PATCH** — a bug fix, or a change with no user-facing behavior at all
  (docs, refactors, internal cleanup).

Starting at `0.1.0` rather than `1.0.0` follows SemVer's own convention for
initial development: the public API/data model can still change in
backward-incompatible ways at any time before `1.0.0`, without that alone
requiring a MAJOR bump.

`VERSION` is bumped by hand as part of whatever PR the version change
belongs to — there's no automated enforcement of when or by how much. The
frontend fetches `VERSION` directly (a plain static file, deployed
alongside `index.html`) to render the version indicator described in
`web-static/README.md`.

## Deployment

`.github/workflows/deploy.yml` deploys to Bluehost over FTP on every push to
`main` (production); `.github/workflows/deploy-dev.yml` does the same on
every push to `development` (dev), reading its own separate set of secrets
— see "Branching & environments" above. Both merge `web-static/` and
`php-app/` into a single site: static files serve from the domain root, and
the PHP app is reachable under `/app` (e.g. `/app/health`) via
`php-app/public/.htaccess`'s rewrite rule.

Every `<script src="...">`/`<link href="...">` referencing a `.js`/`.css`
file gets `?v=<short commit SHA>` appended during the build, so browsers
that already cached an old version of a script (from before a page's
markup last changed) reliably fetch the new one instead of silently
keeping the stale cached copy.

### One-time setup

1. In cPanel, create (or reuse) an FTP account for deploys and note its
   host/username/password.
2. Get SMTP credentials for sending the registration verification email.
   A transactional email service (e.g. SendGrid, Mailgun, Postmark) is
   recommended over Bluehost's own mail server — shared-hosting IPs have
   no sending reputation of their own, which can mean mail gets silently
   filtered by providers like Gmail even with correct SPF/DKIM. These
   services have SMTP relays that work as a drop-in replacement (e.g.
   SendGrid: host `smtp.sendgrid.net`, port `587`, username `apikey`,
   password = an API key from your account, `SMTP_FROM_ADDRESS` = a
   sender you've verified with them) — no code changes needed either way,
   since `Mailer.php` just speaks plain SMTP to whatever's configured.
3. In your GitHub repo, go to **Settings → Secrets and variables → Actions**
   and add these **secrets**:
   - `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` — from step 1.
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` — credentials
     for the MySQL database the deployed app should use.
   - `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`,
     `SMTP_ENCRYPTION` (`tls` or `ssl`), `SMTP_FROM_ADDRESS`,
     `SMTP_FROM_NAME` — from step 2, used to send verification emails.
4. Optionally add these **variables** (same Settings page, "Variables" tab):
   - `FTP_SERVER_DIR` — remote path to deploy into. Defaults to
     `/public_html/` if unset.
   - `APP_URL` — your live site's base URL including the `/app` path (e.g.
     `https://example.com/app`), used to build the verification link sent
     in the registration email.
   - `SITE_URL` — your live site's base URL (e.g. `https://example.com`). If
     set, the workflow curls `$SITE_URL/app/health` after each deploy as a
     smoke test.
5. Create the database itself and apply its migrations — this repo's GitHub
   Actions runner cannot reach Bluehost's MySQL directly, so run each file
   in `database/migrations/` (in order) yourself via phpMyAdmin's SQL tab in
   cPanel (or Bluehost's Remote MySQL feature if you prefer a local client).
   See [`database/README.md`](database/README.md) for details.

Once secrets are set and `main` has the schema-backed database ready, a push
to `main` deploys automatically. Deploys only push application files —
whenever a PR adds a new file under `database/migrations/`, apply it to the
production database yourself before (or right after) that PR's changes go
live, the same way as the initial setup above.

### Development environment setup

Same steps as above, aimed at your dev domain/database instead, using the
`DEV_`-prefixed name of each secret/variable so FTP/DB credentials stay
entirely separate from production's — except SMTP, which is intentionally
shared: both `deploy.yml` and `deploy-dev.yml` read the same plain
`SMTP_*` secrets, since it's just a transactional-email sender rather than
something meaningfully different per environment, and dev verification
emails going out from the same already-configured sender isn't a concern.

1. A separate FTP account (or the same one, if it can already reach your
   dev domain's document root) for `DEV_FTP_SERVER`, `DEV_FTP_USERNAME`,
   `DEV_FTP_PASSWORD`.
2. Add the **secrets**: `DEV_FTP_SERVER`/`DEV_FTP_USERNAME`/
   `DEV_FTP_PASSWORD`, `DEV_DB_HOST`/`DEV_DB_PORT`/`DEV_DB_NAME`/
   `DEV_DB_USER`/`DEV_DB_PASSWORD`. No `DEV_SMTP_*` secrets are needed —
   `deploy-dev.yml` reuses production's own `SMTP_*` secrets from step 3
   above, so if those are already set, dev's email sending already works.
3. Add the **variables**: `DEV_FTP_SERVER_DIR`, `DEV_APP_URL` (your dev
   domain's `/app` URL), `DEV_SITE_URL` (your dev domain's base URL).
4. Create a **separate** database for the dev domain (do not point it at
   the production database) and apply `database/migrations/` to it the same
   way as production's step 5 — the two environments' data should stay
   fully independent, so testing on dev never risks live player data.
   Since this is a brand-new database, `database/consolidated/`'s merged
   script is the fastest way to do that in one paste — see "Applying
   migrations" in `database/README.md`.

Once these are set, a push to `development` (i.e. any feature PR merging
into it) deploys automatically to the dev domain. As with production,
apply any new migration to the dev database yourself when a PR merges into
`development` — before, or right after, its own dev deploy goes live.

