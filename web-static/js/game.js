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
    const cardDetailDialog = document.getElementById('card-detail-dialog');

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

    // Lets a player double-check a card they don't recognize -- whether
    // it's their own or an opponent's -- without it affecting play, since
    // in-play/discard-pile cards can't be acted on anyway.
    function openCardDetail(card, ownerLabel) {
        document.getElementById('card-detail-name').textContent = card.name;

        let meta = card.color + ', base value ' + card.base_value;
        if (card.alt_value !== null && card.alt_value !== undefined) {
            meta += ' (alt value: ' + card.alt_value + ')';
        }
        if (card.value !== card.base_value) {
            meta += ', current value ' + card.value;
        }
        if (ownerLabel) {
            meta += ' — ' + ownerLabel;
        }
        if (card.is_suppressed) {
            meta += ' (suppressed)';
        }
        document.getElementById('card-detail-meta').textContent = meta;

        document.getElementById('card-detail-rules').textContent = card.rules_text || 'No ability.';
        cardDetailDialog.showModal();
    }

    document.getElementById('card-detail-close-button').addEventListener('click', () => {
        cardDetailDialog.close();
    });

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
                const ownerLabel = owner ? owner.username : '?';
                const li = document.createElement('li');
                li.appendChild(actionButton(
                    cardLabel(card) + ' — ' + ownerLabel + (card.is_suppressed ? ' (suppressed)' : ''),
                    () => openCardDetail(card, ownerLabel)
                ));
                return li;
            }
        );

        document.getElementById('discard-count').textContent = state.discard_pile.length;
        document.getElementById('deck-count').textContent = state.deck_count;
        renderList(document.getElementById('discard-list'), { hidden: true }, state.discard_pile, (card) => {
            const li = document.createElement('li');
            li.appendChild(actionButton(cardLabel(card), () => openCardDetail(card)));
            return li;
        });

        const canAct = state.game.status === 'in_progress' && state.you.is_your_turn;
        renderList(document.getElementById('hand-list'), { hidden: true }, state.you.hand || [], (card) => {
            const li = document.createElement('li');
            li.appendChild(actionButton(cardLabel(card), () => handleHandCardClick(card)));
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

    // -- Choices panel -----------------------------------------------------
    //
    // Each card's choice_fields (from GET /games/state, sourced from
    // CardChoiceSchema on the server -- see php-app/src/Rules/
    // CardChoiceSchema.php) describes exactly which PlayerChoices keys that
    // specific card reads, so the panel only ever asks for what the card
    // being played actually needs, instead of one form covering all ~127
    // cards' possible fields. Cards with no fields at all (roughly half the
    // pool -- pure value formulas, unconditional grants) skip the panel
    // entirely and play immediately on click.

    let selectedCard = null;

    function handleHandCardClick(card) {
        openChoicesPanel(card);
    }

    // field.filter narrows a dropdown to candidates the server will actually
    // accept -- mirroring each effect class's own InvalidChoiceException
    // checks (see php-app/src/Rules/CardChoiceSchema.php's docblock for the
    // exact correspondence). A field with no filter has no such narrowing.

    function moodCountFor(gamePlayerId) {
        return currentState.in_play.filter((c) => c.owner_game_player_id === gamePlayerId).length;
    }

    function matchesCardFilter(card, filter) {
        if (!filter) return true;
        if (filter.colors && !filter.colors.includes(card.color)) return false;
        if (filter.values && !filter.values.includes(card.value)) return false;
        if (filter.min_value !== undefined && card.value < filter.min_value) return false;
        if (filter.max_value !== undefined && card.value > filter.max_value) return false;
        if (filter.parity === 'odd' && card.value % 2 === 0) return false;
        if (filter.parity === 'even' && card.value % 2 !== 0) return false;
        if (filter.has_dice_value && !card.has_dice_value) return false;
        return true;
    }

    function matchesPlayerFilter(player, filter) {
        if (!filter) return true;
        if (filter.min_hand_count !== undefined && player.hand_count < filter.min_hand_count) return false;
        if (filter.min_mood_count !== undefined && moodCountFor(player.game_player_id) < filter.min_mood_count) return false;
        if (filter.more_moods_than_viewer) {
            // The card being played will itself already be in play by the
            // time the server checks this (afterPlaying always runs once
            // this card is in play -- see PrideEffect), so the viewer's
            // live count needs a +1 to match what the server compares
            // against.
            const viewerCountAfterThisPlay = moodCountFor(currentState.you.game_player_id) + 1;
            if (moodCountFor(player.game_player_id) <= viewerCountAfterThisPlay) return false;
        }
        return true;
    }

    function fieldOptions(field, card) {
        switch (field.type) {
            case 'player':
                return currentState.players
                    .filter((p) => field.scope !== 'other' || p.game_player_id !== currentState.you.game_player_id)
                    .filter((p) => matchesPlayerFilter(p, field.filter))
                    .map((p) => ({ value: p.game_player_id, label: p.username }));
            case 'mood':
                return currentState.in_play
                    .filter((c) => c.card_id !== card.card_id)
                    .filter((c) => {
                        if (field.scope === 'own') return c.owner_game_player_id === currentState.you.game_player_id;
                        if (field.scope === 'other') return c.owner_game_player_id !== currentState.you.game_player_id;
                        return true;
                    })
                    .filter((c) => matchesCardFilter(c, field.filter))
                    .map((c) => ({ value: c.card_id, label: cardLabel(c) }));
            case 'hand_card':
                return currentState.you.hand
                    .filter((c) => c.card_id !== card.card_id)
                    .filter((c) => matchesCardFilter(c, field.filter))
                    .map((c) => ({ value: c.card_id, label: cardLabel(c) }));
            case 'discard_card':
                return currentState.discard_pile
                    .filter((c) => matchesCardFilter(c, field.filter))
                    .map((c) => ({ value: c.card_id, label: cardLabel(c) }));
            default:
                return [];
        }
    }

    function capitalize(word) {
        return word.charAt(0).toUpperCase() + word.slice(1);
    }

    function buildFieldWidget(field, card) {
        if (field.type === 'bool') {
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.id = 'choice-field-' + field.key;
            return checkbox;
        }

        if (field.type === 'value') {
            const select = document.createElement('select');
            select.id = 'choice-field-' + field.key;
            select.appendChild(new Option('(none)', ''));
            for (let value = field.min; value <= field.max; value++) {
                select.appendChild(new Option(String(value), String(value)));
            }
            return select;
        }

        const select = document.createElement('select');
        select.id = 'choice-field-' + field.key;
        if (field.multi) {
            select.multiple = true;
        } else {
            select.appendChild(new Option('(none)', ''));
        }

        const options = field.type === 'mode'
            ? field.options.map((value) => ({ value, label: capitalize(value).replace(/_/g, ' ') }))
            : fieldOptions(field, card);
        for (const option of options) {
            select.appendChild(new Option(option.label, option.value));
        }
        return select;
    }

    function fieldHasValue(widget, field) {
        if (field.type === 'bool') return true; // a checkbox always has a value (checked or not)
        if (field.multi) return widget.selectedOptions.length > 0;
        return widget.value !== '';
    }

    // field.count/field.constraint mirror a card's own cross-candidate
    // InvalidChoiceException checks (exact/bounded selection counts, "must
    // share a color or value," "must be the same opponent," "one per
    // player," a combined value ceiling) -- see CardChoiceSchema's docblock
    // for the exact correspondence. Checked client-side purely so the
    // player doesn't have to submit to discover a count/pairing mistake;
    // the server re-validates the same rules regardless.

    function selectedCandidates(field, widget) {
        const ids = Array.from(widget.selectedOptions)
            .filter((option) => option.value !== '')
            .map((option) => Number(option.value));
        const source = field.type === 'mood' ? currentState.in_play
            : field.type === 'hand_card' ? currentState.you.hand
            : field.type === 'discard_card' ? currentState.discard_pile
            : field.type === 'player' ? currentState.players
            : [];
        if (field.type === 'player') {
            return ids.map((id) => source.find((p) => p.game_player_id === id)).filter(Boolean);
        }
        return ids.map((id) => source.find((c) => c.card_id === id)).filter(Boolean);
    }

    function countMessage(count, selectedCount) {
        if (!count) return null;
        if (count.zero_ok && selectedCount === 0) return null;
        if (count.min !== undefined && count.max !== undefined && count.min === count.max && selectedCount !== count.min) {
            return `Choose exactly ${count.min}`;
        }
        if (count.min !== undefined && selectedCount < count.min) {
            return `Choose at least ${count.min}`;
        }
        if (count.max !== undefined && selectedCount > count.max) {
            return `Choose at most ${count.max}`;
        }
        return null;
    }

    function constraintMessage(constraint, candidates) {
        if (!constraint) return null;
        if (constraint.type === 'max_total_value') {
            const total = candidates.reduce((sum, c) => sum + c.value, 0);
            return total > constraint.max ? `The combined value of the chosen moods cannot exceed ${constraint.max}` : null;
        }
        if (candidates.length < 2) return null; // the rest only make sense once 2+ are chosen
        if (constraint.type === 'same_color_or_value') {
            const [a, b] = candidates;
            return a.color !== b.color && a.value !== b.value
                ? 'The two chosen moods must share a color or have the same value' : null;
        }
        if (constraint.type === 'same_owner') {
            const [a, b] = candidates;
            return a.owner_game_player_id !== b.owner_game_player_id ? 'Both moods must belong to the same opponent' : null;
        }
        if (constraint.type === 'distinct_owners') {
            const owners = candidates.map((c) => c.owner_game_player_id);
            return new Set(owners).size !== owners.length ? 'You can only choose one mood per player' : null;
        }
        return null;
    }

    function fieldValidationMessage(field, widget) {
        if (!field.multi) return null;
        const candidates = selectedCandidates(field, widget);
        return countMessage(field.count, candidates.length) || constraintMessage(field.constraint, candidates);
    }

    function updatePlayButtonEnabled() {
        const playButton = document.getElementById('play-card-button');
        const validationMessage = document.getElementById('choices-validation');

        const allRequiredFilled = selectedCard.choice_fields
            .filter((field) => field.required)
            .every((field) => fieldHasValue(document.getElementById('choice-field-' + field.key), field));

        let firstError = null;
        for (const field of selectedCard.choice_fields) {
            const widget = document.getElementById('choice-field-' + field.key);
            const message = fieldValidationMessage(field, widget);
            if (message && !firstError) {
                firstError = message;
            }
        }

        validationMessage.textContent = firstError || '';
        validationMessage.hidden = !firstError;
        playButton.disabled = !allRequiredFilled || !!firstError;
    }

    function openChoicesPanel(card) {
        selectedCard = card;
        boardError.hidden = true;
        document.getElementById('choices-card-name').textContent = cardLabel(card);
        document.getElementById('choices-card-rules').textContent = card.rules_text;

        const fieldsContainer = document.getElementById('choices-fields');
        fieldsContainer.innerHTML = '';
        for (const field of card.choice_fields) {
            const label = document.createElement('label');
            label.className = 'choice-field';
            label.append(field.label + (field.required ? ' (required)' : '') + ' ');
            const widget = buildFieldWidget(field, card);
            widget.addEventListener('change', updatePlayButtonEnabled);
            label.appendChild(widget);
            fieldsContainer.appendChild(label);
        }

        updatePlayButtonEnabled();
        choicesPanel.hidden = false;
    }

    document.getElementById('cancel-choice-button').addEventListener('click', () => {
        selectedCard = null;
        choicesPanel.hidden = true;
    });

    async function submitPlay(card, choices) {
        boardError.hidden = true;
        boardMessage.hidden = true;

        const { ok, body } = await playCard(currentGameId, card.card_id, choices);

        if (!ok) {
            boardError.textContent = body.message || 'Could not play that card.';
            boardError.hidden = false;
            return;
        }

        selectedCard = null;
        choicesPanel.hidden = true;
        announceOutcome(body);
        await refreshBoard();
    }

    document.getElementById('play-card-button').addEventListener('click', async () => {
        const choices = {};
        for (const field of selectedCard.choice_fields) {
            const widget = document.getElementById('choice-field-' + field.key);
            if (field.type === 'bool') {
                if (widget.checked) choices[field.key] = true;
            } else if (field.multi) {
                const values = Array.from(widget.selectedOptions).map((option) => option.value);
                if (values.length > 0) {
                    choices[field.key] = field.type === 'mode' ? values : values.map(Number);
                }
            } else if (widget.value !== '') {
                choices[field.key] = field.type === 'mode' ? widget.value : Number(widget.value);
            }
        }

        await submitPlay(selectedCard, choices);
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
