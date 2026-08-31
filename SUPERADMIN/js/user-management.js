// JS ng User Management page. Ito ang para sa search, edit/save, at create user modal.
document.addEventListener('DOMContentLoaded', function () {
    function isValidName(value) {
        return /^[\p{L} .'-]+$/u.test(value.trim()) && /[\p{L}]{2,}/u.test(value);
    }

    function normalizePhMobile(value) {
        let digits = String(value || '').replace(/\D/g, '');

        if (digits === '') {
            return '';
        }

        if (digits.startsWith('639')) {
            digits = '09' + digits.slice(3);
        } else if (digits.startsWith('9')) {
            digits = '0' + digits;
        } else if (!digits.startsWith('09')) {
            digits = '09' + digits.replace(/^0+/, '');
        }

        return digits.slice(0, 11);
    }

    function isValidPhone(value) {
        return /^09\d{9}$/.test(normalizePhMobile(value));
    }

    function isValidEmail(value) {
        return /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(value.trim());
    }

    function setFieldState(input, isValid, message) {
        input.classList.toggle('is-invalid-live', !isValid);
        input.classList.toggle('is-valid-live', isValid && input.value.trim() !== '');

        const error = input.closest('.form-group')?.querySelector('[data-field-error]');
        if (error) {
            error.textContent = isValid ? '' : message;
            error.hidden = isValid;
        }
    }

    function scorePassword(value) {
        let score = 0;
        if (value.length >= 12) score++;
        if (/[A-Z]/.test(value)) score++;
        if (/[a-z]/.test(value)) score++;
        if (/\d/.test(value)) score++;
        if (/[^A-Za-z0-9]/.test(value)) score++;
        return score;
    }

    function applyStrengthUI(input, indicator) {
        const score = scorePassword(input.value);
        let text = 'Weak';
        let cls = 'weak';

        if (score >= 5) {
            text = 'Super Strong';
            cls = 'super-strong';
        } else if (score === 4) {
            text = 'Strong';
            cls = 'strong';
        } else if (score === 3) {
            text = 'Medium';
            cls = 'medium';
        }

        indicator.textContent = 'Strength: ' + text;
        indicator.className = 'pass-indicator ' + cls;
        input.classList.remove('weak-border', 'medium-border', 'strong-border');
        if (cls === 'weak') input.classList.add('weak-border');
        else if (cls === 'medium') input.classList.add('medium-border');
        else input.classList.add('strong-border');
    }

    const tempPass = document.getElementById('password');
    const tempIndicator = document.getElementById('tempPassStrength');
    if (tempPass && tempIndicator) {
        tempPass.addEventListener('input', function () {
            applyStrengthUI(tempPass, tempIndicator);
        });
    }

    function bindUserToast(toast) {
        const closeToast = function () {
            toast.classList.add('is-hiding');
            window.setTimeout(() => toast.remove(), 220);
        };

        toast.querySelector('[data-user-toast-close]')?.addEventListener('click', closeToast);
        toast.addEventListener('click', function (event) {
            if (event.target === toast) {
                closeToast();
            }
        });

        window.setTimeout(function () {
            closeToast();
        }, toast.classList.contains('user-toast-error') ? 9000 : 7000);
    }

    function createUserToast(message, type) {
        const toast = document.createElement('div');
        let toastClass = 'user-toast-success';
        if (type === 'error') {
            toastClass = 'user-toast-error';
        } else if (type === 'warning') {
            toastClass = 'user-toast-warning';
        }
        toast.className = 'user-toast ' + toastClass;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.setAttribute('data-user-toast', '');
        toast.innerHTML = '<span data-user-toast-text></span><button type="button" class="user-toast__close" aria-label="Close notification" data-user-toast-close>&times;</button><span class="user-toast__progress" aria-hidden="true"></span>';
        toast.querySelector('[data-user-toast-text]').textContent = message;
        document.body.appendChild(toast);
        bindUserToast(toast);
    }

    const serverToasts = document.querySelectorAll('[data-user-toast]');
    if (serverToasts.length > 0) {
        sessionStorage.removeItem('superadmin_user_toast');
    }

    serverToasts.forEach(function (toast) {
        const toastText = toast.textContent.toLowerCase();
        if (toast.classList.contains('user-toast-success') && toastText.includes('deactivated')) {
            toast.classList.remove('user-toast-success');
            toast.classList.add('user-toast-warning');
        }
        bindUserToast(toast);
    });

    const storedToast = sessionStorage.getItem('superadmin_user_toast');
    if (storedToast && serverToasts.length === 0) {
        sessionStorage.removeItem('superadmin_user_toast');
        try {
            const parsedToast = JSON.parse(storedToast);
            if (parsedToast.message) {
                createUserToast(parsedToast.message, parsedToast.type || 'success');
            }
        } catch (error) {
            createUserToast(storedToast, 'success');
        }
    }

    document.addEventListener('submit', function (event) {
        const form = event.target.closest('[data-confirm-message]');
        if (!form) {
            return;
        }

        if (!window.confirm(form.getAttribute('data-confirm-message') || 'Continue?')) {
            event.preventDefault();
            return;
        }

        sessionStorage.removeItem('superadmin_user_toast');
    });

    document.querySelector('[data-role-filter-select]')?.addEventListener('change', function (event) {
        event.target.closest('form')?.submit();
    });

    const closeUserActionMenus = function (exceptMenu) {
        let hasOpenMenu = false;
        document.querySelectorAll('[data-user-actions-menu]').forEach(function (menu) {
            if (menu === exceptMenu) {
                hasOpenMenu = true;
                return;
            }
            menu.querySelector('[data-user-actions-list]')?.setAttribute('hidden', 'hidden');
            menu.querySelector('[data-user-actions-toggle]')?.setAttribute('aria-expanded', 'false');
        });

        if (!hasOpenMenu) {
            document.body.classList.remove('user-actions-modal-open');
            document.body.style.overflow = '';
        }
    };

    document.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-user-actions-toggle]');
        const closeButton = event.target.closest('[data-user-actions-close]');
        const blockedAction = event.target.closest('[data-user-blocked-toast]');
        const menu = event.target.closest('[data-user-actions-menu]');

        if (closeButton) {
            event.preventDefault();
            closeUserActionMenus(null);
            return;
        }

        if (blockedAction) {
            event.preventDefault();
            closeUserActionMenus(null);
            createUserToast(blockedAction.getAttribute('data-user-blocked-toast') || 'This user still has active work.', 'error');
            return;
        }

        if (toggle && menu) {
            event.preventDefault();
            const list = menu.querySelector('[data-user-actions-list]');
            const willOpen = list?.hasAttribute('hidden');
            closeUserActionMenus(menu);
            if (willOpen) {
                list?.removeAttribute('hidden');
                toggle.setAttribute('aria-expanded', 'true');
                document.body.classList.add('user-actions-modal-open');
                document.body.style.overflow = 'hidden';
            } else {
                list?.setAttribute('hidden', 'hidden');
                toggle.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('user-actions-modal-open');
                document.body.style.overflow = '';
            }
            return;
        }

        if (!menu) {
            closeUserActionMenus(null);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeUserActionMenus(null);
        }
    });

    const syncPhoneInput = function (input) {
        input.value = normalizePhMobile(input.value);
    };

    const ensurePhonePrefix = function (input) {
        if (input.value.trim() === '') {
            input.value = '09';
        } else {
            syncPhoneInput(input);
        }
    };

    document.querySelectorAll('[data-ph-phone-lock-prefix], [data-field="phone"]').forEach(function (input) {
        input.addEventListener('focus', function () {
            if (!input.hasAttribute('readonly')) {
                ensurePhonePrefix(input);
            }
        });

        input.addEventListener('input', function () {
            syncPhoneInput(input);
            if (input.value.trim() !== '') {
                input.classList.toggle('is-valid-live', isValidPhone(input.value));
                input.classList.toggle('is-invalid-live', !isValidPhone(input.value));
            }
        });

        input.addEventListener('paste', function (event) {
            const pastedText = event.clipboardData?.getData('text') || '';
            if (pastedText !== '') {
                event.preventDefault();
                input.value = normalizePhMobile(pastedText);
                input.classList.toggle('is-valid-live', isValidPhone(input.value));
                input.classList.toggle('is-invalid-live', !isValidPhone(input.value));
            } else {
                window.setTimeout(function () {
                    syncPhoneInput(input);
                }, 0);
            }
        });
    });

    const editModal = document.querySelector('[data-edit-user-modal]');
    const editForm = document.querySelector('[data-edit-user-form]');
    if (editModal && editForm) {
        const editUserId = editForm.querySelector('[data-edit-user-id]');
        const editName = editForm.querySelector('#edit_full_name');
        const editEmail = editForm.querySelector('#edit_email');
        const editPhone = editForm.querySelector('#edit_phone');
        const editStatusDate = editForm.querySelector('[data-edit-status-date]');

        const clearEditValidation = function () {
            [editName, editEmail, editPhone].forEach(function (input) {
                input?.classList.remove('is-invalid-live', 'is-valid-live');
                const error = input?.closest('.form-group')?.querySelector('[data-field-error]');
                if (error) {
                    error.textContent = '';
                    error.hidden = true;
                }
            });
        };

        const closeEditModal = function () {
            editModal.hidden = true;
            editModal.classList.remove('is-open');
            document.body.style.overflow = '';
            clearEditValidation();
        };

        const validateEditForm = function (showErrors) {
            if (editPhone) {
                editPhone.value = normalizePhMobile(editPhone.value);
            }

            const checks = [
                [editName, editName && isValidName(editName.value), 'Use a real name. Letters, spaces, dot, hyphen, or apostrophe only.'],
                [editEmail, editEmail && isValidEmail(editEmail.value), 'Use a valid email address.'],
                [editPhone, editPhone && isValidPhone(editPhone.value), 'Use 09 plus 9 more digits.'],
            ];

            let firstInvalid = null;
            checks.forEach(function ([input, isValid, message]) {
                if (!input) return;
                if (showErrors || input.value.trim() !== '') {
                    setFieldState(input, Boolean(isValid), message);
                }
                if (!isValid && !firstInvalid) {
                    firstInvalid = input;
                }
            });

            return firstInvalid;
        };

        document.querySelectorAll('[data-open-edit-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (editUserId) editUserId.value = button.getAttribute('data-user-id') || '';
                if (editName) editName.value = button.getAttribute('data-user-name') || '';
                if (editEmail) editEmail.value = button.getAttribute('data-user-email') || '';
                if (editPhone) editPhone.value = normalizePhMobile(button.getAttribute('data-user-phone') || '');
                if (editStatusDate) editStatusDate.textContent = button.getAttribute('data-user-status-date') || 'Not set';
                clearEditValidation();
                closeUserActionMenus(null);
                editModal.hidden = false;
                editModal.classList.add('is-open');
                document.body.style.overflow = 'hidden';
                window.setTimeout(() => editName?.focus(), 50);
            });
        });

        document.querySelectorAll('[data-close-edit-modal]').forEach((button) => button.addEventListener('click', closeEditModal));

        editModal.addEventListener('click', function (event) {
            if (event.target === editModal) {
                closeEditModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && editModal.classList.contains('is-open')) {
                closeEditModal();
            }
        });

        [editName, editEmail, editPhone].forEach(function (input) {
            input?.addEventListener('input', function () {
                validateEditForm(false);
            });
            input?.addEventListener('change', function () {
                validateEditForm(false);
            });
        });

        editForm.addEventListener('submit', function (event) {
            const firstInvalid = validateEditForm(true);
            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus();
            }
        });
    }

    const userManagementShell = document.querySelector('[data-user-management-shell]');
    const createUserModal = document.querySelector('[data-user-create-modal]');
    if (userManagementShell && createUserModal) {
        const openButtons = document.querySelectorAll('[data-open-create-modal]');
        const closeButtons = document.querySelectorAll('[data-close-create-modal]');
        const initialFocusTarget = createUserModal.querySelector('#full_name');
        const shouldOpenOnLoad = userManagementShell.getAttribute('data-create-modal-default-open') === 'true';

        const openCreateModal = function () {
            createUserModal.hidden = false;
            createUserModal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            if (initialFocusTarget) {
                window.setTimeout(() => initialFocusTarget.focus(), 50);
            }
        };

        const closeCreateModal = function () {
            createUserModal.hidden = true;
            createUserModal.classList.remove('is-open');
            document.body.style.overflow = '';
        };

        openButtons.forEach((button) => button.addEventListener('click', openCreateModal));
        closeButtons.forEach((button) => button.addEventListener('click', closeCreateModal));

        createUserModal.addEventListener('click', function (event) {
            if (event.target === createUserModal) {
                closeCreateModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && createUserModal.classList.contains('is-open')) {
                closeCreateModal();
            }
        });

        if (shouldOpenOnLoad) {
            openCreateModal();
        }
    }

    const resetModal = document.querySelector('[data-reset-password-modal]');
    const resetForm = document.querySelector('[data-reset-password-form]');
    if (resetModal && resetForm) {
        const resetUserId = resetForm.querySelector('[data-reset-password-user-id]');
        const resetPassword = resetForm.querySelector('#reset_new_password');
        const resetIndicator = resetForm.querySelector('#resetPassStrength');
        const resetTarget = resetModal.querySelector('[data-reset-password-target]');

        const openResetModal = function (button) {
            closeUserActionMenus(null);
            if (resetUserId) {
                resetUserId.value = button.getAttribute('data-user-id') || '';
            }
            if (resetTarget) {
                resetTarget.textContent = button.getAttribute('data-user-name') || 'User account';
            }
            if (resetPassword) {
                resetPassword.value = '';
                resetPassword.classList.remove('is-invalid-live', 'is-valid-live', 'weak-border', 'medium-border', 'strong-border');
            }
            if (resetIndicator) {
                resetIndicator.textContent = 'Strength: -';
                resetIndicator.className = 'pass-indicator';
            }
            resetModal.hidden = false;
            resetModal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            window.setTimeout(() => resetPassword?.focus(), 50);
        };

        const closeResetModal = function () {
            resetModal.hidden = true;
            resetModal.classList.remove('is-open');
            document.body.style.overflow = '';
        };

        document.querySelectorAll('[data-open-reset-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                openResetModal(button);
            });
        });

        document.querySelectorAll('[data-close-reset-modal]').forEach((button) => button.addEventListener('click', closeResetModal));

        resetModal.addEventListener('click', function (event) {
            if (event.target === resetModal) {
                closeResetModal();
            }
        });

        resetPassword?.addEventListener('input', function () {
            if (resetIndicator) {
                applyStrengthUI(resetPassword, resetIndicator);
            }
            setFieldState(resetPassword, scorePassword(resetPassword.value) >= 5, 'Password needs 12+ chars, uppercase, lowercase, number, and symbol.');
        });

        resetForm.addEventListener('submit', function (event) {
            if (!resetPassword || scorePassword(resetPassword.value) < 5) {
                event.preventDefault();
                if (resetPassword) {
                    setFieldState(resetPassword, false, 'Password needs 12+ chars, uppercase, lowercase, number, and symbol.');
                    resetPassword.focus();
                }
            }
        });
    }

    const createUserForm = document.querySelector('[data-user-create-form]');
    if (createUserForm) {
        const nameInput = createUserForm.querySelector('#full_name');
        const emailInput = createUserForm.querySelector('#email');
        const phoneInput = createUserForm.querySelector('#phone');
        const roleInput = createUserForm.querySelector('#role');
        const passwordInput = createUserForm.querySelector('#password');

        const validateCreateForm = function (showErrors) {
            if (phoneInput) {
                phoneInput.value = normalizePhMobile(phoneInput.value);
            }
            const checks = [
                [nameInput, nameInput && isValidName(nameInput.value), 'Use a real name. Letters, spaces, dot, hyphen, or apostrophe only.'],
                [emailInput, emailInput && isValidEmail(emailInput.value), 'Use a valid email address.'],
                [phoneInput, phoneInput && isValidPhone(phoneInput.value), 'Use 09 plus 9 more digits.'],
                [roleInput, roleInput && roleInput.value !== '', 'Select a role.'],
                [passwordInput, passwordInput && scorePassword(passwordInput.value) >= 5, 'Password needs 12+ chars, uppercase, lowercase, number, and symbol.'],
            ];

            let firstInvalid = null;
            checks.forEach(function ([input, isValid, message]) {
                if (!input) return;
                if (showErrors || input.value.trim() !== '') {
                    setFieldState(input, Boolean(isValid), message);
                }
                if (!isValid && !firstInvalid) {
                    firstInvalid = input;
                }
            });

            return firstInvalid;
        };

        [nameInput, emailInput, phoneInput, roleInput, passwordInput].forEach(function (input) {
            input?.addEventListener('input', function () {
                validateCreateForm(false);
            });
            input?.addEventListener('change', function () {
                validateCreateForm(false);
            });
        });

        createUserForm.addEventListener('submit', function (event) {
            const firstInvalid = validateCreateForm(true);
            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus();
            }
        });
    }

    const userSearchInput = document.querySelector('[data-user-search]');
    const userTableBody = document.querySelector('[data-user-table-body]');
    if (userSearchInput && userTableBody) {
        const rows = Array.from(userTableBody.querySelectorAll('.user-row'));
        const emptySearchRow = userTableBody.querySelector('.user-search-empty-row');

        const syncSearchResults = function () {
            const query = userSearchInput.value.trim().toLowerCase();
            let visibleCount = 0;

            rows.forEach(function (row) {
                const haystack = row.getAttribute('data-user-search') || '';
                const matches = query === '' || haystack.includes(query);
                row.hidden = !matches;
                if (matches) {
                    visibleCount += 1;
                }
            });

            if (emptySearchRow) {
                emptySearchRow.hidden = visibleCount !== 0;
            }
        };

        userSearchInput.addEventListener('input', syncSearchResults);
        syncSearchResults();
    }
});
