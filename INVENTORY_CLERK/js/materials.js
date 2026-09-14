document.addEventListener('DOMContentLoaded', function () {
    const materialName = document.querySelector('[data-material-name]');
    const category = document.querySelector('[data-material-category]');
    const unit = document.querySelector('[data-material-unit]');
    const suggestion = document.querySelector('[data-material-suggestion]');
    const reorderLevel = document.querySelector('[data-reorder-level]');
    const form = document.querySelector('[data-material-form]');

    if (!materialName || !category || !unit) {
        return;
    }

    const suggestions = [
        { words: ['pako', 'bolt', 'screw', 'nut'], category: 'Fasteners & Hardware', unit: 'pcs' },
        { words: ['wire', 'cable'], category: 'Cable & Wire', unit: 'meter' },
        { words: ['rj45', 'connector', 'terminal'], category: 'Connectors & Terminals', unit: 'pcs' },
        { words: ['conduit'], category: 'Conduit & Raceway', unit: 'meter' },
    ];
    let categoryChangedByUser = false;
    let unitChangedByUser = false;

    const findSuggestion = function (value) {
        const name = value.trim().toLowerCase();
        return suggestions.find(function (item) {
            return item.words.some(function (word) {
                return name.includes(word);
            });
        });
    };

    const applySuggestion = function () {
        const match = findSuggestion(materialName.value);
        if (!match) {
            if (suggestion) {
                suggestion.textContent = '';
                suggestion.removeAttribute('title');
            }
            return;
        }

        if (!categoryChangedByUser) {
            category.value = match.category;
        }
        if (!unitChangedByUser) {
            unit.value = match.unit;
        }
        if (suggestion) {
            suggestion.textContent = 'Suggested';
            suggestion.title = match.category + ' / ' + match.unit;
        }
    };

    category.addEventListener('change', function () {
        categoryChangedByUser = true;
    });
    unit.addEventListener('change', function () {
        unitChangedByUser = true;
    });
    materialName.addEventListener('input', applySuggestion);

    const validateReorderLevel = function () {
        if (!reorderLevel) {
            return true;
        }

        const value = Number(reorderLevel.value);
        const isValid = reorderLevel.value.trim() !== '' && Number.isFinite(value) && value > 0;
        reorderLevel.setCustomValidity(isValid ? '' : 'Enter a reorder level greater than zero.');
        return isValid;
    };

    reorderLevel?.addEventListener('input', validateReorderLevel);
    reorderLevel?.addEventListener('keydown', function (event) {
        if (['e', 'E', '+', '-'].includes(event.key)) {
            event.preventDefault();
        }
    });
    form?.addEventListener('submit', function (event) {
        if (!validateReorderLevel()) {
            event.preventDefault();
            reorderLevel?.reportValidity();
        }
    });
});
