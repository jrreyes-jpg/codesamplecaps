// Load shared duplicate-window guard for Engineer pages.
(function () {
    if (document.querySelector('script[src$="/assets/js/app-window-guard.js"]')) {
        return;
    }

    const guardScript = document.createElement('script');
    guardScript.src = '/codesamplecaps/assets/js/app-window-guard.js';
    guardScript.defer = true;
    document.head.appendChild(guardScript);
})();

// Load shared Admin/Engineer header behavior once.
(function () {
    if (document.querySelector('script[src$="/SHARED/js/operations-header.js"]')) {
        return;
    }

    const headerScript = document.createElement('script');
    headerScript.src = '/codesamplecaps/SHARED/js/operations-header.js';
    headerScript.defer = true;
    document.head.appendChild(headerScript);
})();

const spotlightTask = (taskId) => {
    if (!taskId) {
        return;
    }

    const taskItem = document.querySelector(`[data-task-item-id="${taskId}"]`);

    if (!taskItem) {
        return;
    }

    taskItem.classList.add('is-spotlight');
    taskItem.scrollIntoView({ behavior: 'smooth', block: 'center' });

    window.setTimeout(() => {
        taskItem.classList.remove('is-spotlight');
    }, 2200);
};

const initTaskFilters = () => {
    const searchInput = document.querySelector('[data-task-search]');
    const statusFilter = document.querySelector('[data-task-status-filter]');
    const deadlineFilter = document.querySelector('[data-task-deadline-filter]');
    const taskItems = Array.from(document.querySelectorAll('[data-task-item]'));
    const quickButtons = Array.from(document.querySelectorAll('[data-task-quick-filter]'));
    const defaultQuickFilter = document.querySelector('[data-default-quick-filter]')?.value ?? '';

    if (!searchInput || !statusFilter || !deadlineFilter || taskItems.length === 0) {
        return;
    }

    let quickFilterValue = defaultQuickFilter;

    const setQuickFilter = (value) => {
        quickFilterValue = value;

        quickButtons.forEach((button) => {
            const buttonFilter = button.dataset.taskQuickFilter ?? '';
            button.classList.toggle('active', value !== '' && buttonFilter === value);
        });
    };

    const applyFilters = () => {
        const searchValue = searchInput.value.trim().toLowerCase();
        const statusValue = statusFilter.value;
        const deadlineValue = deadlineFilter.value;

        taskItems.forEach((item) => {
            const taskName = item.dataset.taskName ?? '';
            const projectName = item.dataset.projectName ?? '';
            const taskStatus = item.dataset.taskStatus ?? '';
            const deadlineGroup = item.dataset.deadlineGroup ?? '';
            const hasUpdate = item.dataset.taskHasUpdate ?? 'no';
            const isDueToday = item.dataset.taskIsDueToday ?? 'no';
            const isOverdue = item.dataset.taskIsOverdue ?? 'no';
            const isBlocked = item.dataset.taskIsBlocked ?? 'no';
            const isLocked = item.dataset.taskIsLocked ?? 'no';

            const matchesSearch =
                searchValue === '' ||
                taskName.includes(searchValue) ||
                projectName.includes(searchValue);
            const matchesStatus = statusValue === '' || taskStatus === statusValue;
            const matchesDeadline = deadlineValue === '' || deadlineGroup === deadlineValue;
            const matchesQuick =
                quickFilterValue === '' ||
                (quickFilterValue === 'all-open' && taskStatus !== 'completed' && isLocked !== 'yes') ||
                (quickFilterValue === 'overdue' && isOverdue === 'yes') ||
                (quickFilterValue === 'due-today' && isDueToday === 'yes') ||
                (quickFilterValue === 'no-update' && hasUpdate === 'no' && taskStatus !== 'completed' && isLocked !== 'yes') ||
                (quickFilterValue === 'blocked' && isBlocked === 'yes' && isLocked !== 'yes');

            item.hidden = !(matchesSearch && matchesStatus && matchesDeadline && matchesQuick);
        });
    };

    quickButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const nextQuickFilter = button.dataset.taskQuickFilter ?? '';
            const isResetButton = button.hasAttribute('data-reset-task-filters');

            searchInput.value = '';
            statusFilter.value = '';
            deadlineFilter.value = '';
            setQuickFilter(isResetButton ? '' : nextQuickFilter);
            applyFilters();
        });
    });

    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
    deadlineFilter.addEventListener('change', applyFilters);

    setQuickFilter(defaultQuickFilter);
    applyFilters();
};

