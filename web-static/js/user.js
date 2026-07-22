(async function () {
    const user = await getCurrentUser();
    if (!user) {
        window.location.replace('/');
        return;
    }

    document.getElementById('user-info-username').textContent = user.username;
    document.getElementById('user-main').hidden = false;
    startVersionWatcher();

    // Lifetime stats (issue #106) -- see GameService::lifetimeStatsFor()/
    // GET /user/stats. Self only for now; "Games" covers every format,
    // "Matches" is Quick/Winston/Grid Draft best-of-three results only
    // (see #user-stats-match-note in the HTML for why).
    const { ok, body } = await getUserStats();
    if (ok) {
        const stats = body.stats;
        document.getElementById('user-stats-game-record').textContent =
            `${stats.game_wins}-${stats.game_losses}`;
        document.getElementById('user-stats-match-record').textContent =
            `${stats.match_wins}-${stats.match_losses}`;
    }
})();
