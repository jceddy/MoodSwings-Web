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

function getCatalog() {
    return apiRequest('/catalog');
}

function createGame(opponentUserIds, format, winsNeeded) {
    return apiRequest('/games', {
        method: 'POST',
        body: JSON.stringify({ opponent_user_ids: opponentUserIds, format, wins_needed: winsNeeded }),
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
