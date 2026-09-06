// Inquiry modal controls para malinis at walang inline JavaScript.
document.addEventListener('DOMContentLoaded', function () {
    const openButtons = document.querySelectorAll('[data-inquiry-modal-open]');
    const archiveOpenButtons = document.querySelectorAll('[data-archive-modal-open]');
    const inquiryShell = document.querySelector('.inquiries-shell');
    let pendingUnreadInquiryCount = Number.parseInt(inquiryShell?.dataset.pendingUnreadInquiryCount || '0', 10);
    let latestRevisionId = Number.parseInt(inquiryShell?.dataset.latestRevisionId || '0', 10);
    let latestRevisionUpdatedAt = inquiryShell?.dataset.latestRevisionUpdatedAt || '';
    let lastOpenButton = null;
    let pendingConfirmForm = null;

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

    const showLiveInquiryToast = function (message, inquiryId, targetTab) {
        const openToastInquiry = function () {
            const modal = document.getElementById('inquiryModal' + String(inquiryId || ''));
            if (!modal) {
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
            window.showToast(message, 'success', {
                onClick: Number.parseInt(inquiryId || '0', 10) > 0 ? openToastInquiry : null,
            });
        }
        playToastSound();
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
        link.addEventListener('click', function (event) {
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
    const liveSearchInput = filterForm?.querySelector('input[name="search"]');
    let liveSearchTimer = null;
    let liveSearchRequest = null;

    filterForm?.addEventListener('submit', showPageLoading);

    const syncLiveSearchEmptyState = function (visibleCardCount) {
        const currentCards = Array.from(document.querySelectorAll('[data-inquiry-card-id]'));
        let emptyMessage = document.querySelector('[data-inquiry-live-search-empty]');

        if (!emptyMessage && currentCards[0]?.parentElement) {
            emptyMessage = document.createElement('div');
            emptyMessage.className = 'inquiry-empty';
            emptyMessage.setAttribute('data-inquiry-live-search-empty', '');
            emptyMessage.textContent = 'No inquiries found.';
            currentCards[0].parentElement.after(emptyMessage);
        }

        if (emptyMessage) {
            emptyMessage.hidden = visibleCardCount !== 0;
        }
    };

    const filterCurrentChipCards = function (searchValue) {
        const keyword = searchValue.trim().toLocaleLowerCase();
        let visibleCardCount = 0;

        document.querySelectorAll('[data-inquiry-card-id]').forEach(function (card) {
            const isMatch = keyword === '' || card.textContent.toLocaleLowerCase().includes(keyword);
            card.hidden = !isMatch;
            if (isMatch) visibleCardCount += 1;
        });

        syncLiveSearchEmptyState(visibleCardCount);
    };

    const runLiveSearch = function () {
        const requestUrl = new URL(filterForm.getAttribute('action') || window.location.href, window.location.origin);
        const activeViewLink = document.querySelector('.inquiry-view-link.is-active');
        const activeStatusLink = document.querySelector('.inquiry-status-link.is-active');
        const activeViewUrl = activeViewLink ? new URL(activeViewLink.href) : null;
        const activeStatus = activeStatusLink?.dataset.status || '';
        const searchValue = liveSearchInput.value.trim();

        requestUrl.searchParams.delete('action');
        requestUrl.searchParams.delete('open');
        requestUrl.searchParams.delete('tab');
        requestUrl.searchParams.delete('inquiry_id');

        if (activeViewUrl?.searchParams.get('view') === 'archive') {
            requestUrl.searchParams.set('view', 'archive');
        } else {
            requestUrl.searchParams.delete('view');
        }

        if (activeStatus) requestUrl.searchParams.set('status', activeStatus);
        else requestUrl.searchParams.delete('status');
        if (searchValue) requestUrl.searchParams.set('search', searchValue);
        else requestUrl.searchParams.delete('search');

        liveSearchRequest?.abort();
        liveSearchRequest = new AbortController();

        fetch(requestUrl.toString(), {
            headers: { Accept: 'text/html' },
            cache: 'no-store',
            signal: liveSearchRequest.signal,
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Unable to search inquiries.');
                return response.text();
            })
            .then(function (html) {
                const resultDocument = new DOMParser().parseFromString(html, 'text/html');
                const matchingIds = new Set(Array.from(resultDocument.querySelectorAll('[data-inquiry-card-id]')).map(function (card) {
                    return card.getAttribute('data-inquiry-card-id');
                }));
                const currentCards = Array.from(document.querySelectorAll('[data-inquiry-card-id]'));
                let visibleCardCount = 0;

                currentCards.forEach(function (card) {
                    const isMatch = matchingIds.has(card.getAttribute('data-inquiry-card-id'));
                    card.hidden = !isMatch;
                    if (isMatch) visibleCardCount += 1;
                });

                syncLiveSearchEmptyState(visibleCardCount);

                window.history.replaceState(window.history.state, document.title, requestUrl.toString());
            })
            .catch(function (error) {
                if (error.name !== 'AbortError' && typeof window.showToast === 'function') {
                    window.showToast('Unable to search inquiries right now.', 'error');
                }
            });
    };

    liveSearchInput?.addEventListener('input', function () {
        window.clearTimeout(liveSearchTimer);
        filterCurrentChipCards(liveSearchInput.value);

        if (liveSearchInput.value === '') {
            runLiveSearch();
            return;
        }

        liveSearchTimer = window.setTimeout(runLiveSearch, 250);
    });

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
        const submitButton = form.querySelector('button[type="submit"]');
        if (!statusSelect) {
            return;
        }

        let originalStatus = statusSelect.value;
        const modal = form.closest('.inquiry-modal');
        const statusChip = modal?.querySelector('[data-modal-status-chip]');
        const statusClasses = [
            'status-select--pending',
            'status-select--verified',
            'status-select--inspection',
            'status-select--not-qualified',
        ];

        const syncStatusChip = function () {
            const isPendingReview = statusSelect.value === 'Pending Review';
            statusSelect.dataset.status = statusSelect.value;
            statusSelect.classList.remove(...statusClasses);
            submitButton?.setAttribute('aria-disabled', String(isPendingReview));

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
            event.preventDefault();

            if (statusSelect.value === 'Pending Review') {
                if (typeof window.showToast === 'function') {
                    window.showToast('Please update the status before saving.', 'warning', { duration: 3000 });
                } else {
                    window.alert('Please update the status before saving.');
                }
                statusSelect.focus();
                return;
            }

            if (form.dataset.submitting === '1') {
                return;
            }

            if (form.dataset.confirmed !== '1' && statusSelect.value !== originalStatus) {
                showConfirm(form, 'Change inquiry status from ' + originalStatus + ' to ' + statusSelect.value + '?');
                return;
            }

            delete form.dataset.confirmed;
            form.dataset.submitting = '1';
            if (submitButton) submitButton.disabled = true;

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
                        throw new Error(result.data.message || 'Unable to save inquiry review.');
                    }

                    originalStatus = result.data.status || statusSelect.value;
                    window.location.assign(result.data.redirect || window.location.href);
                })
                .catch(function (error) {
                    form.dataset.submitting = '0';
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.setAttribute('aria-disabled', String(statusSelect.value === 'Pending Review'));
                    }
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
                        window.alert("Notice: This stage is locked. Please review the inquiry and update the status selection to 'Verified Lead' at the bottom of the 'Contact & Review' tab to activate pricing tools.");
                    } else if (target === 'inspection') {
                        const quotationStatus = modal.dataset.quotationStatus || '';
                        const message = quotationStatus === 'sent'
                            ? 'Notice: This stage is locked. Inspection layout scheduling tools will activate automatically once the client officially approves the quotation draft via email.'
                            : 'Notice: This stage is locked. Please create the quotation first using the button below and send it to the client for financial review.';
                        window.alert(message);
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
        if (quotationPollInProgress || document.hidden || !inquiryShell?.hasAttribute('data-pending-unread-inquiry-count')) {
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

                const currentPendingUnreadCount = Number.parseInt(data.pending_unread_inquiry_count || '0', 10);
                if (currentPendingUnreadCount > pendingUnreadInquiryCount) {
                    showNewInquiryBellDot();
                    showLiveInquiryToast(
                        'Notification: A new client inquiry has just been received!',
                        Number.parseInt(data.latest_pending_inquiry_id || '0', 10),
                        'client'
                    );
                }
                pendingUnreadInquiryCount = currentPendingUnreadCount;
                inquiryShell.dataset.pendingUnreadInquiryCount = String(currentPendingUnreadCount);

                const currentRevisionId = Number.parseInt(data.latest_revision_id || '0', 10);
                const currentRevisionUpdatedAt = String(data.latest_revision_updated_at || '');
                const hasNewRevision = currentRevisionUpdatedAt > latestRevisionUpdatedAt
                    || (currentRevisionUpdatedAt === latestRevisionUpdatedAt && currentRevisionId > latestRevisionId);

                if (currentRevisionId > 0 && hasNewRevision) {
                    showNewInquiryBellDot();
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

                    if (previousStatus !== 'sent' || !['accepted', 'approved'].includes(currentStatus)) {
                        return;
                    }

                    const inspectionTab = modal.querySelector('[data-inquiry-stage="inspection"]');
                    if (inspectionTab) {
                        inspectionTab.disabled = false;
                        inspectionTab.setAttribute('aria-disabled', 'false');
                        inspectionTab.classList.remove('chip-disabled');
                    }

                    modal.querySelector('[data-inquiry-inspection-form]')?.removeAttribute('hidden');
                    modal.querySelector('[data-quotation-approved-banner]')?.removeAttribute('hidden');
                    const statusLabel = modal.querySelector('[data-quotation-status-label]');
                    if (statusLabel) {
                        statusLabel.textContent = quotation.label || 'Accepted';
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
            const sendingModal = form.closest('.inquiry-modal');
            if (submitButton) {
                submitButton.disabled = true;
                const spinner = document.createElement('span');
                const label = document.createElement('span');
                spinner.className = 'inquiry-send-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                label.textContent = 'Sending...';
                submitButton.classList.add('inquiry-send-button--loading');
                submitButton.replaceChildren(spinner, label);
            }
            if (sendingModal) {
                sendingModal.inert = true;
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
                    if (sendingModal) {
                        sendingModal.inert = false;
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
        const updateSubmitButton = form?.querySelector('[data-quotation-update-submit]');
        const isEditMode = form?.dataset.quotationEditMode === 'true';

        const serializeQuotationForm = function () {
            return form ? new URLSearchParams(new FormData(form)).toString() : '';
        };

        let initialQuotationState = '';

        const updateEditSubmitState = function () {
            if (!isEditMode || !updateSubmitButton) {
                return;
            }

            updateSubmitButton.disabled = serializeQuotationForm() === initialQuotationState;
        };

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
            updateEditSubmitState();
        });

        items?.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-quotation-remove-item]');
            if (!removeButton || items.querySelectorAll('[data-quotation-item]').length <= 1) {
                return;
            }

            removeButton.closest('[data-quotation-item]')?.remove();
            updateQuotationPreview();
            updateEditSubmitState();
        });

        form?.addEventListener('submit', function (event) {
            if (event.submitter?.hasAttribute('data-confirm-quotation-save')
                && !window.confirm('Are you sure you want to save this quotation draft?')) {
                event.preventDefault();
                return;
            }

            if (event.submitter?.hasAttribute('data-confirm-quotation-update')
                && !window.confirm('Are you sure you want to save and update these quotation changes?')) {
                event.preventDefault();
            }
        });

        quotationCreate.querySelector('[data-quotation-cancel]')?.addEventListener('click', function (event) {
            const hasCostBreakdownData = Array.from(items?.querySelectorAll('[data-quotation-item]') || []).some(function (item) {
                const itemName = item.querySelector('input[name="item_name[]"]')?.value.trim() || '';
                const unitCost = item.querySelector('input[name="unit_cost[]"]')?.value.trim() || '';
                return itemName !== '' || unitCost !== '';
            });

            if (hasCostBreakdownData
                && !window.confirm('You have unsaved changes in the cost breakdown. Are you sure you want to cancel and lose this data?')) {
                event.preventDefault();
            }
        });

        form?.addEventListener('input', function () {
            updateQuotationPreview();
            updateEditSubmitState();
        });
        form?.addEventListener('change', updateEditSubmitState);
        updateQuotationPreview();
        initialQuotationState = serializeQuotationForm();
        updateEditSubmitState();
    }

    const queryParams = new URLSearchParams(window.location.search);
    const inquiryIdFromUrl = Number.parseInt(queryParams.get('inquiry_id') || '0', 10);
    const urlOpenModalId = queryParams.get('open') || (inquiryIdFromUrl > 0 ? 'inquiryModal' + inquiryIdFromUrl : '');
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
