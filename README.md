# MoodSwings-Web

A web-based simulator for the Mood Swings TCG.

## Repository structure

This repository is organized into three independent projects:

- [`php-app/`](php-app/) — The PHP application implementing the game/simulator logic.
- [`database/`](database/) — The MySQL schema and related database assets.
- [`web-static/`](web-static/) — Static web content (HTML/CSS/JS/images) served to the browser.

See each project's own README for setup and details.

## Deployment

`.github/workflows/deploy.yml` deploys to Bluehost over FTP on every push to
`main`. It merges `web-static/` and `php-app/` into a single site: static
files serve from the domain root, and the PHP app is reachable under `/app`
(e.g. `/app/health`) via `php-app/public/.htaccess`'s rewrite rule.

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

