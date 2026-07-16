// Shared client-side API helpers for MoodSwings-Web. The PHP app is deployed
// under /app (see php-app/public/.htaccess), so all requests are same-origin
// relative to that prefix regardless of which domain this is served from.
const API_BASE = '/app';

// Set once the app has redirected to /maintenance.html so that any request
// still in flight from a setInterval poll (e.g. game.js's board polling)
// doesn't keep hitting the server or re-writing sessionStorage during the
// brief window before window.location.href actually navigates away --
// that assignment doesn't stop script execution synchronously.
let redirectingToMaintenance = false;

async function apiRequest(path, options = {}) {
    if (redirectingToMaintenance) {
        return new Promise(() => {}); // never resolves; a navigation is already in flight
    }

    try {
        const response = await fetch(API_BASE + path, {
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            ...options,
        });

        let body = {};
        try {
            body = await response.json();
        } catch (e) {
            // Non-JSON response body; leave body empty.
        }

        if (response.status === 503 && body.status === 'maintenance') {
            redirectingToMaintenance = true;
            try {
                sessionStorage.setItem('maintenanceMessage', body.message || '');
                sessionStorage.setItem('maintenanceReturnTo', window.location.pathname);
            } catch (e) {
                // sessionStorage unavailable (e.g. private browsing) --
                // maintenance.js falls back to a hardcoded message/path.
            }
            window.location.href = '/maintenance.html';
            return new Promise(() => {}); // never resolves -- avoids the caller's post-await code running mid-navigation
        }

        return { ok: response.ok, status: response.status, body };
    } catch (networkError) {
        return { ok: false, status: 0, body: { message: 'Network error. Please try again.' } };
    }
}

function getCurrentUser() {
    return apiRequest('/me').then(({ ok, body }) => (ok ? body.user : null));
}

function login(username, password) {
    return apiRequest('/login', { method: 'POST', body: JSON.stringify({ username, password }) });
}

function register(username, email, password, phoneNumber) {
    return apiRequest('/register', {
        method: 'POST',
        body: JSON.stringify({ username, email, password, phone_number: phoneNumber || null }),
    });
}

function logout() {
    return apiRequest('/logout', { method: 'POST' });
}

function listFriends() {
    return apiRequest('/friends');
}

function listFriendInvites() {
    return apiRequest('/friends/invites');
}

function sendFriendInvite(usernameOrEmail) {
    return apiRequest('/friends/invite', {
        method: 'POST',
        body: JSON.stringify({ username_or_email: usernameOrEmail }),
    });
}

function respondToFriendInvite(userId, action) {
    return apiRequest('/friends/respond', {
        method: 'POST',
        body: JSON.stringify({ user_id: userId, action }),
    });
}

function removeFriend(userId) {
    return apiRequest('/friends/remove', {
        method: 'POST',
        body: JSON.stringify({ user_id: userId }),
    });
}

function listGames() {
    return apiRequest('/games');
}

function createGame(opponentUserIds, format, winsNeeded, deckType, decklistText, duelDeckRules, partnerUserId, quickDraftPoolSource, quickDraftCustomPoolText, winstonDraftPoolSource, winstonDraftCustomPoolText) {
    return apiRequest('/games', {
        method: 'POST',
        body: JSON.stringify({
            opponent_user_ids: opponentUserIds,
            format,
            wins_needed: winsNeeded,
            deck_type: deckType,
            decklist_text: decklistText,
            duel_deck_rules: duelDeckRules,
            // Only meaningful for format 'team'/'closed_team' -- see "Open
            // Team Play"/"Closed Team Play" in web-static/README.md.
            partner_user_id: partnerUserId,
            // Only meaningful for deck_type 'quick_draft' -- see "Quick
            // Draft" in web-static/README.md.
            quick_draft_pool_source: quickDraftPoolSource,
            quick_draft_custom_pool_text: quickDraftCustomPoolText,
            // Only meaningful for deck_type 'winston_draft' -- see
            // "Winston Draft" in web-static/README.md.
            winston_draft_pool_source: winstonDraftPoolSource,
            winston_draft_custom_pool_text: winstonDraftCustomPoolText,
        }),
    });
}

// Open Team Play's own turn_order/draw_recipient decisions -- see
// "Open Team Play" in web-static/README.md. Either teammate proposes;
// the OTHER teammate then confirms (approve) or rejects via the same
// endpoint, distinguished by `action`.
function proposeTeamDecision(gameId, proposedGamePlayerId) {
    return apiRequest('/games/team-decision', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, action: 'propose', proposed_game_player_id: proposedGamePlayerId }),
    });
}

function confirmTeamDecision(gameId, approve) {
    return apiRequest('/games/team-decision', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, action: 'confirm', approve }),
    });
}

// 'closed_team's own pregame mechanic -- see "Closed Team Play" in
// web-static/README.md: pass exactly 2 hand cards to your teammate,
// face down, before round 1 can begin.
function submitInitialCardPass(gameId, cardIds) {
    return apiRequest('/games/initial-pass', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, card_ids: cardIds }),
    });
}

function submitCustomDuelDeck(gameId, decklistText) {
    return apiRequest('/games/decklist', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, decklist_text: decklistText }),
    });
}

// Quick Draft (issue #88) -- see "Quick Draft" in web-static/README.md.
// stage is 'draw' (keep 2 of your own just-dealt 6) or 'received' (keep 2
// of the 4 cards you received from your opponent).
function submitQuickDraftPick(gameId, round, stage, cardIds) {
    return apiRequest('/games/draft/pick', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, round, stage, card_ids: cardIds }),
    });
}

