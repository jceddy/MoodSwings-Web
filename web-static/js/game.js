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
    const pendingDecisionBanner = document.getElementById('pending-decision-banner');
    const pendingDecisionPanel = document.getElementById('pending-decision-panel');
    const cardDetailDialog = document.getElementById('card-detail-dialog');

    let currentGameId = null;
    let currentState = null;
    let pollTimer = null;

    // refreshBoard() can overlap with itself -- the 4-second poll timer
    // doesn't wait for a prior call to finish, and a user action (Start
    // game, Play, Pass, ...) triggers its own refreshBoard() independent
    // of whatever poll might already be in flight. Without this, an older
    // request that happens to resolve *after* a newer one (a slow "still
    // waiting" poll issued just before Start was clicked, resolving after
    // Start's own now-in_progress fetch, say) would silently overwrite the
    // correct render with stale data -- exactly the "wrong until the page
    // is reloaded" shape a genuine race produces. Only the most recently
    // issued call's response is ever actually rendered.
    let boardRequestSeq = 0;

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
        // Picks up a game created by another player (or this same player,
        // from a second tab) without needing a hard reload -- the same
        // 4-second cadence showBoard()'s own poll uses, and mutually
        // exclusive with it, since only one of lobbyView/boardView is ever
        // visible and each view's own show*() clears any prior pollTimer
        // before starting its own.
        pollTimer = setInterval(refreshLobby, 4000);
    }

    function showBoard(gameId) {
        currentGameId = gameId;
        lobbyView.hidden = true;
        boardView.hidden = false;
        // boardMessage ("Game complete!"/"Round scored...") is otherwise
        // only ever hidden right before submitting a play/pass/response --
        // never on a plain board load -- so without this, a message left
        // over from whichever game was open last (e.g. its own
        // "Game complete!") would still be sitting there, visible, the
        // moment a brand-new game's board first renders. boardError
        // doesn't need the same treatment -- refreshBoard() itself
        // already clears it on every successful load.
        boardMessage.hidden = true;
        refreshBoard();
        if (pollTimer) {
            clearInterval(pollTimer);
        }
        pollTimer = setInterval(() => {
            if (choicesPanel.hidden && pendingDecisionPanel.hidden) {
                refreshBoard();
            }
        }, 4000);
    }

    // Turns a raw snake_case status value ('in_progress', 'waiting', ...)
    // into a human-friendly one ('In Progress', 'Waiting') -- generic
    // rather than a fixed lookup table, so any future status value reads
    // reasonably without this needing to be updated too.
    function humanizeStatus(status) {
        return status
            .split('_')
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    }

    // The 'standard' format's own display name is "Traditional" -- the
    // underlying value stays 'standard' (it's the API/DB enum value, used
    // as the <option>'s own value and everywhere on the backend), so this
    // only overrides how it's *labeled* here; every other format falls
    // back to humanizeStatus()'s generic capitalization.
    function formatLabel(format) {
        return format === 'standard' ? 'Traditional' : humanizeStatus(format);
    }

    async function refreshLobby() {
        const { ok, body } = await listGames();
        const gamesList = document.getElementById('games-list');

        renderList(gamesList, document.getElementById('games-empty'), ok ? body.games : [], (game) => {
            const li = document.createElement('li');
            const opponents = game.players.map((p) => p.username).join(', ');
            li.append(opponents + ' — ' + humanizeStatus(game.status) + (game.is_your_turn ? ' (your turn)' : ''));
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

    function playerLabelFor(gamePlayerId) {
        const player = currentState.players.find((p) => p.game_player_id === gamePlayerId);
        return player ? player.username : '?';
    }

    // Lets a player double-check a card they don't recognize -- whether
    // it's their own or an opponent's -- without it affecting play, since
    // in-play/discard-pile cards can't be acted on anyway.
    function openCardDetail(card, ownerLabel) {
        document.getElementById('card-detail-name').textContent = card.name;

        let meta = card.color + ', base value ' + card.base_value;
        // base_color only differs from color while Imagination is in play
        // (or, for a Creativity copy, is simply the copied card's own
        // printed color) -- otherwise identical, so this stays silent the
        // overwhelming majority of the time.
        if (card.base_color && card.base_color !== card.color) {
            meta += ' (printed color: ' + card.base_color + ')';
        }
        if (card.alt_value !== null && card.alt_value !== undefined) {
            meta += ' (alt value: ' + card.alt_value + ')';
        }
        if (card.value !== card.base_value) {
            meta += ', current value ' + card.value;
        }
        if (ownerLabel) {
            meta += ' — ' + ownerLabel;
        }
        document.getElementById('card-detail-meta').textContent = meta;

        const suppressionEl = document.getElementById('card-detail-suppression');
        if (card.is_suppressed) {
            let text = 'Suppressed';
            if (card.suppressed_by_name) {
                text += ' by ' + card.suppressed_by_name;
            }
            if (card.suppression_expiry === 'while_source_in_play') {
                text += ' — lasts as long as that mood stays in play.';
            } else if (card.suppression_expiry === 'end_of_round') {
                text += ' — lasts until the end of this round.';
            }
            suppressionEl.textContent = text;
            suppressionEl.hidden = false;
        } else {
            suppressionEl.hidden = true;
        }

        // temporary_ownership only exists on in-play cards (see
        // GameService::getState()'s in_play mapping's temporary_ownership
        // field) -- reads as undefined, and so stays hidden, for a
        // hand/discard-pile card, same as boosted_by_name/affecting below.
        const ownershipEl = document.getElementById('card-detail-ownership');
        if (card.temporary_ownership) {
            const revertsText = card.temporary_ownership.reverts === 'when_source_leaves_play'
                ? 'when ' + card.temporary_ownership.source_card_name + ' leaves play'
                : 'after this round is scored';
            ownershipEl.textContent = 'Temporarily owned via ' + card.temporary_ownership.source_card_name +
                ' — returns to ' + card.temporary_ownership.original_owner_name + ' ' + revertsText + '.';
            ownershipEl.hidden = false;
        } else {
            ownershipEl.hidden = true;
        }

        // boosted_by_name/affecting only exist on in-play cards (see
        // GameService::getState()'s in_play mapping) -- both read as
        // undefined, and so stay hidden, for a hand/discard-pile card.
        const affectedByEl = document.getElementById('card-detail-affected-by');
        if (card.boosted_by_name) {
            affectedByEl.textContent = 'Affected by ' + card.boosted_by_name + ' (dice value)';
            affectedByEl.hidden = false;
        } else {
            affectedByEl.hidden = true;
        }

        const affectingEl = document.getElementById('card-detail-affecting');
        if (card.affecting && card.affecting.length > 0) {
            const relationshipLabels = { dice_value: 'dice value', suppressed: 'suppressed' };
            affectingEl.textContent = 'Affecting: ' + card.affecting
                .map((entry) => entry.name + ' (' + (relationshipLabels[entry.relationship] || entry.relationship) + ')')
                .join(', ');
            affectingEl.hidden = false;
        } else {
            affectingEl.hidden = true;
        }

        document.getElementById('card-detail-rules').textContent = card.rules_text || 'No ability.';
        cardDetailDialog.showModal();
    }

    document.getElementById('card-detail-close-button').addEventListener('click', () => {
        cardDetailDialog.close();
    });

    async function refreshBoard() {
        const seq = ++boardRequestSeq;
        const { ok, body } = await getGameState(currentGameId);
        if (seq !== boardRequestSeq) {
            return; // a newer refreshBoard() call has since been issued -- this response is stale, ignore it
        }
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
            'Game #' + state.game.id + ' (' + formatLabel(state.game.format) + ')';

        const inProgressArea = document.getElementById('in-progress-area');
        const startButton = document.getElementById('start-game-button');

        renderList(
            document.getElementById('players-list'),
            { hidden: true }, // players are never empty
            state.players,
            (player) => {
                const li = document.createElement('li');
                const isTurn = state.round && state.round.current_turn_game_player_id === player.game_player_id;
                const wentFirst = state.round && state.round.first_game_player_id === player.game_player_id;
                li.textContent = player.username + ' — seat ' + player.seat_order +
                    ', ' + player.total_score + ' point(s), ' + player.total_wins + ' win(s), ' +
                    player.hand_count + ' card(s) in hand' +
                    (wentFirst ? ' — went first this round' : '') +
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

        const pendingDecision = state.round && state.round.pending_decision;
        renderPendingDecision(pendingDecision);
        renderScoringPreview(state.round && state.round.scoring_preview);

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

        // A pending decision freezes the whole round -- nobody, including
        // the player whose turn it nominally is, can play or pass until
        // the targeted player has answered it.
        const canAct = state.game.status === 'in_progress' && state.you.is_your_turn && !pendingDecision;

        document.getElementById('discard-count').textContent = state.discard_pile.length;
        document.getElementById('deck-count').textContent = state.deck_count;
        renderList(document.getElementById('discard-list'), { hidden: true }, state.discard_pile, (card) => {
            const li = document.createElement('li');
            // Almost always just informational (a discard-pile card can't
            // normally be played), but Angst/Harmony/Grief's discard-sourced
            // extra play, or Melancholy's "play from the discard pile as
            // though it were your hand," can make a specific one playable
            // for the rest of this turn -- is_playable already reflects
            // that (see GameService::getState()'s discard_pile mapping), so
            // route straight to the same Play/Cancel panel a hand card
            // uses instead of the read-only detail view in that case.
            if (canAct && card.is_playable) {
                li.appendChild(actionButton(cardLabel(card), () => handleHandCardClick(card)));
            } else {
                li.appendChild(actionButton(cardLabel(card), () => openCardDetail(card)));
            }
            return li;
        });

        // While the viewer is the one being asked to answer a pending
        // decision (e.g. Confusion's "choose a hand card to give away"),
        // the response panel -- not the ordinary choices panel -- owns
        // choosing which card; clicking a hand card here instead opens the
        // same read-only detail view in-play/discard-pile cards already
        // get, so an unfamiliar card can still be checked before answering.
        const respondingToDecision = !!(pendingDecision && pendingDecision.is_you);
        renderList(document.getElementById('hand-list'), { hidden: true }, state.you.hand || [], (card) => {
            const li = document.createElement('li');
            if (respondingToDecision) {
                li.appendChild(actionButton(cardLabel(card), () => openCardDetail(card)));
                return li;
            }
            li.appendChild(actionButton(cardLabel(card), () => handleHandCardClick(card)));
            li.lastChild.disabled = !canAct;
            // is_playable reflects whether some outstanding play grant this
            // turn actually covers this specific card (e.g. Intimidation's
            // grant only covers the one card it revealed) and, if the card
            // has a "to play" cost, whether that cost is payable at all --
            // see MoodPlayService::isPlayable(). The hand button itself
            // stays clickable either way, so the card's rules text can
            // still be inspected -- only the panel's own Play button
            // (see updatePlayButtonEnabled()) is actually gated on this.
            if (canAct && !card.is_playable) {
                li.lastChild.classList.add('not-playable');
                li.lastChild.title = "This card can't be played right now";
            }
            return li;
        });

        document.getElementById('pass-button').disabled = !canAct;

        // round.play_grants describes whoever's turn it currently is, not
        // the viewer specifically -- showing it while it's someone else's
        // turn would read as "you have a play left" when you don't, so the
        // whole indicator stays hidden until it's actually your turn.
        const playGrantsDetails = document.getElementById('play-grants-details');
        playGrantsDetails.hidden = !state.you.is_your_turn;
        const playGrants = (state.you.is_your_turn && state.round && state.round.play_grants) || [];
        document.getElementById('plays-remaining-count').textContent = playGrants.length;
        renderList(
            document.getElementById('play-grants-list'),
            { hidden: true }, // always at least the base turn's own grant while a round is in progress
            playGrants,
            (grant) => {
                const li = document.createElement('li');
                li.textContent = grant.description;
                return li;
            }
        );

        renderList(
            document.getElementById('recent-events-list'),
            document.getElementById('recent-events-empty'),
            state.recent_events || [],
            (event) => {
                const li = document.createElement('li');
                li.textContent = event.description;
                return li;
            }
        );
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
        // Passing is always valid even with a hand card's choices panel
        // still open (considered playing it, decided not to) -- close it
        // the same way submitPlay()'s own success path does, since
        // otherwise polling stays suppressed indefinitely (see
        // showBoard()'s pollTimer) and the board silently goes stale
        // until the player happens to notice and clicks Cancel.
        selectedCard = null;
        choicesPanel.hidden = true;
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

    // Creativity's copy_card_id is the only field whose choice changes what
    // OTHER fields the panel needs to show. Two different things happen
    // once a copy target is picked, both mirroring exactly what
    // MoodPlayService reads once the play actually reaches the server:
    //  1) the copied mood's OWN fields -- its "to play" cost (e.g. Guile's
    //     discard_card_ids) and its own after-playing choices (e.g.
    //     Compulsion's target_player_id, Dignity's discard_card_id) -- read
    //     from the exact same flat, top-level choices bag a normal play of
    //     that card would use. These are already sitting on the candidate's
    //     own serialized choice_fields (currentState.in_play), computed the
    //     same way for every card -- no new data needed, just reused as-is.
    //  2) reactions to the play from the ACTING PLAYER's own OTHER cards --
    //     Duplicity's repeat-with-fresh-choices, Scorn's/Validation's
    //     reactions -- which depend on board state (do you have Duplicity/
    //     Scorn/Validation in play?) the client would otherwise have to
    //     duplicate the checks for, so these come precomputed per candidate
    //     from the server instead (copy_simulation, from
    //     GameService::creativityCopySimulation()).
    // creativityBaseFields is just the copy_card_id field itself -- rendered
    // once, as a static row, and never rebuilt. Everything else Creativity's
    // own serialized choice_fields carries (its own baseline Scorn/
    // Validation reaction, if the viewer has one in play, matching
    // Creativity's own raw blue color for the "you didn't copy anything"
    // case) is creativityNoCopyExtraFields -- the fallback extras used
    // whenever copy_card_id is blank. Both "no copy" and "copy candidate X"
    // extras render through the same renderCreativityCopyFields() path
    // (tracked via creativityCopyFieldNodes) so switching between them --
    // including back to blank -- always fully replaces the previous rows
    // rather than leaving a stale one behind with a now-wrong filter (e.g.
    // Scorn's blue-color filter lingering after copying a white mood).
    let creativityBaseFields = null;
    let creativityNoCopyExtraFields = [];
    let creativityCopyFieldNodes = [];

    function renderCreativityCopyFields(fields) {
        for (const node of creativityCopyFieldNodes) {
            node.remove();
        }
        const fieldsContainer = document.getElementById('choices-fields');
        creativityCopyFieldNodes = fields.map((field) => {
            const row = buildFieldRow(field, selectedCard, field.key);
            fieldsContainer.appendChild(row);
            return row;
        });
    }

    function handleCreativityCopyChange() {
        const select = document.getElementById('choice-field-copy_card_id');
        const copiedCardId = select.value ? Number(select.value) : null;
        const copiedCard = copiedCardId !== null
            ? currentState.in_play.find((c) => c.card_id === copiedCardId)
            : null;

        let extras;
        if (copiedCard) {
            const simulation = selectedCard.copy_simulation[copiedCardId];
            extras = [...copiedCard.choice_fields, ...(simulation ? simulation.extra_fields : [])];
            selectedCard.copy_cost_payable = simulation ? simulation.cost_payable : true;
        } else {
            extras = creativityNoCopyExtraFields;
            selectedCard.copy_cost_payable = true;
        }

        selectedCard.choice_fields = [...creativityBaseFields, ...extras];
        renderCreativityCopyFields(extras);
        updatePlayButtonEnabled();
    }

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
                if (field.candidate_card_ids) {
                    return currentState.in_play
                        .filter((c) => field.candidate_card_ids.includes(c.card_id))
                        .map((c) => ({ value: c.card_id, label: cardLabel(c) }));
                }
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

    function buildFieldWidget(field, card, path) {
        path = path || field.key;

        if (field.type === 'bool') {
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.id = 'choice-field-' + path;
            return checkbox;
        }

        if (field.type === 'value') {
            const select = document.createElement('select');
            select.id = 'choice-field-' + path;
            select.appendChild(new Option('(none)', ''));
            for (let value = field.min; value <= field.max; value++) {
                select.appendChild(new Option(String(value), String(value)));
            }
            return select;
        }

        const select = document.createElement('select');
        select.id = 'choice-field-' + path;
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

    // Builds one <label> per field for the choices panel and the
    // pending-decision panel alike. Most fields are a label wrapping a
    // single widget (DOM id choice-field-<path>). A type: 'nested' field
    // is a label wrapping an indented container of its own sub-fields
    // instead, each one's path prefixed with the nested field's own key --
    // recursively, so nesting can be more than one level deep. Currently
    // only Duplicity's repeat offer uses this: a top-level 'duplicity_repeat'
    // nested field holding a 'repeat' checkbox plus its own nested
    // 'choices' field (the repeated card's own fields), giving paths like
    // choice-field-duplicity_repeat.choices.target_mood_id.
    function buildFieldRow(field, card, path, onChange) {
        path = path || field.key;
        onChange = onChange || updatePlayButtonEnabled;

        const label = document.createElement('label');
        label.className = 'choice-field';
        label.append(field.label + (field.required ? ' (required)' : '') + ' ');

        if (field.type === 'nested') {
            const nestedContainer = document.createElement('div');
            nestedContainer.className = 'choice-field-nested';
            for (const subField of field.fields) {
                nestedContainer.appendChild(buildFieldRow(subField, card, path + '.' + subField.key, onChange));
            }
            label.appendChild(nestedContainer);
            return label;
        }

        const widget = buildFieldWidget(field, card, path);
        widget.addEventListener('change', onChange);
        label.appendChild(widget);
        return label;
    }

    // Flattens choice_fields for validation purposes, descending into a
    // nested field's own .fields with a dotted path so a repeated
    // multi-select (e.g. repeating Courage) still gets its count/constraint
    // checked. Top-level required-ness is checked separately in
    // updatePlayButtonEnabled without recursing: a nested field's own
    // sub-fields may carry `required: true` from their original card
    // schema, but whether that requirement actually applies here depends on
    // the sibling 'repeat?' checkbox (Duplicity's repeat offer -- see
    // updateRespondButtonEnabled()), so it's left informational only (not
    // enforced client-side) for this pass.
    function collectValidatableFields(fields, prefix) {
        const result = [];
        for (const field of fields) {
            const path = prefix ? prefix + '.' + field.key : field.key;
            if (field.type === 'nested') {
                result.push(...collectValidatableFields(field.fields, path));
            } else {
                result.push({ field, path });
            }
        }
        return result;
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

        // is_playable covers everything that isn't a per-field choice
        // mistake -- whose turn it is, a play-grant restriction (e.g.
        // Intimidation), an unpayable "to play" cost -- so it overrides
        // whatever the field-level checks below would otherwise say.
        if (!selectedCard.is_playable) {
            validationMessage.textContent = "This card can't be played right now.";
            validationMessage.hidden = false;
            playButton.disabled = true;
            return;
        }

        // Creativity only: the mood it's about to copy might have its own
        // "to play" cost that can't currently be paid (e.g. copying Guile
        // without two other hand cards to discard) -- is_playable above
        // doesn't know this, since it only checks Creativity's own
        // (nonexistent) cost. See handleCreativityCopyChange().
        if (selectedCard.copy_cost_payable === false) {
            validationMessage.textContent = "That mood's own cost can't be paid right now, so it can't be copied.";
            validationMessage.hidden = false;
            playButton.disabled = true;
            return;
        }

        const allRequiredFilled = selectedCard.choice_fields
            .filter((field) => field.required)
            .every((field) => fieldHasValue(document.getElementById('choice-field-' + field.key), field));

        let firstError = null;
        for (const { field, path } of collectValidatableFields(selectedCard.choice_fields)) {
            const widget = document.getElementById('choice-field-' + path);
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
        const isCreativity = card.effect_key === 'creativity';

        // Creativity gets its own mutable clone -- handleCreativityCopyChange()
        // reassigns its choice_fields as copy_card_id changes, and mutating
        // the original card object would corrupt currentState.you.hand until
        // the next poll. Every other card is read-only, so no clone needed.
        selectedCard = isCreativity ? { ...card, choice_fields: [...card.choice_fields] } : card;
        selectedCard.copy_cost_payable = true;
        creativityBaseFields = isCreativity ? card.choice_fields.filter((f) => f.key === 'copy_card_id') : null;
        creativityNoCopyExtraFields = isCreativity ? card.choice_fields.filter((f) => f.key !== 'copy_card_id') : [];
        creativityCopyFieldNodes = [];

        boardError.hidden = true;
        document.getElementById('choices-card-name').textContent = cardLabel(card);
        document.getElementById('choices-card-rules').textContent = card.rules_text;

        const fieldsContainer = document.getElementById('choices-fields');
        fieldsContainer.innerHTML = '';
        // Only copy_card_id (or, for every other card, its whole field list)
        // renders as a static row here -- Creativity's own reaction extras
        // render through the same renderCreativityCopyFields() path
        // handleCreativityCopyChange() uses, so there's exactly one place
        // that ever adds/removes them.
        const staticFields = isCreativity ? creativityBaseFields : selectedCard.choice_fields;
        for (const field of staticFields) {
            const onChange = field.key === 'copy_card_id' ? handleCreativityCopyChange : undefined;
            fieldsContainer.appendChild(buildFieldRow(field, selectedCard, field.key, onChange));
        }
        if (isCreativity) {
            selectedCard.choice_fields = [...creativityBaseFields, ...creativityNoCopyExtraFields];
            renderCreativityCopyFields(creativityNoCopyExtraFields);
        }

        updatePlayButtonEnabled();
        choicesPanel.hidden = false;
    }

    document.getElementById('cancel-choice-button').addEventListener('click', () => {
        selectedCard = null;
        creativityBaseFields = null;
        creativityNoCopyExtraFields = [];
        creativityCopyFieldNodes = [];
        choicesPanel.hidden = true;
    });

    // -- Pending decisions ---------------------------------------------
    //
    // A handful of cards (Compulsion, Instability, Arrogance, ...) give
    // the real decision to a player OTHER than the one whose turn it is --
    // e.g. Compulsion's target chooses which hand card to give up. The
    // play pauses until that player answers here; state.round.
    // pending_decision (from GET /games/state) is null when nothing's
    // pending, otherwise identifies who's waiting on whom and, only for
    // the targeted player themselves, the actual field to answer
    // (pending_decision.field, shaped exactly like a choice_fields entry,
    // so it's rendered with the same buildFieldRow()/fieldOptions() the
    // regular choices panel uses rather than needing its own logic).

    let activePendingDecision = null;

    // fieldOptions()'s 'mood'/'hand_card' cases exclude a "card being
    // played" id from their own candidates -- meaningless here, since the
    // responder isn't playing anything, so a placeholder with an id no
    // real card ever has keeps that exclusion a harmless no-op.
    const PENDING_DECISION_PLACEHOLDER_CARD = { card_id: -1 };

    function renderPendingDecision(pendingDecision) {
        if (!pendingDecision) {
            pendingDecisionBanner.hidden = true;
            pendingDecisionPanel.hidden = true;
            activePendingDecision = null;
            return;
        }

        if (!pendingDecision.is_you) {
            pendingDecisionBanner.textContent = 'Waiting on ' + playerLabelFor(pendingDecision.target_game_player_id) +
                ' to respond to ' + (pendingDecision.played_card_name || 'a mood') + '.';
            pendingDecisionBanner.hidden = false;
            pendingDecisionPanel.hidden = true;
            activePendingDecision = null;
            return;
        }

        pendingDecisionBanner.hidden = true;

        // Only (re)build the panel once per decision -- polling is
        // suspended while it's open (see showBoard()'s pollTimer), so
        // this only ever runs once per decision, the same way
        // openChoicesPanel() only ever gets called once per selection.
        if (activePendingDecision && !pendingDecisionPanel.hidden) {
            return;
        }

        activePendingDecision = pendingDecision;
        const titlesByDecisionType = {
            duplicity_repeat_offer: "Repeat " + (pendingDecision.played_card_name || 'this mood') + "'s effect?",
            enthusiasm_extra_score: "Enthusiasm's bonus",
            passion_score_opponent_mood: "Passion's bonus",
        };
        document.getElementById('pending-decision-title').textContent =
            titlesByDecisionType[pendingDecision.decision_type] || 'Respond to ' + (pendingDecision.played_card_name || 'a mood');

        // Duplicity's repeat-offer is about the ALREADY-PLAYED card itself
        // (still correctly excludable from a mood/hand_card field's own
        // candidates via fieldOptions()'s card.card_id check, the same way
        // it would be while that card was still being played) -- every
        // other decision type has no "card being played" from the
        // responder's own perspective, so the placeholder stays a no-op.
        const fieldCard = pendingDecision.decision_type === 'duplicity_repeat_offer'
            ? { card_id: pendingDecision.played_card_id }
            : PENDING_DECISION_PLACEHOLDER_CARD;

        const fieldContainer = document.getElementById('pending-decision-field');
        fieldContainer.innerHTML = '';
        fieldContainer.appendChild(buildFieldRow(
            pendingDecision.field,
            fieldCard,
            pendingDecision.field.key,
            updateRespondButtonEnabled
        ));

        updateRespondButtonEnabled();
        pendingDecisionPanel.hidden = false;
    }

    // round.scoring_preview (null except while an Enthusiasm/Passion
    // scoring decision is outstanding -- see GameService::
    // serializeScoringPreview()) is the running score-so-far (an
    // undecided card just reads as declined) plus any active Sneakiness
    // swap targets. Shown to every viewer, not just whoever's actually
    // answering -- final round scores aren't hidden the way an opponent's
    // hand is, and without this "you may score one of your opponents'
    // moods" is close to meaningless to decide on blind, especially once
    // a swap is in play.
    function renderScoringPreview(preview) {
        const container = document.getElementById('scoring-preview');
        if (!preview) {
            container.hidden = true;
            return;
        }

        renderList(document.getElementById('scoring-preview-scores'), { hidden: true }, Object.entries(preview.scores), ([gamePlayerId, score]) => {
            const li = document.createElement('li');
            li.textContent = playerLabelFor(Number(gamePlayerId)) + ': ' + score;
            return li;
        });

        const swapsText = document.getElementById('scoring-preview-swaps');
        if (preview.sneakiness_swaps.length === 0) {
            swapsText.hidden = true;
        } else {
            swapsText.hidden = false;
            swapsText.textContent = 'Sneakiness will swap scores after scoring: ' + preview.sneakiness_swaps
                .map((swap) => playerLabelFor(swap.game_player_id) + ' ↔ ' + playerLabelFor(swap.swaps_with_game_player_id))
                .join(', ');
        }

        container.hidden = false;
    }

    function updateRespondButtonEnabled() {
        const respondButton = document.getElementById('respond-decision-button');
        if (!activePendingDecision) {
            respondButton.disabled = true;
            return;
        }

        const field = activePendingDecision.field;

        // Duplicity's repeat-offer is the one pending-decision field shaped
        // like a nested choices-panel field (a "repeat?" checkbox plus a
        // nested sub-form for the repeat's own choices) rather than a
        // single simple widget -- declining is always a complete, valid
        // answer regardless of what the (inert unless repeating) nested
        // form contains, matching the same "not enforced client-side"
        // tradeoff this codebase already accepts for the ordinary choices
        // panel's own nested-field required-ness (see updatePlayButtonEnabled()).
        if (field.type === 'nested') {
            respondButton.disabled = false;
            return;
        }

        const widget = document.getElementById('choice-field-' + field.key);
        const hasValue = fieldHasValue(widget, field);

        // Every one of the nine original opponent-decision types is
        // required: true (Compulsion's target must choose *a* card, no
        // decline), so this never had to distinguish the two cases before
        // -- Enthusiasm's/Passion's own scoring-time decisions are the
        // first required: false pending-decision fields, and leaving one
        // blank (declining) has to stay a valid, submittable answer the
        // same way it already does in the ordinary choices panel (see
        // updatePlayButtonEnabled()'s own required-only filter).
        if (field.required && !hasValue) {
            respondButton.disabled = true;
            return;
        }

        respondButton.disabled = hasValue && !!fieldValidationMessage(field, widget);
    }

    document.getElementById('respond-decision-button').addEventListener('click', async () => {
        if (!activePendingDecision) {
            return;
        }

        boardError.hidden = true;
        boardMessage.hidden = true;

        const choices = buildChoicesFromFields([activePendingDecision.field]);
        const { ok, body } = await respondToDecision(currentGameId, choices);

        if (!ok) {
            boardError.textContent = body.message || 'Could not submit your response.';
            boardError.hidden = false;
            return;
        }

        activePendingDecision = null;
        pendingDecisionPanel.hidden = true;
        announceOutcome(body);
        await refreshBoard();
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

    // Builds the choices payload, descending into a nested field's own
    // .fields with a dotted path (matching buildFieldRow's widget ids) to
    // produce a sub-object -- e.g. { duplicity_repeat: { repeat: true,
    // choices: { target_mood_id: 12 } } }. A nested field is only included
    // if its sub-object ended up non-empty, so submitting without touching
    // the repeat offer leaves the payload as if it weren't there.
    function buildChoicesFromFields(fields, prefix) {
        const choices = {};
        for (const field of fields) {
            const path = prefix ? prefix + '.' + field.key : field.key;

            if (field.type === 'nested') {
                const subChoices = buildChoicesFromFields(field.fields, path);
                if (Object.keys(subChoices).length > 0) {
                    choices[field.key] = subChoices;
                }
                continue;
            }

            const widget = document.getElementById('choice-field-' + path);
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
        return choices;
    }

    document.getElementById('play-card-button').addEventListener('click', async () => {
        const choices = buildChoicesFromFields(selectedCard.choice_fields);
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
