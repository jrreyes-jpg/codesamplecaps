// JS ng User Management page. Ito ang para sa search, edit/save, at create user modal.
document.addEventListener('DOMContentLoaded', function () {
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

    document.querySelectorAll('[data-ph-phone-lock-prefix]').forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/[^0-9]/g, '');
            if (!input.value.startsWith('09')) {
                input.value = '09';
            }
        });
    });

    document.querySelectorAll('.user-row').forEach(function (row) {
        const editBtn = row.querySelector('[data-edit-btn]');
        const saveBtn = row.querySelector('[data-save-btn]');
        const cancelBtn = row.querySelector('[data-cancel-btn]');
        const inputs = row.querySelectorAll('.table-input');
        const rowId = row.getAttribute('data-row-id');
        const saveForm = document.getElementById('save-form-' + rowId);
        const originals = Array.from(inputs).map((input) => input.value);

        if (!editBtn || !saveBtn || !cancelBtn || !saveForm) {
            return;
        }

        editBtn.addEventListener('click', function () {
            inputs.forEach((input) => input.removeAttribute('readonly'));
            editBtn.hidden = true;
            saveBtn.hidden = false;
            cancelBtn.hidden = false;
        });

        cancelBtn.addEventListener('click', function () {
            inputs.forEach((input, index) => {
                input.value = originals[index];
                input.setAttribute('readonly', 'readonly');
            });
            editBtn.hidden = false;
            saveBtn.hidden = true;
            cancelBtn.hidden = true;
        });

        saveBtn.addEventListener('click', function () {
            const byField = {};
            inputs.forEach((input) => {
                byField[input.getAttribute('data-field')] = input.value;
            });
            saveForm.querySelector('[data-save-field="full_name"]').value = byField.full_name || '';
            saveForm.querySelector('[data-save-field="email"]').value = byField.email || '';
            const phoneField = saveForm.querySelector('[data-save-field="phone"]');
            if (phoneField) {
                phoneField.value = byField.phone || phoneField.value || '';
            }
            saveForm.submit();
        });
    });

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
