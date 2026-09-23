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

    function render(byCategory) {
        container.innerHTML = '';

        let total = 0;
        let unlockedCount = 0;

        // Catalog order (A-I) rather than object key insertion order, which
        // JSON round-tripping wouldn't guarantee for non-numeric string keys
        // anyway -- explicit is simplest here.
        for (const category of Object.keys(CATEGORY_NAMES)) {
            const achievements = byCategory[category];
            if (!achievements || achievements.length === 0) {
                continue;
            }

            const section = categoryTemplate.content.firstElementChild.cloneNode(true);
            section.querySelector('.achievement-category-title').textContent = CATEGORY_NAMES[category];
            const list = section.querySelector('.achievement-list');

            for (const achievement of achievements) {
                total++;
                if (achievement.unlocked_at !== null) {
                    unlockedCount++;
                }
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
    } else {
        document.getElementById('achievements-summary').textContent =
            'Could not load achievements right now.';
    }
})();
