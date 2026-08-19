(function () {
    getCurrentUser();

    const form = document.getElementById('resend-verification-form');
    const errorEl = document.getElementById('resend-verification-error');
    const successEl = document.getElementById('resend-verification-success');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorEl.hidden = true;
        successEl.hidden = true;

        const email = document.getElementById('resend-verification-email').value;

        const { ok, body } = await resendVerificationEmail(email);

        if (ok) {
            form.reset();
            successEl.textContent = body.message || 'If an account with that email exists and needs verification, a new email has been sent.';
            successEl.hidden = false;
            return;
        }

        errorEl.textContent = body.message || 'Something went wrong. Please try again.';
        errorEl.hidden = false;
    });
})();
