# MoodSwings-Web

A web-based simulator for the Mood Swings TCG.

## Resources

- [Rules](https://magic.wizards.com/en/news/feature/mood-swings-extended-rules)
- [Formats](https://magic.wizards.com/en/news/feature/other-ways-to-play-mood-swings)
- [Card Specific Rulings](https://magic.wizards.com/en/news/feature/mood-swings-card-notes)
- [Card Gallery](https://magic.wizards.com/en/news/card-image-gallery/mood-swings)
- [Moodiest (Card Repository)](https://moodiest.app/)
- [Moodfall (another Card Repository)](https://moodswings.scryfall.com/)
- [Discord](https://discord.gg/GgHFEBAd6C)
- [Reddit](https://www.reddit.com/r/moodswingstcg/)

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
`0.2.0`. Follows [Semantic Versioning](https://semver.org/)
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

**Hard requirement: any change that includes a database migration must also
bump `VERSION`.** The deployed app compares `VERSION` against a version
value stored in the database (`schema_version`, see `database/README.md`)
on every request, and shows a maintenance page on any mismatch — see
`MaintenanceGate` in `php-app/README.md`. This exists because production's
GitHub Actions runner has no direct access to the database (it can only
reach it indirectly, by asking the already-deployed app to apply pending
migrations itself — see "Deployment" below); without this check, a deploy
that shipped code depending on a not-yet-applied migration would run
silently against a stale schema for however long that request takes,
instead of visibly blocking traffic until the migration catches up.

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

Deploys aren't atomic: the FTP action uploads changed files one at a time,
so for the (typically brief) duration of a deploy, different requests can
hit a mix of old and new files rather than a single consistent before/after
state. Anything reacting to a deploy having landed (e.g. `web-static/js/app.js`'s
version watcher, see `web-static/README.md`) needs to account for this
rather than assuming the first sign of change is already the final state.

Once the migration and health-check steps above both succeed, each workflow's
final "Announce deploy on Discord" step posts a message — the deployed
`VERSION`, the environment's own `SITE_URL`, and the commit subjects (capped
at 15, `--no-merges`) introduced since the previous deploy on that
branch — into one shared Discord channel, using the app's own bot token
(production's `DISCORD_BOT_TOKEN`, dev's own `DEV_DISCORD_BOT_TOKEN`) rather
than the per-user DM path `DiscordNotificationChannel.php` uses elsewhere.
It's entirely optional: unless `DISCORD_ANNOUNCE_CHANNEL_ID` (below) is set,
this step is skipped rather than failing the deploy. A failed migration or
health check also skips it, so a broken deploy is never announced as if it
succeeded.

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
   - `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` — for browser
     push notifications. Generate a keypair with `php -r 'require
     "vendor/autoload.php"; print_r((new
     Minishlink\WebPush\VAPID)::createVapidKeys());'` from `php-app/`, or
     the `web-push` npm CLI; `VAPID_SUBJECT` is a `mailto:`/`https://` URL
     identifying you, e.g. `mailto:you@example.com`. See "Browser push
     notifications" in `php-app/README.md` for details. Without these set,
     `GET /notifications/vapid-public-key` returns an empty key and the
     frontend shows "Push notifications are not configured on the server
     yet."
   - `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`, `DISCORD_PUBLIC_KEY`,
     `DISCORD_BOT_TOKEN` — for Discord account linking/notifications (issue
     #232), **production's own Discord Application** (dev gets a second,
     separate one — see "Development environment setup" below, and
     "Discord" in `php-app/README.md` for why one Application can't serve
     both). From the [Discord Developer Portal](https://discord.com/developers/applications)'s
     General Information (Application ID, Public Key), Bot (Token), and
     OAuth2 → General (Client Secret) tabs, for an Application registered
     with a "User Install" installation context. See "Discord" in
     `php-app/README.md` for the full setup checklist and why the
     Interactions Endpoint URL itself has to be set in the portal
     separately, after this app is deployed.
   - `MIGRATION_DEPLOY_KEY` — lets each deploy apply its own pending
     `database/migrations/*.sql` files automatically (any sufficiently
     random string, e.g. `openssl rand -hex 32`). Shared with
     `deploy-dev.yml` (dev), same reasoning as `SMTP_*`/`VAPID_*` above.
     See "Auto-applying migrations on deploy" in `database/README.md` for
     how this is used; optional in the sense that skipping it just falls
     back to applying migrations by hand (step 5 below), not a hard
     requirement to get a deploy working at all.
4. Optionally add these **variables** (same Settings page, "Variables" tab):
   - `FTP_SERVER_DIR` — remote path to deploy into. Defaults to
     `/public_html/` if unset.
   - `APP_URL` — your live site's base URL including the `/app` path (e.g.
     `https://example.com/app`), used to build the verification link sent
     in the registration email.
   - `SITE_URL` — your live site's base URL (e.g. `https://example.com`),
     domain root only, no `/app`. If set, the workflow curls
     `$SITE_URL/app/health` after each deploy as a smoke test (and, if
     `MIGRATION_DEPLOY_KEY` is also set, `POST`s to `$SITE_URL/app/migrate`
     just before that to apply any pending migration); the app itself also
     reads it (via `.env`) to build links back into the static frontend,
     e.g. the post-Discord-link redirect to `/game/` -- see
     `siteRootUrl()` in `php-app/README.md`'s "Discord" section. If unset,
     the app derives it from `APP_URL` instead, so this is optional but
     recommended.
   - `DISCORD_ANNOUNCE_CHANNEL_ID` — the id of the Discord channel to post
     deploy announcements into (right-click the channel in Discord with
     Developer Mode on → **Copy Channel ID**). Not sensitive (unlike the
     bot token itself), so this lives with the other variables rather than
     the secrets above. Shared with `deploy-dev.yml` (dev) — see
     "Development environment setup" below — so both environments'
     deploys announce into the same channel. Both the production bot
     (`DISCORD_BOT_TOKEN`, from step 3 above) and the dev bot
     (`DEV_DISCORD_BOT_TOKEN`, see below) need to actually be members of
     the server that channel is in, with permission to view it and send
     messages there — inviting a bot with only the OAuth2 scopes needed
     for account linking (per "Discord" in `php-app/README.md`) doesn't
     add it to any server on its own; use the Developer Portal's OAuth2
     URL Generator with the `bot` scope and "Send Messages"/"View
     Channel" permissions to generate an invite link for this. If unset,
     the "Announce deploy on Discord" step above is skipped entirely.
5. Create the database itself. A brand-new database still needs its
   initial schema applied by hand — this repo's GitHub Actions runner
   cannot reach Bluehost's MySQL directly, so run each file in
   `database/migrations/` (in order) yourself via phpMyAdmin's SQL tab in
   cPanel (or Bluehost's Remote MySQL feature if you prefer a local
   client), or paste `database/consolidated/`'s merged script in one go.
   See [`database/README.md`](database/README.md) for details. Every
   *later* migration, once the app is deployed and `MIGRATION_DEPLOY_KEY`
   is set, applies itself automatically on the deploy that introduces it.

Once secrets are set and `main` has the schema-backed database ready, a push
to `main` deploys automatically, applying any pending migration along the
way. If `MIGRATION_DEPLOY_KEY` isn't set, deploys still only push
application files — whenever a PR adds a new file under
`database/migrations/`, apply it to the production database yourself
before (or right after) that PR's changes go live, the same way as the
initial setup above.

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
   Discord is the opposite of SMTP here: `DEV_DISCORD_CLIENT_ID`/
   `DEV_DISCORD_CLIENT_SECRET`/`DEV_DISCORD_PUBLIC_KEY`/
   `DEV_DISCORD_BOT_TOKEN` need their own, second Discord Application
   (same Developer Portal, same tabs as production's own — see "Discord"
   in `php-app/README.md`), since a Discord Application can only ever
   point its Interactions Endpoint/OAuth2 redirect at one URL.
   `DEV_DISCORD_BOT_TOKEN` doubles as the token `deploy-dev.yml`'s own
   "Announce deploy on Discord" step posts with — this second Application's
   bot needs to be invited to (and have Send Messages/View Channel
   permission in) the same server/channel as production's own bot, per
   `DISCORD_ANNOUNCE_CHANNEL_ID` above, or dev deploys just won't announce.
3. Add the **variables**: `DEV_FTP_SERVER_DIR`, `DEV_APP_URL` (your dev
   domain's `/app` URL), `DEV_SITE_URL` (your dev domain's base URL). No
   separate `DEV_MIGRATION_DEPLOY_KEY` is needed — `deploy-dev.yml` reuses
   the same `MIGRATION_DEPLOY_KEY` secret from production's own step 3
   above, so if that's already set, dev's migrations already auto-apply
   too.
4. Create a **separate** database for the dev domain (do not point it at
   the production database) and apply `database/migrations/` to it the same
   way as production's step 5 — the two environments' data should stay
   fully independent, so testing on dev never risks live player data.
   Since this is a brand-new database, `database/consolidated/`'s merged
   script is the fastest way to do that in one paste — see "Applying
   migrations" in `database/README.md`.

Once these are set, a push to `development` (i.e. any feature PR merging
into it) deploys automatically to the dev domain, applying any pending
migration along the way (via the shared `MIGRATION_DEPLOY_KEY`). If that
secret isn't set, apply any new migration to the dev database yourself
when a PR merges into `development` instead — before, or right after, its
own dev deploy goes live.

## Credits

- **Developer:** [jceddy](https://github.com/jceddy)
- **Play testers:** Dr Potato, Tori_Tumbleweed

