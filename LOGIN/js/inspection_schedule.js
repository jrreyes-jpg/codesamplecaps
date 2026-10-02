document.addEventListener('DOMContentLoaded', function () {
    const liveMessage = document.querySelector('[data-schedule-live-message]');
    const forms = document.querySelectorAll('[data-schedule-action-form], [data-schedule-reschedule-form]');

    const showMessage = function (message, type) {
        if (!liveMessage) return;
        liveMessage.textContent = message;
        liveMessage.className = 'schedule-live-message is-' + type;
        liveMessage.hidden = false;
    };

    const setFieldError = function (field, message) {
        const error = document.querySelector('[data-field-error="' + field.name + '"]');
        field.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (error) error.textContent = message || '';
    };

    const manilaNowMs = function () {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
        }).formatToParts(new Date());
        const value = function (type) { return parts.find(function (part) { return part.type === type; })?.value || ''; };
        return Date.parse(value('year') + '-' + value('month') + '-' + value('day') + 'T' + value('hour') + ':' + value('minute') + ':00+08:00');
    };

    const validateReason = function (reason) {
        reason.value = reason.value.trim();
        const meaningfulLength = reason.value.replace(/\s+/g, '').length;
        const message = meaningfulLength >= 5 ? '' : 'Enter a reason with at least 5 characters.';
        setFieldError(reason, message);
        return message === '';
    };

    const validateDateTime = function (date, time) {
        let valid = true;
        if (!date.value) {
            setFieldError(date, 'Choose a preferred date.');
            valid = false;
        } else {
            setFieldError(date, '');
        }
        if (!time.value) {
            setFieldError(time, 'Choose a preferred time.');
            valid = false;
        } else {
            setFieldError(time, '');
        }
        if (!valid) return false;

        const selectedMs = Date.parse(date.value + 'T' + time.value + ':00+08:00');
        if (!Number.isFinite(selectedMs) || selectedMs <= manilaNowMs()) {
            const message = 'Choose a future date and time.';
            setFieldError(date, message);
            setFieldError(time, message);
            return false;
        }
        setFieldError(date, '');
        setFieldError(time, '');
        return true;
    };

    const validateReschedule = function (form, shouldFocus) {
        const reason = form.elements.reason;
        const date = form.elements.preferred_date;
        const time = form.elements.preferred_time;
        const reasonValid = validateReason(reason);
        const dateTimeValid = validateDateTime(date, time);
        if (!reasonValid && shouldFocus) reason.focus();
        else if (!dateTimeValid && shouldFocus) (date.value ? time : date).focus();
        return reasonValid && dateTimeValid;
    };

    document.querySelectorAll('[data-schedule-reschedule-form]').forEach(function (form) {
        const reason = form.elements.reason;
        const date = form.elements.preferred_date;
        const time = form.elements.preferred_time;
        reason.addEventListener('input', function () { validateReason(reason); });
        date.addEventListener('change', function () { validateDateTime(date, time); });
        time.addEventListener('change', function () { validateDateTime(date, time); });
    });

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (form.dataset.submitting === '1') return;
            if (form.matches('[data-schedule-reschedule-form]') && !validateReschedule(form, true)) return;

            const button = form.querySelector('button[type="submit"]');
            const originalLabel = button?.textContent || '';
            const action = String(form.elements.action?.value || 'confirm');
            const payload = new FormData(form);
            if (!payload.has('action')) payload.set('action', action);
            form.dataset.submitting = '1';
            if (button) {
                button.disabled = true;
                button.textContent = action === 'request_reschedule' ? 'Sending Request...' : 'Confirming...';
            }

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: payload,
                credentials: 'same-origin',
            })
                .then(function (response) {
                    return response.json().then(function (data) { return { ok: response.ok, data: data }; });
                })
                .then(function (result) {
                    if (!result.ok || !result.data.success) throw new Error(result.data.message || 'Unable to save your response.');
                    showMessage(result.data.message, 'success');
                    const actionArea = form.closest('.schedule-actions');
                    if (!actionArea) return;
                    actionArea.className = 'schedule-response-state ' + (action === 'request_reschedule' ? 'schedule-response-state--reschedule' : 'schedule-response-state--confirmed');
                    const title = document.createElement('strong');
                    const text = document.createElement('p');
                    title.textContent = action === 'request_reschedule' ? 'Reschedule Request Sent' : 'Schedule Confirmed';
                    text.textContent = action === 'request_reschedule' ? 'A schedule change is waiting for Admin review. A new schedule will need confirmation.' : 'Your response has been recorded.';
                    actionArea.replaceChildren(title, text);
                })
                .catch(function (error) {
                    showMessage(error.message || 'Unable to save your response. Please try again.', 'error');
                    delete form.dataset.submitting;
                    if (button) {
                        button.disabled = false;
                        button.textContent = originalLabel;
                    }
                });
        });
    });
});
