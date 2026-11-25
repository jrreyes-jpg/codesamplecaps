document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-quotation-live-filter]');
    const results = document.querySelector('[data-quotation-results]');
    const searchInput = form?.querySelector('input[name="q"]');
    const statusInput = form?.querySelector('[data-quotation-status-value]');
    const statusMenu = form?.querySelector('[data-quotation-status-menu]');
    const statusToggle = form?.querySelector('[data-quotation-status-toggle]');
    const statusPanel = form?.querySelector('[data-quotation-status-panel]');
    const statusLabel = form?.querySelector('[data-quotation-status-label]');
    const feedback = form?.querySelector('[data-quotation-search-feedback]');

    if (!form || !results || !searchInput || !statusInput || !statusMenu || !statusToggle || !statusPanel || !statusLabel) {
        return;
    }

    let searchTimer = 0;
    let activeRequest = 0;
    let controller = null;

    const closeStatusMenu = function () {
        statusPanel.hidden = true;
        statusToggle.setAttribute('aria-expanded', 'false');
    };

    const setStatusMenu = function (status, label, counts) {
        statusInput.value = status;
        statusLabel.textContent = status === 'all' ? 'Status' : 'Status: ' + label;

        form.querySelectorAll('[data-quotation-status-option]').forEach(function (option) {
            const selected = option.dataset.statusValue === status;
            option.classList.toggle('is-selected', selected);
            option.setAttribute('aria-checked', selected ? 'true' : 'false');
        });

        if (!counts) return;
        form.querySelectorAll('[data-quotation-status-count]').forEach(function (count) {
            const value = count.dataset.quotationStatusCount;
            if (Object.prototype.hasOwnProperty.call(counts, value)) {
                count.textContent = String(counts[value]);
            }
        });
    };

    const updateAddress = function (page) {
        const url = new URL(window.location.href);
        url.searchParams.set('status', statusInput.value);
        url.searchParams.set('q', searchInput.value.trim());
        url.searchParams.set('page', String(page));
        url.searchParams.delete('ajax');
        url.searchParams.delete('id');
        window.history.replaceState({}, '', url.toString());
    };

    const loadResults = function (page) {
        const currentRequest = ++activeRequest;
        const safePage = Number.parseInt(page, 10) || 1;

        if (controller) controller.abort();
        controller = new AbortController();
        feedback.textContent = 'Searching...';
        results.setAttribute('aria-busy', 'true');

        const url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('ajax', '1');
        url.searchParams.set('q', searchInput.value.trim());
        url.searchParams.set('status', statusInput.value);
        url.searchParams.set('page', String(safePage));

        fetch(url.toString(), {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            signal: controller.signal,
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Unable to load quotations.');
                return response.json();
            })
            .then(function (data) {
                if (currentRequest !== activeRequest || !data?.ok) return;
                results.innerHTML = data.html || '';
                setStatusMenu(data.status || 'all', (data.status_label || 'Status').replace(/^Status:\s*/, ''), data.counts || null);
                updateAddress(safePage);
            })
            .catch(function (error) {
                if (error.name === 'AbortError' || currentRequest !== activeRequest) return;
                feedback.textContent = 'Unable to update list.';
            })
            .finally(function () {
                if (currentRequest !== activeRequest) return;
                results.removeAttribute('aria-busy');
                if (feedback.textContent === 'Searching...') feedback.textContent = '';
            });
    };

    searchInput.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
            loadResults(1);
        }, 300);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        window.clearTimeout(searchTimer);
        loadResults(1);
    });

    statusToggle.addEventListener('click', function () {
        const willOpen = statusPanel.hidden;
        statusPanel.hidden = !willOpen;
        statusToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    statusPanel.addEventListener('click', function (event) {
        const option = event.target.closest('[data-quotation-status-option]');
        if (!option) return;
        setStatusMenu(option.dataset.statusValue || 'all', option.dataset.statusLabel || 'All', null);
        closeStatusMenu();
        loadResults(1);
    });

    document.addEventListener('click', function (event) {
        if (!statusMenu.contains(event.target)) closeStatusMenu();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeStatusMenu();
    });

    results.addEventListener('click', function (event) {
        const pageLink = event.target.closest('.admin-quotation-pagination a');
        if (!pageLink) return;
        event.preventDefault();
        const page = new URL(pageLink.href, window.location.origin).searchParams.get('page');
        loadResults(page || 1);
    });
});
