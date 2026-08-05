// Costing rows para mabilis magdagdag ng materials, labor, at notes.
document.addEventListener('DOMContentLoaded', function () {
    const money = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    });

    const syncTotal = function (form) {
        let total = 0;
        form.querySelectorAll('.costing-row').forEach(function (row) {
            const quantity = Number(row.querySelector('input[name="quantity[]"]')?.value || 0);
            const unitCost = Number(row.querySelector('input[name="unit_cost[]"]')?.value || 0);
            total += quantity * unitCost;
        });

        const totalBox = form.querySelector('[data-costing-total]');
        if (totalBox) {
            totalBox.textContent = money.format(total);
        }
    };

    const bindRow = function (form, row) {
        row.querySelectorAll('[data-costing-number]').forEach(function (field) {
            field.addEventListener('input', function () {
                syncTotal(form);
            });
        });

        row.querySelector('[data-inventory-picker]')?.addEventListener('change', function (event) {
            const option = event.target.selectedOptions[0];
            const name = option?.getAttribute('data-name') || '';
            const nameField = row.querySelector('input[name="item_name[]"]');
            if (name && nameField && nameField.value.trim() === '') {
                nameField.value = name;
            }
        });

        row.querySelector('[data-remove-costing-row]')?.addEventListener('click', function () {
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
        });
    };

    document.querySelectorAll('[data-costing-form]').forEach(function (form) {
        form.querySelectorAll('.costing-row').forEach(function (row) {
            bindRow(form, row);
        });

        form.querySelector('[data-add-costing-row]')?.addEventListener('click', function () {
            const rowsBox = form.querySelector('[data-costing-rows]');
            const firstRow = rowsBox?.querySelector('.costing-row');
            if (!rowsBox || !firstRow) {
                return;
            }

            const row = firstRow.cloneNode(true);
            row.querySelectorAll('input').forEach(function (field) {
                field.value = field.name === 'quantity[]' ? '1' : '';
            });
            row.querySelectorAll('select').forEach(function (field) {
                field.selectedIndex = 0;
            });
            rowsBox.appendChild(row);
            bindRow(form, row);
            syncTotal(form);
        });

        form.querySelector('[data-confirm-submit-costing]')?.addEventListener('click', function (event) {
            if (!window.confirm('Submit this costing to Admin for review?')) {
                event.preventDefault();
            }
        });

        syncTotal(form);
    });
});
