(function () {
    const messageEl = document.getElementById('maintenance-message');
    const retryButton = document.getElementById('maintenance-retry-button');

    // Falls back to the hardcoded message/path already in the HTML if
    // sessionStorage is unavailable (e.g. private browsing) or this page
    // was visited directly rather than via apiRequest()'s 503 redirect.
    let returnTo = '/';
    try {
        const storedMessage = sessionStorage.getItem('maintenanceMessage');
        if (storedMessage) {
            messageEl.textContent = storedMessage;
        }
        returnTo = sessionStorage.getItem('maintenanceReturnTo') || '/';
    } catch (e) {
        // Ignore -- keep the defaults above.
    }

    retryButton.addEventListener('click', () => {
        window.location.href = returnTo;
    });

    // Raw fetch rather than apiRequest(), so this poll can't itself trigger
    // apiRequest()'s own maintenance-redirect handling while already on the
    // maintenance page.
    setInterval(async () => {
        try {
            const response = await fetch('/app/me', { credentials: 'same-origin' });
            if (response.status !== 503) {
                window.location.href = returnTo;
            }
        } catch (e) {
            // Still unreachable -- keep polling.
        }
    }, 15000);
})();