// Used both for the initial trim and every later sideboard between the
// match's up-to-3 games -- same endpoint, same shape, shared by both
// quick_draft and winston_draft.
function submitDraftDeck(gameId, cardIds) {
    return apiRequest('/games/draft/deck', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, card_ids: cardIds }),
    });
}

// Winston Draft (issue #89) -- see "Winston Draft" in web-static/README.md.
// action is 'take' (claim the whole current pile) or 'pass' (move on to
// the next pile, or the mandatory deck draw after declining pile 3).
function submitWinstonDraftPick(gameId, action) {
    return apiRequest('/games/draft/winston-pick', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, action }),
    });
}

function getGameState(gameId) {
    return apiRequest('/games/state?game_id=' + encodeURIComponent(gameId));
}

function startGame(gameId) {
    return apiRequest('/games/start', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId }),
    });
}

function playCard(gameId, cardId, choices) {
    return apiRequest('/games/play', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, card_id: cardId, choices: choices || {} }),
    });
}

function passTurn(gameId) {
    return apiRequest('/games/pass', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId }),
    });
}

function respondToDecision(gameId, choices) {
    return apiRequest('/games/respond', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, choices: choices || {} }),
    });
}

// VERSION is a plain static file at the site root (deployed alongside
// index.html, not under API_BASE), fetched with cache: 'no-store' so a
// page loaded shortly after a deploy can't keep showing a browser-cached
// pre-deploy value. Shared by the footer indicator below and by
// startVersionWatcher(); resolves to null (rather than rejecting) on any
// failure -- including a response that doesn't actually look like a
// MAJOR.MINOR.PATCH version, e.g. an error page's HTML served with a 200,
// or a truncated/empty body from a mid-deploy read -- so callers don't
// need their own try/catch or shape validation, and never mistake garbage
// for a genuine version change.
function fetchDeployedVersion() {
    return fetch('/VERSION', { cache: 'no-store' })
        .then((response) => (response.ok ? response.text() : Promise.reject()))
        .then((version) => version.trim())
        .then((version) => (/^\d+\.\d+\.\d+$/.test(version) ? version : Promise.reject()))
        .catch(() => null);
}

// Every page's own #app-version footer element (see the "Versioning"
// section of the top-level README) is populated here rather than per-page,
// since app.js is the one script every page already loads.
(function renderAppVersion() {
    const el = document.getElementById('app-version');
    if (!el) {
        return;
    }

    fetchDeployedVersion().then((version) => {
        if (version !== null) {
            el.textContent = 'v' + version;
        }
        // else leave the element empty rather than showing a broken/stale value
    });
})();

// Theme select (System/Light/Dark) in every page's own footer -- see "Dark
// mode" in web-static/README.md. The actual color switch is CSS-only
// (custom properties in style.css, gated by a prefers-color-scheme media
// query plus a documentElement data-theme override); this just keeps the
// <select> in sync with the stored preference and writes a new one back on
// change. The very first paint's data-theme is already set by a duplicated
// inline <script> in each page's own <head> (before this file even loads),
// so an explicit preference never flashes the wrong theme first -- this
// IIFE only needs to reflect that same preference in the dropdown's value.
const THEME_STORAGE_KEY = 'themePreference';

(function initThemeSelect() {
    const select = document.getElementById('theme-select');
    if (!select) {
        return;
    }

    let stored = 'system';
    try {
        stored = localStorage.getItem(THEME_STORAGE_KEY) || 'system';
    } catch (e) {
        // localStorage unavailable (e.g. private browsing) -- leave the
        // dropdown on its default "System" option.
    }
    select.value = stored;

    select.addEventListener('change', () => {
        const preference = select.value;
        if (preference === 'light' || preference === 'dark') {
            document.documentElement.dataset.theme = preference;
        } else {
            delete document.documentElement.dataset.theme;
        }
        try {
            localStorage.setItem(THEME_STORAGE_KEY, preference);
        } catch (e) {
            // ignore -- the selection just won't persist across reloads
        }
    });
})();

// Detects a new deploy landing while a session is already open (e.g. a
// player leaves the game page open across a deploy) and force-reloads so
// the page picks up the new JS/CSS/HTML instead of continuing to run
// whatever was cached at load time -- see "Version watcher" in
// web-static/README.md. Only started by pages with an active session
// (game.js) -- not login.js/register.js, which don't stay open long
// enough for this to matter and redirect away as soon as a session exists
// anyway.
function startVersionWatcher(intervalMs = 60000) {
    let versionAtLoad = null;
    fetchDeployedVersion().then((version) => { versionAtLoad = version; });

    setInterval(async () => {
        // No baseline yet, or a maintenance redirect is already in flight
        // -- either way, nothing to compare against or act on right now.
        if (versionAtLoad === null || redirectingToMaintenance) {
            return;
        }

        const firstCheck = await fetchDeployedVersion();
        if (firstCheck === null || firstCheck === versionAtLoad) {
            return; // unchanged, or the fetch itself failed -- nothing to act on
        }

        // The deploy pipeline uploads files one at a time over FTP, not as
        // one atomic swap (see "Deployment" in the top-level README), so a
        // single differing fetch could just be this poll's bad luck landing
        // mid-deploy rather than a genuinely finished new version -- and
        // reloading into that same half-deployed moment is exactly how a
        // stale/inconsistent value could flash up right after an
        // auto-refresh. Confirm the new value is still there and unchanged
        // a few seconds later before actually reloading; if it isn't
        // (mid-deploy noise), this just waits for the next poll instead of
        // acting on a possibly-transient reading.
        await new Promise((resolve) => setTimeout(resolve, 3000));
        const confirmed = await fetchDeployedVersion();
        if (confirmed !== null && confirmed === firstCheck && confirmed !== versionAtLoad) {
            window.location.reload();
        }
    }, intervalMs);
}
