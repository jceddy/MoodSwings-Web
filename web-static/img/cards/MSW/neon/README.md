# Neon card skin (issue #363)

Drop themed art here as it's produced -- same naming convention as the
base set (`../<cards.id>-<slugified-name>.webp`, e.g. `1-altruism.webp`
for Altruism), just nested one folder deeper under this theme's own name.

No code changes needed to pick up a new file: `cardArtUrl()`/`setCardArt()`
in `web-static/js/game.js` already point here whenever a player has
"Neon" selected as their card skin, and fall back to the base set's own
art automatically for any card that doesn't have a file here yet (a
themed request 404s, so the `<img>`'s own `onerror` swaps it back to
normal). Coverage can grow one card at a time -- there's no manifest to
update, and nothing to test besides the file actually rendering.
