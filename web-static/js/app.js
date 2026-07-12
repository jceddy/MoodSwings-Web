// Shared client-side API helpers for MoodSwings-Web. The PHP app is deployed
// under /app (see php-app/public/.htaccess), so all requests are same-origin
// relative to that prefix regardless of which domain this is served from.
const API_BASE = '/app';

async function apiRequest(path, options = {}) {
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

function createGame(opponentUserIds, format, winsNeeded, deckType, decklistText, duelDeckRules) {
    return apiRequest('/games', {
        method: 'POST',
        body: JSON.stringify({
            opponent_user_ids: opponentUserIds,
            format,
            wins_needed: winsNeeded,
            deck_type: deckType,
            decklist_text: decklistText,
            duel_deck_rules: duelDeckRules,
        }),
    });
}

function submitCustomDuelDeck(gameId, decklistText) {
    return apiRequest('/games/decklist', {
        method: 'POST',
        body: JSON.stringify({ game_id: gameId, decklist_text: decklistText }),
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

// Every page's own #app-version footer element (see the "Versioning"
// section of the top-level README) is populated here rather than per-page,
// since app.js is the one script every page already loads. VERSION is a
// plain static file at the site root (deployed alongside index.html, not
// under API_BASE), fetched with cache: 'no-store' so a page loaded shortly
// after a deploy can't keep showing a browser-cached pre-deploy version.
(function renderAppVersion() {
    const el = document.getElementById('app-version');
    if (!el) {
        return;
    }

    fetch('/VERSION', { cache: 'no-store' })
        .then((response) => (response.ok ? response.text() : Promise.reject()))
        .then((version) => { el.textContent = 'v' + version.trim(); })
        .catch(() => {}); // leave the element empty rather than showing a broken/stale value
})();
