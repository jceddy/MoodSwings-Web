(async function () {
    const user = await getCurrentUser();
    if (!user) {
        window.location.replace('/');
        return;
    }

    document.getElementById('user-info-username').textContent = user.username;
    document.getElementById('user-main').hidden = false;
    startVersionWatcher();

    document.getElementById('user-back-to-lobby-button').addEventListener('click', () => {
        window.location.href = '../game/';
    });

    // Lifetime stats (issue #106) -- see GameService::lifetimeStatsFor()/
    // GET /user/stats. Self only for now; "Games" covers every format,
    // "Matches" is Quick/Winston/Grid Draft best-of-three results only
    // (see #user-stats-match-note in the HTML for why). *_win_percentage
    // is null (rather than a divide-by-zero 0%) until at least one
    // game/match has actually completed -- recordFormatted() leaves the
    // percentage off entirely in that case rather than showing a
    // misleading "0%".
    function recordFormatted(wins, losses, winPercentage) {
        return winPercentage === null
            ? `${wins}-${losses}`
            : `${wins}-${losses} (${winPercentage}%)`;
    }

    const { ok, body } = await getUserStats();
    if (ok) {
        const stats = body.stats;
        document.getElementById('user-stats-game-record').textContent =
            recordFormatted(stats.game_wins, stats.game_losses, stats.game_win_percentage);
        document.getElementById('user-stats-match-record').textContent =
            recordFormatted(stats.match_wins, stats.match_losses, stats.match_win_percentage);
    }

    // Online/presence indicator (issue #110) -- initialized from
    // getCurrentUser()'s own user.share_presence (already fetched above),
    // no separate GET needed. Saved immediately on change, same
    // auto-save-on-toggle pattern the lobby's own Notifications dialog
    // checkboxes use.
    const sharePresenceCheckbox = document.getElementById('share-presence-checkbox');
    sharePresenceCheckbox.checked = user.share_presence;
    sharePresenceCheckbox.addEventListener('change', () => {
        savePresencePreference(sharePresenceCheckbox.checked);
    });

    // Change password -- same "trim the form, show one message, don't
    // reveal which field was wrong beyond the server's own message" shape
    // reset-password.js's own form submit handler uses; the mismatch
    // check between the two new-password fields happens here, client-side
    // only, the same as that form's own confirm field.
    const changePasswordForm = document.getElementById('change-password-form');
    const changePasswordError = document.getElementById('change-password-error');
    const changePasswordSuccess = document.getElementById('change-password-success');
    changePasswordForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        changePasswordError.hidden = true;
        changePasswordSuccess.hidden = true;

        const currentPassword = document.getElementById('change-password-current').value;
        const newPassword = document.getElementById('change-password-new').value;
        const newPasswordConfirm = document.getElementById('change-password-confirm').value;

        if (newPassword !== newPasswordConfirm) {
            changePasswordError.textContent = 'New passwords do not match.';
            changePasswordError.hidden = false;
            return;
        }

        const { ok, body } = await changePassword(currentPassword, newPassword);

        if (ok) {
            changePasswordForm.reset();
            changePasswordSuccess.textContent = body.message || 'Your password has been changed.';
            changePasswordSuccess.hidden = false;
            return;
        }

        changePasswordError.textContent = body.message || 'Could not change your password.';
        changePasswordError.hidden = false;
    });
})();
