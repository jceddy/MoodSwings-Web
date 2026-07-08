# web-static

Static web content (HTML/CSS/JS/images) for MoodSwings-Web.

Served directly by the web server, or proxied alongside the [`php-app`](../php-app)
backend (e.g. static assets served from this directory, API/dynamic requests
routed to the PHP app).

## Pages

- `index.html` (`/`) — Login form. If the visitor already has an active
  session (checked via `GET /app/me`), they're redirected straight to
  `/game/`. Links to `register.html`.
- `register.html` — Registration form. On success, shows a message to check
  email for the verification link (login is blocked until verified).
- `game/index.html` (`/game/`) — Redirects to `/` if there's no active
  session; otherwise shows the logged-in username, a logout button, a
  "Friends" button (see below), and the game lobby/board itself:
  - **Lobby**: your games (via `GET /games`), each showing opponents,
    status, and whether it's your turn; a "New game" dialog picks 1-3
    friends (via `GET /friends`) plus a format, then calls `POST /games`.
  - **Board**: players, whose turn it is, in-play moods, the discard pile,
    deck count, and your hand (via `GET /games/state`). Playing a card
    opens a generic choices panel — since each of the ~127 cards' `choices`
    payload has its own shape (see `php-app/src/Rules/PlayerChoices.php`),
    the panel offers the common choice kinds (target player, target mood
    in play, a card to discard, cards to reveal, a mode string) rather
    than a bespoke prompt per card; `POST /games/play` only reads the keys
    the played card actually asks for, and rejects the rest with a
    human-readable message if something required is still missing. Polls
    `GET /games/state` every 4 seconds while open to pick up opponents'
    moves.
  - A "Friends" button opens a `<dialog>` for managing friends: send a
    request by username/email, accept/decline/block incoming requests,
    view sent (outgoing) requests, and remove existing friends. All of it
    talks to the `/friends/*` endpoints.

All of the above talk to the PHP API at `/app/*` via `js/app.js`'s helpers,
using the same-origin `session_token` cookie for auth — see
[`../php-app/README.md`](../php-app/README.md) for the API itself.

## Layout

- `css/` — Stylesheets.
- `js/` — Client-side scripts (`app.js` holds shared API helpers; each page
  has its own small script wiring up that page's behavior).
