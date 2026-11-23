(function () {
    'use strict';

    const form = document.querySelector('[data-stock-in-form]');
    const modal = document.querySelector('[data-stock-in-confirm-modal]');
    const rowsContainer = form?.querySelector('[data-stock-in-rows]');
    const rowTemplate = form?.querySelector('[data-stock-in-row-template]');
    const addButton = form?.querySelector('[data-stock-in-add]');
    const formSubmitButton = form?.querySelector('[data-stock-in-submit]');
    const clearButton = form?.querySelector('[data-stock-in-clear]');
    const closeButton = modal?.querySelector('[data-stock-in-confirm-close]');
    const submitButton = modal?.querySelector('[data-stock-in-confirm-submit]');
    const clearModal = document.querySelector('[data-stock-in-clear-modal]');
    const clearCloseButton = clearModal?.querySelector('[data-stock-in-clear-close]');
    const clearConfirmButton = clearModal?.querySelector('[data-stock-in-clear-confirm]');
    const maximumRows = 50;
    const draftStorageKey = 'admin_material_delivery_draft';
    let confirmed = false;

    if (!form || !modal || !rowsContainer || !rowTemplate || !addButton || !formSubmitButton || !clearButton || !closeButton || !submitButton || !clearModal || !clearCloseButton || !clearConfirmButton) return;

    const modalController = window.SharedModalController?.create({
        modal: modal,
        onOpen: function () { document.body.classList.add('material-create-modal-open'); },
        onClose: function () { document.body.classList.remove('material-create-modal-open'); },
    });
    const clearModalController = window.SharedModalController?.create({
        modal: clearModal,
        onOpen: function () { document.body.classList.add('material-create-modal-open'); },
        onClose: function () { document.body.classList.remove('material-create-modal-open'); },
    });

    function hasEnteredDetails() {
        const rows = Array.from(rowsContainer.querySelectorAll('[data-stock-in-row]'));
        return form.elements.receipt_no.value.trim() !== ''
            || form.elements.supplier_name.value.trim() !== ''
            || form.elements.remarks.value.trim() !== ''
            || rows.length > 1
            || rows.some(function (row) {
                return row.querySelector('[data-stock-in-material]')?.value !== ''
                    || row.querySelector('[data-stock-in-quantity]')?.value.trim() !== '';
            });
    }

    function validateStaticFields() {
        const receipt = form.elements.receipt_no;
        const supplier = form.elements.supplier_name;
        const notes = form.elements.remarks;
        const deliveryDate = form.elements.delivery_date;
        const receiptValue = receipt.value.trim();
        const supplierValue = supplier.value.trim();

        receipt.setCustomValidity(receiptValue !== '' && !/^[\p{L}\p{N}/-]+$/u.test(receiptValue)
            ? 'Use letters, numbers, dash, and slash only.'
            : '');
        supplier.setCustomValidity(supplierValue !== '' && !/^[\p{L}\p{N} &.,-]+$/u.test(supplierValue)
            ? 'Use letters, numbers, spaces, &, period, dash, and comma only.'
            : '');
        notes.setCustomValidity(/\p{C}/u.test(notes.value)
            ? 'Enter valid note text only.'
            : '');
        deliveryDate.setCustomValidity(
            deliveryDate.value !== '' && (deliveryDate.value < deliveryDate.min || deliveryDate.value > deliveryDate.max)
                ? 'Choose a date within the last 30 days, up to today.'
                : ''
        );
    }

    function validateRows() {
        const rows = Array.from(rowsContainer.querySelectorAll('[data-stock-in-row]'));
        const counts = new Map();
        rows.forEach(function (row) {
            const select = row.querySelector('[data-stock-in-material]');
            if (select?.value) counts.set(select.value, (counts.get(select.value) || 0) + 1);
        });

        rows.forEach(function (row) {
            const select = row.querySelector('[data-stock-in-material]');
            const quantity = row.querySelector('[data-stock-in-quantity]');
            const option = select?.options[select.selectedIndex];
            const wholeNumber = option?.dataset.wholeNumber === 'true';
            const quantityValue = Number(quantity?.value || 0);
            select?.setCustomValidity(select.value && counts.get(select.value) > 1
                ? 'Do not add the same material twice.'
                : '');
            quantity?.setCustomValidity(quantity.value !== '' && wholeNumber && !Number.isInteger(quantityValue)
                ? 'Use a whole number for this unit.'
                : '');
        });

        addButton.disabled = rows.length >= maximumRows;
        addButton.title = rows.length >= maximumRows ? 'Maximum of 50 materials reached.' : '';
        return rows;
    }

    function updateSubmitState() {
        validateStaticFields();
        const rows = validateRows();
        const receiptValue = form.elements.receipt_no.value.trim();
        const supplierValue = form.elements.supplier_name.value.trim();
        const deliveryDate = form.elements.delivery_date;
        const notesValue = form.elements.remarks.value;
        const staticFieldsReady = /^[\p{L}\p{N}/-]+$/u.test(receiptValue)
            && /^[\p{L}\p{N} &.,-]+$/u.test(supplierValue)
            && deliveryDate.value !== ''
            && deliveryDate.value >= deliveryDate.min
            && deliveryDate.value <= deliveryDate.max
            && !/\p{C}/u.test(notesValue);
        const selectedMaterialIds = rows.map(function (row) {
            return row.querySelector('[data-stock-in-material]').value;
        }).filter(Boolean);
        const materialsAreUnique = new Set(selectedMaterialIds).size === selectedMaterialIds.length;
        const materialsReady = rows.length > 0 && materialsAreUnique && rows.every(function (row) {
            const material = row.querySelector('[data-stock-in-material]');
            const quantity = row.querySelector('[data-stock-in-quantity]');
            const option = material.options[material.selectedIndex];
            const quantityValue = Number(quantity.value);
            const wholeNumber = option?.dataset.wholeNumber === 'true';
            return material.value !== ''
                && quantity.value !== ''
                && Number.isFinite(quantityValue)
                && quantityValue > 0
                && quantityValue <= Number(quantity.max)
                && (!wholeNumber || Number.isInteger(quantityValue));
        });
        formSubmitButton.disabled = form.dataset.deliveryReady !== 'true' || !staticFieldsReady || !materialsReady;
        clearButton.disabled = !hasEnteredDetails();
    }

    function saveDraft() {
        const rows = Array.from(rowsContainer.querySelectorAll('[data-stock-in-row]')).map(function (row) {
            return {
                materialId: row.querySelector('[data-stock-in-material]')?.value || '',
                quantity: row.querySelector('[data-stock-in-quantity]')?.value || '',
            };
        });
        const draft = {
            receiptNo: form.elements.receipt_no.value,
            supplierName: form.elements.supplier_name.value,
            deliveryDate: form.elements.delivery_date.value,
            remarks: form.elements.remarks.value,
            rows: rows,
        };
        try {
            sessionStorage.setItem(draftStorageKey, JSON.stringify(draft));
        } catch (error) {
            // Tuloy pa rin ang form kapag bawal ang browser storage.
        }
    }

    function restoreDraft() {
        let draft;
        try {
            draft = JSON.parse(sessionStorage.getItem(draftStorageKey) || 'null');
        } catch (error) {
            sessionStorage.removeItem(draftStorageKey);
            return;
        }
        if (!draft || !Array.isArray(draft.rows)) return;

        form.elements.receipt_no.value = draft.receiptNo || '';
        form.elements.supplier_name.value = draft.supplierName || '';
        form.elements.delivery_date.value = draft.deliveryDate || form.elements.delivery_date.value;
        form.elements.remarks.value = draft.remarks || '';

        const wantedRows = Math.min(Math.max(draft.rows.length, 1), maximumRows);
        while (rowsContainer.querySelectorAll('[data-stock-in-row]').length < wantedRows) {
            rowsContainer.appendChild(rowTemplate.content.cloneNode(true));
        }
        while (rowsContainer.querySelectorAll('[data-stock-in-row]').length > wantedRows) {
            rowsContainer.lastElementChild?.remove();
        }

        rowsContainer.querySelectorAll('[data-stock-in-row]').forEach(function (row, index) {
            const savedRow = draft.rows[index] || {};
            window.EdgeMaterialSuggestions?.bind(row);
            const material = row.querySelector('[data-stock-in-material]');
            material.value = savedRow.materialId || '';
            material.dispatchEvent(new Event('change', { bubbles: true }));
            row.querySelector('[data-stock-in-quantity]').value = savedRow.quantity || '';
            updateRow(row);
        });
    }

    function updateRow(row) {
        const materialSelect = row.querySelector('[data-stock-in-material]');
        const quantityInput = row.querySelector('[data-stock-in-quantity]');
        const unitText = row.querySelector('[data-stock-in-unit]');
        const warning = row.querySelector('[data-stock-limit-warning]');
        const option = materialSelect?.options[materialSelect.selectedIndex];
        const unit = option?.dataset.materialUnit || 'Unit';
        const wholeNumber = option?.dataset.wholeNumber === 'true';

        if (unitText) unitText.textContent = unit;
        if (quantityInput) {
            quantityInput.min = wholeNumber ? '1' : '0.01';
            quantityInput.step = wholeNumber ? '1' : '0.01';
        }
        if (warning) {
            const current = Number(option?.dataset.currentStock || 0);
            const maximum = Number(option?.dataset.maximumStock || 0);
            const delivery = Number(quantityInput?.value || 0);
            const projected = current + delivery;
            const overLimit = maximum > 0 && delivery > 0 && projected > maximum;
            warning.hidden = !overLimit;
            warning.textContent = overLimit
                ? 'Warning: projected stock is ' + projected.toLocaleString() + ' ' + unit + ', above the ' + maximum.toLocaleString() + ' maximum.'
                : '';
        }
    }

    function refreshRemoveButtons() {
        const rows = rowsContainer.querySelectorAll('[data-stock-in-row]');
        rows.forEach(function (row) {
            const removeButton = row.querySelector('[data-stock-in-row-remove]');
            if (removeButton) {
                removeButton.disabled = rows.length === 1;
                removeButton.hidden = rows.length === 1;
            }
        });
    }

    restoreDraft();
    rowsContainer.querySelectorAll('[data-stock-in-row]').forEach(updateRow);
    refreshRemoveButtons();
    updateSubmitState();

    addButton.addEventListener('click', function () {
        if (rowsContainer.querySelectorAll('[data-stock-in-row]').length >= maximumRows) return;
        const fragment = rowTemplate.content.cloneNode(true);
        const row = fragment.querySelector('[data-stock-in-row]');
        rowsContainer.appendChild(fragment);
        window.EdgeMaterialSuggestions?.bind(row);
        updateRow(row);
        refreshRemoveButtons();
        updateSubmitState();
        saveDraft();
        row.querySelector('[data-stock-in-material]')?.focus();
    });

    rowsContainer.addEventListener('change', function (event) {
        if (!event.target.matches('[data-stock-in-material]')) return;
        updateRow(event.target.closest('[data-stock-in-row]'));
        updateSubmitState();
        saveDraft();
    });

    rowsContainer.addEventListener('click', function (event) {
        const removeButton = event.target.closest('[data-stock-in-row-remove]');
        if (!removeButton || removeButton.disabled) return;
        removeButton.closest('[data-stock-in-row]')?.remove();
        refreshRemoveButtons();
        updateSubmitState();
        saveDraft();
    });

    form.addEventListener('input', function (event) {
        const row = event.target.closest('[data-stock-in-row]');
        if (row) updateRow(row);
        updateSubmitState();
        saveDraft();
    });
    form.addEventListener('change', function () {
        updateSubmitState();
        saveDraft();
    });
    window.addEventListener('pageshow', updateSubmitState);

    function closeModal() {
        modalController?.close();
    }

    form.addEventListener('submit', function (event) {
        if (confirmed) {
            confirmed = false;
            return;
        }
        updateSubmitState();
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        event.preventDefault();
        modalController?.open({
            trigger: form.querySelector('button[type="submit"]'),
            focus: closeButton,
        });
    });

    closeButton.addEventListener('click', closeModal);
    submitButton.addEventListener('click', function () {
        confirmed = true;
        sessionStorage.removeItem(draftStorageKey);
        form.requestSubmit();
    });

    clearButton.addEventListener('click', function () {
        if (!hasEnteredDetails()) return;
        clearModalController?.open({ trigger: clearButton, focus: clearCloseButton });
    });
    clearCloseButton.addEventListener('click', function () {
        clearModalController?.close();
    });
    clearConfirmButton.addEventListener('click', function () {
        sessionStorage.removeItem(draftStorageKey);
        form.elements.receipt_no.value = '';
        form.elements.supplier_name.value = '';
        form.elements.delivery_date.value = form.elements.delivery_date.max;
        form.elements.remarks.value = '';
        rowsContainer.replaceChildren(rowTemplate.content.cloneNode(true));
        const row = rowsContainer.querySelector('[data-stock-in-row]');
        window.EdgeMaterialSuggestions?.bind(row);
        updateRow(row);
        refreshRemoveButtons();
        updateSubmitState();
        clearModalController?.close();
        form.elements.receipt_no.focus();
    });
}());
