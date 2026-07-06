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
2. In your GitHub repo, go to **Settings → Secrets and variables → Actions**
   and add these **secrets**:
   - `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` — from step 1.
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` — credentials
     for the MySQL database the deployed app should use.
3. Optionally add these **variables** (same Settings page, "Variables" tab):
   - `FTP_SERVER_DIR` — remote path to deploy into. Defaults to
     `/public_html/` if unset.
   - `SITE_URL` — your live site's base URL (e.g. `https://example.com`). If
     set, the workflow curls `$SITE_URL/app/health` after each deploy as a
     smoke test.
4. Create the database itself and load the schema — this repo's GitHub
   Actions runner cannot reach Bluehost's MySQL directly, so run
   `database/schema.sql` yourself via phpMyAdmin in cPanel (or Bluehost's
   Remote MySQL feature if you prefer a local client).

Once secrets are set and `main` has the schema-backed database ready, a push
to `main` deploys automatically.

