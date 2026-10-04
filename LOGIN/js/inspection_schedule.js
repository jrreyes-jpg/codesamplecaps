document.addEventListener('DOMContentLoaded', function () {
    const forms = document.querySelectorAll('[data-schedule-action-form], [data-schedule-reschedule-form]');
    const modalElement = document.querySelector('[data-schedule-modal]');

    const scheduleModal = (function () {
        if (!modalElement) {
            return {
                confirm: function (options) {
                    if (typeof options.onCancel === 'function') options.onCancel();
                },
                notify: function () {},
                setSubmitting: function () {},
                close: function () {},
            };
        }

        const dialog = modalElement.querySelector('.schedule-modal__dialog');
        const title = modalElement.querySelector('[data-schedule-modal-title]');
        const message = modalElement.querySelector('[data-schedule-modal-message]');
        const closeButton = modalElement.querySelector('[data-schedule-modal-close]');
        const cancelButton = modalElement.querySelector('[data-schedule-modal-cancel]');
        const primaryButton = modalElement.querySelector('[data-schedule-modal-primary]');
        const backdrop = modalElement.querySelector('[data-schedule-modal-backdrop]');
        const details = modalElement.querySelector('[data-schedule-modal-details]');
        const actions = modalElement.querySelector('[data-schedule-modal-actions]');
        const requestForm = modalElement.querySelector('[data-schedule-reschedule-form]');
        if (!dialog || !title || !message || !closeButton || !cancelButton || !primaryButton || !backdrop || !details || !actions) {
            return {
                confirm: function (options) {
                    if (typeof options.onCancel === 'function') options.onCancel();
                },
                notify: function () {},
                setSubmitting: function () {},
                close: function () {},
            };
        }
        let activeModal = null;

        const getFocusable = function () {
            return Array.from(dialog.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
        };

        const close = function (isCancelled, forceClose) {
            if (!activeModal || (activeModal.submitting && !forceClose)) return;
            const currentModal = activeModal;
            activeModal = null;
            modalElement.hidden = true;
            if (requestForm) requestForm.hidden = true;
            actions.hidden = false;
            message.hidden = false;
            details.hidden = true;
            details.replaceChildren();
            document.body.classList.remove('is-schedule-modal-open');
            if (isCancelled && typeof currentModal.onCancel === 'function') currentModal.onCancel();
            if (currentModal.trigger && typeof currentModal.trigger.focus === 'function') currentModal.trigger.focus();
        };

        const open = function (options) {
            activeModal = {
                trigger: options.trigger || document.activeElement,
                onConfirm: options.onConfirm || null,
                onCancel: options.onCancel || null,
                submitting: false,
                isNotification: Boolean(options.isNotification),
            };
            title.textContent = options.title;
            message.textContent = options.message;
            message.hidden = false;
            details.replaceChildren();
            (options.details || []).forEach(function (detail) {
                const term = document.createElement('dt');
                const value = document.createElement('dd');
                term.textContent = detail.label;
                value.textContent = detail.value;
                details.append(term, value);
            });
            details.hidden = !(options.details || []).length;
            if (requestForm) requestForm.hidden = true;
            actions.hidden = false;
            primaryButton.textContent = options.primaryLabel || 'Close';
            primaryButton.className = 'schedule-modal__button schedule-modal__button--primary' + (options.tone === 'error' ? ' is-error' : '');
            cancelButton.hidden = Boolean(options.isNotification);
            closeButton.disabled = false;
            cancelButton.disabled = false;
            primaryButton.disabled = false;
            modalElement.hidden = false;
            document.body.classList.add('is-schedule-modal-open');
            window.setTimeout(function () {
                (options.isNotification ? primaryButton : primaryButton).focus();
            }, 0);
        };

        primaryButton.addEventListener('click', function () {
            if (!activeModal || activeModal.submitting) return;
            if (activeModal.isNotification) {
                close(false);
                return;
            }
            activeModal.submitting = true;
            if (typeof activeModal.onConfirm === 'function') activeModal.onConfirm();
        });

        closeButton.addEventListener('click', function () { close(true); });
        cancelButton.addEventListener('click', function () { close(true); });
        backdrop.addEventListener('click', function () { close(true); });
        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
                return;
            }
            if (event.key !== 'Tab') return;
            const focusable = getFocusable();
            if (focusable.length === 0) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        return {
            confirm: function (options) { open(options); },
            notify: function (options) { open(Object.assign({}, options, { isNotification: true, primaryLabel: 'Close' })); },
            openRequestForm: function (options) {
                if (!requestForm) return;
                activeModal = {
                    trigger: options.trigger || document.activeElement,
                    onConfirm: null,
                    onCancel: options.onCancel || null,
                    submitting: false,
                    isNotification: false,
                };
                title.textContent = options.title;
                message.hidden = true;
                details.hidden = true;
                details.replaceChildren();
                actions.hidden = true;
                requestForm.hidden = false;
                closeButton.disabled = false;
                modalElement.hidden = false;
                document.body.classList.add('is-schedule-modal-open');
                window.setTimeout(function () { requestForm.elements.reason?.focus(); }, 0);
            },
            setSubmitting: function (label) {
                if (!activeModal) return;
                primaryButton.disabled = true;
                primaryButton.textContent = label;
                closeButton.disabled = true;
                cancelButton.disabled = true;
            },
            close: function () { close(false, true); },
        };
    }());

    const showErrorModal = function (message) {
        scheduleModal.notify({ title: 'Submission Failed', message: message, tone: 'error' });
    };

    const requestScheduleButton = document.querySelector('[data-schedule-reschedule-open]');
    const requestScheduleForm = document.querySelector('[data-schedule-reschedule-form]');
    const requestScheduleCancel = document.querySelector('[data-schedule-reschedule-cancel]');

    const openRequestScheduleForm = function (trigger) {
        if (!requestScheduleForm) return;
        scheduleModal.openRequestForm({
            title: 'Request a Schedule Change',
            trigger: trigger,
        });
    };

    requestScheduleButton?.addEventListener('click', function () {
        openRequestScheduleForm(requestScheduleButton);
    });

    requestScheduleCancel?.addEventListener('click', function () {
        scheduleModal.close();
    });

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
const manilaTimestamp = Date.parse(date + 'T' + time + '+08:00');
        // Pareho sa server: minuto lang ang base ng 1-hour lead time.
        return { date: date, timestamp: manilaTimestamp - (manilaTimestamp % 60000)
 };
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

    const refreshTimeOptions = function (form) {
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
        const selectedTimeIsAvailable = previousValue !== '' && visibleSlots.some(function (slot) {
            return slot.value === previousValue;
        });
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = !selectedTimeIsAvailable;
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
        time.value = selectedTimeIsAvailable ? previousValue : '';
        time.disabled = !date.value || visibleSlots.length === 0;

        if (availability) {
            availability.textContent = noTimesToday && (!date.value || date.value === now.date)
                ? 'No available times remain today. Please choose another date.'
                : '';
        }
        if (previousValue && !selectedTimeIsAvailable) {
            // Tahimik na alisin ang lumang error kapag nagbago ang date.
            setFieldError(time, '');
        }

        return now;
    };

const validateReason = function (reason) {
    const meaningfulLength = reason.value.replace(/\s+/g, '').length;
    const message = meaningfulLength >= 5
        ? ''
        : 'Please provide at least 5 characters explaining your request.';

setFieldError(reason, message);
    return message === '';
};


    const validateDateTime = function (form) {
        const date = form.elements.preferred_date;
        const time = form.elements.preferred_time;
        const now = refreshTimeOptions(form);
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
        refreshTimeOptions(form);
        reason.addEventListener('input', function () { setFieldError(reason, ''); });
        reason.addEventListener('blur', function () { validateReason(reason); });
        date.addEventListener('change', function () {
            refreshTimeOptions(form);
            // Date change lang ito. Error ay lalabas lang sa Submit.
            setFieldError(date, '');
            setFieldError(time, '');
        });
        time.addEventListener('change', function () {
            if (hasSubmitAttempt()) validateDateTime(form);
        });
        window.setInterval(function () { refreshTimeOptions(form); }, 60000);
    });

    const submitResponse = function (form, action, button, originalLabel) {
const payload = new FormData(form);
        if (!payload.has('action')) payload.set('action', action);
        form.dataset.submitting = '1';
        scheduleModal.setSubmitting(action === 'request_reschedule' ? 'Submitting...' : 'Confirming...');
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
                scheduleModal.close();
                const actionArea = document.querySelector('[data-schedule-actions]');
                if (!actionArea) return;
                actionArea.className = 'schedule-response-state ' + (action === 'request_reschedule' ? 'schedule-response-state--reschedule' : 'schedule-response-state--confirmed');
                const title = document.createElement('strong');
                const text = document.createElement('p');
                title.textContent = action === 'request_reschedule' ? 'Pending Admin Review' : 'Schedule Confirmed';
                text.textContent = action === 'request_reschedule'
                    ? 'Your reschedule request has been sent to Admin. Your current inspection schedule remains unchanged while your request is being reviewed.'
                    : 'Your response has been recorded.';
                actionArea.replaceChildren(title, text);
                if (action === 'request_reschedule') {
                    const requested = document.createElement('p');
                    requested.className = 'schedule-response-state__requested';
                    const label = document.createElement('span');
                    const date = form.elements.preferred_date.value;
                    const time = form.elements.preferred_time.options[form.elements.preferred_time.selectedIndex]?.textContent || '';
                    label.textContent = 'Requested schedule';
                    requested.append(label, document.createTextNode(date + ' ' + time + ' PHT'));
                    actionArea.append(requested);
                    const reason = document.createElement('p');
                    reason.className = 'schedule-response-state__note';
                    reason.textContent = 'Your reason: ' + form.elements.reason.value;
                    actionArea.append(reason);
                    const pendingButton = document.createElement('button');
                    pendingButton.type = 'button';
                    pendingButton.className = 'schedule-button schedule-button--secondary schedule-response-state__pending-button';
                    pendingButton.disabled = true;
                    pendingButton.textContent = 'Request Pending';
                    actionArea.append(pendingButton);
                    scheduleModal.notify({
                        title: 'Request Sent',
                        message: 'Your schedule change request was sent to Admin for review.',
                        tone: 'success',
                    });
                } else {
                    scheduleModal.notify({
                        title: 'Schedule Confirmed',
                        message: 'Your schedule confirmation was recorded.',
                        tone: 'success',
                    });
                }
            })
            .catch(function (error) {
                const isNetworkError = error instanceof TypeError || error instanceof SyntaxError;
                delete form.dataset.submitting;
                if (button) {
                    button.disabled = false;
                    button.textContent = originalLabel;
                }
                scheduleModal.close();
                showErrorModal(
                    isNetworkError
                        ? 'Unable to submit your request. Please check your internet connection and try again.'
                        : (error.message || 'Unable to save your response. Please try again.')
                );
            });
    };

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (form.dataset.submitting === '1' || form.dataset.confirming === '1') return;
            if (form.matches('[data-schedule-reschedule-form]')) {
                form.dataset.submitAttempted = '1';
                if (!validateReschedule(form, true)) return;
            }

            const button = form.querySelector('button[type="submit"]');
            const originalLabel = button?.textContent || '';
            const action = String(form.elements.action?.value || 'confirm');
            const isRescheduleRequest = action === 'request_reschedule';
            form.dataset.confirming = '1';
            scheduleModal.confirm({
                title: isRescheduleRequest ? 'Submit Reschedule Request' : 'Confirm Inspection Schedule',
                message: isRescheduleRequest
                    ? 'Please check your request before sending it to Admin.'
                    : 'Confirm the official inspection schedule.',
                primaryLabel: isRescheduleRequest ? 'Submit Request' : 'Confirm Schedule',
                trigger: button,
                details: isRescheduleRequest ? [
                    { label: 'Official schedule', value: document.querySelector('[data-schedule-official-schedule]')?.textContent.trim() || 'Not set' },
                    { label: 'Requested date', value: form.elements.preferred_date.value },
                    { label: 'Requested time', value: form.elements.preferred_time.options[form.elements.preferred_time.selectedIndex]?.textContent || '' },
                    { label: 'Reason', value: form.elements.reason.value },
                ] : [],
                onCancel: function () {
                    delete form.dataset.confirming;
                    if (isRescheduleRequest) {
                        window.setTimeout(function () { openRequestScheduleForm(button); }, 0);
                    }
                },
                onConfirm: function () {
                    delete form.dataset.confirming;
                    submitResponse(form, action, button, originalLabel);
                },
            });
        });
    });

    document.querySelectorAll('[data-schedule-server-message]').forEach(function (notice) {
        scheduleModal.notify({
            title: notice.dataset.scheduleMessageType === 'error' ? 'Schedule Update' : 'Schedule Updated',
            message: notice.textContent.trim(),
            tone: notice.dataset.scheduleMessageType === 'error' ? 'error' : 'success',
        });
        notice.hidden = true;
    });
});
