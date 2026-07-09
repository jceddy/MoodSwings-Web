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
    since that's how the rules engine resolves them. Duplicity no longer
    adds anything to this panel at all: playing any card with its own
    after-playing effect (including a zero-field one like Charity) while
    you have Duplicity in play instead pauses the round afterward and
    offers you — the acting player, same as if you'd targeted yourself —
    a repeat via the same pending-decision panel described below, one
    independent offer per Duplicity-effective source you have in play (a
    second one appears if you also have a Creativity currently copying
    Duplicity). That panel's "repeat this mood's own effect" checkbox
    expands an indented, nested copy of the played card's own
    after-playing fields for a second, independent set of choices — e.g.
    repeating Dignity offers a second, separately-filtered
    card-to-discard dropdown, since the same card can't be discarded
    twice. Guile's and Regret's mandatory discard cost is excluded from
    the nested repeat form (a repeat only re-runs the after-playing
    effect, not the cost to play), leaving just their target-mood field.
    Creativity's panel shows a dropdown to play it as
    an exact copy of any mood currently in play (any player's) — left
    blank, it's just a blue card worth 0. Picking one dynamically swaps in
    that mood's own fields (its own "to play" cost, if any, and its own
    after-playing choices — e.g. copying Compulsion adds its
    `target_player_id` field, copying Guile adds both its 2-card discard
    cost and its `target_mood_id`), plus Scorn's/Validation's own
    reactions if you have those in play and the copied mood's
    color/value qualifies — all precomputed
    per candidate server-side (`copy_simulation`, see `php-app/README.md`)
    so switching candidates needs no round trip. If the copied mood has
    its own "to play" cost that can't currently be paid, Play stays
    disabled with an inline message the same way an ordinary unpayable
    card's would. Each card's `is_playable` flag
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
    moves -- suspended while the choices panel or a pending-decision panel
    is open, so a card being actively composed doesn't get its fields
    reset out from under the player. Passing closes the choices panel the
    same way a successful play does (rather than leaving whatever hand
    card you'd opened to consider playing sitting there instead), since
    otherwise polling would stay silently suspended until the panel was
    manually cancelled, even though the pass itself went through fine.
    Every mood in play and every card in the discard pile is also
    clickable, opening a read-only detail view (name, base value, alt
    value if it has one, current value if a while-in-play effect has
    changed it, owner, rules text, and — if it's currently suppressed — an
    indicator naming the suppressing mood, if the game tracks one, and
    whether the suppression lasts as long as that mood stays in play or
    just until the end of the current round) so an unfamiliar card can be
    checked before deciding how to respond to it. Seven cards (Arrogance,
    Compulsion, Disillusionment, Instability, Intimidation, Malice,
    Suspicion) hand part of their effect to a player other than whoever's
    turn it is, and three more (Avoidance, Confusion, Fury) say a player
    "chooses" (not "at random") what to give up to a direction-based
    neighbor or discard — playing any of these ten pauses the whole round
    (Play/Pass both disabled for everyone, a banner names who's being
    waited on) via `round.pending_decision` in the state response. The one
    player it's actually waiting on sees a response panel instead of the
    banner, reusing the exact same field-rendering code as the regular
    choices panel (including multi-select validation for Malice's two
    moods and the `mode` dropdown for Disillusionment's color) — answered
    via `POST /games/respond`. Suspicion, Disillusionment, Avoidance,
    Confusion, and Fury all queue one decision per player (Disillusionment's
    queue starts with the next player in turn order and wraps around to
    the acting player themselves last; Avoidance's, Confusion's, and
    Fury's queue every player with a qualifying mood/hand card, including
    the acting player — Fury further narrows each player's own options to
    just the mood(s) tied for that player's own highest value); only the
    one player currently up in the queue is prompted at a time, and the
    round only unfreezes once the last one has answered. Duplicity's
    repeat offer (see above) uses this exact same pause-and-respond
    mechanism, just targeting the acting player themselves instead of an
    opponent — its panel shows a nested checkbox-plus-fields shape rather
    than a single field, which the response panel renders the same way
    the ordinary choices panel already renders nested fields. There's no
    timeout — if a targeted player (including yourself, for a Duplicity
    repeat) goes AFK the round just stays paused. Enthusiasm's and
    Passion's own "you may" scoring bonuses use this same pause, but
    triggered by the round ending rather than by a play: unlike
    Exhilaration/Bliss (no "may" in their printed text, so always applied
    automatically), taking Enthusiasm's/Passion's bonus isn't always
    correct — a card like Sneakiness swaps its owner's whole final score
    with a chosen opponent's, so a bigger pre-swap score can just mean
    handing your opponent more of one once the swap lands. Since that's
    close to impossible to reason about blind, `round.scoring_preview`
    (present only while one of these is being decided) shows every
    player's running score-so-far plus who's swapping with whom, right
    above the response panel, the same way suppression info is shown
    before you decide how to respond to a card you don't recognize.
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
