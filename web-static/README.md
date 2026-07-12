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
endpoints -- and renders it as e.g. "v0.1.0". Fetched with `cache:
'no-store'` so a page loaded shortly after a deploy can't keep showing a
stale, browser-cached version string. See "Versioning" in the top-level
README for what the version itself means and where it's bumped.

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
  under, rather than mixing its art in with the official pool's.

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
    status, and whether it's your turn — status reads as e.g. "In
    Progress" rather than the raw `in_progress` the API returns
    (`humanizeStatus()`, a generic snake_case-to-Title-Case transform, not
    a fixed per-status lookup table, so any future status value still
    reads reasonably without needing an update here). The list itself is
    rendered in whatever order the API returns (no client-side re-sort) --
    `GET /games` always puts `waiting`/`in_progress` games above
    `completed` ones regardless of recency, so a stalled active game never
    gets buried below a long-finished one; see "Game timestamps" in
    `php-app/README.md`. A "New game" dialog
    picks 1-3 friends (via `GET /friends`) plus a format, then calls
    `POST /games`. `updateOpponentSelectionLimit()` caps how many friends
    can be checked at once to match the format's actual player count --
    3 normally, but only 1 for Duel, since a duel is exactly 2 players and
    the server rejects anything else (see "Duel: separate per-player
    decks" in `php-app/README.md`). It runs on every checkbox's own
    `change` as well as the format `<select>`'s: switching to Duel with 2
    friends already checked auto-unchecks the second one and disables the
    rest, and switching back to Traditional re-enables them, so you can't
    submit a Duel request the server will just reject with a 400. The
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
    that option only makes sense for a duel. Selecting Custom Decklists
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
    had chosen. Clicking any hand
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
    without a round trip to find out. Every option naming an in-play mood
    or a discard-pile card appends its owner (`cardLabel(card) + ' — ' +
    playerLabelFor(...)`, or the discard pile's own last-known
    `last_owner_name`) — needed since a `'duel' game's two independent
    decks can put the same printed card in play (or the discard pile)
    twice at once, and a bare name alone can't tell two "Discipline" options
    apart in, say, Pacifism's own "one per player" target list. This
    matches how the in-play list itself already labels every card with its
    owner regardless of any choice field being open. If a filled-in choice is still
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
    (name, base value, alt value if it has one, current value if a
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
    renders any decision, self-targeted or not, the same way. Suspicion, Disillusionment, Avoidance,
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
    this round, but previously never surfaced to the client either) gets
    its own "— has Hurt Feelings (2 plays this round)" tag, so the extra
    play they're about to get isn't a surprise.

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
