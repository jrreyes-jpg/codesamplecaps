(function () {
    'use strict';

    const boundFields = new WeakSet();

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
            return !option.disabled && option.value !== '' && name !== '' && normalize(name).includes(query);
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
    };

    document.addEventListener('click', function (event) {
        document.querySelectorAll('[data-material-suggestion-field]').forEach(function (field) {
            if (!field.contains(event.target)) close(field);
        });
    });

    window.EdgeMaterialSuggestions = { bind: bind, close: close };
    bind(document);
}());
