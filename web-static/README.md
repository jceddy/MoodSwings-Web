# web-static

Static web content (HTML/CSS/JS/images) for MoodSwings-Web.

Served directly by the web server, or proxied alongside the [`php-app`](../php-app)
backend (e.g. static assets served from this directory, API/dynamic requests
routed to the PHP app).

## Version indicator

Every page has a `<footer>` with a `#app-version` span, populated by a
self-invoking snippet at the bottom of `js/app.js` (the one script every
page already loads) that fetches `/VERSION` -- a plain static text file
deployed alongside `index.html`, not one of the PHP app's own `API_BASE`
endpoints -- and renders it as e.g. "v0.2.0". Fetched with `cache:
'no-store'` so a page loaded shortly after a deploy can't keep showing a
stale, browser-cached version string. See "Versioning" in the top-level
README for what the version itself means and where it's bumped.

## Dark mode

Three modes, chosen via a `<select id="theme-select">` in every page's own
`<footer>` (`system`/`light`/`dark`, defaulting to `system`): honor the
OS/browser's `prefers-color-scheme` preference, force light, or force dark
regardless of what the OS says. Full theming (more than light/dark, a
"themes" concept) was scoped out of this pass -- see the "Implement dark
mode and/or themes" issue's own notes -- this only covers the two-mode
case plus the system default.

The color switch itself is CSS-only, in `css/style.css`: a `:root` block
defines light-mode custom properties (`--color-bg`, `--color-text`,
`--color-border`, etc.) that every other rule reads from rather than
hardcoding colors directly, plus a `color-scheme: light` declaration so
native form controls/scrollbars/default link colors follow along without
being restyled by hand. A `prefers-color-scheme: dark` media query
overrides those properties (and flips `color-scheme` to `dark`) whenever
the OS prefers dark -- but only when `documentElement` doesn't carry
`data-theme="light"`, so an explicit "Light" selection can still force
light mode against a dark OS. A separate `:root[data-theme="dark"]` rule
applies the same dark overrides unconditionally, forcing dark mode against
a light OS. This means the "System" default needs no JavaScript to react
live to an OS-level theme change -- the media query alone re-evaluates
automatically -- only the two explicit overrides need `data-theme` set at
all.

Getting that attribute set before first paint (so an explicit preference
never flashes the wrong theme first) needs to happen before `js/app.js`
even loads, since that script tag sits at the bottom of `<body>`. Each
page duplicates a tiny inline `<script>` at the top of its own `<head>`
that reads `themePreference` from `localStorage` and sets
`document.documentElement.dataset.theme` synchronously -- inlined and
repeated per page rather than factored into a shared external file, since
an external script would itself add back the network-round-trip delay
this exists to avoid. `js/app.js`'s own `initThemeSelect()` IIFE only
needs to keep the footer `<select>` in sync with that same stored
preference and write a new one back to `localStorage` (plus update
`data-theme` immediately) on `change` -- it doesn't need to set the
attribute on load, since the inline per-page script already did that job
by the time this file runs.

## Maintenance mode

