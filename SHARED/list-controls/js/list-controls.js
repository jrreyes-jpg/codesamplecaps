(function () {
    'use strict';

    function start(root) {
        const search = root.querySelector('[data-shared-list-search]');
        const sort = root.querySelector('[data-shared-list-sort]');
        let timer = 0;

        const notify = function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                root.dispatchEvent(new CustomEvent('shared:list-controls-change', {
                    bubbles: true,
                    detail: {
                        query: String(search?.value || '').trim().toLowerCase(),
                        sort: String(sort?.value || 'newest'),
                    },
                }));
            }, 100);
        };

        search?.addEventListener('input', notify);
        sort?.addEventListener('change', notify);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-shared-list-controls]').forEach(start);
    });
}());
