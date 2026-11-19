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

    const syncNonStockDecision = function (row, config) {
        const settings = config || {};
        const source = row?.querySelector('[data-material-suggestion-source]');
        const nameInput = row?.querySelector('[data-material-suggestion-input]');
        const choiceInput = row?.querySelector('[data-non-stock-choice]');
        const decision = row?.querySelector('[data-manual-material-decision], [data-non-stock-match]');
        const manualValues = settings.manualValues || ['', 'manual'];
        const isManual = source && manualValues.includes(source.value) && nameInput && !nameInput.disabled && !nameInput.readOnly;
        const exactOption = isManual ? findExactOption(source, nameInput.value) : null;

        if (!exactOption) {
            if (choiceInput) choiceInput.value = '';
            if (decision) {
                decision.hidden = true;
                delete decision.dataset.materialId;
            }
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
        return {
            isManual: true,
            exactOption: exactOption,
            choice: choice,
            valid: choice === 'keep' || choice === 'stock',
        };
    };

    window.EdgeMaterialSuggestions = {
        bind: bind,
        close: close,
        normalize: normalize,
        findExactOption: findExactOption,
        syncNonStockDecision: syncNonStockDecision,
    };
    bind(document);
}());
