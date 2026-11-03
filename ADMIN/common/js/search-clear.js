// Ipakita ang X kapag may laman ang search.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-search-field]').forEach(function (field) {
        const input = field.querySelector('input[type="search"]');
        const clearButton = field.querySelector('[data-search-clear]');
        const form = field.closest('form');

        if (!input || !clearButton || !form) {
            return;
        }

        const updateClearButton = function () {
            clearButton.hidden = input.value.trim() === '';
        };

        input.addEventListener('input', updateClearButton);
        clearButton.addEventListener('click', function () {
            input.value = '';
            updateClearButton();
            form.requestSubmit();
        });

        updateClearButton();
    });
});
