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

    const manilaParts = function (timestamp) {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
        }).formatToParts(new Date(timestamp));
        const value = function (type) { return parts.find(function (part) { return part.type === type; })?.value || ''; };
        const date = value('year') + '-' + value('month') + '-' + value('day');
        const time = value('hour') + ':' + value('minute') + ':' + value('second');
        const timestamp = Date.parse(date + 'T' + time + '+08:00');
        // Pareho sa server: minuto lang ang base ng 1-hour lead time.
        return { date: date, timestamp: timestamp - (timestamp % 60000) };
    };

    const manilaNow = function () {
        return manilaParts(Date.now());
    };

    const selectedTimeTimestamp = function (date, time) {
        return Date.parse(date + 'T' + time + ':00+08:00');
    };

    const isTimeAvailable = function (date, time, now) {
        if (!date || !time) return false;
        if (date > now.date) return true;
        if (date < now.date) return false;
        return selectedTimeTimestamp(date, time) >= now.timestamp + (60 * 60 * 1000);
    };

    const availableTimeSlots = function (slots, date, now) {
        return slots.filter(function (slot) {
            return isTimeAvailable(date, slot.value, now);
        });
    };

    const refreshTimeOptions = function (form, showTimeError) {
        const date = form.elements.preferred_date;
        const time = form.elements.preferred_time;
        const availability = form.querySelector('[data-schedule-time-availability]');
        const now = manilaNow();
        const slots = form._scheduleTimeSlots || Array.from(time.querySelectorAll('[data-schedule-time-option]')).map(function (option) {
            return { value: option.value, label: option.textContent };
        });
        form._scheduleTimeSlots = slots;
        const todayHasAvailableTime = availableTimeSlots(slots, now.date, now).length > 0;
        const noTimesToday = !todayHasAvailableTime;
        const minimumDate = todayHasAvailableTime
            ? now.date
            : manilaParts(now.timestamp + (24 * 60 * 60 * 1000)).date;

        date.min = minimumDate;
        if (date.value && date.value < minimumDate) {
            date.value = '';
        }

        const previousValue = time.value;
        const visibleSlots = date.value ? availableTimeSlots(slots, date.value, now) : [];
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = !previousValue || !visibleSlots.some(function (slot) { return slot.value === previousValue; });
        if (!date.value && noTimesToday) {
            placeholder.textContent = 'No available times remain today';
        } else if (!date.value) {
            placeholder.textContent = 'Select a date first';
        } else if (date.value === now.date && visibleSlots.length === 0) {
            placeholder.textContent = 'No available times remain today';
        } else {
            placeholder.textContent = 'Select time';
        }

        time.replaceChildren(placeholder);
        visibleSlots.forEach(function (slot) {
            const option = document.createElement('option');
            option.value = slot.value;
            option.textContent = slot.label;
            option.selected = slot.value === previousValue;
            time.append(option);
        });
        time.disabled = !date.value || visibleSlots.length === 0;

        if (availability) {
            availability.textContent = noTimesToday && (!date.value || date.value === now.date)
                ? 'No available times remain today. Please choose another date.'
                : '';
        }
        if (previousValue && !visibleSlots.some(function (slot) { return slot.value === previousValue; }) && showTimeError) {
            setFieldError(time, 'Choose an available preferred time.');
        }

        return now;
    };

    const validateReason = function (reason) {
        reason.value = reason.value.trim();
        const meaningfulLength = reason.value.replace(/\s+/g, '').length;
        const message = meaningfulLength >= 5 ? '' : 'Enter a reason with at least 5 characters.';
        setFieldError(reason, message);
        return message === '';
    };

    const validateDateTime = function (form) {
        const date = form.elements.preferred_date;
        const time = form.elements.preferred_time;
        const now = refreshTimeOptions(form, false);
        let valid = true;
        if (!date.value) {
            setFieldError(date, 'Choose a preferred date.');
            valid = false;
        } else {
            setFieldError(date, '');
        }
        if (!time.value) {
            setFieldError(time, 'Choose an available preferred time.');
            valid = false;
        } else if (!isTimeAvailable(date.value, time.value, now)) {
            setFieldError(time, 'Choose a time at least 1 hour from now.');
            valid = false;
        } else {
            setFieldError(time, '');
        }
        return valid;
    };

    const validateReschedule = function (form, shouldFocus) {
        const reason = form.elements.reason;
        const date = form.elements.preferred_date;
        const reasonValid = validateReason(reason);
        const dateTimeValid = validateDateTime(form);
        if (!reasonValid && shouldFocus) reason.focus();
        else if (!dateTimeValid && shouldFocus) date.focus();
        return reasonValid && dateTimeValid;
    };

    document.querySelectorAll('[data-schedule-reschedule-form]').forEach(function (form) {
        const reason = form.elements.reason;
        const date = form.elements.preferred_date;
        const time = form.elements.preferred_time;
        const hasSubmitAttempt = function () { return form.dataset.submitAttempted === '1'; };
        refreshTimeOptions(form, false);
        reason.addEventListener('input', function () { validateReason(reason); });
        date.addEventListener('change', function () {
            refreshTimeOptions(form, hasSubmitAttempt());
            if (hasSubmitAttempt()) validateDateTime(form);
        });
        time.addEventListener('change', function () {
            if (hasSubmitAttempt()) validateDateTime(form);
        });
        window.setInterval(function () { refreshTimeOptions(form, hasSubmitAttempt() && Boolean(time.value)); }, 60000);
    });

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (form.dataset.submitting === '1') return;
            if (form.matches('[data-schedule-reschedule-form]')) {
                form.dataset.submitAttempted = '1';
                if (!validateReschedule(form, true)) return;
            }

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
                    title.textContent = action === 'request_reschedule' ? 'Request Submitted' : 'Schedule Confirmed';
                    text.textContent = action === 'request_reschedule'
                        ? 'Your reschedule request has been sent to Admin. Your current inspection schedule remains unchanged while your request is being reviewed.'
                        : 'Your response has been recorded.';
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
