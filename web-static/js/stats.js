(async function () {
    const user = await getCurrentUser();
    if (!user) {
        window.location.replace('/');
        return;
    }

    document.getElementById('stats-main').hidden = false;
    startVersionWatcher();

    document.getElementById('stats-back-to-lobby-button').addEventListener('click', () => {
        window.location.href = '../game/';
    });

    const tableBody = document.getElementById('card-stats-body');

    let cards = [];
    // Name ascending is the natural default first view -- an alphabetical
    // card list, same as the deck builder's own default ordering.
    let sortKey = 'name';
    let sortAscending = true;

    // The three draft-format columns hold {average, count} rather than a
    // plain number (see CardStatsService::averagePick()) -- sorting by
    // .average keeps a never-drafted card's null last regardless of
    // direction, rather than sorting arbitrarily against 0.
    function sortValue(card, key) {
        const value = card[key];
        if (value !== null && typeof value === 'object') {
            return value.average;
        }
        return value;
    }

    function compareCards(a, b) {
        const va = sortValue(a, sortKey);
        const vb = sortValue(b, sortKey);

        // Null (no data yet) always sorts last, regardless of direction --
        // an ascending/descending toggle should only ever reorder cards
        // that actually have a value for this column.
        if (va === null && vb === null) {
            return 0;
        }
        if (va === null) {
            return 1;
        }
        if (vb === null) {
            return -1;
        }

        let result;
        if (typeof va === 'string') {
            result = va.localeCompare(vb);
        } else {
            result = va - vb;
        }

        return sortAscending ? result : -result;
    }

    function formatRate(rate) {
        return rate === null ? '—' : Math.round(rate * 100) + '%';
    }

    function formatPick(pick) {
        return pick.count === 0 ? '—' : pick.average.toFixed(2) + ' (' + pick.count + ' pick' + (pick.count === 1 ? '' : 's') + ')';
    }

    function renderTable() {
        const sorted = cards.slice().sort(compareCards);

        tableBody.replaceChildren();
        for (const card of sorted) {
            const row = document.createElement('tr');

            const cells = [
                card.name,
                card.rarity,
                card.color,
                String(card.times_in_deck),
                formatRate(card.deck_win_rate),
                String(card.times_played),
                formatRate(card.play_win_rate),
                formatPick(card.quick_draft),
                formatPick(card.winston_draft),
                formatPick(card.grid_draft),
            ];
            for (const text of cells) {
                const cell = document.createElement('td');
                cell.textContent = text;
                row.appendChild(cell);
            }

            tableBody.appendChild(row);
        }
    }

    for (const header of document.querySelectorAll('#card-stats-table th[data-sort-key]')) {
        header.addEventListener('click', () => {
            const key = header.dataset.sortKey;
            sortAscending = sortKey === key ? !sortAscending : true;
            sortKey = key;
            renderTable();
        });
    }

    const { ok, body } = await getCardStats();
    if (ok) {
        cards = body.cards;
        renderTable();
    }
})();
