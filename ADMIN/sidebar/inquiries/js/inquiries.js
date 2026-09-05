// Inquiry modal controls para malinis at walang inline JavaScript.
document.addEventListener('DOMContentLoaded', function () {
    const openButtons = document.querySelectorAll('[data-inquiry-modal-open]');
    const archiveOpenButtons = document.querySelectorAll('[data-archive-modal-open]');
    const toast = document.querySelector('[data-inquiry-toast]');
    const inquiryShell = document.querySelector('.inquiries-shell');
    let latestKnownInquiryId = Number.parseInt(inquiryShell?.dataset.latestInquiryId || '0', 10);
    let lastOpenButton = null;
    let pendingConfirmForm = null;

    const showPageLoading = function () {
        if (inquiryShell) {
            inquiryShell.classList.add('is-loading');
        }
    };

    const playToastSound = function () {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;

            const audioContext = new AudioContextClass();
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.value = 0.05;
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start();
            oscillator.stop(audioContext.currentTime + 0.12);
        } catch (error) {
            // Tahimik lang kapag bawal ng browser ang auto sound.
        }
    };

    if (toast) {
        playToastSound();
        const isErrorToast = toast.classList.contains('inquiry-toast--error');
        let toastClosed = false;

        const closeToast = function () {
            if (toastClosed) {
                return;
            }

            toastClosed = true;
            toast.remove();
        };

        toast.querySelector('[data-inquiry-toast-close]')?.addEventListener('click', function () {
            closeToast();
        });

        setTimeout(function () {
            closeToast();
        }, isErrorToast ? 8000 : 4500);

        if (!isErrorToast) {
            document.addEventListener('click', function closeSuccessToastOnOutside(event) {
                if (!toast || toastClosed) {
                    document.removeEventListener('click', closeSuccessToastOnOutside);
                    return;
                }

                if (!toast.contains(event.target)) {
                    closeToast();
                    document.removeEventListener('click', closeSuccessToastOnOutside);
                }
            });
        }
    }

    const showLiveInquiryToast = function (message) {
        document.querySelector('[data-live-inquiry-toast]')?.remove();

        const notice = document.createElement('div');
        const text = document.createElement('span');
        const closeButton = document.createElement('button');
        let closeTimer = null;

        notice.className = 'inquiry-toast inquiry-toast--success';
        notice.setAttribute('role', 'status');
        notice.setAttribute('data-live-inquiry-toast', '');
        text.textContent = message;
        closeButton.type = 'button';
        closeButton.setAttribute('aria-label', 'Close notification');
        closeButton.textContent = '\u00d7';

        const closeNotice = function () {
            if (closeTimer) {
                window.clearTimeout(closeTimer);
            }
            notice.remove();
        };

        closeButton.addEventListener('click', closeNotice);
        notice.append(text, closeButton);
        document.body.appendChild(notice);
        playToastSound();
        closeTimer = window.setTimeout(closeNotice, 6000);
    };

    const showNewInquiryBellDot = function () {
        const notificationToggle = document.querySelector('[data-notification-root] .topbar-notifications__toggle');
        if (!notificationToggle || notificationToggle.querySelector('[data-live-inquiry-dot]')) {
            return;
        }

        const dot = document.createElement('span');
        dot.className = 'inquiry-live-notification-dot';
        dot.setAttribute('data-live-inquiry-dot', '');
        dot.setAttribute('aria-hidden', 'true');
        notificationToggle.appendChild(dot);
    };

    const confirmBox = document.createElement('div');
    confirmBox.className = 'inquiry-confirm';
    confirmBox.hidden = true;
    confirmBox.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryConfirmTitle">',
        '<h3 id="inquiryConfirmTitle">Are you sure?</h3>',
        '<p data-inquiry-confirm-message>This action will update the inquiry.</p>',
        '<dl class="inquiry-confirm__details" data-inquiry-confirm-details hidden></dl>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-secondary" data-inquiry-confirm-no>No</button>',
        '<button type="button" class="btn-primary" data-inquiry-confirm-yes>Yes</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(confirmBox);

    const showConfirm = function (form, message, details) {
        pendingConfirmForm = form;
        const messageBox = confirmBox.querySelector('[data-inquiry-confirm-message]');
        const detailsBox = confirmBox.querySelector('[data-inquiry-confirm-details]');
        if (messageBox) {
            messageBox.textContent = message;
        }

        if (detailsBox) {
            detailsBox.replaceChildren();
            if (details && details.length) {
                details.forEach(function (detail) {
                    const term = document.createElement('dt');
                    const description = document.createElement('dd');
                    term.textContent = detail.label;
                    description.textContent = detail.value || 'Not set';
                    detailsBox.append(term, description);
                });
                detailsBox.hidden = false;
            } else {
                detailsBox.hidden = true;
            }
        }

        confirmBox.hidden = false;
        confirmBox.querySelector('[data-inquiry-confirm-no]')?.focus();
    };

    const closeConfirm = function () {
        pendingConfirmForm = null;
        confirmBox.hidden = true;
    };

    const closeModal = function (modal) {
        if (!modal) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('inquiry-modal-open');
        sessionStorage.removeItem('edgeLastInquiryModal');

        if (lastOpenButton) {
            lastOpenButton.focus();
            lastOpenButton = null;
        }
    };

    const openModal = function (modal) {
        if (!modal) {
            return;
        }

        modal.hidden = false;
        document.body.classList.add('inquiry-modal-open');
        sessionStorage.setItem('edgeLastInquiryModal', modal.id);

        const closeButton = modal.querySelector('[data-inquiry-modal-close]');
        if (closeButton) {
            closeButton.focus();
        }
    };

    const activateModalTab = function (modal, target) {
        if (!modal || !target) {
            return false;
        }

        const tabs = Array.from(modal.querySelectorAll('[data-inquiry-tab]'));
        const panels = Array.from(modal.querySelectorAll('[data-inquiry-panel]'));
        const targetTab = tabs.find(function (tab) {
            return tab.getAttribute('data-inquiry-tab') === target;
        });
        if (!targetTab || targetTab.disabled || targetTab.classList.contains('chip-disabled')) {
            return false;
        }

        tabs.forEach(function (tab) {
            tab.classList.toggle('is-active', tab === targetTab);
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-inquiry-panel') !== target;
            panel.classList.toggle('is-active', !panel.hidden);
        });
        return true;
    };

    const pushModalHistory = function (modal, target) {
        const url = new URL(window.location.href);
        const currentDepth = window.history.state?.inquiryModalId === modal.id
            ? Number.parseInt(window.history.state.inquiryDepth || '0', 10)
            : 0;
        url.searchParams.set('open', modal.id);
        url.searchParams.set('tab', target);
        window.history.pushState({
            inquiryModalId: modal.id,
            inquiryTab: target,
            inquiryDepth: currentDepth + 1,
        }, '', url);
    };

    const requestCloseModal = function (modal) {
        closeModal(modal);
        window.history.replaceState({}, document.title, 'inquiries.php');
    };

    openButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            const modalId = button.getAttribute('data-inquiry-modal-open');
            lastOpenButton = button;
            const modal = document.getElementById(modalId);
            openModal(modal);

            if (modal) {
                const requestedTab = button.getAttribute('data-inquiry-open-tab') || 'client';
                let activeTab = requestedTab;
                if (!activateModalTab(modal, activeTab)) {
                    activeTab = 'client';
                    activateModalTab(modal, activeTab);
                }
                pushModalHistory(modal, activeTab);
            }
        });
    });

    document.querySelectorAll('.inquiry-status-link, .inquiry-view-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (link.classList.contains('is-active')) {
                return;
            }

            showPageLoading();
        });
    });

    document.querySelector('.inquiry-filter-bar')?.addEventListener('submit', showPageLoading);

    document.querySelectorAll('.inquiry-modal').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-inquiry-modal-close]')) {
                requestCloseModal(modal);
            }
        });
    });

    const closeArchiveModal = function (modal) {
        if (modal) {
            modal.hidden = true;
        }
    };

    archiveOpenButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = document.getElementById(button.getAttribute('data-archive-modal-open'));
            if (modal) {
                modal.hidden = false;
                modal.querySelector('textarea')?.focus();
            }
        });
    });

    document.querySelectorAll('.inquiry-archive-modal').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-archive-modal-close]')) {
                closeArchiveModal(modal);
            }
        });
    });

    document.querySelectorAll('.inquiry-schedule-form').forEach(function (form) {
        const dateInput = form.querySelector('.js-admin-inspection-date');
        const timeInput = form.querySelector('.js-admin-inspection-time');
        const hiddenSchedule = form.querySelector('.js-admin-scheduled-at');
        const dateButton = form.querySelector('.js-admin-date-picker-button');
        const dateTooltip = form.querySelector('.js-admin-date-tooltip');
        const modal = form.closest('.inquiry-modal');
        const draftKey = modal?.dataset.inquiryId ? 'edgeInquiryScheduleDraft:' + modal.dataset.inquiryId : '';

        const syncInvalidUi = function () {
            if (form.dataset.submitAttempted !== '1') {
                return;
            }

            form.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.classList.toggle('is-invalid', !field.checkValidity());
            });
        };

        if (!dateInput || !timeInput || !hiddenSchedule) {
            return;
        }

        if (draftKey) {
            try {
                const draft = JSON.parse(sessionStorage.getItem(draftKey) || '{}');
                if (draft.engineer_id) form.querySelector('select[name="engineer_id"]').value = draft.engineer_id;
                if (draft.inspection_date) dateInput.value = draft.inspection_date;
                if (draft.inspection_time) timeInput.value = draft.inspection_time;
                if (draft.site_notes) form.querySelector('textarea[name="site_notes"]').value = draft.site_notes;
            } catch (error) {
                sessionStorage.removeItem(draftKey);
            }
        }

        const formatLocalDate = function (date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        };

        const today = new Date();
        const todayDate = formatLocalDate(today);
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        const lastWorkingTimeToday = new Date(todayDate + 'T16:00:00');
        let dateNoteTimer = null;

        const minimumScheduleBufferMs = 30 * 60 * 1000;

        const getMinimumScheduleTime = function () {
            return Date.now() + minimumScheduleBufferMs;
        };

        const hasWorkingTimeToday = function () {
            return getMinimumScheduleTime() <= lastWorkingTimeToday.getTime();
        };

        dateInput.min = hasWorkingTimeToday() ? todayDate : formatLocalDate(tomorrow);
        if (dateInput.value === todayDate && !hasWorkingTimeToday()) {
            dateInput.value = '';
            timeInput.value = '';
        }

        const syncTimeOptions = function () {
            const minimumTime = getMinimumScheduleTime();

            Array.from(timeInput.options).forEach(function (option) {
                if (!option.value) {
                    return;
                }

                const optionDate = new Date(dateInput.value + 'T' + option.value + ':00');
                const isUnavailable = dateInput.value === todayDate && optionDate.getTime() < minimumTime;
                option.disabled = isUnavailable;
                option.hidden = isUnavailable;
            });

            if (timeInput.selectedOptions[0]?.disabled) {
                timeInput.value = '';
            }
        };

        const isInvalidSelectedTime = function () {
            if (dateInput.value !== todayDate || !timeInput.value) {
                return false;
            }

            const selectedTime = new Date(dateInput.value + 'T' + timeInput.value + ':00');
            return selectedTime.getTime() < getMinimumScheduleTime();
        };

        const validateScheduleTime = function () {
            syncTimeOptions();
            if (isInvalidSelectedTime()) {
                timeInput.setCustomValidity('Select a time at least 30 minutes from now.');
            } else {
                timeInput.setCustomValidity('');
            }
        };

        const hideDatePickerState = function () {
            dateInput.dataset.pickerOpen = '0';
            dateButton?.classList.remove('is-active');
            dateTooltip?.classList.remove('is-visible');
        };

        const showDateNoteBriefly = function () {
            dateTooltip?.classList.add('is-visible');
            if (dateNoteTimer) {
                window.clearTimeout(dateNoteTimer);
            }
            dateNoteTimer = window.setTimeout(function () {
                dateTooltip?.classList.remove('is-visible');
            }, 2600);
        };

        dateButton?.addEventListener('click', function () {
            const isOpen = dateInput.dataset.pickerOpen === '1';

            if (isOpen) {
                hideDatePickerState();
                dateInput.blur();
                return;
            }

            dateInput.dataset.pickerOpen = '1';
            dateButton.classList.add('is-active');
            showDateNoteBriefly();

            if (typeof dateInput.showPicker === 'function') {
                dateInput.showPicker();
            } else {
                dateInput.focus();
            }
        });

        dateInput.addEventListener('change', function () {
            hideDatePickerState();
            showDateNoteBriefly();
            syncTimeOptions();
            validateScheduleTime();
        });
        dateInput.addEventListener('blur', function () {
            dateInput.dataset.pickerOpen = '0';
            dateButton?.classList.remove('is-active');
        });
        timeInput.addEventListener('change', validateScheduleTime);
        syncTimeOptions();

        const saveDraft = function () {
            if (!draftKey) {
                return;
            }

            const draft = {
                engineer_id: form.querySelector('select[name="engineer_id"]')?.value || '',
                inspection_date: dateInput.value,
                inspection_time: timeInput.value,
                site_notes: form.querySelector('textarea[name="site_notes"]')?.value || '',
            };
            sessionStorage.setItem(draftKey, JSON.stringify(draft));
        };

        form.querySelectorAll('select, input, textarea').forEach(function (field) {
            field.addEventListener('input', saveDraft);
            field.addEventListener('change', saveDraft);
            field.addEventListener('input', function () {
                if (form.dataset.submitAttempted === '1') syncInvalidUi();
            });
            field.addEventListener('change', function () {
                if (form.dataset.submitAttempted === '1') syncInvalidUi();
            });
            field.addEventListener('invalid', function () {
                form.dataset.submitAttempted = '1';
                field.classList.add('is-invalid');
            });
        });

        form.querySelector('[data-inquiry-clear-inputs]')?.addEventListener('click', function () {
            form.reset();
            form.dataset.submitAttempted = '0';
            form.querySelectorAll('.is-invalid').forEach(function (field) {
                field.classList.remove('is-invalid');
            });
            hiddenSchedule.value = '';
            if (draftKey) sessionStorage.removeItem(draftKey);
            syncTimeOptions();
        });

        form.addEventListener('submit', function (event) {
            form.dataset.submitAttempted = '1';
            validateScheduleTime();
            if (!form.checkValidity()) {
                syncInvalidUi();
                form.reportValidity();
                return;
            }

            hiddenSchedule.value = dateInput.value && timeInput.value
                ? dateInput.value + ' ' + timeInput.value
                : '';

            if (form.dataset.confirmed === '1') {
                if (draftKey) sessionStorage.removeItem(draftKey);
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Finalize this inspection schedule and email the final quotation to the client?');
        });
    });

    document.querySelectorAll('.inquiry-review-form').forEach(function (form) {
        const statusSelect = form.querySelector('select[name="status"]');
        if (!statusSelect) {
            return;
        }

        const originalStatus = statusSelect.value;
        const modal = form.closest('.inquiry-modal');
        const statusChip = modal?.querySelector('[data-modal-status-chip]');
        const statusClasses = [
            'status-select--pending',
            'status-select--verified',
            'status-select--inspection',
            'status-select--not-qualified',
        ];

        const syncStatusChip = function () {
            statusSelect.dataset.status = statusSelect.value;
            statusSelect.classList.remove(...statusClasses);

            if (statusSelect.value === 'Pending Review') {
                statusSelect.classList.add('status-select--pending');
            } else if (statusSelect.value === 'Verified Lead') {
                statusSelect.classList.add('status-select--verified');
            } else if (statusSelect.value === 'For Inspection') {
                statusSelect.classList.add('status-select--inspection');
            } else if (statusSelect.value === 'Not Qualified') {
                statusSelect.classList.add('status-select--not-qualified');
            }

            if (statusChip) {
                statusChip.textContent = statusSelect.value;
                statusChip.dataset.status = statusSelect.value;
            }
        };

        statusSelect.addEventListener('change', syncStatusChip);
        syncStatusChip();

        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1' || statusSelect.value === originalStatus) {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Change inquiry status from ' + originalStatus + ' to ' + statusSelect.value + '?');
        });
    });

    document.querySelectorAll('.inquiry-archive-form').forEach(function (form) {
        const reasonSelect = form.querySelector('select[name="archive_reason"]');
        const manualReason = form.querySelector('textarea[name="archive_reason_other"]');
        const manualReasonMark = form.querySelector('[data-archive-other-required]');

        const syncArchiveReasonRequirement = function () {
            if (!reasonSelect || !manualReason) {
                return;
            }

            manualReason.required = reasonSelect.value === 'Other';
            if (manualReasonMark) {
                manualReasonMark.hidden = reasonSelect.value !== 'Other';
            }
            if (reasonSelect.value !== 'Other') {
                manualReason.classList.remove('is-invalid');
            }
        };

        reasonSelect?.addEventListener('change', syncArchiveReasonRequirement);
        syncArchiveReasonRequirement();

        form.querySelectorAll('textarea, input, select').forEach(function (field) {
            field.addEventListener('invalid', function () {
                field.classList.add('is-invalid');
            });
            field.addEventListener('input', function () {
                field.classList.toggle('is-invalid', !field.checkValidity());
            });
        });

        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Archive this inquiry? It will move to Archive list.');
        });
    });

    document.querySelectorAll('.inquiry-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Permanently delete this archived inquiry? This cannot be undone.');
        });
    });

    document.querySelectorAll('.inquiry-restore-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Restore this inquiry to active list?');
        });
    });

    document.querySelectorAll('.inquiry-modal').forEach(function (modal) {
        const tabs = Array.from(modal.querySelectorAll('[data-inquiry-tab]'));

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                if (tab.disabled || tab.classList.contains('chip-disabled')) {
                    return;
                }

                const target = tab.getAttribute('data-inquiry-tab');
                if (tab.classList.contains('is-active')) {
                    return;
                }

                activateModalTab(modal, target);
                pushModalHistory(modal, target);
            });
        });
    });

    const quotationStatusModals = Array.from(document.querySelectorAll('.inquiry-modal[data-inquiry-id]'));
    let quotationPollInProgress = false;

    const pollQuotationStatuses = function () {
        if (quotationPollInProgress || document.hidden || !inquiryShell?.hasAttribute('data-latest-inquiry-id')) {
            return;
        }

        const inquiryIds = quotationStatusModals
            .map(function (modal) { return modal.dataset.inquiryId || ''; })
            .filter(Boolean);
        const pollUrl = new URL('/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php', window.location.origin);
        pollUrl.searchParams.set('action', 'poll_quotation_status');
        pollUrl.searchParams.set('inquiry_ids', inquiryIds.join(','));
        quotationPollInProgress = true;

        fetch(pollUrl.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Unable to check quotation status.');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data.success || !Array.isArray(data.quotations)) {
                    return;
                }

                const latestInquiryId = Number.parseInt(data.latest_inquiry_id || '0', 10);
                if (latestInquiryId > latestKnownInquiryId) {
                    latestKnownInquiryId = latestInquiryId;
                    if (inquiryShell) {
                        inquiryShell.dataset.latestInquiryId = String(latestInquiryId);
                    }
                    showNewInquiryBellDot();
                    showLiveInquiryToast('Notification: A new client inquiry has just been received!');
                }

                data.quotations.forEach(function (quotation) {
                    const modal = document.querySelector('.inquiry-modal[data-inquiry-id="' + String(quotation.inquiry_id) + '"]');
                    if (!modal) {
                        return;
                    }

                    const previousStatus = modal.dataset.quotationStatus || '';
                    const currentStatus = String(quotation.status || '');
                    modal.dataset.quotationStatus = currentStatus;

                    if (previousStatus !== 'sent' || currentStatus !== 'accepted') {
                        return;
                    }

                    const inspectionTab = modal.querySelector('[data-inquiry-stage="inspection"]');
                    if (inspectionTab) {
                        inspectionTab.disabled = false;
                        inspectionTab.setAttribute('aria-disabled', 'false');
                        inspectionTab.classList.remove('chip-disabled');
                    }

                    modal.querySelector('[data-inquiry-inspection-form]')?.removeAttribute('hidden');
                    const statusLabel = modal.querySelector('[data-quotation-status-label]');
                    if (statusLabel) {
                        statusLabel.textContent = quotation.label || 'Accepted';
                    }

                    const nextAction = modal.closest('.inquiry-card')?.querySelector('.inquiry-open-modal');
                    if (nextAction) {
                        nextAction.textContent = 'Schedule Inspection';
                        nextAction.setAttribute('data-inquiry-open-tab', 'inspection');
                    }

                    showLiveInquiryToast('Notification: Quotation for this inquiry has been accepted by the client!');
                });
            })
            .catch(function () {
                // Susubok ulit sa next poll kapag may temporary connection problem.
            })
            .finally(function () {
                quotationPollInProgress = false;
            });
    };

    window.setInterval(pollQuotationStatuses, 8000);

    document.querySelectorAll('[data-inquiry-history-back]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!document.referrer) {
                return;
            }

            const previousUrl = new URL(document.referrer);
            if (previousUrl.origin === window.location.origin && previousUrl.pathname.endsWith('/ADMIN/sidebar/inquiries/php/inquiries.php')) {
                event.preventDefault();
                window.history.back();
            }
        });
    });

    document.querySelectorAll('.inquiry-quote-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Generate quotation draft from engineer costing?');
        });
    });

    document.querySelectorAll('.inquiry-quote-send-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (form.dataset.confirmed !== '1') {
                showConfirm(form, 'Send this quotation to the client by email?', [
                    { label: 'Client', value: form.dataset.quoteRecipientName || '' },
                    { label: 'Email', value: form.dataset.quoteRecipientEmail || '' },
                    { label: 'Contact', value: form.dataset.quoteRecipientContact || '' },
                    { label: 'Source', value: form.dataset.quoteRecipientSource || '' },
                ]);
                return;
            }

            delete form.dataset.confirmed;
            if (form.dataset.submitting === '1') {
                return;
            }

            form.dataset.submitting = '1';
            const submitButton = form.querySelector('button[type="submit"]');
            const defaultText = submitButton?.textContent || 'Send Quotation to Client';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Sending...';
            }

            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',   
                },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { response: response, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.response.ok || !result.data.success) {
                        throw new Error(result.data.message || 'Unable to send quotation.');
                    }
                    window.location.assign(result.data.redirect || window.location.href);
                })
                .catch(function (error) {
                    form.dataset.submitting = '0';
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent = defaultText;
                    }

                    const notice = document.createElement('div');
                    const message = document.createElement('span');
                    const closeButton = document.createElement('button');
                    notice.className = 'inquiry-toast inquiry-toast--error';
                    notice.setAttribute('role', 'alert');
                    message.textContent = error.message || 'Unable to send quotation.';
                    closeButton.type = 'button';
                    closeButton.setAttribute('aria-label', 'Close notification');
                    closeButton.textContent = '\u00d7';
                    closeButton.addEventListener('click', function () {
                        notice.remove();
                    });
                    notice.append(message, closeButton);
                    document.body.appendChild(notice);
                    window.setTimeout(function () {
                        notice.remove();
                    }, 8000);
                });
        });
    });

    document.querySelectorAll('.inquiry-project-create-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Create project from this accepted quotation?');
        });
    });

    const quotationCreate = document.querySelector('[data-quotation-create]');
    if (quotationCreate) {
        const form = quotationCreate.querySelector('[data-quotation-create-form]');
        const items = quotationCreate.querySelector('[data-quotation-items]');
        const template = quotationCreate.querySelector('[data-quotation-item-template]');
        const addButton = quotationCreate.querySelector('[data-quotation-add-item]');
        const subtotalOutput = quotationCreate.querySelector('[data-quotation-subtotal]');
        const profitOutput = quotationCreate.querySelector('[data-quotation-profit]');
        const totalOutput = quotationCreate.querySelector('[data-quotation-total]');
        const marginInput = form?.querySelector('input[name="profit_margin_percent"]');

        const updateQuotationPreview = function () {
            let subtotal = 0;
            items?.querySelectorAll('[data-quotation-item]').forEach(function (item) {
                const quantity = Number.parseFloat(item.querySelector('input[name="quantity[]"]')?.value || '0');
                const unitCost = Number.parseFloat(item.querySelector('input[name="unit_cost[]"]')?.value || '0');
                subtotal += Math.max(0, quantity) * Math.max(0, unitCost);
            });

            const margin = Number.parseFloat(marginInput?.value || '0');
            const profit = subtotal * (Math.max(0, margin) / 100);

            if (subtotalOutput) subtotalOutput.textContent = subtotal.toFixed(2);
            if (profitOutput) profitOutput.textContent = profit.toFixed(2);
            if (totalOutput) totalOutput.textContent = (subtotal + profit).toFixed(2);

            const rows = items?.querySelectorAll('[data-quotation-item]') || [];
            rows.forEach(function (row) {
                const removeButton = row.querySelector('[data-quotation-remove-item]');
                if (removeButton) removeButton.disabled = rows.length === 1;
            });
        };

        addButton?.addEventListener('click', function () {
            if (!items || !template || items.children.length >= 50) {
                return;
            }

            items.appendChild(template.content.cloneNode(true));
            updateQuotationPreview();
        });

        items?.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-quotation-remove-item]');
            if (!removeButton || items.querySelectorAll('[data-quotation-item]').length <= 1) {
                return;
            }

            removeButton.closest('[data-quotation-item]')?.remove();
            updateQuotationPreview();
        });

        form?.addEventListener('input', updateQuotationPreview);
        updateQuotationPreview();
    }

    const queryParams = new URLSearchParams(window.location.search);
    const urlOpenModalId = queryParams.get('open');
    const urlOpenTab = queryParams.get('tab') || 'client';
    if (urlOpenModalId) {
        const modal = document.getElementById(urlOpenModalId);
        if (modal) {
            openModal(modal);
            activateModalTab(modal, urlOpenTab);
        }
    } else {
        const lastOpenModalId = sessionStorage.getItem('edgeLastInquiryModal');
        const lastModal = lastOpenModalId ? document.getElementById(lastOpenModalId) : null;
        const lastCard = lastModal?.closest('.inquiry-card');
        if (lastCard) {
            lastCard.scrollIntoView({ behavior: 'instant', block: 'center' });
        }
    }

    window.addEventListener('popstate', function (event) {
        const modalId = event.state?.inquiryModalId || new URLSearchParams(window.location.search).get('open');
        const targetTab = event.state?.inquiryTab || new URLSearchParams(window.location.search).get('tab') || 'client';
        const targetModal = modalId ? document.getElementById(modalId) : null;

        document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(function (modal) {
            if (modal !== targetModal) {
                closeModal(modal);
            }
        });

        if (targetModal) {
            openModal(targetModal);
            activateModalTab(targetModal, targetTab);
        } else {
            document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(closeModal);
        }
    });

    confirmBox.querySelector('[data-inquiry-confirm-no]')?.addEventListener('click', closeConfirm);
    confirmBox.querySelector('[data-inquiry-confirm-yes]')?.addEventListener('click', function () {
        if (!pendingConfirmForm) {
            closeConfirm();
            return;
        }

        const form = pendingConfirmForm;
        form.dataset.confirmed = '1';
        closeConfirm();
        form.requestSubmit();
    });

    confirmBox.addEventListener('click', function (event) {
        if (event.target === confirmBox) {
            closeConfirm();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        if (!confirmBox.hidden) {
            closeConfirm();
            return;
        }

        document.querySelectorAll('.inquiry-archive-modal:not([hidden])').forEach(closeArchiveModal);
        document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(requestCloseModal);
    });
});
