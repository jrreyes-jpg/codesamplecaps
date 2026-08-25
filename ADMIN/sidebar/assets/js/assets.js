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

        if (filterTabs.length === 0 || assetRows.length === 0) {
            return;
        }

        var applyAssetFilter = function (filterValue) {
            var visibleRows = 0;

            assetRows.forEach(function (row) {
                var rowStatus = row.getAttribute('data-asset-status') || 'available';
                var isVisible = filterValue === 'all' || rowStatus === filterValue;
                row.hidden = !isVisible;
                if (isVisible) {
                    visibleRows += 1;
                }
            });

            if (filterEmptyRow) {
                filterEmptyRow.hidden = visibleRows !== 0;
            }
        };

        filterTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                filterTabs.forEach(function (button) {
                    button.classList.remove('is-active');
                });
                tab.classList.add('is-active');
                applyAssetFilter(tab.getAttribute('data-filter') || 'all');
            });
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
                return;
            }

            modalContent.innerHTML = galleryItems.map(function (item, index) {
                var safeLabel = escapeHtml(item.label || 'Asset QR');
                var safeScanValue = escapeHtml(item.scan_value || '');
                var safeSrc = String(item.src || '').replace(/"/g, '&quot;');

                return [
                    '<article class="asset-preview-card">',
                    '<span class="asset-preview-card__count">#' + (index + 1) + '</span>',
                    '<img class="asset-preview-image" src="' + safeSrc + '" alt="' + safeLabel + '">',
                    '<strong>' + safeLabel + '</strong>',
                    '<small class="asset-qr-modal__scan">' + safeScanValue + '</small>',
                    '</article>',
                ].join('');
            }).join('');

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

    document.addEventListener('DOMContentLoaded', function () {
        setupStockDefaults();
        setupAssetFilters();
        setupAssetActions();
        setupDistributionBars();
        setupQrModal();
    });
})();
