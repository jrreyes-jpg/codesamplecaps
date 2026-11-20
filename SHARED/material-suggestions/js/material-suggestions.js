(function () {
    'use strict';

    const boundFields = new WeakSet();
    const boundComboboxes = new WeakSet();

    const normalize = function (value) {
        return String(value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase();
    };

    const close = function (field) {
        const list = field?.querySelector('[data-material-suggestion-list]');
        if (!list) return;
        list.hidden = true;
        list.replaceChildren();
    };

    const render = function (field) {
        const scope = field.closest('[data-material-suggestion-scope]');
        const input = field.querySelector('[data-material-suggestion-input]');
        const list = field.querySelector('[data-material-suggestion-list]');
        const source = scope?.querySelector('[data-material-suggestion-source]');
        if (!input || !list || !source || input.disabled || input.readOnly) {
            close(field);
            return;
        }

        const query = normalize(input.value);
        if (query.length < 2) {
            close(field);
            return;
        }

        const matches = Array.from(source.options).filter(function (option) {
            const name = option.dataset.materialSuggestionName || '';
            return option.value !== '' && name !== '' && normalize(name).includes(query);
        }).slice(0, 5);

        list.replaceChildren();
        matches.forEach(function (option) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = option.dataset.materialSuggestionName;
            button.addEventListener('click', function () {
                field.dispatchEvent(new CustomEvent('edge:material-suggestion-selected', {
                    bubbles: true,
                    detail: {
                        value: option.value,
                        name: option.dataset.materialSuggestionName || '',
                        unit: option.dataset.materialSuggestionUnit || '',
                        option: option,
                    },
                }));
                close(field);
            });
            list.appendChild(button);
        });
        list.hidden = matches.length === 0;
    };

    const bind = function (scope) {
        const fields = [];
        if (scope?.matches?.('[data-material-suggestion-field]')) fields.push(scope);
        scope?.querySelectorAll?.('[data-material-suggestion-field]').forEach(function (field) {
            fields.push(field);
        });

        fields.forEach(function (field) {
            if (boundFields.has(field)) return;
            const input = field.querySelector('[data-material-suggestion-input]');
            if (!input) return;
            boundFields.add(field);
            input.addEventListener('input', function () {
                render(field);
            });
            input.addEventListener('focus', function () {
                render(field);
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') close(field);
            });
        });

        const comboboxes = [];
        if (scope?.matches?.('[data-material-combobox]')) comboboxes.push(scope);
        scope?.querySelectorAll?.('[data-material-combobox]').forEach(function (combobox) {
            comboboxes.push(combobox);
        });

        comboboxes.forEach(function (combobox) {
            if (boundComboboxes.has(combobox)) return;
            const row = combobox.closest('[data-material-suggestion-scope]');
            const source = row?.querySelector('[data-material-suggestion-source]');
            const input = combobox.querySelector('[data-material-combobox-input]');
            const toggle = combobox.querySelector('[data-material-combobox-toggle]');
            const list = combobox.querySelector('[data-material-combobox-list]');
            if (!source || !input || !toggle || !list) return;
            boundComboboxes.add(combobox);
            toggle.setAttribute('aria-label', 'Search materials');
            toggle.setAttribute('title', 'Search materials');

            const syncText = function () {
                const selected = source.selectedOptions[0];
                input.value = selected ? selected.textContent.trim() : '';
            };
            const closeList = function () {
                combobox.classList.remove('is-open');
            };
            const renderOptions = function (showAll) {
                if (input.disabled) return;
                const query = showAll ? '' : normalize(input.value);
                const matches = Array.from(source.options).filter(function (option) {
                    return !option.disabled && (query === '' || normalize(option.textContent).includes(query));
                });
                list.replaceChildren();
                if (matches.length === 0) {
                    const empty = document.createElement('span');
                    empty.className = 'material-combobox__empty';
                    empty.textContent = 'No matching material.';
                    list.appendChild(empty);
                } else {
                    matches.forEach(function (option) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = option.textContent.trim();
                        button.classList.toggle('is-selected', option.selected);
                        button.addEventListener('click', function () {
                            source.value = option.value;
                            syncText();
                            closeList();
                            source.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                        list.appendChild(button);
                    });
                }
                combobox.classList.add('is-open');
            };

            syncText();
            source.addEventListener('change', syncText);
            input.addEventListener('focus', function () {
                input.select();
                renderOptions(true);
            });
            input.addEventListener('input', function () {
                renderOptions(false);
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeList();
                    syncText();
                }
            });
            toggle.addEventListener('click', function () {
                if (combobox.classList.contains('is-open')) {
                    closeList();
                    return;
                }
                input.focus();
                renderOptions(true);
            });
        });
    };

    document.addEventListener('click', function (event) {
        document.querySelectorAll('[data-material-suggestion-field]').forEach(function (field) {
            if (!field.contains(event.target)) close(field);
        });
        document.querySelectorAll('[data-material-combobox].is-open').forEach(function (combobox) {
            if (combobox.contains(event.target)) return;
            combobox.classList.remove('is-open');
            const row = combobox.closest('[data-material-suggestion-scope]');
            const selected = row?.querySelector('[data-material-suggestion-source]')?.selectedOptions[0];
            const input = combobox.querySelector('[data-material-combobox-input]');
            if (input && selected) input.value = selected.textContent.trim();
        });
    });

    const findExactOption = function (source, value) {
        const identity = normalize(value);
        if (!source || identity === '') return null;
        return Array.from(source.options || []).find(function (option) {
            return option.value !== ''
                && option.value !== 'manual'
                && normalize(option.dataset.materialSuggestionName || option.dataset.materialName || '') === identity;
        }) || null;
    };

    const ensureSelectedDecision = function (decision) {
        if (!decision) return null;
        let selected = decision.querySelector('[data-non-stock-selected]');
        if (selected) return selected;

        selected = document.createElement('span');
        selected.className = 'shared-material-decision__selected';
        selected.setAttribute('data-non-stock-selected', '');
        selected.hidden = true;

        const text = document.createElement('span');
        text.textContent = 'Kept as Non-stock';
        selected.appendChild(text);

        const change = document.createElement('button');
        change.type = 'button';
        change.textContent = 'Change';
        change.setAttribute('data-change-non-stock', '');
        selected.appendChild(change);
        decision.appendChild(selected);
        return selected;
    };

    const setRowDecisionLock = function (row, locked) {
        row?.querySelectorAll('input[name="quantity[]"], select[name="unit[]"], input[name="unit_cost[]"], input[name="notes[]"], input[name="item_notes[]"]').forEach(function (field) {
            field.disabled = locked;
            field.setAttribute('aria-disabled', locked ? 'true' : 'false');
        });
        row?.classList.toggle('is-waiting-material-decision', locked);
    };

    const ensureMergePrompt = function (decision) {
        if (!decision) return null;
        let prompt = decision.querySelector('[data-stock-merge-prompt]');
        if (prompt) return prompt;

        prompt = document.createElement('span');
        prompt.className = 'shared-material-merge';
        prompt.setAttribute('data-stock-merge-prompt', '');
        prompt.hidden = true;
        prompt.innerHTML = '<label><span>Qty to add (Optional)</span><input type="text" inputmode="decimal" data-stock-merge-quantity placeholder="No quantity change"></label><span class="shared-material-merge__actions"><button type="button" data-stock-merge-apply>Apply to existing row</button><button type="button" data-stock-merge-back>Back</button></span><small data-stock-merge-error></small>';
        decision.appendChild(prompt);
        return prompt;
    };

    const showLinkedMaterialFeedback = function (row, message) {
        const reference = row?.querySelector('[data-material-field], [data-quotation-material-reference]');
        if (!reference) return;
        reference.classList.add('is-shared-material-linked');

        let feedback = reference.querySelector('[data-shared-material-linked-feedback]');
        if (!feedback) {
            feedback = document.createElement('small');
            feedback.className = 'shared-material-linked-feedback';
            feedback.setAttribute('data-shared-material-linked-feedback', '');
            reference.appendChild(feedback);
        }
        feedback.textContent = message;
        window.setTimeout(function () {
            reference.classList.remove('is-shared-material-linked');
        }, 1200);
        window.setTimeout(function () {
            feedback.remove();
        }, 2600);
    };

    const syncNonStockDecision = function (row, config) {
        const settings = config || {};
        const source = row?.querySelector('[data-material-suggestion-source]');
        const nameInput = row?.querySelector('[data-material-suggestion-input]');
        const choiceInput = row?.querySelector('[data-non-stock-choice]');
        const decision = row?.querySelector('[data-manual-material-decision], [data-non-stock-match]');
        const manualValues = settings.manualValues || ['', 'manual'];
        const isManual = source && manualValues.includes(source.value) && nameInput && !nameInput.disabled && !nameInput.readOnly;
        const exactOption = isManual ? findExactOption(source, nameInput.value) : null;
        const actions = decision?.querySelector('.shared-material-decision__actions');
        const selected = ensureSelectedDecision(decision);
        const mergePrompt = ensureMergePrompt(decision);

        if (!exactOption) {
            if (choiceInput) choiceInput.value = '';
            if (decision) {
                decision.hidden = true;
                delete decision.dataset.materialId;
            }
            if (actions) actions.hidden = false;
            if (selected) selected.hidden = true;
            if (mergePrompt) mergePrompt.hidden = true;
            setRowDecisionLock(row, false);
            return { isManual: Boolean(isManual), exactOption: null, choice: '', valid: true };
        }

        if (decision?.dataset.materialId && decision.dataset.materialId !== exactOption.value && choiceInput) {
            choiceInput.value = '';
        }
        if (decision) {
            decision.hidden = false;
            decision.dataset.materialId = exactOption.value;
        }
        const choice = choiceInput?.value || '';
        const isKept = choice === 'keep';
        if (actions) actions.hidden = isKept || choice === 'merge';
        if (selected) selected.hidden = !isKept;
        if (mergePrompt && choice !== 'merge') mergePrompt.hidden = true;
        setRowDecisionLock(row, choice === '' || choice === 'merge');
        return {
            isManual: true,
            exactOption: exactOption,
            choice: choice,
            valid: choice === 'keep' || choice === 'stock',
        };
    };

    const duplicateStockRow = function (row, materialId) {
        const scope = row?.closest('form') || document;
        return Array.from(scope.querySelectorAll('[data-material-suggestion-scope]')).find(function (candidate) {
            return candidate !== row
                && candidate.querySelector('[data-material-suggestion-source]')?.value === materialId;
        }) || null;
    };

    document.addEventListener('click', function (event) {
        const useStock = event.target.closest('[data-use-existing-material]');
        if (useStock) {
            const row = useStock.closest('[data-material-suggestion-scope]');
            const decision = useStock.closest('[data-manual-material-decision], [data-non-stock-match]');
            const materialId = decision?.dataset.materialId || '';
            const existingRow = duplicateStockRow(row, materialId);
            if (!existingRow) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            const choice = row.querySelector('[data-non-stock-choice]');
            const actions = decision.querySelector('.shared-material-decision__actions');
            const selected = decision.querySelector('[data-non-stock-selected]');
            const prompt = ensureMergePrompt(decision);
            if (choice) choice.value = 'merge';
            if (actions) actions.hidden = true;
            if (selected) selected.hidden = true;
            if (prompt) {
                prompt.hidden = false;
                prompt.dataset.existingMaterialId = materialId;
                const quantity = prompt.querySelector('[data-stock-merge-quantity]');
                const error = prompt.querySelector('[data-stock-merge-error]');
                if (quantity) quantity.value = '';
                if (error) error.textContent = '';
                quantity?.focus();
            }
            return;
        }

        const apply = event.target.closest('[data-stock-merge-apply]');
        if (apply) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const row = apply.closest('[data-material-suggestion-scope]');
            const prompt = apply.closest('[data-stock-merge-prompt]');
            const quantity = prompt?.querySelector('[data-stock-merge-quantity]');
            const error = prompt?.querySelector('[data-stock-merge-error]');
            const raw = quantity?.value.trim() || '';
            const amount = raw === '' ? 0 : Number(raw);
            if (raw !== '' && (!/^\d+(?:\.\d{1,2})?$/.test(raw) || !Number.isFinite(amount) || amount <= 0)) {
                if (error) error.textContent = 'Enter a valid quantity or leave it blank.';
                quantity?.focus();
                return;
            }

            const existingRow = duplicateStockRow(row, prompt?.dataset.existingMaterialId || '');
            if (!existingRow) return;
            const existingQuantity = existingRow.querySelector('input[name="quantity[]"]');
            if (amount > 0 && existingQuantity) {
                const oldAmount = Number(existingQuantity.value || 0);
                const total = Math.round((oldAmount + amount) * 100) / 100;
                existingQuantity.value = Number.isInteger(total) ? String(total) : total.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
            }
            row.remove();
            existingQuantity?.dispatchEvent(new Event('input', { bubbles: true }));
            existingRow.dispatchEvent(new CustomEvent('edge:material-row-merged', { bubbles: true }));
            showLinkedMaterialFeedback(existingRow, amount > 0
                ? 'Quantity added to existing material row'
                : 'Material already exists. Update its Qty if needed.');
            return;
        }

        const back = event.target.closest('[data-stock-merge-back]');
        if (back) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const row = back.closest('[data-material-suggestion-scope]');
            const choice = row?.querySelector('[data-non-stock-choice]');
            if (choice) choice.value = '';
            syncNonStockDecision(row);
        }
    }, true);

    document.addEventListener('click', function (event) {
        const change = event.target.closest('[data-change-non-stock]');
        if (change) {
            const row = change.closest('[data-material-suggestion-scope]');
            const choiceInput = row?.querySelector('[data-non-stock-choice]');
            if (choiceInput) choiceInput.value = '';
            syncNonStockDecision(row);
            row?.dispatchEvent(new CustomEvent('edge:non-stock-choice-reset', { bubbles: true }));
            return;
        }

        const useStock = event.target.closest('[data-use-existing-material]');
        if (!useStock) return;
        const row = useStock.closest('[data-material-suggestion-scope]');
        window.setTimeout(function () {
            if (!row?.isConnected) return;
            showLinkedMaterialFeedback(row, 'Using existing stock material');
        }, 0);
    });

    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-material-suggestion-source]')) return;
        const row = event.target.closest('[data-material-suggestion-scope]');
        row?.querySelector('[data-shared-material-linked-feedback]')?.remove();
    });

    window.EdgeMaterialSuggestions = {
        bind: bind,
        close: close,
        normalize: normalize,
        findExactOption: findExactOption,
        syncNonStockDecision: syncNonStockDecision,
    };
    bind(document);
}());
