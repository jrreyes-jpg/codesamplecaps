document.addEventListener('DOMContentLoaded', function () {
    const liveMessage = document.querySelector('[data-schedule-live-message]');
    const forms = document.querySelectorAll('[data-schedule-action-form], [data-schedule-reschedule-form]');
    const showMessage = function (message, type) { if (!liveMessage) return; liveMessage.textContent = message; liveMessage.className = 'schedule-live-message is-' + type; liveMessage.hidden = false; };
    const setFieldError = function (field, message) { const error = document.querySelector('[data-field-error="' + field.name + '"]'); field.setAttribute('aria-invalid', message ? 'true' : 'false'); if (error) error.textContent = message || ''; };
    const validateReschedule = function (form) {
        const reason = form.elements.reason; const date = form.elements.preferred_date; const time = form.elements.preferred_time; let firstInvalid = null;
        reason.value = reason.value.trim();
        if (reason.value.length < 5) { setFieldError(reason, 'Enter a reason with at least 5 characters.'); firstInvalid = reason; } else setFieldError(reason, '');
        if (Boolean(date.value) !== Boolean(time.value)) { const message = 'Enter both a preferred date and time.'; setFieldError(date, message); setFieldError(time, message); firstInvalid = firstInvalid || date; } else { setFieldError(date, ''); setFieldError(time, ''); }
        if (date.value && date.value < date.min) { setFieldError(date, 'Choose today or a future date.'); firstInvalid = firstInvalid || date; }
        if (firstInvalid) { firstInvalid.focus(); return false; } return true;
    };
    forms.forEach(function (form) { form.addEventListener('submit', function (event) {
        event.preventDefault(); if (form.dataset.submitting === '1') return;
        if (form.matches('[data-schedule-reschedule-form]') && !validateReschedule(form)) return;
        const button = form.querySelector('button[type="submit"]'); const originalLabel = button?.textContent || '';
        const action = String(form.elements.action?.value || 'confirm');
        form.dataset.submitting = '1'; if (button) { const spinner = document.createElement('span'); spinner.className = 'schedule-button__spinner'; spinner.setAttribute('aria-hidden', 'true'); button.disabled = true; button.replaceChildren(spinner, document.createTextNode(action === 'request_reschedule' ? 'Sending Request...' : 'Confirming...')); }
        const payload = new FormData(form);
        if (!payload.has('action')) payload.set('action', action);
        fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, body: payload, credentials: 'same-origin' })
            .then(function (response) { return response.json().then(function (data) { return { ok: response.ok, data: data }; }); })
            .then(function (result) {
                if (!result.ok || !result.data.success) throw new Error(result.data.message || 'Unable to save your response.');
                showMessage(result.data.message, 'success');
                const actionArea = form.closest('.schedule-actions');
                if (!actionArea) return;
                actionArea.className = 'schedule-response-state ' + (action === 'request_reschedule' ? 'schedule-response-state--reschedule' : 'schedule-response-state--confirmed');
                const title = document.createElement('strong'); const text = document.createElement('p');
                title.textContent = action === 'request_reschedule' ? 'Reschedule Request Sent' : '\u2713 Schedule Confirmed';
                text.textContent = action === 'request_reschedule' ? 'A schedule change is waiting for Admin review. A new schedule will need confirmation.' : 'Your response has been recorded.';
                actionArea.replaceChildren(title, text);
            })
            .catch(function (error) { showMessage(error.message || 'Unable to save your response. Please try again.', 'error'); form.dataset.submitting = '0'; if (button) { button.disabled = false; button.textContent = originalLabel; } });
    }); });
});
