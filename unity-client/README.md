# MoodSwings Unity client

A native mobile/desktop client for the same MoodSwings backend
`php-app/` and `web-static/` already serve to the browser. This
directory is scaffolding, not a working project yet -- it was created
outside the Unity Editor (this session has no GUI/display and can't run
the Editor), so it's deliberately minimal about anything Unity itself
needs to generate.

## What's here vs. what Unity generates

Committed:

- `Assets/` -- empty script/scene/prefab folders to start from
  (`Scripts/Core`, `Scripts/Networking`, `Scripts/UI`, `Scenes`,
  `Prefabs`, `Editor`), plus one real script:
  `Assets/Scripts/Networking/ApiClient.cs`, a starting point for talking
  to the existing JSON API (see "Talking to the backend" below).
- `Packages/manifest.json` -- a reasonable baseline package/module list
  for a blank project. Unity's Package Manager will resolve/update exact
  versions and add any additional built-in modules it needs the first
  time it opens this project -- don't worry about this file being
  slightly stale.
- `ProjectSettings/ProjectVersion.txt` -- pins an Editor version so
  Unity Hub knows what to open this with. **The exact version in this
  file is a placeholder** (I can't verify what's current or what you
  have installed from here) -- either install a matching Editor via
  Unity Hub, or just open the project with whatever LTS you already
  have and let Unity Hub prompt you to upgrade this file. Nothing else
  here depends on the exact version.
- `.gitignore` -- Unity's own standard template
  (`Library/`, `Temp/`, `Obj/`, `Build/`, `Logs/`, `UserSettings/`, ...),
  scoped to this directory.

**Deliberately NOT committed yet:** the rest of `ProjectSettings/`
(`ProjectSettings.asset`, `TagManager.asset`, `EditorBuildSettings.asset`,
etc. -- the tag/layer/physics/quality/input settings a real Unity
project normally tracks in git). These are hand-editable YAML in
principle, but they're really meant to be generated and maintained by
the Editor itself; hand-authoring a full, internally-consistent set from
outside the Editor risks producing a project that fails to open or
behaves subtly wrong in ways I have no way to test here. Let Unity
generate them for real (see below), then commit what it creates.

## First-time setup

1. Install Unity Hub and an Editor version (2022 LTS or newer is a safe
   default; match `ProjectSettings/ProjectVersion.txt` above, or just
   update that file to whatever you install).
2. In Unity Hub, **Open** (not "New Project") and point it at this
   `unity-client/` folder directly. Unity will recognize the existing
   `Assets/`/`Packages/manifest.json`/`ProjectSettings/ProjectVersion.txt`
   and fill in every other `ProjectSettings/*.asset` file with its own
   defaults on first load.
3. Once it opens cleanly, `git add`/commit the newly-generated
   `ProjectSettings/` files (everything except what `.gitignore` already
   excludes) -- that's now the real, Editor-verified project state.
4. From there, treat this like an ordinary git-tracked Unity project:
   commit `Assets/`, `Packages/`, and `ProjectSettings/`; never commit
   `Library/` or the other Editor-generated caches.

## Talking to the backend

`Assets/Scripts/Networking/ApiClient.cs` wraps the same JSON HTTP API
`web-static/js/app.js` already calls (see `php-app/public/index.php`
for the full route list and `php-app/README.md` for what each one
expects/returns). The one thing worth understanding up front: auth is a
server-issued **cookie** (`session_token`), not a bearer token --
`UnityWebRequest` doesn't persist cookies across requests the way a
browser does, so `ApiClient` captures the `Set-Cookie` response header
on login and replays it as a plain `Cookie` request header on every
later call. Only `Login`/`Logout`/`GetMe` are wired up so far; extend it
with the same pattern for whatever routes the game actually needs.

## Mobile + desktop from one project

Nothing here is platform-specific yet -- that's mostly a Build Settings
and input-handling concern once there's an actual scene/UI to build:
Unity's Input System can define separate control schemes (touch vs.
mouse/keyboard/controller) against the same game logic, and a
`CanvasScaler` set to "Scale With Screen Size" handles the aspect-ratio
spread across phones/tablets/desktop monitors. The one hard platform
constraint: an iOS build has to go through Xcode on an actual Mac --
that step can't be done from this environment, or from Windows/Linux at
all.

## Versioning

This client is **not** part of `VERSION` at the repo root -- that file
tracks `php-app`/`database`/`web-static` deploying together as one
site (see the root `README.md`'s "Versioning" section). This client
consumes that backend as an API client and can version/release on its
own schedule; it doesn't need a schema-version-bump migration the way a
backend change does.
