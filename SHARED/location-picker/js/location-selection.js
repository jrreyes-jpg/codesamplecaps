/* Shared rule para sa Online at Walk-in location pickers. */
(function () {
    'use strict';

    const commit = function (input, value, selectionKey) {
        if (!input) return false;

        const key = selectionKey || 'selectedValue';
        const nextValue = String(value || '');
        const previousValue = String(input.dataset.locationCommittedValue || input.dataset[key] || '');
        const changed = previousValue !== nextValue;

        input.value = nextValue;
        if (nextValue === '') {
            delete input.dataset[key];
            delete input.dataset.locationCommittedValue;
        } else {
            input.dataset[key] = nextValue;
            input.dataset.locationCommittedValue = nextValue;
        }
        input.dataset.locationChanged = changed ? '1' : '0';
        return changed;
    };

    const clear = function (input, selectionKey) {
        if (!input) return;
        const key = selectionKey || 'selectedValue';
        input.value = '';
        delete input.dataset[key];
        delete input.dataset.locationCommittedValue;
        delete input.dataset.locationChanged;
    };

    const takeChange = function (input) {
        if (!input) return false;
        const changed = input.dataset.locationChanged === '1';
        delete input.dataset.locationChanged;
        return changed;
    };

    window.EdgeLocationSelection = { commit, clear, takeChange };
}());
