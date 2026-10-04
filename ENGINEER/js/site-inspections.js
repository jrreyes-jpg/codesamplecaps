// Costing rows para mabilis magdagdag ng materials, labor, at notes.
document.addEventListener('DOMContentLoaded', function () {
    const activeModalKey = 'engineer.siteInspection.activeModal';
    const modalScrollKey = 'engineer.siteInspection.modalScroll';

    const money = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    });

    const syncTotal = function (form) {
        let total = 0;
        form.querySelectorAll('.costing-row').forEach(function (row) {
            const quantityField = row.querySelector('input[name="quantity[]"]');
            const unitField = row.querySelector('select[name="unit[]"]');
            const costField = row.querySelector('input[name="unit_cost[]"]');
            if (isValidQuantityText(quantityField?.value.trim() || '', unitField?.value || '')
                && isValidCostText(costField?.value.trim() || '')) {
                total += Number(quantityField.value) * Number(costField.value);
            }
        });

        const totalBox = form.querySelector('[data-costing-total]');
        if (totalBox) {
            totalBox.textContent = money.format(total);
        }
    };

    const storageKeyForForm = function (form) {
        const inspectionId = form.querySelector('input[name="inspection_id"]')?.value || 'unknown';
        return `engineer.siteInspection.costing.${inspectionId}`;
    };

    const saveFormDraft = function (form) {
        const rows = Array.from(form.querySelectorAll('.costing-row')).map(function (row) {
            return {
                item_type: row.querySelector('select[name="item_type[]"]')?.value || 'material',
                inventory_id: row.querySelector('input[name="inventory_id[]"]')?.value || '',
                material_id: row.querySelector('select[name="material_id[]"]')?.value || '',
                item_name: row.querySelector('input[name="item_name[]"]')?.value || '',
                quantity: row.querySelector('input[name="quantity[]"]')?.value || '',
                unit: row.querySelector('select[name="unit[]"]')?.value || 'unit',
                unit_cost: row.querySelector('input[name="unit_cost[]"]')?.value || '',
                notes: row.querySelector('input[name="notes[]"]')?.value || '',
            };
        });
        const assetRequirements = Array.from(form.querySelectorAll('[data-asset-requirement-row]')).map(function (row) {
            return {
                asset_id: row.querySelector('select[name="asset_requirement_asset_id[]"]')?.value || '',
                quantity: row.querySelector('input[name="asset_requirement_quantity[]"]')?.value || '',
                notes: row.querySelector('input[name="asset_requirement_notes[]"]')?.value || '',
            };
        });

        const payload = {
            engineer_findings: form.querySelector('textarea[name="engineer_findings"]')?.value || '',
            risk_notes: form.querySelector('textarea[name="risk_notes"]')?.value || '',
            client_requests: form.querySelector('textarea[name="client_requests"]')?.value || '',
            rows,
            asset_requirements: assetRequirements,
        };

        window.localStorage.setItem(storageKeyForForm(form), JSON.stringify(payload));
    };

    const normalizeText = function (value) {
        return String(value ?? '').trim();
    };

    const normalizeNumber = function (value) {
        const text = normalizeText(value);
        if (text === '') {
            return '';
        }

        const normalizedText = text.startsWith('.') ? `0${text}` : text;
        const number = Number(normalizedText);
        return Number.isFinite(number) ? String(number) : normalizedText;
    };

    const formSnapshot = function (form) {
        return JSON.stringify({
            findings: normalizeText(form.querySelector('textarea[name="engineer_findings"]')?.value),
            riskNotes: normalizeText(form.querySelector('textarea[name="risk_notes"]')?.value),
            clientRequests: normalizeText(form.querySelector('textarea[name="client_requests"]')?.value),
            rows: Array.from(form.querySelectorAll('.costing-row')).map(function (row) {
                return {
                    type: normalizeText(row.querySelector('select[name="item_type[]"]')?.value).toLowerCase(),
                    materialId: normalizeText(row.querySelector('select[name="material_id[]"]')?.value) || '',
                    itemName: normalizeText(row.querySelector('input[name="item_name[]"]')?.value),
                    quantity: normalizeNumber(row.querySelector('input[name="quantity[]"]')?.value),
                    unit: normalizeText(row.querySelector('select[name="unit[]"]')?.value).toLowerCase(),
                    unitCost: normalizeNumber(row.querySelector('input[name="unit_cost[]"]')?.value),
                    notes: normalizeText(row.querySelector('input[name="notes[]"]')?.value),
                };
            }),
            assetRequirements: Array.from(form.querySelectorAll('[data-asset-requirement-row]')).map(function (row) {
                return {
                    assetId: normalizeText(row.querySelector('select[name="asset_requirement_asset_id[]"]')?.value),
                    quantity: normalizeNumber(row.querySelector('input[name="asset_requirement_quantity[]"]')?.value),
                    notes: normalizeText(row.querySelector('input[name="asset_requirement_notes[]"]')?.value),
                };
            }),
        });
    };

    const updateSaveDraftState = function (form) {
        const saveButton = form.querySelector('[data-save-draft]');
        if (!saveButton || form.dataset.isSaving === 'true') {
            return;
        }

        const isDirty = isFormDirty(form);
        saveButton.disabled = !isDirty;
        saveButton.classList.toggle('is-disabled', !isDirty);
        saveButton.setAttribute('aria-disabled', isDirty ? 'false' : 'true');

        if (new URLSearchParams(window.location.search).has('debug_inspection_dirty')) {
            console.debug('Inspection draft snapshots', {
                baseline: form.dataset.savedSnapshot || '',
                current: formSnapshot(form),
            });
        }
    };

    const isFormDirty = function (form) {
        return formSnapshot(form) !== (form.dataset.savedSnapshot || '');
    };

    const fillRow = function (row, data) {
        row.querySelector('select[name="item_type[]"]').value = data.item_type || 'material';
        const materialPicker = row.querySelector('select[name="material_id[]"]');
        if (materialPicker) {
            materialPicker.value = data.material_id || '';
        }
        row.querySelector('input[name="item_name[]"]').value = data.item_name || '';
        row.querySelector('input[name="quantity[]"]').value = data.quantity || '1';
        row.querySelector('select[name="unit[]"]').value = data.unit || 'unit';
        row.querySelector('input[name="unit_cost[]"]').value = data.unit_cost || '';
        row.querySelector('input[name="notes[]"]').value = data.notes || '';
    };

    const updateAssetRequirementAvailability = function (row) {
        const picker = row.querySelector('[data-asset-requirement-picker]');
        const output = row.querySelector('[data-asset-requirement-available]');
        const shortage = row.querySelector('[data-asset-requirement-shortage]');
        const quantity = row.querySelector('input[name="asset_requirement_quantity[]"]');
        const selected = picker?.selectedOptions[0];
        const available = selected?.getAttribute('data-available');
        if (output) {
            output.textContent = available === null || available === undefined || available === ''
                ? 'Select an asset'
                : `${available} available`;
        }

        const quantityNeeded = Number(quantity?.value.trim() || 0);
        const availableCount = Number(available || 0);
        const hasShortage = picker?.value !== ''
            && /^[1-9]\d*$/.test(quantity?.value.trim() || '')
            && quantityNeeded > availableCount;
        if (shortage) {
            shortage.hidden = !hasShortage;
            shortage.textContent = hasShortage ? `Short by ${quantityNeeded - availableCount}` : '';
        }
    };

    const formatMaterialQuantity = function (value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return '0';

        return new Intl.NumberFormat('en-PH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        }).format(number);
    };

    const updateMaterialAvailability = function (row) {
        const type = row.querySelector('select[name="item_type[]"]')?.value || '';
        const picker = row.querySelector('[data-material-picker]');
        const quantityField = row.querySelector('input[name="quantity[]"]');
        const feedback = row.querySelector('[data-material-stock-feedback]');
        const selected = picker?.selectedOptions[0];

        if (!feedback) return;

        const availableText = selected?.getAttribute('data-available');
        const unit = selected?.getAttribute('data-unit') || '';
        const required = Number(quantityField?.value.trim() || '');
        const available = Number(availableText);
        const hasLinkedMaterial = type === 'material'
            && picker?.value !== ''
            && availableText !== null
            && availableText !== undefined
            && unit !== '';
        const hasValidRequiredQuantity = Number.isFinite(required) && required > 0;

        feedback.hidden = !hasLinkedMaterial || !hasValidRequiredQuantity;
        feedback.classList.remove('is-available', 'is-shortage');
        feedback.textContent = '';

        if (feedback.hidden) return;

        const availableLabel = `${formatMaterialQuantity(available)} ${unit}`;
        const requiredLabel = `${formatMaterialQuantity(required)} ${unit}`;
        if (required <= available) {
            feedback.classList.add('is-available');
            feedback.textContent = `Available: ${availableLabel}`;
            return;
        }

        const shortage = required - available;
        feedback.classList.add('is-shortage');
        feedback.textContent = `Insufficient stock — Available: ${availableLabel} • Required: ${requiredLabel} • Short by: ${formatMaterialQuantity(shortage)} ${unit}`;
    };

    const assetRequirementHasMeaningfulData = function (row) {
        const assetId = row.querySelector('select[name="asset_requirement_asset_id[]"]')?.value || '';
        const quantity = row.querySelector('input[name="asset_requirement_quantity[]"]')?.value.trim() || '';
        const notes = row.querySelector('input[name="asset_requirement_notes[]"]')?.value.trim() || '';
        return assetId !== '' || !['', '1'].includes(quantity) || notes !== '';
    };

    const bindAssetRequirementRow = function (form, row) {
        const picker = row.querySelector('[data-asset-requirement-picker]');
        const quantity = row.querySelector('input[name="asset_requirement_quantity[]"]');
        const notes = row.querySelector('input[name="asset_requirement_notes[]"]');

        updateAssetRequirementAvailability(row);

        picker?.addEventListener('change', function () {
            const selectedId = picker.value;
            const duplicate = selectedId !== '' && Array.from(form.querySelectorAll('[data-asset-requirement-picker]'))
                .some((otherPicker) => otherPicker !== picker && otherPicker.value === selectedId);
            if (duplicate) {
                picker.value = '';
                setFieldError(picker, 'This asset is already added.');
            } else {
                clearFieldError(picker);
            }
            updateAssetRequirementAvailability(row);
            saveFormDraft(form);
            updateSaveDraftState(form);
        });

        [quantity, notes].forEach(function (field) {
            field?.addEventListener('input', function () {
                clearFieldError(field);
                clearCostingErrorWhenResolved(form);
                updateAssetRequirementAvailability(row);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });
        });

        quantity?.addEventListener('blur', function () {
            const value = quantity.value.trim();
            if (!/^[1-9]\d*$/.test(value)) {
                setFieldError(quantity, 'Enter 1 or more.');
            } else {
                clearFieldError(quantity);
                clearCostingErrorWhenResolved(form);
            }
            updateAssetRequirementAvailability(row);
        });

        row.querySelector('[data-remove-asset-requirement]')?.addEventListener('click', function () {
            if (assetRequirementHasMeaningfulData(row)
                && !window.confirm('Remove asset requirement?\n\nThis will remove the selected asset requirement.')) {
                return;
            }
            row.remove();
            saveFormDraft(form);
            updateSaveDraftState(form);
        });
    };

    const addAssetRequirementRow = function (form, data = null) {
        const rowsBox = form.querySelector('[data-asset-requirement-rows]');
        const template = form.querySelector('[data-asset-requirement-template]');
        if (!rowsBox || !template) {
            return null;
        }

        const row = template.content.firstElementChild.cloneNode(true);
        if (data) {
            row.querySelector('select[name="asset_requirement_asset_id[]"]').value = data.asset_id || '';
            row.querySelector('input[name="asset_requirement_quantity[]"]').value = data.quantity || '1';
            row.querySelector('input[name="asset_requirement_notes[]"]').value = data.notes || '';
        }
        rowsBox.appendChild(row);
        bindAssetRequirementRow(form, row);
        return row;
    };

    const setLinkedMaterialState = function (row) {
        const type = row.querySelector('select[name="item_type[]"]')?.value || '';
        const materialField = row.querySelector('[data-material-field]');
        const picker = row.querySelector('[data-material-picker]');
        const itemName = row.querySelector('input[name="item_name[]"]');
        const unit = row.querySelector('select[name="unit[]"]');
        const inventoryId = row.querySelector('input[name="inventory_id[]"]');
        const isMaterial = type === 'material';

        materialField?.toggleAttribute('hidden', !isMaterial);
        if (!isMaterial) {
            if (picker) picker.value = '';
            if (inventoryId) inventoryId.value = '';
            itemName?.removeAttribute('readonly');
            unit?.removeAttribute('data-locked');
            unit?.removeAttribute('aria-disabled');
            updateMaterialAvailability(row);
            return;
        }

        const option = picker?.selectedOptions[0];
        const linkedName = option?.getAttribute('data-name') || '';
        const linkedUnit = option?.getAttribute('data-unit') || '';
        if (linkedName && linkedUnit) {
            itemName.value = linkedName;
            itemName.setAttribute('readonly', 'readonly');
            unit.value = linkedUnit;
            unit.setAttribute('data-locked', 'true');
            unit.setAttribute('aria-disabled', 'true');
        } else {
            itemName?.removeAttribute('readonly');
            unit?.removeAttribute('data-locked');
            unit?.removeAttribute('aria-disabled');
        }

        updateMaterialAvailability(row);
    };

    const rowHasMeaningfulData = function (row) {
        return (row.querySelector('select[name="material_id[]"]')?.value || '') !== ''
            || (row.querySelector('input[name="item_name[]"]')?.value.trim() || '') !== ''
            || !['', '0', '0.00'].includes(row.querySelector('input[name="unit_cost[]"]')?.value.trim() || '')
            || (row.querySelector('input[name="notes[]"]')?.value.trim() || '') !== ''
            || (row.querySelector('input[name="quantity[]"]')?.value || '') !== '1';
    };

    const resetRowForType = function (row) {
        row.querySelector('input[name="inventory_id[]"]').value = '';
        row.querySelector('select[name="material_id[]"]').value = '';
        row.querySelector('input[name="item_name[]"]').value = '';
        row.querySelector('input[name="quantity[]"]').value = '';
        row.querySelector('select[name="unit[]"]').selectedIndex = 0;
        row.querySelector('input[name="unit_cost[]"]').value = '';
        row.querySelector('input[name="notes[]"]').value = '';
        row.querySelectorAll('.costing-field-error').forEach((error) => error.remove());
        row.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
        setLinkedMaterialState(row);
        updateMaterialAvailability(row);
    };

    const restoreFormDraft = function (form) {
        const saved = window.localStorage.getItem(storageKeyForForm(form));
        if (!saved) {
            return;
        }

        let payload = null;
        try {
            payload = JSON.parse(saved);
        } catch (error) {
            window.localStorage.removeItem(storageKeyForForm(form));
            return;
        }
        form.querySelector('textarea[name="engineer_findings"]').value = payload.engineer_findings || '';
        form.querySelector('textarea[name="risk_notes"]').value = payload.risk_notes || '';
        form.querySelector('textarea[name="client_requests"]').value = payload.client_requests || '';

        const rowsBox = form.querySelector('[data-costing-rows]');
        const firstRow = rowsBox?.querySelector('.costing-row');
        if (!rowsBox || !firstRow || !Array.isArray(payload.rows)) {
            return;
        }

        rowsBox.innerHTML = '';
        payload.rows.forEach(function (rowData) {
            const row = firstRow.cloneNode(true);
            fillRow(row, rowData);
            rowsBox.appendChild(row);
            bindRow(form, row);
        });

        const assetRowsBox = form.querySelector('[data-asset-requirement-rows]');
        if (assetRowsBox && Array.isArray(payload.asset_requirements)) {
            assetRowsBox.innerHTML = '';
            payload.asset_requirements.forEach(function (assetRequirement) {
                addAssetRequirementRow(form, assetRequirement);
            });
        }
    };

    const setFieldState = function (field, isInvalid) {
        if (!field) {
            return;
        }

        field.classList.toggle('is-invalid', isInvalid);
    };

    const setFieldError = function (field, message) {
        if (!field) {
            return;
        }

        setFieldState(field, true);

        const holder = field.closest('label') || field.parentElement;
        if (!holder || holder.querySelector('.costing-field-error')) {
            return;
        }

        const error = document.createElement('small');
        error.className = 'costing-field-error';
        error.textContent = message;
        holder.appendChild(error);
    };

    const clearFieldError = function (field) {
        if (!field) {
            return;
        }

        field.classList.remove('is-invalid');
        const holder = field.closest('label') || field.parentElement;
        holder?.querySelector('.costing-field-error')?.remove();
    };

    const showCostingError = function (form, message) {
        const errorBox = form.querySelector('[data-costing-error]');
        if (!errorBox) {
            return;
        }

        errorBox.textContent = message;
        errorBox.hidden = false;
    };

    const clearCostingError = function (form) {
        const errorBox = form.querySelector('[data-costing-error]');
        form.querySelectorAll('.is-invalid-total').forEach(function (field) {
            field.classList.remove('is-invalid-total');
        });

        if (errorBox) {
            errorBox.textContent = '';
            errorBox.hidden = true;
        }
    };

    const clearCostingErrorWhenResolved = function (form) {
        if (!form.querySelector('.is-invalid')) {
            clearCostingError(form);
        }
    };

    const normalizeDecimalField = function (field) {
        if (!field) {
            return;
        }

        const value = field.value.trim();
        if (/^\.\d+$/.test(value)) {
            field.value = `0${value}`;
        }
    };

    const isValidCostText = function (value) {
        return /^(?:0|[1-9]\d*)(\.\d{1,2})?$/.test(value) && Number(value) > 0;
    };

    const costErrorMessage = function (value) {
        const number = Number(value);
        if (/^(?:0|[1-9]\d*)\.\d{3,}$/.test(value)) {
            return 'Maximum 2 decimal places.';
        }
        if (value !== '' && Number.isFinite(number) && number <= 0) {
            return 'Enter a cost greater than 0.';
        }
        return 'Enter a valid amount.';
    };

    const isWholeCountUnit = function (unit) {
        return ['unit', 'pc', 'pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person', 'bundle', 'sheet', 'pair', 'tube', 'trip'].includes(unit);
    };

    const isValidQuantityText = function (value, unit) {
        if (isWholeCountUnit(unit)) {
            return /^[1-9]\d*$/.test(value);
        }

        return /^(?:0|[1-9]\d*)(\.\d+)?$/.test(value) && Number(value) > 0;
    };

    const addCostingRow = function (form, type = 'material') {
        const rowsBox = form.querySelector('[data-costing-rows]');
        const firstRow = rowsBox?.querySelector('.costing-row');
        if (!rowsBox || !firstRow) {
            return null;
        }

        const row = firstRow.cloneNode(true);
        row.querySelectorAll('input').forEach(function (field) {
            field.value = field.name === 'quantity[]' ? '1' : '';
            clearFieldError(field);
        });
        row.querySelectorAll('select').forEach(function (field) {
            field.selectedIndex = 0;
            clearFieldError(field);
        });

        const typeField = row.querySelector('select[name="item_type[]"]');
        if (typeField) {
            typeField.value = type;
        }

        rowsBox.appendChild(row);
        bindRow(form, row);
        syncTotal(form);
        saveFormDraft(form);
        updateSaveDraftState(form);

        return row;
    };

    const validateCosting = function (form, requireFinal) {
        let hasMaterial = false;
        let hasLabor = false;
        let hasAnyRow = false;
        let total = 0;
        let firstInvalid = null;
        let errorMessage = '';
        const findings = form.querySelector('textarea[name="engineer_findings"]');

        clearCostingError(form);
        form.querySelectorAll('.is-invalid').forEach(function (field) {
            field.classList.remove('is-invalid');
        });
        form.querySelectorAll('.costing-field-error').forEach(function (field) {
            field.remove();
        });
        form.querySelectorAll('.is-invalid-total').forEach(function (field) {
            field.classList.remove('is-invalid-total');
        });

        form.querySelectorAll('.costing-row').forEach(function (row) {
            const type = row.querySelector('select[name="item_type[]"]');
            const name = row.querySelector('input[name="item_name[]"]');
            const quantity = row.querySelector('input[name="quantity[]"]');
            const unit = row.querySelector('select[name="unit[]"]');
            const unitCost = row.querySelector('input[name="unit_cost[]"]');
            const nameValue = name?.value.trim() || '';
            const quantityText = quantity?.value.trim() || '';
            const quantityValue = Number(quantityText || 0);
            const unitValue = unit?.value.trim() || '';
            const unitCostText = unitCost?.value.trim() || '';
            const rowHasValue = nameValue !== '' || quantityText !== '' || unitCostText !== '';

            if (!rowHasValue) {
                return;
            }

            hasAnyRow = true;
            hasMaterial = hasMaterial || type?.value === 'material';
            hasLabor = hasLabor || type?.value === 'labor';
            if (isValidQuantityText(quantityText, unitValue) && isValidCostText(unitCostText)) {
                total += quantityValue * Number(unitCostText);
            }

            if (nameValue === '') {
                setFieldError(name, 'Item / Labor name is required.');
                firstInvalid = firstInvalid || name;
            }

            if (!isValidQuantityText(quantityText, unitValue)) {
                setFieldError(quantity, isWholeCountUnit(unitValue)
                    ? `Enter a whole number for ${unitValue || 'this unit'}.`
                    : 'Qty must be greater than 0.');
                firstInvalid = firstInvalid || quantity;
            }

            if (!isValidCostText(unitCostText)) {
                setFieldError(unitCost, costErrorMessage(unitCostText));
                firstInvalid = firstInvalid || unitCost;
            }

            if (unitValue === '') {
                setFieldError(unit, 'Unit is required.');
                firstInvalid = firstInvalid || unit;
            }

        });

        const seenAssets = new Set();
        form.querySelectorAll('[data-asset-requirement-row]').forEach(function (row) {
            const asset = row.querySelector('select[name="asset_requirement_asset_id[]"]');
            const quantity = row.querySelector('input[name="asset_requirement_quantity[]"]');
            const assetId = asset?.value || '';
            const quantityText = quantity?.value.trim() || '';

            if (!assetRequirementHasMeaningfulData(row)) {
                return;
            }

            if (assetId === '') {
                setFieldError(asset, 'Select an asset.');
                firstInvalid = firstInvalid || asset;
            } else if (seenAssets.has(assetId)) {
                setFieldError(asset, 'This asset is already added.');
                firstInvalid = firstInvalid || asset;
            } else {
                seenAssets.add(assetId);
            }

            if (!/^[1-9]\d*$/.test(quantityText)) {
                setFieldError(quantity, 'Enter 1 or more.');
                firstInvalid = firstInvalid || quantity;
            }
        });

        if (!hasAnyRow) {
            firstInvalid = firstInvalid || form.querySelector('input[name="item_name[]"]');
            setFieldError(firstInvalid, 'Add at least one material or labor row.');
        }

        if (requireFinal && findings && findings.value.trim().length < 10) {
            setFieldError(findings, 'Engineer Findings is required before submitting.');
            firstInvalid = firstInvalid || findings;
        }

        if (requireFinal && (!hasMaterial || !hasLabor || total <= 0)) {
            const totalBox = form.querySelector('[data-costing-total]');
            totalBox?.classList.add('is-invalid-total');
            if (!hasMaterial) {
                const newRow = addCostingRow(form, 'material');
                const nameField = newRow?.querySelector('input[name="item_name[]"]');
                setFieldError(nameField, 'Fill this Material item.');
                firstInvalid = firstInvalid || nameField;
                errorMessage = errorMessage || 'Missing Material row. I added one below.';
            } else if (!hasLabor) {
                const newRow = addCostingRow(form, 'labor');
                const nameField = newRow?.querySelector('input[name="item_name[]"]');
                setFieldError(nameField, 'Fill this Labor item.');
                firstInvalid = firstInvalid || nameField;
                errorMessage = errorMessage || 'Missing Labor row. I added one below.';
            } else {
                firstInvalid = firstInvalid || totalBox;
                errorMessage = errorMessage || 'Total cost must be greater than 0.';
            }
        }

        if (firstInvalid) {
            showCostingError(form, 'Please fix the highlighted fields below.');
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (typeof firstInvalid.focus === 'function') {
                firstInvalid.focus();
            }
            return false;
        }

        return true;
    };

    const bindRow = function (form, row) {
        const typeField = row.querySelector('select[name="item_type[]"]');
        let previousType = typeField?.value || 'material';
        setLinkedMaterialState(row);
        updateMaterialAvailability(row);

        typeField?.addEventListener('change', function () {
            const nextType = typeField.value;
            if (rowHasMeaningfulData(row)
                && !window.confirm('Change cost type?\n\nChanging the type will clear the current row details. Continue?')) {
                typeField.value = previousType;
                return;
            }

            previousType = nextType;
            resetRowForType(row);
            syncTotal(form);
            saveFormDraft(form);
            updateSaveDraftState(form);
        });

        row.querySelectorAll('[data-costing-decimal]').forEach(function (field) {
            field.addEventListener('input', function () {
                clearFieldError(field);
                clearCostingErrorWhenResolved(form);
                syncTotal(form);
                updateMaterialAvailability(row);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });

            field.addEventListener('blur', function () {
                normalizeDecimalField(field);
                const value = field.value.trim();
                const unit = row.querySelector('select[name="unit[]"]')?.value || '';
                const valid = field.name === 'unit_cost[]'
                    ? isValidCostText(value)
                    : isValidQuantityText(value, unit);
                if (!valid) {
                    setFieldError(field, field.name === 'unit_cost[]'
                        ? costErrorMessage(value)
                        : (isWholeCountUnit(unit) ? `Enter a whole number for ${unit}.` : 'Qty must be greater than 0.'));
                }
                syncTotal(form);
                updateMaterialAvailability(row);
            });
        });

        row.querySelector('[data-material-picker]')?.addEventListener('change', function (event) {
            const option = event.target.selectedOptions[0];
            const name = option?.getAttribute('data-name') || '';
            const unit = option?.getAttribute('data-unit') || '';
            const nameField = row.querySelector('input[name="item_name[]"]');
            const unitField = row.querySelector('select[name="unit[]"]');
            if (name && nameField) {
                nameField.value = name;
            }
            if (unit && unitField && Array.from(unitField.options).some((optionItem) => optionItem.value === unit)) {
                unitField.value = unit;
            }
            if (!name) {
                if (nameField) nameField.value = '';
                if (unitField) unitField.selectedIndex = 0;
            }
            setLinkedMaterialState(row);
            updateMaterialAvailability(row);
            saveFormDraft(form);
            updateSaveDraftState(form);
        });

        row.querySelector('select[name="unit[]"]')?.addEventListener('change', function (event) {
            const quantity = row.querySelector('input[name="quantity[]"]');
            const value = quantity?.value.trim() || '';
            if (value !== '' && !isValidQuantityText(value, event.target.value)) {
                setFieldError(quantity, isWholeCountUnit(event.target.value)
                    ? `Enter a whole number for ${event.target.value}.`
                    : 'Qty must be greater than 0.');
            }
            syncTotal(form);
            updateMaterialAvailability(row);
        });

        row.querySelectorAll('input, select').forEach(function (field) {
            field.addEventListener('input', function () {
                clearFieldError(field);
                clearCostingErrorWhenResolved(form);
                updateMaterialAvailability(row);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });

            field.addEventListener('change', function () {
                if (field.matches('select[name="unit[]"][data-locked]')) {
                    const option = row.querySelector('[data-material-picker]')?.selectedOptions[0];
                    field.value = option?.getAttribute('data-unit') || field.value;
                }
                clearFieldError(field);
                clearCostingErrorWhenResolved(form);
                updateMaterialAvailability(row);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });
        });

        row.querySelector('[data-remove-costing-row]')?.addEventListener('click', function () {
            if (rowHasMeaningfulData(row)
                && !window.confirm('Remove costing item?\n\nThis will remove the current costing row and its entered details.')) {
                return;
            }

            const rows = form.querySelectorAll('.costing-row');
            if (rows.length <= 1) {
                row.querySelectorAll('input').forEach(function (field) {
                    field.value = field.name === 'quantity[]' ? '1' : '';
                });
                row.querySelectorAll('select').forEach(function (field) {
                    field.selectedIndex = 0;
                });
            } else {
                row.remove();
            }

            syncTotal(form);
            saveFormDraft(form);
            updateSaveDraftState(form);
        });
    };

    document.querySelectorAll('[data-costing-form]').forEach(function (form) {
        form.querySelectorAll('.costing-row').forEach(function (row) {
            bindRow(form, row);
        });
        form.querySelectorAll('[data-asset-requirement-row]').forEach(function (row) {
            bindAssetRequirementRow(form, row);
        });

        // Kunin muna ang saved server values bago mag-restore ng unsaved browser draft.
        form.dataset.savedSnapshot = formSnapshot(form);
        restoreFormDraft(form);

        form.querySelector('[data-add-costing-row]')?.addEventListener('click', function () {
            addCostingRow(form);
        });

        form.querySelector('[data-add-asset-requirement]')?.addEventListener('click', function () {
            addAssetRequirementRow(form);
            saveFormDraft(form);
            updateSaveDraftState(form);
        });

        form.querySelector('[data-save-draft]')?.addEventListener('click', function (event) {
            if (!isFormDirty(form) || form.dataset.isSaving === 'true') {
                event.preventDefault();
                event.stopPropagation();
            }
        });

        form.querySelectorAll('textarea').forEach(function (field) {
            field.addEventListener('input', function () {
                clearFieldError(field);
                clearCostingErrorWhenResolved(form);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });
        });

        form.querySelector('[data-clear-costing-form]')?.addEventListener('click', function () {
            if (!window.confirm('Clear all unsaved costing inputs?')) {
                return;
            }

            window.localStorage.removeItem(storageKeyForForm(form));
            form.querySelectorAll('textarea').forEach(function (field) {
                field.value = '';
            });
            form.querySelectorAll('.costing-row').forEach(function (row, index) {
                if (index > 0) {
                    row.remove();
                    return;
                }

                row.querySelectorAll('input').forEach(function (field) {
                    field.value = field.name === 'quantity[]' ? '1' : '';
                });
                row.querySelectorAll('select').forEach(function (field) {
                    field.selectedIndex = 0;
                });
            });
            form.querySelector('[data-asset-requirement-rows]')?.replaceChildren();
            clearCostingError(form);
            form.querySelectorAll('.costing-field-error, .is-invalid').forEach(function (field) {
                field.classList?.remove('is-invalid');
                if (field.classList?.contains('costing-field-error')) {
                    field.remove();
                }
            });
            syncTotal(form);
            updateSaveDraftState(form);
        });

        form.addEventListener('submit', function (event) {
            if (form.dataset.isSaving === 'true') {
                event.preventDefault();
                return;
            }

            const action = event.submitter?.value || 'save_draft';
            const isFinalSubmit = action === 'submit_to_admin';

            if (!isFinalSubmit && !isFormDirty(form)) {
                event.preventDefault();
                updateSaveDraftState(form);
                return;
            }

            form.querySelectorAll('[data-costing-decimal]').forEach(normalizeDecimalField);

            if (!validateCosting(form, isFinalSubmit)) {
                event.preventDefault();
                return;
            }

            if (isFinalSubmit && !window.confirm('Submit this costing to Admin for review?')) {
                event.preventDefault();
                return;
            }

            if (!isFinalSubmit) {
                const saveButton = form.querySelector('[data-save-draft]');
                if (saveButton) {
                    form.dataset.isSaving = 'true';
                    saveButton.disabled = true;
                    saveButton.classList.remove('is-disabled');
                    saveButton.classList.add('is-loading');
                    saveButton.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Saving...';
                }
            }
        });

        syncTotal(form);
        updateSaveDraftState(form);
    });

    const completionModal = document.querySelector('[data-complete-inspection-modal]');
    const completionCancelButton = completionModal?.querySelector('[data-complete-inspection-cancel]');
    const completionConfirmButton = completionModal?.querySelector('[data-complete-inspection-confirm]');
    let pendingCompletionForm = null;
    let pendingCompletionButton = null;

    const closeCompletionModal = function () {
        if (!completionModal || completionModal.dataset.isCompleting === 'true') {
            return;
        }
        completionModal.hidden = true;
        pendingCompletionForm = null;
        pendingCompletionButton = null;
    };

    const openCompletionModal = function (form, button) {
        if (!completionModal || completionModal.dataset.isCompleting === 'true') {
            return;
        }
        pendingCompletionForm = form;
        pendingCompletionButton = button;
        completionModal.hidden = false;
        completionCancelButton?.focus();
    };

    completionCancelButton?.addEventListener('click', closeCompletionModal);
    completionModal?.addEventListener('click', function (event) {
        if (event.target === completionModal) {
            closeCompletionModal();
        }
    });

    completionConfirmButton?.addEventListener('click', function () {
        if (!pendingCompletionForm || completionModal?.dataset.isCompleting === 'true') {
            return;
        }

        completionModal.dataset.isCompleting = 'true';
        completionCancelButton.disabled = true;
        completionConfirmButton.disabled = true;
        completionConfirmButton.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Marking as completed...';
        pendingCompletionButton.disabled = true;
        pendingCompletionButton.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Marking as completed...';
        pendingCompletionForm.dataset.completeConfirmed = 'true';
        pendingCompletionForm.requestSubmit();
    });

    const acknowledgeModal = document.querySelector('[data-acknowledge-modal]');
    const acknowledgeCancelButton = acknowledgeModal?.querySelector('[data-acknowledge-cancel]');
    const acknowledgeConfirmButton = acknowledgeModal?.querySelector('[data-acknowledge-confirm]');
    let pendingAcknowledgeForm = null;
    let pendingAcknowledgeButton = null;

    document.querySelectorAll('[data-confirm-acknowledge]').forEach(function(button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            pendingAcknowledgeForm = button.closest('form');
            pendingAcknowledgeButton = button;
            if (!acknowledgeModal || acknowledgeModal.dataset.isAcknowledging === 'true') return;
            acknowledgeModal.hidden = false;
            acknowledgeCancelButton?.focus();
        });
    });

    acknowledgeCancelButton?.addEventListener('click', function () {
        if (!acknowledgeModal || acknowledgeModal.dataset.isAcknowledging === 'true') return;
        acknowledgeModal.hidden = true;
        pendingAcknowledgeForm = null;
        pendingAcknowledgeButton = null;
    });
    acknowledgeModal?.addEventListener('click', function (event) {
        if (event.target === acknowledgeModal) {
            acknowledgeModal.hidden = true;
            pendingAcknowledgeForm = null;
            pendingAcknowledgeButton = null;
        }
    });

    acknowledgeConfirmButton?.addEventListener('click', function () {
        if (!pendingAcknowledgeForm || acknowledgeModal.dataset.isAcknowledging === 'true') return;
        acknowledgeModal.dataset.isAcknowledging = 'true';
        acknowledgeCancelButton.disabled = true;
        acknowledgeConfirmButton.disabled = true;
        acknowledgeConfirmButton.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Acknowledging...';
        pendingAcknowledgeButton.disabled = true;
        pendingAcknowledgeButton.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Acknowledging...';
        pendingAcknowledgeForm.dataset.acknowledgeConfirmed = 'true';
        pendingAcknowledgeForm.requestSubmit();
    });

    const rescheduleModal = document.querySelector('[data-engineer-reschedule-modal]');
    const rescheduleReviewModal = document.querySelector('[data-engineer-reschedule-review-modal]');
    const rescheduleForm = document.querySelector('[data-engineer-reschedule-form]');
    const rescheduleOpenButtons = document.querySelectorAll('[data-engineer-reschedule-open]');
    const rescheduleCancelButton = rescheduleModal?.querySelector('[data-engineer-reschedule-cancel]');
    const rescheduleReviewCancelButton = rescheduleReviewModal?.querySelector('[data-engineer-reschedule-review-cancel]');
    const rescheduleReviewSubmitButton = rescheduleReviewModal?.querySelector('[data-engineer-reschedule-review-submit]');
    let rescheduleTrigger = null;

    // Load available time slots provided by server (value => label)
    const availableTimeSlots = (function () {
        if (!rescheduleModal) return {};
        const raw = rescheduleModal.dataset.availableTimeSlots || '{}';
        try { return JSON.parse(raw); } catch (e) { return {}; }
    })();
    const rescheduleDateField = rescheduleModal?.querySelector('[data-engineer-reschedule-date]');
    const rescheduleTimeSelect = rescheduleModal?.querySelector('[data-engineer-reschedule-time]');

    const parseManila = function (date, time) {
        // returns ms since epoch for Asia/Manila
        return Date.parse(date + 'T' + time + ':00+08:00');
    };

    const refreshTimeOptions = function () {
        if (!rescheduleTimeSelect || !rescheduleDateField) return;
        const dateVal = rescheduleDateField.value;
        rescheduleTimeSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = dateVal ? 'Select a time' : 'Select a date first';
        placeholder.disabled = true;
        placeholder.selected = true;
        rescheduleTimeSelect.appendChild(placeholder);
        if (!dateVal) { rescheduleTimeSelect.disabled = true; setRescheduleFieldError(rescheduleTimeSelect, ''); return; }
        rescheduleTimeSelect.disabled = false;
        const slots = Object.entries(availableTimeSlots);
        const nowMs = Date.now();
        const manilaNowStr = new Date().toLocaleDateString('en-GB', { timeZone: 'Asia/Manila' });
        const selectedDateIsToday = manilaNowStr === new Date(dateVal + 'T00:00:00+08:00').toLocaleDateString('en-GB', { timeZone: 'Asia/Manila' });
        const cutoff = nowMs + 60 * 60 * 1000; // one hour ahead
        let added = 0;
        slots.forEach(function ([val, label]) {
            const slotMs = Date.parse(dateVal + 'T' + val + ':00+08:00');
            if (selectedDateIsToday && slotMs < cutoff) return; // skip past or less-than-1h slots
            const opt = document.createElement('option'); opt.value = val; opt.textContent = label;
            rescheduleTimeSelect.appendChild(opt); added++;
        });
        if (added === 0) {
            const opt2 = document.createElement('option'); opt2.value = ''; opt2.disabled = true; opt2.selected = true; opt2.textContent = 'No slots available — choose another date';
            rescheduleTimeSelect.appendChild(opt2);
            rescheduleTimeSelect.disabled = true;
        }
        // clear selection if no longer valid
        if (rescheduleTimeSelect.value && !Array.from(rescheduleTimeSelect.options).some(function (o) { return o.value === rescheduleTimeSelect.value; })) {
            rescheduleTimeSelect.value = '';
            setRescheduleFieldError(rescheduleTimeSelect, 'Selected time is no longer available for this date.');
        } else {
            setRescheduleFieldError(rescheduleTimeSelect, '');
        }
    };

    const setRescheduleFieldError = function (field, message) {
        if (!field) return;
        const error = rescheduleModal?.querySelector('[data-engineer-reschedule-error="' + field.name.replace('schedule_preferred_', '') + '"]')
            || rescheduleModal?.querySelector('[data-engineer-reschedule-error="reason"]');
        field.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (error) {
            error.textContent = message || '';
            error.hidden = !message;
        }
    };

    const closeRescheduleModal = function () {
        if (!rescheduleModal || rescheduleModal.dataset.submitting === 'true') return;
        rescheduleModal.hidden = true;
        rescheduleTrigger?.focus();
    };

    const closeRescheduleReviewModal = function (returnToForm) {
        if (!rescheduleReviewModal || rescheduleReviewModal.dataset.submitting === 'true') return;
        rescheduleReviewModal.hidden = true;
        if (returnToForm && rescheduleModal) {
            rescheduleModal.hidden = false;
            rescheduleForm?.querySelector('[data-engineer-reschedule-reason]')?.focus();
        }
    };

    const validateRescheduleForm = function () {
        if (!rescheduleForm) return false;
        const reason = rescheduleForm.elements.schedule_response_note;
        const date = rescheduleForm.elements.schedule_preferred_date;
        const time = rescheduleForm.elements.schedule_preferred_time;
        const checks = [
                [reason, reason.value.trim().replace(/\s+/g, '').length >= 5, 'Enter at least 5 characters.'],
            [date, date.value !== '', 'Choose a preferred new date.'],
            [time, time.value !== '', 'Choose a preferred new time.'],
        ];
        let firstInvalid = null;
        checks.forEach(function ([field, isValid, message]) {
            setRescheduleFieldError(field, isValid ? '' : message);
            if (!isValid && !firstInvalid) firstInvalid = field;
        });

        // additional check: ensure selected time is still allowed client-side
        if (!firstInvalid && time && time.value) {
                const allowed = Object.prototype.hasOwnProperty.call(availableTimeSlots, time.value);
                if (!allowed) {
                    setRescheduleFieldError(time, 'Selected time is not available.');
                    firstInvalid = time;
                } else {
                    // if date is today, ensure at least 1 hour ahead
                    const nowMs = Date.now();
                    const manilaNowStr = new Date().toLocaleDateString('en-GB', { timeZone: 'Asia/Manila' });
                    const selectedDateIsToday = manilaNowStr === new Date(date.value + 'T00:00:00+08:00').toLocaleDateString('en-GB', { timeZone: 'Asia/Manila' });
                    if (selectedDateIsToday) {
                        const slotMs = parseManila(date.value, time.value);
                        if (slotMs < (nowMs + 60 * 60 * 1000)) {
                            setRescheduleFieldError(time, 'Choose a time at least 1 hour from now for today.');
                            firstInvalid = time;
                        }
                    }
                }
        }

        firstInvalid?.focus();
        return !firstInvalid;
    };

    const openRescheduleReview = function () {
        if (!rescheduleModal || !rescheduleReviewModal || !rescheduleForm || !validateRescheduleForm()) return;
        const official = rescheduleModal.querySelector('[data-engineer-reschedule-official]')?.textContent || 'Not set';
        const details = [
            ['Official schedule', official],
            ['Requested date', rescheduleForm.elements.schedule_preferred_date.value],
            ['Requested time', rescheduleForm.elements.schedule_preferred_time.value],
            ['Reason', rescheduleForm.elements.schedule_response_note.value],
        ];
        const list = rescheduleReviewModal.querySelector('[data-engineer-reschedule-review-details]');
        list?.replaceChildren();
        details.forEach(function ([label, value]) {
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = label;
            description.textContent = value;
            list?.append(term, description);
        });
        rescheduleModal.hidden = true;
        rescheduleReviewModal.hidden = false;
        rescheduleReviewCancelButton?.focus();
    };

    rescheduleOpenButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (!rescheduleModal || !rescheduleForm) return;
            rescheduleTrigger = button;
            rescheduleForm.reset();
            rescheduleForm.dataset.submitted = '';
            rescheduleForm.dataset.reviewConfirmed = '';
            rescheduleForm.elements.inspection_id.value = button.dataset.inspectionId || '';
            const inspectionIdForDraft = rescheduleForm.elements.inspection_id.value || '';
            const official = rescheduleModal.querySelector('[data-engineer-reschedule-official]');
            if (official) official.textContent = button.dataset.officialSchedule || 'Not set';
            rescheduleForm.querySelectorAll('[aria-invalid="true"]').forEach(function (field) {
                setRescheduleFieldError(field, '');
                field.dataset.touched = '';
            });
            // populate time options based on any preset date
            refreshTimeOptions();

            // restore draft if present and server hasn't already recorded submission
            try {
                const draftKey = 'reschedule-draft-' + inspectionIdForDraft;
                const raw = sessionStorage.getItem(draftKey);
                if (raw && rescheduleModal.dataset.rescheduleSent !== '1') {
                    const draft = JSON.parse(raw);
                    if (draft) {
                        if (draft.date) rescheduleForm.elements.schedule_preferred_date.value = draft.date;
                        refreshTimeOptions();
                        if (draft.time) rescheduleForm.elements.schedule_preferred_time.value = draft.time;
                        if (draft.reason) rescheduleForm.elements.schedule_response_note.value = draft.reason;
                        // mark touched for validation rules
                        rescheduleForm.querySelectorAll('textarea, input, select').forEach(function (f) { if (f.value) f.dataset.touched = 'true'; });
                    }
                } else if (raw && rescheduleModal.dataset.rescheduleSent === '1') {
                    sessionStorage.removeItem(draftKey);
                }
            } catch (e) { /* ignore */ }

            rescheduleModal.hidden = false;
            rescheduleForm.elements.schedule_response_note.focus();
        });
    });

    // Manage touched state and validation for reschedule form fields
    rescheduleForm?.querySelectorAll('textarea, input, select').forEach(function (field) {
        field.addEventListener('input', function () {
            field.dataset.touched = 'true';
            setRescheduleFieldError(field, '');
            if (field.name === 'schedule_response_note') return;
            // if date changed, refresh time options
            if (field.name === 'schedule_preferred_date') {
                refreshTimeOptions();
            }
        });
        // also handle change for selects (date/time)
        field.addEventListener('change', function () {
            field.dataset.touched = 'true';
            if (field.name === 'schedule_preferred_date') refreshTimeOptions();
            setRescheduleFieldError(field, '');
        });
        field.addEventListener('blur', function () {
            if (field.name === 'schedule_response_note') {
                const touched = field.dataset.touched === 'true' || rescheduleForm.dataset.submitted === 'true';
                if (!touched) { setRescheduleFieldError(field, ''); return; }
                const valid = field.value.trim().replace(/\s+/g, '').length >= 5;
                setRescheduleFieldError(field, valid ? '' : 'Enter at least 5 characters.');
            } else if (field.name === 'schedule_preferred_date') {
                setRescheduleFieldError(field, field.value ? '' : 'Choose a preferred new date.');
            } else if (field.name === 'schedule_preferred_time') {
                setRescheduleFieldError(field, field.value ? '' : 'Choose a preferred new time.');
            }
        });
    });

    rescheduleForm?.addEventListener('submit', function (event) {
        if (rescheduleForm.dataset.reviewConfirmed === 'true') return;
        event.preventDefault();
        rescheduleForm.dataset.submitted = 'true';
        openRescheduleReview();
    });

    rescheduleCancelButton?.addEventListener('click', closeRescheduleModal);
    rescheduleModal?.addEventListener('click', function (event) {
        if (event.target === rescheduleModal) closeRescheduleModal();
    });
    rescheduleReviewCancelButton?.addEventListener('click', function () { closeRescheduleReviewModal(true); });
    rescheduleReviewModal?.addEventListener('click', function (event) {
        if (event.target === rescheduleReviewModal) closeRescheduleReviewModal(true);
    });
    rescheduleReviewSubmitButton?.addEventListener('click', function () {
        if (!rescheduleForm || rescheduleReviewModal?.dataset.submitting === 'true') return;
        // save draft to sessionStorage to preserve values on network failure
        try {
            const inspectionIdForDraft = rescheduleForm.elements.inspection_id.value || '';
            const draftKey = 'reschedule-draft-' + inspectionIdForDraft;
            const draft = {
                reason: rescheduleForm.elements.schedule_response_note.value,
                date: rescheduleForm.elements.schedule_preferred_date.value,
                time: rescheduleForm.elements.schedule_preferred_time.value,
            };
            sessionStorage.setItem(draftKey, JSON.stringify(draft));
        } catch (e) { /* ignore */ }

        rescheduleReviewModal.dataset.submitting = 'true';
        rescheduleReviewCancelButton.disabled = true;
        rescheduleReviewSubmitButton.disabled = true;
        rescheduleReviewSubmitButton.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Sending...';
        rescheduleForm.dataset.reviewConfirmed = 'true';
        rescheduleForm.requestSubmit();
    });

    document.querySelectorAll('[data-confirm-inspection-transition]').forEach(function (button) {
        button.closest('form')?.addEventListener('submit', function (event) {
            const form = event.currentTarget;
            if (button.hasAttribute('data-complete-inspection')) {
                if (form.dataset.completeConfirmed === 'true') {
                    return;
                }
                event.preventDefault();
                openCompletionModal(form, button);
                return;
            }

            const actionLabel = button.getAttribute('data-confirm-inspection-transition') || 'update this inspection';
            if (!window.confirm(`${actionLabel}?`)) {
                event.preventDefault();
            }
        });
    });

    const closeInspectionModal = function (modal) {
        if (!modal) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('inspection-modal-open');
        window.localStorage.removeItem(activeModalKey);
    };

    const openInspectionModal = function (modal) {
        if (!modal) {
            return;
        }

        modal.hidden = false;
        document.body.classList.add('inspection-modal-open');
        window.localStorage.setItem(activeModalKey, modal.id);
        modal.querySelector('[data-inspection-modal-close]')?.focus();
    };

    document.querySelectorAll('[data-inspection-modal-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = document.getElementById(button.getAttribute('data-inspection-modal-open'));
            openInspectionModal(modal);
        });
    });

    document.querySelectorAll('.inspection-modal').forEach(function (modal) {
        const panel = modal.querySelector('.inspection-modal__panel');
        panel?.addEventListener('scroll', function () {
            window.localStorage.setItem(modalScrollKey, String(panel.scrollTop));
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-inspection-modal-close]')) {
                closeInspectionModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (rescheduleReviewModal && !rescheduleReviewModal.hidden) {
                event.preventDefault();
                closeRescheduleReviewModal(true);
                return;
            }
            if (rescheduleModal && !rescheduleModal.hidden) {
                event.preventDefault();
                closeRescheduleModal();
                return;
            }
            if (completionModal && !completionModal.hidden) {
                event.preventDefault();
                closeCompletionModal();
                return;
            }
            if (acknowledgeModal && !acknowledgeModal.hidden) {
                event.preventDefault();
                acknowledgeModal.hidden = true;
                return;
            }
            document.querySelectorAll('.inspection-modal:not([hidden])').forEach(closeInspectionModal);
        }
    });

    // Warn user about unsaved reschedule form changes using native beforeunload prompt
    window.addEventListener('beforeunload', function (e) {
        try {
            if (!rescheduleForm) return;
            const hasTouched = Array.from(rescheduleForm.querySelectorAll('textarea, input, select')).some(function (f) { return f.dataset.touched === 'true'; });
            if (hasTouched && !rescheduleForm.dataset.submitted) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes.';
            }
        } catch (err) { /* ignore */ }
    });

    const requestedInspectionId = Number.parseInt(new URLSearchParams(window.location.search).get('inspection_id') || '0', 10);
    const requestedModal = requestedInspectionId > 0 ? document.getElementById('inspectionModal' + requestedInspectionId) : null;
    if (requestedModal) {
        openInspectionModal(requestedModal);
        const url = new URL(window.location.href);
        url.searchParams.delete('inspection_id');
        window.history.replaceState({}, document.title, url);
    } else {
        const activeModalId = window.localStorage.getItem(activeModalKey);
        const modal = activeModalId ? document.getElementById(activeModalId) : null;
        const panel = modal?.querySelector('.inspection-modal__panel');
        if (modal) {
            openInspectionModal(modal);
            window.setTimeout(function () {
                if (panel) {
                    panel.scrollTop = Number(window.localStorage.getItem(modalScrollKey) || 0);
                }
            }, 0);
        }
    }

});
