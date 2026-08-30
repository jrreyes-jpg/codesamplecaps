document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-progress-width]').forEach(function (bar) {
        const value = Number.parseFloat(bar.dataset.progressWidth || '0');
        bar.style.width = Math.min(100, Math.max(0, value)) + '%';
    });

    const searchRoot = document.querySelector('[data-client-project-search]');
    const searchInput = document.getElementById('client-project-search');
    const searchClear = document.getElementById('client-project-search-clear');
    const searchDropdown = document.getElementById('client-project-search-dropdown');
    const searchHint = document.getElementById('client-project-search-hint');
    const searchCount = document.getElementById('client-project-search-count');
    const searchEmpty = document.getElementById('client-project-search-empty');
    const projectCards = Array.from(document.querySelectorAll('[data-client-project-card]'));

    if (!searchRoot || !searchInput) {
        return;
    }

    let searchTimer = null;

    const escapeHtml = function (value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const highlightMatch = function (text, query) {
        const value = String(text);
        const index = value.toLowerCase().indexOf(query.toLowerCase());

        if (index < 0 || query === '') {
            return escapeHtml(value);
        }

        return escapeHtml(value.slice(0, index))
            + '<mark>' + escapeHtml(value.slice(index, index + query.length)) + '</mark>'
            + escapeHtml(value.slice(index + query.length));
    };

    const updateClearButton = function () {
        if (searchClear) {
            searchClear.classList.toggle('is-visible', searchInput.value.trim() !== '');
        }
    };

    const showSuggestions = function (matches, query) {
        if (!searchDropdown) {
            return;
        }

        if (query === '') {
            searchDropdown.hidden = true;
            searchDropdown.innerHTML = '';
            searchInput.setAttribute('aria-expanded', 'false');
            return;
        }

        if (matches.length === 0) {
            searchDropdown.innerHTML = '<div class="client-project-search__empty">No matching project.</div>';
        } else {
            searchDropdown.innerHTML = matches.slice(0, 6).map(function (card) {
                const title = card.dataset.title || 'Project';
                const engineer = card.dataset.engineer || 'Not assigned';
                const status = card.dataset.status || 'pending';
                const timeline = card.dataset.timeline || 'Not set';

                return '<button type="button" class="client-project-search__result" data-project-target="'
                    + escapeHtml(card.id)
                    + '"><strong>'
                    + highlightMatch(title, query)
                    + '</strong><span>'
                    + escapeHtml(engineer + ' | ' + status + ' | ' + timeline)
                    + '</span></button>';
            }).join('');
        }

        searchDropdown.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
    };

    const applySearch = function () {
        const query = searchInput.value.trim().toLowerCase();
        const matches = [];

        projectCards.forEach(function (card) {
            const isMatch = query === '' || (card.dataset.search || '').includes(query);
            card.hidden = !isMatch;

            if (isMatch) {
                matches.push(card);
            }
        });

        if (searchCount) {
            searchCount.textContent = matches.length + ' project(s)';
        }

        if (searchEmpty) {
            searchEmpty.hidden = query === '' || matches.length > 0;
        }

        if (searchHint) {
            searchHint.textContent = query === ''
                ? 'Type a keyword to search.'
                : matches.length + ' matching project(s).';
        }

        showSuggestions(matches, query);
    };

    projectCards.forEach(function (card, index) {
        if (!card.id) {
            card.id = 'client-project-card-' + index;
        }
    });

    searchInput.addEventListener('input', function () {
        updateClearButton();

        if (searchTimer) {
            window.clearTimeout(searchTimer);
        }

        searchTimer = window.setTimeout(applySearch, 250);
    });

    searchClear?.addEventListener('click', function () {
        searchInput.value = '';
        updateClearButton();
        applySearch();
        searchInput.focus();
    });

    searchDropdown?.addEventListener('click', function (event) {
        const result = event.target.closest('[data-project-target]');

        if (!result) {
            return;
        }

        const card = document.getElementById(result.dataset.projectTarget || '');

        if (card) {
            card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            card.focus({ preventScroll: true });
        }

        searchDropdown.hidden = true;
        searchInput.setAttribute('aria-expanded', 'false');
    });

    document.addEventListener('click', function (event) {
        if (!searchRoot.contains(event.target) && searchDropdown) {
            searchDropdown.hidden = true;
            searchInput.setAttribute('aria-expanded', 'false');
        }
    });

    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && searchDropdown) {
            searchDropdown.hidden = true;
            searchInput.setAttribute('aria-expanded', 'false');
        }
    });

    updateClearButton();
});
