// Costing rows para mabilis magdagdag ng materials, labor, at notes.
document.addEventListener('DOMContentLoaded', function () {
    const activeModalKey = 'engineer.siteInspection.activeModal';
    const modalScrollKey = 'engineer.siteInspection.modalScroll';

    const money = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    });

    const syncTotal = function (form) {
        let total = 0;
        form.querySelectorAll('.costing-row').forEach(function (row) {
            const quantity = Math.max(0, Number(row.querySelector('input[name="quantity[]"]')?.value || 0));
            const unitCost = Math.max(0, Number(row.querySelector('input[name="unit_cost[]"]')?.value || 0));
            total += quantity * unitCost;
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
                inventory_id: row.querySelector('select[name="inventory_id[]"]')?.value || '',
                item_name: row.querySelector('input[name="item_name[]"]')?.value || '',
                quantity: row.querySelector('input[name="quantity[]"]')?.value || '',
                unit: row.querySelector('select[name="unit[]"]')?.value || 'unit',
                unit_cost: row.querySelector('input[name="unit_cost[]"]')?.value || '',
                notes: row.querySelector('input[name="notes[]"]')?.value || '',
            };
        });

        const payload = {
            engineer_findings: form.querySelector('textarea[name="engineer_findings"]')?.value || '',
            risk_notes: form.querySelector('textarea[name="risk_notes"]')?.value || '',
            client_requests: form.querySelector('textarea[name="client_requests"]')?.value || '',
            rows,
        };

        window.localStorage.setItem(storageKeyForForm(form), JSON.stringify(payload));
    };

    const fillRow = function (row, data) {
        row.querySelector('select[name="item_type[]"]').value = data.item_type || 'material';
        row.querySelector('select[name="inventory_id[]"]').value = data.inventory_id || '';
        row.querySelector('input[name="item_name[]"]').value = data.item_name || '';
        row.querySelector('input[name="quantity[]"]').value = data.quantity || '1';
        row.querySelector('select[name="unit[]"]').value = data.unit || 'unit';
        row.querySelector('input[name="unit_cost[]"]').value = data.unit_cost || '';
        row.querySelector('input[name="notes[]"]').value = data.notes || '';
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
        if (!errorBox) {
            return;
        }

        errorBox.textContent = '';
        errorBox.hidden = true;
    };

    const sanitizePositiveNumber = function (field) {
        if (!field) {
            return;
        }

        field.value = field.value.replace(/[^0-9.]/g, '');

        const firstDot = field.value.indexOf('.');
        if (firstDot !== -1) {
            field.value = field.value.slice(0, firstDot + 1) + field.value.slice(firstDot + 1).replace(/\./g, '');
        }

        if (field.value.startsWith('0')) {
            field.value = field.value.replace(/^0+/, '');
        }

        field.setCustomValidity('');
    };

    const validateCosting = function (form, requireFinal) {
        let hasMaterial = false;
        let hasLabor = false;
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
            const quantityValue = Number(quantity?.value || 0);
            const unitValue = unit?.value.trim() || '';
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
                setFieldError(name, 'Item / Labor name is required.');
                firstInvalid = firstInvalid || name;
            }

            if (quantityValue <= 0) {
                setFieldError(quantity, 'Qty must be greater than 0.');
                firstInvalid = firstInvalid || quantity;
            }

            if (unitCostValue < 0) {
                setFieldError(unitCost, 'Price cannot be negative.');
                firstInvalid = firstInvalid || unitCost;
            }

            if (unitValue === '') {
                setFieldError(unit, 'Unit is required.');
                firstInvalid = firstInvalid || unit;
            }

            if (requireFinal && unitCostValue <= 0) {
                setFieldError(unitCost, 'Price must be greater than 0 before submitting.');
                firstInvalid = firstInvalid || unitCost;
            }
        });

        if (!hasAnyRow) {
            firstInvalid = firstInvalid || form.querySelector('input[name="item_name[]"]');
            setFieldError(firstInvalid, 'Add at least one material or labor row.');
        }

        if (requireFinal && findings && findings.value.trim().length < 10) {
            setFieldError(findings, 'Engineer Findings is required before submitting.');
            firstInvalid = firstInvalid || findings;
        }

        if (requireFinal && (!hasMaterial || !hasLabor || total <= 0)) {
            const totalBox = form.querySelector('[data-costing-total]');
            totalBox?.classList.add('is-invalid-total');
            firstInvalid = firstInvalid || totalBox;
            if (!hasMaterial) {
                errorMessage = errorMessage || 'Add at least one Material row.';
            } else if (!hasLabor) {
                errorMessage = errorMessage || 'Add at least one Labor row.';
            } else {
                errorMessage = errorMessage || 'Total cost must be greater than 0.';
            }
        }

        if (firstInvalid) {
            if (errorMessage) {
                showCostingError(form, errorMessage);
            }
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
            field.addEventListener('keydown', function (event) {
                if (event.key === '-' || event.key === 'e' || event.key === 'E' || event.key === '+') {
                    event.preventDefault();
                }
            });

            field.addEventListener('input', function () {
                sanitizePositiveNumber(field);
                syncTotal(form);
                saveFormDraft(form);
            });

            field.addEventListener('paste', function () {
                window.setTimeout(function () {
                    sanitizePositiveNumber(field);
                    syncTotal(form);
                    saveFormDraft(form);
                }, 0);
            });
        });

        row.querySelector('[data-inventory-picker]')?.addEventListener('change', function (event) {
            const option = event.target.selectedOptions[0];
            const name = option?.getAttribute('data-name') || '';
            const nameField = row.querySelector('input[name="item_name[]"]');
            if (name && nameField && nameField.value.trim() === '') {
                nameField.value = name;
            }
            saveFormDraft(form);
        });

        row.querySelectorAll('input, select').forEach(function (field) {
            field.addEventListener('input', function () {
                saveFormDraft(form);
            });

            field.addEventListener('change', function () {
                saveFormDraft(form);
            });
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
            saveFormDraft(form);
        });
    };

    document.querySelectorAll('[data-costing-form]').forEach(function (form) {
        form.querySelectorAll('.costing-row').forEach(function (row) {
            bindRow(form, row);
        });

        restoreFormDraft(form);

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
            saveFormDraft(form);
        });

        form.querySelectorAll('textarea').forEach(function (field) {
            field.addEventListener('input', function () {
                saveFormDraft(form);
            });
        });

        form.querySelector('[data-clear-costing-form]')?.addEventListener('click', function () {
            if (!window.confirm('Clear all unsaved costing inputs?')) {
                return;
            }

            window.localStorage.removeItem(storageKeyForForm(form));
            form.querySelectorAll('textarea').forEach(function (field) {
                field.value = '';
            });
            form.querySelectorAll('.costing-row').forEach(function (row, index) {
                if (index > 0) {
                    row.remove();
                    return;
                }

                row.querySelectorAll('input').forEach(function (field) {
                    field.value = field.name === 'quantity[]' ? '1' : '';
                });
                row.querySelectorAll('select').forEach(function (field) {
                    field.selectedIndex = 0;
                });
            });
            clearCostingError(form);
            form.querySelectorAll('.costing-field-error, .is-invalid').forEach(function (field) {
                field.classList?.remove('is-invalid');
                if (field.classList?.contains('costing-field-error')) {
                    field.remove();
                }
            });
            syncTotal(form);
        });

        form.addEventListener('submit', function (event) {
            const action = event.submitter?.value || 'save_draft';
            const isFinalSubmit = action === 'submit_to_admin';

            if (!validateCosting(form, isFinalSubmit)) {
                event.preventDefault();
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
        window.localStorage.removeItem(activeModalKey);
    };

    document.querySelectorAll('[data-inspection-modal-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = document.getElementById(button.getAttribute('data-inspection-modal-open'));
            if (!modal) {
                return;
            }

            modal.hidden = false;
            document.body.classList.add('inspection-modal-open');
            window.localStorage.setItem(activeModalKey, modal.id);
            modal.querySelector('[data-inspection-modal-close]')?.focus();
        });
    });

    document.querySelectorAll('.inspection-modal').forEach(function (modal) {
        const panel = modal.querySelector('.inspection-modal__panel');
        panel?.addEventListener('scroll', function () {
            window.localStorage.setItem(modalScrollKey, String(panel.scrollTop));
        });

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

    const activeModalId = window.localStorage.getItem(activeModalKey);
    if (activeModalId) {
        const modal = document.getElementById(activeModalId);
        const panel = modal?.querySelector('.inspection-modal__panel');
        if (modal) {
            modal.hidden = false;
            document.body.classList.add('inspection-modal-open');
            window.setTimeout(function () {
                if (panel) {
                    panel.scrollTop = Number(window.localStorage.getItem(modalScrollKey) || 0);
                }
            }, 0);
        }
    }
});
