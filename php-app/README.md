# php-app

Plain PHP application implementing the MoodSwings-Web simulator, using PDO to
talk to the MySQL database defined in [`../database`](../database).

## Setup

```sh
composer install
cp .env.example .env   # then edit with your local MySQL credentials
```

Apply the database migrations (see [`../database`](../database) for details):

```sh
composer migrate
```

then start the built-in dev server:

```sh
php -S localhost:8000 -t public
```

Visit `http://localhost:8000/health` to verify the app can connect to the
database.

## Layout

- `public/` — Web server document root / front controller.
- `src/` — Application source (PSR-4 autoloaded under `MoodSwings\`).
- `bin/migrate.php` — Applies pending database migrations from
  `../database/migrations/` (see that project's README).
- `tests/` — PHPUnit tests.

## API

All responses are JSON with a `status` field (`ok` or `error`), except
`/verify-email` — that one's opened directly from an emailed link by a
human rather than called by our own JS, so it renders an HTML page
instead.

| Method | Path            | Body                                                          | Notes |
| ------ | --------------- | -------------------------------------------------------------- | ----- |
| GET    | `/health`       | —                                                                | Checks DB connectivity. |
| POST   | `/register`     | `{"username", "email", "password", "phone_number"?}`             | Creates an unverified user and emails a verification link. Username: 3-32 chars (letters/numbers/`_`/`-`); email: valid format; password: 8-72 chars; phone (optional): 7-20 chars, digits/`+`/`-`/`.`/spaces/parens. `409` on duplicate username/email, `400` on validation failure, `502` if the verification email can't be sent (registration is rolled back so you can retry). |
| GET    | `/verify-email` | query param `token`                                              | HTML page (not JSON). On success, auto-redirects to `/` after 5 seconds (plus a manual link). `400` with just a manual link (no auto-redirect) if the token is invalid/expired. |
| POST   | `/resend-verification` | `{"email"}`                                                | Issues a fresh verification link, revoking any prior one, and emails it. Always returns the same generic `200` message regardless of whether the email exists, is already verified, or was rate-limited, so it can't be used to discover which addresses are registered. Limited to once per 60 seconds per account; `400` on invalid email format, `502` if sending fails. |
| POST   | `/login`        | `{"username", "password"}`                                       | `401` on bad credentials, `403` if the email isn't verified yet. |
| POST   | `/logout`       | —                                                                 | Invalidates the current session only (other logged-in devices/sessions are unaffected). |
| GET    | `/me`           | —                                                                 | Returns the current user if authenticated, `401` otherwise. |
| GET    | `/friends`      | —                                                                 | Requires auth. Lists accepted friends (`friend_id`, `friend_username`, `created_at`). |
| GET    | `/friends/invites` | —                                                              | Requires auth. Returns `{"incoming": [...], "outgoing": [...]}`, each entry has `other_user_id`/`other_username`/`created_at`. |
| POST   | `/friends/invite` | `{"username_or_email"}`                                        | Requires auth. Sends a friend request; looks up the target by username first, then email. `404` if no such user, `409` if you already have a request/friendship/block with them (or if you invite yourself) — the message is deliberately generic when they've blocked you, so you aren't told that specifically. |
| POST   | `/friends/respond` | `{"user_id", "action"}`                                        | Requires auth. `action` is `accept`, `decline`, or `block`, responding to the pending invite from `user_id`. Declining just removes the request (not punitive — they can invite you again); blocking permanently prevents future invites from that user. `403` if you try to respond to your own outgoing invite, `404` if there's no such pending invite, `400` for an invalid `action`. |

Auth-requiring routes use the same `session_token` cookie as `/me` (`401` if
missing/invalid). Friendships are stored as one row per pair of users
(see `database/migrations/0002_create_friendships_table.sql`), so each pair
can only ever have a single pending/accepted/blocked relationship — there's
no separate "invite" record that outlives the relationship it represents.

Authentication uses an httpOnly, `Secure`, `SameSite=Lax` cookie
(`session_token`) holding a random token; only its SHA-256 hash is stored in
the `sessions` table (see `database/migrations/0001_baseline.sql`), so a database leak alone
can't be used to log in. Sessions last 30 days and slide forward on each
authenticated request.

Verification links are single-use and expire after 24 hours; email is sent
via SMTP (PHPMailer) using the `SMTP_*` variables in `.env` (see
`.env.example`). `APP_URL` (no trailing slash) is used to build the link,
e.g. `https://example.com/app` if deployed under `/app`. Phone numbers and
verified email are captured for future notification use but nothing sends
notifications yet.

If a verification email fails to send, the real error (e.g. the SMTP
error PHPMailer raised) is appended to `src/mail-errors.log` — a fixed,
predictable location rather than PHP's ambient `error_log()` destination,
which varies by host and isn't always what cPanel's error log page shows.
`src/` already has a deny-all `.htaccess` from the deploy workflow, so
that file isn't web-accessible; check it via cPanel's File Manager or FTP,
not a browser.

## Tests

Unit tests run without a database. The `AuthIntegrationTest` suite exercises
registration/login/session-tracking against a real MySQL-compatible
database and is skipped automatically if one isn't reachable. To run it
locally, provision a throwaway database with the migration runner:

```sh
DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=moodswings_test DB_USER=root DB_PASSWORD= \
composer migrate
```

then point the tests at it via environment variables (all optional, shown
with their defaults):

```sh
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_NAME=moodswings_test \
TEST_DB_USER=root TEST_DB_PASSWORD= vendor/bin/phpunit
```

The test suite truncates `users`/`sessions`/`email_verifications`/
`friendships` in that database before each test, so never point it at a
database with real data.