const initEngineerClock = () => {
    const timeElement = document.querySelector('[data-engineer-time]');
    const dateElement = document.querySelector('[data-engineer-date]');

    if (
        !timeElement ||
        !dateElement ||
        window.edgeOperationsHeaderClockStarted ||
        document.querySelector('script[src$="/SHARED/js/operations-header.js"]')
    ) {
        return;
    }

    const timeFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    });

    const dateFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });

    const updateClock = () => {
        const now = new Date();
        timeElement.textContent = timeFormatter.format(now);
        dateElement.textContent = dateFormatter.format(now);
    };

    updateClock();
    window.setInterval(updateClock, 1000);
};

const initEngineerProfileMenu = () => {
    const root = document.querySelector('[data-engineer-profile-root]');
    const toggle = document.querySelector('[data-engineer-profile-toggle]');
    const dropdown = document.querySelector('[data-engineer-profile-dropdown]');
    const profileModal = document.querySelector('[data-engineer-profile-modal]');
    const profileOpenButtons = document.querySelectorAll('[data-engineer-profile-modal-open]');
    const profileClose = document.querySelector('[data-engineer-profile-modal-close]');
    const photoModal = document.querySelector('[data-engineer-photo-modal]');
    const photoOpenButtons = document.querySelectorAll('[data-engineer-photo-preview]');
    const photoClose = document.querySelector('[data-engineer-photo-modal-close]');
    const photoChangeButtons = document.querySelectorAll('[data-engineer-photo-change]');
    const photoSave = document.querySelector('[data-engineer-photo-save]');
    const photoCancel = document.querySelector('[data-engineer-photo-cancel]');
    const photoActions = document.querySelector('[data-engineer-photo-actions]');
    const photoStatus = document.querySelector('[data-engineer-photo-status]');
    const confirmModal = document.querySelector('[data-engineer-confirm-modal]');
    const confirmYes = document.querySelector('[data-engineer-confirm-yes]');
    const confirmNo = document.querySelector('[data-engineer-confirm-no]');
    const profileForm = document.querySelector('[data-engineer-profile-form]');
    const profileError = document.querySelector('[data-profile-form-error]');
    let confirmedSubmit = false;

    if (!root || !toggle || !dropdown) {
        return;
    }

    const closeMenu = () => {
        dropdown.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    };

    const openMenu = () => {
        dropdown.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
    };

    const openModal = (modal) => {
        if (!modal) {
            return;
        }

        modal.hidden = false;
        closeMenu();
    };

    const closeModal = (modal) => {
        if (modal) {
            modal.hidden = true;
        }
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        dropdown.hidden ? openMenu() : closeMenu();
    });

    profileOpenButtons.forEach((button) => {
        button.addEventListener('click', () => openModal(profileModal));
    });
    profileClose?.addEventListener('click', () => closeModal(profileModal));
    photoOpenButtons.forEach((button) => {
        button.addEventListener('click', () => openModal(photoModal));
    });
    photoClose?.addEventListener('click', () => closeModal(photoModal));
    photoChangeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const photoInput = document.querySelector('[data-profile-photo-input]');

            if (photoInput) {
                photoInput.click();
            }
        });
    });

    photoSave?.addEventListener('click', () => {
        const photoInput = document.querySelector('[data-profile-photo-input]');

        if (!photoInput?.files?.length) {
            if (photoStatus) {
                photoStatus.textContent = 'Please choose a photo first.';
                photoStatus.hidden = false;
            }
            return;
        }

        openModal(confirmModal);
    });

    photoCancel?.addEventListener('click', () => {
        const photoInput = document.querySelector('[data-profile-photo-input]');
        if (photoInput) {
            photoInput.value = '';
        }

        if (photoActions) {
            photoActions.hidden = true;
        }

        if (photoStatus) {
            photoStatus.textContent = '';
            photoStatus.hidden = true;
        }
    });

    profileForm?.addEventListener('submit', (event) => {
        if (confirmedSubmit) {
            return;
        }

        event.preventDefault();
        openModal(confirmModal);
    });

    confirmYes?.addEventListener('click', () => {
        if (!profileForm) {
            return;
        }

        confirmedSubmit = true;
        profileForm.submit();
    });

    confirmNo?.addEventListener('click', () => {
        confirmedSubmit = false;
        closeModal(confirmModal);
    });

    profileModal?.addEventListener('click', (event) => {
        if (event.target === profileModal) {
            closeModal(profileModal);
        }
    });

    photoModal?.addEventListener('click', (event) => {
        if (event.target === photoModal) {
            closeModal(photoModal);
        }
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
            closeModal(profileModal);
            closeModal(photoModal);
            closeModal(confirmModal);
        }
    });
};

