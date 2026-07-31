'use strict';

// ── Comment form ──────────────────────────────────────────────────────────
// Posts to /comment over fetch so the page (and any realization already on
// screen) survives the submission. The server re-validates everything; the
// checks below only spare the user a round trip.
(function () {
    const form      = document.getElementById('comment-form');
    if (!form) return;

    const emailIn   = document.getElementById('comment-email');
    const bodyIn    = document.getElementById('comment-body');
    const emailErr  = document.getElementById('comment-email-error');
    const bodyErr   = document.getElementById('comment-body-error');
    const statusEl  = document.getElementById('comment-status');
    const submitBtn = document.getElementById('comment-submit');

    // Same shape as the strict RFC check the backend applies, kept loose
    // enough not to reject an address the server would happily accept.
    const EMAIL_RE = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;

    function setFieldError(input, errEl, message) {
        errEl.textContent = message || '';
        errEl.classList.toggle('visible', Boolean(message));
        input.classList.toggle('has-error', Boolean(message));
        input.setAttribute('aria-invalid', message ? 'true' : 'false');
    }

    function clearErrors() {
        setFieldError(emailIn, emailErr, '');
        setFieldError(bodyIn, bodyErr, '');
    }

    function setStatus(message, kind) {
        statusEl.textContent = message || '';
        statusEl.classList.toggle('is-success', kind === 'success');
        statusEl.classList.toggle('is-error', kind === 'error');
    }

    // Clear a field's error as soon as the user starts fixing it.
    [[emailIn, emailErr], [bodyIn, bodyErr]].forEach(([input, errEl]) => {
        input.addEventListener('input', () => setFieldError(input, errEl, ''));
    });

    function validateLocally() {
        const email = emailIn.value.trim();
        const body  = bodyIn.value.trim();
        let ok = true;

        if (!email) {
            setFieldError(emailIn, emailErr, TRANS.comment_email_required);
            ok = false;
        } else if (!EMAIL_RE.test(email)) {
            setFieldError(emailIn, emailErr, TRANS.comment_email_invalid);
            ok = false;
        }

        if (!body) {
            setFieldError(bodyIn, bodyErr, TRANS.comment_body_required);
            ok = false;
        }

        if (!ok) (emailIn.classList.contains('has-error') ? emailIn : bodyIn).focus();
        return ok;
    }

    form.addEventListener('submit', async ev => {
        ev.preventDefault();
        clearErrors();
        setStatus('', null);

        if (!validateLocally()) return;

        submitBtn.disabled = true;
        submitBtn.textContent = TRANS.comment_sending;

        try {
            const resp = await fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
            });
            const data = await resp.json().catch(() => null);

            if (resp.ok && data && data.success) {
                form.reset();
                setStatus(data.message || TRANS.comment_success, 'success');
            } else if (data && data.errors) {
                if (data.errors.email) setFieldError(emailIn, emailErr, data.errors.email);
                if (data.errors.body)  setFieldError(bodyIn, bodyErr, data.errors.body);
                setStatus(data.errors._ || '', data.errors._ ? 'error' : null);
            } else {
                setStatus(TRANS.comment_network, 'error');
            }
        } catch (_) {
            setStatus(TRANS.comment_network, 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = TRANS.comment_submit;
        }
    });
})();
