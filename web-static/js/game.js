(async function () {
    const user = await getCurrentUser();
    if (!user) {
        window.location.replace('/');
        return;
    }

    document.getElementById('username').textContent = user.username;
    document.getElementById('game-main').hidden = false;
    startVersionWatcher();

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

    // deck_type's own display names -- matching the <option> labels in the
    // New Game dialog -- don't fit humanizeStatus()'s generic
    // underscore-splitting capitalization ("one_of_each" would become "One
    // Of Each", capitalizing "Of"), so this is a fixed lookup instead.
    const DECK_TYPE_LABELS = {
        structure: 'Structure',
        power: 'Power',
        jceddys_75: 'jceddy\'s 75 Card',
        custom: 'Custom Decklist',
        custom_duel: 'Custom Decklists (Duel)',
        one_of_each: 'One of Each Card',
    };

    function deckTypeLabel(deckType) {
        return DECK_TYPE_LABELS[deckType] || deckType;
    }

    // Plain-language explanation shown under the New Game dialog's own
    // Deck dropdown (see updateDeckTypeDescription()) -- kept in sync with
    // GameService::buildStructureDeckCardIds()/buildPowerDeckCardIds()/
    // buildJceddys75DeckCardIds()'s own actual card counts, since those are
    // the numbers a player is actually choosing between here.
    const DECK_TYPE_DESCRIPTIONS = {
        structure: 'A 45-card deck matching a new physical box’s printed rarity mix: 23 common, 14 uncommon, 6 rare, and 2 mythic moods.',
        power: 'A fast 15-card deck: 1 random mythic mood plus 14 other random moods.',
        jceddys_75: 'A 75-card deck: for each color, 1 random mythic, 2 different random rares, 4 random uncommons (up to 2 copies of any one), and 8 random commons (up to 3 copies of any one).',
        custom: 'Upload or paste your own decklist: at least 15 cards, plus 15 more per player beyond the first two.',
        custom_duel: 'Each player uploads/pastes their own decklist, validated against deck-building rules you choose below.',
        one_of_each: 'The full 133-card pool — one copy of every printed mood.',
    };

    const RARITIES = ['common', 'uncommon', 'rare', 'mythic'];

    // Plain-language summaries of DuelDeckRules::forPreset()'s own locked
    // values, shown read-only when a preset other than User-Defined is
    // picked below -- purely descriptive, the actual values are resolved
    // server-side (see GameService::resolveDuelDeckRules()) so there's no
    // risk of these two ever disagreeing about what gets enforced.
    const DUEL_RULES_PRESET_SUMMARIES = {
        structure: 'Exactly 45 cards: 23 common, 14 uncommon, 6 rare, 2 mythic. No duplicate cards.',
        power: 'At least 15 cards, at most 1 mythic. No duplicate cards.',
        jceddys_75: 'Exactly 75 cards: 40 common (up to 3 copies of any one), 20 uncommon (up to 2 copies of any one), 10 rare, 5 mythic. No duplicate rare or mythic cards. Every rarity split evenly across all 5 colors.',
    };

    function updateDeckTypeDescription() {
        const deckType = document.getElementById('new-game-deck-type').value;
        document.getElementById('new-game-deck-type-description').textContent = DECK_TYPE_DESCRIPTIONS[deckType] || '';
        document.getElementById('new-game-decklist-fields').hidden = deckType !== 'custom';
        document.getElementById('new-game-duel-rules-fields').hidden = deckType !== 'custom_duel';
        if (deckType === 'custom_duel') {
            updateDuelRulesPresetVisibility();
        }
    }

    // Shows the locked-in summary for a built-in preset, or the editable
    // fields for User-Defined -- mutually exclusive, matching
    // updateDeckTypeDescription()'s own decklist-fields-vs-rules-fields
    // toggle one level up.
    function updateDuelRulesPresetVisibility() {
        const preset = document.getElementById('new-game-duel-rules-preset').value;
        const summaryEl = document.getElementById('new-game-duel-rules-preset-summary');

        summaryEl.textContent = DUEL_RULES_PRESET_SUMMARIES[preset] || '';
        summaryEl.hidden = preset === 'user_defined';
        document.getElementById('new-game-duel-rules-user-defined').hidden = preset !== 'user_defined';
    }

    // 'custom' decklists aren't supported for duel games, and 'custom_duel'
    // (each player supplying their own decklist against rules the creator
    // defines) only makes sense FOR a duel -- both enforced server-side by
    // GameService::createGame(), disabled here too so a doomed combination
    // can't even be selected, matching updateOpponentSelectionLimit()'s own
    // proactive-prevention approach for format-dependent constraints.
    // Either team format similarly disables 'power' -- its 15 cards fall
    // short of the 45-card minimum both team formats share (see
    // php-app/README.md). Falls back to 'structure' if the now-disallowed
    // option was selected.
    function updateDeckTypeAvailability() {
        const deckTypeSelect = document.getElementById('new-game-deck-type');
        const format = document.getElementById('new-game-format').value;
        const isDuel = format === 'duel';
        const isTeam = format === 'team' || format === 'closed_team';
        const customOption = deckTypeSelect.querySelector('option[value="custom"]');
        const customDuelOption = deckTypeSelect.querySelector('option[value="custom_duel"]');
        const powerOption = deckTypeSelect.querySelector('option[value="power"]');

        customOption.disabled = isDuel;
        customDuelOption.disabled = !isDuel;
        powerOption.disabled = isTeam;
        if (
            (isDuel && deckTypeSelect.value === 'custom')
            || (!isDuel && deckTypeSelect.value === 'custom_duel')
            || (isTeam && deckTypeSelect.value === 'power')
        ) {
            deckTypeSelect.value = 'structure';
        }

        updateDeckTypeDescription();
    }

    // Shows the partner picker only for Open Team Play, populated from
    // whichever opponents are currently checked -- re-run on every
    // checkbox change and format change (mirroring
    // updateOpponentSelectionLimit()'s own re-run triggers), so the
    // partner list never offers someone who isn't actually one of this
    // game's 3 opponents. Preserves the previously-selected partner
    // across a re-population if they're still checked.
    const TEAM_FIELDS_DESCRIPTIONS = {
        team: "Open Team Play needs exactly 3 opponents (4 players total), seated as two teams of two. Choose which of them is your partner -- you'll sit next to them, see each other's hands, and share a score each round:",
        closed_team: "Closed Team Play needs exactly 3 opponents (4 players total), seated as two teams of two across the table from each other. Choose which of them is your partner -- your hands stay private from each other, but you'll pass 2 cards to them at the start of the game and share a score each round:",
    };

    function updateTeamFields() {
        const format = document.getElementById('new-game-format').value;
        const isTeamFormat = format === 'team' || format === 'closed_team';
        document.getElementById('new-game-team-fields').hidden = !isTeamFormat;
        if (!isTeamFormat) {
            return;
        }

        document.getElementById('new-game-team-fields-description').textContent = TEAM_FIELDS_DESCRIPTIONS[format];

        const partnerSelect = document.getElementById('new-game-partner');
        const previousValue = partnerSelect.value;
        partnerSelect.innerHTML = '';

        const checked = Array.from(opponentCheckboxes.querySelectorAll('input:checked'));
        for (const box of checked) {
            const option = document.createElement('option');
            option.value = box.value;
            option.textContent = box.parentElement.textContent.trim();
            partnerSelect.appendChild(option);
        }

        if (checked.some((box) => box.value === previousValue)) {
            partnerSelect.value = previousValue;
        }
    }

    async function refreshLobby() {
        const { ok, body } = await listGames();
        const gamesList = document.getElementById('games-list');

        renderList(gamesList, document.getElementById('games-empty'), ok ? body.games : [], (game) => {
            const li = document.createElement('li');
            // The your-turn background (".lobby-row--your-turn") is a
            // whole-row highlight on top of (not instead of) the bold
            // "(your turn)" text tag on its own status line below --
            // makes an actionable game stand out even before the text
            // itself is read.
            li.className = 'lobby-row' + (game.is_your_turn ? ' lobby-row--your-turn' : '');

            // Wrapped in its own container (rather than appended straight
            // to the li) so the action button below can sit beside the
            // text as a flex sibling -- see the ".lobby-row"/".lobby-info"
            // rules in style.css -- instead of always trailing after
            // however many lines the text itself wraps to on a narrow
            // (phone-width) viewport.
            const infoEl = document.createElement('div');
            infoEl.className = 'lobby-info';

            // Matches renderBoard()'s own deckDescription logic one level up
            // (board title), except there's no per-viewer custom_deck_name
            // available for 'custom_duel' at this list level -- each
            // player's own submitted deck name only comes back from
            // getState(), not listGamesForUser() -- so that case falls back
            // to deckTypeLabel()'s generic label like every other deck_type.
            const deckDescription = game.deck_type === 'custom'
                ? (game.custom_deck_name || 'Uploaded Deck')
                : deckTypeLabel(game.deck_type) + ' deck';
            const formatEl = document.createElement('div');
            formatEl.className = 'lobby-format';
            formatEl.textContent = formatLabel(game.format) + ', ' + deckDescription;
            infoEl.appendChild(formatEl);

            // Its own line beneath the format/deck line (rather than
            // trailing on it) and above the opponents, so status -- the
            // single most actionable piece of information in the row --
            // isn't buried mid-line.
            const statusLineEl = document.createElement('div');
            statusLineEl.className = 'lobby-status-line';

            const statusEl = document.createElement('span');
            statusEl.className = 'lobby-status lobby-status--' + game.status;
            statusEl.textContent = humanizeStatus(game.status);
            statusLineEl.appendChild(statusEl);

            if (game.is_your_turn) {
                const yourTurnEl = document.createElement('span');
                yourTurnEl.className = 'lobby-your-turn';
                yourTurnEl.textContent = ' (your turn)';
                statusLineEl.appendChild(yourTurnEl);
            }

            infoEl.appendChild(statusLineEl);

            const opponents = game.players.map((p) => p.username).join(', ');
            infoEl.append(opponents);

            // winner_usernames is only ever non-empty once the game is
            // actually 'completed' (see GameService::listGamesForUser()) --
            // both teammates' names for a team-format win, just the one
            // player's otherwise, matching how the board's own end-of-game
            // display already credits a team win.
            if (game.winner_usernames.length > 0) {
                const winnerEl = document.createElement('div');
                winnerEl.className = 'lobby-winner';
                winnerEl.textContent = game.winner_usernames.join(' & ') + ' won';
                infoEl.appendChild(winnerEl);
            }

            li.appendChild(infoEl);

            // A 'waiting'/'in_progress' game is still something to actually
            // play; anything else (completed, or the rarer abandoned) is
            // read-only from here on, so the button reads "View" instead --
            // matches what actually happens when it's clicked (showBoard()
            // renders a read-only board once the game itself isn't
            // in_progress, see GameService::getState()'s own round: null).
            const canPlay = game.status === 'waiting' || game.status === 'in_progress';
            li.appendChild(actionButton(canPlay ? 'Play' : 'View', () => showBoard(game.id)));
            return li;
        });
    }

    document.getElementById('back-to-lobby-button').addEventListener('click', showLobby);

    // -- New game dialog ---------------------------------------------------

    const newGameDialog = document.getElementById('new-game-dialog');
    const newGameForm = document.getElementById('new-game-form');
    const newGameError = document.getElementById('new-game-error');
    const opponentCheckboxes = document.getElementById('opponent-checkboxes');

    // A 'duel' game requires exactly 2 players total (enforced server-side
    // by GameService::createGame()), so at most 1 opponent may be chosen
    // for it -- every other format allows up to 3. Re-run on every
    // checkbox change and every format-dropdown change, so switching to
    // 'duel' with 2+ opponents already checked un-checks the extras
    // (keeping the first one) rather than leaving a selection the server
    // would just reject.
    function updateOpponentSelectionLimit() {
        const maxOpponents = document.getElementById('new-game-format').value === 'duel' ? 1 : 3;
        const boxes = opponentCheckboxes.querySelectorAll('input');

        let checkedCount = 0;
        for (const box of boxes) {
            if (box.checked) {
                checkedCount += 1;
                if (checkedCount > maxOpponents) {
                    box.checked = false;
                    checkedCount -= 1;
                }
            }
        }

        for (const box of boxes) {
            box.disabled = checkedCount >= maxOpponents && !box.checked;
        }
    }

    document.getElementById('new-game-format').addEventListener('change', updateOpponentSelectionLimit);
    document.getElementById('new-game-format').addEventListener('change', updateDeckTypeAvailability);
    document.getElementById('new-game-format').addEventListener('change', updateTeamFields);
    document.getElementById('new-game-deck-type').addEventListener('change', updateDeckTypeDescription);
    document.getElementById('new-game-duel-rules-preset').addEventListener('change', updateDuelRulesPresetVisibility);

    // Reading an uploaded decklist file into the textarea lets both input
    // methods (file upload or pasted text) share the same submit-time
    // field, rather than createGame() needing to know which one was used.
    document.getElementById('new-game-decklist-file').addEventListener('change', async (event) => {
        const file = event.target.files[0];
        if (!file) {
            return;
        }

        document.getElementById('new-game-decklist-text').value = await file.text();
    });

    // Reads the four rarity rows' own optional "max total"/"max
    // duplicates" fields for the User-Defined preset -- a blank field
    // means "no restriction for that rarity" (matches DuelDeckRules's own
    // "missing key = unrestricted" contract), so it's simply omitted
    // rather than sent as e.g. 0.
    function collectRarityMap(idPrefix) {
        const map = {};
        for (const rarity of RARITIES) {
            const value = document.getElementById(idPrefix + rarity).value;
            if (value !== '') {
                map[rarity] = Number(value);
            }
        }
        return map;
    }

    // Reads the four rarity rows' own "even split across colors"
    // checkboxes into a plain list of checked rarity names -- matches
    // DuelDeckRules's own $evenColorDistributionRarities shape (a rarity
    // present in the list means the rule applies, absent means no
    // requirement), unlike the two number-field rules above which are
    // sent as {rarity: value} maps.
    function collectEvenColorDistributionRarities() {
        return RARITIES.filter((rarity) => document.getElementById('new-game-duel-even-distribution-' + rarity).checked);
    }

    function collectDuelDeckRules() {
        const preset = document.getElementById('new-game-duel-rules-preset').value;
        if (preset !== 'user_defined') {
            return { preset };
        }

        return {
            preset: 'user_defined',
            min_cards: Number(document.getElementById('new-game-duel-min-cards').value) || 0,
            rarity_limits: collectRarityMap('new-game-duel-rarity-limit-'),
            duplicate_limits: collectRarityMap('new-game-duel-duplicate-limit-'),
            even_color_distribution_rarities: collectEvenColorDistributionRarities(),
        };
    }

    document.getElementById('new-game-button').addEventListener('click', async () => {
        newGameError.hidden = true;
        newGameForm.reset();
        updateDeckTypeAvailability();

        const { ok, body } = await listFriends();
        const friends = ok ? body.friends : [];

        opponentCheckboxes.innerHTML = '';
        document.getElementById('opponent-checkboxes-empty').hidden = friends.length > 0;

        for (const friend of friends) {
            const label = document.createElement('label');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = friend.friend_id;
            checkbox.addEventListener('change', updateOpponentSelectionLimit);
            checkbox.addEventListener('change', updateTeamFields);
            label.appendChild(checkbox);
            label.append(' ' + friend.friend_username);
            opponentCheckboxes.appendChild(label);
        }

        updateTeamFields();
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
        const isTeamFormat = format === 'team' || format === 'closed_team';

        if (isTeamFormat && opponentUserIds.length !== 3) {
            newGameError.textContent = 'Either team format needs exactly 3 opponents (4 players total).';
            newGameError.hidden = false;
            return;
        }

        const partnerUserId = isTeamFormat ? Number(document.getElementById('new-game-partner').value) : undefined;
        const deckType = document.getElementById('new-game-deck-type').value;
        const decklistText = deckType === 'custom' ? document.getElementById('new-game-decklist-text').value : undefined;
        const duelDeckRules = deckType === 'custom_duel' ? collectDuelDeckRules() : undefined;
        const { ok, body } = await createGame(opponentUserIds, format, undefined, deckType, decklistText, duelDeckRules, partnerUserId);

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
        // has_unused_play_grant only ever exists (and is only ever true) on
        // an in-play card -- see GameService::getState()'s in_play mapping
        // -- so this stays silent for a hand/discard-pile card the same way
        // is_creativity_copy already does below. Most relevant for Hope/
        // Grace, whose own grant is lost outright if this specific card
        // leaves play before it's spent (see BoardState::grantIsActive()'s
        // own docblock) -- called out here too since a 'mood' choice field
        // (e.g. Faith's target_mood_id) is exactly the kind of place a
        // player might not otherwise think to check the card detail dialog
        // before picking a target.
        return card.name + (card.has_unused_play_grant ? ' *' : '') +
            ' (' + card.color + ', ' + card.value + ')' +
            (card.is_creativity_copy ? ' [Creativity copy]' : '');
    }

    // Mirrors the naming convention documented in web-static/README.md's
    // "Assets" section: <cards.id>-<slugified-name>.webp, one folder per
    // Set. Only the 'MSW' set exists today, so its folder is hardcoded
    // here rather than threaded through the API -- see the "custom card
    // sets" issue for when that stops being true.
    function slugify(name) {
        return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    function cardArtUrl(card) {
        return '../img/cards/MSW/' + card.catalog_card_id + '-' + slugify(card.name) + '.webp';
    }

    // Builds a card-art thumbnail in place of the old text-only button --
    // the printed art already conveys name/color/base value/rules text (all
    // included as alt text for accessibility), so only whatever ISN'T part
    // of the static art -- a value currently different from what's printed,
    // suppression, an owner caption, or a disabled state -- needs to be
    // overlaid on top of it.
    function buildCardThumb(card, { caption, onClick, notPlayable = false } = {}) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'card-thumb';
        if (onClick) {
            button.addEventListener('click', onClick);
        }

        const img = document.createElement('img');
        img.className = 'card-thumb__art';
        img.src = cardArtUrl(card);
        img.alt = card.name + '. ' + (card.rules_text || 'No ability.');
        button.appendChild(img);

        if (card.value !== card.base_value) {
            const valueBadge = document.createElement('span');
            valueBadge.className = 'card-thumb__badge card-thumb__badge--value';
            valueBadge.textContent = card.value;
            button.appendChild(valueBadge);
        }

        if (card.is_creativity_copy) {
            const copyBadge = document.createElement('span');
            copyBadge.className = 'card-thumb__badge card-thumb__badge--copy';
            copyBadge.textContent = 'Copy';
            button.appendChild(copyBadge);
        }

        if (card.is_suppressed) {
            const suppressedBadge = document.createElement('span');
            suppressedBadge.className = 'card-thumb__badge card-thumb__badge--suppressed';
            suppressedBadge.textContent = 'Suppressed';
            button.appendChild(suppressedBadge);
            // Tapped-card convention: rotate the art 90deg (the badge above
            // stays upright so the state is still readable, not just
            // visual) -- see "Card art rendering" in web-static/README.md.
            button.classList.add('card-thumb--suppressed');
        }

        if (card.value_locked) {
            // A permanent "after playing this mood, ... this mood's value
            // becomes N" trigger (Dignity, Delight, ...) has locked in its
            // alt value, as opposed to a "while in play" card (Determination)
            // whose value is only ever recomputed live -- rotated 180deg to
            // distinguish the two at a glance, per table convention.
            button.classList.add('card-thumb--value-locked');
        }

        if (notPlayable) {
            button.classList.add('not-playable');
            button.title = "This card can't be played right now";
        }

        if (caption) {
            const captionEl = document.createElement('span');
            captionEl.className = 'card-thumb__caption';
            captionEl.textContent = caption;
            button.appendChild(captionEl);
        }

        return button;
    }

    function playerLabelFor(gamePlayerId) {
        const player = currentState.players.find((p) => p.game_player_id === gamePlayerId);
        return player ? player.username : '?';
    }

    // Lets a player double-check a card they don't recognize -- whether
    // it's their own or an opponent's -- without it affecting play, since
    // in-play/discard-pile cards can't be acted on anyway. `selection`, when
    // passed, additionally turns this into the Closed Team Play initial
    // card-pass's own picker (see renderInitialCardPass()): { selected,
    // disabled, onToggle } shows a Select/De-select button that calls
    // onToggle() and closes the dialog -- disabled is computed by the
    // caller (true once 2 OTHER cards are already selected, so an
    // already-selected card can still always be de-selected).
    function openCardDetail(card, ownerLabel, selection) {
        const artEl = document.getElementById('card-detail-art');
        artEl.src = cardArtUrl(card);
        artEl.alt = card.name + '. ' + (card.rules_text || 'No ability.');

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

        // is_creativity_copy only exists (and is only ever true) on an
        // in-play Creativity that actually copied something -- see
        // GameService::serializeCard(). Its name/rules text above already
        // read as the copied card's own, so this is purely a "why does
        // this say Serenity when the card is Creativity" explainer.
        const creativityCopyEl = document.getElementById('card-detail-creativity-copy');
        if (card.is_creativity_copy) {
            creativityCopyEl.textContent = 'A Creativity copy of ' + card.name + '.';
            creativityCopyEl.hidden = false;
        } else {
            creativityCopyEl.hidden = true;
        }

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

        // bliss_discard_color only exists on an in-play Bliss card (see
        // GameService::getState()'s in_play mapping) -- reads as
        // undefined/null, so stays hidden, for every other card.
        const blissColorEl = document.getElementById('card-detail-bliss-color');
        if (card.bliss_discard_color) {
            blissColorEl.textContent = 'Discarded to pay its cost: a ' + card.bliss_discard_color + ' card ' +
                '— scores ' + card.bliss_discard_color + ' moods two extra times.';
            blissColorEl.hidden = false;
        } else {
            blissColorEl.hidden = true;
        }

        // has_unused_play_grant only exists (and is only ever true) on an
        // in-play card whose own effect (Hope, Grace, ...) granted an extra
        // play that hasn't been spent yet -- see GameService::getState()'s
        // in_play mapping. Only ever true during that card's own owner's
        // turn (the grant doesn't exist as an object at all outside of it),
        // so this stays hidden the rest of the time same as the others.
        const unusedGrantEl = document.getElementById('card-detail-unused-grant');
        if (card.has_unused_play_grant) {
            unusedGrantEl.textContent = 'This card has an unused extra play grant available.';
            unusedGrantEl.hidden = false;
        } else {
            unusedGrantEl.hidden = true;
        }

        const selectButton = document.getElementById('card-detail-select-button');
        if (selection) {
            selectButton.hidden = false;
            selectButton.textContent = selection.selected ? 'De-select' : 'Select';
            selectButton.disabled = selection.disabled;
            selectButton.onclick = () => {
                selection.onToggle();
                cardDetailDialog.close();
            };
        } else {
            selectButton.hidden = true;
            selectButton.onclick = null;
        }

        cardDetailDialog.showModal();
    }

    document.getElementById('card-detail-close-button').addEventListener('click', () => {
        cardDetailDialog.close();
    });

    // In-play board (issue #124): groups in-play moods by seating position
    // relative to the viewer instead of one flat list, matching how the
    // moods would actually sit around a physical table. Index 0 is always
    // the viewer's own zone ("south"); index 1 is the player whose turn
    // comes right after the viewer's own -- GameService::rotate() already
    // treats ascending seat_order as "clockwise" (see its own docblock),
    // and clockwise turn order seats that next player at the viewer's own
    // left -- so index 1 is "west"/"northwest" (left), the last index is
    // "east"/"northeast" (right), and (for 3-4 players) whichever index
    // sits directly across is "north".
    const IN_PLAY_ZONE_ORDER_BY_PLAYER_COUNT = {
        2: ['south', 'north'],
        3: ['south', 'northwest', 'northeast'],
        4: ['south', 'west', 'north', 'east'],
    };
    const IN_PLAY_ZONE_NAMES = ['north', 'northwest', 'northeast', 'west', 'east', 'south'];

    function inPlayZoneAssignments(state) {
        const players = state.players;
        const zoneOrder = IN_PLAY_ZONE_ORDER_BY_PLAYER_COUNT[players.length];
        const viewer = players.find((p) => p.game_player_id === state.you.game_player_id);

        const zoneByGamePlayerId = {};
        for (const player of players) {
            const offset = (player.seat_order - viewer.seat_order + players.length) % players.length;
            zoneByGamePlayerId[player.game_player_id] = zoneOrder[offset];
        }
        return zoneByGamePlayerId;
    }

    function renderInPlay(state) {
        const board = document.getElementById('in-play-board');
        const emptyEl = document.getElementById('in-play-empty');

        if (state.in_play.length === 0) {
            board.hidden = true;
            emptyEl.hidden = false;
            return;
        }
        board.hidden = false;
        emptyEl.hidden = true;

        board.classList.remove('in-play-board--2', 'in-play-board--3', 'in-play-board--4');
        board.classList.add('in-play-board--' + state.players.length);

        const zoneByGamePlayerId = inPlayZoneAssignments(state);
        const activeZones = new Set(Object.values(zoneByGamePlayerId));

        const cardsByZone = {};
        for (const zone of IN_PLAY_ZONE_NAMES) {
            cardsByZone[zone] = [];
        }
        for (const card of state.in_play) {
            cardsByZone[zoneByGamePlayerId[card.owner_game_player_id]].push(card);
        }

        for (const zone of IN_PLAY_ZONE_NAMES) {
            const zoneEl = document.getElementById('in-play-zone-' + zone);
            zoneEl.hidden = !activeZones.has(zone);

            const listEl = zoneEl.querySelector('.in-play-zone__list');
            listEl.innerHTML = '';
            for (const card of cardsByZone[zone]) {
                const owner = state.players.find((p) => p.game_player_id === card.owner_game_player_id);
                const ownerLabel = owner ? owner.username : '?';
                const li = document.createElement('li');
                li.appendChild(buildCardThumb(card, {
                    caption: ownerLabel,
                    onClick: () => openCardDetail(card, ownerLabel),
                }));
                listEl.appendChild(li);
            }
        }
    }

    // Generic enlarged-art viewer for game-level art not tied to a specific
    // printed card (e.g. Hurt Feelings) -- see "Assets" in
    // web-static/README.md for why that art doesn't live under
    // img/cards/ and so has no catalog_card_id/rules_text to build a
    // card-detail-dialog-style view from.
    const artPreviewDialog = document.getElementById('art-preview-dialog');
    function openArtPreview(src, alt) {
        const imageEl = document.getElementById('art-preview-image');
        imageEl.src = src;
        imageEl.alt = alt;
        artPreviewDialog.showModal();
    }

    document.getElementById('art-preview-close-button').addEventListener('click', () => {
        artPreviewDialog.close();
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
        // A custom decklist's own name (or "Uploaded Deck" if none was
        // specified) replaces "<deck type> deck" entirely here, rather than
        // being appended to it -- the deck name itself is what identifies
        // the deck in this case, the same role "Structure deck" etc. plays
        // for the algorithmically-assembled pools. custom_duel has no
        // single game-wide deck (each player submits their own -- see the
        // per-player "deck:" labels in the players list below), so the
        // title shows the *viewer's own* submitted deck name instead of
        // deckTypeLabel()'s generic "Custom Decklists (Duel) deck", which
        // never actually named anything the viewer had chosen.
        const you = state.players.find((p) => p.game_player_id === state.you.game_player_id);
        const deckDescription = state.game.deck_type === 'custom'
            ? (state.game.custom_deck_name || 'Uploaded Deck')
            : state.game.deck_type === 'custom_duel'
                ? (you && you.custom_deck_name || 'Uploaded Deck')
                : deckTypeLabel(state.game.deck_type) + ' deck';
        document.getElementById('board-title').textContent =
            'Game #' + state.game.id + ' (' + formatLabel(state.game.format) + ', ' + deckDescription + ')';

        const inProgressArea = document.getElementById('in-progress-area');
        const startButton = document.getElementById('start-game-button');

        renderList(
            document.getElementById('players-list'),
            { hidden: true }, // players are never empty
            state.players,
            (player) => {
                const li = document.createElement('li');
                const isTurn = state.round && state.round.current_turn_game_player_id === player.game_player_id;
                const wentFirst = state.round && state.round.went_first_game_player_id === player.game_player_id;
                const hasHurtFeelings = state.round && state.round.hurt_feelings_game_player_id === player.game_player_id;
                // Each custom_duel player has their OWN deck (unlike every
                // other deck_type, where one label covers the whole game --
                // see the board title itself), so that label belongs on
                // this per-player row instead once the game has actually
                // started (in_play/hand card counts already differ per
                // player here for the same reason).
                const deckLabel = state.game.deck_type === 'custom_duel' && state.game.status !== 'waiting'
                    ? ' — deck: ' + (player.custom_deck_name || 'Uploaded Deck')
                    : '';
                // Open Team Play's own team_id (null in every other format)
                // -- tags each row with which team it's on, and calls out
                // the viewer's own teammate specifically since that's the
                // one other player whose hand they can actually see (see
                // the teammate-hand section below).
                const isTeammate = state.you.teammate_game_player_id === player.game_player_id;
                const teamLabel = player.team_id !== null
                    ? ' — Team ' + (player.team_id + 1) + (isTeammate ? ' (your teammate)' : '')
                    : '';

                const infoEl = document.createElement('span');
                infoEl.textContent = player.username + ' — seat ' + player.seat_order +
                    ', ' + player.total_score + ' point(s), ' + player.total_wins + ' win(s), ' +
                    player.hand_count + ' card(s) in hand' + deckLabel + teamLabel +
                    (wentFirst ? ' — went first this round' : '') +
                    (isTurn ? ' — on turn' : '');
                li.appendChild(infoEl);

                // A small Hurt Feelings art thumbnail replaces the old plain
                // text tag -- Hurt Feelings is a round-level marker/token,
                // not a cards row (see migration 0003's header comment), so
                // its art lives at img/hurt-feelings.webp rather than under
                // img/cards/ and has no catalog_card_id to build a
                // card-detail-dialog-style view from; clicking it opens the
                // same generic art-preview dialog used for that reason.
                if (hasHurtFeelings) {
                    const alt = player.username + ' has Hurt Feelings this round (2 plays).';
                    const thumb = document.createElement('button');
                    thumb.type = 'button';
                    thumb.className = 'hurt-feelings-thumb';
                    thumb.title = alt;
                    thumb.addEventListener('click', () => openArtPreview('../img/hurt-feelings.webp', alt));
                    const img = document.createElement('img');
                    img.src = '../img/hurt-feelings.webp';
                    img.alt = 'Hurt Feelings';
                    thumb.appendChild(img);
                    li.appendChild(thumb);
                }

                return li;
            }
        );

        renderTeamScores(state.teams);

        if (state.game.status === 'waiting') {
            document.getElementById('board-round-status').textContent = 'Waiting for the game to start.';
            inProgressArea.hidden = true;

            if (state.game.deck_type === 'custom_duel') {
                renderDuelDeckSubmission(state);
                startButton.hidden = !state.players.every((p) => p.deck_submitted);
            } else {
                document.getElementById('duel-deck-submission').hidden = true;
                startButton.hidden = false;
            }

            return;
        }

        startButton.hidden = true;
        document.getElementById('duel-deck-submission').hidden = true;
        inProgressArea.hidden = false;

        if (state.game.status === 'completed') {
            const winnerNames = state.game.winner_usernames && state.game.winner_usernames.length
                ? state.game.winner_usernames.join(' & ')
                : 'nobody';
            document.getElementById('board-round-status').textContent =
                'Game over — ' + winnerNames + ' won.';
        } else {
            document.getElementById('board-round-status').textContent =
                'Round ' + state.round.round_number +
                (state.you.is_your_turn ? ' — your turn' : ' — waiting on another player');
        }

        const pendingDecision = state.round && state.round.pending_decision;
        renderPendingDecision(pendingDecision);
        renderScoringPreview(state.round && state.round.scoring_preview);
        renderScoringEffects(state.round && state.round.scoring_effects);
        renderBoardEffects(state.round && state.round.board_effects);

        renderInPlay(state);

        // A pending decision freezes the whole round -- nobody, including
        // the player whose turn it nominally is, can play or pass until
        // the targeted player has answered it.
        const canAct = state.game.status === 'in_progress' && state.you.is_your_turn && !pendingDecision;

        document.getElementById('discard-count').textContent = state.discard_pile.length;
        document.getElementById('deck-count').textContent = state.deck_count;
        renderList(document.getElementById('discard-list'), { hidden: true }, state.discard_pile, (card) => {
            const li = document.createElement('li');
            // last_owner_name disambiguates two players' identical catalog
            // cards both sitting in the shared discard pile at once (see
            // the 'discard_card' case in fieldOptions() below) -- shown
            // here too so the thumbnail itself isn't ambiguous either.
            const caption = card.last_owner_name || null;
            // Almost always just informational (a discard-pile card can't
            // normally be played), but Angst/Harmony/Grief's discard-sourced
            // extra play, or Melancholy's "play from the discard pile as
            // though it were your hand," can make a specific one playable
            // for the rest of this turn -- is_playable already reflects
            // that (see GameService::getState()'s discard_pile mapping), so
            // route straight to the same Play/Cancel panel a hand card
            // uses instead of the read-only detail view in that case.
            if (canAct && card.is_playable) {
                li.appendChild(buildCardThumb(card, { caption, onClick: () => handleHandCardClick(card) }));
            } else {
                li.appendChild(buildCardThumb(card, { caption, onClick: () => openCardDetail(card) }));
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
                li.appendChild(buildCardThumb(card, { onClick: () => openCardDetail(card) }));
                return li;
            }
            // Whether it's the viewer's own turn or not, a card sitting in
            // their own hand is never hidden *from them* -- only playing it
            // is turn-gated. When canAct is false (not their turn, or a
            // pending decision elsewhere is freezing the round), route to
            // the same read-only detail view in-play/discard-pile cards
            // already get instead of disabling the button outright, the
            // same split the discard-pile list above already makes.
            if (!canAct) {
                li.appendChild(buildCardThumb(card, { onClick: () => openCardDetail(card) }));
                return li;
            }
            // is_playable reflects whether some outstanding play grant this
            // turn actually covers this specific card (e.g. Intimidation's
            // grant only covers the one card it revealed) and, if the card
            // has a "to play" cost, whether that cost is payable at all --
            // see MoodPlayService::isPlayable(). The hand thumbnail stays
            // clickable either way, so the card's rules text can still be
            // inspected -- only the panel's own Play button (see
            // updatePlayButtonEnabled()) is actually gated on this.
            li.appendChild(buildCardThumb(card, {
                onClick: () => handleHandCardClick(card),
                notPlayable: !card.is_playable,
            }));
            return li;
        });

        renderTeammateHand(state);
        renderTeamDecision(state.team_decision);
        renderInitialCardPass(state);

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

    // Open Team Play's own team totals (null in every other format) --
    // teams is only populated once the game is in_progress/completed (see
    // GameService::getState()'s early return for 'waiting'), so the
    // partner pairing itself is only visible via each player row's own
    // team_id tag (see the players-list renderer above) until then.
    function renderTeamScores(teams) {
        const container = document.getElementById('team-scores');
        if (!teams) {
            container.hidden = true;
            return;
        }

        container.hidden = false;
        renderList(document.getElementById('team-scores-list'), { hidden: true }, teams, (team) => {
            const li = document.createElement('li');
            const memberNames = team.game_player_ids.map(playerLabelFor).join(' & ');
            li.textContent = 'Team ' + (team.team_id + 1) + ' (' + memberNames + ') — ' +
                team.total_score + ' point(s) this round, ' + team.total_wins + ' round win(s)';
            return li;
        });
    }

    // Open Team Play's "open information" premise -- see
    // php-app/README.md -- shows the viewer's own teammate's hand
    // read-only, the same way in-play/discard-pile cards are shown to
    // everyone: clicking a card opens the ordinary detail view rather than
    // anything playable, since only the teammate who actually holds a card
    // can play it.
    function renderTeammateHand(state) {
        const section = document.getElementById('teammate-hand-section');
        const teammateHand = state.you.teammate_hand;
        if (!teammateHand) {
            section.hidden = true;
            return;
        }

        section.hidden = false;
        document.getElementById('teammate-hand-title').textContent =
            playerLabelFor(state.you.teammate_game_player_id) + "'s hand";
        renderList(document.getElementById('teammate-hand-list'), { hidden: true }, teammateHand, (card) => {
            const li = document.createElement('li');
            li.appendChild(buildCardThumb(card, { onClick: () => openCardDetail(card) }));
            return li;
        });
    }

    // Open Team Play's own turn_order/draw_recipient decisions -- see
    // "Open Team Play" in php-app/README.md. Either teammate proposes who
    // should act; the OTHER teammate then approves or rejects before it's
    // locked in (game_team_decisions' own propose/confirm phases) --
    // mirrors renderPendingDecision()'s shape but reads state.team_decision
    // (a top-level field, not round.pending_decision) since this isn't a
    // card-effect decision and has no played_card_id/afterPlaying() tie-in.
    function renderTeamDecision(decision) {
        const panel = document.getElementById('team-decision-panel');
        const confirmButton = document.getElementById('team-decision-confirm-button');
        const rejectButton = document.getElementById('team-decision-reject-button');

        if (!decision) {
            panel.hidden = true;
            confirmButton.hidden = true;
            rejectButton.hidden = true;
            return;
        }

        panel.hidden = false;

        // Every viewer in the game receives the same team_decision (see
        // GameService::getState()), including the team that ISN'T making
        // the decision -- so "your team"/"your teammate" phrasing is only
        // correct when decision.team_id actually matches the viewer's own
        // team. can_propose/can_confirm are already false for an outside
        // viewer, but the wording still needs to branch explicitly so it
        // doesn't claim a stranger is "your teammate".
        const viewer = currentState.players.find((p) => p.game_player_id === currentState.you.game_player_id);
        const isOwnTeam = viewer && viewer.team_id === decision.team_id;

        const titlesByDecisionType = isOwnTeam
            ? { turn_order: "Your team's turn", draw_recipient: "Your team's shared draw" }
            : { turn_order: "Opposing team's turn", draw_recipient: "Opposing team's shared draw" };
        document.getElementById('team-decision-title').textContent =
            titlesByDecisionType[decision.decision_type] || 'Team decision';

        const actionDescription = decision.decision_type === 'draw_recipient'
            ? 'draw the shared card'
            : 'go next';
        const statusEl = document.getElementById('team-decision-status');
        const candidatesContainer = document.getElementById('team-decision-candidates');
        candidatesContainer.innerHTML = '';

        if (decision.phase === 'propose') {
            confirmButton.hidden = true;
            rejectButton.hidden = true;

            if (decision.can_propose) {
                statusEl.textContent = 'Choose who should ' + actionDescription + ':';
                decision.candidate_game_player_ids.forEach((gamePlayerId) => {
                    candidatesContainer.appendChild(actionButton(
                        playerLabelFor(gamePlayerId),
                        () => submitTeamProposal(gamePlayerId)
                    ));
                });
            } else {
                statusEl.textContent = 'Waiting for ' +
                    decision.candidate_game_player_ids.map(playerLabelFor).join(' or ') +
                    ' to choose who should ' + actionDescription + '.';
            }
            return;
        }

        // phase === 'confirm'
        const proposedLabel = playerLabelFor(decision.proposed_game_player_id);
        if (decision.can_confirm) {
            statusEl.textContent = playerLabelFor(decision.proposer_game_player_id) +
                ' proposed ' + proposedLabel + ' to ' + actionDescription + '. Do you agree?';
            confirmButton.hidden = false;
            rejectButton.hidden = false;
        } else if (isOwnTeam) {
            statusEl.textContent = 'Waiting for your teammate to confirm that ' +
                proposedLabel + ' should ' + actionDescription + '.';
            confirmButton.hidden = true;
            rejectButton.hidden = true;
        } else {
            statusEl.textContent = 'Waiting for ' + playerLabelFor(decision.proposer_game_player_id) +
                "'s team to confirm that " + proposedLabel + ' should ' + actionDescription + '.';
            confirmButton.hidden = true;
            rejectButton.hidden = true;
        }
    }

    async function submitTeamProposal(proposedGamePlayerId) {
        boardError.hidden = true;
        boardMessage.hidden = true;
        const { ok, body } = await proposeTeamDecision(currentGameId, proposedGamePlayerId);
        if (!ok) {
            boardError.textContent = body.message || 'Could not submit that proposal.';
            boardError.hidden = false;
            return;
        }
        await refreshBoard();
    }

    document.getElementById('team-decision-confirm-button').addEventListener('click', async () => {
        boardError.hidden = true;
        boardMessage.hidden = true;
        const { ok, body } = await confirmTeamDecision(currentGameId, true);
        if (!ok) {
            boardError.textContent = body.message || 'Could not confirm that decision.';
            boardError.hidden = false;
            return;
        }
        await refreshBoard();
    });

    document.getElementById('team-decision-reject-button').addEventListener('click', async () => {
        boardError.hidden = true;
        boardMessage.hidden = true;
        const { ok, body } = await confirmTeamDecision(currentGameId, false);
        if (!ok) {
            boardError.textContent = body.message || 'Could not reject that decision.';
            boardError.hidden = false;
            return;
        }
        await refreshBoard();
    });

    // 'closed_team's own pregame blind card pass -- see "Closed Team Play"
    // in php-app/README.md. Tracks which 2 of the viewer's own hand cards
    // are currently selected (by card_id, the per-game instance id every
    // other card action already keys off of) purely client-side; nothing
    // is sent until the submit button is pressed. Re-rendered from scratch
    // every refreshBoard() poll, same as every other panel here -- the
    // selection itself is deliberately NOT preserved across a poll (the
    // panel disappears entirely once submitted, so there's nothing left to
    // preserve for).
    let initialCardPassSelection = new Set();

    function renderInitialCardPass(state) {
        const panel = document.getElementById('initial-card-pass-panel');
        const pass = state.initial_card_pass;
        const submitButton = document.getElementById('initial-card-pass-submit-button');
        const fieldsContainer = document.getElementById('initial-card-pass-fields');

        if (!pass) {
            panel.hidden = true;
            submitButton.hidden = true;
            fieldsContainer.innerHTML = '';
            return;
        }

        panel.hidden = false;
        const statusEl = document.getElementById('initial-card-pass-status');

        if (pass.you_submitted) {
            const waitingOn = state.players
                .filter((p) => !pass.submitted_game_player_ids.includes(p.game_player_id))
                .map((p) => p.username);
            statusEl.textContent = waitingOn.length
                ? 'Waiting for ' + waitingOn.join(', ') + ' to pass their cards.'
                : 'Everyone has passed their cards -- starting the round.';
            submitButton.hidden = true;
            fieldsContainer.innerHTML = '';
            return;
        }

        statusEl.textContent = "Choose 2 cards from your hand to pass to your teammate, face down -- you won't see what they passed you until you do. Tap a card to view it and select/de-select it.";
        fieldsContainer.innerHTML = '';

        (state.you.hand || []).forEach((card) => {
            const selected = initialCardPassSelection.has(card.card_id);
            const thumb = buildCardThumb(card, {
                onClick: () => openCardDetail(card, null, {
                    selected,
                    disabled: !selected && initialCardPassSelection.size >= 2,
                    onToggle: () => {
                        if (initialCardPassSelection.has(card.card_id)) {
                            initialCardPassSelection.delete(card.card_id);
                        } else {
                            initialCardPassSelection.add(card.card_id);
                        }
                        renderInitialCardPass(currentState);
                    },
                }),
            });
            thumb.classList.toggle('selected', selected);
            fieldsContainer.appendChild(thumb);
        });

        submitButton.hidden = false;
        submitButton.disabled = initialCardPassSelection.size !== 2;
    }

    document.getElementById('initial-card-pass-submit-button').addEventListener('click', async () => {
        boardError.hidden = true;
        boardMessage.hidden = true;
        const { ok, body } = await submitInitialCardPass(currentGameId, Array.from(initialCardPassSelection));
        if (!ok) {
            boardError.textContent = body.message || 'Could not pass those cards.';
            boardError.hidden = false;
            return;
        }
        initialCardPassSelection = new Set();
        await refreshBoard();
    });

    // The 'custom_duel' waiting-room view: shows the creator's own locked-
    // in deck-building rules, both players' submission status (never the
    // decklist contents themselves -- see deck_submitted's own docblock in
    // GameService::getState()), and, if the viewer hasn't submitted yet, a
    // file/textarea submission form matching the 'custom' deck_type's own
    // (see #new-game-decklist-fields). Start game itself stays hidden
    // until every player has submitted -- see renderBoard()'s own caller.
    function renderDuelDeckSubmission(state) {
        document.getElementById('duel-deck-submission').hidden = false;

        const rules = state.game.duel_deck_rules;
        const rarityLimitsText = RARITIES
            .filter((rarity) => rules.rarity_limits[rarity] !== undefined)
            .map((rarity) => 'at most ' + rules.rarity_limits[rarity] + ' ' + rarity)
            .join(', ');
        const duplicateLimitsText = RARITIES
            .filter((rarity) => rules.duplicate_limits[rarity] !== undefined)
            .map((rarity) => 'at most ' + rules.duplicate_limits[rarity] + ' cop' + (rules.duplicate_limits[rarity] === 1 ? 'y' : 'ies') + ' of any ' + rarity + ' card')
            .join(', ');
        const evenDistributionText = rules.even_color_distribution_rarities
            .map((rarity) => rarity + ' cards split evenly across all 5 colors')
            .join(', ');
        document.getElementById('duel-deck-rules-summary').textContent =
            'At least ' + rules.min_cards + ' cards.' +
            (rarityLimitsText ? ' At most ' + rarityLimitsText.replace(/^at most /, '') + '.' : '') +
            (duplicateLimitsText ? ' At most ' + duplicateLimitsText.replace(/^at most /, '') + '.' : '') +
            (evenDistributionText ? ' ' + evenDistributionText.charAt(0).toUpperCase() + evenDistributionText.slice(1) + '.' : '');

        renderList(
            document.getElementById('duel-deck-submission-status'),
            { hidden: true }, // always exactly 2 players in a duel
            state.players,
            (player) => {
                const li = document.createElement('li');
                li.textContent = player.username + ' — ' +
                    (player.deck_submitted ? 'submitted' + (player.custom_deck_name ? ' (' + player.custom_deck_name + ')' : '') : 'waiting for a decklist');
                return li;
            }
        );

        const you = state.players.find((p) => p.game_player_id === state.you.game_player_id);
        document.getElementById('duel-deck-submit-form-container').hidden = you.deck_submitted;
        document.getElementById('duel-deck-submitted-message').hidden = !you.deck_submitted;
        if (you.deck_submitted) {
            document.getElementById('duel-deck-submitted-message').textContent =
                'You submitted: ' + (you.custom_deck_name || 'Uploaded Deck');
        }
    }

    document.getElementById('duel-deck-submit-file').addEventListener('change', async (event) => {
        const file = event.target.files[0];
        if (!file) {
            return;
        }

        document.getElementById('duel-deck-submit-text').value = await file.text();
    });

    document.getElementById('duel-deck-submit-button').addEventListener('click', async () => {
        const submitError = document.getElementById('duel-deck-submit-error');
        submitError.hidden = true;

        const decklistText = document.getElementById('duel-deck-submit-text').value;
        const { ok, body } = await submitCustomDuelDeck(currentGameId, decklistText);

        if (!ok) {
            submitError.textContent = body.message || 'Could not submit that decklist.';
            submitError.hidden = false;
            return;
        }

        await refreshBoard();
    });

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
        return true;
    }

    function fieldOptions(field, card) {
        switch (field.type) {
            case 'player':
                // candidate_player_ids (e.g. Pride's pending decision) is a
                // server-computed exact candidate list, sourced from the
                // real post-play board -- takes priority over scope/filter
                // narrowing the same way 'mood'/candidate_card_ids does
                // below, since it's already authoritative.
                if (field.candidate_player_ids) {
                    return currentState.players
                        .filter((p) => field.candidate_player_ids.includes(p.game_player_id))
                        .map((p) => ({ value: p.game_player_id, label: p.username }));
                }
                return currentState.players
                    .filter((p) => field.scope !== 'other' || p.game_player_id !== currentState.you.game_player_id)
                    // excludes_teammate (see CardChoiceSchema.php's own
                    // docblock): a handful of cards say "opponent," not
                    // "another player" -- in Open Team Play, your own
                    // teammate isn't a legal choice for these even though
                    // scope: 'other' alone wouldn't exclude them. A no-op
                    // outside team format, since teammate_game_player_id
                    // is undefined there.
                    .filter((p) => !field.excludes_teammate || p.game_player_id !== currentState.you.teammate_game_player_id)
                    .filter((p) => matchesPlayerFilter(p, field.filter))
                    .map((p) => ({ value: p.game_player_id, label: p.username }));
            case 'mood':
                // ownerLabel drives buildFieldWidget()'s own <optgroup>
                // grouping below -- with 3+ players in play, a flat list
                // made it tedious to find a specific player's moods. It
                // also still disambiguates two players' identical catalog
                // cards (a 'duel' game gives each player their own deck) the
                // same way the old inline "— Owner" suffix used to, just
                // via the group label instead of repeating it on every
                // option.
                if (field.candidate_card_ids) {
                    return currentState.in_play
                        .filter((c) => field.candidate_card_ids.includes(c.card_id))
                        .map((c) => ({ value: c.card_id, label: cardLabel(c), ownerLabel: playerLabelFor(c.owner_game_player_id) }));
                }
                return currentState.in_play
                    .filter((c) => c.card_id !== card.card_id)
                    .filter((c) => {
                        if (field.scope === 'own') return c.owner_game_player_id === currentState.you.game_player_id;
                        if (field.scope === 'other') return c.owner_game_player_id !== currentState.you.game_player_id;
                        return true;
                    })
                    // excludes_teammate: see the 'player' case above --
                    // same handful-of-cards exception, just excluding
                    // moods the teammate owns instead of the teammate
                    // themselves.
                    .filter((c) => !field.excludes_teammate || c.owner_game_player_id !== currentState.you.teammate_game_player_id)
                    .filter((c) => matchesCardFilter(c, field.filter))
                    .map((c) => ({ value: c.card_id, label: cardLabel(c), ownerLabel: playerLabelFor(c.owner_game_player_id) }));
            case 'hand_card':
                // No owner suffix needed here -- every option is already
                // the viewer's own hand, and two identical physical copies
                // there (e.g. one received via Compulsion) are genuinely
                // interchangeable for any cost/choice that uses this field.
                return currentState.you.hand
                    .filter((c) => c.card_id !== card.card_id)
                    .filter((c) => matchesCardFilter(c, field.filter))
                    .map((c) => ({ value: c.card_id, label: cardLabel(c) }));
            case 'discard_card':
                // Same disambiguation as 'mood' above, using each card's
                // last-known owner (state.discard_pile[].last_owner_name --
                // see GameService::getState()) since the shared discard
                // pile itself has no current owner to read. Matters
                // functionally, not just cosmetically: Corruption's own
                // discard_card_ids field bottoms each cycled card onto its
                // *owner's* deck in a duel.
                return currentState.discard_pile
                    .filter((c) => matchesCardFilter(c, field.filter))
                    .map((c) => ({
                        value: c.card_id,
                        label: cardLabel(c) + (c.last_owner_name ? ' — ' + c.last_owner_name : ''),
                    }));
            case 'grant_choice':
                // Unlike every other case here, the options themselves are
                // already fully server-computed (GameService::
                // grantChoiceOptions(), reusing describePlayGrant()'s own
                // description text) -- this field only ever appears when
                // there are 2+ usable grants to choose between in the
                // first place, so there's nothing left to derive
                // client-side.
                return field.options;
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
            // 'grant_choice' (grant_source_card_id) reads differently from
            // every other optional field here: leaving it blank doesn't
            // mean "use no grant" (a play always uses one), just "no
            // preference which outstanding one" -- see MoodPlayService::
            // playMood()'s own fallback to "whichever comes first" -- so
            // "(any)" says what actually happens, where "(none)" would
            // misleadingly suggest declining to use a grant at all.
            select.appendChild(new Option(field.type === 'grant_choice' ? '(any)' : '(none)', ''));
        }

        const options = field.type === 'mode'
            ? field.options.map((value) => ({ value, label: capitalize(value).replace(/_/g, ' ') }))
            : fieldOptions(field, card);
        if (field.type === 'mood') {
            appendGroupedMoodOptions(select, options);
        } else {
            for (const option of options) {
                select.appendChild(new Option(option.label, option.value));
            }
        }
        return select;
    }

    // Groups a 'mood' field's <option>s into one <optgroup> per owner
    // (fieldOptions()'s 'mood' case stamps an ownerLabel onto each option
    // for exactly this) instead of one flat list -- with 3+ players in
    // play, picking a specific player's mood out of a single long list got
    // tedious. Groups appear in the order each owner's first option is
    // encountered (itself currentState.in_play's own order), not re-sorted
    // by seat, consistent with every other consumer of currentState.in_play
    // in this file.
    function appendGroupedMoodOptions(select, options) {
        const groupsByOwner = new Map();
        for (const option of options) {
            let group = groupsByOwner.get(option.ownerLabel);
            if (!group) {
                group = document.createElement('optgroup');
                group.label = option.ownerLabel;
                groupsByOwner.set(option.ownerLabel, group);
                select.appendChild(group);
            }
            group.appendChild(new Option(option.label, option.value));
        }
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
        const artEl = document.getElementById('choices-card-art');
        artEl.src = cardArtUrl(card);
        artEl.alt = card.name + '. ' + (card.rules_text || 'No ability.');

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

    // Board-wide, always-visible (not gated on any pending decision, unlike
    // scoring_preview above) summary of every in-play mood whose ability
    // changes how this round's scoring will work -- see
    // GameService::scoringEffectEntries().
    function renderScoringEffects(effects) {
        const container = document.getElementById('scoring-effects');
        const entries = effects || [];
        container.hidden = entries.length === 0;
        renderList(document.getElementById('scoring-effects-list'), { hidden: true }, entries, (effect) => {
            const li = document.createElement('li');
            li.textContent = effect.description;
            return li;
        });
    }

    // Board-wide, always-visible summary of every in-play mood whose
    // ability reshapes every mood on the board (not just scoring) --
    // currently just Imagination overriding every mood's color -- see
    // GameService::boardEffectEntries().
    function renderBoardEffects(effects) {
        const container = document.getElementById('board-effects');
        const entries = effects || [];
        container.hidden = entries.length === 0;
        renderList(document.getElementById('board-effects-list'), { hidden: true }, entries, (effect) => {
            const li = document.createElement('li');
            li.textContent = effect.description;
            return li;
        });
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
            // choices-validation already sits right next to the Play
            // button (used for client-side field checks in
            // updatePlayButtonEnabled()), so a server-side rejection
            // reuses that same spot instead of board-error -- which is
            // easy to miss, sitting above the hand, while the player's
            // attention is still on the choices panel they were just
            // filling in.
            const validationMessage = document.getElementById('choices-validation');
            validationMessage.textContent = body.message || 'Could not play that card.';
            validationMessage.hidden = false;
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
