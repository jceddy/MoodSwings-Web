(function () {
    // Matches login.js/game.js's own first action on load: the result is
    // irrelevant here (a 401 is the normal, expected outcome for a
    // logged-out visitor), this call exists only so apiRequest() gets a
    // chance to detect and redirect on a pending maintenance window, since
    // register.html otherwise makes no API call until form submission.
    getCurrentUser();

    const form = document.getElementById('register-form');
    const errorEl = document.getElementById('register-error');
    const successEl = document.getElementById('register-success');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorEl.hidden = true;
        successEl.hidden = true;

        const username = document.getElementById('register-username').value;
        const email = document.getElementById('register-email').value;
        const phoneNumber = document.getElementById('register-phone').value;
        const password = document.getElementById('register-password').value;

        const { ok, body } = await register(username, email, password, phoneNumber);

        if (ok) {
            form.reset();
            successEl.textContent = body.message || 'Check your email to verify your account.';
            successEl.hidden = false;
            return;
        }

        errorEl.textContent = body.message || 'Registration failed.';
        errorEl.hidden = false;
    });
})();
