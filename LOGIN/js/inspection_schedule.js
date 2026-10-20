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
        }).formatToParts(new Date(timestamp));
        const value = function (type) { return parts.find(function (part) { return part.type === type; })?.value || ''; };
        const date = value('year') + '-' + value('month') + '-' + value('day');
        return { date: date };
    };

    const manilaNow = function () {
        return manilaParts(Date.now());
    };

    const addCalendarDays = function (date, days) {
        const parts = date.split('-').map(Number);
        return new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] + days)).toISOString().slice(0, 10);
    };

    const validateReason = function (reason) {
        const meaningfulLength = reason.value.replace(/\s+/g, '').length;
        const message = meaningfulLength >= 5
            ? ''
            : 'Please provide at least 5 characters explaining your request.';

        setFieldError(reason, message);
        return message === '';
    };

    const validateDate = function (form) {
        const date = form.elements.preferred_date;
        const now = manilaNow();
        let valid = true;
        if (!date.value) {
            setFieldError(date, 'Choose a preferred date.');
            valid = false;
        } else if (date.value < now.date || date.value > addCalendarDays(now.date, 30)) {
            setFieldError(date, 'Choose a date from today up to 30 days ahead.');
            valid = false;
        } else {
            setFieldError(date, '');
        }
        return valid;
    };

    const validateReschedule = function (form, shouldFocus) {
        const reason = form.elements.reason;
        const date = form.elements.preferred_date;
        const reasonValid = validateReason(reason);
        const dateValid = validateDate(form);
        if (!reasonValid && shouldFocus) reason.focus();
        else if (!dateValid && shouldFocus) date.focus();
        return reasonValid && dateValid;
    };

    document.querySelectorAll('[data-schedule-reschedule-form]').forEach(function (form) {
        const reason = form.elements.reason;
        const date = form.elements.preferred_date;
        const hasSubmitAttempt = function () { return form.dataset.submitAttempted === '1'; };
        reason.addEventListener('input', function () { setFieldError(reason, ''); });
        reason.addEventListener('blur', function () { validateReason(reason); });
        date.addEventListener('change', function () {
            // Date change lang ito. Error ay lalabas lang sa Submit.
            setFieldError(date, '');
            if (hasSubmitAttempt()) validateDate(form);
        });
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
                    label.textContent = 'Requested date';
                    requested.append(label, document.createTextNode(date));
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
