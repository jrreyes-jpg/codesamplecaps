(function () {
    'use strict';

    const form = document.querySelector('[data-stock-in-form]');
    const modal = document.querySelector('[data-stock-in-confirm-modal]');
    const rowsContainer = form?.querySelector('[data-stock-in-rows]');
    const rowTemplate = form?.querySelector('[data-stock-in-row-template]');
    const addButton = form?.querySelector('[data-stock-in-add]');
    const formSubmitButton = form?.querySelector('[data-stock-in-submit]');
    const closeButton = modal?.querySelector('[data-stock-in-confirm-close]');
    const submitButton = modal?.querySelector('[data-stock-in-confirm-submit]');
    let confirmed = false;
    let returnFocus = null;

    if (!form || !modal || !rowsContainer || !rowTemplate || !addButton || !formSubmitButton || !closeButton || !submitButton) return;

    function updateSubmitState() {
        const deliveryReady = form.dataset.deliveryReady === 'true';
        const receiptReady = form.elements.receipt_no.value.trim() !== '';
        const supplierReady = form.elements.supplier_name.value.trim() !== '';
        const rows = Array.from(rowsContainer.querySelectorAll('[data-stock-in-row]'));
        const materialsReady = rows.length > 0 && rows.every(function (row) {
            const materialId = row.querySelector('[data-stock-in-material]')?.value || '';
            const quantity = Number(row.querySelector('[data-stock-in-quantity]')?.value || 0);
            return materialId !== '' && Number.isFinite(quantity) && quantity > 0;
        });

        formSubmitButton.disabled = !(deliveryReady && receiptReady && supplierReady && materialsReady);
    }

    function updateRow(row) {
        const materialSelect = row.querySelector('[data-stock-in-material]');
        const quantityInput = row.querySelector('[data-stock-in-quantity]');
        const unitText = row.querySelector('[data-stock-in-unit]');
        const option = materialSelect?.options[materialSelect.selectedIndex];
        const unit = option?.dataset.materialUnit || 'Unit';
        const wholeNumber = option?.dataset.wholeNumber === 'true';

        if (unitText) unitText.textContent = unit;
        if (quantityInput) quantityInput.step = wholeNumber ? '1' : '0.01';
    }

    function refreshRemoveButtons() {
        const rows = rowsContainer.querySelectorAll('[data-stock-in-row]');
        rows.forEach(function (row) {
            const removeButton = row.querySelector('[data-stock-in-row-remove]');
            if (removeButton) removeButton.disabled = rows.length === 1;
        });
    }

    rowsContainer.querySelectorAll('[data-stock-in-row]').forEach(updateRow);
    refreshRemoveButtons();
    updateSubmitState();

    addButton.addEventListener('click', function () {
        const fragment = rowTemplate.content.cloneNode(true);
        const row = fragment.querySelector('[data-stock-in-row]');
        rowsContainer.appendChild(fragment);
        updateRow(row);
        refreshRemoveButtons();
        updateSubmitState();
        row.querySelector('[data-stock-in-material]')?.focus();
    });

    rowsContainer.addEventListener('change', function (event) {
        if (!event.target.matches('[data-stock-in-material]')) return;
        updateRow(event.target.closest('[data-stock-in-row]'));
        updateSubmitState();
    });

    rowsContainer.addEventListener('click', function (event) {
        const removeButton = event.target.closest('[data-stock-in-row-remove]');
        if (!removeButton || removeButton.disabled) return;
        removeButton.closest('[data-stock-in-row]')?.remove();
        refreshRemoveButtons();
        updateSubmitState();
    });

    form.addEventListener('input', updateSubmitState);
    form.addEventListener('change', updateSubmitState);

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('material-create-modal-open');
        returnFocus?.focus();
    }

    form.addEventListener('submit', function (event) {
        if (confirmed) {
            confirmed = false;
            return;
        }
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        event.preventDefault();
        returnFocus = form.querySelector('button[type="submit"]');
        modal.hidden = false;
        document.body.classList.add('material-create-modal-open');
        window.requestAnimationFrame(function () { closeButton.focus(); });
    });

    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
        }
    });
    submitButton.addEventListener('click', function () {
        confirmed = true;
        form.requestSubmit();
    });
}());
