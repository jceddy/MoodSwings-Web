(async function () {
    const user = await getCurrentUser();
    if (!user) {
        window.location.replace('/');
        return;
    }

    document.getElementById('spectate-main').hidden = false;
    startVersionWatcher();

    document.getElementById('spectate-back-to-lobby-button').addEventListener('click', () => {
        window.location.href = '../game/';
    });

    // Spectator mode (issue #128). A friend's game (below) needs no code
    // -- GET /games/spectate/state authorizes by friendship on its own --
    // so this only ever navigates with ?spectate_game_id=. A code-entry
    // game (this form) additionally carries &spectate_code=, since that's
    // the only thing authorizing a stranger to view it; game.js re-sends
    // that same code on every poll, not just this first navigation.
    const codeForm = document.getElementById('spectate-code-form');
    const codeInput = document.getElementById('spectate-code-input');
    const codeError = document.getElementById('spectate-code-error');

    codeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        codeError.hidden = true;

        const code = codeInput.value.trim();
        if (!code) {
            return;
        }

        const { ok, body } = await resolveSpectateCode(code);
        if (!ok) {
            codeError.textContent = body.message || 'That spectate code was not found.';
            codeError.hidden = false;
            return;
        }

        window.location.href = '../game/?spectate_game_id=' + encodeURIComponent(body.game_id)
            + '&spectate_code=' + encodeURIComponent(code);
    });

    const friendsGamesList = document.getElementById('spectate-friends-games-list');
    const friendsGamesEmpty = document.getElementById('spectate-friends-games-empty');

    function buildFriendGameRow(game) {
        const li = document.createElement('li');
        li.className = 'lobby-row';

        const infoEl = document.createElement('div');
        infoEl.className = 'lobby-info';

        const formatEl = document.createElement('div');
        formatEl.className = 'lobby-format';
        const deckDescription = game.deck_type === 'custom'
            ? (game.custom_deck_name || 'Uploaded Deck')
            : deckTypeLabel(game.deck_type) + ' deck';
        formatEl.textContent = formatLabel(game.format) + ', ' + deckDescription;
        infoEl.appendChild(formatEl);

        const playersEl = document.createElement('div');
        playersEl.textContent = game.players.map((player) => player.username).join(', ');
        infoEl.appendChild(playersEl);

        li.appendChild(infoEl);

        const actionsEl = document.createElement('div');
        actionsEl.className = 'lobby-actions';
        actionsEl.appendChild(iconActionButton('view', 'Spectate', () => {
            window.location.href = '../game/?spectate_game_id=' + encodeURIComponent(game.id);
        }));
        li.appendChild(actionsEl);

        return li;
    }

    const { ok, body } = await getSpectatableFriendsGames();
    if (ok) {
        renderList(friendsGamesList, friendsGamesEmpty, body.games, buildFriendGameRow);
    }
})();
