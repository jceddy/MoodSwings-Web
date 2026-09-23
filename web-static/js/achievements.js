(async function () {
    const user = await getCurrentUser();
    if (!user) {
        window.location.replace('/');
        return;
    }

    document.getElementById('achievements-main').hidden = false;
    startVersionWatcher();

    document.getElementById('achievements-back-to-lobby-button').addEventListener('click', () => {
        window.location.href = '../game/';
    });

    // Matches AchievementService's own DECK_CLUSTERS/category assignment
    // (php-app README's "Achievements" section) -- category letters are
    // never shown to the player, just used to order/label each section.
    const CATEGORY_NAMES = {
        A: 'Volume & Milestones',
        B: 'Format Mastery',
        C: 'Deck Type & Draft Mastery',
        D: 'Color & Rarity Mastery',
        E: 'In-Game Skill & Card Feats',
        F: 'Tournaments',
        G: 'Social & Account',
        H: 'Fun, Flavor & Meta',
        I: 'Card Cycles',
    };

    const categoryTemplate = document.getElementById('achievement-category-template');
    const rowTemplate = document.getElementById('achievement-row-template');
    const container = document.getElementById('achievements-categories');

    // "Hide locked achievements" (a plain client-side filter over data
    // already fetched -- no server round-trip) -- same localStorage-backed
    // per-device preference pattern as app.js's own THEME_STORAGE_KEY.
    // Re-renders from the last-fetched catalog rather than refetching, so
    // toggling it is instant.
    const HIDE_LOCKED_STORAGE_KEY = 'achievementsHideLocked';
    const hideLockedToggle = document.getElementById('hide-locked-toggle');
    let lastFetchedByCategory = null;

    try {
        hideLockedToggle.checked = localStorage.getItem(HIDE_LOCKED_STORAGE_KEY) === '1';
    } catch (e) {
        // localStorage unavailable (e.g. private browsing) -- leave the
        // checkbox on its default, unchecked.
    }

    hideLockedToggle.addEventListener('change', () => {
        try {
            localStorage.setItem(HIDE_LOCKED_STORAGE_KEY, hideLockedToggle.checked ? '1' : '0');
        } catch (e) {
            // ignore -- the preference just won't persist across reloads
        }
        if (lastFetchedByCategory) {
            render(lastFetchedByCategory);
        }
    });

    function buildRow(achievement) {
        const row = rowTemplate.content.firstElementChild.cloneNode(true);
        const unlocked = achievement.unlocked_at !== null;

        row.classList.toggle('achievement-unlocked', unlocked);
        row.classList.toggle('achievement-locked', !unlocked);
        row.classList.toggle('achievement-hidden', achievement.hidden && !unlocked);

        const tierBadge = row.querySelector('.achievement-tier-badge');
        tierBadge.textContent = achievement.tier;
        tierBadge.classList.add('achievement-tier-' + achievement.tier.toLowerCase());

        row.querySelector('.achievement-title').textContent = achievement.title;
        row.querySelector('.achievement-description').textContent = achievement.description;
        row.querySelector('.achievement-unlocked-check').hidden = !unlocked;

        if (achievement.target !== null && !unlocked) {
            const wrap = row.querySelector('.achievement-progress-wrap');
            wrap.hidden = false;
            const percent = Math.min(100, Math.round((achievement.progress / achievement.target) * 100));
            row.querySelector('.achievement-progress-fill').style.width = percent + '%';
            row.querySelector('.achievement-progress-label').textContent =
                `${achievement.progress} / ${achievement.target}`;
        }

        return row;
    }

    // Marks every currently-unlocked achievement as seen (drives
    // #achievements-button's own dot) and toasted (suppresses a redundant
    // toast for something the player just looked at) -- see
    // checkAchievementNotification() in game.js, which owns these same two
    // localStorage keys. Uses the latest unlocked_at actually present in
    // the catalog rather than the current time, so a clock difference
    // between browser and server can't leave a just-unlocked achievement
    // looking "unseen" again on the very next lobby poll.
    function markAchievementsSeen(byCategory) {
        const latestUnlockedAt = Object.values(byCategory)
            .flat()
            .filter((achievement) => achievement.unlocked_at !== null)
            .reduce((max, achievement) => (achievement.unlocked_at > max ? achievement.unlocked_at : max), '');

        try {
            localStorage.setItem('achievementsSeenAt', latestUnlockedAt);
            localStorage.setItem('achievementsToastedThroughAt', latestUnlockedAt);
        } catch (e) {
            // ignore -- the dot may reappear next poll, but nothing worse
        }
    }

    function render(byCategory) {
        lastFetchedByCategory = byCategory;
        container.innerHTML = '';

        let total = 0;
        let unlockedCount = 0;
        const hideLocked = hideLockedToggle.checked;

        // Catalog order (A-I) rather than object key insertion order, which
        // JSON round-tripping wouldn't guarantee for non-numeric string keys
        // anyway -- explicit is simplest here.
        for (const category of Object.keys(CATEGORY_NAMES)) {
            const achievements = byCategory[category];
            if (!achievements || achievements.length === 0) {
                continue;
            }

            total += achievements.length;
            unlockedCount += achievements.filter((achievement) => achievement.unlocked_at !== null).length;

            const visible = hideLocked
                ? achievements.filter((achievement) => achievement.unlocked_at !== null)
                : achievements;
            if (visible.length === 0) {
                continue;
            }

            const section = categoryTemplate.content.firstElementChild.cloneNode(true);
            section.querySelector('.achievement-category-title').textContent = CATEGORY_NAMES[category];
            const list = section.querySelector('.achievement-list');

            for (const achievement of visible) {
                list.appendChild(buildRow(achievement));
            }

            container.appendChild(section);
        }

        document.getElementById('achievements-summary').textContent =
            `${unlockedCount} / ${total} unlocked`;
    }

    const { ok, body } = await getAchievements();
    if (ok) {
        render(body.achievements);
        markAchievementsSeen(body.achievements);
    } else {
        document.getElementById('achievements-summary').textContent =
            'Could not load achievements right now.';
    }
})();
