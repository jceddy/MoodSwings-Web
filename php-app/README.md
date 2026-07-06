# php-app

Plain PHP application implementing the MoodSwings-Web simulator, using PDO to
talk to the MySQL database defined in [`../database`](../database).

## Setup

```sh
composer install
cp .env.example .env   # then edit with your local MySQL credentials
```

Load the schema from `../database/schema.sql` into MySQL (see that project's
README), then start the built-in dev server:

```sh
php -S localhost:8000 -t public
```

Visit `http://localhost:8000/health` to verify the app can connect to the
database.

## Layout

- `public/` — Web server document root / front controller.
- `src/` — Application source (PSR-4 autoloaded under `MoodSwings\`).
- `tests/` — PHPUnit tests.

## API

All responses are JSON with a `status` field (`ok` or `error`).

| Method | Path        | Body                            | Notes |
| ------ | ----------- | -------------------------------- | ----- |
| GET    | `/health`   | —                                 | Checks DB connectivity. |
| POST   | `/register` | `{"username", "password"}`       | Creates a user and logs them in. Username: 3-32 chars (letters/numbers/`_`/`-`); password: 8-72 chars. `409` on duplicate username, `400` on validation failure. |
| POST   | `/login`    | `{"username", "password"}`       | `401` on bad credentials. |
| POST   | `/logout`   | —                                 | Invalidates the current session only (other logged-in devices/sessions are unaffected). |
| GET    | `/me`       | —                                 | Returns the current user if authenticated, `401` otherwise. |

Authentication uses an httpOnly, `Secure`, `SameSite=Lax` cookie
(`session_token`) holding a random token; only its SHA-256 hash is stored in
the `sessions` table (see `database/schema.sql`), so a database leak alone
can't be used to log in. Sessions last 30 days and slide forward on each
authenticated request.

## Tests

Unit tests run without a database. The `AuthIntegrationTest` suite exercises
registration/login/session-tracking against a real MySQL-compatible
database and is skipped automatically if one isn't reachable. To run it
locally, point it at a throwaway database via environment variables (all
optional, shown with their defaults):

```sh
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_NAME=moodswings_test \
TEST_DB_USER=root TEST_DB_PASSWORD= vendor/bin/phpunit
```

The test suite truncates `users`/`sessions` in that database before each
test, so never point it at a database with real data.
