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

## Resources

Every page has a `<a id="resources-link">Resources</a>` next to the theme
select in its own `<footer>`, opening a static `<dialog id="resources-dialog">`
(already present in each page's own HTML, wired up by `initResourcesDialog()`
in `js/app.js` alongside the theme-select/version-indicator footer logic
every page already shares) -- issue #148. Contains external links to the
official rules, formats, card-specific rulings, and card gallery pages, the
Moodiest and Moodfall card repositories, and the community Discord/Reddit --
the same set documented in the top-level README's own "Resources" section, just
reachable in-app rather than only from the repo itself -- plus a link to
this GitHub repository. The dialog also embeds Buy Me a Coffee's own
official `<script data-name="bmc-button">` widget (`cdnjs.buymeacoffee.com`),
which inserts its own button element into the dialog once it loads.

Unlike every other dialog in this app (`#card-detail-dialog`,
`#friends-dialog`, etc.), which close via a plain button at the bottom,
`#resources-dialog` closes via an "X" (`&times;`) absolutely positioned
in its own top-right corner. This is specific to this one dialog: a
bottom Close button here would sit directly against whatever the BMC
widget's own injected markup renders right above it -- markup this
codebase doesn't control the shape of, so a CSS margin targeting it
(tried initially) wasn't reliable. Moving Close to the corner sidesteps
that entirely, since it opens the door for the BMC button to render
however it wants without ever crowding another control.

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

## Mobile text sizing

