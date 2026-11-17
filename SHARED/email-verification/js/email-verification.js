(function () {
    'use strict';

    function setState(options) {
        const input = options?.input;
        const status = options?.status;
        const verified = options?.verified === true;

        if (input) {
            input.readOnly = verified;
            input.classList.toggle('shared-email-verified', verified);
        }
        if (status) {
            status.textContent = '';
            status.hidden = true;
            status.classList.remove('is-verified');
        }
    }

    function clearAndFocus(options) {
        const input = options?.input;
        setState({ input, status: options?.status, verified: false });
        if (input) {
            input.value = '';
            input.focus({ preventScroll: true });
        }

        if (!options?.clearUrl) return Promise.resolve();

        const data = new FormData();
        data.append(options.tokenName || 'csrf_token', options.token || '');
        return fetch(options.clearUrl, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: data,
        }).catch(function () {
            // Server expiry ang backup kapag mahina ang internet.
        });
    }

    function isValidEmail(value) {
        const email = String(value || '').trim().toLowerCase();
        const format = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        const domain = email.includes('@') ? email.split('@').pop() : '';
        const commonTypos = new Set([
            'gmail.co', 'gmail.con', 'gmail.cm', 'gmai.com', 'gmial.com',
            'yahoo.co', 'yahoo.con', 'yaho.com',
            'outlook.co', 'outlook.con', 'hotmail.co', 'hotmail.con',
        ]);

        return format.test(email) && !commonTypos.has(domain);
    }

    window.edgeEmailVerificationUI = { setState, clearAndFocus, isValidEmail };
}());
