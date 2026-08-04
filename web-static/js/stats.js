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
    const setFilterSelect = document.getElementById('stats-set-filter');
    const pageSizeSelect = document.getElementById('stats-page-size');
    const pageIndicator = document.getElementById('stats-page-indicator');
    const prevPageButton = document.getElementById('stats-prev-page-button');
    const nextPageButton = document.getElementById('stats-next-page-button');

    let cards = [];
    // Name ascending is the natural default first view -- an alphabetical
    // card list, same as the deck builder's own default ordering.
    let sortKey = 'name';
    let sortAscending = true;
    let setFilter = '';
    // Page size follows the <select>'s own markup rather than a separate
    // hardcoded default, so the two can never drift apart.
    let pageSize = parseInt(pageSizeSelect.value, 10);
    let currentPage = 1;

    // Rarity has an inherent order that alphabetical sorting gets wrong
    // (common, mythic, rare, uncommon) -- rank it print-frequency order
    // instead, from least to greatest rare.
    const RARITY_RANK = { common: 0, uncommon: 1, rare: 2, mythic: 3 };

    // Color sorts by the game's own WUBRG-style wheel order rather than
    // alphabetically (black, blue, green, red, white would carry no
    // meaning to a player).
    const COLOR_RANK = { white: 0, blue: 1, black: 2, red: 3, green: 4 };

    // The three draft-format columns hold {average, count} rather than a
    // plain number (see CardStatsService::averagePick()) -- sorting by
    // .average keeps a never-drafted card's null last regardless of
    // direction, rather than sorting arbitrarily against 0.
    function sortValue(card, key) {
        const value = card[key];
        if (key === 'rarity') {
            return RARITY_RANK[value];
        }
        if (key === 'color') {
            return COLOR_RANK[value];
        }
        if (value !== null && typeof value === 'object') {
            return value.average;
        }
        return value;
    }

    function compareCards(a, b) {
        const va = sortValue(a, sortKey);
        const vb = sortValue(b, sortKey);

        // Null (no data yet, or a card with no set_code/collector_number)
        // always sorts last, regardless of direction -- an
        // ascending/descending toggle should only ever reorder cards that
        // actually have a value for this column.
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

    // Every set code any fetched card carries, used both for the filter
    // dropdown's own options and (once picked) to narrow the table/download
    // -- built once per fetch rather than per render, since the full card
    // list never changes after the initial load.
    function populateSetFilterOptions() {
        const setCodes = [...new Set(cards.map((card) => card.set_code).filter((code) => code !== null))].sort();
        for (const code of setCodes) {
            const option = document.createElement('option');
            option.value = code;
            option.textContent = code;
            setFilterSelect.appendChild(option);
        }
    }

    function filteredCards() {
        return setFilter === '' ? cards : cards.filter((card) => card.set_code === setFilter);
    }

    // .slice() before .sort() so this never mutates the shared `cards`
    // array as a side effect of rendering -- .sort() sorts in place, and
    // filteredCards() returns `cards` itself (not a copy) when no set
    // filter is active, so without this every render (including a plain
    // page-navigation click that doesn't touch sortKey/sortAscending at
    // all) would silently resort the underlying source array.
    function sortedFilteredCards() {
        return filteredCards().slice().sort(compareCards);
    }

    // Marks the currently-sorted header with aria-sort (the standard
    // accessible way to convey this) so a CSS ::after rule (see
    // style.css) can draw an arrow indicating ascending/descending --
    // without this there was no on-screen way to tell whether a header
    // click just re-confirmed the current sort or flipped it, which
    // easily reads as "the sort changed on its own" while paging.
    function updateSortIndicators() {
        for (const header of document.querySelectorAll('#card-stats-table th[data-sort-key]')) {
            header.setAttribute('aria-sort', header.dataset.sortKey === sortKey ? (sortAscending ? 'ascending' : 'descending') : 'none');
        }
    }

    function renderTable() {
        updateSortIndicators();
        const sorted = sortedFilteredCards();
        const totalPages = Math.max(1, Math.ceil(sorted.length / pageSize));
        if (currentPage > totalPages) {
            currentPage = totalPages;
        }
        const start = (currentPage - 1) * pageSize;
        const pageCards = sorted.slice(start, start + pageSize);

        tableBody.replaceChildren();
        for (const card of pageCards) {
            const row = document.createElement('tr');

            const cells = [
                card.name,
                card.set_code === null ? '—' : card.set_code,
                card.collector_number === null ? '—' : String(card.collector_number),
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

        pageIndicator.textContent = 'Page ' + currentPage + ' of ' + totalPages + ' (' + sorted.length + ' card' + (sorted.length === 1 ? '' : 's') + ')';
        prevPageButton.disabled = currentPage <= 1;
        nextPageButton.disabled = currentPage >= totalPages;
    }

    for (const header of document.querySelectorAll('#card-stats-table th[data-sort-key]')) {
        header.addEventListener('click', () => {
            const key = header.dataset.sortKey;
            sortAscending = sortKey === key ? !sortAscending : true;
            sortKey = key;
            currentPage = 1;
            renderTable();
        });
    }

    setFilterSelect.addEventListener('change', () => {
        setFilter = setFilterSelect.value;
        currentPage = 1;
        renderTable();
    });

    pageSizeSelect.addEventListener('change', () => {
        pageSize = parseInt(pageSizeSelect.value, 10);
        currentPage = 1;
        renderTable();
    });

    prevPageButton.addEventListener('click', () => {
        currentPage = Math.max(1, currentPage - 1);
        renderTable();
    });

    nextPageButton.addEventListener('click', () => {
        currentPage = currentPage + 1;
        renderTable();
    });

    function triggerDownload(content, mimeType, filename) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(url);
    }

    // Downloads every card matching the current set filter (not just
    // whatever page is currently visible), sorted the same way the table
    // currently is, plus a "filters" block recording what was applied --
    // so the file is self-describing rather than leaving the reader to
    // guess whether it's the full catalog or a narrowed slice.
    document.getElementById('download-json-button').addEventListener('click', () => {
        const payload = {
            filters: { set: setFilter === '' ? null : setFilter },
            cards: sortedFilteredCards(),
        };
        triggerDownload(JSON.stringify(payload, null, 2), 'application/json', 'moodswings-card-stats.json');
    });

    // One column per raw field rather than the table's own formatted
    // display strings (e.g. "4.20 (1 pick)") -- a spreadsheet consumer
    // wants the average and count as separate numeric columns it can
    // compute on, not a pre-formatted phrase meant for on-screen reading.
    const CSV_COLUMNS = [
        ['catalog_card_id', (card) => card.catalog_card_id],
        ['name', (card) => card.name],
        ['set_code', (card) => card.set_code],
        ['collector_number', (card) => card.collector_number],
        ['rarity', (card) => card.rarity],
        ['color', (card) => card.color],
        ['times_in_deck', (card) => card.times_in_deck],
        ['deck_win_rate', (card) => card.deck_win_rate],
        ['times_played', (card) => card.times_played],
        ['play_win_rate', (card) => card.play_win_rate],
        ['quick_draft_average', (card) => card.quick_draft.average],
        ['quick_draft_count', (card) => card.quick_draft.count],
        ['winston_draft_average', (card) => card.winston_draft.average],
        ['winston_draft_count', (card) => card.winston_draft.count],
        ['grid_draft_average', (card) => card.grid_draft.average],
        ['grid_draft_count', (card) => card.grid_draft.count],
    ];

    // Per RFC 4180: a field containing a comma, double quote, or newline
    // is wrapped in double quotes, with any double quote inside it
    // doubled. A null/undefined value (e.g. a never-drafted card's
    // average) becomes an empty field rather than the literal text
    // "null".
    function csvField(value) {
        if (value === null || value === undefined) {
            return '';
        }
        const text = String(value);
        return /[",\r\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
    }

    function toCsv(cardsToExport) {
        const lines = [CSV_COLUMNS.map(([header]) => csvField(header)).join(',')];
        for (const card of cardsToExport) {
            lines.push(CSV_COLUMNS.map(([, value]) => csvField(value(card))).join(','));
        }
        return lines.join('\r\n');
    }

    // Same "every filtered card, current sort order" scope as the JSON
    // download -- no separate "filters" metadata here, since a CSV has
    // no natural place for a nested block and the header row already
    // says what each column is.
    document.getElementById('download-csv-button').addEventListener('click', () => {
        triggerDownload(toCsv(sortedFilteredCards()), 'text/csv', 'moodswings-card-stats.csv');
    });

    const { ok, body } = await getCardStats();
    if (ok) {
        cards = body.cards;
        populateSetFilterOptions();
        renderTable();
    }
})();
