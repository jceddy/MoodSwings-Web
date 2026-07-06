(async function () {
    const user = await getCurrentUser();
    if (user) {
        window.location.replace('/game/');
        return;
    }

    const form = document.getElementById('login-form');
    const errorEl = document.getElementById('login-error');

    form.hidden = false;
    document.getElementById('login-register-link').hidden = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorEl.hidden = true;

        const username = document.getElementById('login-username').value;
        const password = document.getElementById('login-password').value;

        const { ok, body } = await login(username, password);

        if (ok) {
            window.location.replace('/game/');
            return;
        }

        errorEl.textContent = body.message || 'Login failed.';
        errorEl.hidden = false;
    });
})();
