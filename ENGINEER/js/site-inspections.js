// Costing rows para mabilis magdagdag ng materials, labor, at notes.
document.addEventListener('DOMContentLoaded', function () {
    const activeModalKey = 'engineer.siteInspection.activeModal';
    const modalScrollKey = 'engineer.siteInspection.modalScroll';
    const activeTabKey = 'engineer.siteInspection.activeTab';

    const money = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    });

    const normalizeCostType = function (type) {
        const value = String(type || '').trim().toLowerCase();
        const legacyTypes = {
            equipment: 'misc_safety_temp',
            service: 'misc_safety_temp',
            other: 'misc_safety_temp',
        };
        return legacyTypes[value] || value;
    };

    const syncTotal = function (form) {
        let total = 0;
        const categoryTotals = {
            material: 0,
            labor: 0,
            misc_safety_temp: 0,
            transport: 0,
            management_overhead_commission: 0,
        };
        form.querySelectorAll('.costing-row').forEach(function (row) {
            const quantityField = row.querySelector('input[name="quantity[]"]');
            const unitField = row.querySelector('select[name="unit[]"]');
            const costField = row.querySelector('input[name="unit_cost[]"]');
            if (isValidQuantityText(quantityField?.value.trim() || '', unitField?.value || '')
                && isValidCostText(costField?.value.trim() || '')) {
                const lineTotal = Number(quantityField.value) * Number(costField.value);
                const itemType = normalizeCostType(row.querySelector('select[name="item_type[]"]')?.value);
                total += lineTotal;
                if (Object.prototype.hasOwnProperty.call(categoryTotals, itemType)) {
                    categoryTotals[itemType] += lineTotal;
                }
            }
        });

        form.querySelectorAll('[data-costing-category-total]').forEach(function (output) {
            output.textContent = money.format(categoryTotals[output.dataset.costingCategoryTotal] || 0);
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

        const submitButton = form.querySelector('[data-submit-costing]');
        if (submitButton) {
            const canSubmit = isCostingReadyForSubmit(form);
            submitButton.disabled = !canSubmit;
            submitButton.classList.toggle('is-locked', !canSubmit);
            submitButton.setAttribute('aria-disabled', canSubmit ? 'false' : 'true');
            submitButton.title = canSubmit ? '' : 'Complete the required findings and cost item.';
        }

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
        row.querySelector('select[name="item_type[]"]').value = normalizeCostType(data.item_type) || 'material';
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
        feedback.removeAttribute('title');

        if (feedback.hidden) return;

        const availableLabel = `${formatMaterialQuantity(available)} ${unit}`;
        const requiredLabel = `${formatMaterialQuantity(required)} ${unit}`;
        if (required <= available) {
            feedback.classList.add('is-available');
            feedback.textContent = `Available: ${availableLabel}`;
            return;
        }

        feedback.classList.add('is-shortage');
        feedback.textContent = available <= 0
            ? 'Out of stock — Purchase required.'
            : `Stock shortage: ${availableLabel} available, ${requiredLabel} needed.`;
        feedback.title = feedback.textContent;
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
            const removeRow = function () {
                row.remove();
                saveFormDraft(form);
                updateSaveDraftState(form);
            };
            if (assetRequirementHasMeaningfulData(row)) {
                openInspectionActionConfirm({
                    trigger: this,
                    title: 'Remove Asset Requirement?',
                    message: 'This will remove the selected asset requirement.',
                    confirm: 'Remove',
                    onConfirm: removeRow,
                });
                return;
            }
            removeRow();
        });
    };

    const addAssetRequirementRow = function (form, data = null) {
        const rowsBox = form.querySelector('[data-asset-requirement-rows]');
        const template = form.querySelector('[data-asset-requirement-template]');
        if (!rowsBox || !template) {
            return null;
        }

        rowsBox.closest('details')?.setAttribute('open', '');

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
        const itemNameField = row.querySelector('[data-item-name-field]');
        const itemNameLabel = row.querySelector('[data-item-name-label]');
        const itemName = row.querySelector('input[name="item_name[]"]');
        const unit = row.querySelector('select[name="unit[]"]');
        const inventoryId = row.querySelector('input[name="inventory_id[]"]');
        const isMaterial = type === 'material';

        const fieldDetails = {
            material: ['Material Name', 'Enter material name'],
            labor: ['Labor Description', 'Enter labor description'],
            misc_safety_temp: ['Miscellaneous / Facility Item', 'Enter safety, facility, or miscellaneous item'],
            transport: ['Transport Description', 'Enter transport details'],
            management_overhead_commission: ['Management / Overhead Item', 'Enter management, overhead, or commission detail'],
        };
        const [label, placeholder] = fieldDetails[type] || ['Item Description', 'Enter item description'];
        if (itemNameLabel) {
            itemNameLabel.childNodes[0].nodeValue = `${label} `;
        }
        if (itemName) {
            itemName.placeholder = placeholder;
        }

        materialField?.toggleAttribute('hidden', !isMaterial);
        if (!isMaterial) {
            if (picker) picker.value = '';
            if (inventoryId) inventoryId.value = '';
            itemNameField?.removeAttribute('hidden');
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
            itemNameField?.setAttribute('hidden', '');
            itemName.setAttribute('readonly', 'readonly');
            unit.value = linkedUnit;
            unit.setAttribute('data-locked', 'true');
            unit.setAttribute('aria-disabled', 'true');
        } else {
            itemNameField?.removeAttribute('hidden');
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

    // Magtanong lang kapag may kumpletong cost item na mabubura.
    const costingRowHasCompletedData = function (row) {
        const name = row.querySelector('input[name="item_name[]"]')?.value.trim() || '';
        const quantity = row.querySelector('input[name="quantity[]"]')?.value.trim() || '';
        const unit = row.querySelector('select[name="unit[]"]')?.value || '';
        const unitCost = row.querySelector('input[name="unit_cost[]"]')?.value.trim() || '';

        return name !== ''
            && isValidQuantityText(quantity, unit)
            && isValidCostText(unitCost);
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

    const hasMeaningfulFindings = function (value) {
        const text = String(value || '').trim();
        return text.length >= 10 && text.length <= 1000 && /[a-z]/i.test(text);
    };

    const updateFindingsCount = function (form) {
        const field = form.querySelector('textarea[name="engineer_findings"]');
        const counter = form.querySelector('[data-findings-count]');
        if (field && counter) counter.textContent = `${field.value.length} / 1000`;
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

        return /^(?:0|[1-9]\d*)(\.\d{1,2})?$/.test(value) && Number(value) > 0;
    };

    const cleanQuantityInput = function (field, unit) {
        if (!field) return;

        let value = field.value.replace(/[^\d.]/g, '');
        if (isWholeCountUnit(unit)) {
            value = value.replace(/\D/g, '');
        } else {
            const parts = value.split('.');
            const whole = parts.shift() || '';
            const decimal = parts.join('').slice(0, 2);
            value = whole === '' && (parts.length > 0 || decimal !== '')
                ? `0.${decimal}`
                : `${whole}${parts.length > 0 ? `.${decimal}` : ''}`;
        }

        if (field.value !== value) field.value = value;
    };

    const cleanUnitCostInput = function (field) {
        if (!field) return;

        const cleanValue = field.value.replace(/[^\d.]/g, '');
        const parts = cleanValue.split('.');
        const whole = parts.shift() || '';
        const decimal = parts.join('').slice(0, 2);
        const value = whole === '' && (parts.length > 0 || decimal !== '')
            ? `0.${decimal}`
            : `${whole}${parts.length > 0 ? `.${decimal}` : ''}`;

        if (field.value !== value) field.value = value;
    };

    const updateCostEntryNumbers = function (form) {
        form.querySelectorAll('.costing-row').forEach(function (row, index) {
            const number = row.querySelector('[data-costing-row-number]');
            if (number) number.textContent = `Cost Entry ${index + 1}`;
        });
    };

    const quantityErrorMessage = function (value, unit) {
        if (isWholeCountUnit(unit)) {
            return `Enter a whole number greater than 0 for ${unit || 'this unit'}.`;
        }
        if (/^(?:0|[1-9]\d*)\.\d{3,}$/.test(value)) {
            return 'Maximum 2 decimal places.';
        }
        return 'Qty must be greater than 0.';
    };

    const addCostingRow = function (form, type = 'material') {
        const rowsBox = form.querySelector('[data-costing-rows]');
        const firstRow = rowsBox?.querySelector('.costing-row');
        if (!rowsBox || !firstRow) {
            return null;
        }

        rowsBox.closest('details')?.setAttribute('open', '');

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
        updateCostEntryNumbers(form);
        syncTotal(form);
        saveFormDraft(form);
        updateSaveDraftState(form);

        return row;
    };

    const validateCosting = function (form, requireFinal) {
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
            const rowHasValue = rowHasMeaningfulData(row);

            if (!rowHasValue) {
                return;
            }

            hasAnyRow = true;
            if (isValidQuantityText(quantityText, unitValue) && isValidCostText(unitCostText)) {
                total += quantityValue * Number(unitCostText);
            }

            if (nameValue === '') {
                setFieldError(name, 'Item / Labor name is required.');
                firstInvalid = firstInvalid || name;
            }

            if (!isValidQuantityText(quantityText, unitValue)) {
                setFieldError(quantity, quantityErrorMessage(quantityText, unitValue));
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

        if (requireFinal && !hasAnyRow) {
            firstInvalid = firstInvalid || form.querySelector('input[name="item_name[]"]');
            setFieldError(firstInvalid, 'Add at least one cost item.');
        }

        if (requireFinal && findings && !hasMeaningfulFindings(findings.value)) {
            setFieldError(findings, 'Enter 10 to 1,000 characters.');
            firstInvalid = firstInvalid || findings;
        }

        if (requireFinal && total <= 0) {
            const totalBox = form.querySelector('[data-costing-total]');
            totalBox?.classList.add('is-invalid-total');
            firstInvalid = firstInvalid || totalBox;
            errorMessage = errorMessage || 'Total cost must be greater than 0.';
        }

        if (firstInvalid) {
            showCostingError(form, 'Complete the highlighted fields before submitting.');
            firstInvalid.closest('details')?.setAttribute('open', '');
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (typeof firstInvalid.focus === 'function') {
                firstInvalid.focus();
            }
            return false;
        }

        return true;
    };

    const isCostingReadyForSubmit = function (form) {
        const findings = form.querySelector('textarea[name="engineer_findings"]');
        if (!findings || !hasMeaningfulFindings(findings.value)) return false;

        const rows = Array.from(form.querySelectorAll('.costing-row')).filter(rowHasMeaningfulData);
        if (rows.length < 1) return false;

        return rows.every(function (row) {
            const name = row.querySelector('input[name="item_name[]"]')?.value.trim() || '';
            const quantity = row.querySelector('input[name="quantity[]"]')?.value.trim() || '';
            const unit = row.querySelector('select[name="unit[]"]')?.value || '';
            const unitCost = row.querySelector('input[name="unit_cost[]"]')?.value.trim() || '';
            return name !== ''
                && unit !== ''
                && isValidQuantityText(quantity, unit)
                && isValidCostText(unitCost);
        });
    };

    const bindRow = function (form, row) {
        const typeField = row.querySelector('select[name="item_type[]"]');
        let previousType = typeField?.value || 'material';
        setLinkedMaterialState(row);
        updateMaterialAvailability(row);

        typeField?.addEventListener('change', function () {
            const nextType = typeField.value;
            const applyTypeChange = function () {
                typeField.value = nextType;
                previousType = nextType;
                resetRowForType(row);
                syncTotal(form);
                saveFormDraft(form);
                updateSaveDraftState(form);
            };
            if (costingRowHasCompletedData(row)) {
                typeField.value = previousType;
                openInspectionActionConfirm({
                    trigger: typeField,
                    title: 'Change Cost Type?',
                    message: 'Changing the type will clear the current row details.',
                    confirm: 'Change Type',
                    onConfirm: applyTypeChange,
                });
                return;
            }
            applyTypeChange();
        });

        row.querySelectorAll('[data-costing-decimal]').forEach(function (field) {
            field.addEventListener('input', function () {
                if (field.name === 'quantity[]') {
                    const unit = row.querySelector('select[name="unit[]"]')?.value || '';
                    cleanQuantityInput(field, unit);
                } else if (field.name === 'unit_cost[]') {
                    cleanUnitCostInput(field);
                }
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
                if (value !== '') {
                    if (valid) {
                        clearFieldError(field);
                    } else {
                        setFieldError(field, field.name === 'unit_cost[]'
                            ? costErrorMessage(value)
                            : quantityErrorMessage(value, unit));
                    }
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
            if (value !== '') {
                if (isValidQuantityText(value, event.target.value)) {
                    clearFieldError(quantity);
                } else {
                    setFieldError(quantity, quantityErrorMessage(value, event.target.value));
                }
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
            const removeRow = function () {
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
                updateCostEntryNumbers(form);
                syncTotal(form);
                saveFormDraft(form);
                updateSaveDraftState(form);
            };
            if (rowHasMeaningfulData(row)) {
                openInspectionActionConfirm({
                    trigger: this,
                    title: 'Remove Costing Item?',
                    message: 'This will remove the current costing row and its entered details.',
                    confirm: 'Remove',
                    onConfirm: removeRow,
                });
                return;
            }
            removeRow();
        });
    };

    const bindCostingForm = function (form) {
        if (!form || form.dataset.costingHandlersBound === '1') {
            return;
        }
        form.dataset.costingHandlersBound = '1';

        // Huwag ipakita ang lumang browser draft sa locked report.
        if (form.classList.contains('inspection-costing-form--readonly')) {
            window.localStorage.removeItem(storageKeyForForm(form));
            syncTotal(form);
            return;
        }

        form.querySelectorAll('.costing-row').forEach(function (row) {
            bindRow(form, row);
        });
        updateCostEntryNumbers(form);
        form.querySelectorAll('[data-asset-requirement-row]').forEach(function (row) {
            bindAssetRequirementRow(form, row);
        });

        // Kunin muna ang saved server values bago mag-restore ng unsaved browser draft.
        form.dataset.savedSnapshot = formSnapshot(form);
        restoreFormDraft(form);
        updateCostEntryNumbers(form);

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
                if (field.name === 'engineer_findings') updateFindingsCount(form);
                clearFieldError(field);
                clearCostingErrorWhenResolved(form);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });
        });
        updateFindingsCount(form);

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
            form.dataset.validationAttempted = 'true';

            if (!validateCosting(form, isFinalSubmit)) {
                event.preventDefault();
                return;
            }

            if (isFinalSubmit && form.dataset.submitConfirmed !== 'true') {
                event.preventDefault();
                openInspectionActionConfirm({
                    trigger: event.submitter,
                    title: 'Submit to Admin?',
                    message: 'This will submit the inspection findings and costing to Admin for review.',
                    confirm: 'Submit to Admin',
                    onConfirm: function () {
                        form.dataset.submitConfirmed = 'true';
                        submitCostingToAdmin(form, event.submitter);
                    },
                });
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
    };

    document.querySelectorAll('[data-costing-form]').forEach(bindCostingForm);

    const activateInspectionTab = function (modal, nextTab) {
        if (!modal || !nextTab) return;

        const requestedButton = modal.querySelector('[data-inspection-tab="' + nextTab + '"]');
        if (!requestedButton || requestedButton.disabled) {
            nextTab = 'details';
        }

        window.localStorage.setItem(`${activeTabKey}.${modal.id}`, nextTab);
        const modalPanel = modal.querySelector('.inspection-modal__panel');
        if (modalPanel) {
            modalPanel.scrollTop = 0;
        }

        modal.querySelectorAll('[data-inspection-tab]').forEach(function (button) {
            const isActive = button.getAttribute('data-inspection-tab') === nextTab;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        modal.querySelectorAll('[data-inspection-tab-panel]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-inspection-tab-panel') !== nextTab;
        });
        modal.querySelectorAll('[data-inspection-report-actions]').forEach(function (actions) {
            actions.hidden = nextTab !== 'report';
        });
    };

    document.addEventListener('click', function (event) {
        const tab = event.target.closest('[data-inspection-tab]');
        if (!tab) return;

        activateInspectionTab(tab.closest('.inspection-modal'), tab.getAttribute('data-inspection-tab'));
    });

    const inspectionActionConfirmModal = document.querySelector('[data-inspection-action-confirm-modal]');
    const inspectionActionConfirmTitle = inspectionActionConfirmModal?.querySelector('[data-inspection-action-confirm-title]');
    const inspectionActionConfirmMessage = inspectionActionConfirmModal?.querySelector('[data-inspection-action-confirm-message]');
    const inspectionActionConfirmReasonWrap = inspectionActionConfirmModal?.querySelector('[data-inspection-action-confirm-reason-wrap]');
    const inspectionActionConfirmReason = inspectionActionConfirmModal?.querySelector('[data-inspection-action-confirm-reason]');
    const inspectionActionConfirmReasonError = inspectionActionConfirmModal?.querySelector('[data-inspection-action-confirm-reason-error]');
    const inspectionActionConfirmCancel = inspectionActionConfirmModal?.querySelector('[data-inspection-action-confirm-cancel]');
    const inspectionActionConfirmSubmit = inspectionActionConfirmModal?.querySelector('[data-inspection-action-confirm-submit]');
    let pendingInspectionActionConfirm = null;

    const closeInspectionActionConfirm = function (returnFocus) {
        if (!inspectionActionConfirmModal || inspectionActionConfirmModal.dataset.submitting === 'true') return;
        const trigger = pendingInspectionActionConfirm?.trigger;
        inspectionActionConfirmModal.hidden = true;
        pendingInspectionActionConfirm = null;
        if (returnFocus) trigger?.focus();
    };

    const openInspectionActionConfirm = function (options) {
        if (!inspectionActionConfirmModal || inspectionActionConfirmModal.dataset.submitting === 'true') return;
        pendingInspectionActionConfirm = options;
        inspectionActionConfirmTitle.textContent = options.title;
        inspectionActionConfirmMessage.textContent = options.message;
        inspectionActionConfirmSubmit.textContent = options.confirm;
        if (inspectionActionConfirmReasonWrap) {
            inspectionActionConfirmReasonWrap.hidden = !Boolean(options.requiresReason);
        }
        if (inspectionActionConfirmReason) {
            inspectionActionConfirmReason.value = '';
            inspectionActionConfirmReason.setAttribute('aria-invalid', 'false');
        }
        if (inspectionActionConfirmReasonError) {
            inspectionActionConfirmReasonError.textContent = '';
            inspectionActionConfirmReasonError.hidden = true;
        }
        inspectionActionConfirmSubmit.disabled = false;
        inspectionActionConfirmCancel.disabled = false;
        inspectionActionConfirmModal.hidden = false;
        (options.requiresReason ? inspectionActionConfirmReason : inspectionActionConfirmCancel)?.focus();
    };

    inspectionActionConfirmCancel?.addEventListener('click', function () {
        closeInspectionActionConfirm(true);
    });
    inspectionActionConfirmModal?.querySelector('[data-inspection-action-confirm-close]')?.addEventListener('click', function () {
        closeInspectionActionConfirm(true);
    });
    inspectionActionConfirmModal?.addEventListener('click', function (event) {
        if (event.target === inspectionActionConfirmModal) closeInspectionActionConfirm(true);
    });
    inspectionActionConfirmReason?.addEventListener('input', function () {
        inspectionActionConfirmReason.setAttribute('aria-invalid', 'false');
        if (inspectionActionConfirmReasonError) {
            inspectionActionConfirmReasonError.textContent = '';
            inspectionActionConfirmReasonError.hidden = true;
        }
    });
    inspectionActionConfirmSubmit?.addEventListener('click', function () {
        const action = pendingInspectionActionConfirm;
        if (!action || inspectionActionConfirmModal?.dataset.submitting === 'true') return;
        const reason = inspectionActionConfirmReason?.value.trim() || '';
        if (action.requiresReason && reason.replace(/\s+/g, '') === '') {
            inspectionActionConfirmReason?.setAttribute('aria-invalid', 'true');
            if (inspectionActionConfirmReasonError) {
                inspectionActionConfirmReasonError.textContent = 'Enter a hold reason.';
                inspectionActionConfirmReasonError.hidden = false;
            }
            inspectionActionConfirmReason?.focus();
            return;
        }
        inspectionActionConfirmModal.dataset.submitting = 'true';
        inspectionActionConfirmCancel.disabled = true;
        inspectionActionConfirmSubmit.disabled = true;
        inspectionActionConfirmSubmit.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Processing...';
        Promise.resolve()
            .then(function () { return action.onConfirm(reason); })
            .then(function (success) {
                if (success !== false) {
                    inspectionActionConfirmModal.hidden = true;
                    pendingInspectionActionConfirm = null;
                }
            })
            .finally(function () {
                if (!inspectionActionConfirmModal.hidden) {
                    inspectionActionConfirmCancel.disabled = false;
                    inspectionActionConfirmSubmit.disabled = false;
                    inspectionActionConfirmSubmit.textContent = action.confirm;
                }
                inspectionActionConfirmModal.dataset.submitting = '';
            });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab' || !inspectionActionConfirmModal || inspectionActionConfirmModal.hidden) return;
        const controls = Array.from(inspectionActionConfirmModal.querySelectorAll('button:not([disabled]), textarea:not([disabled])'))
            .filter(function (control) { return !control.closest('[hidden]'); });
        if (controls.length === 0) return;
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    const parseInspectionJsonResponse = function (response, fallbackMessage) {
        return response.text().then(function (text) {
            let payload = {};
            try {
                payload = text ? JSON.parse(text) : {};
            } catch (error) {
                throw new Error('The server returned an invalid response. Please try again.');
            }
            if (!response.ok || payload.success !== true) {
                throw new Error(payload.message || fallbackMessage);
            }
            return payload;
        });
    };

    const applyWorkflowFallback = function (form, payload) {
        const card = form.closest('.inspection-card');
        const status = payload?.status || '';
        if (!card || !status) return;

        const statusNode = card.querySelector('.inspection-status');
        if (statusNode) {
            const displayStatus = status === 'Submitted' ? 'Costing Submitted' : status;
            statusNode.dataset.status = displayStatus;
            statusNode.textContent = displayStatus;
        }

        const steps = Array.from(card.querySelectorAll('.inspection-workflow__step'));
        const progressIndexByStatus = {
            Assigned: 0,
            Acknowledged: 0,
            Ongoing: 1,
            'On Hold': 1,
            Completed: 2,
            Submitted: 2,
        };
        const currentIndex = progressIndexByStatus[status] ?? -1;
        steps.forEach(function (step, index) {
            step.classList.toggle('is-done', currentIndex > index);
            step.classList.toggle('is-current', currentIndex === index);
            const label = step.querySelector('span')?.textContent.trim() || '';
            const timestampKey = label === 'Costing Submitted' ? 'Submitted' : label;
            const timestamp = payload.timestamps?.[timestampKey] || '';
            if (timestamp) {
                let timeNode = step.querySelector('small');
                if (!timeNode) {
                    timeNode = document.createElement('small');
                    step.appendChild(timeNode);
                }
                timeNode.textContent = timestamp;
            }
        });

        const actionButton = form.querySelector('[data-workflow-transition]')
            || (form.id ? document.querySelector('[data-workflow-transition][form="' + CSS.escape(form.id) + '"]') : null);
        const actionField = form.elements.workflow_action;
        const nextAction = {
            Assigned: ['start', 'Start Inspection'],
            Acknowledged: ['start', 'Start Inspection'],
            Ongoing: ['complete', 'Submit to Admin'],
        }[status];
        if (!nextAction) {
            actionButton?.remove();
            form.remove();
            return;
        }
        actionField.value = nextAction[0];
        actionButton.textContent = nextAction[1];
        actionButton.disabled = false;
        actionButton.toggleAttribute('data-complete-inspection', nextAction[0] === 'complete');
    };

    const requestWorkflowTransition = function (form, button, holdReason) {
        const action = form.elements.workflow_action?.value || '';
        const originalLabel = button.dataset.originalLabel || button.textContent.trim();
        const data = new URLSearchParams();
        data.append('csrf_token', form.elements.csrf_token?.value || '');
        data.append('inspection_id', String(form.elements.inspection_id?.value || ''));
        data.append('workflow_action', action);
        if (holdReason) data.append('hold_reason', holdReason);
        form.dataset.isTransitioning = 'true';
        button.disabled = true;
        button.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Updating...';

        return fetch(form.getAttribute('action') || window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: data.toString(),
        })
            .then(function (response) {
                return parseInspectionJsonResponse(response, 'Unable to update the inspection. Please try again.');
            })
            .then(function (payload) {
                return refreshInspectionCard(String(payload.inspection_id || form.elements.inspection_id?.value || ''), true)
                    .then(function (updatedCard) {
                        if (action === 'start') {
                            activateInspectionTab(updatedCard?.querySelector('.inspection-modal'), 'report');
                        }
                        return updatedCard;
                    })
                    .catch(function () {
                        applyWorkflowFallback(form, payload);
                        return null;
                    })
                    .then(function () {
                        if (typeof window.showToast === 'function') {
                            window.showToast(payload.message || 'Inspection updated.', 'success', { duration: 4000 });
                        }
                        return true;
                    });
            })
            .catch(function (error) {
                // Check the server state first. A lost connection may still have saved the action.
                return refreshInspectionCard(String(form.elements.inspection_id?.value || ''), true)
                    .catch(function () { return null; })
                    .then(function () {
                        if (button.isConnected) {
                            button.disabled = false;
                            button.textContent = originalLabel;
                        }
                        form.dataset.isTransitioning = '';
                        if (typeof window.showToast === 'function') {
                            window.showToast(error.message || 'Unable to update the inspection. Please try again.', 'error', { duration: 8000 });
                        }
                        return false;
                    });
            });
    };

    const submitCostingToAdmin = function (form, button) {
        const originalLabel = button?.textContent.trim() || 'Submit to Admin';
        const data = new FormData(form);
        data.set('costing_action', 'submit_to_admin');
        form.dataset.isSaving = 'true';
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Submitting...';
        }

        return fetch(form.getAttribute('action') || window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: data,
        })
            .then(function (response) {
                return parseInspectionJsonResponse(response, 'Unable to submit the inspection to Admin. Please try again.');
            })
            .then(function (payload) {
                return refreshInspectionCard(String(payload.inspection_id || form.querySelector('input[name="inspection_id"]')?.value || ''), true)
                    .catch(function () { return null; })
                    .then(function () {
                        window.localStorage.removeItem(storageKeyForForm(form));
                        if (typeof window.showToast === 'function') {
                            window.showToast(payload.message || 'Costing submitted to Admin.', 'success', { duration: 4000 });
                        }
                        return true;
                    });
            })
            .catch(function (error) {
                return refreshInspectionCard(String(form.querySelector('input[name="inspection_id"]')?.value || ''), true)
                    .catch(function () { return null; })
                    .then(function () {
                        if (button?.isConnected) {
                            button.disabled = false;
                            button.textContent = originalLabel;
                        }
                        form.dataset.isSaving = 'false';
                        form.dataset.submitConfirmed = '';
                        if (typeof window.showToast === 'function') {
                            window.showToast(error.message || 'Unable to submit the inspection to Admin. Please try again.', 'error', { duration: 8000 });
                        }
                        return false;
                    });
            });
    };

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
        completionConfirmButton.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Submitting costing...';
        pendingCompletionButton.dataset.originalLabel = pendingCompletionButton.textContent.trim() || 'Submit to Admin';
        pendingCompletionButton.disabled = true;
        pendingCompletionButton.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Submitting costing...';
        requestWorkflowTransition(pendingCompletionForm, pendingCompletionButton).then(function (success) {
            if (success) {
                completionModal.hidden = true;
                completionModal.dataset.isCompleting = '';
                pendingCompletionForm = null;
                pendingCompletionButton = null;
                return;
            }
            completionModal.dataset.isCompleting = '';
            completionCancelButton.disabled = false;
            completionConfirmButton.disabled = false;
            completionConfirmButton.textContent = 'Submit Costing';
        });
    });

    const rescheduleModal = document.querySelector('[data-engineer-reschedule-modal]');
    const rescheduleReviewModal = document.querySelector('[data-engineer-reschedule-review-modal]');
    const rescheduleForm = document.querySelector('[data-engineer-reschedule-form]');
    const rescheduleCancelButton = rescheduleModal?.querySelector('[data-engineer-reschedule-cancel]');
    const rescheduleReviewCancelButton = rescheduleReviewModal?.querySelector('[data-engineer-reschedule-review-cancel]');
    const rescheduleReviewSubmitButton = rescheduleReviewModal?.querySelector('[data-engineer-reschedule-review-submit]');
    let rescheduleTrigger = null;

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

    const closeRescheduleModalByButton = function () {
        closeRescheduleModal();
        if (rescheduleForm) {
            rescheduleForm.dataset.submitted = '';
        }
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
        const checks = [
            [reason, reason.value.trim().replace(/\s+/g, '').length >= 5, 'Enter at least 5 characters.'],
            [date, date.value !== '', 'Choose a preferred new date.'],
        ];
        let firstInvalid = null;
        checks.forEach(function ([field, isValid, message]) {
            setRescheduleFieldError(field, isValid ? '' : message);
            if (!isValid && !firstInvalid) firstInvalid = field;
        });

        firstInvalid?.focus();
        return !firstInvalid;
    };

    const openRescheduleReview = function () {
        if (!rescheduleModal || !rescheduleReviewModal || !rescheduleForm || !validateRescheduleForm()) return;
        const official = rescheduleModal.querySelector('[data-engineer-reschedule-official]')?.textContent || 'Not set';
        const details = [
            ['Official schedule', official],
            ['Requested date', rescheduleForm.elements.schedule_preferred_date.value],
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

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-engineer-reschedule-open]');
        if (!button || !rescheduleModal || !rescheduleForm) return;

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

        try {
            const draftKey = 'reschedule-draft-' + inspectionIdForDraft;
            const raw = sessionStorage.getItem(draftKey);
            if (raw && rescheduleModal.dataset.rescheduleSent !== '1') {
                const draft = JSON.parse(raw);
                if (draft) {
                    if (draft.date) rescheduleForm.elements.schedule_preferred_date.value = draft.date;
                    if (draft.reason) rescheduleForm.elements.schedule_response_note.value = draft.reason;
                    rescheduleForm.querySelectorAll('textarea, input, select').forEach(function (field) {
                        if (field.value) field.dataset.touched = 'true';
                    });
                }
            } else if (raw && rescheduleModal.dataset.rescheduleSent === '1') {
                sessionStorage.removeItem(draftKey);
            }
        } catch (error) {
            // Tuloy lang kapag hindi mabasa ang local draft.
        }

        rescheduleModal.hidden = false;
        rescheduleForm.elements.schedule_response_note.focus();
    });

    // Manage touched state and validation for reschedule form fields
    rescheduleForm?.querySelectorAll('textarea, input, select').forEach(function (field) {
        field.addEventListener('input', function () {
            field.dataset.touched = 'true';
            setRescheduleFieldError(field, '');
            if (field.name === 'schedule_response_note') return;
        });
        field.addEventListener('change', function () {
            field.dataset.touched = 'true';
            setRescheduleFieldError(field, '');
        });
        field.addEventListener('blur', function () {
            field.dataset.touched = 'true';
            if (field.name === 'schedule_response_note') {
                const valid = field.value.trim().replace(/\s+/g, '').length >= 5;
                setRescheduleFieldError(field, valid ? '' : 'Enter at least 5 characters.');
            } else if (field.name === 'schedule_preferred_date') {
                setRescheduleFieldError(field, field.value ? '' : 'Choose a preferred new date.');
            }
        });
    });

    rescheduleForm?.addEventListener('submit', function (event) {
        if (rescheduleForm.dataset.reviewConfirmed === 'true') return;
        event.preventDefault();
        rescheduleForm.dataset.submitted = 'true';
        openRescheduleReview();
    });

    const closeRescheduleButton = rescheduleModal?.querySelector('[data-engineer-reschedule-close]');
    rescheduleCancelButton?.addEventListener('click', closeRescheduleModalByButton);
    closeRescheduleButton?.addEventListener('click', closeRescheduleModalByButton);
    rescheduleModal?.addEventListener('click', function (event) {
        if (event.target === rescheduleModal) closeRescheduleModalByButton();
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

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-workflow-transition]');
        if (!button) return;
        const linkedFormId = button.getAttribute('form') || '';
        const form = linkedFormId ? document.getElementById(linkedFormId) : button.closest('form');
        const action = form?.elements.workflow_action?.value || '';
        if (!form || form.dataset.isTransitioning === 'true') return;

        if (action === 'complete') {
            const modal = button.closest('.inspection-modal');
            const costingForm = modal?.querySelector('[data-costing-form]');
            const findings = costingForm?.querySelector('textarea[name="engineer_findings"]');
            activateInspectionTab(modal, 'report');
            if (costingForm) costingForm.dataset.validationAttempted = 'true';
            const reportIsValid = costingForm ? validateCosting(costingForm, true) : false;
            const findingsAreValid = hasMeaningfulFindings(findings?.value || '');

            if (!reportIsValid || !findingsAreValid) {
                if (!findingsAreValid && findings) {
                    setFieldError(findings, 'Enter 10 to 1,000 characters.');
                }
                showCostingError(costingForm, 'Complete the highlighted fields before submitting.');
                (findings && !findingsAreValid ? findings : costingForm?.querySelector('.is-invalid'))?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                (findings && !findingsAreValid ? findings : costingForm?.querySelector('.is-invalid'))?.focus();
                return;
            }
            if (button.getAttribute('data-complete-ready') !== 'true' || (costingForm && isFormDirty(costingForm))) {
                showCostingError(costingForm, 'Save the costing first before submitting.');
                costingForm?.querySelector('[data-save-draft]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            openCompletionModal(form, button);
            return;
        }

        if (action === 'start') {
            openInspectionActionConfirm({
                trigger: button,
                title: 'Start Inspection?',
                message: 'This will save the start time and unlock the inspection findings and costing form.',
                confirm: 'Start Inspection',
                onConfirm: function () {
                    return requestWorkflowTransition(form, button);
                },
            });
            return;
        }

        if (action === 'hold') {
            openInspectionActionConfirm({
                trigger: button,
                title: 'Put Inspection On Hold?',
                message: 'Add a reason. Admin will see this while the inspection is on hold.',
                confirm: 'Put On Hold',
                requiresReason: true,
                onConfirm: function (reason) {
                    return requestWorkflowTransition(form, button, reason);
                },
            });
            return;
        }

        if (action === 'resume') {
            openInspectionActionConfirm({
                trigger: button,
                title: 'Resume Inspection?',
                message: 'This will return the inspection to Ongoing.',
                confirm: 'Resume Inspection',
                onConfirm: function () {
                    return requestWorkflowTransition(form, button);
                },
            });
        }
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
        const savedTab = window.localStorage.getItem(`${activeTabKey}.${modal.id}`);
        if (modal.dataset.reportSubmitted === 'true') {
            activateInspectionTab(modal, 'report');
        } else if (savedTab === 'details' || savedTab === 'report') {
            activateInspectionTab(modal, savedTab);
        }
        modal.querySelector('[data-inspection-modal-close]')?.focus();
    };

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-inspection-modal-open]');
        if (!button) return;

        const modal = document.getElementById(button.getAttribute('data-inspection-modal-open'));
        openInspectionModal(modal);
    });

    document.addEventListener('scroll', function (event) {
        const panel = event.target.closest?.('.inspection-modal__panel');
        if (panel) {
            window.localStorage.setItem(modalScrollKey, String(panel.scrollTop));
        }
    }, true);

    document.addEventListener('click', function (event) {
        const modal = event.target.closest('.inspection-modal');
        if (modal && (event.target === modal || event.target.closest('[data-inspection-modal-close]'))) {
            closeInspectionModal(modal);
        }
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
            if (inspectionActionConfirmModal && !inspectionActionConfirmModal.hidden) {
                event.preventDefault();
                closeInspectionActionConfirm(true);
                return;
            }
            document.querySelectorAll('.inspection-modal:not([hidden])').forEach(closeInspectionModal);
        }
    });

    // Warn user about unsaved reschedule form changes using native beforeunload prompt
    window.addEventListener('beforeunload', function (e) {
        try {
            if (window.__edgeLogoutInProgress) return;
            if (!rescheduleForm) return;
            const hasTouched = Array.from(rescheduleForm.querySelectorAll('textarea, input, select')).some(function (f) { return f.dataset.touched === 'true'; });
            if (hasTouched && !rescheduleForm.dataset.submitted) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes.';
            }
        } catch (err) { /* ignore */ }
    });

    const inspectionPanel = document.querySelector('.inspection-panel');
    const inspectionFilterButtons = Array.from(document.querySelectorAll('[data-inspection-filter]'));
    const inspectionFilterEmpty = inspectionPanel?.querySelector('[data-inspection-filter-empty]');
    const inspectionFilterMessages = {
        all: 'No site inspections yet.',
        new: 'No new site inspections.',
        ongoing: 'No ongoing site inspections.',
        submitted: 'No submitted site inspections.',
    };
    let activeInspectionFilter = 'all';

    const inspectionMatchesFilter = function (card, filter) {
        const status = card.querySelector('.inspection-card__head .inspection-status')?.dataset.status || '';
        if (filter === 'new') return card.classList.contains('is-unviewed');
        if (filter === 'ongoing') return status === 'Ongoing';
        if (filter === 'submitted') return ['Submitted', 'Costing Submitted', 'Submitted to Admin', 'Submitted for Admin Review'].includes(status);
        return true;
    };

    const applyInspectionFilter = function () {
        if (!inspectionPanel || inspectionFilterButtons.length === 0) return;

        const cards = Array.from(inspectionPanel.querySelectorAll(':scope > [data-inspection-card-id]'));
        let visibleCount = 0;
        cards.forEach(function (card) {
            // Huwag isara ang details habang nagbabago ang New o status.
            const detailsAreOpen = Boolean(card.querySelector('.inspection-modal:not([hidden])'));
            const shouldHide = !inspectionMatchesFilter(card, activeInspectionFilter) && !detailsAreOpen;
            if (card.hidden !== shouldHide) card.hidden = shouldHide;
            if (!shouldHide) visibleCount++;
        });

        inspectionFilterButtons.forEach(function (button) {
            const filter = button.dataset.inspectionFilter;
            button.setAttribute('aria-pressed', filter === activeInspectionFilter ? 'true' : 'false');
            const count = button.querySelector('[data-inspection-filter-count]');
            const nextCount = String(cards.filter(function (card) { return inspectionMatchesFilter(card, filter); }).length);
            if (count && count.textContent !== nextCount) count.textContent = nextCount;
        });

        const initialEmptyMessage = inspectionPanel.querySelector('[data-inspection-empty]');
        if (initialEmptyMessage) initialEmptyMessage.hidden = cards.length > 0;
        if (inspectionFilterEmpty) {
            inspectionFilterEmpty.hidden = visibleCount > 0 || Boolean(initialEmptyMessage && cards.length === 0);
            const message = cards.length === 0 ? inspectionFilterMessages.all : inspectionFilterMessages[activeInspectionFilter];
            if (inspectionFilterEmpty.textContent !== message) inspectionFilterEmpty.textContent = message;
        }
    };

    inspectionFilterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            activeInspectionFilter = button.dataset.inspectionFilter || 'all';
            applyInspectionFilter();
        });
    });

    if (inspectionPanel && inspectionFilterButtons.length > 0) {
        let filterRefreshQueued = false;
        const inspectionFilterObserver = new MutationObserver(function (records) {
            const changed = records.some(function (record) {
                if (record.type === 'childList') return record.target === inspectionPanel;
                return (record.attributeName === 'class' && record.target.matches('[data-inspection-card-id]'))
                    || (record.attributeName === 'data-status' && record.target.matches('.inspection-status'))
                    || (record.attributeName === 'hidden' && record.target.matches('.inspection-modal'));
            });
            if (!changed || filterRefreshQueued) return;
            filterRefreshQueued = true;
            window.queueMicrotask(function () {
                filterRefreshQueued = false;
                applyInspectionFilter();
            });
        });
        inspectionFilterObserver.observe(inspectionPanel, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'data-status', 'hidden'],
        });
        applyInspectionFilter();
    }

    const inspectionRefreshUrl = new URL(window.location.href);
    inspectionRefreshUrl.searchParams.delete('inspection_id');
    let inspectionRefreshInProgress = false;
    let inspectionRefreshDeferred = false;
    let inspectionSchedulePollInProgress = false;
    const pendingInspectionIds = new Set();

    const replaceInspectionCardSnapshot = function (currentCard, nextCard, keepModalOpen) {
        const currentModal = currentCard?.querySelector('.inspection-modal');
        const keepOpen = Boolean(keepModalOpen && currentModal && !currentModal.hidden);
        const savedScrollTop = currentModal?.querySelector('.inspection-modal__panel')?.scrollTop || 0;
        const savedTab = currentModal?.querySelector('[data-inspection-tab].is-active')?.getAttribute('data-inspection-tab') || 'details';
        const importedCard = document.importNode(nextCard, true);

        currentCard.replaceWith(importedCard);
        bindCostingForm(importedCard.querySelector('[data-costing-form]'));

        if (keepOpen) {
            const nextModal = importedCard.querySelector('.inspection-modal');
            const nextPanel = nextModal?.querySelector('.inspection-modal__panel');
            if (nextModal) {
                nextModal.hidden = false;
                document.body.classList.add('inspection-modal-open');
                window.localStorage.setItem(activeModalKey, nextModal.id);
                activateInspectionTab(nextModal, savedTab);
            }
            if (nextPanel) nextPanel.scrollTop = savedScrollTop;
        }

        return importedCard;
    };

    const refreshInspectionCard = function (inspectionId, keepModalOpen) {
        if (!inspectionPanel || !inspectionId) {
            return Promise.reject(new Error('Inspection card is not available.'));
        }

        const currentCard = inspectionPanel.querySelector('[data-inspection-card-id="' + CSS.escape(String(inspectionId)) + '"]');
        if (!currentCard) {
            return Promise.reject(new Error('Inspection card is not available.'));
        }

        return fetch(inspectionRefreshUrl.toString(), {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Inspection details refresh failed.');
                return response.text();
            })
            .then(function (html) {
                const nextDocument = new DOMParser().parseFromString(html, 'text/html');
                const nextCard = nextDocument.querySelector('[data-inspection-card-id="' + CSS.escape(String(inspectionId)) + '"]');
                if (!nextCard) throw new Error('Updated inspection details were not found.');

                return replaceInspectionCardSnapshot(currentCard, nextCard, keepModalOpen);
            });
    };

    const pollInspectionScheduleUpdates = function () {
        if (!inspectionPanel || inspectionSchedulePollInProgress || document.hidden) return;
        if (document.querySelector('[data-inspection-action-confirm-modal]:not([hidden]), [data-engineer-reschedule-modal]:not([hidden]), [data-engineer-reschedule-review-modal]:not([hidden])')) return;

        inspectionSchedulePollInProgress = true;
        fetch(inspectionRefreshUrl.toString(), {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Inspection schedule check failed.');
                return response.text();
            })
            .then(function (html) {
                const nextDocument = new DOMParser().parseFromString(html, 'text/html');
                let scheduleWasUpdated = false;

                inspectionPanel.querySelectorAll(':scope > [data-inspection-card-id]').forEach(function (currentCard) {
                    const inspectionId = currentCard.dataset.inspectionCardId || '';
                    const nextCard = nextDocument.querySelector('[data-inspection-card-id="' + CSS.escape(inspectionId) + '"]');
                    const costingForm = currentCard.querySelector('[data-costing-form]');
                    const hasUnsavedReport = costingForm
                        && !costingForm.classList.contains('inspection-costing-form--readonly')
                        && isFormDirty(costingForm);
                    if (hasUnsavedReport) return;

                    if (!nextCard) {
                        const currentModal = currentCard.querySelector('.inspection-modal');
                        if (currentModal && !currentModal.hidden) {
                            document.body.classList.remove('inspection-modal-open');
                            window.localStorage.removeItem(activeModalKey);
                        }
                        currentCard.remove();
                        scheduleWasUpdated = true;
                        return;
                    }
                    if (currentCard.dataset.inspectionSyncKey === nextCard.dataset.inspectionSyncKey) return;

                    const keepModalOpen = !currentCard.querySelector('.inspection-modal')?.hidden;
                    replaceInspectionCardSnapshot(currentCard, nextCard, keepModalOpen);
                    scheduleWasUpdated = true;
                });

                mergeAssignedInspectionCards(nextDocument);
                if (scheduleWasUpdated && typeof window.showToast === 'function') {
                    window.showToast('Inspection schedule updated by Admin.', 'success', { duration: 5000 });
                }
            })
            .catch(function () {
                // Susubok ulit sa next check.
            })
            .finally(function () {
                inspectionSchedulePollInProgress = false;
            });
    };

    const inspectionRefreshIsBlocked = function () {
        return Boolean(document.querySelector([
            '.inspection-modal:not([hidden])',
            '[data-complete-inspection-modal]:not([hidden])',
            '[data-engineer-reschedule-modal]:not([hidden])',
            '[data-engineer-reschedule-review-modal]:not([hidden])',
        ].join(', ')));
    };

    const mergeAssignedInspectionCards = function (nextDocument) {
        const result = { success: false, insertedIds: [], missingIds: [] };
        if (!inspectionPanel) return result;

        const nextPanel = nextDocument.querySelector('.inspection-panel');
        if (!nextPanel) return result;

        const nextCards = Array.from(nextPanel.querySelectorAll(':scope > [data-inspection-card-id]'));
        const currentCardsById = new Map(Array.from(inspectionPanel.querySelectorAll(':scope > [data-inspection-card-id]')).map(function (card) {
            return [card.dataset.inspectionCardId || '', card];
        }));
        const scrollX = window.scrollX;
        const scrollY = window.scrollY;

        nextCards.forEach(function (nextCard, index) {
            const inspectionId = nextCard.dataset.inspectionCardId || '';
            if (inspectionId === '' || currentCardsById.has(inspectionId)) return;

            const importedCard = document.importNode(nextCard, true);
            const followingCard = nextCards.slice(index + 1).map(function (card) {
                return currentCardsById.get(card.dataset.inspectionCardId || '');
            }).find(Boolean);
            inspectionPanel.insertBefore(importedCard, followingCard || null);
            currentCardsById.set(inspectionId, importedCard);
            result.insertedIds.push(inspectionId);
        });

        pendingInspectionIds.forEach(function (inspectionId) {
            if (currentCardsById.has(inspectionId)) {
                pendingInspectionIds.delete(inspectionId);
            } else {
                result.missingIds.push(inspectionId);
            }
        });

        if (result.insertedIds.length > 0) {
            inspectionPanel.querySelector(':scope > [data-inspection-empty]')?.remove();
        }
        result.success = result.missingIds.length === 0;
        window.requestAnimationFrame(function () {
            window.scrollTo(scrollX, scrollY);
        });
        return result;
    };

    const refreshAssignedInspectionCards = function () {
        if (!inspectionPanel || inspectionRefreshInProgress || pendingInspectionIds.size === 0) return;
        if (inspectionRefreshIsBlocked()) {
            inspectionRefreshDeferred = true;
            return;
        }

        inspectionRefreshInProgress = true;
        inspectionRefreshDeferred = false;

        fetch(inspectionRefreshUrl.toString(), {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Inspection list refresh failed.');
                return response.text();
            })
            .then(function (html) {
                if (inspectionRefreshIsBlocked()) {
                    inspectionRefreshDeferred = true;
                    return;
                }
                const nextDocument = new DOMParser().parseFromString(html, 'text/html');
                mergeAssignedInspectionCards(nextDocument);
            })
            .catch(function () {
                // Mananatili ang lumang cards. Susubok ulit sa next bell check.
            })
            .finally(function () {
                inspectionRefreshInProgress = false;
            });
    };

    if (inspectionPanel) {
        pollInspectionScheduleUpdates();
        window.setInterval(pollInspectionScheduleUpdates, 5000);

        const modalStateObserver = new MutationObserver(function () {
            if (inspectionRefreshDeferred && !inspectionRefreshIsBlocked()) {
                refreshAssignedInspectionCards();
            }
        });
        modalStateObserver.observe(document.querySelector('.inspection-shell') || inspectionPanel, {
            subtree: true,
            attributes: true,
            attributeFilter: ['hidden'],
        });

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) pollInspectionScheduleUpdates();
        });
    }

    document.addEventListener('edge:engineer-notifications-updated', function (event) {
        const assignmentItems = Array.isArray(event.detail?.assignment_items)
            ? event.detail.assignment_items
            : [];
        const activeAssignmentIds = new Set();

        assignmentItems.forEach(function (item) {
            const inspectionId = String(Number.parseInt(item?.inspection_id || '0', 10));
            if (inspectionId === '0') return;

            activeAssignmentIds.add(inspectionId);
            if (!document.querySelector('[data-inspection-card-id="' + CSS.escape(inspectionId) + '"]')) {
                pendingInspectionIds.add(inspectionId);
            }
        });
        pendingInspectionIds.forEach(function (inspectionId) {
            if (!activeAssignmentIds.has(inspectionId)) pendingInspectionIds.delete(inspectionId);
        });
        refreshAssignedInspectionCards();
    });

    const requestedInspectionId = Number.parseInt(new URLSearchParams(window.location.search).get('inspection_id') || '0', 10);
    const requestedModal = requestedInspectionId > 0 ? document.getElementById('inspectionModal' + requestedInspectionId) : null;
    if (requestedModal) {
        openInspectionModal(requestedModal);
        const requestedCard = requestedModal.closest('[data-inspection-card-id]');
        const requestedButton = requestedCard?.querySelector('[data-engineer-notification-id]');
        const notificationId = Number.parseInt(requestedButton?.dataset.engineerNotificationId || '0', 10);
        if (notificationId > 0 && typeof window.edgeEngineerMarkNotificationRead === 'function') {
            window.edgeEngineerMarkNotificationRead(notificationId, requestedInspectionId)
                .then(function (data) {
                    if (!data?.success) return;
                    requestedButton?.removeAttribute('data-engineer-notification-id');
                    requestedCard?.querySelector('[data-inspection-new-badge]')?.remove();
                    requestedCard?.classList.remove('is-unviewed');
                    requestedCard?.classList.add('is-viewed');
                })
                .catch(function () {
                    // Mananatiling New kapag hindi naisave ang read state.
                });
        }
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
