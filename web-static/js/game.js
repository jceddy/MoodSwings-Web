(async function () {
    const user = await getCurrentUser();
    if (!user) {
        window.location.replace('/');
        return;
    }

    document.getElementById('username').textContent = user.username;
    document.getElementById('game-main').hidden = false;

    document.getElementById('logout-button').addEventListener('click', async () => {
        await logout();
        window.location.replace('/');
    });

    const friendsDialog = document.getElementById('friends-dialog');
    const friendInviteForm = document.getElementById('friend-invite-form');
    const friendInviteInput = document.getElementById('friend-invite-input');
    const friendInviteError = document.getElementById('friend-invite-error');
    const friendInviteSuccess = document.getElementById('friend-invite-success');

    function renderList(listEl, emptyEl, items, buildItem) {
        listEl.innerHTML = '';
        emptyEl.hidden = items.length > 0;
        for (const item of items) {
            listEl.appendChild(buildItem(item));
        }
    }

    function actionButton(label, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.addEventListener('click', onClick);
        return button;
    }

    async function refreshFriendsData() {
        const [friendsResp, invitesResp] = await Promise.all([listFriends(), listFriendInvites()]);
        const friends = friendsResp.ok ? friendsResp.body.friends : [];
        const incoming = invitesResp.ok ? invitesResp.body.incoming : [];
        const outgoing = invitesResp.ok ? invitesResp.body.outgoing : [];

        renderList(
            document.getElementById('friends-list'),
            document.getElementById('friends-empty'),
            friends,
            (friend) => {
                const li = document.createElement('li');
                li.append(friend.friend_username + ' ');
                li.appendChild(actionButton('Remove', async () => {
                    await removeFriend(friend.friend_id);
                    await refreshFriendsData();
                }));
                return li;
            }
        );

        renderList(
            document.getElementById('incoming-invites-list'),
            document.getElementById('incoming-invites-empty'),
            incoming,
            (invite) => {
                const li = document.createElement('li');
                li.append(invite.other_username + ' ');
                for (const [action, label] of [['accept', 'Accept'], ['decline', 'Decline'], ['block', 'Block']]) {
                    li.appendChild(actionButton(label, async () => {
                        await respondToFriendInvite(invite.other_user_id, action);
                        await refreshFriendsData();
                    }));
                }
                return li;
            }
        );

        renderList(
            document.getElementById('outgoing-invites-list'),
            document.getElementById('outgoing-invites-empty'),
            outgoing,
            (invite) => {
                const li = document.createElement('li');
                li.textContent = invite.other_username + ' (pending)';
                return li;
            }
        );
    }

    // -- Game lobby + board ----------------------------------------------
    //
    // Every card's `choices` payload has a card-specific shape (a target
    // player id, a discard, a mode string, an array of revealed cards --
    // see php-app/src/Rules/PlayerChoices.php). Rather than a bespoke form
    // per card, this sends a generic superset of the common choice keys
    // seen across the rules engine's tests, aliasing each concept to both
    // key names cards use for it (target_player_id/opponent_player_id,
    // discard_card_id/discard_mood_id). PlayerChoices ignores whatever keys
    // a given card doesn't ask for, so only the fields the player actually
    // fills in are sent. If a card needs something this form doesn't cover,
    // the rules engine's InvalidChoiceException message (surfaced directly
    // in board-error) explains what's missing.

    const lobbyView = document.getElementById('lobby-view');
    const boardView = document.getElementById('board-view');
    const boardError = document.getElementById('board-error');
    const boardMessage = document.getElementById('board-message');
    const choicesPanel = document.getElementById('choices-panel');

    let currentGameId = null;
    let currentState = null;
    let pollTimer = null;

    function showLobby() {
        currentGameId = null;
        currentState = null;
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        boardView.hidden = true;
        lobbyView.hidden = false;
        refreshLobby();
    }

    function showBoard(gameId) {
        currentGameId = gameId;
        lobbyView.hidden = true;
        boardView.hidden = false;
        refreshBoard();
        if (pollTimer) {
            clearInterval(pollTimer);
        }
        pollTimer = setInterval(() => {
            if (choicesPanel.hidden) {
                refreshBoard();
            }
        }, 4000);
    }

    async function refreshLobby() {
        const { ok, body } = await listGames();
        const gamesList = document.getElementById('games-list');

        renderList(gamesList, document.getElementById('games-empty'), ok ? body.games : [], (game) => {
            const li = document.createElement('li');
            const opponents = game.players.map((p) => p.username).join(', ');
            li.append(opponents + ' — ' + game.status + (game.is_your_turn ? ' (your turn)' : ''));
            li.appendChild(actionButton('Open', () => showBoard(game.id)));
            return li;
        });
    }

    document.getElementById('back-to-lobby-button').addEventListener('click', showLobby);

    // -- New game dialog ---------------------------------------------------

    const newGameDialog = document.getElementById('new-game-dialog');
    const newGameForm = document.getElementById('new-game-form');
    const newGameError = document.getElementById('new-game-error');
    const opponentCheckboxes = document.getElementById('opponent-checkboxes');

    document.getElementById('new-game-button').addEventListener('click', async () => {
        newGameError.hidden = true;
        newGameForm.reset();

        const { ok, body } = await listFriends();
        const friends = ok ? body.friends : [];

        opponentCheckboxes.innerHTML = '';
        document.getElementById('opponent-checkboxes-empty').hidden = friends.length > 0;

        for (const friend of friends) {
            const label = document.createElement('label');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = friend.friend_id;
            checkbox.addEventListener('change', () => {
                const checked = opponentCheckboxes.querySelectorAll('input:checked');
                for (const box of opponentCheckboxes.querySelectorAll('input')) {
                    box.disabled = checked.length >= 3 && !box.checked;
                }
            });
            label.appendChild(checkbox);
            label.append(' ' + friend.friend_username);
            opponentCheckboxes.appendChild(label);
        }

        newGameDialog.showModal();
    });

    document.getElementById('new-game-close-button').addEventListener('click', () => {
        newGameDialog.close();
    });

    newGameForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        newGameError.hidden = true;

        const opponentUserIds = Array.from(opponentCheckboxes.querySelectorAll('input:checked'))
            .map((box) => Number(box.value));

        if (opponentUserIds.length === 0) {
            newGameError.textContent = 'Choose at least one opponent.';
            newGameError.hidden = false;
            return;
        }

        const format = document.getElementById('new-game-format').value;
        const { ok, body } = await createGame(opponentUserIds, format);

        if (!ok) {
            newGameError.textContent = body.message || 'Could not create the game.';
            newGameError.hidden = false;
            return;
        }

        newGameDialog.close();
        showBoard(body.game_id);
    });

    // -- Board ---------------------------------------------------------

    function cardLabel(card) {
        return card.name + ' (' + card.color + ', ' + card.value + ')';
    }

    async function refreshBoard() {
        const { ok, body } = await getGameState(currentGameId);
        if (!ok) {
            boardError.textContent = body.message || 'Could not load this game.';
            boardError.hidden = false;
            return;
        }
        boardError.hidden = true;
        currentState = body;
        renderBoard(body);
    }

    function renderBoard(state) {
        document.getElementById('board-title').textContent =
            'Game #' + state.game.id + ' (' + state.game.format + ')';

        const inProgressArea = document.getElementById('in-progress-area');
        const startButton = document.getElementById('start-game-button');

        renderList(
            document.getElementById('players-list'),
            { hidden: true }, // players are never empty
            state.players,
            (player) => {
                const li = document.createElement('li');
                const isTurn = state.round && state.round.current_turn_game_player_id === player.game_player_id;
                li.textContent = player.username + ' — seat ' + player.seat_order +
                    ', ' + player.total_wins + ' win(s), ' + player.hand_count + ' card(s) in hand' +
                    (isTurn ? ' — on turn' : '');
                return li;
            }
        );

        if (state.game.status === 'waiting') {
            document.getElementById('board-round-status').textContent = 'Waiting for the game to start.';
            startButton.hidden = false;
            inProgressArea.hidden = true;
            return;
        }

        startButton.hidden = true;
        inProgressArea.hidden = false;

        if (state.game.status === 'completed') {
            document.getElementById('board-round-status').textContent =
                'Game over — ' + (state.game.winner_username || 'nobody') + ' won.';
        } else {
            document.getElementById('board-round-status').textContent =
                'Round ' + state.round.round_number +
                (state.you.is_your_turn ? ' — your turn' : ' — waiting on another player');
        }

        renderList(
            document.getElementById('in-play-list'),
            document.getElementById('in-play-empty'),
            state.in_play,
            (card) => {
                const owner = state.players.find((p) => p.game_player_id === card.owner_game_player_id);
                const li = document.createElement('li');
                li.textContent = cardLabel(card) + ' — ' + (owner ? owner.username : '?') +
                    (card.is_suppressed ? ' (suppressed)' : '');
                return li;
            }
        );

        document.getElementById('discard-count').textContent = state.discard_pile.length;
        document.getElementById('deck-count').textContent = state.deck_count;
        renderList(document.getElementById('discard-list'), { hidden: true }, state.discard_pile, (card) => {
            const li = document.createElement('li');
            li.textContent = cardLabel(card);
            return li;
        });

        const canAct = state.game.status === 'in_progress' && state.you.is_your_turn;
        renderList(document.getElementById('hand-list'), { hidden: true }, state.you.hand || [], (card) => {
            const li = document.createElement('li');
            li.appendChild(actionButton(cardLabel(card), () => openChoicesPanel(card)));
            li.lastChild.disabled = !canAct;
            return li;
        });

        document.getElementById('pass-button').disabled = !canAct;
    }

    document.getElementById('start-game-button').addEventListener('click', async () => {
        boardError.hidden = true;
        const { ok, body } = await startGame(currentGameId);
        if (!ok) {
            boardError.textContent = body.message || 'Could not start the game.';
            boardError.hidden = false;
            return;
        }
        await refreshBoard();
    });

    document.getElementById('pass-button').addEventListener('click', async () => {
        boardError.hidden = true;
        boardMessage.hidden = true;
        const { ok, body } = await passTurn(currentGameId);
        if (!ok) {
            boardError.textContent = body.message || 'Could not pass.';
            boardError.hidden = false;
            return;
        }
        announceOutcome(body);
        await refreshBoard();
    });

    function announceOutcome(result) {
        if (result.game_completed) {
            boardMessage.textContent = 'Game complete!';
            boardMessage.hidden = false;
        } else if (result.round_scored) {
            boardMessage.textContent = 'Round scored — a new round has begun.';
            boardMessage.hidden = false;
        }
    }

    // -- Choices panel ---------------------------------------------------

    let selectedCard = null;

    function populateSelect(selectEl, items, labelFor, valueFor) {
        selectEl.innerHTML = '<option value="">(none)</option>';
        for (const item of items) {
            const option = document.createElement('option');
            option.value = valueFor(item);
            option.textContent = labelFor(item);
            selectEl.appendChild(option);
        }
    }

    function openChoicesPanel(card) {
        selectedCard = card;
        boardError.hidden = true;
        document.getElementById('choices-card-name').textContent = cardLabel(card);
        document.getElementById('choices-card-rules').textContent = card.rules_text;

        populateSelect(
            document.getElementById('choice-target-player'),
            currentState.players,
            (p) => p.username,
            (p) => p.game_player_id
        );
        populateSelect(
            document.getElementById('choice-target-mood'),
            currentState.in_play,
            (c) => cardLabel(c),
            (c) => c.card_id
        );
        const otherHandCards = currentState.you.hand.filter((c) => c.card_id !== card.card_id);
        populateSelect(
            document.getElementById('choice-discard-card'),
            otherHandCards,
            (c) => cardLabel(c),
            (c) => c.card_id
        );
        const revealSelect = document.getElementById('choice-reveal-cards');
        revealSelect.innerHTML = '';
        for (const c of otherHandCards) {
            const option = document.createElement('option');
            option.value = c.card_id;
            option.textContent = cardLabel(c);
            revealSelect.appendChild(option);
        }
        document.getElementById('choice-mode').value = '';

        choicesPanel.hidden = false;
    }

    document.getElementById('cancel-choice-button').addEventListener('click', () => {
        selectedCard = null;
        choicesPanel.hidden = true;
    });

    document.getElementById('play-card-button').addEventListener('click', async () => {
        boardError.hidden = true;
        boardMessage.hidden = true;

        const choices = {};
        const targetPlayer = document.getElementById('choice-target-player').value;
        if (targetPlayer) {
            choices.target_player_id = Number(targetPlayer);
            choices.opponent_player_id = Number(targetPlayer);
        }
        const targetMood = document.getElementById('choice-target-mood').value;
        if (targetMood) {
            choices.target_mood_id = Number(targetMood);
        }
        const discardCard = document.getElementById('choice-discard-card').value;
        if (discardCard) {
            choices.discard_card_id = Number(discardCard);
            choices.discard_mood_id = Number(discardCard);
        }
        const revealCards = Array.from(document.getElementById('choice-reveal-cards').selectedOptions)
            .map((option) => Number(option.value));
        if (revealCards.length > 0) {
            choices.reveal_card_ids = revealCards;
        }
        const mode = document.getElementById('choice-mode').value.trim();
        if (mode) {
            choices.mode = mode;
        }

        const { ok, body } = await playCard(currentGameId, selectedCard.card_id, choices);

        if (!ok) {
            boardError.textContent = body.message || 'Could not play that card.';
            boardError.hidden = false;
            return;
        }

        selectedCard = null;
        choicesPanel.hidden = true;
        announceOutcome(body);
        await refreshBoard();
    });

    document.getElementById('friends-button').addEventListener('click', async () => {
        friendInviteError.hidden = true;
        friendInviteSuccess.hidden = true;
        friendsDialog.showModal();
        await refreshFriendsData();
    });

    document.getElementById('friends-close-button').addEventListener('click', () => {
        friendsDialog.close();
    });

    friendInviteForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        friendInviteError.hidden = true;
        friendInviteSuccess.hidden = true;

        const { ok, body } = await sendFriendInvite(friendInviteInput.value);

        if (ok) {
            friendInviteInput.value = '';
            friendInviteSuccess.textContent = 'Friend request sent to ' + body.user.username + '.';
            friendInviteSuccess.hidden = false;
            await refreshFriendsData();
            return;
        }

        friendInviteError.textContent = body.message || 'Could not send friend request.';
        friendInviteError.hidden = false;
    });

    showLobby();
})();
