// JS ng Audit Logs page. Search pattern gaya ng Projects: AJAX refresh, focus restore, at clear button.
function initAuditLogsUI() {
    const section = document.getElementById('audit-logs-section');
    const globalSearchForm = section?.querySelector('[data-audit-global-search]');
    const searchInput = globalSearchForm?.querySelector('input[name="q"]');
    const entityFilter = section?.querySelector('[data-audit-entity-filter]');
    const actionFilter = section?.querySelector('[data-audit-action-filter]');
    const dateFilter = section?.querySelector('[data-audit-date-filter]');
    const auditRows = Array.from(section?.querySelectorAll('[data-audit-row]') || []);
    const emptyRow = section?.querySelector('[data-audit-empty-row]');
    const folderCards = Array.from(section?.querySelectorAll('[data-audit-folder-card]') || []);
    const folderEmpty = section?.querySelector('[data-audit-folder-empty]');
    const savedFocusState = window.__auditSearchFocusState || null;
    let searchDebounceId = null;

    function syncHiddenSearchFields() {
        section?.querySelectorAll('input[type="hidden"][name="q"]').forEach((hiddenInput) => {
            hiddenInput.value = searchInput?.value || '';
        });
    }

    function searchVariants(query) {
        const cleanQuery = query.trim().toLowerCase();
        if (cleanQuery.length <= 3) {
            return [cleanQuery];
        }

        const altQuery = cleanQuery.endsWith('s')
            ? cleanQuery.slice(0, -1)
            : cleanQuery + 's';

        return [cleanQuery, altQuery];
    }

    function syncLocalSearch() {
        if (!searchInput) {
            return;
        }

        const query = searchInput.value.trim().toLowerCase();
        const queries = searchVariants(query);
        let visibleCount = 0;
        let visibleFolderCount = 0;

        syncHiddenSearchFields();

        auditRows.forEach((row) => {
            const haystack = row.getAttribute('data-audit-search') || '';
            const matches = query === '' || queries.some((item) => haystack.includes(item));
            row.hidden = !matches;
            if (matches) {
                visibleCount += 1;
            }
        });

        if (emptyRow) {
            emptyRow.hidden = visibleCount !== 0;
        }

        folderCards.forEach((card) => {
            const haystack = card.getAttribute('data-audit-folder-search') || '';
            const matches = query === '' || queries.some((item) => haystack.includes(item));
            card.hidden = !matches;
            if (matches) {
                visibleFolderCount += 1;
            }
        });

        if (folderEmpty) {
            folderEmpty.hidden = visibleFolderCount !== 0;
        }
    }

    function buildAuditUrl() {
        if (!globalSearchForm) {
            return '/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php';
        }

        const formData = new FormData(globalSearchForm);
        const params = new URLSearchParams();

        formData.forEach((value, key) => {
            const text = String(value || '').trim();
            if (text !== '') {
                params.set(key, text);
            }
        });

        const queryString = params.toString();
        return '/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php' + (queryString ? '?' + queryString : '');
    }

    function refreshAuditSection(url) {
        if (!section) {
            window.location.href = url;
            return;
        }

        if (searchInput) {
            window.__auditSearchFocusState = {
                value: searchInput.value,
                selectionStart: searchInput.selectionStart ?? searchInput.value.length,
                selectionEnd: searchInput.selectionEnd ?? searchInput.value.length,
            };
        }

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((response) => response.text())
            .then((html) => {
                const parser = new DOMParser();
                const nextDocument = parser.parseFromString(html, 'text/html');
                const nextSection = nextDocument.getElementById('audit-logs-section');

                if (!nextSection) {
                    window.location.href = url;
                    return;
                }

                section.replaceWith(nextSection);
                if (window.history?.replaceState) {
                    window.history.replaceState(null, '', url);
                }
                initAuditLogsUI();
            })
            .catch(() => {
                window.location.href = url;
            });
    }

    function triggerSearchRefresh(immediate) {
        if (!globalSearchForm) {
            return;
        }

        if (searchDebounceId) {
            window.clearTimeout(searchDebounceId);
        }

        const runSearch = () => refreshAuditSection(buildAuditUrl());

        if (immediate) {
            runSearch();
            return;
        }

        searchDebounceId = window.setTimeout(runSearch, 1200);
    }

    [entityFilter, actionFilter, dateFilter].forEach((filter) => {
        filter?.addEventListener('change', () => {
            filter.form?.requestSubmit();
        });
    });

    searchInput?.addEventListener('input', () => {
        syncLocalSearch();
        triggerSearchRefresh(false);
    });

    searchInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            triggerSearchRefresh(true);
        }
    });

    globalSearchForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        triggerSearchRefresh(true);
    });

    syncLocalSearch();

    if (savedFocusState && searchInput) {
        const restoredValue = typeof savedFocusState.value === 'string' ? savedFocusState.value : searchInput.value;
        searchInput.value = restoredValue;
        searchInput.focus();
        const cursorStart = typeof savedFocusState.selectionStart === 'number' ? savedFocusState.selectionStart : restoredValue.length;
        const cursorEnd = typeof savedFocusState.selectionEnd === 'number' ? savedFocusState.selectionEnd : restoredValue.length;
        searchInput.setSelectionRange(cursorStart, cursorEnd);
        window.__auditSearchFocusState = null;
    }

    const modal = section?.querySelector('[data-audit-modal]');
    if (!modal) {
        return;
    }

    const fields = {
        action: modal.querySelector('[data-audit-modal-action]'),
        time: modal.querySelector('[data-audit-modal-time]'),
        actor: modal.querySelector('[data-audit-modal-actor]'),
        role: modal.querySelector('[data-audit-modal-role]'),
        target: modal.querySelector('[data-audit-modal-target]'),
        ip: modal.querySelector('[data-audit-modal-ip]'),
        oldValue: modal.querySelector('[data-audit-modal-old]'),
        newValue: modal.querySelector('[data-audit-modal-new]'),
    };

    const closeModal = () => {
        modal.hidden = true;
        document.body.style.overflow = '';
    };

    section.querySelectorAll('[data-audit-open]').forEach((button) => {
        button.addEventListener('click', () => {
            fields.action.textContent = button.getAttribute('data-audit-action') || 'Activity';
            fields.time.textContent = button.getAttribute('data-audit-time') || 'Unknown';
            fields.actor.textContent = button.getAttribute('data-audit-actor') || 'System';
            fields.role.textContent = button.getAttribute('data-audit-role') || 'System';
            fields.target.textContent = button.getAttribute('data-audit-target') || 'Record';
            fields.ip.textContent = button.getAttribute('data-audit-ip') || 'N/A';
            fields.oldValue.textContent = button.getAttribute('data-audit-old') || 'No data';
            fields.newValue.textContent = button.getAttribute('data-audit-new') || 'No data';
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
        });
    });

    section.querySelector('[data-audit-close]')?.addEventListener('click', closeModal);

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    }, { once: true });
}

document.addEventListener('DOMContentLoaded', initAuditLogsUI);
