(function () {
    var escapeHtml = function (value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    var readQrGalleryMap = function () {
        var dataNode = document.getElementById('assetQrGalleryData');
        if (!dataNode) {
            return {};
        }

        try {
            return JSON.parse(dataNode.textContent || '{}');
        } catch (error) {
            return {};
        }
    };

    var computeSmartMaxStock = function (quantityValue, minStockValue, categoryDefaultMax) {
        var quantity = Math.max(0, Number(quantityValue || 0));
        var minStock = Math.max(0, Number(minStockValue || 0));
        var defaultMax = Number(categoryDefaultMax || 0);

        if (defaultMax > 0) {
            return Math.max(defaultMax, quantity, minStock);
        }

        var baseline = Math.max(quantity, minStock);
        var buffer = Math.max(2, Math.ceil(baseline * 0.5));
        return Math.max(1, baseline + buffer, minStock * 2);
    };

    var setupStockDefaults = function () {
        var categoryField = document.getElementById('asset_category');
        var minStockField = document.getElementById('min_stock');
        var maxStockField = document.getElementById('max_stock');
        var quantityField = document.getElementById('quantity');

        if (!categoryField || !minStockField || !maxStockField || !quantityField) {
            return;
        }

        var syncMaxStock = function () {
            if (maxStockField.dataset.userEdited === 'true') {
                return;
            }

            var selectedOption = categoryField.options[categoryField.selectedIndex];
            maxStockField.value = String(
                computeSmartMaxStock(
                    quantityField.value,
                    minStockField.value,
                    selectedOption?.getAttribute('data-default-max-stock')
                )
            );
        };

        var syncThresholdsFromCategory = function () {
            var selectedOption = categoryField.options[categoryField.selectedIndex];
            if (!selectedOption) {
                return;
            }

            var recommendedMinStock = selectedOption.getAttribute('data-default-min-stock');
            if (recommendedMinStock !== null) {
                minStockField.value = recommendedMinStock;
            }

            syncMaxStock();
        };

        categoryField.addEventListener('change', syncThresholdsFromCategory);
        minStockField.addEventListener('input', syncMaxStock);
        quantityField.addEventListener('input', syncMaxStock);
        maxStockField.addEventListener('input', function () {
            maxStockField.dataset.userEdited = 'true';
        });

        syncThresholdsFromCategory();
    };

    var setupAssetFilters = function () {
        var filterTabs = Array.from(document.querySelectorAll('.asset-filter-tab'));
        var assetRows = Array.from(document.querySelectorAll('.asset-table-row'));
        var filterEmptyRow = document.querySelector('.asset-filter-empty');
        var searchInput = document.querySelector('[data-asset-search]');
        var pagination = document.querySelector('[data-asset-pagination]');
        var activeFilter = 'all';
        var activePage = 1;
        var pageSize = 8;

        if (assetRows.length === 0) {
            return;
        }

        var applyAssetFilter = function () {
            var query = String(searchInput?.value || '').trim().toLowerCase();
            var matchingRows = assetRows.filter(function (row) {
                var rowStatus = row.getAttribute('data-asset-status') || 'available';
                var searchText = row.getAttribute('data-asset-search-text') || '';
                return (activeFilter === 'all' || rowStatus === activeFilter) && (query === '' || searchText.indexOf(query) !== -1);
            });
            var totalPages = Math.max(1, Math.ceil(matchingRows.length / pageSize));
            activePage = Math.min(activePage, totalPages);

            assetRows.forEach(function (row) {
                row.hidden = true;
            });
            matchingRows.slice((activePage - 1) * pageSize, activePage * pageSize).forEach(function (row) { row.hidden = false; });

            if (filterEmptyRow) {
                filterEmptyRow.hidden = matchingRows.length !== 0;
            }
            if (pagination) {
                pagination.innerHTML = '';
                if (totalPages > 1) {
                    for (var page = 1; page <= totalPages; page += 1) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = String(page);
                        button.className = 'asset-pagination__button' + (page === activePage ? ' is-active' : '');
                        button.dataset.page = String(page);
                        pagination.appendChild(button);
                    }
                }
            }
        };

        filterTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                filterTabs.forEach(function (button) {
                    button.classList.remove('is-active');
                });
                tab.classList.add('is-active');
                activeFilter = tab.getAttribute('data-filter') || 'all';
                activePage = 1;
                applyAssetFilter();
            });
        });

        searchInput?.addEventListener('input', function () {
            activePage = 1;
            applyAssetFilter();
        });
        pagination?.addEventListener('click', function (event) {
            var button = event.target.closest('[data-page]');
            if (!button) return;
            activePage = Number(button.dataset.page || 1);
            applyAssetFilter();
        });
        applyAssetFilter();
    };

    var setupAddAssetModal = function () {
        var modal = document.querySelector('[data-add-asset-modal]');
        var opener = document.querySelector('[data-add-asset-open]');
        if (!modal || !opener) return;
        var previousFocus = null;
        var closeModal = function () {
            modal.hidden = true;
            document.body.classList.remove('asset-modal-open');
            previousFocus?.focus();
        };
        opener.addEventListener('click', function () {
            previousFocus = document.activeElement;
            modal.hidden = false;
            document.body.classList.add('asset-modal-open');
            modal.querySelector('input, select, button')?.focus();
        });
        modal.querySelectorAll('[data-add-asset-close]').forEach(function (button) { button.addEventListener('click', closeModal); });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });
        modal.querySelector('.asset-create-modal__panel')?.addEventListener('click', function (event) { event.stopPropagation(); });
    };

    var setupAddAssetForm = function () {
        var form = document.getElementById('addAssetForm');
        if (!form) return;

        var nameField = form.querySelector('#asset_name');
        var minStockField = form.querySelector('#min_stock');
        var maxStockField = form.querySelector('#max_stock');
        var quantityField = form.querySelector('#quantity');
        var submitButton = form.querySelector('[data-add-asset-submit]');
        var submitting = false;

        var showError = function (field, message) {
            var group = field?.closest('[data-asset-field]');
            var error = group?.querySelector('.asset-field-error');
            field?.classList.toggle('is-invalid', message !== '');
            field?.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (error) error.textContent = message;
            return message === '';
        };

        var validateName = function () {
            var value = String(nameField?.value || '').trim().replace(/\s+/g, ' ');
            if (nameField) nameField.value = value;
            if (value === '') return showError(nameField, 'Asset name is required.');
            if (value.length > 255) return showError(nameField, 'Asset name is too long.');

            var hasLetter = false;
            try {
                hasLetter = new RegExp('\\p{L}', 'u').test(value);
            } catch (error) {
                hasLetter = /[A-Za-z]/.test(value);
            }
            return showError(nameField, hasLetter ? '' : 'Enter a valid asset name.');
        };

        var validateStocks = function () {
            var minValue = String(minStockField?.value || '').trim();
            var maxValue = String(maxStockField?.value || '').trim();
            var quantity = Number(quantityField?.value || 1);
            var minValid = minValue === '' || /^\d+$/.test(minValue);
            var maxValid = /^\d+$/.test(maxValue) && Number(maxValue) >= 1;

            showError(minStockField, minValid ? '' : 'Enter a whole number of 0 or more.');
            if (!maxValid) {
                return showError(maxStockField, 'Enter a whole number of 1 or more.');
            }
            if (minValue !== '' && Number(maxValue) < Number(minValue)) {
                return showError(maxStockField, 'Max stock must not be lower than min stock.');
            }
            if (Number(maxValue) < quantity) {
                return showError(maxStockField, 'Max stock must cover the starting quantity.');
            }
            return minValid && showError(maxStockField, '');
        };

        nameField?.addEventListener('blur', validateName);
        minStockField?.addEventListener('blur', validateStocks);
        maxStockField?.addEventListener('blur', validateStocks);
        [nameField, minStockField, maxStockField].forEach(function (field) {
            field?.addEventListener('input', function () {
                if (field.classList.contains('is-invalid')) {
                    field === nameField ? validateName() : validateStocks();
                }
            });
        });

        form.addEventListener('submit', function (event) {
            if (submitting) {
                event.preventDefault();
                return;
            }

            var nameValid = validateName();
            var stocksValid = validateStocks();
            if (!nameValid || !stocksValid) {
                event.preventDefault();
                form.querySelector('.is-invalid')?.focus();
                return;
            }

            submitting = true;
            form.setAttribute('aria-busy', 'true');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.classList.add('is-loading');
                submitButton.textContent = 'Creating Asset & QR...';
            }
        });
    };

    var setupAssetStockModal = function () {
        var modal = document.querySelector('[data-asset-stock-modal]');
        var form = modal?.querySelector('[data-asset-stock-form]');
        if (!modal || !form) return;

        var title = modal.querySelector('[data-asset-stock-title]');
        var assetName = modal.querySelector('[data-asset-stock-name]');
        var actionInput = modal.querySelector('[data-asset-stock-action]');
        var inventoryInput = modal.querySelector('[data-asset-stock-inventory-id]');
        var quantityInput = modal.querySelector('[data-asset-stock-quantity]');
        var currentUnits = modal.querySelector('[data-asset-stock-current]');
        var availableUnits = modal.querySelector('[data-asset-stock-available]');
        var note = modal.querySelector('[data-asset-stock-note]');
        var submitButton = modal.querySelector('[data-asset-stock-submit]');
        var closeControls = Array.from(modal.querySelectorAll('[data-asset-stock-close]'));
        var previousFocus = null;
        var submitting = false;

        var closeModal = function () {
            if (submitting) return;
            modal.hidden = true;
            document.body.classList.remove('asset-modal-open');
            previousFocus?.focus();
        };

        document.querySelectorAll('[data-asset-stock-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                var action = button.getAttribute('data-stock-action') || '';
                var isStockOut = action === 'stock_out_asset';
                previousFocus = button;
                actionInput.value = action;
                inventoryInput.value = button.getAttribute('data-inventory-id') || '';
                quantityInput.value = '1';
                quantityInput.max = isStockOut ? (button.getAttribute('data-available-units') || '1') : '';
                title.textContent = isStockOut ? 'Stock Out Asset' : 'Stock In Asset';
                assetName.textContent = button.getAttribute('data-asset-name') || 'Asset';
                currentUnits.textContent = button.getAttribute('data-current-units') || '0';
                availableUnits.textContent = button.getAttribute('data-available-units') || '0';
                note.textContent = isStockOut
                    ? 'Stock Out uses available units only. In-use, maintenance, and lost units stay protected.'
                    : 'New physical unit records and QR values will use the existing asset rules.';
                submitButton.textContent = isStockOut ? 'Confirm Stock Out' : 'Confirm Stock In';
                submitButton.classList.toggle('btn-danger', isStockOut);
                submitButton.classList.toggle('btn-primary', !isStockOut);
                modal.hidden = false;
                document.body.classList.add('asset-modal-open');
                quantityInput.focus();
            });
        });

        closeControls.forEach(function (control) { control.addEventListener('click', closeModal); });
        modal.querySelector('.asset-stock-modal__panel')?.addEventListener('click', function (event) { event.stopPropagation(); });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });
        form.addEventListener('submit', function (event) {
            if (submitting) {
                event.preventDefault();
                return;
            }
            submitting = true;
            modal.setAttribute('aria-busy', 'true');
            closeControls.forEach(function (control) { control.disabled = true; });
            submitButton.disabled = true;
            submitButton.textContent = actionInput.value === 'stock_out_asset' ? 'Saving Stock Out...' : 'Saving Stock In...';
        });
    };

    var setupAssetActions = function () {
        document.querySelectorAll('.asset-inline-form[data-confirm-message]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm-message') || 'Continue?')) {
                    event.preventDefault();
                }
            });
        });

        document.querySelectorAll('.asset-action-form').forEach(function (form) {
            var actionInput = form.querySelector('.asset-action-input');
            var actionSelect = form.querySelector('.asset-action-select');

            if (!actionInput || !actionSelect) {
                return;
            }

            form.addEventListener('submit', function (event) {
                actionInput.value = actionSelect.value;

                if (actionSelect.value === 'mark_asset_lost') {
                    var lostMessage = form.getAttribute('data-confirm-lost') || 'Mark the selected quantity as lost?';
                    if (!window.confirm(lostMessage)) {
                        event.preventDefault();
                    }
                }
            });
        });

        document.querySelectorAll('.asset-recovery-form').forEach(function (form) {
            var actionInput = form.querySelector('.asset-recovery-action-input');
            var actionSelect = form.querySelector('.asset-recovery-select');
            var quantityInput = form.querySelector('.asset-action-qty');

            if (!actionInput || !actionSelect || !quantityInput) {
                return;
            }

            var syncRecoveryQuantity = function () {
                var selectedOption = actionSelect.options[actionSelect.selectedIndex];
                var maxQuantity = Number(selectedOption?.getAttribute('data-max-qty') || '1');
                quantityInput.max = String(Math.max(1, maxQuantity));
                if (Number(quantityInput.value) > maxQuantity) {
                    quantityInput.value = String(Math.max(1, maxQuantity));
                }
            };

            actionSelect.addEventListener('change', syncRecoveryQuantity);
            syncRecoveryQuantity();

            form.addEventListener('submit', function () {
                actionInput.value = actionSelect.value;
            });
        });
    };

    var setupDistributionBars = function () {
        document.querySelectorAll('[data-segment-width]').forEach(function (segment) {
            var width = Number(segment.getAttribute('data-segment-width') || 0);
            segment.style.width = Math.max(0, Math.min(100, width)) + '%';
        });
    };

    var setupQrModal = function () {
        var qrGalleryMap = readQrGalleryMap();
        var modal = document.getElementById('qrModal');
        var modalContent = document.getElementById('qrModalContent');

        if (!modal || !modalContent) {
            return;
        }

        var closeAssetQrModal = function () {
            modal.hidden = true;
            modal.classList.remove('is-open');
        };

        var showAssetQrGallery = function (assetId) {
            var galleryItems = qrGalleryMap[String(assetId)] || qrGalleryMap[assetId] || [];

            if (galleryItems.length === 0) {
                modalContent.innerHTML = '<p class="asset-qr-feedback">QR preview is currently unavailable. Please try again later.</p>';
            } else {
                modalContent.innerHTML = galleryItems.map(function (item, index) {
                    var safeLabel = escapeHtml(item.label || 'Asset QR');
                    var safeScanValue = escapeHtml(item.scan_value || '');
                    var safeSrc = String(item.src || '').replace(/"/g, '&quot;');

                    return [
                        '<article class="asset-preview-card">',
                        '<span class="asset-preview-card__count">#' + (index + 1) + '</span>',
                        safeSrc
                            ? '<img class="asset-preview-image" src="' + safeSrc + '" alt="' + safeLabel + '">'
                            : '<p class="asset-qr-feedback">QR preview is currently unavailable. Please try again later.</p>',
                        '<strong>' + safeLabel + '</strong>',
                        '<small class="asset-qr-modal__scan">' + safeScanValue + '</small>',
                        '</article>',
                    ].join('');
                }).join('');
            }

            modal.hidden = false;
            modal.classList.add('is-open');
        };

        document.querySelectorAll('[data-asset-qr-preview]').forEach(function (button) {
            button.addEventListener('click', function () {
                showAssetQrGallery(button.getAttribute('data-asset-qr-preview'));
            });
        });

        document.querySelectorAll('[data-asset-qr-close]').forEach(function (button) {
            button.addEventListener('click', closeAssetQrModal);
        });

        modal.addEventListener('click', closeAssetQrModal);
        modal.querySelector('.asset-qr-modal__panel')?.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    };

    var setupPrintQrPage = function () {
        document.querySelectorAll('[data-print-asset-qrs]').forEach(function (button) {
            button.addEventListener('click', function () {
                window.print();
            });
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        setupStockDefaults();
        setupAddAssetForm();
        setupAddAssetModal();
        setupAssetStockModal();
        setupAssetFilters();
        setupAssetActions();
        setupDistributionBars();
        setupQrModal();
        setupPrintQrPage();
    });
})();