`html { text-size-adjust: 100%; -webkit-text-size-adjust: 100%; }` in
`style.css` opts every page out of mobile Chrome's "font boosting" text
autosizer, which otherwise independently scales blocks of text to stay
legible on a narrow screen. A correct `<meta name="viewport"
content="width=device-width, initial-scale=1.0">` (already present on
every page) doesn't by itself suppress this, and a page that renders
correctly on desktop/emulated-viewport testing can still look subtly
different on a real phone without it -- general defensive hygiene, not
tied to any one page or feature.

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
an extra implicit row/column past the real zones. `#in-play-board`
itself needs the identical `#in-play-board[hidden] { display: none; }`
override for the same reason, one level up: `renderInPlay()` sets
`board.hidden = true` and returns early whenever the just-loaded game's
own `state.in_play` is empty, without touching that game's own zone
`hidden`/list contents at all (there's nothing to bucket) -- without the
CSS override, that early return did nothing visible, and whichever other
game's board had rendered last stayed fully on screen underneath, cards
and all, until a game whose own `in_play` wasn't empty happened to load
next. Concretely: viewing a finished game with moods still in play, going
back to the lobby, then opening a different, fresh in-progress game
before its first play left that first game's own in-play area showing.

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
  "Friends" button (see below), a "Decks" button (see below), and the game
  lobby/board itself. The "Friends"/"Decks"/"Log out" buttons carry their
  own `margin-bottom` so they don't touch whichever of the lobby or board
  view is showing directly beneath them (most noticeably the board view's
  own "Back to your games" button).
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
    `is_your_turn` gets its own whole-row background
    (`.lobby-row--your-turn`, a new `--color-your-turn-bg` theme variable)
    so an actionable game stands out even before any of the row's text is
    read; `#lobby-view li`'s own horizontal padding (rather than 0) is what
    keeps that background from touching the row's edges.
    `is_awaiting_your_response` (a delayed choice is on you specifically
    -- see `GameService::isAwaitingResponseFrom()`) gets the same
    treatment with a distinct color: its own row background
    (`.lobby-row--awaiting-response`, a new `--color-awaiting-response-bg`
    amber theme variable, distinct from your-turn's green) -- deliberately
    a different color from your-turn's, since the two mean different
    things and can both be true at once (a pending decision freezes the
    round even on what's nominally your own turn). `buildGameRow()` gives
    the row background priority to awaiting-response over your-turn when
    both apply, since it's the more urgent of the two. Both of these row
    highlights stay strictly viewer-centric -- they only ever answer "is
    there something for ME to do here" -- even though *who* the game is
    actually on turn/waiting on can be a different player entirely (see
    the per-player icons below); a row is never highlighted purely because
    it's some OTHER player's turn or they're the one being waited on.

    Rather than the old "(your turn)"/"(waiting on &lt;username&gt;)" text
    tags (which could only ever name the viewer, even when a pending
    decision was actually paused on a different player), each opponent's
    own name in the row now gets an inline icon instead, reusing the exact
    same shapes/colors the board's own players list already established
    for issue #143: `current_turn_username` (whichever seated player
    `current_turn_game_player_id` actually belongs to) gets the green
    play-arrow icon (`buildPlayerFlag('onTurn', ..., 'player-flag--turn')`)
    right after their name, and every name in
    `awaiting_response_usernames` (the all-players generalization of
    `is_awaiting_your_response` -- see `GameService::listGamesForUser()`)
    gets the gold waiting-hourglass icon
    (`buildPlayerFlag('pendingDecision', ..., 'player-flag--pendingDecision')`)
    -- so e.g. a game where you played Compulsion targeting an opponent
    shows the hourglass next to *their* name, not yours, even though it's
    still nominally your own turn. A still-`waiting` `quick_draft`/
    `winston_draft`/`grid_draft` game reuses the exact same
    `awaiting_response_usernames` field (see
    `GameService::draftAwaitingResponseUsernames()`) for the same
    hourglass icon, now naming whoever the draft/deck-building step
    itself is blocked on instead: both players at once for quick_draft's
    own simultaneous-blind draw/received stages until each has
    submitted, or exactly one at a time for winston_draft's/grid_draft's
    single active turn player; `current_turn_username` stays `null` the
    whole time a game is still `waiting`, since there's no board "turn"
    concept yet to name. This also required extracting the icon-rendering
    logic into a shared `appendPlayersWithFlags()` helper, reused by both
    `buildGameRow()`'s own (non-compact) opponents line and
    `buildMatchGroupRow()`'s header -- a draft-based match's games are
    always grouped (`draft_match_id` is set from game 1 onward), so
    without that shared header call the icons would never render at all
    for a draft game, since its own row only ever appears as a match
    group's compact sub-row (which skips the opponents line entirely, see
    `opts.compact` above). `.player-flag`/`.player-stat` pick up a
    `vertical-align: middle` rule (a no-op inside their usual flex
    `.player-icons` wrapper on the board, but needed here since these are
    now also reused inline in ordinary text flow) so they line up cleanly
    with the surrounding username text. Once a game is `completed`,
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
    clicking, not a new access restriction). A `quick_draft`/`winston_draft`/
    `grid_draft`
    game's up-to-3
    `games` rows (sharing one `draft_match_id`) are grouped into a single
    `<li class="lobby-match-group">` instead of showing as unrelated rows --
    `refreshLobby()` walks `GET /games`'s already-sorted array once,
    bucketing by `draft_match_id` into a `Map`, and renders one
    `buildMatchGroupRow()` per match at the position of its first (best-
    sorted) game; every other format's games are unaffected (no
    `draft_match_id`, so each stays its own top-level row). The group's own
    header carries the format/deck line and a `.lobby-match-score` line
    ("Match score: you 1, opponent 0 (first to 2 wins)", from the game's
    own `draft_match` field -- shared/renamed from `quick_draft_match` once
    Winston Draft became a second consumer of the exact same shape) once, plus -- only once the match itself
    is decided -- a `.lobby-winner`-styled result line ("alice won the
    match"); each game nested underneath (`.lobby-match-games`, indented)
    renders via the same `buildGameRow()` the flat list uses, but with
    `{ compact: true }` to drop the now-redundant format/deck/opponents
    lines and prefix its own status with "Game N --", keeping its own
    Play/View button and its own `winner_usernames` line once that
    particular game is `completed` -- not redundant with the group header's
    own result, since a match's games aren't necessarily all won by the
    same player. A "New game" dialog
    (`.new-game-field` puts the Format and Deck `<label>`s each on their
    own line -- plain inline `<label>` elements otherwise sit side by side
    until their own `<select>` runs out of room, rather than breaking
    predictably between fields; `#new-game-close-button`'s small
    margin-top keeps it from touching the submit button directly above
    it, which are two separate block boxes -- the button's inside
    `<form>`, Close is a sibling after it -- that would otherwise stack
    flush against each other)
    picks 1-3 friends (via `GET /friends`) plus a format (Traditional,
    Duel, Draft, Open Team Play, or Closed Team Play), then calls
    `POST /games`. `updateOpponentSelectionLimit()` caps how many friends
    can be checked at once to match the format's actual player count --
    3 normally, but only 1 for Duel or Draft, since both are exactly 2
    players and the server rejects anything else (see "Duel: separate
    per-player decks" in `php-app/README.md`). It runs on every checkbox's
    own `change` as well as the format `<select>`'s: switching to Duel or
    Draft with 2 friends already checked auto-unchecks the second one and
    disables the rest, and switching back to Traditional re-enables them,
    so you can't submit a request the server will just reject with a 400.
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
    jceddy's 75 Card, Custom Decklist, Custom Decklists (Duel), Quick
    Draft, Winston Draft, Grid Draft, One of Each Card, in that order,
    matching `deck_type`'s own nine values -- see "Deck types" in
    `php-app/README.md`) has a plain-language
    description shown right below it (`#new-game-deck-type-description`,
    `updateDeckTypeDescription()`) that updates live as the selection
    changes, and once more when the dialog itself opens (`newGameForm.
    reset()` resets the `<select>` back to its default, Structure, first)
    so the description shown always matches what's actually selected,
    never a stale one left over from the last time the dialog was open.
    Selecting Custom Decklist also reveals `#new-game-decklist-fields` -- a
    saved-deck dropdown (`#new-game-saved-decklist`, issue #92) above a
    file input and a textarea, both ultimately feeding the same
    `decklist_text` string sent to `POST /games` (uploading a file just
    reads its text into the textarea via `FileReader`, so the server only
    ever sees one input shape regardless of which the player used) -- see
    "Custom decklists" in `php-app/README.md` for the format itself. The
    textarea itself (like every other "Or paste your decklist"/"Or paste
    the pool" field in the app -- the `custom_duel` waiting room's own
    submission form, the Decks dialog's create/edit form, and the
    Quick/Winston/Grid Draft custom-pool fields below, see below) carries
    a shared `.decklist-textarea` class giving it an explicit `17.0625rem`
    width, 150% of the ~`11.375rem` a bare `<textarea rows="10">` renders
    at with no width set of its own -- a decklist or custom pool can run
    to 60+ lines and the default width was cramped for reading it back
    while pasting or editing. The
    dropdown is populated (`populateSavedDecklistSelect()`, shared with the
    `custom_duel` waiting room below and the draft "Save deck" dialog's own
    friends-visible mirror) from `GET /decklists`, grouped into a "My
    decks" `<optgroup>` and one further `<optgroup>` per friend who has at
    least one friends-visible deck, labeled "`{friend}`'s decks" -- the same
    grouping/omission rules `#decks-friends-list` uses. Its default option
    reads "Paste/upload a decklist instead" and selecting anything else
    hides `#new-game-decklist-paste-fields` (the file/textarea pair,
    `updateDeckTypeDescription()`), since the two inputs are mutually
    exclusive -- submitting sends `saved_decklist_id` instead of
    `decklist_text` once a saved deck is picked, and the paste fields are
    simply not read.
    `updateDeckTypeAvailability()` **hides** (`option.hidden`, not merely
    `option.disabled`) whichever deck-type options don't make sense for
    the currently-selected format -- so the dropdown only ever *lists*
    options that are actually legal, rather than showing a doomed one
    grayed out -- via a small `isDeckTypeAvailableForFormat(deckType,
    format)` allow-list function: Custom Decklist whenever Duel is
    selected (custom decklists aren't supported for duel games), Custom
    Decklists (Duel) whenever Duel *isn't* selected (that option only
    makes sense for a duel), Power whenever either team format is
    selected (Power's 15 cards fall short of the 45-card minimum both team
    formats share -- see "Open Team Play"/"Closed Team Play" in
    `php-app/README.md`), and -- since the Draft format supports only Quick
    Draft/Winston Draft/Grid Draft -- every option *except* those three
    whenever Draft is selected, and Quick Draft/Winston Draft/Grid Draft
    themselves whenever Draft *isn't* selected. If
    the previously-selected option becomes unavailable, the dropdown falls
    back to the first option that's still available in document order --
    Structure for most formats, but Quick Draft for Draft (the first of the
    three options in document order), since none of the three is available
    anywhere else. Selecting Custom Decklists (Duel) reveals
    `#new-game-duel-rules-fields` instead of the decklist fields -- no
    decklist is entered here at all, only the deck-building *rules* both
    players' own decklists (submitted later, see the Board bullet below)
    will have to satisfy. A `#new-game-duel-rules-preset` dropdown
    (Structure/Power/jceddy's 75 Card/User-Defined) either shows a
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
    mean. Selecting Quick Draft reveals `#new-game-quick-draft-fields`
    instead -- a Pool dropdown (`#new-game-quick-draft-pool-source`: 48
    random cards, Structure deck, jceddy's 75 Card deck, One of Each Card,
    or Custom pool) with its own plain-language description below it
    (`QUICK_DRAFT_POOL_SOURCE_DESCRIPTIONS`, `updateQuickDraftPoolSourceVisibility()`),
    and, only for Custom pool, the same file-upload/paste pair the
    Traditional `custom` deck_type and the `custom_duel` waiting room both
    already use, feeding `quick_draft_custom_pool_text` instead of
    `decklist_text`/`custom_duel`'s own decklist field. Selecting Winston
    Draft reveals `#new-game-winston-draft-fields` instead -- the exact
    same shape one level down (`#new-game-winston-draft-pool-source`, same
    5 options, its own `WINSTON_DRAFT_POOL_SOURCE_DESCRIPTIONS` wording
    reflecting its own 45-card target rather than Quick Draft's 48, and the
    same Custom-pool file/textarea pair feeding
    `winston_draft_custom_pool_text`) -- `updateWinstonDraftPoolSourceVisibility()`
    mirrors `updateQuickDraftPoolSourceVisibility()` exactly. Selecting
    Grid Draft reveals `#new-game-grid-draft-fields` instead -- the same
    shape again (`#new-game-grid-draft-pool-source`, its own
    `GRID_DRAFT_POOL_SOURCE_DESCRIPTIONS` wording reflecting its own
    54-card target, and the same Custom-pool file/textarea pair feeding
    `grid_draft_custom_pool_text`) -- `updateGridDraftPoolSourceVisibility()`
    mirrors the other two exactly, except its own pool-source `<select>`
    has only 4 options, not 5: Structure deck is deliberately absent, since
    its 45 cards fall short of the 54 Grid Draft always requires and there's
    no top-up mechanism to cover the gap (see "Grid Draft" in
    `php-app/README.md`) -- offering it in the dropdown would just be a
    guaranteed `400` waiting to happen. Quick Draft, Winston Draft, and
    Grid Draft are all three only ever offered under the Draft format
    (`#new-game-format` has its own
    Draft option, functionally identical to Duel -- same 2-player,
    separate-per-player-deck engine, `updateOpponentSelectionLimit()` caps
    it at 1 opponent the same way Duel is -- but restricted to deck types
    that build a deck through some kind of live drafting process; Quick
    Draft was the first, Winston Draft joined it next, Grid Draft joined
    after that -- see "Draft
    format" in
    `php-app/README.md`). Polls `GET /games` every 4 seconds while the lobby is
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
    submitted yet, the same saved-deck dropdown/file-upload/paste form the
    Traditional `custom` deck_type uses in the New Game dialog --
    `#duel-deck-submit-saved-decklist` (populated the first time this
    section renders for the current game, via the same
    `populateSavedDecklistSelect()` helper, guarded by a
    `duelSavedDecklistSelectPopulated` flag so it's only fetched once per
    game view rather than on every 4-second board poll) hides
    `#duel-deck-submit-paste-fields` once a saved deck is picked, the same
    way the New Game dialog's own dropdown does -- wired to
    `POST /games/decklist` instead of `POST /games`, sending
    `saved_decklist_id` instead of `decklist_text` when a saved deck was
    selected. "Start game" itself
    stays hidden until every player's own `deck_submitted` is true, since
    the server would just reject starting otherwise. Once a `custom_duel`
    game is actually in progress, each player's own row in the Players
    list additionally shows their deck name (or "Uploaded Deck") on its
    own line underneath the username (`.player-deck-name`, a muted second
    line inside the same `.player-name` block rather than appended inline
    after the name) -- since unlike every other deck_type, a `custom_duel`
    game has no single deck the whole table shares, each player having
    submitted their own. The board title itself shows the *viewer's own*
    submitted deck name for a
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

    A `#draft-match-scoreline` line (`renderDraftMatchScoreline()`, reading
    whichever of `state.quick_draft`/`state.winston_draft`/`state.grid_draft`
    is non-null --
    all three share an identical outer shape, `your_wins`/`opponent_wins`/
    `games_to_win`/`next_game_id`, even though their own `drafting`
    sub-shapes differ -- hidden for every other deck_type) sits just below
    the round-status line and is always shown once a `quick_draft`/
    `winston_draft`/`grid_draft` game
    exists, regardless of whether the game itself is `waiting`/
    `in_progress`/`completed` -- "Best of 3 match, game N, you lead X-Y"
    (or "tied X-X"/"<opponent> leads Y-X", whichever side is actually
    ahead). No deck_type-specific label here (Quick Draft/Winston
    Draft/Grid Draft) -- that's already shown elsewhere on the board (the
    title), so this line stays purely about the match's own progress. The same function also
    owns `#draft-match-next-game-button`, right next to the scoreline:
    hidden unless `next_game_id` is set (only true once
    this specific game has completed but the match itself hasn't --
    `advanceDraftMatch()` already created the next game), and its
    `onclick` is just `showBoard(next_game_id)` -- a direct, prominent
    link to the next game from a just-finished one, instead of making the
    player go back to the lobby and pick the new `waiting` row out by hand.
    A `renderDraftPanel(state)` dispatcher
    (mutually exclusive with `#duel-deck-submission`, occupying the same
    waiting-room slot while `state.game.status` is `'waiting'`) shows
    whichever of `#quick-draft-panel`/`#winston-draft-panel`/
    `#grid-draft-panel` matches
    `state.game.deck_type`, covering each
    format's own two pregame phases. Quick Draft's own two phases are read
    from `state.quick_draft`:
    - **Drafting** (`#quick-draft-drafting`, `renderQuickDraftDrafting()`,
      shown while `state.quick_draft.status` is `'drafting'`) -- shows the
      current round number and one of four stage messages
      (`QUICK_DRAFT_STAGE_STATUS`) depending on `state.quick_draft.drafting.stage`:
      `'draw'` (your own 6 just-dealt cards, keep 2), `'received'` (the 4
      cards you actually received from your opponent, keep 2 — only
      determined once both players have submitted `'draw'`), or one of the
      two `awaiting_opponent_*` stages (an empty pack, a "waiting on your
      opponent" message — transient, since the round/stage advances
      automatically the moment they finish too). The pack itself
      (`#quick-draft-pack`) reuses the exact same click-thumbnail-to-open-
      `#card-detail-dialog`-with-a-`selection`-object picker pattern
      `renderInitialCardPass()` already established (a plain client-side
      `Set`, capped at 2, never sent until `#quick-draft-pick-submit-button`
      is pressed, which calls `submitQuickDraftPick()` — `POST
      /games/draft/pick`). That selection Set is keyed to the current
      `round:stage` pair (reset the moment either changes) rather than
      reset on every render, so it survives an ordinary 4-second poll
      mid-pick the same way `renderInitialCardPass()`'s own selection does.
      A `#quick-draft-kept-so-far` list shows every card you've kept in the
      draft so far (across all rounds, including whatever's already
      resolved this one), read-only.
    - **Deck building** (shared `#draft-deck-building` block, sitting
      outside `#quick-draft-panel`/`#winston-draft-panel`/`#grid-draft-panel`
      since its shape is identical for all three -- `renderDraftDeckBuilding()`,
      shown while `state.quick_draft.status`/`state.winston_draft.status`/
      `state.grid_draft.status`
      is `'deck_building'`) -- the exact same picker/endpoint
      (`submitDraftDeck()`, `POST /games/draft/deck`) serves both the
      very first trim and every later sideboard between the
      match's games; there's no "first trim" vs. "sideboard" distinction in
      the UI either. Its title/status text is built from
      `deckBuilding.min_deck_size`/`max_deck_size` (14/16 for Quick Draft,
      12/however-many-you-drafted for Winston Draft and Grid Draft alike),
      never hardcoded, so
      one function serves all three formats' own bounds correctly. All of your
      own drafted cards
      (`deckBuilding.drafted_cards`) show as toggleable
      thumbnails (same picker pattern again, no 2-card cap this time — any
      count within that format's own min/max is valid), pre-seeded from your current
      `deck_card_ids`; if that's null (this game's deck hasn't been
      (re)submitted yet), falls back to `previous_deck_card_ids` — whatever
      deck you last submitted, for the game that just ended — so
      sideboarding starts from your existing deck instead of forcing a full
      retrim from scratch before every game. Only the very first game of a
      match (no previous deck yet) still defaults to every drafted card.
      A `#draft-deck-reset-button` ("Reset to previous deck") sits next to
      Select all/Clear selection -- it re-seeds the selection from
      `deckBuilding.previous_deck_card_ids` on demand (via the same
      `cardIdsToDraftedCardIndices()` helper the initial seeding uses),
      undoing whatever sideboard changes have been made since without
      forcing a full "Clear selection" + reselect. It's only shown when
      `previous_deck_card_ids` is actually set -- i.e. never on the very
      first game of a match, where there's no previous deck to reset to.
      A card currently excluded from the deck is dimmed with a dashed
      border -- `buildCardThumb()`'s existing `.not-playable` treatment for
      an unplayable in-game hand card, reused here via its `notPlayable`
      option (`notPlayable: !selected`) purely for its "this one's excluded"
      visual, not its original "can't be played" meaning -- so it's obvious
      at a glance which cards have actually been cut. That seeding only
      happens once per game (`draftDeckSelectionInitialized`,
      reset by `showBoard()` whenever you switch games/sideboard into a new
      one) so an in-progress selection isn't silently overwritten by an
      ordinary poll. Once you've submitted, the picker itself hides in
      favor of a status line ("waiting for your opponent's deck" or "both
      decks are in"). "Start game" itself stays hidden until
      `deckBuilding.you_submitted` AND
      `.opponent_submitted` are both true. A `#draft-deck-save-button`
      ("Save deck…", issue #92) sits right before the Submit button,
      sharing its own min/max-size disabled check
      (`renderDraftDeckBuilding()` mirrors the two buttons' `disabled`
      state) -- it opens a small `#draft-deck-save-dialog` (name input, a
      "Share with friends" checkbox) that, unlike Submit, never touches
      `GameService`/match state at all: the frontend already knows the
      current selection's resolved card ids client-side, so its submit
      handler derives `deckCardIds` from the same `draftDeckSelection`
      Set the picker itself uses and `sideboardCardIds` as its complement
      -- every drafted card that *isn't* in the current selection --
      and posts both straight to `POST /decklists` (`createDecklist()`),
      saving a personal copy of the in-progress build under its own name
      without submitting it, ending the deck-building step, or being
      visible to the opponent. See "Saved decklists" in
      `php-app/README.md`.
    - **Who goes first** (`#draft-first-player-choice`, sitting inside
      `#draft-deck-building` above the picker so it's visible independent
      of deck submission -- `renderFirstPlayerChoice()`) -- `null` for
      game 1 of a match, so the whole block stays hidden there. From
      game 2 on, both players always see a status line naming who's
      going first (either the previous game's own winner by default, or
      whoever the previous loser actually chose); only the previous
      loser's own client shows the two choice buttons ("I'll go first" /
      "`<opponent>` goes first"), which call
      `chooseFirstPlayerForNextMatchGame()` (`POST
      /games/draft/first-player-choice`) and re-render immediately once
      a choice sticks. Entirely optional -- nothing here gates the
      Submit-deck button or `autoStartGameIfReady()`. Pool/pack/drafted cards are all
      served by a catalog-only card shape (`GameService::serializeCatalogCards()`)
      rather than the usual in-play `serializeCard()` result, but with the
      exact same field names `buildCardThumb()`/`openCardDetail()` already
      read (`card_id`, `name`, `color`, `value`, `rules_text`, etc.), so
      neither function needed any change to render a card that hasn't been
      dealt into a game yet. See "Quick Draft"/"Winston Draft"/"Grid Draft"
      in
      `php-app/README.md` for the formats themselves.
    - **Winston Draft's own drafting phase** (`#winston-draft-panel` >
      `#winston-draft-drafting`, `renderWinstonDraftDrafting()`, shown
      while `state.winston_draft.status` is `'drafting'`) -- unlike Quick
      Draft's simultaneous pack-pick, only one player acts at a time (see
      `GameService::winstonDraftDraftingStateFor()`), so there's no
      selection `Set` to manage: each of the 3 piles renders as a labeled
      stack showing its size (`drafting.pile_sizes`, always visible to
      both players -- a real face-down stack's height is visible even when
      its contents aren't), and only the *current* pile's cards
      (`drafting.current_pile_cards`, populated by the backend only when
      it's actually your turn) render as thumbnails inside it. `#winston-
      draft-take-button`/`#winston-draft-pass-button` (hidden entirely when
      it isn't your turn; Pass itself is also hidden on pile 3, since
      declining there is a mandatory deck-draw, not a choice) call
      `submitWinstonDraftPick()` (`POST /games/draft/winston-pick`) with
      `action: 'take'`/`'pass'` -- no card selection needed, since taking a
      pile claims it whole. `drafting.remaining_deck_count` and a
      `#winston-draft-drafted-so-far` read-only list (your own accumulated
      picks -- never your opponent's) round out the panel, along with a
      `#winston-draft-opponent-info` line built from
      `drafting.opponent_drafted_card_count` (how many cards the opponent
      has drafted in total) and either `drafting.opponent_last_take_pile_number`
      (which pile number -- never its contents -- they most recently
      claimed) or `drafting.opponent_last_drew_from_deck` (`true` if they
      instead most recently declined all 3 piles and took the mandatory
      top-of-deck draw); neither is shown until the opponent has completed
      at least one turn.
    - **Grid Draft's own drafting phase** (`#grid-draft-panel` >
      `#grid-draft-drafting`, `renderGridDraftDrafting()`, shown while
      `state.grid_draft.status` is `'drafting'`) -- like Winston Draft,
      only one player acts at a time, so there's no card-selection `Set`
      either: the active player picks a whole row or column, never
      individual cells. `#grid-draft-grid` renders all 9 cells of
      `drafting.grid_cards` (always fully visible to both players -- unlike
      Winston Draft's face-down piles, a dealt grid is face-up on the
      table) in the same row-major order `getState()` reports them in, via
      a CSS grid (`#grid-draft-grid { grid-template-columns: repeat(3, ...) }`);
      a cell that's already been taken this round (`null` in
      `grid_cards`) renders as a plain dashed placeholder
      (`.grid-draft-cell--empty`) instead of a card thumbnail.
      `#grid-draft-picks` renders 6 buttons (Row 1-3, Column 1-3), each
      labeled with however many cells are actually still non-null along
      that line (`gridDraftLineCells()`, a client-side mirror of
      `GameService::submitGridDraftPick()`'s own cell-counting logic) --
      "Row 2 (3 cards)", "Column 1 (2 cards)", etc. A line with 0 cards
      left (the second pick choosing the exact same line the first pick
      already fully cleared) renders disabled rather than being sent to
      the server only to be rejected. Clicking a button calls
      `submitGridDraftAction(axis, index)` (`submitGridDraftPick()`, `POST
      /games/draft/grid-pick`). `drafting.remaining_deck_count` and a
      `#grid-draft-drafted-so-far` read-only list (your own accumulated
      picks) round out the panel. Unlike Winston Draft's own
      drafted-so-far list (strictly your own picks, since its piles are
      genuinely hidden from you until you take them), Grid Draft's grid is
      face-up and visible to both players the whole time, so there's a
      second `#grid-draft-opponent-drafted-so-far` read-only list right
      underneath it showing your opponent's own accumulated picks too
      (`drafting.opponent_drafted_so_far`) -- nothing there was ever hidden
      information to begin with. Its heading (`#grid-draft-opponent-drafted-so-far-title`)
      is set to the opponent's own username (the same `currentOpponentUsername`
      the deck-building waiting-for-submission message already uses) rather
      than a generic "Opponent", e.g. "alice's drafted so far".

    Clicking any hand
    card opens `#choices-panel` inline, underneath the hand -- a plain
    block element (not a `<dialog>`/overlay, deliberately: an overlay was
    tried and reverted, since it made the rest of the board -- in-play
    moods, discard pile, opponents' state -- harder to reference while
    still choosing a target) showing an enlarged view of the card's own
    art in place of a separate name/rules-text heading (its `alt` text
    carries both, for accessibility) plus whatever choice fields it
    needs and Play/"Close" (labeled "Close" rather than "Cancel" --
    once a play is actually submitted, closing the panel can't retract
    it, and "Cancel" reads as if it might). A server-side rejection from actually
    submitting the play (caught after the panel's own client-side checks
    below already passed) surfaces inside the panel itself, reusing
    `#choices-validation`'s existing spot right next to the Play button,
    rather than the board's own `#board-error` above the hand -- easy to
    miss while attention is still on the fields just below it. The Play
    button itself disables and relabels to "Playing..." the instant it's
    clicked, before the request even resolves, so a slow response can't
    read as a missed click and prompt a second, duplicate submission; on a
    server-side rejection it re-labels back to "Play card" and re-enables
    (a plain `disabled = false`, not a recomputed
    `updatePlayButtonEnabled()` call, which would otherwise overwrite
    `#choices-validation` with its own client-side-only verdict and
    clobber the server's actual rejection message that was just shown
    there) so the player can adjust their choice and try again; on success
    the whole panel closes anyway, so there's nothing left to re-enable.
    Cards with
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
    The `'discard_card'` case also excludes `card.card_id` from its own
    candidates, the same way `'hand_card'` already did -- normally a no-op
    (the card being played hasn't reached the discard pile yet), but
    Melancholy's "play moods from the discard pile as though they were in
    your hand" makes it a real case: without this, Nostalgia (or Cynicism/
    Corruption) played straight out of the discard pile could offer its
    own just-about-to-be-played instance as a candidate, which the server
    would then reject once it's actually moved into play (see
    `MoodPlayService::playMood()`'s `moveDiscardToInPlay()` call, which
    happens before any effect's `afterPlaying()` runs).
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
    ordinary Play/Close choices panel instead. The viewer's own hand cards
    make this same read-only-vs-Play/Close split whenever it isn't their
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
    via `POST /games/respond` — Disillusionment's own dropdown is optional
    (its printed text is a "may"), so leaving it on its default "(none)"
    option and responding anyway is a fully valid answer: the respond
    button is never disabled for it the way it is for a `required` field
    with nothing chosen. Betrayal uses this exact same pending-decision
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

    **Game log (issue #98).** The "Recent plays" list above is capped at
    15 entries and shows only the current game's board -- a "View log"
    button next to its own `<h3>Recent plays</h3>` heading, and a second
    one under every lobby row's own Play/View button (so a *completed*
    game's log is reachable without reopening its board at all), each
    open the same `#game-log-dialog` (`openGameLog()`), fetching the
    game's ENTIRE history via `GET /games/log`
    (`GameService::fullEventLog()`, chronological oldest-first, no cap --
    see `php-app/README.md`). Reusing recentEvents' own `describeEvent()`
    rendering keeps the two views' phrasing identical; the only
    presentation difference is how a description with more than one
    semicolon-joined segment (e.g. Malice's own color cascade, or a
    play that also logs a draw/grant) renders: instead of one run-on
    sentence, `buildLogEntryContent()` splits it into a bulleted `<ul>`
    headed by its first segment, one `<li>` per segment, so a multi-part
    event reads as a scannable breakdown rather than a single dense line.
    The dialog's three buttons all operate on the SAME already-fetched
    `currentGameLogEvents` array, so none of them re-request the log:
    **Copy text** (`navigator.clipboard.writeText()`) and **Download
    text** (`downloadFile()`, a throwaway `<a download>` + object URL,
    since there's no server-side file to link to) both render every
    event through `formatLogEntryAsText()` -- the same "bulleted list
    headed by the first item" treatment as on-screen, just `'- '`-prefixed
    lines instead of an actual `<ul>`, joined with a blank line between
    events -- while **Download data** hands the browser
    `JSON.stringify(currentGameLogEvents, null, 2)` verbatim, a genuine
    raw export (every event's own `event_type`/`round_number`/
    `acting_game_player_id`/`card_id`/`details` alongside the resolved
    `acting_username`/`card_name`/`description`) rather than a repeat of
    the human-readable text the other two buttons produce. Both
    "View log" buttons -- and both new lobby-row buttons more generally
    -- sit in their own `.lobby-actions` column now (Play/View above,
    View log below), rather than as flex siblings of `.lobby-row`
    directly, so the secondary action reads as subordinate to the
    primary one instead of competing with it for the row's right edge.

    **Shared deck view (issue #197).** Until now, the board only ever
    showed a deck *count* (`Deck: N cards left`) and, for `custom`, the
    deck's own name -- never its actual contents. A "View decklist"
    button (`#view-shared-deck-button`, right next to "View log") and a
    matching one on every applicable lobby row (same
    `.lobby-actions`-column placement as "View log") both open the same
    `#shared-deck-dialog` (`openSharedDeckView()`), fetching a shared-deck
    game's entire deck via `GET /games/deck`
    (`GameService::viewSharedDeck()`, sorted white/blue/black/red/green
    then alphabetically by name -- see `php-app/README.md`). Both buttons
    are conditional on `isSharedDeckType(deckType)`, a small JS mirror of
    `GameService::isSharedDeckType()` (every `deck_type` except
    `custom_duel`/`quick_draft`/`winston_draft`/`grid_draft`, which each
    give every player their own separate deck instead of one shared pool)
    -- the board's own button is toggled in `renderBoard()` alongside
    `resign-button`, and each lobby row's is only appended
    (`buildGameRow()`) when the game is also *not* still `'waiting'`,
    since nothing's been dealt yet at that point (matching
    `viewSharedDeck()`'s own `409` for that case). The dialog itself just
    renders `body.cards` in the order the server already returned them
    (`buildCardThumb()`/`openCardDetail()`, the same card-grid pattern
    every other card list in this app uses -- hand, discard pile, drafted
    pool, a saved deck's own view) rather than re-sorting client-side.
    `dialog#shared-deck-dialog[open]` gets its own wider `max-width`
    (48rem, vs. the game log dialog's 36rem) plus `max-height`/
    `overflow-y: auto` (same reasoning as `dialog#game-log-dialog[open]`
    just above it in `style.css` -- a `one_of_each` deck can run to 133
    cards, and a card thumbnail takes up considerably more room than a
    line of log text).

    `#pending-decision-banner` and `#scoring-preview` are two more elements
    with this exact same failure shape, caught later: both live outside
    `#in-progress-area` (a pending decision/scoring preview belongs to
    whichever game most recently had one, not necessarily the one on
    screen now), and both are only ever updated by `renderPendingDecision()`/
    `renderScoringPreview()` calls that sit *after* `renderBoard()`'s early
    `return` for a game whose `status` is still `'waiting'` (drafting/
    deck-building) -- so switching straight from an in-progress game that
    had either visible to a still-drafting game left that OTHER game's
    stale text sitting on screen, since `inProgressArea.hidden = true`
    alone doesn't touch either of them. Fixed the same way as the
    `#board-message` case above: the `'waiting'` branch now explicitly
    calls `renderPendingDecision(null)`/`renderScoringPreview(null)` up
    front, rather than only relying on being reached during a normal
    in-progress render.

    The "Players" list near the top of the board tags the viewer's own row
    with a "(you)" suffix right after their username
    (`state.you.game_player_id === player.game_player_id`), so it's
    unambiguous at a glance which row is theirs even in a 4-player game with
    similar-looking usernames. The suffix has its own `.player-you-tag`
    color (`--color-info`, bold) rather than being plain text, so it reads
    as a tag next to the name instead of looking like part of the username
    itself. It also shows each player's seat,
    current point total, win count, and hand size as small inline SVG
    icons (issue #143) rather than spelled-out text — a bench (seat), a
    star (points), a trophy (wins), and two overlapping cards (hand size)
    — each colored to match what it depicts rather than every one
    defaulting to the same muted gray: seat/hand-count in brown
    (`--color-brown`, a bench and a hand of cards), points/wins in gold
    (`--color-gold`, a star and a trophy), and the went-first pennant in
    red (reusing `--color-error`, already theme-tuned for both light and
    dark). The on-turn triangle keeps its own existing green
    (`--color-success` via `.player-flag--turn`) — each with a numeric badge overlaid on its lower-right corner
    (`.player-stat__badge`, the same overlay convention `.card-thumb__badge`
    already uses for a card's own current value). The badge's background is
    a 40%-opacity mix of the theme's surface color
    (`color-mix(in srgb, var(--color-surface) 40%, transparent)`) rather
    than solid, so the icon underneath is still partly visible through it
    instead of being almost entirely hidden behind an opaque plate — the
    number is the point of the badge, not full coverage of the icon it
    sits on. `player.total_score` is
    a live sum of what's actually on the board right now, i.e. what each
    player would score if the round ended this instant, not anything
    accumulated from earlier rounds; distinct from `total_wins`, which only
    counts outright round victories. Every icon keeps its full original
    text (e.g. "12 point(s)", "Seat 0") as both a `title` tooltip and an
    `aria-label` on its wrapping `<span role="img">`, so a screen reader or
    a sighted user hovering for a reminder still gets the exact same
    information the old plain-text clauses gave (see `buildPlayerStat()`/
    `buildStatIcon()`/`PLAYER_STAT_ICON_PATHS` in `game.js`, and the
    `.player-stat`/`.player-flag` rules in `style.css`). It also marks
    whoever went first this round (`state.round.first_game_player_id`,
    already tracked server-side for Chivalry/Honor/Triumph-style effects
    but previously never surfaced to the client) with its own small flag
    icon (`buildPlayerFlag()`), independent of (and possibly a different
    player from) whoever the current-turn marker — a play/active triangle,
    rendered in `--color-success` via `.player-flag--turn` to match this
    app's existing "your turn" bold/success-color convention — currently
    marks. Every row's icons line up at the same horizontal position
    regardless of how long that row's own username (or, for `custom_duel`,
    deck name) is: `renderBoard()` measures the widest `.player-name` block
    in the just-rendered list and applies that as a shared `min-width` to
    all of them, rather than hardcoding one width that would either clip a
    long username or leave a short one with an oddly large gap before its
    icons start. All the
    icons/flags/thumbnail for a row share one `.player-icons` wrapper (its
    own flex-wrap container) rather than wrapping directly as children of
    the row itself, and the row is `flex-wrap: nowrap` so the name and this
    wrapper always stay on the same line — the wrapper just shrinks (see
    its `flex: 1 1 auto; min-width: 0`) and wraps its own children within
    whatever width is left. On a narrow viewport this means an overflowing
    icon continues on a second line starting right under the first icon
    (this wrapper's own left edge), not back at the row's far-left edge
    underneath the username. In games of 3+ players, whoever holds Hurt Feelings
    (`state.round.hurt_feelings_game_player_id`,
    already tracked server-side to grant that player 2 plays instead of 1
    this round, but previously never surfaced to the client either) gets a
    small `img/hurt-feelings.webp` thumbnail next to their row instead of a
    plain text tag, so the extra play they're about to get isn't a
    surprise. Clicking it opens the same generic `#art-preview-dialog`
    an enlarged card-art view uses (`openArtPreview()`) with an enlarged
    view of that art, since Hurt Feelings is a round-level marker/token,
    not a `cards` row (see migration `0003`'s own header comment), so it
    has no `catalog_card_id`/`rules_text` to build a card-detail-dialog-style
    view from. Whoever a delayed choice response is currently pending on
    (Compulsion, Arrogance/Intimidation/Instability/Suspicion/
    Disillusionment/Malice, a Duplicity repeat offer, or a scoring-time
    Enthusiasm/Passion decision — see `round.pending_decision` in
    `php-app/README.md`'s `/games/state` entry) gets its own small hourglass flag icon
    (`state.round.pending_decision.target_game_player_id`, compared against
    each row's own `game_player_id`) in `--color-pending`, this app's
    existing color for "something is actively blocking on a response" (the
    lobby's own awaiting-response styling already uses it). `target_game_player_id`
    is always visible to every player in the game, unlike the actual prompt
    (`pending_decision.field`), which stays targeted-player-only — the same
    "a real opponent across the table would see someone visibly puzzling
    over a card, just not what it says" principle other open-information
    fields on this page already follow.

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
  - A "Resign game" button (`#resign-button`) sits right after Pass --
    unlike Pass, it isn't turn-gated (you can resign any time it's
    `in_progress`, not just on your own turn), so it's only ever hidden
    once the game's over or the viewer has already resigned, and disabled
    while a decision is pending (mirrors the backend's own
    `assertNoPendingDecision()` gate -- resolve it first). Clicking it
    shows a `window.confirm()` prompt first, since resigning can't be
    undone, then calls `POST /games/resign` (`resignGame()` in `app.js`)
    and runs the exact same success path Pass does
    (`announceOutcome()`/`refreshBoard()`). For 2-player and team-format
    games this ends the game immediately (`announceOutcome()` shows "Game
    complete!" the same as any other game-ending action); for a 3-4
    player `standard` game it doesn't -- the resigning player instead
    gets a small `(resigned)` tag next to their name in the players list
    (`.player-resigned-tag`) and the game carries on without them. See
    "Resigning" in `php-app/README.md`.
  - A "Friends" button opens a `<dialog>` for managing friends: send a
    request by username/email, accept/decline/block incoming requests,
    view sent (outgoing) requests, and remove existing friends. All of it
    talks to the `/friends/*` endpoints.
  - A "Decks" button (`#decks-button`, issue #92) opens `#decks-dialog` for
    managing saved decklists -- letting a player build up a personal
    library of decks instead of re-pasting/re-uploading the same text
    every time they start a `custom`/`custom_duel` game. A create/edit
    form (`#decks-form`) takes a name, a "Share with friends" checkbox
    (`#decks-form-friends-visible` -- the only visibility choice; there's
    no third all-users-public tier, see "Saved decklists" in
    `php-app/README.md`), and the deck's own cards via the exact same
    file-upload-into-textarea pattern (`#decks-form-file`/
    `#decks-form-text`) the New Game dialog's Custom Decklist fields and
    the `custom_duel` waiting room already use. `refreshDecksData()`
    (`GET /decklists`) renders two lists: "Your decks"
    (`#decks-own-list`) and "Friends' decks" (`#decks-friends-list`).
    Each `<li>` row is split into two flex children: a left,
    flex-growing `.decks-row-info` (built by
    `buildDeckRowInfo(deck, includeFriendsIcon)`, shared by both lists)
    and a right, `flex-shrink: 0` `.decks-row-actions` holding that row's
    buttons -- so the info column is free to wrap onto its own width
    while the actions column stays put flush against the dialog's right
    edge. `.decks-row-info` itself stacks two lines: `.decks-row-name`
    (the deck's name, bold) above `.decks-row-meta`, a left-justified
    row containing a card-count icon+badge
    (`buildPlayerStat('hand', deck.card_count, ...)`) rather than a plain
    "(N cards)" text clause -- reusing the exact same `.player-stat--hand`
    icon (two overlapping card portraits) the board's own players list
    already uses for hand size, since a saved deck and an in-hand card
    count are conceptually the same thing: a pile of cards with a size
    worth badging rather than spelling out in words -- and, for a
    friends-visible own deck, a small two-person icon right after that
    badge (`buildPlayerFlag('friendsShared', 'Shared with friends', ...)`,
    `.player-flag--friendsShared`, colored via the same `--color-info`
    blue already established for the lobby's own "in progress" status),
    reusing the exact icon/tooltip/`aria-label` convention the board's
    own players list established for issue #143
    (`title`/`role="img"`/`aria-label` together mean a mouse-hover tooltip
    and a screen reader both still get the full "Shared with friends"
    text even though nothing on screen literally spells it out anymore).
    `buildDeckRowInfo()`'s `includeFriendsIcon` flag is `true` for
    "Your decks" rows and `false` for "Friends' decks" rows, since every
    deck shown there is already friends-visible by definition -- the icon
    would be redundant. Putting the card-count/friends-shared icons on
    their own line under the name (rather than trailing after it, or
    after the action buttons) replaced what used to be a plain
    "(N cards, shared with friends)" text clause, since every own-deck row
    already carries an action button per column and the extra text made
    rows harder to scan at a glance. `.player-stat`/`.player-flag`'s
    shared rule carries `flex-shrink: 0` -- without it, the icon (which
    has no natural min-content size of its own to resist shrinking) gets
    squeezed below its specified `1.5rem` by `.decks-row-meta`'s own
    plain `display: flex` (no `flex-wrap`, unlike `.player-icons` on the
    board, which wraps overflow to a second line instead of shrinking
    it) whenever a row's name text and buttons don't leave quite enough
    room -- and since how much room is left over depends on the name's
    own length, two otherwise-identical icons on two different rows
    would end up rendering at visibly different sizes purely because
    their deck names differ.

    A "Your decks" row's `.decks-row-actions` holds
    View/Build/Edit/Duplicate/Download/Delete, all rendered as small square
    icon-only buttons (`iconActionButton('view'/'build'/'edit'/'duplicate'/
    'download'/'delete', label, onClick)`, an eye/wrench/pencil/
    two-overlapping-sheets/download-tray/trash-can, standard Material
    Design glyphs reused verbatim in a new `ACTION_ICON_PATHS` map
    alongside the existing `PLAYER_STAT_ICON_PATHS` -- separate from it
    since these live inside an actual `.icon-action-button` rather than
    the players list's own icon+badge convention, and don't need a badge
    overlay) instead of six separate text buttons, so they take up
    noticeably less horizontal room next to a name that might already be
    wrapping onto 2 lines -- same `title`/`aria-label` treatment as every
    other icon on this page, so "View"/"Build"/"Edit"/"Duplicate"/
    "Download"/"Delete" are still each button's accessible name for a
    screen reader (and Playwright's own `getByRole('button', {name:
    'View'})`-style lookups) even though nothing on screen literally
    spells the word out anymore. All six sit together in one
    `.decks-row-actions-grid` (a 3-column CSS grid,
    `grid-template-columns: repeat(3, auto)`, filled row-major in the
    order they're appended -- View/Build/Edit on the top row,
    Duplicate/Download/Delete directly beneath). Delete's own icon is
    still colored with `.icon-action-button--delete { color:
    var(--color-error) }` (the `<svg>`'s `fill="currentColor"` means
    setting the button's own `color` is enough, no separate `fill`
    override needed) -- the same error-red already used for validation
    messages, so the one irreversible action still stands out by color
    even though it's no longer set physically apart from the rest.
    "Friends' decks" (`#decks-friends-list`) rows are simpler:
    `.decks-row-actions` holds `view`, `duplicate`, and `download` icon
    buttons in a plain flex row (no grid needed for three buttons) -- no
    Build/Edit/Delete, since those change/remove a deck someone else
    owns, but Duplicate and Download both work exactly as they do on an
    owner's own row, since
    both are just `viewDecklist(id)` under the hood and the backend's
    `UserDecklistService::view()`/`authorizeViewer()` already authorizes
    any accepted friend to view a `visibility='friends'` deck (the very
    check that lets the row render in "Friends' decks" at all -- see
    "Saved decklists" in `php-app/README.md`). A
    friend with no friends-visible decks is omitted entirely from
    "Friends' decks," not shown with an empty section, and each of that
    section's rows is labeled with that friend's own username. "Edit" (`startEditingDeck()`)
    populates the form from that deck's own contents, including
    `#decks-form-text` itself -- `buildDecklistText()` reconstructs the
    deck's decklist text in the exact `About`/`Name`/blank-line/card-lines/
    optional-`Sideboard` format `DecklistParser` accepts (one counted line
    per distinct card, e.g. `"1 Bliss (MSW) 108"`, collapsing repeat
    copies of the same card rather than one line per copy), so editing a
    deck's cards reads and works the same way creating one does instead of
    leaving the field blank behind a silent stashed-ids fallback. That
    fallback still exists (`editingDeckCardIds`/`editingDeckSideboardCardIds`,
    stashed alongside) purely for the edge case of a user clearing the
    (now pre-filled) field entirely and submitting anyway, meaning "keep
    the cards, I only touched the name/visibility" -- the form's submit
    handler only re-parses `#decks-form-text` when it's non-empty,
    otherwise reusing the stashed ids. A hidden `#decks-form-id` field is
    what decides whether submitting calls `POST /decklists` (create) or
    `POST /decklists/update` (edit) in the first place.

    "Duplicate" (`duplicateDeck()`) resets the form back to its own "new
    deck" state (`resetDecksForm()`, the same reset a Cancel does) and
    then fills `#decks-form-text` with just that deck's card lines
    (`buildDecklistCardsText()` -- the same per-card-line formatting
    `buildDecklistText()` uses, but deliberately *without* the `About`/
    `Name` header, since showing "Name `<the old deck's name>`" in the
    pasted text while the visible Name field sits blank -- ready for a
    new name -- would read as a mismatch rather than an invitation to
    type one) -- so clicking "Save deck" afterward creates a brand new
    deck with the same cards under whatever name is typed in, leaving
    the original deck untouched. "Download" (`downloadDeck()`) saves a
    `<deck name>.txt` file containing that same full `buildDecklistText()`
    output (`About`/`Name` header included this time, since there's no
    blank-Name-field mismatch to worry about for a plain file save) via
    the generic `downloadFile()` helper the game log's own download
    button already established (an object-URL `<a download>` click,
    since there's no server-side file to link to -- the content is
    assembled entirely client-side) -- a slash in the deck's own name is
    swapped for a dash first, since that's the one character which would
    otherwise be misread as a path separator rather than literal text.
    "View" (`openDeckView()`) opens a separate small
    `#deck-view-dialog` showing the deck's name, the same card-count
    icon+badge and (when applicable) friends-shared icon its own row in
    the list carries, and its full contents as two card-thumb grids
    (main deck, and a sideboard grid only shown when the deck actually
    has one) -- reusing
    `buildCardThumb()`/`openCardDetail()`, the same helpers every other
    card grid in this app already uses, so a saved deck's cards are just
    as clickable-for-detail as an in-game hand or draft pool.
    `#deck-view-close-button` carries its own small `margin-top`
    (`#deck-view-close-button` in `style.css`, the same reasoning as
    `#new-game-close-button` above) so it doesn't sit flush against
    whichever grid ends up last -- the card grid when there's no
    sideboard, the sideboard grid otherwise.
  - **Deck builder (issue #93).** A card-by-card alternative to the form
    above, opened either empty (`#decks-build-new-button`, "Build a new
    deck") or pre-loaded with an owned deck's own cards/name/visibility
    (a "Build" icon action alongside View/Edit/Duplicate/Download on each
    of "Your decks"' own rows) -- both call `openDeckBuilder(existingId?)`,
    which shows `#deck-builder-dialog`. The catalog panel
    (`#deck-builder-catalog-cards`) lists every card from `GET
    /cards/catalog` (fetched once and cached in `deckBuilderCatalog`,
    read-only reference data no more likely to change mid-session than
    `DECK_TYPE_DESCRIPTIONS`), filterable by set/color/rarity (three
    `<select>`s) and a case-insensitive name-or-rules-text substring
    (`#deck-builder-filter-text`, matched client-side in
    `cardMatchesDeckBuilderFilters()`) -- clicking a card's own "+ Add"
    button (not the card-thumb itself, which still opens the usual detail
    dialog on click, same as every other card grid) appends it to
    `deckBuilderCardIds`, the flat array (one entry per copy, same
    convention `GameService::viewSharedDeck()`/`openDeckView()` already
    use) backing the deck panel (`#deck-builder-deck-cards`) on the other
    side. Both panels get their own independent three-select multi-sort
    (`#deck-builder-catalog-sort-1/2/3` for the catalog,
    `#deck-builder-deck-sort-1/2/3` for the deck under construction) --
    Color/Rarity/Name/unsorted, applied in order via a single stable
    `Array.sort()` pass (`sortBuilderCards(cards, selectIdPrefix)`, shared
    by `renderDeckBuilderCatalog()`/`renderDeckBuilderDeck()`) -- issue
    #93's own "multi-sort" ask -- each later key only breaking ties the
    earlier ones left, with Color/Rarity using the same
    white/blue/black/red/green / common/uncommon/rare/mythic reference
    orders `GameService::viewSharedDeck()`/`DuelDeckRules` already sort
    by rather than plain alphabetical (neither name sorts into a
    meaningful order alphabetically). Sorting the catalog doesn't affect
    the deck panel's own order or vice versa -- each panel's sort
    controls only re-render that one panel.

    `#deck-builder-format` (Free-form/Power Duel/Structure Deck/jceddy's
    75 Card) restricts which cards `canAddCardToBuilderDeck()` allows
    adding *while building* -- its four options' numbers mirror
    `GameService`'s own `buildPowerDeckCardIds()`/
    `buildStructureDeckCardIds()`/`buildJceddys75DeckCardIds()` (Power:
    exactly 1 mythic + 14 other singleton non-mythics; Structure: 23
    common/14 uncommon/6 rare/2 mythic, all singleton; jceddy's 75: per
    color, 1 mythic/2 rares (singleton)/4 uncommons (up to 2 copies
    each)/8 commons (up to 3 copies each)) -- a card's own "+ Add" button
    is `disabled` once adding it would exceed whatever cap applies, so
    the deck can never be walked into an over-cap state to begin with,
    rather than only being validated after the fact. Switching formats
    mid-build does NOT retroactively remove anything already added (a
    saved decklist has no `format` column of its own, same as one built
    by pasting text) -- only further additions are gated by the newly
    selected format. `#deck-builder-deck-summary` shows a running
    `<count> / <target>` (or just `<count>` for Free-form, which has no
    target) so the caps stay visible while building.

    Saving (`#deck-builder-save-button`) calls the exact same
    `createDecklist()`/`updateDecklist()` the paste/upload form's own
    submit handler uses -- `deckBuilderEditingId` (set by
    `openDeckBuilder()` when opened via a "Build" row action, null
    otherwise) decides create vs. update, the same role
    `#decks-form-id` plays for that form. No separate save endpoint
    needed, since a saved decklist is just a `card_ids` array regardless
    of how it was assembled.
  - That same button gets a small red notification dot
    (`.has-friend-request`, applied/removed by
    `setFriendRequestNotification()`) whenever `/friends/invites` returns
    at least one incoming request -- so a pending request is visible
    without opening the dialog. `checkFriendRequestNotification()` polls
    this independently of `refreshLobby()`/`refreshBoard()`'s own 4-second
    `pollTimer` (a 15-second interval, since `#friends-button` lives in the
    page's always-visible header, outside both `#lobby-view` and
    `#board-view`, and a friend request is far less time-sensitive than
    in-progress game state); `refreshFriendsData()` (the dialog's own data
    fetch) also re-applies it immediately from the same `incoming` array
    it already has, so accepting/declining/blocking a request clears the
    dot right away rather than waiting for the next poll.

All of the above talk to the PHP API at `/app/*` via `js/app.js`'s helpers,
using the same-origin `session_token` cookie for auth — see
[`../php-app/README.md`](../php-app/README.md) for the API itself.

## Layout

- `css/` — Stylesheets.
- `js/` — Client-side scripts (`app.js` holds shared API helpers; each page
  has its own small script wiring up that page's behavior).
