# Unity client implementation plan

Goal: a desktop/mobile native client on top of the `php-app/` REST service,
implementing most/all of what `web-static/` does, with an interface closer to
Magic Arena -- but supporting up to four players per game.

## Decisions

| Decision | Choice |
|---|---|
| UI framework | uGUI (`com.unity.ugui`) |
| JSON | Newtonsoft (`com.unity.nuget.newtonsoft-json`), replacing `JsonUtility` |
| First target platforms | Windows desktop + Android (iOS last -- needs a Mac/Xcode) |
| Mobile orientation | Landscape only |
| Render pipeline | URP (Built-In is deprecated since Unity 6.5) |

Still open (to be raised when the relevant phase approaches -- see "Open
decisions"): push notifications, card art licensing, four-seat table layout.

## What the repo gives us

- **Backend:** ~130 JSON routes in `php-app/public/index.php`. Auth is a
  `session_token` cookie; `ApiClient.cs` already replays it manually for
  `Login`/`Logout`/`GetMe`.
- **No push channel:** the web client polls. Lobby ~4s; `GET /games/state`
  ~4s during play. That endpoint also advances bot turns and enforces
  timers, so the client must keep polling it.
- **Card art:** 141 `.webp` files in `web-static/img/cards/MSW/`, named
  `<catalog_id>-<slug>.webp`. Unity can't decode WebP out of the box, so they
  need converting.
- **Breadth:** traditional (2-4 players), duel, six draft variants, sealed,
  open/closed team play, tournaments, puzzles, etc. `web-static/js/game.js`
  is ~12.9k lines. The plan goes smallest to largest.
- **Server-driven prompts:** the server sends `pending_decision` and per-card
  `choice_fields`, so the client needs one generic decision UI, not a screen
  per card effect.

## Phases

Each phase ends with something runnable against the dev server
(`moodswings-dev.jceddy.com`).

### Phase 0 -- Foundations (complete)
- Add Newtonsoft; move `ApiClient` off `JsonUtility`.
- `ApiClient` returns typed results, handles the `{status}` envelope and
  `503` maintenance responses.
- Environment switching (dev/prod), scene/screen router, uGUI theme and
  `CanvasScaler` setup, EditMode test setup.
- Capture real JSON fixtures from the dev server (`/me`, `/games/state` for
  2/3/4 players, `/cards/catalog`, `/open-games`, ...).
- Card art pipeline: convert WebP to PNG/ASTC, bundle, load by catalog id.
- **Done when:** fixtures deserialize in tests and art loads in a sample scene.
- **Status:** done. Verified headless: 18 EditMode tests (incl. models checked
  against real captured responses in `Assets/Tests/Fixtures/`, with 2-, 3- and
  4-player game states) and a PlayMode test that loads
  `Assets/Scenes/CardArtSample.unity` and checks the art renders.
- **Noted for later:** `web-static/img/cards/MSW/{futuristic,neon,steampunk}/`
  hold themed card skins; the converter skips them for now.

### Phase 1 -- Splash + login (complete)
- Splash, version/health check, maintenance screen, login, session restore
  (saved cookie, then `/me`), logout.
- Register / forgot-password / verify-email open the web pages in the system
  browser.
- Decisions: session stored via DPAPI (Windows) / Keystore (Android), failing
  closed; login handles the unverified-email 403 with an inline resend form.
- **Done when:** cold start, log in, relaunch, still logged in.
- **Status:** built and verified headless against a scripted transport
  (EditMode + PlayMode with screenshots, and live unauthenticated checks
  against the dev server). Android: the APK builds, installs and runs on an
  API 36 x86_64 emulator under Unity 6000.2.1f1 (landscape, login screen, live
  server version), and the 6 Keystore tests pass on it. Unity 6.6 dropped
  x86_64 Android, so further Android checks need a physical ARM64 device.
  A real login against the dev server has since been verified by hand.
  **Not yet verified:** anything on a physical Android device.

### Phase 2 -- Main menu shell (complete)
- Home screen, user info, friends list and invites, preferences
  (`/user/*-preference` routes).
- **Done when:** friends and preferences work end to end.
- **Status:** main menu, Friends (add, accept/decline, remove, presence,
  15-second refresh) and Settings (seven toggles + board layout, saving on
  change with rollback) are built and verified against a scripted server:
  93 EditMode tests, and PlayMode tests that press the real buttons and check
  the requests sent, with screenshots reviewed. Friends and preference saving
  have since been verified by hand against the dev server.
- **Not included:** push-notification settings (browser-only today; part of the
  push decision) and the web's card-size slider (not meaningful yet).

### Phase 3 -- Lobby (in progress)
- Open games (list/post/join/leave/cancel), create a game against bots and
  friends (`/games/bots`, `POST /games`), your active and past games, rematch.
- **Scope:** Traditional format with the ready-made deck types (Structure,
  Power, jceddy's 75, One of Each). Other formats, drafts and custom decklists
  come with Phases 7 and 8 (the deck builder), and a game's board is Phase 4 --
  so a game row can't be opened yet. Rematch is offered only for games this
  can express.
- **Done when:** you can create or join a game and see it in your games list,
  waiting on you or a bot.
- **Status:** Play (your games, with rematch), New game (bots, friends, open
  lobby, four decks) and Open games (join / take down / leave, 8-second
  refresh) are built and verified against a scripted server: 144 EditMode tests
  (against the real captured games, past games and bots) and PlayMode tests that
  press the real buttons and check the requests sent, with screenshots reviewed.
  **Not yet verified:** the real dev server -- creating a game against bots, and
  the open lobby (which needs a second account to join your posted game).

### Phase 4 -- Read-only game board (done)
- Render `GET /games/state` for 2-4 seats: hand, board, discard, scores,
  round/turn, log, chat.
- Build against spectate/replay first -- no input needed.
- **Done when:** a spectated 4-player game renders correctly (incl. Hurt
  Feelings).
- **Status:** the board (seats, moods, piles, hand, close-up, log, chat),
  opening your own games, and spectating (friends' games or a code) are built and
  verified against the real captured 2-, 3- and 4-player states: 209 EditMode
  tests, and PlayMode tests that check actual on-screen positions (which seat is
  left of which, Hurt Feelings on exactly one seat, polling, a dropped
  connection), with screenshots reviewed. Hurt Feelings is checked against an
  edited capture, since none of the captures has a holder. **Not included:**
  replay (stepping through a finished game's recorded moves), which the plan
  mentioned alongside spectating; it needs its own controls and can follow the
  playable board. **Not yet verified:** the real dev server, and a real
  spectated game.

### Phase 5 -- Playable core (in progress)
- Ready/start, play, pass, advance-turn, resign, generic `pending_decision`
  UI, turn/decision timers.
- Traditional format vs bots first, then vs humans.
- **Done when:** a full 2-4 player traditional game is playable.
- **Status:** built and verified against a scripted server and the real captured
  states: a play form for every card (driven by the server's `choice_fields`, with
  Creativity's changing fields, nested fields and reordering), the pending-decision
  form, Pass / Advance turn / I'm ready / Resign, chat, auto-start, the synchronous
  action clock, the repeated-board warning, and round/game-end notices. EditMode
  covers the choice rules (options, counts, constraints, what's sent, the "did you
  mean that?" questions); PlayMode presses the real buttons and checks the requests
  sent and what's on screen afterwards, with screenshots reviewed.
  **Not included:** drafts, duels, team play and best-of-three (Phases 7-8; the
  board says so and turns the actions off), the chaos-draft loop shortcut (Phase 8),
  and a display of the asynchronous turn-timeout warning. **Not yet verified:** a
  whole game against the real dev server (every card's form against real play),
  and a physical Android device.

### Phase 6 -- Arena feel (in progress)
- Drag-to-play, card zoom/inspect, turn and scoring animations, audio,
  haptics, landscape layouts, four-seat table layout, "pause before own turn"
  and "auto-pass" prefs.
- **Done when:** playtest pass on desktop and phone.
- **Status:** built and verified headless: drag-to-play (mouse and touch; tested with
  both pointer events and a virtual mouse through the Input System), a hover
  preview on desktop, card-slide and round-result animation, cues for your turn /
  questions / plays / round and game results, placeholder sounds synthesized in code
  with device switches, Android vibration, safe-area fitting for notches, and a layout
  check at 16:9, 20:9, 16:10 and 4:3. The click close-up, four-seat layout and the
  "pause before your turn" / "auto-pass" preferences came earlier (Phases 2-5).
  **Not included:** real sound files (placeholders until there are some), and
  scoring animations beyond the announcement. **Not yet verified:** the actual feel
  -- a playtest on desktop and a physical phone (vibration, notch, touch dragging).

### Phase 7 -- More play modes (in progress)
- Duel (separate decks), open/closed team play, best-of-three, synchronous
  mode (ready check + clocks), puzzles.
- **Status:** built and verified headless, in four steps: (a) New Game offers Duel, and a
  synchronous option (hidden while the server's feature switch is off, as on the dev server);
  the deck pile is right for separate decks and for spectators; (b) best-of-three matches:
  the option, the match score, the previous loser's first-player choice, Next game, and the
  match result; (c) Open and Closed Team Play: creation with partner choice, team scores and
  tags, the team propose/confirm decisions, the partner's hand, team chat, and Closed Team's
  card pass; (d) the puzzle collection and a puzzle's board (goal, hint, solved banner).
  **Not included:** drafts, sealed and custom-decklist duels (Phase 8), power-duel
  sideboarding, tournaments, and the asynchronous turn-timeout options. **Not yet verified:**
  any of it against the real dev server -- no team, match or puzzle game was captured (the
  team and match states are the four-player and two-player captures edited, the puzzle list
  is written from the server's documented shape), and synchronous play can't be tried while
  the server has it off.

### Phase 8 -- Draft, sealed, deck builder
- Quick, Winston, Grid, Rotisserie, Tiered Rotisserie, Chaos drafts; sealed
  deck and daily/weekly sealed pool; custom deck builder.

### Phase 9 -- Meta features
- Tournaments and pod drafts, stats, achievements, card stats, notifications.

### Phase 10 -- Ship
- Android and Windows builds first; iOS and macOS after.
- Icons, signing, store listings, real mobile push.

## Open decisions

Raise each of these with the user before the phase that depends on it.

1. **Push notifications** (before Phase 10, or earlier if wanted). The
   backend only sends browser Web Push (VAPID). Native needs FCM/APNs, which
   means backend changes -- the one place `php-app/` will need work for this
   client.
2. **Card art licensing** (before any store/public build). The art is WotC's
   Mood Swings content. Fine for the hobby web site; shipping it in app-store
   builds is a separate question.
3. **Four-seat table layout** (before Phase 4). Arena's board assumes two
   players; need a layout for you + up to three others around the table.
