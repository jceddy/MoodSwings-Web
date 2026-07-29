(function () {
    getCurrentUser();

    const form = document.getElementById('forgot-password-form');
    const errorEl = document.getElementById('forgot-password-error');
    const successEl = document.getElementById('forgot-password-success');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorEl.hidden = true;
        successEl.hidden = true;

        const email = document.getElementById('forgot-password-email').value;

        const { ok, body } = await requestPasswordReset(email);

        if (ok) {
            form.reset();
            successEl.textContent = body.message || 'If an account with that email exists, a password reset link has been sent.';
            successEl.hidden = false;
            return;
        }

        errorEl.textContent = body.message || 'Something went wrong. Please try again.';
        errorEl.hidden = false;
    });
})();
