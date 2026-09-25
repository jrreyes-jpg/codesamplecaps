// Inquiry modal controls para malinis at walang inline JavaScript.
document.addEventListener('DOMContentLoaded', function () {
    const openButtons = document.querySelectorAll('[data-inquiry-modal-open]');
    const archiveOpenButtons = document.querySelectorAll('[data-archive-modal-open]');
    const inquiryShell = document.querySelector('.inquiries-shell');
    const listStartAtTopKey = 'edgeInquiryListStartAtTop';
    let latestRevisionId = Number.parseInt(inquiryShell?.dataset.latestRevisionId || '0', 10);
    let latestRevisionUpdatedAt = inquiryShell?.dataset.latestRevisionUpdatedAt || '';
    let latestRejectedId = Number.parseInt(inquiryShell?.dataset.latestRejectedId || '0', 10);
    let latestRejectedAt = inquiryShell?.dataset.latestRejectedAt || '';
    let lastOpenButton = null;
    let pendingConfirmForm = null;
    let pendingDiscardModal = null;
    let pendingDiscardAction = null;
    let pendingDiscardKeepAction = null;
    let pendingQuotationDraftDiscard = null;

    if ('scrollRestoration' in window.history) {
        window.history.scrollRestoration = 'manual';
    }

    const startInquiryListAtTop = function () {
        window.scrollTo(0, 0);
        window.requestAnimationFrame(function () {
            window.scrollTo(0, 0);
        });
    };

    const showPageLoading = function () {
        if (!inquiryShell) {
            return;
        }

        inquiryShell.classList.add('is-loading');

        const inquiryList = inquiryShell.querySelector(':scope > .inquiry-list');
        const currentEmptyState = inquiryShell.querySelector(':scope > .inquiry-empty');
        const loadingState = document.createElement('div');
        const loadingIndicator = document.createElement('div');
        const spinner = document.createElement('span');
        const loadingText = document.createElement('span');

        loadingState.className = 'inquiry-empty';
        loadingState.setAttribute('role', 'status');
        loadingState.setAttribute('aria-live', 'polite');
        loadingIndicator.className = 'btn-primary inquiry-send-button--loading';
        spinner.className = 'inquiry-send-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        loadingText.textContent = 'Loading inquiries...';
        loadingIndicator.append(spinner, loadingText);
        loadingState.appendChild(loadingIndicator);

        if (inquiryList) {
            inquiryList.replaceChildren(loadingState);
        } else if (currentEmptyState) {
            currentEmptyState.replaceWith(loadingState);
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

    const showLiveInquiryToast = function (message, inquiryId, targetTab, type, fallbackStatus) {
        const openToastInquiry = function () {
            const modal = document.getElementById('inquiryModal' + String(inquiryId || ''));
            if (!modal) {
                if (!fallbackStatus) {
                    return;
                }
                const targetUrl = new URL('/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php', window.location.origin);
                targetUrl.searchParams.set('status', fallbackStatus);
                targetUrl.searchParams.set('open', 'inquiryModal' + String(inquiryId || ''));
                targetUrl.searchParams.set('tab', targetTab || 'quotation');
                window.location.assign(targetUrl.toString());
                return;
            }

            document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(function (openInquiryModal) {
                if (openInquiryModal !== modal) {
                    closeModal(openInquiryModal);
                }
            });

            openModal(modal);
            const requestedTab = targetTab || 'client';
            const activeTab = activateModalTab(modal, requestedTab) ? requestedTab : 'client';
            pushModalHistory(modal, activeTab);
        };

        if (typeof window.showToast === 'function') {
            window.showToast(message, type || 'success', {
                onClick: Number.parseInt(inquiryId || '0', 10) > 0 ? openToastInquiry : null,
            });
        }
        playToastSound();
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

    const discardConfirmBox = document.createElement('div');
    discardConfirmBox.className = 'inquiry-confirm';
    discardConfirmBox.hidden = true;
    discardConfirmBox.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryDiscardTitle">',
        '<h3 id="inquiryDiscardTitle">Unsaved changes</h3>',
        '<p>You have unsaved changes. Discard them?</p>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-secondary" data-inquiry-discard-keep>Keep Editing</button>',
        '<button type="button" class="btn-primary" data-inquiry-discard-yes>Discard Changes</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(discardConfirmBox);

    const quotationDraftDiscardBox = document.createElement('div');
    quotationDraftDiscardBox.className = 'inquiry-confirm';
    quotationDraftDiscardBox.hidden = true;
    quotationDraftDiscardBox.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="quotationDraftDiscardTitle">',
        '<h3 id="quotationDraftDiscardTitle">Discard unsaved quotation changes?</h3>',
        '<p>Your unsaved quotation changes will be cleared.</p>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-secondary" data-quotation-draft-discard-keep>Keep Editing</button>',
        '<button type="button" class="btn-primary" data-quotation-draft-discard-yes>Discard Changes</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(quotationDraftDiscardBox);

    const prerequisiteNotice = document.createElement('div');
    prerequisiteNotice.className = 'inquiry-confirm inquiry-prerequisite-modal';
    prerequisiteNotice.hidden = true;
    prerequisiteNotice.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryPrerequisiteTitle">',
        '<h3 id="inquiryPrerequisiteTitle">Required First Step</h3>',
        '<p data-prerequisite-notice-message></p>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-primary" data-prerequisite-notice-ok>OK</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(prerequisiteNotice);

    const showConfirm = function (form, message, details, labels = {}) {
        pendingConfirmForm = form;
        form.dataset.confirmationMode = labels.mode || 'submit';
        const titleBox = confirmBox.querySelector('#inquiryConfirmTitle');
        const messageBox = confirmBox.querySelector('[data-inquiry-confirm-message]');
        const detailsBox = confirmBox.querySelector('[data-inquiry-confirm-details]');
        const cancelButton = confirmBox.querySelector('[data-inquiry-confirm-no]');
        const confirmButton = confirmBox.querySelector('[data-inquiry-confirm-yes]');
        if (titleBox) {
            titleBox.textContent = labels.title || 'Are you sure?';
        }

        if (messageBox) {
            messageBox.textContent = message;
        }

        if (cancelButton) {
            cancelButton.textContent = labels.cancel || 'No';
        }

        if (confirmButton) {
            confirmButton.textContent = labels.confirm || 'Yes';
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
        if (pendingConfirmForm) {
            delete pendingConfirmForm.dataset.confirmationMode;
        }
        pendingConfirmForm = null;
        confirmBox.hidden = true;
    };

    const inquiryReviewHasChanges = function (modal) {
        const reviewForm = modal?.querySelector('.inquiry-review-form');
        return reviewForm?.dataset.reviewDirty === '1';
    };

    const discardInquiryReviewChanges = function (modal) {
        const reviewForm = modal?.querySelector('.inquiry-review-form');
        reviewForm?.dispatchEvent(new CustomEvent('edge:review-discard'));
    };

    const closeDiscardConfirm = function (keepEditing = false) {
        const modal = pendingDiscardModal;
        const keepAction = pendingDiscardKeepAction;
        pendingDiscardModal = null;
        pendingDiscardAction = null;
        pendingDiscardKeepAction = null;
        discardConfirmBox.hidden = true;

        if (keepEditing && modal) {
            keepAction?.();
            modal.querySelector('textarea[name="admin_notes"], select[name="status"]')?.focus();
        }
    };

    const showDiscardConfirm = function (modal, onDiscard, onKeepEditing) {
        pendingDiscardModal = modal;
        pendingDiscardAction = onDiscard;
        pendingDiscardKeepAction = onKeepEditing;
        discardConfirmBox.hidden = false;
        discardConfirmBox.querySelector('[data-inquiry-discard-keep]')?.focus();
    };

    const closeQuotationDraftDiscardConfirm = function () {
        pendingQuotationDraftDiscard = null;
        quotationDraftDiscardBox.hidden = true;
    };

    const showQuotationDraftDiscardConfirm = function (onDiscard) {
        pendingQuotationDraftDiscard = onDiscard;
        quotationDraftDiscardBox.hidden = false;
        quotationDraftDiscardBox.querySelector('[data-quotation-draft-discard-keep]')?.focus();
    };

    const closePrerequisiteNotice = function () {
        prerequisiteNotice.hidden = true;
    };

    const showPrerequisiteNotice = function (message) {
        const messageBox = prerequisiteNotice.querySelector('[data-prerequisite-notice-message]');
        if (messageBox) {
            messageBox.textContent = message;
        }

        prerequisiteNotice.hidden = false;
        prerequisiteNotice.querySelector('[data-prerequisite-notice-ok]')?.focus();
    };

    const closeModal = function (modal) {
        if (!modal || modal.dataset.quotationSending === '1' || modal.dataset.reviewSaving === '1') {
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

    const setQuotationSendingState = function (modal, isSending) {
        if (!modal) {
            return;
        }

        if (isSending) {
            modal.dataset.quotationSending = '1';
            modal.classList.add('is-quotation-sending');
            modal.setAttribute('aria-busy', 'true');
            modal.inert = true;
            modal.querySelectorAll('button').forEach(function (button) {
                button.dataset.sendLockWasDisabled = button.disabled ? '1' : '0';
                button.disabled = true;
            });
            modal.querySelectorAll('.inquiry-quote-edit-link, .inquiry-quote-pdf-link').forEach(function (link) {
                link.dataset.sendLockTabindex = link.getAttribute('tabindex') ?? '__none__';
                link.setAttribute('tabindex', '-1');
                link.setAttribute('aria-disabled', 'true');
            });
            return;
        }

        delete modal.dataset.quotationSending;
        modal.classList.remove('is-quotation-sending');
        modal.removeAttribute('aria-busy');
        modal.inert = false;
        modal.querySelectorAll('[data-send-lock-was-disabled]').forEach(function (button) {
            button.disabled = button.dataset.sendLockWasDisabled === '1';
            delete button.dataset.sendLockWasDisabled;
        });
        modal.querySelectorAll('[data-send-lock-tabindex]').forEach(function (link) {
            const previousTabindex = link.dataset.sendLockTabindex;
            if (previousTabindex === '__none__') {
                link.removeAttribute('tabindex');
            } else {
                link.setAttribute('tabindex', previousTabindex);
            }
            link.removeAttribute('aria-disabled');
            delete link.dataset.sendLockTabindex;
        });
    };

    const setReviewSavingState = function (modal, isSaving) {
        if (!modal) {
            return;
        }

        if (isSaving) {
            modal.dataset.reviewSaving = '1';
            modal.classList.add('is-review-saving');
            modal.setAttribute('aria-busy', 'true');
            modal.querySelectorAll('button, select, textarea, input:not([type="hidden"])').forEach(function (control) {
                control.dataset.reviewLockWasDisabled = control.disabled ? '1' : '0';
                control.disabled = true;
            });
            modal.querySelectorAll('a').forEach(function (link) {
                link.dataset.reviewLockTabindex = link.getAttribute('tabindex') ?? '__none__';
                link.setAttribute('tabindex', '-1');
                link.setAttribute('aria-disabled', 'true');
            });
            return;
        }

        delete modal.dataset.reviewSaving;
        modal.classList.remove('is-review-saving');
        modal.removeAttribute('aria-busy');
        modal.querySelectorAll('[data-review-lock-was-disabled]').forEach(function (control) {
            control.disabled = control.dataset.reviewLockWasDisabled === '1';
            delete control.dataset.reviewLockWasDisabled;
        });
        modal.querySelectorAll('[data-review-lock-tabindex]').forEach(function (link) {
            const previousTabindex = link.dataset.reviewLockTabindex;
            if (previousTabindex === '__none__') {
                link.removeAttribute('tabindex');
            } else {
                link.setAttribute('tabindex', previousTabindex);
            }
            link.removeAttribute('aria-disabled');
            delete link.dataset.reviewLockTabindex;
        });
    };

    const setInspectionSchedulingState = function (modal, isScheduling) {
        if (!modal) {
            return;
        }

        if (isScheduling) {
            modal.dataset.inspectionScheduling = '1';
            modal.classList.add('is-inspection-scheduling');
            modal.setAttribute('aria-busy', 'true');
            // I-lock ang modal para hindi mabago ang schedule habang nagsa-save.
            modal.inert = true;
            modal.querySelectorAll('button, select, textarea, input:not([type="hidden"])').forEach(function (control) {
                control.dataset.scheduleLockWasDisabled = control.disabled ? '1' : '0';
                control.disabled = true;
            });
            modal.querySelectorAll('a').forEach(function (link) {
                link.dataset.scheduleLockTabindex = link.getAttribute('tabindex') ?? '__none__';
                link.setAttribute('tabindex', '-1');
                link.setAttribute('aria-disabled', 'true');
            });
            return;
        }

        delete modal.dataset.inspectionScheduling;
        modal.classList.remove('is-inspection-scheduling');
        modal.removeAttribute('aria-busy');
        modal.inert = false;
        modal.querySelectorAll('[data-schedule-lock-was-disabled]').forEach(function (button) {
            button.disabled = button.dataset.scheduleLockWasDisabled === '1';
            delete button.dataset.scheduleLockWasDisabled;
        });
        modal.querySelectorAll('[data-schedule-lock-tabindex]').forEach(function (link) {
            const previousTabindex = link.dataset.scheduleLockTabindex;
            if (previousTabindex === '__none__') {
                link.removeAttribute('tabindex');
            } else {
                link.setAttribute('tabindex', previousTabindex);
            }
            link.removeAttribute('aria-disabled');
            delete link.dataset.scheduleLockTabindex;
        });
    };

    const openModal = function (modal) {
        if (!modal) {
            return;
        }

        modal.hidden = false;
        document.body.classList.add('inquiry-modal-open');
        sessionStorage.setItem('edgeLastInquiryModal', modal.id);

        const inquiryId = Number.parseInt(modal.dataset.inquiryId || '0', 10);
        if (inquiryId > 0) {
            document.dispatchEvent(new CustomEvent('edge:inquiry-opened', {
                detail: { inquiryId: inquiryId },
            }));
        }

        const closeButton = modal.querySelector('[data-inquiry-modal-close]');
        if (closeButton) {
            closeButton.focus();
        }
    };

    const activateModalTab = function (modal, target) {
        if (!modal || !target || modal.dataset.quotationSending === '1' || modal.dataset.reviewSaving === '1' || modal.dataset.inspectionScheduling === '1') {
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
        if (modal?.dataset.quotationSending === '1' || modal?.dataset.reviewSaving === '1' || modal?.dataset.inspectionScheduling === '1') {
            return;
        }

        const reviewForm = modal?.querySelector('.inquiry-review-form');
        if (reviewForm?.dataset.submitting === '1') {
            return;
        }

        if (inquiryReviewHasChanges(modal)) {
            showDiscardConfirm(modal, function () {
                discardInquiryReviewChanges(modal);
                closeModal(modal);
                window.history.replaceState({}, document.title, 'inquiries.php');
            });
            return;
        }

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
        link.addEventListener('click', function (event) {
            // Bagong filter view ito, kaya mula sa taas magsisimula ang listahan.
            sessionStorage.removeItem('edgeLastInquiryModal');
            sessionStorage.setItem(listStartAtTopKey, '1');
            showPageLoading();
            event.preventDefault();

            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    window.location.assign(link.href);
                });
            });
        });
    });

    const filterForm = document.querySelector('.inquiry-filter-bar');
    filterForm?.addEventListener('submit', showPageLoading);

    document.querySelectorAll('.inquiry-modal').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-inquiry-modal-close]')) {
                requestCloseModal(modal);
            }
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.inquiry-modal.is-quotation-sending, .inquiry-modal.is-review-saving, .inquiry-modal.is-inspection-scheduling')) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);

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
        const submitButton = form.querySelector('[data-schedule-submit]');
        const defaultSubmitLabel = submitButton?.textContent || 'Confirm Inspection Schedule & Send to Client';

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

        const manilaDateFormatter = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        });
        const inspectionDayEndMinutes = 17 * 60;
        const inspectionLeadMinutes = 60;
        let dateNoteTimer = null;

        const getManilaNow = function () {
            const parts = manilaDateFormatter.formatToParts(new Date()).reduce(function (values, part) {
                if (part.type !== 'literal') {
                    values[part.type] = part.value;
                }
                return values;
            }, {});

            return {
                date: parts.year + '-' + parts.month + '-' + parts.day,
                minutes: (Number.parseInt(parts.hour || '0', 10) * 60) + Number.parseInt(parts.minute || '0', 10),
            };
        };

        const addDaysToDate = function (dateValue, days) {
            const parts = dateValue.split('-').map(Number);
            const date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] + days));
            return date.toISOString().slice(0, 10);
        };

        const timeValueToMinutes = function (value) {
            const parts = value.split(':').map(Number);
            return (parts[0] * 60) + parts[1];
        };

        const getEarliestScheduleDate = function () {
            const now = getManilaNow();
            return now.minutes + inspectionLeadMinutes <= inspectionDayEndMinutes
                ? now.date
                : addDaysToDate(now.date, 1);
        };

        const syncDateAvailability = function () {
            const earliestDate = getEarliestScheduleDate();
            dateInput.min = earliestDate;

            if (dateInput.value && dateInput.value < earliestDate) {
                dateInput.value = '';
                timeInput.value = '';
            }
        };

        const syncTimeOptions = function () {
            const now = getManilaNow();
            const minimumTodayTime = now.minutes + inspectionLeadMinutes;

            Array.from(timeInput.options).forEach(function (option) {
                if (!option.value) {
                    return;
                }

                const isUnavailable = dateInput.value === now.date
                    && timeValueToMinutes(option.value) < minimumTodayTime;
                option.disabled = isUnavailable;
                option.hidden = isUnavailable;
            });

            if (timeInput.selectedOptions[0]?.disabled) {
                timeInput.value = '';
            }
        };

        const isInvalidSelectedTime = function () {
            if (!dateInput.value || !timeInput.value) {
                return false;
            }

            const now = getManilaNow();
            return dateInput.value < getEarliestScheduleDate()
                || (dateInput.value === now.date && timeValueToMinutes(timeInput.value) < now.minutes + inspectionLeadMinutes);
        };

        const validateScheduleTime = function () {
            syncDateAvailability();
            syncTimeOptions();
            if (isInvalidSelectedTime()) {
                timeInput.setCustomValidity('Select a time at least 1 hour from now.');
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
            syncDateAvailability();
            syncTimeOptions();
            validateScheduleTime();
        });
        dateInput.addEventListener('blur', function () {
            dateInput.dataset.pickerOpen = '0';
            dateButton?.classList.remove('is-active');
        });
        timeInput.addEventListener('change', validateScheduleTime);
        syncDateAvailability();
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

        const getScheduleState = function () {
            return JSON.stringify({
                engineer_id: form.querySelector('select[name="engineer_id"]')?.value || '',
                inspection_date: dateInput.value,
                inspection_time: timeInput.value,
                site_notes: form.querySelector('textarea[name="site_notes"]')?.value || '',
            });
        };
        const initialScheduleState = getScheduleState();
        const syncScheduleSubmitState = function () {
            if (!submitButton || form.dataset.submitting === '1') {
                return;
            }

            const isConfirmed = form.dataset.scheduleConfirmed === '1';
            if (isConfirmed) {
                submitButton.disabled = getScheduleState() === initialScheduleState;
            }
        };

        form.querySelectorAll('select, input, textarea').forEach(function (field) {
            field.addEventListener('input', saveDraft);
            field.addEventListener('change', saveDraft);
            field.addEventListener('input', syncScheduleSubmitState);
            field.addEventListener('change', syncScheduleSubmitState);
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

        form.addEventListener('submit', function (event) {
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

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
                const scheduleRequestData = new FormData(form);
                form.dataset.submitting = '1';
                if (submitButton) {
                    const spinner = document.createElement('span');
                    const label = document.createElement('span');
                    spinner.className = 'inquiry-send-spinner';
                    spinner.setAttribute('aria-hidden', 'true');
                    label.textContent = 'Scheduling inspection...';
                    submitButton.disabled = true;
                    submitButton.classList.add('inquiry-send-button--loading');
                    submitButton.replaceChildren(spinner, label);
                }
                setInspectionSchedulingState(modal, true);
                if (draftKey) sessionStorage.removeItem(draftKey);
                delete form.dataset.confirmed;
                event.preventDefault();

                fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: scheduleRequestData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            return { ok: response.ok, data: data };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.data.success) {
                            throw new Error(result.data.message || 'Unable to save the inspection schedule.');
                        }

                        setInspectionSchedulingState(modal, false);
                        window.location.assign(result.data.redirect || window.location.href);
                    })
                    .catch(function (error) {
                        form.dataset.submitting = '0';
                        setInspectionSchedulingState(modal, false);
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.classList.remove('inquiry-send-button--loading');
                            submitButton.textContent = defaultSubmitLabel;
                        }
                        syncScheduleSubmitState();
                        if (typeof window.showToast === 'function') {
                            window.showToast(error.message || 'Unable to save the inspection schedule.', 'error');
                        } else {
                            window.alert(error.message || 'Unable to save the inspection schedule.');
                        }
                    });
                return;
            }

            event.preventDefault();
            showConfirm(
                form,
                'This will schedule the inspection and notify the client and assigned Engineer.',
                null,
                {
                    title: 'Confirm inspection schedule?',
                    cancel: 'Cancel',
                    confirm: 'Confirm Schedule',
                }
            );
        });
    });

    document.querySelectorAll('.inquiry-review-form').forEach(function (form) {
        const statusSelect = form.querySelector('select[name="status"]');
        const statusField = form.querySelector('[name="status"]');
        const notesField = form.querySelector('textarea[name="admin_notes"]');
        const submitButton = form.querySelector('button[type="submit"]');
        if (!statusField) {
            return;
        }

        let originalStatus = statusField.value;
        let originalNotes = notesField ? notesField.value : '';
        const modal = form.closest('.inquiry-modal');
        const statusChip = modal?.querySelector('[data-modal-status-chip]');
        const statusClasses = [
            'status-select--pending',
            'status-select--verified',
            'status-select--inspection',
            'status-select--not-qualified',
        ];

        const statusLabel = function (status) {
            return status === 'Verified Lead' ? 'Qualified' : status;
        };

        const actionLabel = function (statusChanged, notesChanged) {
            if (statusChanged && ['Verified Lead', 'Not Qualified'].includes(statusField.value)) {
                return 'Save & Notify Client';
            }

            if (!statusSelect && notesChanged) {
                return 'Save Notes';
            }

            return 'Save Review';
        };

        const syncReviewState = function () {
            const status = statusField.value;
            const statusChanged = status !== originalStatus;
            const notesChanged = notesField ? notesField.value !== originalNotes : false;
            const isDirty = statusChanged || notesChanged;
            form.dataset.reviewDirty = isDirty ? '1' : '0';

            if (submitButton) {
                submitButton.disabled = !isDirty || form.dataset.submitting === '1';
                submitButton.setAttribute('aria-disabled', String(!isDirty || form.dataset.submitting === '1'));
                if (form.dataset.submitting !== '1') {
                    submitButton.classList.remove('inquiry-send-button--loading');
                    submitButton.textContent = actionLabel(statusChanged, notesChanged);
                }
            }

            if (!statusSelect) {
                return;
            }

            statusSelect.dataset.status = status;
            statusSelect.classList.remove(...statusClasses);

            if (status === 'Pending Review') {
                statusSelect.classList.add('status-select--pending');
            } else if (status === 'Verified Lead') {
                statusSelect.classList.add('status-select--verified');
            } else if (status === 'For Inspection') {
                statusSelect.classList.add('status-select--inspection');
            } else if (status === 'Not Qualified') {
                statusSelect.classList.add('status-select--not-qualified');
            }

            if (statusChip) {
                statusChip.textContent = statusLabel(status);
                statusChip.dataset.status = status;
            }
        };

        statusSelect?.addEventListener('change', syncReviewState);
        notesField?.addEventListener('input', syncReviewState);
        form.addEventListener('edge:review-discard', function () {
            form.reset();
            delete form.dataset.confirmed;
            delete form.dataset.submitting;
            syncReviewState();
        });
        syncReviewState();

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const statusChanged = statusField.value !== originalStatus;
            const notesChanged = notesField ? notesField.value !== originalNotes : false;
            if (!statusChanged && !notesChanged) {
                return;
            }

            if (statusChanged && statusField.value === 'Pending Review') {
                if (typeof window.showToast === 'function') {
                    window.showToast('Please update the status before saving.', 'warning', { duration: 3000 });
                } else {
                    window.alert('Please update the status before saving.');
                }
                statusSelect?.focus();
                return;
            }

            if (form.dataset.submitting === '1') {
                return;
            }

            if (form.dataset.confirmed !== '1' && statusChanged) {
                showConfirm(form, 'Change inquiry status from ' + statusLabel(originalStatus) + ' to ' + statusLabel(statusField.value) + '?');
                return;
            }

            delete form.dataset.confirmed;
            form.dataset.submitting = '1';
            const willNotifyClient = statusChanged
                && ['Verified Lead', 'Not Qualified'].includes(statusField.value);
            const reviewRequestData = new FormData(form);
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute('aria-disabled', 'true');

                if (willNotifyClient) {
                    const spinner = document.createElement('span');
                    const label = document.createElement('span');
                    spinner.className = 'inquiry-send-spinner';
                    spinner.setAttribute('aria-hidden', 'true');
                    label.textContent = 'Saving & notifying client...';
                    submitButton.classList.add('inquiry-send-button--loading');
                    submitButton.replaceChildren(spinner, label);
                }
            }
            if (willNotifyClient) {
                setReviewSavingState(modal, true);
            }

            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: reviewRequestData,
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
                        throw new Error(result.data.message || 'Unable to save inquiry review.');
                    }

                    originalStatus = result.data.status || statusField.value;
                    originalNotes = notesField ? notesField.value : '';
                    if (willNotifyClient) {
                        setReviewSavingState(modal, false);
                    }
                    window.location.assign(result.data.redirect || window.location.href);
                })
                .catch(function (error) {
                    form.dataset.submitting = '0';
                    if (willNotifyClient) {
                        setReviewSavingState(modal, false);
                    }
                    syncReviewState();
                    if (typeof window.showToast === 'function') {
                        window.showToast(error.message || 'Unable to save inquiry review.', 'error');
                    } else {
                        window.alert(error.message || 'Unable to save inquiry review.');
                    }
                });
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
                const target = tab.getAttribute('data-inquiry-tab');

                if (tab.classList.contains('chip-disabled')) {
                    if (target === 'quotation') {
                        showPrerequisiteNotice("Notice: This stage is locked. Please review the inquiry and set the status to 'Qualified' at the bottom of the 'Contact & Review' tab to activate pricing tools.");
                    } else if (target === 'inspection') {
                        const prerequisite = modal.querySelector('[data-prerequisite-check="client-quotation-approval"]');
                        showPrerequisiteNotice(
                            prerequisite?.dataset.prerequisiteMessage
                            || 'Complete the quotation and wait for client approval before assigning an Engineer.'
                        );
                    }
                    return;
                }

                if (tab.classList.contains('is-active')) {
                    return;
                }

                activateModalTab(modal, target);
                pushModalHistory(modal, target);
            });
        });
    });

    document.querySelectorAll('[data-go-to-inspection]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = button.closest('.inquiry-modal');
            const inspectionTab = modal?.querySelector('[data-inquiry-tab="inspection"]');
            inspectionTab?.click();
        });
    });

    const quotationStatusModals = Array.from(document.querySelectorAll('.inquiry-modal[data-inquiry-id]'));
    let quotationPollInProgress = false;

    const pollQuotationStatuses = function () {
        if (quotationPollInProgress || document.hidden || quotationStatusModals.length === 0) {
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

                const currentRevisionId = Number.parseInt(data.latest_revision_id || '0', 10);
                const currentRevisionUpdatedAt = String(data.latest_revision_updated_at || '');
                const hasNewRevision = currentRevisionUpdatedAt > latestRevisionUpdatedAt
                    || (currentRevisionUpdatedAt === latestRevisionUpdatedAt && currentRevisionId > latestRevisionId);

                if (currentRevisionId > 0 && hasNewRevision) {
                    showLiveInquiryToast(
                        'Notification: A client has requested a revision on their quotation!',
                        Number.parseInt(data.latest_revision_inquiry_id || '0', 10),
                        'quotation'
                    );
                    latestRevisionId = currentRevisionId;
                    latestRevisionUpdatedAt = currentRevisionUpdatedAt;
                    inquiryShell.dataset.latestRevisionId = String(currentRevisionId);
                    inquiryShell.dataset.latestRevisionUpdatedAt = currentRevisionUpdatedAt;
                }

                const currentRejectedId = Number.parseInt(data.latest_rejected_id || '0', 10);
                const currentRejectedAt = String(data.latest_rejected_at || '');
                const hasNewRejection = currentRejectedAt > latestRejectedAt
                    || (currentRejectedAt === latestRejectedAt && currentRejectedId > latestRejectedId);

                if (currentRejectedId > 0 && hasNewRejection) {
                    const rejectionNote = String(data.latest_rejected_note || 'No note provided.');
                    showLiveInquiryToast(
                        'Notification: Client rejected the quotation. Note: ' + rejectionNote,
                        Number.parseInt(data.latest_rejected_inquiry_id || '0', 10),
                        'quotation',
                        'warning',
                        'Rejected'
                    );
                    latestRejectedId = currentRejectedId;
                    latestRejectedAt = currentRejectedAt;
                    inquiryShell.dataset.latestRejectedId = String(currentRejectedId);
                    inquiryShell.dataset.latestRejectedAt = currentRejectedAt;
                }

                data.quotations.forEach(function (quotation) {
                    const modal = document.querySelector('.inquiry-modal[data-inquiry-id="' + String(quotation.inquiry_id) + '"]');
                    if (!modal) {
                        return;
                    }

                    const previousStatus = modal.dataset.quotationStatus || '';
                    const currentStatus = String(quotation.status || '');
                    modal.dataset.quotationStatus = currentStatus;

                    if (!['revision_requested', 'for_revision'].includes(previousStatus)
                        && ['revision_requested', 'for_revision'].includes(currentStatus)) {
                        const statusLabel = modal.querySelector('[data-quotation-status-label]');
                        if (statusLabel) {
                            statusLabel.textContent = quotation.label || 'For Revision';
                            statusLabel.classList.remove('status-draft', 'status-sent', 'status-accepted');
                            statusLabel.classList.add('status-revision');
                        }

                        const revisionNote = modal.querySelector('[data-quotation-revision-note]');
                        if (revisionNote) {
                            revisionNote.textContent = quotation.client_decision_note || 'The client requested changes to this quotation.';
                        }

                        modal.querySelector('[data-quotation-revision-alert]')?.removeAttribute('hidden');
                        modal.querySelector('[data-quotation-revision-action]')?.removeAttribute('hidden');
                    }

                    if (previousStatus !== 'rejected' && currentStatus === 'rejected') {
                        const statusLabel = modal.querySelector('[data-quotation-status-label]');
                        if (statusLabel) {
                            statusLabel.textContent = quotation.label || 'Rejected';
                            statusLabel.classList.remove('status-draft', 'status-sent', 'status-revision', 'status-accepted');
                            statusLabel.classList.add('status-rejected');
                        }

                        const rejectionNote = modal.querySelector('[data-quotation-rejection-note]');
                        if (rejectionNote) {
                            rejectionNote.textContent = quotation.client_decision_note || 'No note provided.';
                        }
                        modal.querySelector('[data-quotation-rejection-alert]')?.removeAttribute('hidden');
                    }

                    if (previousStatus !== 'sent' || currentStatus !== 'accepted') {
                        return;
                    }

                    const inspectionTab = modal.querySelector('[data-inquiry-stage="inspection"]');
                    if (inspectionTab) {
                        inspectionTab.disabled = false;
                        inspectionTab.setAttribute('aria-disabled', 'false');
                        inspectionTab.setAttribute('aria-current', 'step');
                        inspectionTab.classList.remove('chip-disabled');
                        inspectionTab.classList.remove('is-locked');
                        inspectionTab.classList.add('is-current');
                    }

                    const quotationTab = modal.querySelector('[data-inquiry-tab="quotation"]');
                    if (quotationTab) {
                        quotationTab.setAttribute('aria-current', 'false');
                        quotationTab.classList.remove('is-current');
                        quotationTab.classList.add('is-completed');
                    }

                    modal.querySelector('[data-inquiry-inspection-form]')?.removeAttribute('hidden');
                    const quotationPrerequisite = modal.querySelector('[data-prerequisite-check="client-quotation-approval"]');
                    if (quotationPrerequisite) {
                        quotationPrerequisite.hidden = true;
                        quotationPrerequisite.removeAttribute('data-prerequisite-check');
                    }
                    modal.querySelector('[data-quotation-approved-banner]')?.removeAttribute('hidden');
                    const statusLabel = modal.querySelector('[data-quotation-status-label]');
                    if (statusLabel) {
                        statusLabel.textContent = 'Client Accepted';
                        statusLabel.classList.remove('status-draft', 'status-sent', 'status-revision');
                        statusLabel.classList.add('status-accepted');
                    }

                    const nextAction = modal.closest('.inquiry-card')?.querySelector('.inquiry-open-modal');
                    if (nextAction) {
                        nextAction.textContent = 'Schedule Inspection';
                        nextAction.setAttribute('data-inquiry-open-tab', 'inspection');
                    }

                    showLiveInquiryToast(
                        'Notification: Quotation for this inquiry has been accepted by the client!',
                        quotation.inquiry_id,
                        'quotation'
                    );
                });
            })
            .catch(function () {
                // Susubok ulit sa next poll kapag may temporary connection problem.
            })
            .finally(function () {
                quotationPollInProgress = false;
            });
    };

    pollQuotationStatuses();
    window.setInterval(pollQuotationStatuses, 5000);

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

    document.querySelectorAll('.submitted-inspection-review-form').forEach(function (form) {
        const remarks = form.querySelector('textarea[name="admin_remarks"]');

        form.addEventListener('submit', function (event) {
            const decision = event.submitter?.value || '';
            if (decision === 'return' && remarks && remarks.value.trim() === '') {
                event.preventDefault();
                remarks.setCustomValidity('Admin Remarks / Reason for Return is required.');
                remarks.reportValidity();
                remarks.focus();
                return;
            }

            remarks?.setCustomValidity('');
            const message = decision === 'approve'
                ? 'Approve and lock this inspection report?'
                : 'Return this report to the Engineer for revision?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });

        remarks?.addEventListener('input', function () {
            remarks.setCustomValidity('');
        });
    });

    document.querySelectorAll('.inquiry-quote-send-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const isInitialQuotation = form.dataset.quoteKind === 'initial';
            const itemCount = Number.parseInt(form.dataset.quotationItemCount || '0', 10);
            if (isInitialQuotation && itemCount === 1 && form.dataset.completenessConfirmed !== '1') {
                showConfirm(
                    form,
                    'This quotation contains only 1 cost item. Please confirm that the quotation is complete before sending it to the client.',
                    null,
                    {
                        title: 'Confirm quotation completeness',
                        cancel: 'Review Quotation',
                        confirm: 'Continue to Send',
                        mode: 'quotation-completeness',
                    }
                );
                return;
            }

            if (form.dataset.confirmed !== '1') {
                const recipientDetails = [
                    { label: 'Client', value: form.dataset.quoteRecipientName || '' },
                    { label: isInitialQuotation ? 'Verified Email' : 'Email', value: form.dataset.quoteRecipientEmail || '' },
                    { label: 'Contact', value: form.dataset.quoteRecipientContact || '' },
                ];

                // Para sa revised quotation lang ang source details.
                if (!isInitialQuotation) {
                    recipientDetails.push({ label: 'Source', value: form.dataset.quoteRecipientSource || '' });
                }

                showConfirm(form, isInitialQuotation
                    ? 'Please confirm the client details before sending this initial quotation.'
                    : 'Send this revised quotation to the client by email?', recipientDetails, isInitialQuotation ? {
                    title: 'Confirm Recipient',
                    cancel: 'Cancel',
                    confirm: 'Send Quotation',
                } : {});
                return;
            }

            delete form.dataset.confirmed;
            if (form.dataset.submitting === '1') {
                return;
            }

            form.dataset.submitting = '1';
            const submitButton = form.querySelector('button[type="submit"]');
            const defaultText = submitButton?.textContent || 'Send Initial Quotation to Client';
            const sendingModal = form.closest('.inquiry-modal');
            if (submitButton) {
                submitButton.disabled = true;
                const spinner = document.createElement('span');
                const label = document.createElement('span');
                spinner.className = 'inquiry-send-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                label.textContent = 'Sending quotation...';
                submitButton.classList.add('inquiry-send-button--loading');
                submitButton.replaceChildren(spinner, label);
            }
            if (sendingModal) {
                setQuotationSendingState(sendingModal, true);
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
                    sendingModal?.classList.add('is-send-complete');
                    window.setTimeout(function () {
                        window.location.assign(result.data.redirect || window.location.href);
                    }, 180);
                })
                .catch(function (error) {
                    form.dataset.submitting = '0';
                    delete form.dataset.completenessConfirmed;
                    if (sendingModal) {
                        setQuotationSendingState(sendingModal, false);
                        sendingModal.classList.remove('is-send-complete');
                    }
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.classList.remove('inquiry-send-button--loading');
                        submitButton.textContent = defaultText;
                    }

                    if (typeof window.showToast === 'function') {
                        window.showToast(error.message || 'Unable to send quotation.', 'error');
                    }
                });
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
        const markupError = form?.querySelector('[data-quotation-markup-error]');
        const updateSubmitButton = form?.querySelector('[data-quotation-update-submit]');
        const isEditMode = form?.dataset.quotationEditMode === 'true';
        const isInitialQuotation = form?.dataset.initialQuotation === 'true';
        const quotationInquiryId = Number.parseInt(form?.dataset.quotationInquiryId || '0', 10);
        const localDraftKey = quotationInquiryId > 0
            ? 'initial_quotation_draft:' + String(quotationInquiryId)
            : '';
        const localRestoreNoticeKey = quotationInquiryId > 0
            ? 'initial_quotation_restore_notice:' + String(quotationInquiryId)
            : '';
        const localDraftLifetime = 24 * 60 * 60 * 1000;
        const unitOptionsByType = {
            material: ['pcs', 'meter', 'roll', 'box', 'pack', 'set', 'kg', 'liter'],
            labor: ['person', 'hour', 'day', 'lot'],
            equipment: ['unit', 'hour', 'day', 'set', 'lot'],
            service: ['service', 'job', 'lot'],
            other: ['unit', 'lot'],
        };
        const wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person'];
        const serializeQuotationForm = function () {
            return form ? new URLSearchParams(new FormData(form)).toString() : '';
        };

        let initialQuotationState = '';
        let quotationSubmitAccepted = false;
        let localDraftTimer = null;

        const isQuotationDirty = function () {
            return serializeQuotationForm() !== initialQuotationState;
        };

        const updateEditSubmitState = function () {
            if (!isEditMode || !updateSubmitButton) {
                return;
            }

            updateSubmitButton.disabled = serializeQuotationForm() === initialQuotationState;
        };

        const readLocalQuotationDraft = function () {
            if (!isInitialQuotation || localDraftKey === '') {
                return null;
            }

            try {
                const rawDraft = window.localStorage.getItem(localDraftKey);
                if (!rawDraft) {
                    return null;
                }

                const draft = JSON.parse(rawDraft);
                const savedAt = Number(draft?.saved_at || 0);
                const isExpired = !Number.isFinite(savedAt) || Date.now() - savedAt > localDraftLifetime;
                if (draft?.version !== 1
                    || Number(draft?.inquiry_id || 0) !== quotationInquiryId
                    || !Array.isArray(draft?.rows)
                    || isExpired) {
                    window.localStorage.removeItem(localDraftKey);
                    if (localRestoreNoticeKey !== '') {
                        window.sessionStorage.removeItem(localRestoreNoticeKey);
                    }
                    return null;
                }

                return draft;
            } catch (error) {
                return null;
            }
        };

        const clearLocalQuotationDraft = function () {
            if (localDraftTimer) {
                window.clearTimeout(localDraftTimer);
                localDraftTimer = null;
            }
            if (!isInitialQuotation || localDraftKey === '') {
                return;
            }

            try {
                window.localStorage.removeItem(localDraftKey);
                if (localRestoreNoticeKey !== '') {
                    window.sessionStorage.removeItem(localRestoreNoticeKey);
                }
            } catch (error) {
                // Walang gagawin kapag hindi available ang browser storage.
            }
        };

        const buildLocalQuotationDraft = function () {
            return {
                version: 1,
                inquiry_id: quotationInquiryId,
                saved_at: Date.now(),
                markup_percent: marginInput?.value || '',
                rows: Array.from(items?.querySelectorAll('[data-quotation-item]') || []).map(function (row) {
                    return {
                        type: row.querySelector('select[name="item_type[]"]')?.value || 'material',
                        material_id: row.querySelector('select[name="material_id[]"]')?.value || '',
                        item_name: row.querySelector('input[name="item_name[]"]')?.value || '',
                        quantity: row.querySelector('input[name="quantity[]"]')?.value || '',
                        unit: row.querySelector('select[name="unit[]"]')?.value || '',
                        unit_cost: row.querySelector('input[name="unit_cost[]"]')?.value || '',
                        notes: row.querySelector('input[name="item_notes[]"]')?.value || '',
                    };
                }),
            };
        };

        const saveLocalQuotationDraft = function (force = false) {
            if (!isInitialQuotation || localDraftKey === '' || (!force && !isQuotationDirty())) {
                return;
            }

            try {
                window.localStorage.setItem(localDraftKey, JSON.stringify(buildLocalQuotationDraft()));
            } catch (error) {
                // Walang gagawin kapag puno o hindi available ang browser storage.
            }
        };

        const scheduleLocalQuotationDraftSave = function () {
            if (!isInitialQuotation) {
                return;
            }

            if (localDraftTimer) {
                window.clearTimeout(localDraftTimer);
            }
            localDraftTimer = window.setTimeout(function () {
                saveLocalQuotationDraft();
                localDraftTimer = null;
            }, 350);
        };

        const updateQuotationPreview = function () {
            let subtotal = 0;
            items?.querySelectorAll('[data-quotation-item]').forEach(function (item) {
                const quantity = Number.parseFloat(item.querySelector('input[name="quantity[]"]')?.value || '0');
                const unitCostInput = item.querySelector('input[name="unit_cost[]"]');
                const unitCost = Number.parseFloat(unitCostInput?.value || '0');
                const lineTotal = isValidEstimatedUnitCost(unitCostInput?.value || '')
                    ? Math.max(0, quantity) * unitCost
                    : 0;
                const lineTotalOutput = item.querySelector('[data-quotation-line-total]');

                subtotal += lineTotal;
                if (lineTotalOutput) lineTotalOutput.textContent = lineTotal.toFixed(2);
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

        const setQuantityMessage = function (row, message) {
            const quantityInput = row.querySelector('input[name="quantity[]"]');
            const quantityError = row.querySelector('[data-quotation-quantity-error]');
            if (!quantityInput) {
                return;
            }

            quantityInput.setCustomValidity(message);
            quantityInput.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (quantityError) {
                quantityError.textContent = message;
            }
        };

        const isValidEstimatedUnitCost = function (rawValue) {
            const value = Number.parseFloat(rawValue || '0');
            return /^\d+(?:\.\d{1,2})?$/.test((rawValue || '').trim())
                && Number.isFinite(value)
                && value > 0;
        };

        const validateMarkup = function (showMessage, requireValue) {
            if (!marginInput) {
                return true;
            }

            const rawValue = marginInput.value.trim();
            const value = Number(rawValue);
            let message = '';

            if (rawValue === '') {
                message = requireValue ? 'Markup is required.' : '';
            } else if (!Number.isFinite(value)) {
                message = 'Markup must be a number from 0 to 100%.';
            } else if (value < 0) {
                message = 'Markup cannot be negative.';
            } else if (value > 100) {
                message = 'Markup cannot be greater than 100%.';
            }

            marginInput.setCustomValidity(message);
            marginInput.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (markupError) {
                markupError.textContent = showMessage ? message : '';
            }
            return message === '';
        };

        const validateEstimatedUnitCost = function (row, showMessage, requireValue) {
            const unitCostInput = row.querySelector('input[name="unit_cost[]"]');
            const costError = row.querySelector('[data-quotation-cost-error]');
            if (!unitCostInput) {
                return true;
            }

            const rawValue = unitCostInput.value.trim();
            let message = '';

            if (rawValue === '') {
                message = requireValue ? 'Estimated Unit Cost is required.' : '';
            } else if (!isValidEstimatedUnitCost(rawValue)) {
                message = 'Estimated Unit Cost must be greater than 0.';
            }

            unitCostInput.setCustomValidity(message);
            unitCostInput.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (costError) {
                costError.textContent = showMessage ? message : '';
            }
            return message === '';
        };

        const validateQuotationQuantity = function (row, clearInvalidQuantity, requireValue, showMessage) {
            const quantityInput = row.querySelector('input[name="quantity[]"]');
            const unitSelect = row.querySelector('select[name="unit[]"]');
            if (!quantityInput || !unitSelect) {
                return true;
            }

            const rawValue = quantityInput.value.trim();
            const quantity = Number.parseFloat(rawValue || '0');
            const unit = unitSelect.value.toLowerCase();
            let message = '';

            if (rawValue === '') {
                message = requireValue ? 'Quantity is required.' : '';
            } else if (!Number.isFinite(quantity) || quantity <= 0) {
                message = 'Quantity must be greater than 0.';
            } else if (wholeCountUnits.includes(unit) && !Number.isInteger(quantity)) {
                message = 'Use a whole number for ' + unit + '.';
            }

            if (message !== ''
                && clearInvalidQuantity
                && rawValue !== ''
                && Number.isFinite(quantity)
                && quantity > 0
                && wholeCountUnits.includes(unit)
                && !Number.isInteger(quantity)) {
                quantityInput.value = '';
                message = 'Qty was cleared because ' + unit + ' needs a whole number.';
            }

            setQuantityMessage(row, showMessage ? message : '');
            return message === '';
        };

        const setUnitOptions = function (row, options, preferredUnit, isLocked, allowEmpty = false) {
            const unitSelect = row.querySelector('select[name="unit[]"]');
            if (!unitSelect) {
                return;
            }

            const normalizedPreferredUnit = (preferredUnit || '').toLowerCase();
            const selectedUnit = options.find(function (option) {
                return option.toLowerCase() === normalizedPreferredUnit;
            }) || (allowEmpty ? '' : options[0] || '');

            unitSelect.replaceChildren();
            if (allowEmpty) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select unit';
                placeholder.selected = selectedUnit === '';
                unitSelect.appendChild(placeholder);
            }
            options.forEach(function (option) {
                const optionElement = document.createElement('option');
                optionElement.value = option;
                optionElement.textContent = option;
                optionElement.selected = option.toLowerCase() === selectedUnit.toLowerCase();
                unitSelect.appendChild(optionElement);
            });

            unitSelect.classList.toggle('is-locked', isLocked);
            unitSelect.setAttribute('aria-readonly', isLocked ? 'true' : 'false');
            unitSelect.tabIndex = isLocked ? -1 : 0;

            const quantityInput = row.querySelector('input[name="quantity[]"]');
            const selectedUnitIsWhole = wholeCountUnits.includes(selectedUnit.toLowerCase());
            if (quantityInput) {
                quantityInput.min = selectedUnitIsWhole ? '1' : '0.01';
                quantityInput.step = selectedUnitIsWhole ? '1' : '0.01';
                quantityInput.inputMode = selectedUnitIsWhole ? 'numeric' : 'decimal';
            }
        };

        const setMaterialReferenceMessage = function (row, message) {
            const materialSelect = row.querySelector('select[name="material_id[]"]');
            const materialError = row.querySelector('[data-quotation-material-error]');
            if (!materialSelect) {
                return;
            }

            materialSelect.setCustomValidity(message);
            materialSelect.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (materialError) {
                materialError.textContent = message;
            }
        };

        const validateMaterialReference = function (row, showMessage) {
            const typeSelect = row.querySelector('select[name="item_type[]"]');
            const materialSelect = row.querySelector('select[name="material_id[]"]');
            if (!materialSelect || typeSelect?.value !== 'material') {
                setMaterialReferenceMessage(row, '');
                return true;
            }

            const message = materialSelect.value === ''
                ? 'Select a material or Manual / non-stock material.'
                : '';
            setMaterialReferenceMessage(row, showMessage ? message : '');
            return message === '';
        };

        const validateDuplicateMaterialReferences = function (showMessage) {
            const materialRows = Array.from(items?.querySelectorAll('[data-quotation-item]') || []);
            const selectedIds = new Map();
            materialRows.forEach(function (row) {
                const typeSelect = row.querySelector('select[name="item_type[]"]');
                const materialSelect = row.querySelector('select[name="material_id[]"]');
                const materialId = materialSelect?.value || '';
                if (typeSelect?.value === 'material' && /^[1-9]\d*$/.test(materialId)) {
                    selectedIds.set(materialId, (selectedIds.get(materialId) || 0) + 1);
                }
            });

            let isValid = true;
            materialRows.forEach(function (row) {
                const materialSelect = row.querySelector('select[name="material_id[]"]');
                const materialError = row.querySelector('[data-quotation-material-error]');
                const materialId = materialSelect?.value || '';
                const isDuplicate = /^[1-9]\d*$/.test(materialId) && (selectedIds.get(materialId) || 0) > 1;
                if (isDuplicate) {
                    isValid = false;
                    setMaterialReferenceMessage(row, showMessage ? 'This material is already added. Edit the existing row instead.' : '');
                } else if (materialError?.textContent === 'This material is already added. Edit the existing row instead.') {
                    setMaterialReferenceMessage(row, '');
                }
            });
            return isValid;
        };

        const clearMaterialDependentValues = function (row, preserveQuantity = false) {
            const itemName = row.querySelector('input[name="item_name[]"]');
            const quantityInput = row.querySelector('input[name="quantity[]"]');
            const quantityError = row.querySelector('[data-quotation-quantity-error]');
            const unitCostInput = row.querySelector('input[name="unit_cost[]"]');
            const costError = row.querySelector('[data-quotation-cost-error]');
            if (itemName) {
                itemName.value = '';
            }
            if (quantityInput && !preserveQuantity) {
                quantityInput.value = '';
                quantityInput.setCustomValidity('');
                quantityInput.removeAttribute('aria-invalid');
            }
            if (quantityError && !preserveQuantity) {
                quantityError.textContent = '';
            }
            if (unitCostInput) {
                unitCostInput.value = '';
                unitCostInput.setCustomValidity('');
                unitCostInput.removeAttribute('aria-invalid');
            }
            if (costError) {
                costError.textContent = '';
            }
        };

        const syncMaterialReference = function (row, resetForMaterialChange = false) {
            const typeSelect = row.querySelector('select[name="item_type[]"]');
            const materialReference = row.querySelector('[data-quotation-material-reference]');
            const materialSelect = row.querySelector('select[name="material_id[]"]');
            const isMaterial = typeSelect?.value === 'material';
            if (!isMaterial && materialSelect) {
                materialSelect.value = '';
            }
            const materialValue = materialSelect?.value || '';
            const selectedOption = materialSelect?.options[materialSelect.selectedIndex];
            const isLinkedMaterial = isMaterial && /^[1-9]\d*$/.test(materialValue);
            const isManualMaterial = isMaterial && materialValue === 'manual';
            const currentUnit = row.querySelector('select[name="unit[]"]')?.value || '';
            const itemName = row.querySelector('input[name="item_name[]"]');
            const quantityInput = row.querySelector('input[name="quantity[]"]');

            row.classList.toggle('is-material-item', isMaterial);

            if (materialReference) {
                materialReference.hidden = !isMaterial;
            }

            if (materialSelect) {
                materialSelect.required = isMaterial;
            }

            if (resetForMaterialChange && isMaterial) {
                const quantityRaw = quantityInput?.value.trim() || '';
                const materialUnit = selectedOption?.dataset.materialUnit || '';
                const quantityValue = Number.parseFloat(quantityRaw);
                const keepQuantity = isLinkedMaterial
                    && quantityRaw !== ''
                    && Number.isFinite(quantityValue)
                    && quantityValue > 0
                    && (!wholeCountUnits.includes(materialUnit.toLowerCase()) || Number.isInteger(quantityValue));
                clearMaterialDependentValues(row, keepQuantity);
            }

            if (isLinkedMaterial) {
                setUnitOptions(row, [selectedOption?.dataset.materialUnit || 'unit'], selectedOption?.dataset.materialUnit || 'unit', true);
            } else if (isMaterial) {
                const preserveManualUnit = !resetForMaterialChange && isManualMaterial && currentUnit !== '';
                setUnitOptions(row, unitOptionsByType.material, preserveManualUnit ? currentUnit : '', false, !preserveManualUnit);
            } else {
                setUnitOptions(row, unitOptionsByType[typeSelect?.value] || unitOptionsByType.other, currentUnit, false);
            }

            if (itemName) {
                itemName.readOnly = isLinkedMaterial;
                itemName.classList.toggle('is-linked-material-name', isLinkedMaterial);
                itemName.setAttribute('aria-readonly', isLinkedMaterial ? 'true' : 'false');

                if (isLinkedMaterial && selectedOption?.dataset.materialName) {
                    itemName.value = selectedOption.dataset.materialName;
                }
            }

            if (resetForMaterialChange && isLinkedMaterial && quantityInput && quantityInput.value.trim() === '') {
                quantityInput.value = '1';
            }

            validateQuotationQuantity(row, true, false, false);
            validateMaterialReference(row, false);
        };

        const restoreLocalQuotationDraft = function (draft) {
            if (!draft || !items || !template) {
                return false;
            }

            const savedRows = draft.rows.slice(0, 50);
            if (savedRows.length === 0) {
                return false;
            }

            items.replaceChildren();
            savedRows.forEach(function (savedRow) {
                items.appendChild(template.content.cloneNode(true));
                const row = items.lastElementChild;
                const typeSelect = row?.querySelector('select[name="item_type[]"]');
                const materialSelect = row?.querySelector('select[name="material_id[]"]');
                const itemName = row?.querySelector('input[name="item_name[]"]');
                const quantityInput = row?.querySelector('input[name="quantity[]"]');
                const unitSelect = row?.querySelector('select[name="unit[]"]');
                const unitCostInput = row?.querySelector('input[name="unit_cost[]"]');
                const notesInput = row?.querySelector('input[name="item_notes[]"]');
                const savedType = String(savedRow?.type || 'material').toLowerCase();
                const savedMaterialId = String(savedRow?.material_id || '');
                const savedUnit = String(savedRow?.unit || '').toLowerCase();

                if (typeSelect && Array.from(typeSelect.options).some(function (option) { return option.value === savedType; })) {
                    typeSelect.value = savedType;
                }
                if (materialSelect && Array.from(materialSelect.options).some(function (option) { return option.value === savedMaterialId; })) {
                    materialSelect.value = savedMaterialId;
                }
                if (savedType !== 'material') {
                    setUnitOptions(row, unitOptionsByType[savedType] || unitOptionsByType.other, savedUnit, false);
                } else if (savedMaterialId === 'manual') {
                    setUnitOptions(row, unitOptionsByType.material, savedUnit, false, savedUnit === '');
                }
                if (itemName) itemName.value = String(savedRow?.item_name || '');
                if (quantityInput) quantityInput.value = String(savedRow?.quantity || '');
                if (unitSelect && savedUnit !== '' && Array.from(unitSelect.options).some(function (option) { return option.value === savedUnit; })) {
                    unitSelect.value = savedUnit;
                }
                if (unitCostInput) unitCostInput.value = String(savedRow?.unit_cost || '');
                if (notesInput) notesInput.value = String(savedRow?.notes || '');

                syncMaterialReference(row);
            });

            if (marginInput) {
                marginInput.value = String(draft.markup_percent || '');
            }
            return true;
        };

        addButton?.addEventListener('click', function () {
            if (!items || !template || items.children.length >= 50) {
                return;
            }

            items.appendChild(template.content.cloneNode(true));
            syncMaterialReference(items.lastElementChild);
            updateQuotationPreview();
            updateEditSubmitState();
            scheduleLocalQuotationDraftSave();
        });

        items?.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-quotation-remove-item]');
            if (!removeButton || items.querySelectorAll('[data-quotation-item]').length <= 1) {
                return;
            }

            removeButton.closest('[data-quotation-item]')?.remove();
            validateDuplicateMaterialReferences(true);
            updateQuotationPreview();
            updateEditSubmitState();
            scheduleLocalQuotationDraftSave();
        });

        items?.addEventListener('change', function (event) {
            const row = event.target.closest('[data-quotation-item]');
            if (!row) {
                return;
            }

            if (event.target.matches('select[name="item_type[]"]')) {
                syncMaterialReference(row);
                validateDuplicateMaterialReferences(true);
            }
            if (event.target.matches('select[name="material_id[]"]')) {
                syncMaterialReference(row, true);
                validateMaterialReference(row, true);
                validateDuplicateMaterialReferences(true);
            }
            if (event.target.matches('select[name="unit[]"]')) {
                if (event.target.classList.contains('is-locked')) {
                    syncMaterialReference(row);
                } else {
                    validateQuotationQuantity(row, true, false, true);
                }
            }
            updateQuotationPreview();
            updateEditSubmitState();
            scheduleLocalQuotationDraftSave();
        });

        form?.addEventListener('submit', function (event) {
            if (!validateMarkup(true, true)) {
                event.preventDefault();
                marginInput?.focus();
                return;
            }

            const invalidMaterialReferenceRow = Array.from(items?.querySelectorAll('[data-quotation-item]') || []).find(function (row) {
                return !validateMaterialReference(row, true);
            });
            if (invalidMaterialReferenceRow) {
                event.preventDefault();
                invalidMaterialReferenceRow.querySelector('select[name="material_id[]"]')?.focus();
                return;
            }

            if (!validateDuplicateMaterialReferences(true)) {
                event.preventDefault();
                items?.querySelector('[data-quotation-material-error]:not(:empty)')?.closest('[data-quotation-material-reference]')?.querySelector('select[name="material_id[]"]')?.focus();
                return;
            }

            const invalidQuantityRow = Array.from(items?.querySelectorAll('[data-quotation-item]') || []).find(function (row) {
                return !validateQuotationQuantity(row, false, true, true);
            });
            if (invalidQuantityRow) {
                event.preventDefault();
                invalidQuantityRow.querySelector('input[name="quantity[]"]')?.focus();
                return;
            }

            const invalidCostRow = Array.from(items?.querySelectorAll('[data-quotation-item]') || []).find(function (row) {
                return !validateEstimatedUnitCost(row, true, true);
            });
            if (invalidCostRow) {
                event.preventDefault();
                invalidCostRow.querySelector('input[name="unit_cost[]"]')?.focus();
                return;
            }

            if (event.submitter?.hasAttribute('data-confirm-quotation-save')
                && !window.confirm('Create this initial quotation draft?')) {
                event.preventDefault();
                return;
            }

            if (event.submitter?.hasAttribute('data-confirm-quotation-update')
                && !window.confirm('Are you sure you want to save and update these quotation changes?')) {
                event.preventDefault();
                return;
            }

            saveLocalQuotationDraft(true);
            quotationSubmitAccepted = true;
        });

        document.addEventListener('click', function (event) {
            const link = event.target.closest('a[href]');
            if (isInitialQuotation || !link || link.target === '_blank' || link.hasAttribute('download') || !isQuotationDirty()) {
                return;
            }

            const destination = link.getAttribute('href') || '';
            if (destination === '' || destination.startsWith('#') || destination.startsWith('javascript:')) {
                return;
            }

            if (!window.confirm('You have unsaved changes. Leave without saving?')) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);

        window.addEventListener('beforeunload', function (event) {
            if (isInitialQuotation) {
                if (!quotationSubmitAccepted) {
                    saveLocalQuotationDraft();
                }
                return;
            }

            if (quotationSubmitAccepted || !isQuotationDirty()) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        });

        window.addEventListener('pagehide', function () {
            if (isInitialQuotation && !quotationSubmitAccepted) {
                saveLocalQuotationDraft();
            }
        });

        form?.querySelector('[data-quotation-cancel]')?.addEventListener('click', function (event) {
            if (!isInitialQuotation) {
                return;
            }

            saveLocalQuotationDraft();
            if (!readLocalQuotationDraft()) {
                return;
            }

            event.preventDefault();
            const destination = this.href;
            showQuotationDraftDiscardConfirm(function () {
                clearLocalQuotationDraft();
                window.location.assign(destination);
            });
        });

        form?.addEventListener('input', function (event) {
            const row = event.target.closest('[data-quotation-item]');
            if (event.target === marginInput) {
                validateMarkup(true, false);
            }
            if (row && event.target.matches('input[name="quantity[]"]')) {
                validateQuotationQuantity(row, false, false, true);
            }
            if (row && event.target.matches('input[name="unit_cost[]"]')) {
                validateEstimatedUnitCost(row, true, false);
            }
            updateQuotationPreview();
            updateEditSubmitState();
            scheduleLocalQuotationDraftSave();
        });
        marginInput?.addEventListener('blur', function () {
            validateMarkup(true, true);
        });
        items?.addEventListener('focusout', function (event) {
            const row = event.target.closest('[data-quotation-item]');
            if (!row) {
                return;
            }

            if (event.target.matches('input[name="quantity[]"]')) {
                validateQuotationQuantity(row, false, true, true);
            }
            if (event.target.matches('input[name="unit_cost[]"]')) {
                validateEstimatedUnitCost(row, true, true);
            }
        });
        form?.addEventListener('invalid', function (event) {
            if (event.target === marginInput) {
                validateMarkup(true, true);
                return;
            }

            const row = event.target.closest('[data-quotation-item]');
            if (!row) {
                return;
            }

            if (event.target.matches('input[name="quantity[]"]')) {
                validateQuotationQuantity(row, false, true, true);
            }
            if (event.target.matches('input[name="unit_cost[]"]')) {
                validateEstimatedUnitCost(row, true, true);
            }
            if (event.target.matches('select[name="material_id[]"]')) {
                validateMaterialReference(row, true);
            }
        }, true);
        form?.addEventListener('change', updateEditSubmitState);
        items?.querySelectorAll('[data-quotation-item]').forEach(function (row) {
            syncMaterialReference(row);
        });
        updateQuotationPreview();
        initialQuotationState = serializeQuotationForm();
        const savedLocalDraft = readLocalQuotationDraft();
        if (restoreLocalQuotationDraft(savedLocalDraft)) {
            updateQuotationPreview();
            validateDuplicateMaterialReferences(false);
            let hasShownRestoreNotice = false;
            try {
                hasShownRestoreNotice = localRestoreNoticeKey !== ''
                    && window.sessionStorage.getItem(localRestoreNoticeKey) === '1';
            } catch (error) {
                hasShownRestoreNotice = false;
            }
            if (!hasShownRestoreNotice && typeof window.showToast === 'function') {
                window.showToast('Unsaved quotation draft restored.', 'info');
                try {
                    window.sessionStorage.setItem(localRestoreNoticeKey, '1');
                } catch (error) {
                    // Walang gagawin kapag hindi available ang browser storage.
                }
            }
        }
        updateEditSubmitState();
    }

    const queryParams = new URLSearchParams(window.location.search);
    const clearInitialQuotationDraftId = Number.parseInt(queryParams.get('clear_initial_quotation_draft') || '0', 10);
    if (clearInitialQuotationDraftId > 0) {
        try {
            window.localStorage.removeItem('initial_quotation_draft:' + String(clearInitialQuotationDraftId));
            window.sessionStorage.removeItem('initial_quotation_restore_notice:' + String(clearInitialQuotationDraftId));
        } catch (error) {
            // Walang gagawin kapag hindi available ang browser storage.
        }
        queryParams.delete('clear_initial_quotation_draft');
        const cleanQuery = queryParams.toString();
        window.history.replaceState({}, '', window.location.pathname + (cleanQuery ? '?' + cleanQuery : '') + window.location.hash);
    }
    const inquiryIdFromUrl = Number.parseInt(queryParams.get('inquiry_id') || '0', 10);
    const urlOpenModalId = queryParams.get('open') || (inquiryIdFromUrl > 0 ? 'inquiryModal' + inquiryIdFromUrl : '');
    const urlOpenTab = queryParams.get('tab') || 'client';
    if (urlOpenModalId) {
        sessionStorage.removeItem(listStartAtTopKey);
        const modal = document.getElementById(urlOpenModalId);
        if (modal) {
            openModal(modal);
            activateModalTab(modal, urlOpenTab);
        }
    } else {
        const shouldStartAtTop = sessionStorage.getItem(listStartAtTopKey) === '1';
        if (shouldStartAtTop) {
            sessionStorage.removeItem(listStartAtTopKey);
            sessionStorage.removeItem('edgeLastInquiryModal');
            startInquiryListAtTop();
        } else {
            const lastOpenModalId = sessionStorage.getItem('edgeLastInquiryModal');
            const lastModal = lastOpenModalId ? document.getElementById(lastOpenModalId) : null;
            const lastCard = lastModal?.closest('.inquiry-card');
            if (lastCard) {
                lastCard.scrollIntoView({ behavior: 'instant', block: 'center' });
            }
        }
    }

    window.addEventListener('popstate', function (event) {
        const modalId = event.state?.inquiryModalId || new URLSearchParams(window.location.search).get('open');
        const targetTab = event.state?.inquiryTab || new URLSearchParams(window.location.search).get('tab') || 'client';
        const targetModal = modalId ? document.getElementById(modalId) : null;
        const openModalBeforeHistoryChange = document.querySelector('.inquiry-modal:not([hidden])');

        if (openModalBeforeHistoryChange?.dataset.quotationSending === '1') {
            const activeTab = openModalBeforeHistoryChange.querySelector('.inquiry-modal-tab.is-active')?.getAttribute('data-inquiry-tab') || 'quotation';
            if (targetModal !== openModalBeforeHistoryChange || targetTab !== activeTab) {
                pushModalHistory(openModalBeforeHistoryChange, activeTab);
            }
            return;
        }

        if (openModalBeforeHistoryChange?.dataset.reviewSaving === '1') {
            const activeTab = openModalBeforeHistoryChange.querySelector('.inquiry-modal-tab.is-active')?.getAttribute('data-inquiry-tab') || 'client';
            pushModalHistory(openModalBeforeHistoryChange, activeTab);
            return;
        }

        if (openModalBeforeHistoryChange?.dataset.inspectionScheduling === '1') {
            const activeTab = openModalBeforeHistoryChange.querySelector('.inquiry-modal-tab.is-active')?.getAttribute('data-inquiry-tab') || 'inspection';
            pushModalHistory(openModalBeforeHistoryChange, activeTab);
            return;
        }

        if (!targetModal && openModalBeforeHistoryChange && inquiryReviewHasChanges(openModalBeforeHistoryChange)) {
            showDiscardConfirm(
                openModalBeforeHistoryChange,
                function () {
                    discardInquiryReviewChanges(openModalBeforeHistoryChange);
                    closeModal(openModalBeforeHistoryChange);
                },
                function () {
                    const activeTab = openModalBeforeHistoryChange.querySelector('.inquiry-modal-tab.is-active')?.getAttribute('data-inquiry-tab') || 'client';
                    pushModalHistory(openModalBeforeHistoryChange, activeTab);
                }
            );
            return;
        }

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
        const confirmationMode = form.dataset.confirmationMode || 'submit';
        closeConfirm();

        if (confirmationMode === 'quotation-completeness') {
            form.dataset.completenessConfirmed = '1';
            form.requestSubmit();
            return;
        }

        form.dataset.confirmed = '1';
        form.requestSubmit();
    });

    confirmBox.addEventListener('click', function (event) {
        if (event.target === confirmBox) {
            closeConfirm();
        }
    });

    discardConfirmBox.querySelector('[data-inquiry-discard-keep]')?.addEventListener('click', function () {
        closeDiscardConfirm(true);
    });
    discardConfirmBox.querySelector('[data-inquiry-discard-yes]')?.addEventListener('click', function () {
        const discardAction = pendingDiscardAction;
        closeDiscardConfirm();
        discardAction?.();
    });
    discardConfirmBox.addEventListener('click', function (event) {
        if (event.target === discardConfirmBox) {
            closeDiscardConfirm(true);
        }
    });

    quotationDraftDiscardBox.querySelector('[data-quotation-draft-discard-keep]')?.addEventListener('click', closeQuotationDraftDiscardConfirm);
    quotationDraftDiscardBox.querySelector('[data-quotation-draft-discard-yes]')?.addEventListener('click', function () {
        const discardAction = pendingQuotationDraftDiscard;
        closeQuotationDraftDiscardConfirm();
        discardAction?.();
    });
    quotationDraftDiscardBox.addEventListener('click', function (event) {
        if (event.target === quotationDraftDiscardBox) {
            closeQuotationDraftDiscardConfirm();
        }
    });

    prerequisiteNotice.querySelector('[data-prerequisite-notice-ok]')?.addEventListener('click', closePrerequisiteNotice);
    prerequisiteNotice.addEventListener('click', function (event) {
        if (event.target === prerequisiteNotice) {
            closePrerequisiteNotice();
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

        if (!discardConfirmBox.hidden) {
            closeDiscardConfirm(true);
            return;
        }

        if (!quotationDraftDiscardBox.hidden) {
            closeQuotationDraftDiscardConfirm();
            return;
        }

        if (!prerequisiteNotice.hidden) {
            closePrerequisiteNotice();
            return;
        }

        document.querySelectorAll('.inquiry-archive-modal:not([hidden])').forEach(closeArchiveModal);
        document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(requestCloseModal);
    });

    window.addEventListener('beforeunload', function (event) {
        if (!document.querySelector('.inquiry-modal[data-review-saving="1"], .inquiry-modal[data-inspection-scheduling="1"]')) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });
});
