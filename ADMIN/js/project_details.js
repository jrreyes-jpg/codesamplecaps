document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-confirm-action]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const message = form.getAttribute('data-confirm-action') || 'Are you sure?';

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    const tabs = Array.from(document.querySelectorAll('[data-project-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-project-panel]'));

    function setActiveProjectTab(tabName) {
        tabs.forEach(function (tab) {
            const isActive = tab.getAttribute('data-project-tab') === tabName;
            tab.classList.toggle('is-active', isActive);
        });

        panels.forEach(function (panel) {
            const isActive = panel.getAttribute('data-project-panel') === tabName;
            panel.classList.toggle('is-active', isActive);
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const tabName = tab.getAttribute('data-project-tab') || 'details';
            setActiveProjectTab(tabName);
        });
    });

    const editForm = document.querySelector('[data-project-edit-form]');
    const editToggle = document.querySelector('[data-project-edit-toggle]');
    const updateButton = document.querySelector('[data-project-update-button]');
    const cancelButton = document.querySelector('[data-project-cancel-button]');
    const editableFields = Array.from(document.querySelectorAll('[data-project-editable]'));
    const editableControls = Array.from(document.querySelectorAll('[data-project-editable-control]'));
    const overviewPanel = document.querySelector('[data-project-panel="details"]');

    if (editForm && editToggle && updateButton && cancelButton && editableFields.length > 0) {
        const fieldSnapshots = editableFields.map(function (field) {
            const snapshot = {
                field: field,
                value: field.value,
            };

            if (field.tagName === 'SELECT' && field.multiple) {
                snapshot.values = Array.from(field.selectedOptions).map(function (option) {
                    return option.value;
                });
            }

            return {
                field: snapshot.field,
                value: snapshot.value,
                values: snapshot.values || null,
            };
        });

        const setEditMode = function (isEditing) {
            editableFields.forEach(function (field) {
                if (field.tagName === 'SELECT') {
                    field.disabled = !isEditing;
                } else {
                    if (isEditing) {
                        field.removeAttribute('readonly');
                    } else {
                        field.setAttribute('readonly', 'readonly');
                    }
                }
            });

            editableControls.forEach(function (control) {
                control.disabled = !isEditing;
            });

            editToggle.classList.toggle('hidden', isEditing);
            updateButton.classList.toggle('hidden', !isEditing);
            cancelButton.classList.toggle('hidden', !isEditing);

            if (overviewPanel) {
                overviewPanel.classList.toggle('is-editing', isEditing);
            }
        };

        setEditMode(false);

        editToggle.addEventListener('click', function () {
            setEditMode(true);
            const firstField = editableFields.find(function (field) {
                return !field.disabled;
            });

            if (firstField && typeof firstField.focus === 'function') {
                firstField.focus();
            }
        });

        cancelButton.addEventListener('click', function () {
            fieldSnapshots.forEach(function (snapshot) {
                if (snapshot.field.tagName === 'SELECT' && snapshot.field.multiple && Array.isArray(snapshot.values)) {
                    Array.from(snapshot.field.options).forEach(function (option) {
                        option.selected = snapshot.values.indexOf(option.value) !== -1;
                    });
                    return;
                }

                snapshot.field.value = snapshot.value;
            });
            setEditMode(false);
        });
    }

    document.querySelectorAll('[data-engineer-picker]').forEach(function (picker) {
        const engineerSelect = picker.querySelector('[data-engineer-select]');
        const toggleButton = picker.querySelector('[data-engineer-toggle]');
        const toggleButtonIcon = picker.querySelector('.engineer-picker__toggle-icon');
        const toggleButtonText = picker.querySelector('.engineer-picker__toggle-text');
        const selectedContainer = picker.querySelector('[data-engineer-selected]');
        const inputsContainer = picker.querySelector('[data-engineer-inputs]');

        if (!engineerSelect || !toggleButton || !selectedContainer || !inputsContainer) {
            return;
        }

        function getSelectedEngineerIds() {
            return Array.from(inputsContainer.querySelectorAll('[data-engineer-input]')).map(function (input) {
                return String(input.value);
            });
        }

        function syncValidation() {
            if (getSelectedEngineerIds().length > 0) {
                engineerSelect.setCustomValidity('');
            } else {
                engineerSelect.setCustomValidity('Assigned engineer is required.');
            }
        }

        function syncToggleButton() {
            const selectedValue = engineerSelect.value;
            const hasSelectedValue = selectedValue !== '';
            const isAlreadyAdded = hasSelectedValue && getSelectedEngineerIds().includes(selectedValue);

            toggleButton.disabled = toggleButton.hasAttribute('data-project-editable-control') && toggleButton.closest('[data-project-panel="overview"]')?.classList.contains('is-editing') === false
                ? true
                : !hasSelectedValue;
            toggleButton.classList.toggle('is-remove', Boolean(isAlreadyAdded));
            toggleButton.setAttribute('aria-label', isAlreadyAdded ? 'Remove selected engineer' : 'Add selected engineer');
            toggleButtonIcon.textContent = isAlreadyAdded ? '\u2212' : '+';
            toggleButtonText.textContent = isAlreadyAdded ? 'Remove' : 'Add';
        }

        function renderEmptyState() {
            const hasChip = selectedContainer.querySelector('[data-engineer-chip]');
            selectedContainer.classList.toggle('is-empty', !hasChip);

            if (!hasChip) {
                selectedContainer.innerHTML = '<span class="engineer-picker__empty">No engineers added yet.</span>';
            }
        }

        function addEngineer(engineerId, engineerName) {
            const existingInput = inputsContainer.querySelector('[data-engineer-input][value="' + CSS.escape(engineerId) + '"]');

            if (existingInput) {
                return;
            }

            const emptyState = selectedContainer.querySelector('.engineer-picker__empty');
            if (emptyState) {
                emptyState.remove();
            }

            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'engineer-chip';
            chip.setAttribute('data-engineer-chip', '');
            chip.setAttribute('data-engineer-id', engineerId);
            chip.setAttribute('data-engineer-name', engineerName);
            chip.setAttribute('aria-pressed', 'true');
            chip.innerHTML = '<span></span><span class="engineer-chip__remove" aria-hidden="true">&times;</span>';
            chip.querySelector('span').textContent = engineerName;
            selectedContainer.appendChild(chip);

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'engineer_ids[]';
            hiddenInput.value = engineerId;
            hiddenInput.setAttribute('data-engineer-input', '');
            inputsContainer.appendChild(hiddenInput);

            syncValidation();
            syncToggleButton();
        }

        function removeEngineer(engineerId) {
            const hiddenInput = inputsContainer.querySelector('[data-engineer-input][value="' + CSS.escape(engineerId) + '"]');
            const chip = selectedContainer.querySelector('[data-engineer-chip][data-engineer-id="' + CSS.escape(engineerId) + '"]');

            if (hiddenInput) {
                hiddenInput.remove();
            }

            if (chip) {
                chip.remove();
            }

            renderEmptyState();
            syncValidation();
            syncToggleButton();
        }

        engineerSelect.addEventListener('change', function () {
            engineerSelect.setCustomValidity('');
            syncToggleButton();
        });

        toggleButton.addEventListener('click', function () {
            const engineerId = engineerSelect.value;

            if (engineerId === '') {
                engineerSelect.setCustomValidity('Select an engineer first.');
                engineerSelect.reportValidity();
                return;
            }

            const selectedOption = engineerSelect.options[engineerSelect.selectedIndex];
            if (!selectedOption) {
                return;
            }

            if (getSelectedEngineerIds().includes(engineerId)) {
                removeEngineer(engineerId);
            } else {
                addEngineer(engineerId, selectedOption.text);
            }
        });

        selectedContainer.addEventListener('click', function (event) {
            const chip = event.target.closest('[data-engineer-chip]');

            if (!chip) {
                return;
            }

            const engineerId = chip.getAttribute('data-engineer-id') || '';
            if (engineerId === '') {
                return;
            }

            engineerSelect.value = engineerId;
            removeEngineer(engineerId);
        });

        renderEmptyState();
        syncValidation();
        syncToggleButton();
    });

    document.querySelectorAll('[data-inline-edit-form]').forEach(function (form) {
        const editButton = form.querySelector('[data-inline-edit]');
        const updateInlineButton = form.querySelector('[data-inline-update]');
        const cancelInlineButton = form.querySelector('[data-inline-cancel]');
        const fields = Array.from(form.querySelectorAll('[data-inline-editable]'));

        if (!editButton || !updateInlineButton || !cancelInlineButton || fields.length === 0) {
            return;
        }

        const fieldSnapshots = fields.map(function (field) {
            return {
                field: field,
                value: field.value,
            };
        });

        const setInlineEditMode = function (isEditing) {
            fields.forEach(function (field) {
                field.disabled = !isEditing;
            });

            form.classList.toggle('is-editing', isEditing);
            editButton.classList.toggle('hidden', isEditing);
            updateInlineButton.classList.toggle('hidden', !isEditing);
            cancelInlineButton.classList.toggle('hidden', !isEditing);
        };

        setInlineEditMode(false);

        editButton.addEventListener('click', function () {
            setInlineEditMode(true);
            const firstField = fields.find(function (field) {
                return !field.disabled;
            });

            if (firstField && typeof firstField.focus === 'function') {
                firstField.focus();
            }
        });

        cancelInlineButton.addEventListener('click', function () {
            fieldSnapshots.forEach(function (snapshot) {
                snapshot.field.value = snapshot.value;
            });
            setInlineEditMode(false);
        });
    });
});