`apiRequest()` (`js/app.js`) -- the single fetch wrapper every API-calling
function funnels through -- checks every response for `status: 503` with
`body.status === 'maintenance'` (see "Maintenance mode" in
`php-app/README.md`). On a match, it stashes the server's message and the
current page's path in `sessionStorage` (`maintenanceMessage`/
`maintenanceReturnTo`), redirects to `/maintenance.html`, and returns a
Promise that never resolves -- deliberately, so whatever the caller was
about to do with the response (e.g. `login.js` un-hiding the login form)
never runs while the page is already mid-navigation away. A module-level
`redirectingToMaintenance` flag short-circuits any further `apiRequest()`
calls once that's happened, since `window.location.href` doesn't stop
script execution synchronously -- without it, an in-flight `setInterval`
poll (e.g. `game.js`'s board polling) could fire another request and
re-write `sessionStorage` during the brief window before the browser
actually navigates.

`maintenance.html` reads the stashed message/return path in `js/maintenance.js`
(falling back to a hardcoded message and `/` if visited directly, e.g.
bookmarked), wires a retry button, and polls a raw `fetch('/app/me')`
(bypassing `apiRequest()`, so the poll itself can't re-trigger the redirect
logic) every 15 seconds -- once that stops returning `503`, it redirects
back to the stashed return path automatically. `register.html` makes an
otherwise-unused `getCurrentUser()` call on load (matching `login.js`/
`game.js`'s own first action) purely so `apiRequest()` gets a chance to
catch a maintenance window before the visitor ever submits the form.

### Version watcher

Separately from maintenance mode above (which reacts to a *pending*
deploy), `startVersionWatcher()` (`js/app.js`) reacts to a deploy that's
already *landed* while a session was left open -- e.g. a player leaves the
game page open across a deploy, whose HTML/JS/CSS never re-fetch on their
own once loaded. `game/index.html`'s top-level IIFE calls it once the
session is confirmed (right where `#game-main` is un-hidden); `index.html`/
`register.html` don't, since neither is a page a visitor stays on long
enough for this to matter, and both redirect away the moment a session
exists anyway.

It records the deployed `VERSION` at the moment it starts, then re-fetches
`/VERSION` (via the same `fetchDeployedVersion()` helper the footer
indicator uses) every 60 seconds; a value that differs from what was
recorded at start means a new deploy has landed since this page loaded, and
triggers a plain `window.location.reload()` -- the simplest way to pick up
new static assets, since a hard reload always re-fetches everything the
page references rather than trying to hot-swap individual scripts/styles.
Skips its check (rather than reloading) while `apiRequest()`'s own
`redirectingToMaintenance` flag is set, since a maintenance redirect is
already in flight at that point and a second, unrelated reload would just
race it. No special handling for an in-progress play -- a version bump is
expected to be infrequent enough, and the check interval coarse enough,
that this is an acceptable trade-off against the complexity of trying to
defer the reload until the board is idle.

A single differing fetch isn't enough to trigger a reload on its own,
though: the deploy pipeline uploads files one at a time over FTP, not as
one atomic swap (see "Deployment" in the top-level README), so a poll can
land mid-deploy and see a real but transient value that's about to be
overwritten again a moment later. Reloading straight into that window is
exactly how a stale/inconsistent version could flash up right after an
auto-refresh. So a differing value is only acted on after a second fetch,
3 seconds later, confirms the same value is still there -- if it isn't
(mid-deploy noise, or the value reverted), the check just waits for the
next regular poll instead. `fetchDeployedVersion()` itself also verifies
the response actually looks like a `MAJOR.MINOR.PATCH` version before
returning it, resolving to `null` (indistinguishable from a failed fetch)
for anything else -- an error page's HTML served with a stray `200`, or a
truncated/empty body from a mid-write read, so neither the footer nor the
watcher can ever mistake garbage content for a genuine version change.

## Assets

- `img/` -- Game-level art not tied to any specific printed card, e.g.
  `hurt-feelings.webp` (Hurt Feelings is a marker/token, not a `cards` row
  -- see migration `0003`'s own header comment for why -- so its art lives
  here rather than under `img/cards/`).
- `img/cards/<SET_CODE>/` -- Card art, one subfolder per Set, named after
  that Set's own `code` column (`sets.code` -- see migration `0015` and
  "Sets" in `database/README.md`). The official pool's art lives in
  `img/cards/MSW/` (`MSW` = the "Mood Swings" Set `0015` seeds); a future
  community/custom Set (see the "custom card sets" issue) would get its
  own sibling folder here, named after whatever `code` it's registered
  under, rather than mixing its art in with the official pool's. Each
  file is named `<cards.id>-<slugified-name>.webp` (e.g. `1-altruism.webp`
  for Altruism, `cards.id` 1) -- the numeric id is what actually keys the
  lookup (it's the real join key everywhere else, e.g.
  `game_cards.card_id`), the name suffix is purely for a human browsing
  the folder.

### Card art rendering

`game.js`'s `cardArtUrl(card)` builds a card's art URL from two API fields
`GameService::serializeCard()` returns -- `catalog_card_id` (see "Card
identity: catalog id vs. per-game instance id" in `php-app/README.md`) and
`name`, the latter slugified client-side by `slugify()` (lowercase,
non-alphanumeric runs collapsed to a single hyphen, trimmed) to match the
asset filenames above. The `MSW` set folder is hardcoded here rather than
threaded through the API, since it's the only Set that exists today -- see
the "custom card sets" issue for when that stops being true. `buildCardThumb()`
is the shared element builder every card zone (hand, in-play, discard pile)
uses in place of the old text-only buttons: a `<button class="card-thumb">`
containing the art `<img>` (its `alt` text is `name + '. ' + rules_text`,
covering what the printed art itself can't convey to a screen reader) plus
whatever ISN'T part of the static art overlaid as small badges on top of
it -- a current value badge (only shown when `card.value` differs from
`card.base_value`, since the printed value is already baked into the art
otherwise), a "Copy" badge for an in-play Creativity copy, and a
"Suppressed" ribbon. Ownership (issue #142) is no longer a per-thumb
caption -- it's redundant once every zone already identifies whose cards
are in it (see "Discard pile stacking" and "In-play board layout" below),
and it cost each card its own line of vertical space repeated across the
whole board. An unplayable hand card keeps the same dashed,
lower-opacity `.not-playable` treatment the old text button had, just
applied to the thumbnail instead. Two tabletop conventions are conveyed by
rotating the art itself (badges are siblings of the `<img>`, not children
of it, so they always stay upright and legible -- the rotation is a
visual reinforcement of the badge, never a replacement for it): a
suppressed in-play mood's `.card-thumb--suppressed` class
rotates it 90 degrees, and a mood whose `value_locked` flag is true --
a permanent "after playing this mood" trigger (Dignity, Delight, ...)
has locked in its alt value, as opposed to a continuously-recomputed
"while in play" value (Determination) -- see `value_locked` in
`php-app/README.md` -- gets its own `.card-thumb--value-locked` class,
rotating it 180 degrees. Both classes
can apply at once (a suppressed, value-locked mood), for which a third
CSS rule (`.card-thumb--suppressed.card-thumb--value-locked`) rotates
270 degrees rather than letting the two `transform`s silently clobber
each other. The card-detail dialog's enlarged
`#card-detail-art` image replaces what used to be a `<h3>` name heading and
a `<p>` rules-text paragraph -- both now conveyed only via that image's
`alt` text -- while every other line in the dialog (color, value, alt
value, suppression, ownership, "affecting"/"affected by", Bliss's discard
color, an unused play grant) stays plain text exactly as before, since none
of that is information the art itself carries. The last of those,
`#card-detail-unused-grant`, mirrors `card.has_unused_play_grant` (see
`php-app/README.md`) -- most relevant to an in-play Hope/Grace, since it's
the only visible way to tell whether that specific card's own bonus play
is still outstanding or has already been spent (losing track of that
matters more for Hope/Grace than an ordinary grant -- see
`BoardState::grantIsActive()`'s own docblock).

### Discard pile stacking

A discard pile only ever grows over a game -- unlike hand/in-play, which
stay bounded by a player's own card count -- so a flat wrapping list of
full-size thumbnails (the old layout) eventually dwarfs the rest of the
board. `renderDiscardPile()` in `game.js` instead groups `state.discard_pile`
into columns, rendered as `<li class="discard-stack__column">` elements
holding `buildCardThumb()` buttons directly (no per-card `<li>` needed,
unlike hand/in-play). How many columns exist is recomputed on every render
from `discardStackColumnCount()` -- `#discard-list`'s own current
`clientWidth` divided by `.card-thumb`'s fixed 5.5rem width plus the list's
own 0.5rem gap (both hardcoded in pixels assuming the default 16px root
font-size the rest of this file's own rem/px math already assumes)  --
rather than a fixed cards-per-column cap, so however many columns actually
fit side by side is however many there are: cards are dealt round-robin
across them (card 0 into column 0, card 1 into column 1, ..., wrapping
back to column 0 once every column has one), filling the available width
before any single column stacks two deep, rather than filling column 0
completely before starting column 1. A `resize` listener (debounced 150ms
so a drag-resize or a slow orientation-change animation doesn't re-layout
repeatedly mid-gesture) re-runs `renderDiscardPile()` against the same
`state.discard_pile`/`canAct` it was last called with (remembered in
`lastDiscardPile`/`lastDiscardCanAct`, since the normal 4s poll has no
reason to fire just because the viewport changed shape) -- rotating a
phone from portrait to landscape mid-game, for instance, immediately
re-flows the same pile into more, shallower columns, and back again on
rotating back, without waiting for the next poll.

`.discard-stack__column .card-thumb:not(:last-child) { margin-bottom:
-6.6rem; }` pulls each card up to overlap all but a ~19px sliver of the
one before it -- `.card-thumb__art` is a fixed 5.5rem wide with `height:
auto`, so every card renders at the same height (5.5rem times the printed
card art's own fixed 744:1039 intrinsic ratio, ~7.68rem, plus its own 1px
top/bottom border); leaving a ~19px sliver means overlapping the rest,
i.e. roughly `-(7.68rem + border - 1.19rem)`. That sliver still shows the
covered card's own name and, if present, its value badge's upper-right
corner, since both live in that card's own top strip and the next card
(painted on top by DOM/paint order, not applied any negative margin since
it's the last child) only starts further down. A column's own last card
-- the most recently discarded card assigned to it, assuming the array's
actual append-only order, though `BoardState`'s own docblock doesn't treat
that as a hard contract -- therefore always renders in full. Columns lay
out side by side via `#discard-list`'s own `flex-wrap` -- normally exactly
filling one row since the column count is computed to fit, though wrapping
is kept as a defensive fallback in case that math is ever off by one --
with `align-items: flex-start` keeping a shorter trailing column (whenever
the pile's own card count isn't an exact multiple of the column count)
pinned to the top rather than centered/stretched against a taller
neighbor. Clicking any card in the stack (even a mostly-covered one, via
its visible sliver) opens the same read-only detail dialog as before,
passing `last_owner_name` explicitly as that dialog's own ownerLabel
argument (previously conveyed by the per-thumb caption removed above).

### In-play board layout

The "In play" area (issue #124) groups moods by seating position relative
to the viewer instead of one flat list -- `#in-play-board` is a CSS grid
with 6 zone `<div>`s (`#in-play-zone-north`/`-northwest`/`-northeast`/
`-west`/`-east`/`-south`, each a flex container -- `flex-direction: column`
-- that centers its own `.in-play-zone__label` above its cards both ways),
and one of three modifier classes (`in-play-board--2/3/4`, matching
`state.players.length`) picks which `grid-template-areas` -- and so which
of those 6 zones actually participate -- apply for this game: `north`/
`south` for a 2-player game (opponent above, you below), `northwest`/
`northeast`/`south` for 3 players, or `north`/`west`/`east`/`south` for 4.
Zones not used for the current player count stay `hidden`. `game.js`'s
`inPlayZoneAssignments(state)` computes each player's own zone from their
`seat_order` relative to the viewer's: index 0 (the viewer) is always
`south`; index 1 -- the player whose turn comes right after the viewer's
own, since `GameService::rotate()` already treats ascending `seat_order` as
"clockwise" (see its own docblock) -- sits at the
viewer's own left (`west`/`northwest`); the remaining index(es) fill
`north` (directly across, 4 players only) and `east`/`northeast` (the
viewer's right) in that same clockwise order. `renderInPlay()` then buckets
`state.in_play` by each card's `owner_game_player_id` into its assigned
zone's own `<ul class="in-play-zone__list">`, reusing `buildCardThumb()`
exactly as the old flat list did -- except each card's own owner caption
(issue #142) is gone; the zone's own `.in-play-zone__label` (inverted from
the same `zoneByGamePlayerId` map, one lookup per zone rather than one per
card) names its player once instead. This is purely a seating-position
grouping -- Open/Closed Team Play's own team pairing (`team_id`) plays no
part in it, unlike the players list's own "Team 1"/"Team 2" tags.

`#in-play-board` breaks out of `body.game-page`'s own centered 60rem
max-width to span the full browser window horizontally -- `width: 100vw`
plus `left: 50%; transform: translateX(-50%);` re-centers it on the
viewport itself rather than its (now irrelevant, narrower) parent, the
standard full-bleed technique for a block nested inside a max-width
container. Separately, `.in-play-zone`'s own `display: flex` would
otherwise beat the browser's default `[hidden] { display: none }` rule
(an author style always wins over the UA stylesheet, regardless of
selector specificity) for whichever zones aren't used at the current
player count -- `.in-play-zone[hidden] { display: none; }` overrides that
back, so an unused zone (e.g. northwest/northeast in a 2-player game)
doesn't render as a stray empty dashed box, auto-placed by the grid into
an extra implicit row/column past the real zones.

`#in-play-board`'s own grid `gap` is `0` -- the rows/columns of zones sit
flush against each other, with no visible seam between e.g. the `north`
row and the `west`/`east` row in a 4-player game. Each zone's own
`padding` (unaffected by this) still keeps its card thumbnails clear of
its own dashed border; only the space *between* zones was removed.

At phone widths (`@media (max-width: 600px)`), a 4-player game's
`west`/`east` zones are only about a quarter of the viewport each (one of
two grid columns, itself already a fraction of `#in-play-board`'s
full-bleed width) -- too narrow for even two of `.card-thumb`'s normal
5.5rem-wide cards side by side, so a second card in either zone wrapped
onto its own row instead of sitting next to the first. This media query
shrinks `.card-thumb`/`.card-thumb__art` to `4rem` and tightens
`.in-play-zone`'s padding and `.in-play-zone__list`'s gap, scoped to cards
inside `.in-play-zone` only (hand/discard cards keep their normal size
everywhere) -- enough for two cards to fit per row again. The suppressed-card
width/badge-offset overrides (see above) get scaled-down counterparts here
too, proportional to the smaller card width.

## Pages

- `index.html` (`/`) — Login form. If the visitor already has an active
  session (checked via `GET /app/me`), they're redirected straight to
  `/game/`. Links to `register.html`.
- `register.html` — Registration form. On success, shows a message to check
  email for the verification link (login is blocked until verified).
- `maintenance.html` — Shown during a maintenance window (see "Maintenance
  mode" above); not linked from anywhere, reached only via `apiRequest()`'s
  redirect.
- `game/index.html` (`/game/`) — Redirects to `/` if there's no active
  session; otherwise shows the logged-in username, a logout button, a
  "Friends" button (see below), and the game lobby/board itself. The
  "Friends"/"Log out" buttons carry their own `margin-bottom` so they don't
  touch whichever of the lobby or board view is showing directly beneath
  them (most noticeably the board view's own "Back to your games" button).
  - **Lobby**: a "New game" button (`#new-game-button`, also with its own
    `margin-bottom` so it doesn't touch `#games-list` directly beneath it)
    opens the New game dialog described below. Your games (via
    `GET /games`), each rendered as three
    stacked lines above the row's own action button. First, a format/deck
    line (`.lobby-format`, muted/smaller text) -- e.g. "Traditional,
    Structure deck" -- built from the same `format`/`deck_type` labels
    (`formatLabel()`/`deckTypeLabel()`) the board's own title uses,
    substituting the game's `custom_deck_name` for a `custom` deck_type
    just like the board title does. `custom_duel` falls back to
    `deckTypeLabel()`'s generic label here since each player's own
    submitted deck name (unlike `custom`'s single game-wide name) only
    comes back from `GET /games/:id`, not the lobby list. Second, its own
    status line (`.lobby-status-line`) -- status reads as e.g.
    "In Progress" rather than the raw `in_progress` the API returns
    (`humanizeStatus()`, a generic snake_case-to-Title-Case transform, not
    a fixed per-status lookup table, so any future status value still
    reads reasonably without needing an update here). Third, the
    opponents themselves. The list itself is
    rendered in whatever order the API returns (no client-side re-sort) --
    `GET /games` always puts `waiting`/`in_progress` games above
    `completed` ones regardless of recency, so a stalled active game never
    gets buried below a long-finished one; see "Game timestamps" in
    `php-app/README.md`. Status is also color-coded (issue #136) via a
    `.lobby-status--<status>` class per row -- `waiting` reads in
    `--color-pending`, `in_progress` in a new `--color-info` (blue, added
    alongside the existing error/success/pending theme variables --
    see "Dark mode" below), `completed` in `--color-muted`, and the rarer
    `abandoned` in `--color-error` -- distinguishing what needs attention
    at a glance rather than requiring every row's text to be read in full.
    `is_your_turn` gets its own bold `--color-success` "(your turn)" tag
    on that same status line, plus a whole-row background
    (`.lobby-row--your-turn`, a new `--color-your-turn-bg` theme variable)
    so an actionable game stands out even before any of the row's text is
    read; `#lobby-view li`'s own horizontal padding (rather than 0) is what
    keeps that background from touching the row's edges. Once a game is
    `completed`,
    `winner_usernames` (both teammates' for a team-format win, just the
    one player's otherwise -- see `php-app/README.md`) renders as an extra
    line below the players, e.g. "alice won" or "alice & bob won" --
    absent (and the line omitted entirely) for every other status, since
    there's no winner yet to name. All of that text lives in its own
    `.lobby-info` wrapper, a flex sibling of the row's own action button
    (`.lobby-row`'s own `display: flex; justify-content: space-between`)
    rather than a plain child of the `<li>` -- keeps the button pinned to
    the right of and vertically centered against however many lines the
    text itself wraps to on a narrow (phone-width) viewport, instead of
    always trailing on its own line below a wrapped winner line/status.
    The button itself has a fixed width (`.lobby-row button`) so every
    row's button lines up in a column regardless of whether that row's own
    label is the shorter "Play" or the wider "View". Each row's own button
    reads "Play" for
    a `waiting`/`in_progress` game (something there's still an actual turn
    to take) and "View" otherwise (`showBoard()` itself renders read-only
    once a game isn't `in_progress` regardless of the button's own label,
    so this is purely about setting the right expectation before
    clicking, not a new access restriction). A "New game" dialog
    picks 1-3 friends (via `GET /friends`) plus a format (Traditional,
    Duel, Open Team Play, or Closed Team Play), then calls `POST /games`.
    `updateOpponentSelectionLimit()` caps how many friends
    can be checked at once to match the format's actual player count --
    3 normally, but only 1 for Duel, since a duel is exactly 2 players and
    the server rejects anything else (see "Duel: separate per-player
    decks" in `php-app/README.md`). It runs on every checkbox's own
    `change` as well as the format `<select>`'s: switching to Duel with 2
    friends already checked auto-unchecks the second one and disables the
    rest, and switching back to Traditional re-enables them, so you can't
    submit a Duel request the server will just reject with a 400.
    Selecting Open Team Play or Closed Team Play reveals
    `#new-game-team-fields` (`updateTeamFields()`, wired to the same
    checkbox/format `change` events): a partner `<select>` populated from
    whichever friends are currently checked, re-populated on every change
    but keeping the previous selection if that friend is still checked,
    alongside a description paragraph (`TEAM_FIELDS_DESCRIPTIONS`) that
    swaps between the two formats' own wording (adjacent seating/open
    hands vs. across-the-table seating/private hands). Submitting
    requires exactly 3 opponents checked for either format (a client-side
    check ahead of the server's own 4-players-total rejection) and sends
    the selected partner as `partner_user_id`. See "Open Team Play"/
    "Closed Team Play" in `php-app/README.md` for the formats themselves. The
    dialog's Deck dropdown (`#new-game-deck-type` -- Structure, Power,
    jceddy's 75 Card, Custom Decklist, Custom Decklists (Duel), One of Each
    Card, in that order, matching `deck_type`'s own six values -- see
    "Deck types" in
    `php-app/README.md`) has a plain-language
    description shown right below it (`#new-game-deck-type-description`,
    `updateDeckTypeDescription()`) that updates live as the selection
    changes, and once more when the dialog itself opens (`newGameForm.
    reset()` resets the `<select>` back to its default, Structure, first)
    so the description shown always matches what's actually selected,
    never a stale one left over from the last time the dialog was open.
    Selecting Custom Decklist also reveals `#new-game-decklist-fields` -- a
    file input and a textarea, both ultimately feeding the same
    `decklist_text` string sent to `POST /games` (uploading a file just
    reads its text into the textarea via `FileReader`, so the server only
    ever sees one input shape regardless of which the player used) -- see
    "Custom decklists" in `php-app/README.md` for the format itself.
    `updateDeckTypeAvailability()` disables that option (mirroring
    `updateOpponentSelectionLimit()`'s own proactive approach) whenever
    Duel is selected, since custom decklists aren't supported for duel
    games, falling back to Structure if Custom Decklist was already
    selected when the format switches -- and, the other way round,
    disables Custom Decklists (Duel) whenever Duel *isn't* selected, since
    that option only makes sense for a duel. The same function also
    disables Power whenever either team format is selected -- Power's 15
    cards fall short of the 45-card minimum both team formats share (see
    "Open Team Play"/"Closed Team Play" in
    `php-app/README.md`) -- falling back to Structure if Power was already
    selected. Selecting Custom Decklists
    (Duel) reveals `#new-game-duel-rules-fields` instead of the decklist
    fields -- no decklist is entered here at all, only the deck-building
    *rules* both players' own decklists (submitted later, see the Board
    bullet below) will have to satisfy. A `#new-game-duel-rules-preset`
    dropdown (Structure/Power/jceddy's 75 Card/User-Defined) either shows a
    read-only summary of that preset's locked-in values
    (`DUEL_RULES_PRESET_SUMMARIES`, a client-side mirror of
    `DuelDeckRules::forPreset()` purely for display -- the actual values
    are always resolved server-side) or, for User-Defined, reveals editable
    fields: a minimum card count and, for each of the four rarities, an
    optional max-total input, an optional max-duplicates input, and an
    "even split across colors" checkbox (`collectDuelDeckRules()` reads
    all of this into the `duel_deck_rules` object sent to `POST /games`,
    the checkboxes via `collectEvenColorDistributionRarities()` into a
    plain list of checked rarity names rather than a `{rarity: value}`
    map, since it's a flag rather than a count) -- see "Custom decklists
    for Duel games" in `php-app/README.md` for what these rules actually
    mean. Polls `GET /games` every 4 seconds while the lobby is
    open (mirroring the board's own poll below, and mutually exclusive
    with it via the same `pollTimer` variable, since only one of the two
    views is ever visible at once) — so a game another player just
    created (or one you created yourself from a second tab) shows up on
    its own, without needing a hard reload.
  - **Board**: players, whose turn it is, in-play moods, the discard pile,
    deck count, and your hand (via `GET /games/state`). For a
    `custom_duel` game still `waiting` to start, `renderDuelDeckSubmission()`
    replaces the usual "Start game" gating with its own section
    (`#duel-deck-submission`): the creator's own locked-in rules
    (`state.game.duel_deck_rules`, summarized in plain language), each
    player's submission status (`state.players[].deck_submitted` --
    never the decklist contents themselves, so neither player can see the
    other's decklist before the game starts), and, if the viewer hasn't
    submitted yet, the same file-upload/paste form the Traditional
    `custom` deck_type uses in the New Game dialog, wired to
    `POST /games/decklist` instead of `POST /games`. "Start game" itself
    stays hidden until every player's own `deck_submitted` is true, since
    the server would just reject starting otherwise. Once a `custom_duel`
    game is actually in progress, each player's own row in the Players
    list additionally shows `— deck: <name>` (or "Uploaded Deck") -- since
    unlike every other deck_type, a `custom_duel` game has no single deck
    the whole table shares, each player having submitted their own. The
    board title itself shows the *viewer's own* submitted deck name for a
    `custom_duel` game (looked up from `state.players` by
    `state.you.game_player_id`, the same `custom_deck_name` field the
    per-player row reads), rather than `deckTypeLabel()`'s generic "Custom
    Decklists (Duel) deck", which never actually named anything the viewer
    had chosen.

    For a `team`/`closed_team`-format game (see "Open Team Play"/"Closed
    Team Play" in `php-app/README.md`),
    each Players-list row also gets a "— Team N" tag (from that player's
    own `team_id`, `null` in every other format) plus "(your teammate)" on
    the one row that's actually `state.you.teammate_game_player_id` --
    populated for BOTH team formats, regardless of whether their hand is
    visible. A
    `#team-scores` section (`renderTeamScores()`, hidden until
    `state.teams` is populated -- only once the game has actually started)
    lists each team's combined score-so-far and round wins. A
    `#teammate-hand-section` (`renderTeammateHand()`, hidden whenever
    `state.you.teammate_hand` is `null`) shows your teammate's hand the
    same read-only way in-play/discard-pile cards are shown to everyone --
    clicking a card opens the ordinary detail view, never anything
    playable, since only the teammate actually holding a card can play it.
    This section simply never renders anything for `closed_team`, since
    `getState()` never populates `teammate_hand` for that format at all
    (hands stay private between teammates -- see "Closed Team Play" in
    `php-app/README.md`). A `#team-decision-panel` (`renderTeamDecision()`, reading
    `state.team_decision`, `null` unless a `game_team_decisions` row is
    open) shows either a row of candidate buttons (`can_propose`, calling
    `proposeTeamDecision()`) or an Approve/Reject pair (`can_confirm`,
    calling `confirmTeamDecision()`), or, for every OTHER player, a
    read-only "Waiting for X or Y to choose who should go next/draw the
    shared card" status line built from the same `decision_type` --
    everyone sees this panel while a round is frozen on a team decision
    (mirroring `#pending-decision-panel`'s own always-visible-but-
    read-only-for-non-targets shape), not just the two candidates. Its
    title/status wording (`titlesByDecisionType`) branches on whether the
    viewer's own `team_id` matches the decision's ("Your team's turn" vs.
    "Opposing team's turn", etc.), since every viewer -- including
    whichever team ISN'T deciding -- receives the same `team_decision`
    object.

    A `#initial-card-pass-panel` (`renderInitialCardPass()`, reading
    `state.initial_card_pass`, `null` for every format except
    `closed_team`, and `null` there too once every player has submitted)
    is this format's own pregame step: while the viewer hasn't submitted
    yet, it shows every hand card as a clickable thumbnail in a horizontal
    row (reusing `buildCardThumb()`). Selection itself isn't done by
    clicking the thumbnail directly -- that instead opens the same
    `#card-detail-dialog` used to inspect in-play/discard-pile/opponent
    cards elsewhere (`openCardDetail()`), passed an optional third
    `selection` argument (`{ selected, disabled, onToggle }`) that reveals
    a `#card-detail-select-button` reading "Select" or "De-select"
    (disabled once 2 *other* cards are already picked, so an
    already-selected card can always still be de-selected); clicking it
    calls `onToggle()` and closes the dialog. The chosen 2 card_ids are
    tracked purely client-side in a local `Set`, never sent until the
    submit button is pressed -- the row thumbnail itself still gets the
    same `.selected` CSS class/border used elsewhere once picked. Calling
    `submitInitialCardPass()` (`POST /games/initial-pass`) sends the pair;
    once submitted, the panel shows a read-only "Waiting for X, Y to pass
    their cards" status instead (built from
    `state.initial_card_pass.submitted_game_player_ids`, which players
    have or haven't submitted yet -- never which 2 cards anyone chose).
    See "Closed Team Play" in `php-app/README.md`.

    Clicking any hand
    card opens `#choices-panel` inline, underneath the hand -- a plain
    block element (not a `<dialog>`/overlay, deliberately: an overlay was
    tried and reverted, since it made the rest of the board -- in-play
    moods, discard pile, opponents' state -- harder to reference while
    still choosing a target) showing an enlarged view of the card's own
    art in place of a separate name/rules-text heading (its `alt` text
    carries both, for accessibility) plus whatever choice fields it
    needs and Play/Cancel. A server-side rejection from actually
    submitting the play (caught after the panel's own client-side checks
    below already passed) surfaces inside the panel itself, reusing
    `#choices-validation`'s existing spot right next to the Play button,
    rather than the board's own `#board-error` above the hand -- easy to
    miss while attention is still on the fields just below it. Cards with
    no ability worth asking about (roughly half the 127-card
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
    without a round trip to find out. A `type: 'mood'` field's `<select>`
    (Faith's `target_mood_id`, Malice's multi-select cascade, etc.) groups
    its options into one `<optgroup>` per owner (`buildFieldWidget()`'s
    `appendGroupedMoodOptions()`, driven by an `ownerLabel` on each option
    from `fieldOptions()`) rather than one flat list — with 3+ players in
    a game, picking a specific player's mood out of a single long list got
    tedious, and grouping by owner mirrors how the in-play board itself is
    already organized by seating position. Groups appear in the order
    each owner's first candidate is encountered, not resorted by seat.
    A `type: 'grant_choice'` field (`grant_source_card_id`, prepended ahead
    of the card's own fields) appears only when 2+ outstanding play grants
    would each independently cover the card being played — most commonly
    two Hopes/Graces both still armed, or one plus your ordinary turn —
    letting you pick which specific one gets spent instead of the rules
    engine always silently consuming whichever happens to come first (see
    `usableGrants()`/`grant_source_card_id` in `php-app/README.md`).
    Unlike every other field case in `fieldOptions()`, its options arrive
    fully server-computed (`GameService::grantChoiceOptions()`, reusing
    `describePlayGrant()`'s own description text, e.g. "An extra play from
    Hope") — there's nothing left to derive client-side, so the case just
    returns `field.options` as-is. It's optional even when offered: like
    every other non-multi field, `buildFieldWidget()` auto-prepends a blank
    default option, and leaving it selected omits `grant_source_card_id`
    from the submitted payload entirely, falling back to the server's old
    "whichever grant comes first" behavior — no special-case JS needed for
    that leave-it-blank path. That default option reads "(any)" rather than
    every other field's "(none)", the one place `buildFieldWidget()`
    special-cases the label by `field.type` — leaving this blank doesn't
    mean "use no grant" (a play always uses one), just "no preference which
    outstanding one," so "(none)" would misleadingly suggest declining a
    grant entirely.
    A `type: 'mood'` field's own options (e.g. Faith's `target_mood_id`)
    also mark a candidate mood with `card.has_unused_play_grant` (see
    `php-app/README.md`) with a trailing ` *` right after its name
    (`cardLabel()`) — most relevant for an in-play Hope/Grace, since a
    player choosing where to send an effect might otherwise have no reason
    to check the card detail dialog first. The same asterisk appears
    everywhere `cardLabel()` builds a dropdown option (the `'mood'`,
    `'hand_card'`, and `'discard_card'` cases in `fieldOptions()`), though
    it only ever actually shows for a `'mood'` option in practice — a hand
    or discard-pile card has no `has_unused_play_grant` field of its own to
    read.
    This still disambiguates two players' identical printed cards the way
    an inline "— Owner" suffix used to (needed since a `'duel' game's two
    independent decks can put the same printed card in play at once, and
    a bare name alone can't tell two "Discipline" options apart in, say,
    Pacifism's own "one per player" target list) — just via the group
    label instead of repeating it on every option. A discard-pile field
    (e.g. Corruption's `discard_card_ids`) isn't grouped this way and
    keeps the older inline suffix instead (`cardLabel(card) + ' — ' +
    last_owner_name`), since the shared discard pile has no current
    per-player grouping the way in-play moods do. If a filled-in choice is still
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
    Every mood in play is also clickable, opening a read-only detail view
    (an enlarged view of the card's own art in place of a separate name/
    rules-text heading -- see "Card art rendering" above -- base value,
    alt value if it has one, current value if a
    while-in-play effect has changed it, its printed color too if
    Imagination has recolored it (or, for a Creativity copy, if that
    differs from whatever it's copying), owner, rules text, and — if it's
    currently suppressed — an indicator naming the suppressing mood, if the
    game tracks one, and whether the suppression lasts as long as that mood
    stays in play or just until the end of the current round). An in-play
    Creativity that's actually copying something displays AS the copied
    mood rather than as Creativity everywhere on the board — its in-play
    list entry, and this detail view's name/rules text, all read as e.g.
    "Serenity," not "Creativity," matching how the rules engine itself
    treats it — with a `[Creativity copy]` tag next to its in-play list
    entry (`cardLabel()`, driven by `card.is_creativity_copy`) and its own
    detail-view line ("A Creativity copy of Serenity.") so it's never
    mistaken for the genuine card. A "blank" Creativity played without
    copying anything (`is_creativity_copy` false) still just shows as
    plain "Creativity." A few more
    reminder lines cover the game's other "one mood affects another" cases
    the same way: a mood whose printed dice value is currently overridden
    by Encouragement or Idealism shows "Affected by <that mood>", and a
    mood doing the suppressing/boosting itself shows "Affecting: <targets>"
    naming everything it's currently affecting (several at once for a
    mass-suppression card's "all" mode, or for Idealism's blanket "every
    mood its owner controls"). An in-play Bliss shows a line of its own
    naming the color of whatever was discarded to pay its cost (`state.
    in_play[].bliss_discard_color`, `null`/absent for every other card),
    since which color it's currently tripling is otherwise something you'd
    have to remember from when it was played. A mood someone only holds temporarily
    (Arrogance's steal, or Betrayal's/Recklessness's "give it back later")
    shows a fourth reminder line: which card caused the change, who owned
    it originally, and when it reverts — "when <that card> leaves play" for
    Arrogance's own tag, or "after this round is scored" for Betrayal's/
    Recklessness's, matching `state.in_play[].temporary_ownership`'s own
    `reverts` value (`null` for a permanent trade like Guile's/Instability's/
    Avoidance's/Chaos's, which still shows up in the "Recent plays" history
    below, just without this popup detail). Every card in the discard pile is clickable
    too — almost always the same read-only detail view, so an unfamiliar
    card can be checked before deciding how to respond to it, but the rare
    exception is a card actually covered by a discard-sourced extra play
    (Angst/Harmony/Grief) or by Melancholy's "play from the discard pile as
    though it were your hand" — the same `is_playable` flag that already
    filters a hand card routes a click on one of those straight to the
    ordinary Play/Cancel choices panel instead. The viewer's own hand cards
    make this same read-only-vs-Play/Cancel split whenever it isn't their
    turn to act at all (not their turn, or a pending decision elsewhere is
    freezing the round) — a card sitting in your own hand is never hidden
    *from you*, only playing it is turn-gated, so the button stays clickable
    and opens the detail view instead of going disabled, the same way a
    discard-pile card that currently can't be played still opens its own
    read-only view rather than nothing at all. While the viewer is the one
    a pending decision (see below) is actually waiting on, their own hand
    cards switch to opening this same read-only detail view rather than the
    choices panel too — the response panel, not the ordinary choices panel,
    owns picking a card in that moment, but an unfamiliar hand card still
    needs to be checkable before answering. Seven cards (Arrogance,
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
    via `POST /games/respond`. Betrayal uses this exact same pending-decision
    mechanism for a different reason than those seven: nobody else answers
    it (the panel appears immediately, in the same session, the moment
    Betrayal is played -- no "waiting on" banner ever shows for it, the
    same way none ever shows for Duplicity's own repeat-offer below), but
    which of the acting player's own moods to give away can't be offered as
    an ordinary up-front field the way it is for almost every other "your
    own mood" choice -- Betrayal is a legal answer to its own choice (its
    printed text doesn't exclude itself), but it isn't in play yet at the
    moment an ordinary choices panel is filled out, so a field sourced from
    the current board could never legally include it. Deferring the choice
    until after Betrayal has actually entered play is what makes offering
    it as a candidate possible at all. Instability has this exact same
    problem for the mood it gives an opponent in exchange, and gets the
    same fix as a *second* step tacked onto its own existing pending
    decision -- the opponent's own "which of these two do I give up" step
    still shows a "waiting on" banner to everyone else first, then the
    acting player answers their own "which of my own moods (Instability
    itself included) do I give back" step once that resolves, with no
    frontend changes needed for either card: the response panel already
    renders any decision, self-targeted or not, the same way. Pride uses
    this same self-targeted, immediately-shown pending decision for a
    different reason again: "more moods than you" can't be compared
    correctly until Pride itself is in play and counted, so the field's
    candidate players are computed server-side against the real post-play
    board and sent down as `candidate_player_ids` — a dropdown field option
    source `fieldOptions()` handles the same way it already does for
    Instability's own `candidate_card_ids`, just for players instead of
    moods. Suspicion, Disillusionment, Avoidance,
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
    `round.scoring_effects` is a related but separate, always-present
    section rendered just above the in-play list (`#scoring-effects`,
    `renderScoringEffects()`) — one plain-English line per in-play mood
    whose ability changes how the round will score at all (Bliss,
    Exhilaration, Enthusiasm, Passion, Sneakiness, Awe, Corruption), fully
    formatted server-side the same way every other reminder text in this
    app is, so there's nothing here to figure out mid-round rather than
    only once a decision is already forced.

    `round.board_effects` is `scoring_effects`' sibling section, rendered
    directly below it (`#board-effects`, `renderBoardEffects()`) for
    in-play moods whose "while in play" ability reshapes the board itself
    rather than how scoring works — today that's just Imagination, shown
    as e.g. "Alice's Imagination — all moods are red." Hidden entirely
    (both sections independently collapse to nothing, via the same
    `container.hidden = entries.length === 0` pattern) whenever there's
    nothing to say, so an ordinary board with neither in play shows
    neither heading.

    A "Recent plays" list at the bottom of the board shows the last 15
    plays/passes/rounds-scored for the game as plain sentences (e.g. "Alice
    played Paranoia from hand (target player: Bob), revealing Charity from
    Bob's hand") — always naming which zone the card was actually played
    from (hand, or the discard pile for a Harmony/Grief/Angst-style bonus
    play or Melancholy's blanket discard-as-hand), not just that it was
    played — it comes along for free with each poll (`state.recent_events`,
    already fully formatted server-side, see `php-app/README.md`) rather
    than needing its own endpoint or polling loop. This is specifically
    what makes Paranoia's and Curiosity's own random reveal visible at all
    to anyone besides whoever happened to submit that particular play —
    including, for Paranoia, the very player whose hand card got revealed
    and buried — since neither card's outcome is derivable from anything
    else in the state response once the moment it happened has passed;
    every other play's own line also spells out whatever choice was
    actually made (a target player, a chosen mood/hand card, a color, and
    so on), not just which card was played — including, for a card that
    puts one of its own player's *in-play* moods into the discard pile,
    saying so explicitly ("mood moved from play to discard: Charity")
    rather than the more general "discard mood" phrasing that elsewhere
    always means a *hand* card going to the discard pile. Any mood whose
    owner changes — Guile/Instability/Avoidance/Chaos's permanent trades,
    Arrogance's/Betrayal's/Recklessness's own temporary "give it back
    later" swaps, and that swap's own eventual reversal — gets its own
    line too, e.g. "Charity changed ownership from Bob to Alice"
    (`state.<event>.ownership_changes`, tracked completely independently
    of a card's zone). A round-scored line names every player's own final
    score and who won, not just that scoring happened.

    Drawing a card gets its own segment too -- "Alice drew a card" -- but
    deliberately never names *which* card, unlike every other zone move
    described above: a card drawn into a hand was never previously public,
    so saying which one would leak hidden hand information no other
    recorded move does (Zeal's/Doubt's/Paranoia's/Corruption's/Conviction's/
    Hate's/Rationalization's own after-playing draws, and the "each
    non-winning player draws a card" that happens automatically once a
    round scores, all read this way). An extra play grant gets logged
    twice, for two different moments: the instant it's created ("Bob was
    granted an extra play from Charity"), naming its source card and any
    zone/restriction it carries (e.g. "an extra play from the discard
    pile (must share a color with one of your moods)" for Grace's own
    grant) the same way an *outstanding* grant is already described in
    the "Plays left" details above -- and again, once it's actually spent,
    folded right into that later play's own line ("Bob played Apathy from
    hand (using an extra play from Charity)"), so it's clear not just that
    a bonus play was used, but which card's own grant it came from.

    A separate, more prominent green banner (`#board-message`) flashes
    "Game complete!" or "Round scored — a new round has begun." right
    after a play/pass/response that actually triggers one
    (`announceOutcome()`, keyed off that action's own `game_completed`/
    `round_scored` response flags) — distinct from the "Recent plays" line
    above, which is a permanent history entry built from the next poll's
    own `state.recent_events` rather than a one-off local reaction to a
    single request's result. Since nothing ever hides this banner on an
    ordinary board load (only another play/pass/response, right before
    submitting, ever clears it), `showBoard()` now explicitly hides it too
    the moment a game's board is (re)opened — otherwise a game that had
    already completed the last time its board was viewed would leave the
    "Game complete!" banner sitting there, incorrectly, the next time any
    *other* game's board opened, including a freshly created one that had
    never even started yet.

    The "Players" list near the top of the board shows each player's
    current point total alongside their win count and hand size
    (`player.total_score` — a live sum of what's actually on the board
    right now, i.e. what each player would score if the round ended this
    instant, not anything accumulated from earlier rounds; distinct from
    `total_wins`, which only counts outright round victories) — "Alice —
    seat 0, 12 point(s), 2 win(s), 5 card(s) in hand" — so nobody has to
    manually add up the values on their own moods. It also marks whoever
    went first this round (`state.round.first_game_player_id`, already
    tracked server-side for Chivalry/Honor/Triumph-style effects but
    previously never surfaced to the client) with its own "— went first
    this round" tag, independent of (and possibly a different player
    from) whoever the "— on turn" tag currently marks. In games of 3+
    players, whoever holds Hurt Feelings (`state.round.hurt_feelings_game_player_id`,
    already tracked server-side to grant that player 2 plays instead of 1
    this round, but previously never surfaced to the client either) gets a
    small `img/hurt-feelings.webp` thumbnail next to their row instead of a
    plain text tag, so the extra play they're about to get isn't a
    surprise. Clicking it opens the same generic `#art-preview-dialog`
    an enlarged card-art view uses (`openArtPreview()`) with an enlarged
    view of that art, since Hurt Feelings is a round-level marker/token,
    not a `cards` row (see migration `0003`'s own header comment), so it
    has no `catalog_card_id`/`rules_text` to build a card-detail-dialog-style
    view from.

    A "Plays left: N" `<details>` element (collapsed by default, so it
    doesn't crowd the board when there's nothing interesting to say) sits
    just above the Pass button — expanding it lists each outstanding play
    this turn (`state.round.play_grants`, already rendered server-side into
    e.g. "An extra play from Charity" or "An extra play from Angst from
    the discard pile"), rather than just the bare count `plays_remaining`
    already gave no way to explain. The base turn's own single play (two
    with Hurt Feelings) reads as "Your normal turn" instead, since it isn't
    granted by any specific card. This whole indicator only ever shows
    while it's actually your own turn — `state.round.play_grants` itself
    always describes whoever's turn it currently is, so showing it
    unconditionally would read as "you have a play left" on someone
    else's turn. The `<details>` starts `hidden` in the markup itself
    (not just hidden via JS once state loads), and `refreshBoard()` tags
    each `/games/state` call with an incrementing sequence number and
    drops the response if a newer call has since been issued — otherwise
    an in-flight poll issued a moment before an action like Start game
    can resolve *after* that action's own fresher render and silently
    overwrite it with stale data (e.g. showing this indicator again after
    the game started with an opponent going first, until the page was
    reloaded).
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
