(function () {
    getCurrentUser();

    const form = document.getElementById('reset-password-form');
    const errorEl = document.getElementById('reset-password-error');
    const successEl = document.getElementById('reset-password-success');

    // The token is only read here, never submitted until the user actually
    // chooses a new password -- see sendPasswordResetEmail()'s docblock in
    // php-app/public/index.php for why this link isn't a token-consuming GET
    // route.
    const token = new URLSearchParams(window.location.search).get('token') || '';

    if (token === '') {
        form.querySelectorAll('input, button').forEach((el) => { el.disabled = true; });
        errorEl.textContent = 'This password reset link is missing its token. Please request a new one.';
        errorEl.hidden = false;
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorEl.hidden = true;
        successEl.hidden = true;

        const password = document.getElementById('reset-password-new').value;
        const passwordConfirm = document.getElementById('reset-password-confirm').value;

        if (password !== passwordConfirm) {
            errorEl.textContent = 'Passwords do not match.';
            errorEl.hidden = false;
            return;
        }

        const { ok, body } = await resetPassword(token, password);

        if (ok) {
            form.reset();
            successEl.textContent = body.message || 'Your password has been reset. You can now log in with your new password.';
            successEl.hidden = false;
            return;
        }

        errorEl.textContent = body.message || 'Could not reset your password.';
        errorEl.hidden = false;
    });
})();