const initProfilePhotoPreview = () => {
    const input = document.querySelector('[data-profile-photo-input]');
    const previewBox = document.querySelector('.engineer-profile-modal__avatar');
    const profileError = document.querySelector('[data-profile-form-error]');
    const photoModal = document.querySelector('[data-engineer-photo-modal]');
    const photoActions = document.querySelector('[data-engineer-photo-actions]');
    const photoStatus = document.querySelector('[data-engineer-photo-status]');
    const photoCancel = document.querySelector('[data-engineer-photo-cancel]');
    const photoPanel = document.querySelector('.engineer-photo-modal__panel');
    let modalImage = document.querySelector('.engineer-photo-modal__panel img');
    const originalModalImageSrc = modalImage?.getAttribute('src') || '';

    if (!input || !previewBox) {
        return;
    }

    const showError = (message) => {
        previewBox.classList.remove('is-valid');
        previewBox.classList.add('is-invalid');
        photoPanel?.classList.remove('is-valid');
        photoPanel?.classList.add('is-invalid');

        if (!profileError) {
            if (photoStatus) {
                photoStatus.textContent = message;
                photoStatus.classList.remove('is-success');
                photoStatus.classList.add('is-error');
                photoStatus.hidden = false;
            }
            return;
        }

        profileError.textContent = message;
        profileError.hidden = false;

        if (photoStatus) {
            photoStatus.textContent = message;
            photoStatus.classList.remove('is-success');
            photoStatus.classList.add('is-error');
            photoStatus.hidden = false;
        }
    };

    const clearError = () => {
        previewBox.classList.remove('is-invalid');
        photoPanel?.classList.remove('is-invalid');

        if (!profileError) {
            return;
        }

        profileError.textContent = '';
        profileError.hidden = true;
    };

    input.addEventListener('change', () => {
        const file = input.files?.[0];

        clearError();

        if (!file) {
            return;
        }

        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            input.value = '';
            showError('Use JPG, PNG, or WEBP only.');
            if (photoActions) {
                photoActions.hidden = true;
            }
            return;
        }

        if (file.size > 3 * 1024 * 1024) {
            input.value = '';
            showError('Profile photo must be 3MB or smaller.');
            if (photoActions) {
                photoActions.hidden = true;
            }
            return;
        }

        const previewUrl = URL.createObjectURL(file);
        let image = previewBox.querySelector('img');
        const initial = previewBox.querySelector('span');

        if (!image) {
            image = document.createElement('img');
            image.setAttribute('alt', 'Selected profile photo preview');
            image.setAttribute('data-profile-preview-image', '');
            previewBox.appendChild(image);
        }

        if (initial) {
            initial.remove();
        }

        image.src = previewUrl;

        if (!modalImage && photoPanel) {
            photoPanel.textContent = '';
            modalImage = document.createElement('img');
            modalImage.setAttribute('alt', 'Selected profile photo preview');
            photoPanel.appendChild(modalImage);
        }

        if (modalImage) {
            modalImage.src = previewUrl;
        }

        previewBox.classList.add('is-valid');
        photoPanel?.classList.add('is-valid');

        if (photoStatus) {
            photoStatus.textContent = 'New photo selected. Preview shown above.';
            photoStatus.classList.remove('is-error');
            photoStatus.classList.add('is-success');
            photoStatus.hidden = false;
        }

        if (photoActions) {
            photoActions.hidden = false;
        }

        if (photoModal) {
            photoModal.hidden = false;
        }
    });

    photoCancel?.addEventListener('click', () => {
        if (modalImage && originalModalImageSrc !== '') {
            modalImage.src = originalModalImageSrc;
        }

        previewBox.classList.remove('is-valid', 'is-invalid');
        photoPanel?.classList.remove('is-valid', 'is-invalid');
    });
};

