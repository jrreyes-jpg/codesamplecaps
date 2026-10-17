(function () {
    'use strict';

    const form = document.querySelector('[data-stock-in-form]');
    const modal = document.querySelector('[data-stock-in-confirm-modal]');
    const closeButton = modal?.querySelector('[data-stock-in-confirm-close]');
    const submitButton = modal?.querySelector('[data-stock-in-confirm-submit]');
    const materialSelect = form?.querySelector('[data-stock-in-material]');
    const quantityInput = form?.querySelector('[data-stock-in-quantity]');
    const quantityHint = form?.querySelector('[data-stock-in-unit-hint]');
    let confirmed = false;
    let returnFocus = null;

    if (!form || !modal || !closeButton || !submitButton) return;

    function updateQuantityUnit() {
        const option = materialSelect?.options[materialSelect.selectedIndex];
        const unit = option?.dataset.materialUnit || '';
        const wholeNumber = option?.dataset.wholeNumber === 'true';

        if (quantityHint) {
            quantityHint.textContent = unit === ''
                ? 'Select a material to see its unit.'
                : 'Unit: ' + unit + (wholeNumber ? ' (whole number only)' : '');
        }
        if (quantityInput) {
            quantityInput.step = wholeNumber ? '1' : '0.01';
        }
    }

    materialSelect?.addEventListener('change', updateQuantityUnit);
    updateQuantityUnit();

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
