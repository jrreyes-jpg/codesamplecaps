(function () {
    'use strict';

    function start(root) {
        const search = root.querySelector('[data-shared-list-search]');
        const clear = root.querySelector('[data-shared-list-clear]');
        const sort = root.querySelector('[data-shared-list-sort]');
        const listSelector = root.dataset.sharedListTarget || '';
        const itemSelector = root.dataset.sharedListItem || '';
        const emptySelector = root.dataset.sharedListEmpty || '';
        let timer = 0;

        const updateClear = function () {
            if (clear) clear.hidden = String(search?.value || '').trim() === '';
        };

        const filterLoadedItems = function (query) {
            if (listSelector === '' || itemSelector === '') return;

            const list = document.querySelector(listSelector);
            if (!list) return;

            const items = Array.from(list.querySelectorAll(itemSelector));
            let visibleCount = 0;
            items.forEach(function (item) {
                const searchText = String(item.dataset.listSearch || item.textContent || '').toLowerCase();
                const shouldHide = query !== '' && !searchText.includes(query);
                item.hidden = shouldHide;
                if (!shouldHide) visibleCount++;
            });

            const empty = emptySelector !== '' ? document.querySelector(emptySelector) : null;
            if (empty) empty.hidden = visibleCount > 0 || items.length === 0;
        };

        const notify = function () {
            updateClear();
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                const query = String(search?.value || '').trim().toLowerCase();
                filterLoadedItems(query);
                root.dispatchEvent(new CustomEvent('shared:list-controls-change', {
                    bubbles: true,
                    detail: {
                        query: query,
                        sort: String(sort?.value || 'newest'),
                    },
                }));
            }, 250);
        };

        search?.addEventListener('input', notify);
        sort?.addEventListener('change', notify);

        clear?.addEventListener('click', function () {
            if (!search) return;
            search.value = '';
            updateClear();
            notify();
            search.focus();
        });

        if (root.matches('form[data-shared-live-only]')) {
            root.addEventListener('submit', function (event) {
                event.preventDefault();
                notify();
            });

            const searchParam = root.dataset.sharedSearchParam || '';
            if (searchParam !== '') {
                const cleanUrl = new URL(window.location.href);
                if (cleanUrl.searchParams.has(searchParam)) {
                    cleanUrl.searchParams.delete(searchParam);
                    window.history.replaceState({}, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
                }
            }
        }

        updateClear();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-shared-list-controls]').forEach(start);
    });
}());
