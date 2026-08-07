// Inquiry modal controls para malinis at walang inline JavaScript.
document.addEventListener('DOMContentLoaded', function () {
    const openButtons = document.querySelectorAll('[data-inquiry-modal-open]');
    const archiveOpenButtons = document.querySelectorAll('[data-archive-modal-open]');
    const toast = document.querySelector('[data-inquiry-toast]');
    const inquiryShell = document.querySelector('.inquiries-shell');
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

    const confirmBox = document.createElement('div');
    confirmBox.className = 'inquiry-confirm';
    confirmBox.hidden = true;
    confirmBox.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryConfirmTitle">',
        '<h3 id="inquiryConfirmTitle">Are you sure?</h3>',
        '<p data-inquiry-confirm-message>This action will update the inquiry.</p>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-secondary" data-inquiry-confirm-no>No</button>',
        '<button type="button" class="btn-primary" data-inquiry-confirm-yes>Yes</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(confirmBox);

    const showConfirm = function (form, message) {
        pendingConfirmForm = form;
        const messageBox = confirmBox.querySelector('[data-inquiry-confirm-message]');
        if (messageBox) {
            messageBox.textContent = message;
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

    openButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            const modalId = button.getAttribute('data-inquiry-modal-open');
            lastOpenButton = button;
            openModal(document.getElementById(modalId));
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
                closeModal(modal);
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
            const actionText = form.querySelector('.btn-primary')?.textContent?.includes('Reschedule')
                ? 'Reschedule this site inspection?'
                : 'Schedule this site inspection for the selected engineer?';
            showConfirm(form, actionText);
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

    const queryParams = new URLSearchParams(window.location.search);
    const urlOpenModalId = queryParams.get('open');
    if (urlOpenModalId) {
        const modal = document.getElementById(urlOpenModalId);
        if (modal) {
            openModal(modal);
        }
    } else {
        const lastOpenModalId = sessionStorage.getItem('edgeLastInquiryModal');
        const lastModal = lastOpenModalId ? document.getElementById(lastOpenModalId) : null;
        const lastCard = lastModal?.closest('.inquiry-card');
        if (lastCard) {
            lastCard.scrollIntoView({ behavior: 'instant', block: 'center' });
        }
    }

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
        document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(closeModal);
    });
});
