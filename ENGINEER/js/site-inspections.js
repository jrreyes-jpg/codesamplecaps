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

    const setFieldState = function (field, isInvalid) {
        if (!field) {
            return;
        }

        field.classList.toggle('is-invalid', isInvalid);
    };

    const validateCosting = function (form, requireFinal) {
        let hasMaterial = false;
        let hasLabor = false;
        let hasAnyRow = false;
        let total = 0;
        let firstInvalid = null;
        const findings = form.querySelector('textarea[name="engineer_findings"]');

        form.querySelectorAll('.is-invalid').forEach(function (field) {
            field.classList.remove('is-invalid');
        });
        form.querySelectorAll('.is-invalid-total').forEach(function (field) {
            field.classList.remove('is-invalid-total');
        });

        form.querySelectorAll('.costing-row').forEach(function (row) {
            const type = row.querySelector('select[name="item_type[]"]');
            const name = row.querySelector('input[name="item_name[]"]');
            const quantity = row.querySelector('input[name="quantity[]"]');
            const unitCost = row.querySelector('input[name="unit_cost[]"]');
            const nameValue = name?.value.trim() || '';
            const quantityValue = Number(quantity?.value || 0);
            const unitCostValue = Number(unitCost?.value || 0);
            const rowHasValue = nameValue !== '' || quantityValue > 0 || unitCostValue > 0;

            if (!rowHasValue) {
                return;
            }

            hasAnyRow = true;
            hasMaterial = hasMaterial || type?.value === 'material';
            hasLabor = hasLabor || type?.value === 'labor';
            total += quantityValue * unitCostValue;

            if (nameValue === '') {
                setFieldState(name, true);
                firstInvalid = firstInvalid || name;
            }

            if (quantityValue <= 0) {
                setFieldState(quantity, true);
                firstInvalid = firstInvalid || quantity;
            }

            if (requireFinal && unitCostValue <= 0) {
                setFieldState(unitCost, true);
                firstInvalid = firstInvalid || unitCost;
            }
        });

        if (!hasAnyRow) {
            firstInvalid = firstInvalid || form.querySelector('input[name="item_name[]"]');
            setFieldState(firstInvalid, true);
        }

        if (requireFinal && findings && findings.value.trim().length < 10) {
            setFieldState(findings, true);
            firstInvalid = firstInvalid || findings;
        }

        if (requireFinal && (!hasMaterial || !hasLabor || total <= 0)) {
            const totalBox = form.querySelector('[data-costing-total]');
            totalBox?.classList.add('is-invalid-total');
            firstInvalid = firstInvalid || totalBox;
        }

        if (firstInvalid) {
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (typeof firstInvalid.focus === 'function') {
                firstInvalid.focus();
            }
            return false;
        }

        return true;
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

        form.addEventListener('submit', function (event) {
            const action = event.submitter?.value || 'save_draft';
            const isFinalSubmit = action === 'submit_to_admin';

            if (!validateCosting(form, isFinalSubmit)) {
                event.preventDefault();
                window.alert(isFinalSubmit
                    ? 'Please complete findings, material, labor, and valid costs before submitting.'
                    : 'Please complete the costing row before saving draft.');
                return;
            }

            if (isFinalSubmit && !window.confirm('Submit this costing to Admin for review?')) {
                event.preventDefault();
            }
        });

        syncTotal(form);
    });

    const closeInspectionModal = function (modal) {
        if (!modal) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('inspection-modal-open');
    };

    document.querySelectorAll('[data-inspection-modal-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = document.getElementById(button.getAttribute('data-inspection-modal-open'));
            if (!modal) {
                return;
            }

            modal.hidden = false;
            document.body.classList.add('inspection-modal-open');
            modal.querySelector('[data-inspection-modal-close]')?.focus();
        });
    });

    document.querySelectorAll('.inspection-modal').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-inspection-modal-close]')) {
                closeInspectionModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.inspection-modal:not([hidden])').forEach(closeInspectionModal);
        }
    });
});
