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
    deck count, and your hand (via `GET /games/state`). Clicking any hand
    card opens a panel showing its name and rules text plus Play/Cancel, so
    it doubles as a quick way to inspect a card you don't recognize yet;
    cards with no ability worth asking about (roughly half the 127-card
    pool) show that panel with no extra fields, everything else adds only
    the fields that specific card needs (a target player, a mood in play, a
    card to discard, a mode string, etc.), driven by each card's
    `choice_fields` in the state response (see `CardChoiceSchema` in
    `php-app/README.md`) rather than one form covering every card. Each
    dropdown is further narrowed to choices the card will actually accept
    (e.g. Guilt only lists black/red moods, Encouragement only lists moods
    with a dice value) via that same field's `filter`. Multi-select fields
    also get live client-side validation from the field's `count`
    (exact/minimum/maximum selection counts) and `constraint` (e.g. Denial's
    two chosen moods must share a color or value, Courage's must belong to
    different players, Anger's combined value can't exceed 5) — Play stays
    disabled with an inline message until the selection is actually legal,
    without a round trip to find out. If a filled-in choice is still
    rejected regardless, the rules engine's own human-readable message
    explains what's missing. If you have Scorn and/or Validation in play,
    every other hand card's panel also gets that reaction's field (Scorn's
    suppress-target, narrowed to moods sharing the card-to-be-played's own
    color; Validation's extra-play checkbox, only offered when that card's
    base value is 0 or 1) — both submitted in the same play request,
    since that's how the rules engine resolves them. If you have Duplicity
    in play, every other card that has its own after-playing effect
    (including a zero-field one like Charity) also gets a "repeat this
    mood's own effect" checkbox plus an indented, nested copy of that
    card's own after-playing fields (its `duplicity_repeat_choices`) for a
    second, independent set of choices — e.g. repeating Dignity offers a
    second, separately-filtered card-to-discard dropdown, since the same
    card can't be discarded twice. Guile's and Regret's mandatory discard
    cost is excluded from their nested repeat form (a repeat only re-runs
    the after-playing effect, not the cost to play), leaving just their
    target-mood field. Creativity's panel instead shows a dropdown to play
    it as an exact copy of any mood currently in play (any player's) —
    left blank, it's just a blue card worth 0; because that choice
    resolves server-side in the same request, Creativity never offers a
    Duplicity repeat option of its own (see `php-app/README.md` for the
    same gap noted from the server side). Each card's `is_playable` flag
    reflects whether that *specific* card is currently legal to play — not
    just whether it's your turn at all, but whether some outstanding play
    grant this turn actually covers it (e.g. after Intimidation bounces a
    revealed card into your hand, that's the only card covered until some
    other grant makes more of your hand playable) and, for a card with a
    "to play" cost, whether that cost could be paid at all right now (e.g.
    Guile with fewer than two other cards in hand). The hand button itself
    always stays clickable when it's your turn, so an unplayable card's
    rules text can still be inspected -- it's just given a dashed,
    lower-opacity look and a tooltip as a heads-up, and its panel opens
    with Play already disabled and an inline "This card can't be played
    right now" message instead of the usual field-by-field validation.
    Polls
    `GET /games/state` every 4 seconds while open to pick up opponents'
    moves. Every mood in play and every card in the discard pile is also
    clickable, opening a read-only detail view (name, base value, alt
    value if it has one, current value if a while-in-play effect has
    changed it, owner, rules text, and — if it's currently suppressed — an
    indicator naming the suppressing mood, if the game tracks one, and
    whether the suppression lasts as long as that mood stays in play or
    just until the end of the current round) so an unfamiliar card can be
    checked before deciding how to respond to it. Seven cards (Arrogance,
    Compulsion, Disillusionment, Instability, Intimidation, Malice,
    Suspicion) hand part of their effect to a player other than whoever's
    turn it is — playing one of these pauses the whole round (Play/Pass
    both disabled for everyone, a banner names who's being waited on) via
    `round.pending_decision` in the state response. The one player it's
    actually waiting on sees a response panel instead of the banner,
    reusing the exact same field-rendering code as the regular choices
    panel (including multi-select validation for Malice's two moods and
    the `mode` dropdown for Disillusionment's color) — answered via `POST
    /games/respond`. Suspicion and Disillusionment queue one decision per
    player (Disillusionment's queue starts with the next player in turn
    order and wraps around to the acting player themselves last); only
    the one player currently up in the queue is prompted at a time, and
    the round only unfreezes once the last one has answered. There's no
    timeout — if a targeted player goes AFK the round just stays paused.
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
