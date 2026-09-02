(function () {
    var tableBody = document.getElementById('quotationItemsBody');
    var quotationForm = document.querySelector('form[action="/codesamplecaps/controllers/QuotationController.php"]');
    var projectField = document.getElementById('project_id');
    var durationField = document.getElementById('estimated_duration_days');
    var catalogTabs = document.querySelectorAll('[data-catalog-tab]');
    var catalogPanels = document.querySelectorAll('[data-catalog-panel]');

    if (!tableBody || !quotationForm) {
        return;
    }

    function currency(value) {
        return 'PHP ' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function syncProjectDuration() {
        if (!projectField || !durationField) {
            return;
        }

        var selectedOption = projectField.options[projectField.selectedIndex];
        var durationDays = selectedOption ? String(selectedOption.getAttribute('data-duration-days') || '').trim() : '';

        if (durationDays !== '') {
            durationField.value = durationDays;
            durationField.setCustomValidity('');
        } else {
            durationField.value = '';
            durationField.setCustomValidity('This project has no saved duration yet. Update the project timeline first.');
        }
    }

    function validateRow(row) {
        var type = row.querySelector('.item-type').value;
        var quantityField = row.querySelector('.item-quantity');
        var hoursField = row.querySelector('.item-hours');
        var rateField = row.querySelector('.item-rate');
        var itemNameField = row.querySelector('input[name="item_name[]"]');
        var isManpower = type === 'manpower';
        var quantity = parseFloat(quantityField.value || '0');
        var hours = parseFloat(hoursField.value || '0');
        var rate = parseFloat(rateField.value || '0');

        itemNameField.classList.toggle('is-invalid', itemNameField.value.trim().length < 2);
        rateField.classList.toggle('is-invalid', rate <= 0);

        if (isManpower) {
            quantityField.value = quantityField.value === '' ? '0' : quantityField.value;
            quantityField.setCustomValidity('');
            hoursField.setCustomValidity(hours > 0 ? '' : 'Manpower rows require hours greater than zero.');
            hoursField.classList.toggle('is-invalid', !(hours > 0));
            quantityField.classList.remove('is-invalid');
        } else {
            quantityField.setCustomValidity(quantity > 0 ? '' : 'Quantity must be greater than zero.');
            hoursField.setCustomValidity('');
            quantityField.classList.toggle('is-invalid', !(quantity > 0));
            hoursField.classList.remove('is-invalid');
        }
    }

    function calculateRow(row) {
        var type = row.querySelector('.item-type').value;
        var quantity = parseFloat(row.querySelector('.item-quantity').value || '0');
        var hours = parseFloat(row.querySelector('.item-hours').value || '0');
        var rate = parseFloat(row.querySelector('.item-rate').value || '0');
        validateRow(row);
        var total = type === 'manpower' ? hours * rate : quantity * rate;
        row.querySelector('.item-total').value = total.toFixed(2);
        return { type: type, total: total };
    }

    function recalcTotals() {
        var totals = { material: 0, asset: 0, manpower: 0, other: 0 };
        tableBody.querySelectorAll('.quotation-item-row').forEach(function (row) {
            var rowData = calculateRow(row);
            totals[rowData.type] = (totals[rowData.type] || 0) + rowData.total;
        });
        var totalCost = totals.material + totals.asset + totals.manpower + totals.other;
        document.getElementById('materialsTotal').textContent = currency(totals.material);
        document.getElementById('assetsTotal').textContent = currency(totals.asset);
        document.getElementById('manpowerTotal').textContent = currency(totals.manpower);
        document.getElementById('otherTotal').textContent = currency(totals.other);
        document.getElementById('totalCost').textContent = currency(totalCost);
        document.getElementById('sellingPrice').textContent = currency(totalCost);
    }

    function buildRow(data) {
        var row = document.createElement('tr');
        var type = data.item_type || 'other';
        var defaultUnit = data.unit || (type === 'manpower' ? 'hour' : 'unit');
        var defaultQuantity = type === 'manpower' ? '0' : String(data.quantity || '1');
        var defaultHours = String(data.hours || '0');
        var defaultDescription = data.description || (type === 'manpower' ? 'Crew or work package labor entry' : '');
        row.className = 'quotation-item-row';
        row.innerHTML =
            '<td><select name="item_type[]" class="item-type"><option value="material">Material</option><option value="asset">Asset</option><option value="manpower">Manpower</option><option value="other">Other</option></select><input type="hidden" name="source_table[]" value=""><input type="hidden" name="source_id[]" value=""></td>' +
            '<td><input type="text" name="item_name[]" data-quote-validate="item-name" minlength="2" maxlength="160" required></td>' +
            '<td><input type="text" name="item_description[]" value="' + defaultDescription.replace(/"/g, '&quot;') + '"></td>' +
            '<td><input type="text" name="unit[]" value="' + defaultUnit.replace(/"/g, '&quot;') + '" required></td>' +
            '<td><input type="number" step="0.01" min="0.01" name="quantity[]" class="item-quantity" data-quote-validate="quantity" value="' + defaultQuantity + '"></td>' +
            '<td><input type="number" step="0.01" min="0" name="hours[]" class="item-hours" data-quote-validate="hours" value="' + defaultHours + '"></td>' +
            '<td><input type="number" step="0.01" min="0.01" name="rate[]" class="item-rate" data-quote-validate="rate" value="0"></td>' +
            '<td><input type="text" class="item-total" value="0.00" readonly></td>' +
            '<td><button type="button" class="btn-danger" data-remove-row>Remove</button></td>';
        row.querySelector('.item-type').value = type;
        row.querySelector('input[name="source_table[]"]').value = data.source_table || '';
        row.querySelector('input[name="source_id[]"]').value = data.source_id || '';
        row.querySelector('input[name="item_name[]"]').value = data.item_name || '';
        tableBody.appendChild(row);
        recalcTotals();
    }

    function activateCatalogTab(tabName) {
        catalogTabs.forEach(function (tab) {
            var isActive = tab.getAttribute('data-catalog-tab') === tabName;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        catalogPanels.forEach(function (panel) {
            var isActive = panel.getAttribute('data-catalog-panel') === tabName;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
    }

    tableBody.addEventListener('input', recalcTotals);
    tableBody.addEventListener('change', recalcTotals);
    tableBody.addEventListener('click', function (event) {
        if (event.target.matches('[data-remove-row]')) {
            event.preventDefault();
            event.target.closest('tr').remove();
            recalcTotals();
        }
    });

    document.querySelectorAll('[data-add-row]').forEach(function (button) {
        button.addEventListener('click', function () {
            buildRow({ item_type: button.getAttribute('data-add-row') });
        });
    });

    document.querySelectorAll('[data-catalog-item]').forEach(function (button) {
        button.addEventListener('click', function () {
            buildRow({
                item_type: button.getAttribute('data-item-type'),
                source_table: button.getAttribute('data-source-table'),
                source_id: button.getAttribute('data-source-id'),
                item_name: button.getAttribute('data-item-name'),
                unit: button.getAttribute('data-unit')
            });
        });
    });

    catalogTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            activateCatalogTab(tab.getAttribute('data-catalog-tab'));
        });
    });

    quotationForm.addEventListener('submit', function (event) {
        var hasRow = tableBody.querySelectorAll('.quotation-item-row').length > 0;

        syncProjectDuration();
        recalcTotals();

        if (!hasRow) {
            event.preventDefault();
            window.alert('Add at least one quotation item first.');
        }
    });

    if (projectField) {
        projectField.addEventListener('change', syncProjectDuration);
    }

    tableBody.querySelectorAll('.quotation-item-row').forEach(function (row) {
        validateRow(row);
    });

    activateCatalogTab('materials');
    syncProjectDuration();
    recalcTotals();
})();