const initEngineerPasswordChange = () => {
    const panel = document.querySelector('[data-engineer-password-panel]');
    if (!panel) {
        return;
    }

    const start = panel.querySelector('[data-engineer-password-start]');
    const form = panel.querySelector('[data-engineer-password-form]');
    const send = panel.querySelector('[data-engineer-password-send]');
    const save = panel.querySelector('[data-engineer-password-save]');
    const status = panel.querySelector('[data-engineer-password-status]');
    const otp = panel.querySelector('[data-engineer-password-otp]');
    const password = panel.querySelector('[data-engineer-new-password]');
    const confirm = panel.querySelector('[data-engineer-confirm-password]');
    const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
    let sendLocked = false;

    const setStatus = (message, type = 'error') => {
        if (!status) {
            return;
        }

        status.textContent = message;
        status.classList.toggle('is-success', type === 'success');
        status.classList.toggle('is-error', type !== 'success');
        status.hidden = false;
    };

    const rules = {
        length: (value) => value.length >= 8,
        upper: (value) => /[A-Z]/.test(value),
        lower: (value) => /[a-z]/.test(value),
        number: (value) => /\d/.test(value),
        symbol: (value) => /[^A-Za-z0-9]/.test(value),
    };

    const updateStrength = () => {
        const value = password?.value || '';
        Object.entries(rules).forEach(([rule, test]) => {
            panel.querySelector(`[data-rule="${rule}"]`)?.classList.toggle('is-valid', test(value));
        });
    };

    const postPasswordAction = async (payload) => {
        const body = new URLSearchParams({ csrf_token: csrf, ...payload });
        const response = await fetch('/codesamplecaps/ENGINEER/actions/change_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        return response.json();
    };

    start?.addEventListener('click', () => {
        if (!window.confirm('Send a verification code to your registered email?')) {
            return;
        }

        form.hidden = false;
        send?.click();
    });

    send?.addEventListener('click', async () => {
        if (sendLocked) {
            return;
        }

        sendLocked = true;
        send.disabled = true;
        setStatus('Sending verification code...', 'success');

        const result = await postPasswordAction({ password_action: 'send_otp' });
        setStatus(result.message, result.ok ? 'success' : 'error');

        window.setTimeout(() => {
            sendLocked = false;
            send.disabled = false;
        }, Number(result.cooldown || 60) * 1000);
    });

    otp?.addEventListener('input', () => {
        otp.value = otp.value.replace(/\D/g, '').slice(0, 6);
    });

    password?.addEventListener('input', updateStrength);

    save?.addEventListener('click', async () => {
        [otp, password, confirm].forEach((field) => field?.classList.remove('is-invalid'));

        if (!otp?.value || otp.value.length !== 6) {
            otp?.classList.add('is-invalid');
            setStatus('Enter the 6-digit verification code.');
            return;
        }

        const passValue = password?.value || '';
        const isStrong = Object.values(rules).every((test) => test(passValue));
        if (!isStrong) {
            password?.classList.add('is-invalid');
            setStatus('Password must pass all strength rules.');
            return;
        }

        if (passValue !== (confirm?.value || '')) {
            confirm?.classList.add('is-invalid');
            setStatus('Passwords do not match.');
            return;
        }

        const result = await postPasswordAction({
            password_action: 'change_password',
            otp: otp.value,
            new_password: passValue,
            confirm_password: confirm.value,
        });

        setStatus(result.message, result.ok ? 'success' : 'error');
        if (result.ok) {
            otp.value = '';
            password.value = '';
            confirm.value = '';
            updateStrength();
        }
    });
};

document.addEventListener('DOMContentLoaded', () => {
    const taskFromQuery = new URLSearchParams(window.location.search).get('task');
    initEngineerClock();
    initEngineerProfileMenu();
    initProfilePhotoPreview();
    initEngineerPasswordChange();
    initTaskFilters();

    if (taskFromQuery) {
        window.setTimeout(() => {
            spotlightTask(taskFromQuery);
        }, 120);
    }
});
