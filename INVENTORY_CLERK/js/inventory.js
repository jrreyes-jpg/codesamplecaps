document.addEventListener('DOMContentLoaded', () => {
    const unitsModal = document.querySelector('[data-view-asset-units-modal]');
    const unitsBody = document.querySelector('[data-view-asset-units-body]');
    const unitsEmpty = document.querySelector('[data-view-asset-units-empty]');
    const qrModal = document.querySelector('[data-view-unit-qr-modal]');

    if (unitsModal && unitsBody && unitsEmpty && qrModal) {
        const unitStatusLabel = {
            available: 'Available',
            deployed: 'Deployed / In Use',
            maintenance: 'Maintenance',
            lost: 'Lost',
            archived: 'Archived',
        };
        const closeQrModal = () => {
            qrModal.hidden = true;
            document.body.classList.remove('inventory-unit-qr-modal-open');
        };
        const closeUnitsModal = () => {
            closeQrModal();
            unitsModal.hidden = true;
            document.body.classList.remove('inventory-units-modal-open');
        };
        const openQrModal = (unit) => {
            const title = qrModal.querySelector('[data-view-unit-qr-title]');
            const image = qrModal.querySelector('[data-view-unit-qr-image]');
            const unavailable = qrModal.querySelector('[data-view-unit-qr-unavailable]');
            title.textContent = unit.unit_code || 'Unit';
            image.src = unit.qr_image || '';
            image.hidden = !unit.qr_image;
            unavailable.hidden = Boolean(unit.qr_image);
            qrModal.hidden = false;
            document.body.classList.add('inventory-unit-qr-modal-open');
        };
        const addUnitRow = (unit) => {
            const row = document.createElement('tr');
            const codeCell = document.createElement('td');
            const statusCell = document.createElement('td');
            const qrCell = document.createElement('td');
            const actionCell = document.createElement('td');
            const status = Object.hasOwn(unitStatusLabel, unit.status) ? unit.status : 'archived';
            const statusBadge = document.createElement('span');
            const qrText = document.createElement('span');
            const viewButton = document.createElement('button');

            codeCell.dataset.label = 'Unit Code';
            codeCell.textContent = unit.unit_code || '—';
            statusCell.dataset.label = 'Status';
            statusBadge.className = `inventory-unit-status inventory-unit-status--${status}`;
            statusBadge.textContent = unitStatusLabel[status];
            statusCell.append(statusBadge);
            qrCell.dataset.label = 'QR';
            qrText.textContent = unit.qr_image ? 'Available' : 'Unavailable';
            qrCell.append(qrText);
            actionCell.dataset.label = 'Action';
            viewButton.type = 'button';
            viewButton.className = 'btn-secondary inventory-units-modal__view-qr';
            viewButton.textContent = 'View QR';
            viewButton.disabled = !unit.qr_image;
            viewButton.addEventListener('click', () => openQrModal(unit));
            actionCell.append(viewButton);
            row.append(codeCell, statusCell, qrCell, actionCell);
            unitsBody.append(row);
        };
        const openUnitsModal = (button) => {
            let units = [];
            try {
                units = JSON.parse(button.dataset.assetUnits || '[]');
            } catch (error) {
                units = [];
            }

            unitsBody.replaceChildren();
            units.forEach(addUnitRow);
            unitsEmpty.hidden = units.length > 0;
            unitsModal.querySelector('[data-view-asset-units-title]').textContent = button.dataset.assetName || 'Asset';
            unitsModal.querySelector('[data-view-asset-units-name]').textContent = button.dataset.assetName || '';
            unitsModal.querySelector('[data-view-asset-units-total]').textContent = button.dataset.totalUnits || '0';
            unitsModal.querySelector('[data-view-asset-units-available]').textContent = button.dataset.availableUnits || '0';
            unitsModal.querySelector('[data-view-asset-units-deployed]').textContent = button.dataset.deployedUnits || '0';
            unitsModal.querySelector('[data-view-asset-units-maintenance]').textContent = button.dataset.maintenanceUnits || '0';
            unitsModal.hidden = false;
            document.body.classList.add('inventory-units-modal-open');
            window.setTimeout(() => unitsModal.querySelector('[data-view-asset-units-close]')?.focus(), 0);
        };

        document.querySelectorAll('[data-view-asset-units]').forEach((button) => {
            button.addEventListener('click', () => openUnitsModal(button));
        });
        unitsModal.querySelectorAll('[data-view-asset-units-close]').forEach((button) => button.addEventListener('click', closeUnitsModal));
        qrModal.querySelectorAll('[data-view-unit-qr-close]').forEach((button) => button.addEventListener('click', closeQrModal));
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }
            if (!qrModal.hidden) {
                closeQrModal();
            } else if (!unitsModal.hidden) {
                closeUnitsModal();
            }
        });
    }

    const stockInModal = document.querySelector('[data-asset-stock-in-modal]');
    const stockInForm = document.querySelector('[data-asset-stock-in-form]');
    const stockInQuantity = document.querySelector('[data-asset-stock-in-quantity]');
    const stockInError = document.querySelector('[data-asset-stock-in-error]');
    const stockInCurrent = document.querySelector('[data-asset-stock-in-current]');
    const stockInNewTotal = document.querySelector('[data-asset-stock-in-new-total]');
    const stockInInventoryId = document.querySelector('[data-asset-stock-in-inventory-id]');
    const stockInAssetName = document.querySelector('[data-asset-stock-in-name]');
    const stockInAssetNameDisplay = document.querySelector('[data-asset-stock-in-name-display]');
    const stockInCurrentHidden = document.querySelector('[data-asset-stock-in-current-hidden]');
    const stockInSubmit = document.querySelector('[data-asset-stock-in-submit]');

    if (stockInModal && stockInForm && stockInQuantity && stockInError && stockInCurrent && stockInNewTotal) {
        const closeStockInModal = () => {
            stockInModal.hidden = true;
            document.body.classList.remove('inventory-stock-in-modal-open');
        };

        const getCurrentUnits = () => Number.parseInt(stockInCurrent.textContent || '0', 10) || 0;
        const setStockInError = (message = '') => {
            stockInError.textContent = message;
            stockInQuantity.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
        };
        const updateNewTotal = (quantity = 0) => {
            stockInNewTotal.textContent = String(getCurrentUnits() + quantity);
        };
        const validateStockInQuantity = (showRequired = false) => {
            const value = stockInQuantity.value.trim();
            if (value === '') {
                setStockInError(showRequired ? 'Quantity In is required.' : '');
                updateNewTotal();
                return false;
            }
            if (!/^\d+$/.test(value) || Number.parseInt(value, 10) < 1) {
                setStockInError('Enter a whole number greater than zero.');
                updateNewTotal();
                return false;
            }

            setStockInError('');
            updateNewTotal(Number.parseInt(value, 10));
            return true;
        };
        const openStockInModal = (button) => {
            const currentUnits = Number.parseInt(button.dataset.currentUnits || '0', 10) || 0;
            stockInInventoryId.value = button.dataset.inventoryId || '';
            stockInAssetName.value = button.dataset.assetName || '';
            stockInAssetNameDisplay.value = button.dataset.assetName || '';
            stockInCurrent.textContent = String(currentUnits);
            stockInCurrentHidden.value = String(currentUnits);
            stockInNewTotal.textContent = String(currentUnits);
            stockInQuantity.value = '';
            setStockInError('');
            stockInModal.hidden = false;
            document.body.classList.add('inventory-stock-in-modal-open');
            window.setTimeout(() => stockInQuantity.focus(), 0);
        };

        document.querySelectorAll('[data-asset-stock-in-open]').forEach((button) => {
            button.addEventListener('click', () => openStockInModal(button));
        });
        stockInModal.querySelectorAll('[data-asset-stock-in-close]').forEach((button) => {
            button.addEventListener('click', closeStockInModal);
        });
        stockInQuantity.addEventListener('input', () => validateStockInQuantity(false));
        stockInQuantity.addEventListener('blur', () => validateStockInQuantity(true));
        stockInForm.addEventListener('submit', (event) => {
            if (!validateStockInQuantity(true) || stockInForm.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }

            stockInForm.dataset.submitting = 'true';
            stockInSubmit.disabled = true;
            stockInSubmit.textContent = 'Stocking In...';
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !stockInModal.hidden) {
                closeStockInModal();
            }
        });

        if (stockInModal.dataset.open === 'true') {
            stockInModal.hidden = false;
            document.body.classList.add('inventory-stock-in-modal-open');
            window.setTimeout(() => stockInQuantity.focus(), 0);
        }
    }

    const stockInToast = document.querySelector('[data-inventory-stock-in-toast]');
    if (stockInToast) {
        const closeToast = () => stockInToast.remove();
        stockInToast.querySelector('[data-inventory-stock-in-toast-close]')?.addEventListener('click', closeToast);
        window.setTimeout(closeToast, 4000);
    }

    const modal = document.querySelector('[data-add-asset-modal]');
    const openButton = document.querySelector('[data-add-asset-open]');
    const form = document.querySelector('[data-add-asset-form]');
    const submitButton = document.querySelector('[data-add-asset-submit]');
    const suggestButton = document.querySelector('[data-category-suggest]');
    const suggestFeedback = document.querySelector('[data-category-suggest-feedback]');
    const assetNameInput = document.querySelector('#add_asset_name');
    const categorySelect = document.querySelector('#add_asset_category');

    if (!modal || !openButton) {
        return;
    }

    const closeModal = () => {
        modal.hidden = true;
        document.body.classList.remove('inventory-add-asset-modal-open');
    };

    const openModal = () => {
        modal.hidden = false;
        document.body.classList.add('inventory-add-asset-modal-open');
        window.setTimeout(() => modal.querySelector('#add_asset_name')?.focus(), 0);
    };

    openButton.addEventListener('click', openModal);

    modal.querySelectorAll('[data-add-asset-close]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    if (form && submitButton) {
        form.addEventListener('submit', () => {
            if (form.checkValidity()) {
                submitButton.disabled = true;
                submitButton.textContent = 'Adding Asset...';
            }
        });
    }

    if (suggestButton && suggestFeedback && assetNameInput && categorySelect && form) {
        const defaultSuggestText = suggestButton.textContent;
        const showSuggestionFeedback = (message, type = '') => {
            suggestFeedback.textContent = message;
            suggestFeedback.classList.toggle('is-error', type === 'error');
            suggestFeedback.classList.toggle('is-success', type === 'success');
        };

        suggestButton.addEventListener('click', async () => {
            const assetName = assetNameInput.value.trim();
            if (assetName === '') {
                showSuggestionFeedback('Enter an asset name first.', 'error');
                assetNameInput.focus();
                return;
            }

            const csrfToken = form.querySelector('input[name="csrf_token"]')?.value || '';
            suggestButton.disabled = true;
            suggestButton.textContent = 'Searching...';
            showSuggestionFeedback('');

            try {
                const response = await fetch('/codesamplecaps/INVENTORY_CLERK/api/suggest_asset_category.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new URLSearchParams({
                        asset_name: assetName,
                        csrf_token: csrfToken,
                    }),
                });
                const result = await response.json();

                if (!response.ok || !result.success || !result.category) {
                    throw new Error('No category suggestion');
                }

                categorySelect.value = result.category;
                const selectedLabel = categorySelect.options[categorySelect.selectedIndex]?.text || result.category;
                showSuggestionFeedback(`Suggested from web: ${selectedLabel}`, 'success');
            } catch (error) {
                showSuggestionFeedback('Unable to suggest a category. Please select manually.', 'error');
            } finally {
                suggestButton.disabled = false;
                suggestButton.textContent = defaultSuggestText;
            }
        });
    }

    if (modal.dataset.open === 'true') {
        openModal();
    }
});
