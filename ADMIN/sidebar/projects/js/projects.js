function initProjectSearchUI() {
    const section = document.getElementById('projects-list-section');
    const searchForm = document.getElementById('project-search-form');
    const searchInput = document.getElementById('project-search');
    const searchClear = document.getElementById('project-search-clear');
    const searchDropdown = document.getElementById('project-search-dropdown');
    const projectCards = Array.from(document.querySelectorAll('[data-project-card]'));
    const sortSelect = document.getElementById('project-sort-select');
    const statusInput = searchForm?.querySelector('input[name="status"]');
    const viewInput = searchForm?.querySelector('input[name="view"]');
    let activeSuggestionIndex = -1;
    let searchDebounceId = null;
    const savedFocusState = window.__projectSearchFocusState || null;

    function isCardSearchVisible(card) {
        const archiveSection = card.closest('.archive-section');
        return !archiveSection || archiveSection.classList.contains('is-active');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function highlightMatch(text, query) {
        const lowerText = text.toLowerCase();
        const matchIndex = lowerText.indexOf(query);

        if (matchIndex === -1 || query === '') {
            return escapeHtml(text);
        }

        const before = escapeHtml(text.slice(0, matchIndex));
        const matched = escapeHtml(text.slice(matchIndex, matchIndex + query.length));
        const after = escapeHtml(text.slice(matchIndex + query.length));

        return before + '<mark>' + matched + '</mark>' + after;
    }

    function getSuggestionLinks() {
        return Array.from(searchDropdown?.querySelectorAll('.project-search-result') || []);
    }

    function syncSuggestionFocus() {
        const links = getSuggestionLinks();

        links.forEach(function (link, index) {
            link.classList.toggle('is-active', index === activeSuggestionIndex);
        });
    }

    function calculateSearchScore(card, query) {
        const title = (card.getAttribute('data-title') || '').toLowerCase();
        const client = (card.getAttribute('data-client') || '').toLowerCase();
        const engineer = (card.getAttribute('data-engineer') || '').toLowerCase();
        const status = (card.getAttribute('data-status') || '').toLowerCase();
        let score = 0;

        // Title match gets highest priority
        if (title.startsWith(query)) score += 100;
        else if (title.includes(query)) score += 80;

        // Status match
        if (status.startsWith(query)) score += 50;
        else if (status.includes(query)) score += 30;

        // Client match
        if (client.startsWith(query)) score += 40;
        else if (client.includes(query)) score += 20;

        // Engineer match
        if (engineer.startsWith(query)) score += 40;
        else if (engineer.includes(query)) score += 15;

        return score;
    }

    function updateSearchDropdown() {
        if (!searchInput || !searchDropdown) {
            return;
        }

        const query = searchInput.value.trim().toLowerCase();

        if (query.length < 1) {
            searchDropdown.hidden = true;
            searchDropdown.innerHTML = '';
            activeSuggestionIndex = -1;
            return;
        }

        const matches = projectCards
            .map(function (card) {
                return {
                    card: card,
                    score: calculateSearchScore(card, query)
                };
            })
            .filter(function (item) {
                return item.score > 0 && isCardSearchVisible(item.card);
            })
            .sort(function (a, b) {
                return b.score - a.score;
            })
            .slice(0, 8)
            .map(function (item) {
                return item.card;
            });

        if (matches.length === 0) {
            searchDropdown.innerHTML = '<div class="project-search-empty">No matching projects yet.</div>';
            searchDropdown.hidden = false;
            activeSuggestionIndex = -1;
            return;
        }

        searchDropdown.innerHTML = matches.map(function (card) {
            const title = card.getAttribute('data-title') || 'Project';
            const status = card.getAttribute('data-status') || '';
            const link = card.getAttribute('data-link') || '#';
            const client = card.getAttribute('data-client') || 'N/A';
            const engineer = card.getAttribute('data-engineer') || 'Not assigned';
            const statusBadgeClass = 'search-status-badge status-' + escapeHtml(status);
            const statusLabel = status.charAt(0).toUpperCase() + status.slice(1);

            return '<a class="project-search-result" href="' + link + '">' +
                '<div class="search-result-header">' +
                '<strong>' + highlightMatch(title, query) + '</strong>' +
                '<span class="' + statusBadgeClass + '">' + escapeHtml(statusLabel) + '</span>' +
                '</div>' +
                '<div class="search-result-meta">' +
                '<small>👤 ' + escapeHtml(client) + ' · 👨‍💼 ' + escapeHtml(engineer) + '</small>' +
                '</div>' +
                '</a>';
        }).join('');
        searchDropdown.hidden = false;
        activeSuggestionIndex = -1;
        syncSuggestionFocus();
    }

    function updateClearVisibility() {
        if (!searchInput || !searchClear) {
            return;
        }

        searchClear.classList.toggle('is-visible', searchInput.value.trim() !== '');
    }

    function refreshProjectsSection(url) {
        if (!section) {
            window.location.href = url;
            return;
        }

        if (searchInput) {
            window.__projectSearchFocusState = {
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
            .then(function (response) {
                return response.text();
            })
            .then(function (html) {
                const parser = new DOMParser();
                const documentFromResponse = parser.parseFromString(html, 'text/html');
                const nextSection = documentFromResponse.getElementById('projects-list-section');

                if (!nextSection) {
                    window.location.href = url;
                    return;
                }

                section.replaceWith(nextSection);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', url);
                }
                initProjectSearchUI();
                initArchiveTabs();
            })
            .catch(function () {
                window.location.href = url;
            });
    }

    function buildProjectsUrl() {
        const params = new URLSearchParams();
        const queryValue = searchInput ? searchInput.value.trim() : '';
        const statusValue = statusInput ? statusInput.value.trim() : '';

        if (queryValue !== '') {
            params.set('q', queryValue);
        }

        if (statusValue !== '') {
            params.set('status', statusValue);
        }

        if (viewInput && String(viewInput.value || '').trim() !== '') {
            params.set('view', String(viewInput.value || '').trim());
        }

        const queryString = params.toString();
        return '/codesamplecaps/ADMIN/sidebar/projects/php/projects.php' + (queryString ? '?' + queryString : '');
    }

    function triggerSearchRefresh(immediate) {
        if (!searchInput) {
            return;
        }

        if (searchDebounceId) {
            window.clearTimeout(searchDebounceId);
        }

        const runSearch = function () {
            refreshProjectsSection(buildProjectsUrl());
        };

        if (immediate) {
            runSearch();
            return;
        }

        searchDebounceId = window.setTimeout(runSearch, 3000);
    }

    function getDateSortValue(card, attributeName) {
        const rawValue = card.getAttribute(attributeName) || '';
        const timestamp = Date.parse(rawValue);
        return Number.isNaN(timestamp) ? 0 : timestamp;
    }

    function sortVisibleProjectCards() {
        if (!sortSelect) {
            return;
        }

        const sortMode = sortSelect.value || 'updated';
        const grids = Array.from(section?.querySelectorAll('.projects-grid') || []);

        grids.forEach(function (grid) {
            const cards = Array.from(grid.querySelectorAll('[data-project-card]'));

            cards.sort(function (a, b) {
                if (sortMode === 'title') {
                    return (a.getAttribute('data-title') || '').localeCompare(b.getAttribute('data-title') || '');
                }

                if (sortMode === 'start') {
                    return getDateSortValue(b, 'data-start') - getDateSortValue(a, 'data-start');
                }

                if (sortMode === 'progress') {
                    return Number(b.getAttribute('data-progress') || 0) - Number(a.getAttribute('data-progress') || 0);
                }

                return getDateSortValue(b, 'data-updated') - getDateSortValue(a, 'data-updated');
            });

            cards.forEach(function (card) {
                grid.appendChild(card);
            });
        });
    }

    function syncProjectProgressBars() {
        const fills = Array.from(section?.querySelectorAll('[data-progress-width]') || []);

        fills.forEach(function (fill) {
            const percent = Math.max(0, Math.min(100, Number(fill.getAttribute('data-progress-width') || 0)));
            fill.style.width = percent + '%';
        });
    }

    sortSelect?.addEventListener('change', sortVisibleProjectCards);

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            updateClearVisibility();
            updateSearchDropdown();
            triggerSearchRefresh(false);
        });

        searchInput.addEventListener('focus', updateSearchDropdown);
        searchInput.addEventListener('keydown', function (event) {
            const links = getSuggestionLinks();

            if (searchDropdown.hidden || links.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeSuggestionIndex = (activeSuggestionIndex + 1) % links.length;
                syncSuggestionFocus();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeSuggestionIndex = activeSuggestionIndex <= 0 ? links.length - 1 : activeSuggestionIndex - 1;
                syncSuggestionFocus();
                return;
            }

            if (event.key === 'Enter' && activeSuggestionIndex >= 0 && links[activeSuggestionIndex]) {
                event.preventDefault();
                window.location.href = links[activeSuggestionIndex].href;
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                triggerSearchRefresh(true);
                return;
            }

            if (event.key === 'Escape') {
                searchDropdown.hidden = true;
                activeSuggestionIndex = -1;
            }
        });
    }

    if (searchForm) {
        searchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            triggerSearchRefresh(true);
        });
    }

    if (searchClear) {
        searchClear.addEventListener('click', function (event) {
            event.preventDefault();

            if (!searchInput) {
                return;
            }

            searchInput.value = '';
            updateClearVisibility();
            if (searchDropdown) {
                searchDropdown.hidden = true;
                searchDropdown.innerHTML = '';
            }

            if (searchDebounceId) {
                window.clearTimeout(searchDebounceId);
            }

            const resetUrl = section?.getAttribute('data-reset-url') || searchClear.getAttribute('href') || '/codesamplecaps/ADMIN/sidebar/projects/php/projects.php';
            refreshProjectsSection(resetUrl);
        });
    }

    if (!window.__projectSearchOutsideBound) {
        document.addEventListener('click', function (event) {
            const currentDropdown = document.getElementById('project-search-dropdown');
            const isInsideSearch = event.target.closest('.project-search-shell');

            if (!isInsideSearch && currentDropdown) {
                currentDropdown.hidden = true;
            }
        });

        window.__projectSearchOutsideBound = true;
    }

    updateClearVisibility();
    updateSearchDropdown();
    syncProjectProgressBars();
    sortVisibleProjectCards();
    if (savedFocusState && searchInput) {
        const restoredValue = typeof savedFocusState.value === 'string' ? savedFocusState.value : searchInput.value;
        searchInput.value = restoredValue;
        searchInput.focus();
        const cursorStart = typeof savedFocusState.selectionStart === 'number' ? savedFocusState.selectionStart : restoredValue.length;
        const cursorEnd = typeof savedFocusState.selectionEnd === 'number' ? savedFocusState.selectionEnd : restoredValue.length;
        searchInput.setSelectionRange(cursorStart, cursorEnd);
        window.__projectSearchFocusState = null;
    }
}

function initArchiveTabs() {
    const tabButtons = Array.from(document.querySelectorAll('.archive-tab-button'));
    const archiveSections = Array.from(document.querySelectorAll('.archive-section'));

    if (tabButtons.length === 0 || archiveSections.length === 0) {
        return;
    }

    function activateArchiveTab(tabName) {
        tabButtons.forEach(function (button) {
            const isActive = button.dataset.archiveTab === tabName;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        archiveSections.forEach(function (section) {
            const isActive = section.dataset.archiveSection === tabName;
            section.classList.toggle('is-active', isActive);
        });
    }

    tabButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const target = button.dataset.archiveTab;
            if (!target) {
                return;
            }
            activateArchiveTab(target);
        });
    });
}

function initCreateProjectForm() {
    const createProjectForm = document.getElementById('create-project-form');
    const focusFieldName = createProjectForm?.dataset.focusField || '';

    if (!createProjectForm) {
        return;
    }

    createProjectForm.addEventListener('submit', function (event) {
        const firstInvalidField = createProjectForm.querySelector(':invalid');

        if (firstInvalidField && typeof firstInvalidField.focus === 'function') {
            event.preventDefault();
            firstInvalidField.focus();
            firstInvalidField.classList.add('is-invalid-live');
            if (typeof firstInvalidField.scrollIntoView === 'function') {
                firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            createProjectForm.reportValidity();
        }
    });

    const projectStartDateField = createProjectForm.elements.namedItem('project_start_date');
const estimatedCompletionDateField = createProjectForm.elements.namedItem('estimated_completion_date');
const projectDurationField = createProjectForm.elements.namedItem('estimated_duration_days');
const poDateField = createProjectForm.elements.namedItem('po_date');
const statusField = createProjectForm.elements.namedItem('status');
const startDateHelp = document.getElementById('start-date-help');
const initialStatusHelp = document.getElementById('initial-status-help');
    const requiredWhenActiveFields = [
        createProjectForm.elements.namedItem('contact_person'),
        createProjectForm.elements.namedItem('contact_number'),
        createProjectForm.elements.namedItem('project_site'),
        createProjectForm.elements.namedItem('project_address'),
    ].filter(Boolean);

    function syncCreateProjectRequiredFields() {
    const status = String(statusField?.value || '');
    const isDraft = status === 'draft';
    const isOngoing = status === 'ongoing';

    requiredWhenActiveFields.forEach(function (field) {
        field.required = !isDraft;
    });

    if (startDateHelp) {
        if (isDraft) {
            startDateHelp.textContent = 'Optional while draft. Add the purchase order date once it is available.';
        } else if (isOngoing) {
            startDateHelp.textContent = 'Use the purchase order date for tracking. Completion date will be recorded automatically later.';
        } else {
            startDateHelp.textContent = 'Use the purchase order date for approved work. You can still update it before completion.';
        }
    }

    if (initialStatusHelp) {
        initialStatusHelp.textContent = isDraft
            ? 'Draft is safe for incomplete or mistaken entries. Finalize it later before adding tasks.'
            : isOngoing
                ? 'Ongoing marks work as active, while the project completion date will only appear once the project is completed.'
                : 'Pending is the safe default for approved projects that have not started yet.';
    }
}

    function calculateDurationDays(startDate, endDate) {
        if (!startDate || !endDate) {
            return '';
        }

        const start = new Date(startDate + 'T00:00:00');
        const end = new Date(endDate + 'T00:00:00');
        const diff = end.getTime() - start.getTime();

        if (Number.isNaN(diff) || diff < 0) {
            return '';
        }

        return String(Math.floor(diff / 86400000) + 1);
    }

    function calculateEstimatedCompletionDate(startDate, durationDays) {
        if (!startDate || !durationDays) {
            return '';
        }

        const normalizedDuration = Number(durationDays);
        if (!Number.isFinite(normalizedDuration) || normalizedDuration <= 0) {
            return '';
        }

        const start = new Date(startDate + 'T00:00:00');
        if (Number.isNaN(start.getTime())) {
            return '';
        }

        start.setDate(start.getDate() + normalizedDuration - 1);
        const year = start.getFullYear();
        const month = String(start.getMonth() + 1).padStart(2, '0');
        const day = String(start.getDate()).padStart(2, '0');

        return year + '-' + month + '-' + day;
    }

    function syncProjectTimelineValidation(source) {
        if (!projectStartDateField || !estimatedCompletionDateField) {
            return;
        }

        const poDate = poDateField ? String(poDateField.value || '') : '';
        const projectStartDate = String(projectStartDateField.value || '');
        if (poDateField) {
            projectStartDateField.min = poDate;
        }
        estimatedCompletionDateField.min = projectStartDate;

        if (source === 'duration' && projectDurationField) {
            const nextCompletionDate = calculateEstimatedCompletionDate(projectStartDate, String(projectDurationField.value || ''));
            if (nextCompletionDate !== '') {
                estimatedCompletionDateField.value = nextCompletionDate;
            }
        }

        if (source === 'completion' && projectDurationField) {
            projectDurationField.value = calculateDurationDays(projectStartDate, String(estimatedCompletionDateField.value || ''));
        }

        if (poDate !== '' && projectStartDate !== '' && projectStartDate < poDate) {
            projectStartDateField.setCustomValidity('Project Start Date must be the same as or later than P.O Date.');
        } else {
            projectStartDateField.setCustomValidity('');
        }

        if (projectStartDate !== '' && String(estimatedCompletionDateField.value || '') !== '' && estimatedCompletionDateField.value < projectStartDate) {
            estimatedCompletionDateField.setCustomValidity('Estimated Completion Date must be the same as or later than Project Start Date.');
        } else {
            estimatedCompletionDateField.setCustomValidity('');
        }

        if (projectDurationField && source !== 'completion') {
            projectDurationField.value = calculateDurationDays(projectStartDate, String(estimatedCompletionDateField.value || ''));
        }
    }

    if (projectStartDateField && estimatedCompletionDateField) {
        if (poDateField) {
            poDateField.addEventListener('input', function () {
                syncProjectTimelineValidation('po');
            });
        }
        projectStartDateField.addEventListener('input', function () {
            syncProjectTimelineValidation('start');
        });
        estimatedCompletionDateField.addEventListener('input', function () {
            syncProjectTimelineValidation('completion');
        });
        if (projectDurationField) {
            projectDurationField.addEventListener('input', function () {
                syncProjectTimelineValidation('duration');
            });
        }
        syncProjectTimelineValidation('init');
    }

    statusField?.addEventListener('change', syncCreateProjectRequiredFields);
    syncCreateProjectRequiredFields();

    if (focusFieldName !== '') {
        const targetField = createProjectForm.elements.namedItem(focusFieldName) || document.getElementById(focusFieldName);

        if (targetField && typeof targetField.focus === 'function') {
            window.setTimeout(function () {
                targetField.focus();
                if (typeof targetField.scrollIntoView === 'function') {
                    targetField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 80);
        }
    }
}

function initCreateProjectClientAutofill() {
    const createProjectForm = document.getElementById('create-project-form');

    if (!createProjectForm) {
        return;
    }

    const clientField = createProjectForm.elements.namedItem('client_id');
    const projectTitleField = createProjectForm.elements.namedItem('project_name');
    const projectSiteField = createProjectForm.elements.namedItem('project_site');
    const contactPersonField = createProjectForm.elements.namedItem('contact_person');
    const contactNumberField = createProjectForm.elements.namedItem('contact_number');
    let titleWasAutoGenerated = String(projectTitleField?.value || '').trim() === '';

    if (!(clientField instanceof HTMLSelectElement)) {
        return;
    }

    const getSelectedClient = function () {
        const selectedOption = clientField.options[clientField.selectedIndex];
        if (!selectedOption || selectedOption.value === '') {
            return null;
        }

        return {
            name: String(selectedOption.getAttribute('data-client-name') || '').trim(),
            phone: String(selectedOption.getAttribute('data-client-phone') || '').trim(),
        };
    };

    const buildAutoTitle = function () {
        const selectedClient = getSelectedClient();
        const site = String(projectSiteField?.value || '').trim();
        const clientName = selectedClient?.name || '';

        if (site !== '') {
            return 'Project - ' + site;
        }

        if (clientName !== '') {
            return 'Project - ' + clientName;
        }

        return '';
    };

    const syncProjectTitle = function (forceOverwrite) {
        if (!projectTitleField) {
            return;
        }

        const nextTitle = buildAutoTitle();
        if (nextTitle === '') {
            return;
        }

        if (forceOverwrite || titleWasAutoGenerated || String(projectTitleField.value || '').trim() === '') {
            projectTitleField.value = nextTitle;
            titleWasAutoGenerated = true;
        }
    };

    const syncClientDetails = function (forceOverwrite) {
        const selectedClient = getSelectedClient();

        if (!selectedClient) {
            if (forceOverwrite) {
                if (contactPersonField) {
                    contactPersonField.value = '';
                }
                if (contactNumberField) {
                    contactNumberField.value = '';
                }
            }
            return;
        }

        if (contactPersonField && (forceOverwrite || String(contactPersonField.value || '').trim() === '')) {
            contactPersonField.value = selectedClient.name;
        }

        if (contactNumberField && (forceOverwrite || String(contactNumberField.value || '').trim() === '')) {
            contactNumberField.value = selectedClient.phone;
        }

        syncProjectTitle(forceOverwrite);
    };

    projectTitleField?.addEventListener('input', function () {
        titleWasAutoGenerated = String(projectTitleField.value || '').trim() === '' || projectTitleField.value === buildAutoTitle();
    });

    projectSiteField?.addEventListener('input', function () {
        syncProjectTitle(false);
    });

    clientField.addEventListener('change', function () {
        syncClientDetails(true);
        createProjectForm.dispatchEvent(new Event('input', { bubbles: true }));
        createProjectForm.dispatchEvent(new Event('change', { bubbles: true }));
    });

    if (String(clientField.value || '').trim() !== '') {
        syncClientDetails(false);
    }

    syncProjectTitle(false);
}

function initProjectAdditionalInfoRows() {
    const createProjectForm = document.getElementById('create-project-form');
    const section = createProjectForm?.querySelector('[data-additional-info-section]');
    const list = section?.querySelector('[data-additional-info-list]');
    const addButton = section?.querySelector('[data-additional-info-add]');
    const template = section?.querySelector('[data-additional-info-template]');

    if (!createProjectForm || !section || !list || !addButton || !template) {
        return;
    }

    let nextIndex = Number(list.getAttribute('data-next-index') || '0');
    if (!Number.isFinite(nextIndex) || nextIndex < 0) {
        nextIndex = list.querySelectorAll('[data-additional-info-item]').length;
    }

    function buildRow(values) {
        const rowMarkup = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
        const fragment = document.createRange().createContextualFragment(rowMarkup);
        const row = fragment.querySelector('[data-additional-info-item]');

        if (!row) {
            return null;
        }

        row.querySelector('[data-additional-info-name]').value = String(values?.contact_name || '');
        row.querySelector('[data-additional-info-number]').value = String(values?.contact_number || '');
        row.querySelector('[data-additional-info-email]').value = String(values?.email_address || '');

        return row;
    }

    function addRow(values = {}, shouldFocus = false) {
        const row = buildRow(values);
        if (!row) {
            return;
        }

        list.appendChild(row);

        if (shouldFocus) {
            const firstField = row.querySelector('[data-additional-info-name]');
            if (firstField && typeof firstField.focus === 'function') {
                firstField.focus();
            }
        }

        syncRemoveButtons();
    }

    function ensureAtLeastOneRow() {
        if (list.querySelector('[data-additional-info-item]')) {
            return;
        }

        addRow();
    }

    function syncRemoveButtons() {
        const rows = Array.from(list.querySelectorAll('[data-additional-info-item]'));

        rows.forEach(function (row, index) {
            const removeButton = row.querySelector('[data-additional-info-remove]');
            if (!removeButton) {
                return;
            }

            const isBaseRow = index === 0;
            removeButton.hidden = isBaseRow;
            removeButton.disabled = isBaseRow;
        });
    }

    function collectRows() {
        return Array.from(list.querySelectorAll('[data-additional-info-item]')).map(function (row) {
            return {
                contact_name: String(row.querySelector('[data-additional-info-name]')?.value || ''),
                contact_number: String(row.querySelector('[data-additional-info-number]')?.value || ''),
                email_address: String(row.querySelector('[data-additional-info-email]')?.value || ''),
            };
        });
    }

    function setRows(rows) {
        list.innerHTML = '';
        nextIndex = 0;

        if (Array.isArray(rows) && rows.length > 0) {
            rows.forEach(function (row) {
                addRow(row);
            });
        } else {
            addRow();
        }
    }

    addButton.addEventListener('click', function () {
        addRow({}, true);
        createProjectForm.dispatchEvent(new Event('input', { bubbles: true }));
    });

    list.addEventListener('click', function (event) {
        const removeButton = event.target.closest('[data-additional-info-remove]');
        if (!removeButton) {
            return;
        }

        const row = removeButton.closest('[data-additional-info-item]');
        if (!row) {
            return;
        }

        row.remove();
        ensureAtLeastOneRow();
        syncRemoveButtons();
        createProjectForm.dispatchEvent(new Event('input', { bubbles: true }));
    });

    ensureAtLeastOneRow();
    syncRemoveButtons();

    window.__projectAdditionalInfoManager = {
        getRows: collectRows,
        setRows: setRows,
    };
}

function initCreateProjectDraft() {
    const createProjectForm = document.getElementById('create-project-form');
    const clearButton = document.getElementById('create-project-clear-details');
    const hasServerDraft = createProjectForm?.dataset.hasServerDraft === 'true';
    const shouldClearStoredDraft = createProjectForm?.dataset.shouldClearStoredDraft === 'true';
    const blankAdditionalInfo = JSON.parse(createProjectForm?.dataset.blankAdditionalInfo || '{}');
    const defaultDraft = {
        project_name: '',
        project_code: createProjectForm?.dataset.defaultProjectCode || '',
        po_number: '',
        client_id: '',
        contact_person: '',
        contact_number: '',
        engineer_ids: [],
        status: 'pending',
        po_date: '',
        project_start_date: '',
        estimated_completion_date: '',
        project_site: '',
        budget_amount: '',
        budget_notes: '',
        description: '',
        project_address: '',
        additional_info: [blankAdditionalInfo],
    };
    const draftStorageKey = 'codesamplecaps.superadmin.projects.createProjectDraft.v1';

    if (!createProjectForm) {
        return;
    }

    function buildEngineerChip(engineerId, engineerName) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'engineer-chip';
        chip.setAttribute('data-engineer-chip', '');
        chip.setAttribute('data-engineer-id', engineerId);
        chip.setAttribute('data-engineer-name', engineerName);
        chip.setAttribute('aria-pressed', 'true');
        chip.innerHTML = '<span></span><span class="engineer-chip__remove" aria-hidden="true">&times;</span>';
        chip.querySelector('span').textContent = engineerName;
        return chip;
    }

    function setFieldValue(name, value) {
        const field = createProjectForm.elements.namedItem(name);

        if (!field) {
            return;
        }

        if (field instanceof RadioNodeList) {
            Array.from(field).forEach(function (option) {
                option.checked = option.value === value;
            });
            return;
        }

        field.value = value;
    }

    function setEngineerIds(engineerIds) {
        const selectedContainer = createProjectForm.querySelector('[data-engineer-selected]');
        const inputsContainer = createProjectForm.querySelector('[data-engineer-inputs]');
        const engineerSelect = createProjectForm.querySelector('[data-engineer-select]');

        if (!selectedContainer || !inputsContainer || !engineerSelect) {
            return;
        }

        selectedContainer.innerHTML = '';
        inputsContainer.innerHTML = '';
        engineerSelect.value = '';

        engineerIds.forEach(function (engineerId) {
            const option = Array.from(engineerSelect.options).find(function (candidate) {
                return candidate.value === String(engineerId);
            });

            if (!option || option.value === '') {
                return;
            }

            selectedContainer.appendChild(buildEngineerChip(option.value, option.text));

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'engineer_ids[]';
            hiddenInput.value = option.value;
            hiddenInput.setAttribute('data-engineer-input', '');
            inputsContainer.appendChild(hiddenInput);
        });
    }

    function getEngineerIds() {
        return Array.from(createProjectForm.querySelectorAll('[data-engineer-input]')).map(function (input) {
            return String(input.value);
        });
    }

    function collectDraft() {
        return {
            project_name: String(createProjectForm.elements.namedItem('project_name')?.value || ''),
            project_code: String(createProjectForm.elements.namedItem('project_code')?.value || ''),
            po_number: String(createProjectForm.elements.namedItem('po_number')?.value || ''),
            client_id: String(createProjectForm.elements.namedItem('client_id')?.value || ''),
            contact_person: String(createProjectForm.elements.namedItem('contact_person')?.value || ''),
            contact_number: String(createProjectForm.elements.namedItem('contact_number')?.value || ''),
            engineer_ids: getEngineerIds(),
            status: String(createProjectForm.elements.namedItem('status')?.value || defaultDraft.status),
            po_date: String(createProjectForm.elements.namedItem('po_date')?.value || ''),
            project_start_date: String(createProjectForm.elements.namedItem('project_start_date')?.value || ''),
            estimated_completion_date: String(createProjectForm.elements.namedItem('estimated_completion_date')?.value || ''),
            project_site: String(createProjectForm.elements.namedItem('project_site')?.value || ''),
            budget_amount: String(createProjectForm.elements.namedItem('budget_amount')?.value || ''),
            budget_notes: String(createProjectForm.elements.namedItem('budget_notes')?.value || ''),
            description: String(createProjectForm.elements.namedItem('description')?.value || ''),
            project_address: String(createProjectForm.elements.namedItem('project_address')?.value || ''),
            additional_info: window.__projectAdditionalInfoManager
                ? window.__projectAdditionalInfoManager.getRows()
                : defaultDraft.additional_info,
        };
    }

    function saveDraft() {
        try {
            window.localStorage.setItem(draftStorageKey, JSON.stringify(collectDraft()));
        } catch (error) {
        }
    }

    function loadStoredDraft() {
        try {
            const rawDraft = window.localStorage.getItem(draftStorageKey);
            if (!rawDraft) {
                return null;
            }

            const parsedDraft = JSON.parse(rawDraft);
            return parsedDraft && typeof parsedDraft === 'object' ? parsedDraft : null;
        } catch (error) {
            return null;
        }
    }

    function clearStoredDraft() {
        try {
            window.localStorage.removeItem(draftStorageKey);
        } catch (error) {
        }
    }

    function applyDraft(draft) {
        if (!draft || typeof draft !== 'object') {
            return;
        }

        Object.keys(defaultDraft).forEach(function (fieldName) {
            if (fieldName === 'engineer_ids' || fieldName === 'additional_info' || fieldName === 'project_code') {
                return;
            }

            const nextValue = Object.prototype.hasOwnProperty.call(draft, fieldName)
                ? String(draft[fieldName] ?? '')
                : String(defaultDraft[fieldName] ?? '');

            setFieldValue(fieldName, nextValue);
        });

        setEngineerIds(Array.isArray(draft.engineer_ids) ? draft.engineer_ids.map(String) : []);
        setFieldValue('project_code', defaultDraft.project_code);

        if (window.__projectAdditionalInfoManager) {
            const nextRows = Array.isArray(draft.additional_info) ? draft.additional_info : defaultDraft.additional_info;
            window.__projectAdditionalInfoManager.setRows(nextRows);
        }
    }

    if (shouldClearStoredDraft) {
        clearStoredDraft();
    } else if (!hasServerDraft) {
        applyDraft(loadStoredDraft());
    }

    createProjectForm.addEventListener('input', saveDraft);
    createProjectForm.addEventListener('change', saveDraft);

    const engineerInputsContainer = createProjectForm.querySelector('[data-engineer-inputs]');
    if (engineerInputsContainer) {
        const observer = new MutationObserver(saveDraft);
        observer.observe(engineerInputsContainer, { childList: true, subtree: true, attributes: true, attributeFilter: ['value'] });
    }

    if (clearButton) {
        clearButton.addEventListener('click', function () {
            if (!window.confirm('Clear all saved project details?')) {
                return;
            }

            applyDraft(defaultDraft);
            createProjectForm.querySelectorAll('.is-invalid-live').forEach(function (field) {
                field.classList.remove('is-invalid-live');
                field.setCustomValidity('');
            });

            const currencyInput = createProjectForm.querySelector('[data-currency-input="php"]');
            if (currencyInput) {
                currencyInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            clearStoredDraft();
        });
    }
}

function initCurrencyInputs() {
    const currencyInputs = Array.from(document.querySelectorAll('[data-currency-input="php"]'));

    if (currencyInputs.length === 0) {
        return;
    }

    function sanitizeCurrencyValue(value) {
        let normalized = String(value || '').replace(/php/gi, '').replace(/[₱,\s]/g, '');
        normalized = normalized.replace(/[^0-9.]/g, '');

        const firstDotIndex = normalized.indexOf('.');
        if (firstDotIndex !== -1) {
            normalized = normalized.slice(0, firstDotIndex + 1) + normalized.slice(firstDotIndex + 1).replace(/\./g, '');
        }

        const parts = normalized.split('.');
        const wholePart = (parts[0] || '').replace(/^0+(?=\d)/, '');
        const decimalPart = parts.length > 1 ? parts[1].slice(0, 2) : '';

        return {
            wholePart: wholePart === '' ? '0' : wholePart,
            hasDecimal: firstDotIndex !== -1,
            decimalPart: decimalPart,
            isEmpty: normalized === '',
        };
    }

    function formatCurrencyValue(value, forceTwoDecimals) {
        const sanitized = sanitizeCurrencyValue(value);

        if (sanitized.isEmpty) {
            return '';
        }

        const withCommas = sanitized.wholePart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        if (forceTwoDecimals) {
            return withCommas + '.' + sanitized.decimalPart.padEnd(2, '0');
        }

        if (sanitized.hasDecimal) {
            return withCommas + '.' + sanitized.decimalPart;
        }

        return withCommas;
    }

    currencyInputs.forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = formatCurrencyValue(input.value, false);
        });

        input.addEventListener('blur', function () {
            input.value = formatCurrencyValue(input.value, true);
        });

        input.value = formatCurrencyValue(input.value, false);
    });
}

function initEngineerAssignmentPicker() {
    const picker = document.querySelector('[data-engineer-picker]');

    if (!picker || picker.dataset.toggleBound === 'true') {
        return;
    }

    const engineerSelect = picker.querySelector('[data-engineer-select]');
    const toggleButton = picker.querySelector('[data-engineer-toggle]');
    const toggleButtonIcon = picker.querySelector('.engineer-picker__toggle-icon');
    const toggleButtonText = picker.querySelector('.engineer-picker__toggle-text');
    const selectedContainer = picker.querySelector('[data-engineer-selected]');
    const inputsContainer = picker.querySelector('[data-engineer-inputs]');
    const createProjectForm = document.getElementById('create-project-form');

    if (!engineerSelect || !toggleButton || !selectedContainer || !inputsContainer || !createProjectForm) {
        return;
    }

    function getSelectedEngineerIds() {
        return Array.from(inputsContainer.querySelectorAll('[data-engineer-input]')).map(function (input) {
            return String(input.value);
        });
    }

    function syncValidation() {
        const hasSelectedEngineers = getSelectedEngineerIds().length > 0;
        if (hasSelectedEngineers) {
            engineerSelect.setCustomValidity('');
        }
    }

    function syncToggleButton() {
        const selectedValue = engineerSelect.value;
        const hasSelectedValue = selectedValue !== '';
        const isAlreadyAdded = hasSelectedValue && getSelectedEngineerIds().includes(selectedValue);

        toggleButton.disabled = !hasSelectedValue;
        toggleButton.classList.toggle('is-remove', Boolean(isAlreadyAdded));
        toggleButton.setAttribute('aria-label', isAlreadyAdded ? 'Remove selected engineer' : 'Add selected engineer');
        toggleButtonIcon.textContent = isAlreadyAdded ? '\u2212' : '+';
        toggleButtonText.textContent = isAlreadyAdded ? 'Remove' : 'Add';
    }

    function renderEmptyState() {
        const hasChip = selectedContainer.querySelector('[data-engineer-chip]');
        selectedContainer.classList.toggle('is-empty', !hasChip);

        if (!hasChip) {
            selectedContainer.innerHTML = '<span class="engineer-picker__empty">No engineers added yet.</span>';
        }
    }

    function addEngineer(engineerId, engineerName) {
        const existingInput = inputsContainer.querySelector('[data-engineer-input][value="' + CSS.escape(engineerId) + '"]');

        if (existingInput) {
            return;
        }

        const emptyState = selectedContainer.querySelector('.engineer-picker__empty');
        if (emptyState) {
            emptyState.remove();
        }

        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'engineer-chip';
        chip.setAttribute('data-engineer-chip', '');
        chip.setAttribute('data-engineer-id', engineerId);
        chip.setAttribute('data-engineer-name', engineerName);
        chip.setAttribute('aria-pressed', 'true');
        chip.innerHTML = '<span></span><span class="engineer-chip__remove" aria-hidden="true">&times;</span>';
        chip.querySelector('span').textContent = engineerName;
        selectedContainer.appendChild(chip);

        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'engineer_ids[]';
        hiddenInput.value = engineerId;
        hiddenInput.setAttribute('data-engineer-input', '');
        inputsContainer.appendChild(hiddenInput);

        syncValidation();
        syncToggleButton();
    }

    function removeEngineer(engineerId) {
        const hiddenInput = inputsContainer.querySelector('[data-engineer-input][value="' + CSS.escape(engineerId) + '"]');
        const chip = selectedContainer.querySelector('[data-engineer-chip][data-engineer-id="' + CSS.escape(engineerId) + '"]');

        if (hiddenInput) {
            hiddenInput.remove();
        }

        if (chip) {
            chip.remove();
        }

        renderEmptyState();
        syncValidation();
        syncToggleButton();
    }

    engineerSelect.addEventListener('change', function () {
        engineerSelect.setCustomValidity('');
        syncToggleButton();
    });

    toggleButton.addEventListener('click', function () {
        const engineerId = engineerSelect.value;
        const engineerName = engineerSelect.options[engineerSelect.selectedIndex]?.text || '';

        if (engineerId === '') {
            engineerSelect.setCustomValidity('Assigned engineer is required.');
            engineerSelect.reportValidity();
            return;
        }

        if (getSelectedEngineerIds().includes(engineerId)) {
            removeEngineer(engineerId);
        } else {
            addEngineer(engineerId, engineerName);
        }

        engineerSelect.setCustomValidity('');
        engineerSelect.focus();
    });

    selectedContainer.addEventListener('click', function (event) {
        const chip = event.target.closest('[data-engineer-chip]');

        if (!chip) {
            return;
        }

        const engineerId = chip.getAttribute('data-engineer-id') || '';
        if (engineerId === '') {
            return;
        }

        engineerSelect.value = engineerId;
        removeEngineer(engineerId);
        engineerSelect.focus();
    });

    createProjectForm.addEventListener('submit', function (event) {
        syncValidation();

        if (getSelectedEngineerIds().length === 0) {
            event.preventDefault();
            engineerSelect.setCustomValidity('Assigned engineer is required.');
            engineerSelect.reportValidity();
            engineerSelect.focus();
            return;
        }

        engineerSelect.setCustomValidity('');
    });

    renderEmptyState();
    syncValidation();
    syncToggleButton();
    picker.dataset.toggleBound = 'true';
}

function initFieldTipToggle() {
    const tips = Array.from(document.querySelectorAll('.field-tip'));

    function closeTips(exceptTip) {
        tips.forEach(function (tip) {
            if (tip !== exceptTip) {
                tip.classList.remove('is-visible');
            }
        });
    }

    tips.forEach(function (tip) {
        tip.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            const willOpen = !tip.classList.contains('is-visible');
            closeTips(tip);
            tip.classList.toggle('is-visible', willOpen);
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.field-tip')) {
            closeTips(null);
        }
    });

    document.addEventListener('focusin', function (event) {
        if (!event.target.closest('.field-tip')) {
            closeTips(null);
        }
    });
}

function initProjectsScrollRestore() {
    const storageKey = 'codesamplecaps.admin.projects.scrollY';

    try {
        const savedScroll = window.sessionStorage.getItem(storageKey);
        if (savedScroll !== null) {
            window.sessionStorage.removeItem(storageKey);
            const scrollY = Number(savedScroll);
            if (Number.isFinite(scrollY) && scrollY > 0) {
                window.setTimeout(function () {
                    window.scrollTo({ top: scrollY, behavior: 'auto' });
                }, 50);
            }
        }
    } catch (error) {
    }

    window.addEventListener('beforeunload', function () {
        try {
            window.sessionStorage.setItem(storageKey, String(window.scrollY || 0));
        } catch (error) {
        }
    });
}

function initProjectConfirmForms() {
    document.addEventListener('submit', function (event) {
        const form = event.target.closest('form[data-confirm]');

        if (!form) {
            return;
        }

        const message = form.getAttribute('data-confirm') || 'Continue?';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
}

function initCreateProjectPanel() {
    const panel = document.querySelector('[data-create-project-panel]');
    const dialog = panel ? panel.querySelector('[role="dialog"]') : null;
    const title = document.getElementById('create-project-title');
    const sourceInput = panel?.querySelector('[data-project-source-input]');

    if (!panel) {
        return;
    }

    function openPanel(mode) {
        if (title) {
            if (mode === 'walk-in') {
                title.textContent = 'Create Walk-in Project';
            } else if (mode === 'returning-client') {
                title.textContent = 'Create Returning Client Project';
            } else {
                title.textContent = 'Create Project';
            }
        }

        if (sourceInput) {
            sourceInput.value = mode === 'returning-client' ? 'returning_client' : 'walk_in';
        }

        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('project-modal-open');

        const firstField = panel.querySelector('input:not([type="hidden"]), select, textarea, button');
        if (firstField) {
            firstField.focus({ preventScroll: true });
        }
    }

    function closePanel() {
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('project-modal-open');
    }

    document.addEventListener('click', function (event) {
        const openButton = event.target.closest('[data-create-project-open]');
        const closeButton = event.target.closest('[data-create-project-close]');

        if (openButton) {
            event.preventDefault();
            openPanel(openButton.getAttribute('data-create-project-mode') || '');
            return;
        }

        if (closeButton) {
            event.preventDefault();
            closePanel();
        }
    });

    panel.addEventListener('click', function (event) {
        if (dialog && !dialog.contains(event.target)) {
            closePanel();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && panel.classList.contains('is-open')) {
            closePanel();
        }
    });

    if (panel.classList.contains('is-open')) {
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('project-modal-open');
    }
}

function initWalkInInquiryPanel() {
    const panel = document.querySelector('[data-walk-in-inquiry-panel]');
    const dialog = panel?.querySelector('[role="dialog"]');
    const form = panel?.querySelector('[data-walk-in-inquiry-form]');
    const clientName = panel?.querySelector('[data-walk-in-client-name]');
    const companyName = panel?.querySelector('[data-walk-in-company]');
    const emailAddress = panel?.querySelector('[data-walk-in-email]');
    const contactNumber = panel?.querySelector('[data-walk-in-contact-number]');
    const siteAddress = panel?.querySelector('[data-walk-in-site-address]');
    const description = panel?.querySelector('[data-walk-in-description]');
    const serviceSelect = panel?.querySelector('select[name="service_category"]');
    const budgetSelect = panel?.querySelector('select[name="estimated_budget"]');
    const otherServiceWrap = panel?.querySelector('[data-walk-in-other-service]');
    const otherService = panel?.querySelector('input[name="other_service_details"]');
    const regionSelect = panel?.querySelector('[data-walk-in-region]');
    const areaSelect = panel?.querySelector('[data-walk-in-area]');
    const citySelect = panel?.querySelector('[data-walk-in-city]');
    const barangaySelect = panel?.querySelector('[data-walk-in-barangay]');
    const dateInput = panel?.querySelector('[data-walk-in-date]');
    const engineerSelect = panel?.querySelector('select[name="preferred_engineer_id"]');
    const nextActionInput = panel?.querySelector('[data-walk-in-next-action]');
    const submitActionButton = panel?.querySelector('[data-walk-in-submit-action]');
    const engineerHelp = panel?.querySelector('[data-walk-in-engineer-help]');
    const nameError = panel?.querySelector('[data-walk-in-name-error]');
    const companyError = panel?.querySelector('[data-walk-in-company-error]');
    const emailError = panel?.querySelector('[data-walk-in-email-error]');
    const sendOtpButton = panel?.querySelector('[data-walk-in-send-otp]');
    const emailStatus = panel?.querySelector('[data-walk-in-email-status]');
    const contactError = panel?.querySelector('[data-walk-in-contact-error]');
    const descriptionError = panel?.querySelector('[data-walk-in-description-error]');
    const otherError = panel?.querySelector('[data-walk-in-other-error]');
    const serviceError = panel?.querySelector('[data-walk-in-service-error]');
    const budgetError = panel?.querySelector('[data-walk-in-budget-error]');
    const regionError = panel?.querySelector('[data-walk-in-region-error]');
    const areaError = panel?.querySelector('[data-walk-in-area-error]');
    const cityError = panel?.querySelector('[data-walk-in-city-error]');
    const barangayError = panel?.querySelector('[data-walk-in-barangay-error]');
    const siteAddressError = panel?.querySelector('[data-walk-in-site-address-error]');
    const dateError = panel?.querySelector('[data-walk-in-date-error]');
    const confirmModal = document.querySelector('[data-walk-in-confirm-modal]');
    const confirmTitle = confirmModal?.querySelector('[data-walk-in-confirm-title]');
    const confirmMessage = confirmModal?.querySelector('[data-walk-in-confirm-message]');
    const confirmAccept = confirmModal?.querySelector('[data-walk-in-confirm-accept]');
    const confirmCancel = confirmModal?.querySelector('[data-walk-in-confirm-cancel]');
    const saveModal = document.querySelector('[data-walk-in-save-modal]');
    const saveTitle = saveModal?.querySelector('[data-walk-in-save-title]');
    const saveMessage = saveModal?.querySelector('[data-walk-in-save-message]');
    const otpModal = document.querySelector('[data-walk-in-otp-modal]');
    const otpEmail = otpModal?.querySelector('[data-walk-in-otp-email]');
    const otpCode = otpModal?.querySelector('[data-walk-in-otp-code]');
    const otpError = otpModal?.querySelector('[data-walk-in-otp-error]');
    const otpVerifyButton = otpModal?.querySelector('[data-walk-in-otp-verify]');
    const otpResendButton = otpModal?.querySelector('[data-walk-in-otp-resend]');
    const otpTimer = otpModal?.querySelector('[data-walk-in-otp-timer]');
    const draftKey = 'codesamplecaps.admin.walkInInquiryDraft';
    const panelStateKey = 'codesamplecaps.admin.walkInInquiryOpen';
    let locationData = null;
    let locationLoading = null;
    let pendingConfirmAction = null;
    let isSubmitting = false;
    let saveConfirmed = false;
    let contactCheckId = 0;
    let contactCheckTimer = null;
    let emailEdited = false;
    let contactEdited = false;
    let emailIsVerified = false;
    let contactCanVerifyEmail = false;
    let historyGuardReady = false;
    let ignoreNextPopstate = false;
    let otpExpiresAt = 0;
    let otpResendAt = 0;
    let otpClock = null;
    let otpLockClock = null;
    let otpLockedUntil = 0;

    if (!panel || !form) return;

    function syncWalkInDateWindow() {
        if (!dateInput) return;

        const formatter = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            hourCycle: 'h23',
        });
        const parts = formatter.formatToParts(new Date()).reduce(function (values, part) {
            if (part.type !== 'literal') values[part.type] = part.value;
            return values;
        }, {});
        const today = parts.year + '-' + parts.month + '-' + parts.day;
        const addDays = function (dateValue, days) {
            const values = dateValue.split('-').map(Number);
            return new Date(Date.UTC(values[0], values[1] - 1, values[2] + days)).toISOString().slice(0, 10);
        };

        dateInput.min = Number.parseInt(parts.hour || '0', 10) >= 17 ? addDays(today, 1) : today;
        dateInput.max = addDays(today, 30);
        if (dateInput.value && (dateInput.value < dateInput.min || dateInput.value > dateInput.max)) {
            dateInput.value = '';
        }
    }

    function setValidation(field, errorElement, message) {
        if (!field) return false;
        const hasValue = String(field.value || '').trim() !== '';
        const isInvalid = message !== '';
        field.setCustomValidity(message);
        field.classList.remove('is-unverified-live');
        field.classList.toggle('is-invalid-live', isInvalid);
        field.classList.toggle('is-valid-live', !isInvalid && hasValue && !field.disabled);
        if (isInvalid) field.setAttribute('aria-invalid', 'true');
        else field.removeAttribute('aria-invalid');
        if (errorElement) {
            errorElement.classList.remove('is-info', 'is-warning');
            errorElement.textContent = message;
        }
        return message === '';
    }

    function resetValidation(field, errorElement) {
        if (!field) return;
        field.setCustomValidity('');
        field.classList.remove('is-invalid-live', 'is-valid-live', 'is-unverified-live');
        field.removeAttribute('aria-invalid');
        if (errorElement) {
            errorElement.classList.remove('is-info', 'is-warning');
            errorElement.textContent = '';
        }
    }

    function setEmailWarning(message) {
        if (!emailAddress) return false;
        emailAddress.setCustomValidity('');
        emailAddress.classList.remove('is-invalid-live', 'is-valid-live');
        emailAddress.classList.add('is-unverified-live');
        emailAddress.setAttribute('aria-invalid', 'false');
        if (emailError) {
            emailError.classList.remove('is-info');
            emailError.classList.add('is-warning');
            emailError.textContent = message;
        }
        return true;
    }

    function setContactInfo(field, message) {
        if (!field) return;
        field.textContent = message;
        field.classList.toggle('is-info', message !== '');
    }

    function closeCombobox(input) {
        const box = input?.closest('[data-walk-in-combobox]');
        box?.classList.remove('is-open');
        const list = box?.querySelector('[data-walk-in-combobox-list]');
        if (list) list.replaceChildren();
    }

    function closeOtherComboboxes(activeInput) {
        panel.querySelectorAll('[data-walk-in-combobox]').forEach(function (box) {
            if (box !== activeInput?.closest('[data-walk-in-combobox]')) {
                box.classList.remove('is-open');
            }
        });
    }

    function setSelectedLocation(input, value) {
        if (!input) return;
        input.value = value;
        input.dataset.selectedValue = value;
        const errorElement = input.closest('.input-group')?.querySelector('.walk-in-field-error');
        setValidation(input, errorElement, '');
    }

    function clearLocation(input, placeholder) {
        if (!input) return;
        input.value = '';
        delete input.dataset.selectedValue;
        input.placeholder = placeholder;
        const errorElement = input.closest('.input-group')?.querySelector('.walk-in-field-error');
        resetValidation(input, errorElement);
        closeCombobox(input);
    }

    function isSelectedLocation(input, options) {
        const value = String(input?.value || '').trim();
        return value !== '' && value === (input?.dataset.selectedValue || '') && options.includes(value);
    }

    function renderLocationOptions(input, options, showAll = false) {
        const box = input?.closest('[data-walk-in-combobox]');
        const list = box?.querySelector('[data-walk-in-combobox-list]');
        if (!input || !list || input.disabled) return;

        closeOtherComboboxes(input);
        const search = showAll ? '' : input.value.trim().toLowerCase();
        const matches = options.filter(function (option) {
            return option.toLowerCase().includes(search);
        });
        list.replaceChildren();

        if (matches.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'walk-in-combobox__empty';
            empty.textContent = 'No match. Select from the list.';
            list.appendChild(empty);
        } else {
            matches.forEach(function (option) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = option;
                button.addEventListener('click', function (event) {
                    // Huwag isara ang form habang pumipili.
                    event.preventDefault();
                    event.stopPropagation();
                    setSelectedLocation(input, option);
                    closeCombobox(input);
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
                list.appendChild(button);
            });
        }
        box?.classList.add('is-open');
    }

    function bindLocationCombobox(input, getOptions, onChanged, errorElement, label) {
        if (!input) return;
        const toggle = input.closest('[data-walk-in-combobox]')?.querySelector('[data-walk-in-combobox-toggle]');
        input.addEventListener('input', function () {
            if (input.value !== (input.dataset.selectedValue || '')) delete input.dataset.selectedValue;
            onChanged();
            validateLocation(input, getOptions(), errorElement, label);
            renderLocationOptions(input, getOptions());
        });
        input.addEventListener('focus', function () {
            renderLocationOptions(input, getOptions());
        });
        input.addEventListener('blur', function () {
            window.setTimeout(function () {
                validateLocation(input, getOptions(), errorElement, label);
            }, 120);
        });
        toggle?.addEventListener('click', function () {
            if (input.disabled) return;
            if (input.closest('[data-walk-in-combobox]')?.classList.contains('is-open')) {
                closeCombobox(input);
                return;
            }
            input.focus();
            renderLocationOptions(input, getOptions(), true);
        });
    }

    function loadLocations() {
        if (locationData) return Promise.resolve(locationData);
        if (locationLoading) return locationLoading;
        locationLoading = fetch('/codesamplecaps/ADMIN/sidebar/projects/php/walk_in_location_data.php', {
            headers: { Accept: 'application/json' },
        }).then(function (response) {
            if (!response.ok) throw new Error('Location list is not available.');
            return response.json();
        }).then(function (data) {
            locationData = data;
            const initialRegion = regionSelect?.dataset.initialValue || '';
            const regions = Object.keys(data.areas || {});
            regionSelect.disabled = regions.length === 0;
            regionSelect.placeholder = 'Search or select region';
            if (regions.includes(initialRegion)) setSelectedLocation(regionSelect, initialRegion);
            syncAreas(true);
            return data;
        }).catch(function () {
            regionSelect.disabled = true;
            regionSelect.placeholder = 'Location list is not available';
            return null;
        });
        return locationLoading;
    }

    function getRegionOptions() {
        return Object.keys(locationData?.areas || {});
    }

    function getAreaOptions() {
        const region = regionSelect?.value || '';
        return isSelectedLocation(regionSelect, getRegionOptions())
            ? Object.keys(locationData?.areas?.[region] || {})
            : [];
    }

    function getCityOptions() {
        const region = regionSelect?.value || '';
        const area = areaSelect?.value || '';
        return isSelectedLocation(areaSelect, getAreaOptions())
            ? (locationData?.areas?.[region]?.[area] || [])
            : [];
    }

    function getBarangayOptions() {
        const region = regionSelect?.value || '';
        const area = areaSelect?.value || '';
        const city = citySelect?.value || '';
        return isSelectedLocation(citySelect, getCityOptions())
            ? (locationData?.barangays?.[region]?.[area]?.[city] || [])
            : [];
    }

    function syncAreas(restoreInitial = false) {
        clearLocation(areaSelect, 'Select region first');
        if (areaSelect) areaSelect.readOnly = false;
        clearLocation(citySelect, 'Select province / area first');
        clearLocation(barangaySelect, 'Select city first');
        const options = getAreaOptions();
        areaSelect.disabled = options.length === 0;
        if (options.length) areaSelect.placeholder = 'Search or select province / area';

        const isNcr = regionSelect?.value === 'National Capital Region (NCR)';
        if (isNcr && options.includes('Metro Manila (NCR)')) {
            setSelectedLocation(areaSelect, 'Metro Manila (NCR)');
            areaSelect.readOnly = true;
            syncCities(restoreInitial);
            return;
        }

        const initial = restoreInitial ? (areaSelect.dataset.initialValue || '') : '';
        if (options.includes(initial)) setSelectedLocation(areaSelect, initial);
        syncCities(restoreInitial);
    }

    function syncCities(restoreInitial = false) {
        clearLocation(citySelect, 'Select province / area first');
        clearLocation(barangaySelect, 'Select city first');
        const options = getCityOptions();
        citySelect.disabled = options.length === 0;
        if (options.length) citySelect.placeholder = 'Search or select city / municipality';
        const initial = restoreInitial ? (citySelect.dataset.initialValue || '') : '';
        if (options.includes(initial)) setSelectedLocation(citySelect, initial);
        syncBarangays(restoreInitial);
    }

    function syncBarangays(restoreInitial = false) {
        clearLocation(barangaySelect, 'Select city first');
        const options = getBarangayOptions();
        barangaySelect.disabled = options.length === 0;
        if (options.length) barangaySelect.placeholder = 'Search or select barangay';
        const initial = restoreInitial ? (barangaySelect.dataset.initialValue || '') : '';
        if (options.includes(initial)) setSelectedLocation(barangaySelect, initial);
    }

    function validateName() {
        const value = String(clientName?.value || '').trim();
        if (value === '') {
            return setValidation(clientName, nameError, 'Full Name is required.');
        }
        const allowed = /^[\p{L}\p{M} .'\u2019-]+$/u.test(value);
        const letters = value.match(/\p{L}/gu) || [];
        return setValidation(clientName, nameError, value.length <= 150 && allowed && letters.length >= 2 ? '' : 'Enter a valid full name.');
    }

    function validateCompany() {
        const value = String(companyName?.value || '').trim();
        if (value === '') {
            resetValidation(companyName, companyError);
            return true;
        }
        const allowed = /^[\p{L}\p{M}\p{N} .,'\u2019&()\-]+$/u.test(value);
        const letters = value.match(/\p{L}/gu) || [];
        return setValidation(companyName, companyError, value.length >= 2 && value.length <= 150 && allowed && letters.length >= 1
            ? ''
            : 'Enter a valid company name or leave it blank.');
    }

    function validateContact() {
        const value = String(contactNumber?.value || '');
        if (value === '' || value === '09') {
            return setValidation(contactNumber, contactError, 'Contact Number is required.');
        }
        return setValidation(contactNumber, contactError, /^09\d{9}$/.test(value) ? '' : 'Use 09 and enter 11 digits.');
    }

    function validateAutofilledIdentity(field) {
        if (field === clientName && String(field.value || '').trim() !== '') {
            validateName();
        }
        if (field === contactNumber && String(field.value || '') !== '09') {
            keepContactPrefix();
            validateContact();
        }
    }

    function keepContactPrefix() {
        if (!contactNumber) return;
        let digits = contactNumber.value.replace(/\D/g, '');
        if (!digits.startsWith('09')) {
            digits = '09' + digits.replace(/^0?9?/, '');
        }
        contactNumber.value = digits.slice(0, 11) || '09';
    }

    function keepContactCursorAfterPrefix() {
        if (!contactNumber || document.activeElement !== contactNumber) return;
        const start = Math.max(2, contactNumber.selectionStart ?? 2);
        const end = Math.max(2, contactNumber.selectionEnd ?? start);
        contactNumber.setSelectionRange(start, end);
    }

    function validateEmail() {
        const value = String(emailAddress?.value || '').trim();
        const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        if (!valid) return setValidation(emailAddress, emailError, 'Enter a valid email address.');
        if (emailIsVerified) return setValidation(emailAddress, emailError, '');
        return setEmailWarning('Verify this email to continue.');
    }

    function syncVerifyEmailButton() {
        if (!sendOtpButton) return;
        const hasValidEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(emailAddress?.value || '').trim());
        sendOtpButton.disabled = Date.now() < otpLockedUntil || (!emailIsVerified && !hasValidEmail);
    }

    function setEmailVerified(verified, statusMessage = '') {
        emailIsVerified = verified;
        if (emailAddress) {
            emailAddress.readOnly = verified;
            emailAddress.classList.toggle('is-email-locked', verified);
            if (verified) setValidation(emailAddress, emailError, '');
            else emailAddress.classList.remove('is-valid-live');
        }
        if (emailStatus) {
            emailStatus.textContent = statusMessage || (verified ? 'Email verified' : 'Email not verified');
            emailStatus.classList.toggle('is-verified', verified);
        }
        if (sendOtpButton) {
            sendOtpButton.textContent = verified ? 'Change Email' : 'Verify Email';
            syncVerifyEmailButton();
        }
    }

    function unlockVerifiedEmail() {
        const clearVerificationData = new FormData();
        clearVerificationData.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
        fetch('/codesamplecaps/ADMIN/sidebar/projects/php/clear_walk_in_email_verification.php', {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: clearVerificationData,
        }).catch(function () {
            // Lilinisin din ito kapag nag-expire ang session.
        });
        contactCanVerifyEmail = false;
        setEmailVerified(false);
        resetValidation(emailAddress, emailError);
        emailAddress?.focus({ preventScroll: true });
    }

    function closeOtpModal() {
        window.clearInterval(otpClock);
        otpClock = null;
        otpModal?.classList.remove('is-open');
        otpModal?.setAttribute('aria-hidden', 'true');
        panel.classList.remove('is-otp-locked');
        document.body.classList.remove('walk-in-otp-lock');
        if (dialog) dialog.inert = false;
        if (otpError) {
            otpError.textContent = '';
            otpError.classList.remove('is-success');
        }
        if (otpCode) otpCode.value = '';
        sendOtpButton?.focus({ preventScroll: true });
    }

    function formatOtpTime(seconds) {
        const safeSeconds = Math.max(0, seconds);
        return `${String(Math.floor(safeSeconds / 60)).padStart(2, '0')}:${String(safeSeconds % 60).padStart(2, '0')}`;
    }

    function updateOtpClock() {
        const now = Date.now();
        const expiresIn = Math.max(0, Math.ceil((otpExpiresAt - now) / 1000));
        const resendIn = Math.max(0, Math.ceil((otpResendAt - now) / 1000));

        if (otpTimer) {
            otpTimer.textContent = expiresIn > 0 ? `Code expires in ${formatOtpTime(expiresIn)}` : 'Code expired. Resend a new code.';
            otpTimer.classList.toggle('is-warning', expiresIn > 0 && expiresIn <= 60);
            otpTimer.classList.toggle('is-expired', expiresIn === 0);
        }
        if (otpResendButton) {
            otpResendButton.textContent = resendIn > 0 ? `Resend Code (${formatOtpTime(resendIn)})` : 'Resend Code';
            otpResendButton.disabled = resendIn > 0;
        }
    }

    function startOtpClock(expiresIn = 180, resendIn = 60) {
        otpLockedUntil = 0;
        if (otpVerifyButton) otpVerifyButton.textContent = 'Verify Code';
        otpExpiresAt = Date.now() + (Number(expiresIn) || 180) * 1000;
        otpResendAt = Date.now() + resendIn * 1000;
        window.clearInterval(otpClock);
        updateOtpClock();
        otpClock = window.setInterval(updateOtpClock, 1000);
    }

    function startOtpLockClock(retryAfter = 900) {
        window.clearInterval(otpClock);
        window.clearInterval(otpLockClock);
        otpLockedUntil = Date.now() + Math.max(1, Number(retryAfter) || 900) * 1000;
        if (otpCode) {
            otpCode.value = '';
            otpCode.disabled = true;
            otpCode.classList.remove('is-invalid');
        }
        if (otpVerifyButton) otpVerifyButton.disabled = true;
        if (otpVerifyButton) otpVerifyButton.textContent = 'Verify Code (Locked)';
        if (otpResendButton) {
            otpResendButton.disabled = true;
            otpResendButton.textContent = 'Resend Code (Locked)';
        }

        function renderLock() {
            const seconds = Math.max(0, Math.ceil((otpLockedUntil - Date.now()) / 1000));
            const time = formatOtpTime(seconds);
            if (otpTimer) otpTimer.textContent = seconds > 0 ? `Try again in ${time}` : 'You may request a new code.';
            if (seconds > 0) {
                setValidation(emailAddress, emailError, `Email verification locked. Try again in ${time}.`);
                if (sendOtpButton) {
                    sendOtpButton.textContent = `Locked (${time})`;
                    sendOtpButton.disabled = true;
                }
                return;
            }
            window.clearInterval(otpLockClock);
            validateEmail();
            if (sendOtpButton) sendOtpButton.textContent = 'Verify Email';
            syncVerifyEmailButton();
            if (otpResendButton) {
                otpResendButton.disabled = false;
                otpResendButton.textContent = 'Resend Code';
            }
        }

        renderLock();
        otpLockClock = window.setInterval(renderLock, 1000);
    }

    function openOtpModal(isSending = false) {
        panel.classList.add('is-otp-locked');
        document.body.classList.add('walk-in-otp-lock');
        if (dialog) dialog.inert = true;
        otpModal?.classList.toggle('is-sending', isSending);
        otpModal?.setAttribute('aria-busy', isSending ? 'true' : 'false');
        otpModal?.classList.add('is-open');
        otpModal?.setAttribute('aria-hidden', 'false');
        if (!isSending) otpCode?.focus({ preventScroll: true });
    }

    function showOtpEntry() {
        otpModal?.classList.remove('is-sending');
        otpModal?.setAttribute('aria-busy', 'false');
        if (Date.now() >= otpLockedUntil) {
            if (otpCode) otpCode.disabled = false;
            if (otpVerifyButton) otpVerifyButton.disabled = false;
        }
        window.requestAnimationFrame(function () {
            otpCode?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            otpCode?.focus({ preventScroll: true });
        });
    }

    async function sendEmailOtp() {
        if (!validateEmail()) return;
        if (Date.now() < otpLockedUntil) {
            openOtpModal(false);
            startOtpLockClock(Math.ceil((otpLockedUntil - Date.now()) / 1000));
            return;
        }
        sendOtpButton?.setAttribute('disabled', 'disabled');
        openOtpModal(true);
        if (!(await checkExistingContact()) || emailIsVerified) {
            closeOtpModal();
            return;
        }
        const data = new FormData();
        data.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
        data.append('client_name', clientName.value.trim());
        data.append('email', emailAddress.value.trim());
        data.append('contact_no', contactNumber.value);
        try {
            const response = await fetch('/codesamplecaps/ADMIN/sidebar/projects/php/send_walk_in_email_otp.php', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: data,
            });
            const result = await response.json();
            if (!result.success) {
                closeOtpModal();
                if (result.locked) {
                    startOtpLockClock(Number(result.retry_after) || 900);
                    return;
                }
                const errorMessage = result.message || 'Email cannot be sent right now.';
                if (result.field === 'name') setValidation(clientName, nameError, errorMessage);
                else if (result.field === 'phone') setValidation(contactNumber, contactError, errorMessage);
                else if (result.field === 'both') {
                    setValidation(emailAddress, emailError, errorMessage);
                    setValidation(contactNumber, contactError, errorMessage);
                } else setValidation(emailAddress, emailError, errorMessage);
                return;
            }
            if (otpEmail) otpEmail.textContent = emailAddress.value.trim();
            startOtpClock(result.expires_in, Number(result.resend_after) || 60);
            showOtpEntry();
        } catch (error) {
            closeOtpModal();
            setValidation(emailAddress, emailError, 'Email cannot be sent right now.');
        } finally {
            syncVerifyEmailButton();
        }
    }

    async function resendEmailOtp() {
        if (!otpResendButton || otpResendButton.disabled) return;
        if (otpError) {
            otpError.textContent = '';
            otpError.classList.remove('is-success');
        }
        openOtpModal(true);
        const data = new FormData();
        data.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
        data.append('client_name', clientName.value.trim());
        data.append('email', emailAddress.value.trim());
        data.append('contact_no', contactNumber.value);
        try {
            const response = await fetch('/codesamplecaps/ADMIN/sidebar/projects/php/send_walk_in_email_otp.php', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: data,
            });
            const result = await response.json();
            showOtpEntry();
            if (!result.success) {
                if (otpError) {
                    otpError.classList.remove('is-success');
                    otpError.textContent = result.message || 'Code cannot be sent right now.';
                }
                return;
            }
            if (otpError) {
                otpError.textContent = 'A new code was sent.';
                otpError.classList.add('is-success');
            }
            if (otpCode) otpCode.value = '';
            startOtpClock(result.expires_in, Number(result.resend_after) || 60);
        } catch (error) {
            showOtpEntry();
            if (otpError) {
                otpError.classList.remove('is-success');
                otpError.textContent = 'No internet. Check the connection and try again.';
            }
        }
    }

    async function verifyEmailOtp() {
        const code = String(otpCode?.value || '').replace(/\D/g, '').slice(0, 6);
        if (otpCode) otpCode.value = code;
        if (!/^\d{6}$/.test(code)) {
            if (otpError) otpError.textContent = 'Enter the complete 6-digit code.';
            otpCode?.classList.add('is-invalid');
            otpCode?.focus({ preventScroll: true });
            return;
        }
        otpCode?.classList.remove('is-invalid');
        otpVerifyButton?.setAttribute('disabled', 'disabled');
        const data = new FormData();
        data.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
        data.append('email', emailAddress.value.trim());
        data.append('otp', code);
        let lockRetryAfter = 0;
        try {
            const response = await fetch('/codesamplecaps/ADMIN/sidebar/projects/php/verify_walk_in_email_otp.php', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: data,
            });
            const result = await response.json();
            if (!result.success) {
                if (otpError) otpError.textContent = result.message || 'Incorrect code.';
                if (result.locked) {
                    lockRetryAfter = Number(result.retry_after) || 900;
                } else {
                    otpCode?.classList.add('is-invalid');
                    otpCode?.focus({ preventScroll: true });
                }
                return;
            }
            setEmailVerified(true);
            closeOtpModal();
        } catch (error) {
            if (otpError) otpError.textContent = 'Unable to verify the code. Try again.';
            otpCode?.classList.add('is-invalid');
            otpCode?.focus({ preventScroll: true });
        } finally {
            otpVerifyButton?.removeAttribute('disabled');
            if (lockRetryAfter > 0) startOtpLockClock(lockRetryAfter);
        }
    }

    async function checkExistingContact() {
        const name = String(clientName?.value || '').trim();
        const email = String(emailAddress?.value || '').trim();
        const phone = String(contactNumber?.value || '');
        const nameIsValid = /^[\p{L}\p{M} .'\u2019-]+$/u.test(name) && (name.match(/\p{L}/gu) || []).length >= 2;
        const emailIsValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        const phoneIsValid = /^09\d{9}$/.test(phone);
        const requestId = ++contactCheckId;
        if (!nameIsValid || !emailIsValid || !phoneIsValid) {
            contactCanVerifyEmail = false;
            syncVerifyEmailButton();
            return false;
        }

        const data = new FormData();
        data.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
        data.append('client_name', clientName.value.trim());
        data.append('email', emailAddress.value.trim());
        data.append('contact_no', contactNumber.value);

        try {
            const response = await fetch('/codesamplecaps/ADMIN/sidebar/projects/php/check_walk_in_contact.php', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: data,
            });
            const result = await response.json();
            if (requestId !== contactCheckId) return false;
            const message = result.valid ? '' : (result.message || 'Invalid client details.');
            if (nameIsValid) setValidation(clientName, nameError, '');
            if (emailIsValid && !emailIsVerified) setEmailWarning('Verify this email to continue.');
            if (phoneIsValid) setValidation(contactNumber, contactError, '');
            if (!result.valid) {
                contactCanVerifyEmail = false;
                if (result.field === 'name') setValidation(clientName, nameError, message);
                if (result.field === 'email' || result.field === 'both') setValidation(emailAddress, emailError, message);
                if (result.field === 'phone' || result.field === 'both') setValidation(contactNumber, contactError, message);
                if (!result.field) setValidation(emailAddress, emailError, message);
                setEmailVerified(false);
                syncVerifyEmailButton();
            } else if (result.email_verified) {
                contactCanVerifyEmail = true;
                if (result.status === 'existing_client') {
                    setContactInfo(contactError, 'Existing client found. You may create another inquiry.');
                    setEmailVerified(true, 'Existing client found.');
                } else {
                    setEmailVerified(true, 'Email verified');
                }
            } else if (result.complete === false) {
                contactCanVerifyEmail = false;
                if (result.status === 'existing_client_detected') {
                    // Red muna habang kulang o hindi tugma ang buong client details.
                    if (result.field === 'phone' || result.field === 'both') {
                        setValidation(contactNumber, contactError, 'Number already used.');
                    }
                    if (result.field === 'email' || result.field === 'both') {
                        setValidation(emailAddress, emailError, 'Email already used.');
                    }
                }
                if (!emailIsVerified) {
                    setEmailVerified(false, result.status === 'existing_client_detected' ? 'Existing client found.' : 'Email not verified');
                }
                syncVerifyEmailButton();
            } else if (result.status === 'existing_client') {
                contactCanVerifyEmail = result.can_verify_email !== false;
                setContactInfo(contactError, 'Existing client found. You may create another inquiry.');
                setEmailVerified(false, 'Existing client found. Verify email.');
            } else if (result.status === 'existing_inquiry') {
                contactCanVerifyEmail = result.can_verify_email !== false;
                setContactInfo(contactError, 'Existing inquiry found. You may create another inquiry.');
                if (!emailIsVerified) setEmailVerified(false, 'Existing inquiry found. Verify email.');
            } else if (!emailIsVerified) {
                contactCanVerifyEmail = result.can_verify_email !== false;
                setEmailVerified(false);
            }
            return Boolean(result.valid && (result.complete !== false || result.can_verify_email));
        } catch (error) {
            if (requestId !== contactCheckId) return false;
            contactCanVerifyEmail = false;
            setValidation(emailAddress, emailError, 'Unable to check the client details. Try again.');
            return false;
        }
    }

    function validateDescription() {
        const value = String(description?.value || '').trim();
        const letters = value.match(/\p{L}/gu) || [];
        return setValidation(description, descriptionError, value.length >= 10 && value.length <= 2000 && letters.length >= 5
            ? ''
            : 'Enter at least 10 characters with real words.');
    }

    function validateSiteAddress() {
        const value = String(siteAddress?.value || '').trim();
        const allowed = /^[\p{L}\p{M}\p{N}\s#.,'\u2019\/()&\-]+$/u.test(value);
        const letters = value.match(/\p{L}/gu) || [];
        return setValidation(siteAddress, siteAddressError, value.length >= 5 && value.length <= 500 && letters.length >= 3 && allowed
            ? ''
            : 'Enter a clear site address with at least 3 letters.');
    }

    function validateService() {
        return setValidation(serviceSelect, serviceError, serviceSelect?.value ? '' : 'Select a service category.');
    }

    function validateBudget() {
        if (!budgetSelect?.value) {
            resetValidation(budgetSelect, budgetError);
            return true;
        }
        return setValidation(budgetSelect, budgetError, '');
    }

    function validateDate() {
        syncWalkInDateWindow();
        const value = String(dateInput?.value || '');
        const valid = value !== '' && value >= dateInput.min && value <= dateInput.max;
        return setValidation(dateInput, dateError, valid ? '' : 'Select an allowed inspection date.');
    }

    function validateLocation(input, options, errorElement, label) {
        return setValidation(input, errorElement, isSelectedLocation(input, options) ? '' : `Select a valid ${label} from the list.`);
    }

    function syncWalkInSaveAction() {
        const hasConfirmedEngineer = Number(engineerSelect?.value || 0) > 0;
        const nextAction = hasConfirmedEngineer ? 'confirmed' : 'pending';
        if (nextActionInput) nextActionInput.value = nextAction;
        if (submitActionButton) {
            submitActionButton.value = nextAction;
            submitActionButton.textContent = hasConfirmedEngineer
                ? 'Save Confirmed Inspection'
                : 'Save as Pending';
        }
        if (engineerHelp) {
            engineerHelp.textContent = hasConfirmedEngineer
                ? 'Use this only after the Engineer confirms the date.'
                : 'Leave Unassigned while waiting for Engineer confirmation.';
        }
    }

    function formatWalkInDate(value) {
        const parts = String(value || '').split('-').map(Number);
        if (parts.length !== 3 || !parts[0] || !parts[1] || !parts[2]) return value;
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return `${months[parts[1] - 1] || ''} ${parts[2]} ${parts[0]}`.trim();
    }

    function guideToEmailVerification() {
        setValidation(emailAddress, emailError, 'Verify the client email before saving.');
        setEmailVerified(false, 'Email verification required.');
        emailAddress?.closest('.input-group')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(function () {
            sendOtpButton?.focus({ preventScroll: true });
        }, 350);
    }

    function syncOtherService() {
        const shouldShow = serviceSelect?.value === 'Other Service';
        if (otherServiceWrap) otherServiceWrap.hidden = !shouldShow;
        if (otherService) {
            otherService.disabled = !shouldShow;
            otherService.required = shouldShow;
            if (!shouldShow) {
                otherService.value = '';
                setValidation(otherService, otherError, '');
            }
        }
    }

    function validateOtherService() {
        if (serviceSelect?.value !== 'Other Service') return true;
        const value = String(otherService?.value || '').trim();
        const letters = value.match(/\p{L}/gu) || [];
        return setValidation(otherService, otherError, value.length >= 4 && letters.length >= 4 ? '' : 'Enter the other service details.');
    }

    function getDraftData() {
        const data = {};
        new FormData(form).forEach(function (value, key) {
            if (!['csrf_token', 'next_action'].includes(key)) data[key] = String(value);
        });
        return data;
    }

    function hasDraftContent() {
        return Object.entries(getDraftData()).some(function ([key, value]) {
            const cleanValue = value.trim();
            return cleanValue !== '' && !(key === 'contact_no' && cleanValue === '09');
        });
    }

    function enableHistoryGuard() {
        if (historyGuardReady) return;
        window.history.replaceState({ ...(window.history.state || {}), walkInGuardBase: true }, '', window.location.href);
        window.history.pushState({ walkInGuardTop: true }, '', window.location.href);
        historyGuardReady = true;
    }

    function saveDraft() {
        try {
            if (hasDraftContent()) {
                window.sessionStorage.setItem(draftKey, JSON.stringify(getDraftData()));
                if (panel.classList.contains('is-open')) {
                    window.sessionStorage.setItem(panelStateKey, '1');
                }
                enableHistoryGuard();
            } else {
                window.sessionStorage.removeItem(draftKey);
            }
        } catch (error) {
        }
    }

    function clearSavedDraft() {
        try {
            window.sessionStorage.removeItem(draftKey);
            window.sessionStorage.removeItem(panelStateKey);
            window.localStorage.removeItem(draftKey);
        } catch (error) {
        }
    }

    function restoreDraft() {
        if (form.dataset.hasServerInput === '1') return false;
        let draft = null;
        try {
            draft = JSON.parse(window.sessionStorage.getItem(draftKey) || 'null');
        } catch (error) {
            draft = null;
        }
        if (!draft || typeof draft !== 'object') return false;

        Array.from(form.elements).forEach(function (field) {
            if (!field.name || field.name === 'csrf_token' || !(field.name in draft)) return;
            if (['region', 'location_area', 'city_municipality', 'barangay'].includes(field.name)) {
                field.dataset.initialValue = draft[field.name];
            } else {
                field.value = draft[field.name];
            }
        });
        keepContactPrefix();
        syncOtherService();
        syncWalkInSaveAction();
        enableHistoryGuard();
        return true;
    }

    function showConfirm(options, action) {
        if (!confirmModal) return;
        pendingConfirmAction = action;
        if (otpModal?.classList.contains('is-open')) otpModal.inert = true;
        if (confirmTitle) confirmTitle.textContent = options.title;
        if (confirmMessage) confirmMessage.textContent = options.message;
        if (confirmAccept) {
            confirmAccept.textContent = options.accept;
            confirmAccept.classList.toggle('btn-primary', options.tone === 'primary');
            confirmAccept.classList.toggle('btn-danger', options.tone !== 'primary');
        }
        if (confirmCancel) confirmCancel.textContent = options.cancel || 'Keep Editing';
        confirmModal.classList.add('is-open');
        confirmModal.setAttribute('aria-hidden', 'false');
        confirmCancel?.focus({ preventScroll: true });
    }

    function hideConfirm() {
        confirmModal?.classList.remove('is-open');
        confirmModal?.setAttribute('aria-hidden', 'true');
        if (otpModal?.classList.contains('is-open')) {
            otpModal.inert = false;
            otpCode?.focus({ preventScroll: true });
        }
        pendingConfirmAction = null;
    }

    function askToCloseOtp() {
        if (!otpModal?.classList.contains('is-open') || otpModal.classList.contains('is-sending')) return;
        showConfirm({
            title: 'Cancel email verification?',
            message: 'You will return to the Walk-in Inquiry form.',
            accept: 'Go Back',
            cancel: 'Continue Verification',
        }, closeOtpModal);
    }

    function askToDiscard(action) {
        if (!hasDraftContent()) {
            action();
            return;
        }
        showConfirm({
            title: 'Clear this form?',
            message: 'All entered details and the saved draft will be removed.',
            accept: 'Clear Form',
            cancel: 'Keep Editing',
        }, action);
    }

    function askToLeave(action) {
        if (!hasDraftContent()) {
            action();
            return;
        }
        saveDraft();
        showConfirm({
            title: 'Leave and keep draft?',
            message: 'Your entries will stay in this browser until you save or clear the form.',
            accept: 'Leave & Keep Draft',
            cancel: 'Continue Editing',
            tone: 'primary',
        }, action);
    }

    function clearForm() {
        const clearVerificationData = new FormData();
        clearVerificationData.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
        fetch('/codesamplecaps/ADMIN/sidebar/projects/php/clear_walk_in_email_verification.php', {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: clearVerificationData,
        }).catch(function () {
            // Mawawala rin ito kapag nag-expire ang server session.
        });
        form.reset();
        keepContactPrefix();
        syncWalkInSaveAction();
        form.dataset.hasServerInput = '0';
        [regionSelect, areaSelect, citySelect, barangaySelect].forEach(function (field) {
            if (field) field.dataset.initialValue = '';
        });
        clearLocation(regionSelect, 'Search or select region');
        regionSelect.disabled = !locationData;
        syncAreas(false);
        syncOtherService();
        form.querySelectorAll('.is-invalid-live, .is-valid-live').forEach(function (field) {
            field.classList.remove('is-invalid-live', 'is-valid-live');
            field.setCustomValidity('');
            field.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('.walk-in-field-error').forEach(function (field) {
            if (field) field.textContent = '';
        });
        clearSavedDraft();
        emailEdited = false;
        contactEdited = false;
        contactCanVerifyEmail = false;
        setEmailVerified(false);
        clientName?.focus({ preventScroll: true });
    }

    function openPanel() {
        syncWalkInDateWindow();
        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('project-modal-open');
        try {
            window.sessionStorage.setItem(panelStateKey, '1');
        } catch (error) {
        }
        loadLocations();
        [150, 700].forEach(function (delay) {
            window.setTimeout(function () {
                validateAutofilledIdentity(clientName);
                validateAutofilledIdentity(contactNumber);
            }, delay);
        });
        if (emailAddress?.value || /^09\d{9}$/.test(contactNumber?.value || '')) {
            checkExistingContact();
        }
    }

    function closePanel() {
        if (isSubmitting) return;
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('project-modal-open');
        try {
            window.sessionStorage.removeItem(panelStateKey);
        } catch (error) {
        }
    }

    function showSaveLoading(action) {
        const isConfirmed = action === 'confirmed';
        if (saveTitle) saveTitle.textContent = isConfirmed ? 'Saving inspection...' : 'Saving pending inquiry...';
        if (saveMessage) {
            saveMessage.textContent = isConfirmed
                ? 'Sending schedule emails. Please wait.'
                : 'Please wait. Do not close this page.';
        }
        panel.classList.add('is-save-locked');
        saveModal?.classList.add('is-open');
        saveModal?.setAttribute('aria-hidden', 'false');
        document.body.classList.add('walk-in-save-lock');
        saveTitle?.focus({ preventScroll: true });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-walk-in-inquiry-open]')) {
            event.preventDefault();
            restoreDraft();
            openPanel();
            return;
        }
        if (event.target.closest('[data-walk-in-inquiry-close]')) {
            event.preventDefault();
            if (isSubmitting) return;
            if (otpModal?.classList.contains('is-open')) {
                otpCode?.focus({ preventScroll: true });
                return;
            }
            askToLeave(closePanel);
            return;
        }
        if (event.target.closest('[data-walk-in-clear]')) {
            event.preventDefault();
            if (isSubmitting) return;
            askToDiscard(clearForm);
        }
    });

    panel.addEventListener('click', function (event) {
        if (isSubmitting) return;
        if (dialog && !dialog.contains(event.target)) askToLeave(closePanel);
    });

    confirmCancel?.addEventListener('click', hideConfirm);
    confirmAccept?.addEventListener('click', function () {
        const action = pendingConfirmAction;
        hideConfirm();
        if (action) action();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (isSubmitting) {
            event.preventDefault();
            return;
        }
        if (otpModal?.classList.contains('is-open')) {
            event.preventDefault();
            otpCode?.focus({ preventScroll: true });
            return;
        }
        if (confirmModal?.classList.contains('is-open')) {
            hideConfirm();
            return;
        }
        if (panel.classList.contains('is-open')) askToLeave(closePanel);
    });

    contactNumber?.addEventListener('input', function () {
        contactEdited = true;
        contactCanVerifyEmail = false;
        syncVerifyEmailButton();
        keepContactPrefix();
        keepContactCursorAfterPrefix();
        validateContact();
        window.clearTimeout(contactCheckTimer);
        if (/^09\d{9}$/.test(contactNumber.value)) {
            checkExistingContact();
        } else {
            contactCheckTimer = window.setTimeout(checkExistingContact, 450);
        }
    });
    contactNumber?.addEventListener('focus', function () {
        keepContactPrefix();
        window.setTimeout(keepContactCursorAfterPrefix, 0);
    });
    contactNumber?.addEventListener('click', keepContactCursorAfterPrefix);
    contactNumber?.addEventListener('keydown', function (event) {
        const selectionStart = contactNumber.selectionStart ?? 0;
        const selectionEnd = contactNumber.selectionEnd ?? selectionStart;
        if (event.key === 'Backspace' && selectionStart <= 2 && selectionEnd <= 2) {
            event.preventDefault();
        }
    });
    emailAddress?.addEventListener('input', function () {
        emailEdited = true;
        contactCanVerifyEmail = false;
        setEmailVerified(false);
        resetValidation(emailAddress, emailError);
        syncVerifyEmailButton();
        window.clearTimeout(contactCheckTimer);
    });
    sendOtpButton?.addEventListener('click', function () {
        if (emailIsVerified) {
            unlockVerifiedEmail();
            return;
        }

        const hasValidName = validateName();
        const hasValidContact = validateContact();
        if (!hasValidName || !hasValidContact) {
            (hasValidName ? contactNumber : clientName)?.focus({ preventScroll: true });
            return;
        }

        sendEmailOtp();
    });
    otpVerifyButton?.addEventListener('click', verifyEmailOtp);
    otpResendButton?.addEventListener('click', resendEmailOtp);
    otpModal?.addEventListener('click', function (event) {
        if (event.target === otpModal || event.target.closest('[data-walk-in-otp-close]')) {
            askToCloseOtp();
        }
    });
    otpCode?.addEventListener('input', function () {
        otpCode.value = otpCode.value.replace(/\D/g, '').slice(0, 6);
        otpCode.classList.remove('is-invalid');
        if (otpError) {
            otpError.textContent = '';
            otpError.classList.remove('is-success');
        }
    });
    otpModal?.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target === otpCode) {
            event.preventDefault();
            verifyEmailOtp();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(otpModal.querySelectorAll('button:not(:disabled), input:not(:disabled)'));
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    emailAddress?.addEventListener('blur', function () {
        if (emailEdited) validateEmail();
        checkExistingContact();
    });
    form.addEventListener('animationstart', function (event) {
        if (event.animationName !== 'walk-in-autofill-detected') return;
        if (event.target !== clientName && event.target !== contactNumber) return;
        validateAutofilledIdentity(event.target);
        window.clearTimeout(contactCheckTimer);
        contactCheckTimer = window.setTimeout(checkExistingContact, 450);
    });
    contactNumber?.addEventListener('blur', function () {
        if (contactEdited) validateContact();
        checkExistingContact();
    });
    clientName?.addEventListener('input', function () {
        contactCanVerifyEmail = false;
        syncVerifyEmailButton();
        validateName();
        window.clearTimeout(contactCheckTimer);
        contactCheckTimer = window.setTimeout(checkExistingContact, 450);
    });
    clientName?.addEventListener('blur', checkExistingContact);
    companyName?.addEventListener('input', validateCompany);
    companyName?.addEventListener('blur', validateCompany);
    siteAddress?.addEventListener('input', validateSiteAddress);
    siteAddress?.addEventListener('blur', validateSiteAddress);
    description?.addEventListener('input', validateDescription);
    description?.addEventListener('blur', validateDescription);
    otherService?.addEventListener('input', validateOtherService);
    serviceSelect?.addEventListener('change', function () {
        syncOtherService();
        validateService();
    });
    budgetSelect?.addEventListener('change', validateBudget);
    dateInput?.addEventListener('input', validateDate);
    dateInput?.addEventListener('change', validateDate);
    dateInput?.addEventListener('blur', validateDate);
    engineerSelect?.addEventListener('change', syncWalkInSaveAction);
    bindLocationCombobox(regionSelect, getRegionOptions, function () { syncAreas(false); }, regionError, 'region');
    bindLocationCombobox(areaSelect, getAreaOptions, function () { syncCities(false); }, areaError, 'province or area');
    bindLocationCombobox(citySelect, getCityOptions, function () { syncBarangays(false); }, cityError, 'city or municipality');
    bindLocationCombobox(barangaySelect, getBarangayOptions, function () {}, barangayError, 'barangay');
    regionSelect?.addEventListener('change', function () {
        syncAreas(false);
        validateLocation(regionSelect, getRegionOptions(), regionError, 'region');
    });
    areaSelect?.addEventListener('change', function () {
        syncCities(false);
        validateLocation(areaSelect, getAreaOptions(), areaError, 'province or area');
    });
    citySelect?.addEventListener('change', function () {
        syncBarangays(false);
        validateLocation(citySelect, getCityOptions(), cityError, 'city or municipality');
    });
    barangaySelect?.addEventListener('change', function () {
        validateLocation(barangaySelect, getBarangayOptions(), barangayError, 'barangay');
    });
    form.addEventListener('input', saveDraft);
    form.addEventListener('change', saveDraft);

    form.addEventListener('submit', async function (event) {
        if (saveConfirmed) return;
        event.preventDefault();
        const nextAction = Number(engineerSelect?.value || 0) > 0 ? 'confirmed' : 'pending';
        if (nextActionInput) nextActionInput.value = nextAction;
        const nameIsValid = validateName();
        const companyIsValid = validateCompany();
        const emailIsValid = validateEmail();
        const contactIsValid = validateContact();
        const serviceIsValid = validateService();
        const budgetIsValid = validateBudget();
        const siteAddressIsValid = validateSiteAddress();
        const descriptionIsValid = validateDescription();
        const dateIsValid = validateDate();
        const otherServiceIsValid = validateOtherService();
        const locationsAreValid = [
            [regionSelect, getRegionOptions(), regionError, 'region'],
            [areaSelect, getAreaOptions(), areaError, 'province or area'],
            [citySelect, getCityOptions(), cityError, 'city or municipality'],
            [barangaySelect, getBarangayOptions(), barangayError, 'barangay'],
        ].map(function (entry) {
            return validateLocation(entry[0], entry[1], entry[2], entry[3]);
        }).every(Boolean);
        const valid = nameIsValid && companyIsValid && emailIsValid && contactIsValid
            && serviceIsValid && budgetIsValid && siteAddressIsValid && descriptionIsValid
            && dateIsValid && otherServiceIsValid && locationsAreValid;
        if (!valid || !form.checkValidity()) {
            form.reportValidity();
            return;
        }
        if (!(await checkExistingContact())) {
            form.reportValidity();
            return;
        }
        if (!emailIsVerified) {
            guideToEmailVerification();
            return;
        }
        const engineerName = engineerSelect?.selectedOptions[0]?.textContent?.trim() || 'Unassigned';
        const displayDate = formatWalkInDate(dateInput.value);
        const isConfirmedInspection = nextAction === 'confirmed';
        showConfirm({
            title: isConfirmedInspection ? 'Save confirmed inspection?' : 'Save as pending?',
            message: isConfirmedInspection
                ? engineerName + ' - ' + displayDate + '. Client and Engineer will be notified by email.'
                : 'This inquiry will stay Pending Review until an Engineer confirms the date.',
            accept: isConfirmedInspection ? 'Confirm Inspection' : 'Save as Pending',
            cancel: 'Back',
            tone: 'primary',
        }, function () {
            saveConfirmed = true;
            isSubmitting = true;
            showSaveLoading(nextAction);
            try {
                window.sessionStorage.removeItem(panelStateKey);
            } catch (error) {
            }
            form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                button.setAttribute('disabled', 'disabled');
            });
            form.requestSubmit();
        });
    });

    // Alisin ang lumang draft na dating tumatagal kahit sarado na ang browser.
    try {
        window.localStorage.removeItem(draftKey);
    } catch (error) {
    }
    syncOtherService();
    syncWalkInSaveAction();
    keepContactPrefix();
    setEmailVerified(false);
    if (contactNumber?.value && contactNumber.value !== '09') validateContact();
    if (emailAddress?.value) validateEmail();
    if (companyName?.value) validateCompany();
    if (serviceSelect?.value) validateService();
    if (budgetSelect?.value) validateBudget();
    if (siteAddress?.value) validateSiteAddress();
    if (description?.value) validateDescription();
    if (clientName?.value) validateName();
    syncWalkInDateWindow();
    if (dateInput?.value) validateDate();

    if (panel.classList.contains('is-open')) {
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('project-modal-open');
        try {
            window.sessionStorage.setItem(panelStateKey, '1');
        } catch (error) {
        }
        loadLocations();
        if (emailAddress?.value || /^09\d{9}$/.test(contactNumber?.value || '')) {
            checkExistingContact();
        }
    } else {
        let reopenAfterRefresh = false;
        try {
            reopenAfterRefresh = window.sessionStorage.getItem(panelStateKey) === '1';
        } catch (error) {
        }
        if (reopenAfterRefresh) {
            restoreDraft();
            openPanel();
        }
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-walk-in-combobox]')) closeOtherComboboxes(null);
    });

    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[href]');
        if (!link || !panel.classList.contains('is-open') || !hasDraftContent()) return;
        if (link.target === '_blank' || link.hasAttribute('download')) return;
        event.preventDefault();
        askToLeave(function () {
            isSubmitting = true;
            window.location.assign(link.href);
        });
    }, true);

    window.addEventListener('popstate', function () {
        if (ignoreNextPopstate) {
            ignoreNextPopstate = false;
            return;
        }
        if (!historyGuardReady) return;
        if (!hasDraftContent()) {
            historyGuardReady = false;
            window.history.back();
            return;
        }
        ignoreNextPopstate = true;
        window.history.forward();
        showConfirm({
            title: 'Leave and keep draft?',
            message: 'Your entries will stay in this browser until you save or clear the form.',
            accept: 'Leave & Keep Draft',
            cancel: 'Continue Editing',
            tone: 'primary',
        }, function () {
            historyGuardReady = false;
            isSubmitting = true;
            window.history.go(-2);
        });
    });
}

function initProjectCreateMenu() {
    const menu = document.querySelector('[data-project-create-menu]');
    const toggle = menu?.querySelector('[data-project-create-menu-toggle]');
    const list = menu?.querySelector('[data-project-create-menu-list]');

    if (!menu || !toggle || !list) {
        return;
    }

    function closeMenu() {
        list.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    function openMenu() {
        list.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
    }

    toggle.addEventListener('click', function (event) {
        event.preventDefault();
        if (list.hidden) {
            openMenu();
        } else {
            closeMenu();
        }
    });

    document.addEventListener('click', function (event) {
        if (!menu.contains(event.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });

    list.addEventListener('click', function (event) {
        if (event.target.closest('[data-create-project-open]')) {
            closeMenu();
        }
    });
}

function initCreateProjectTextValidation() {
    const createProjectForm = document.getElementById('create-project-form');

    if (!createProjectForm) {
        return;
    }

    const textRules = [
        {
            name: 'project_name',
            message: 'Project Title needs real text, not only numbers or symbols.',
            isValid: function (value) {
                return /\p{L}{2,}/u.test(value);
            },
        },
        {
            name: 'contact_person',
            message: 'Client Contact Person should use letters, spaces, dot, apostrophe, or hyphen only.',
            isValid: function (value) {
                return /^[\p{L} .'-]+$/u.test(value) && /\p{L}{2,}/u.test(value);
            },
        },
        {
            name: 'project_site',
            message: 'Project Site needs real text, not only numbers or symbols.',
            isValid: function (value) {
                return /\p{L}{2,}/u.test(value);
            },
        },
        {
            name: 'project_address',
            message: 'Address needs real text, not only numbers or symbols.',
            isValid: function (value) {
                return /\p{L}{2,}/u.test(value);
            },
        },
        {
            name: 'description',
            message: 'Comment needs real text if you add one.',
            isValid: function (value) {
                return value === '' || /\p{L}{2,}/u.test(value);
            },
        },
    ];

    function validateField(field, rule, shouldShow) {
        const value = String(field.value || '').trim();
        const isEmpty = value === '';
        const isRequired = field.hasAttribute('required') || field.getAttribute('data-required-when-active') === 'true';
        const isInvalid = (!isEmpty || isRequired) && !rule.isValid(value);

        field.setCustomValidity(isInvalid ? rule.message : '');
        field.classList.toggle('is-invalid-live', shouldShow && isInvalid);
    }

    textRules.forEach(function (rule) {
        const field = createProjectForm.elements.namedItem(rule.name);
        if (!field) {
            return;
        }

        field.addEventListener('input', function () {
            validateField(field, rule, true);
        });

        field.addEventListener('blur', function () {
            validateField(field, rule, true);
        });

        validateField(field, rule, false);
    });

    createProjectForm.addEventListener('submit', function () {
        textRules.forEach(function (rule) {
            const field = createProjectForm.elements.namedItem(rule.name);
            if (field) {
                validateField(field, rule, true);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initProjectSearchUI);
document.addEventListener('DOMContentLoaded', initArchiveTabs);
document.addEventListener('DOMContentLoaded', initCreateProjectForm);
document.addEventListener('DOMContentLoaded', initCreateProjectClientAutofill);
document.addEventListener('DOMContentLoaded', initProjectAdditionalInfoRows);
document.addEventListener('DOMContentLoaded', initCreateProjectDraft);
document.addEventListener('DOMContentLoaded', initCurrencyInputs);
document.addEventListener('DOMContentLoaded', initEngineerAssignmentPicker);
document.addEventListener('DOMContentLoaded', initFieldTipToggle);
document.addEventListener('DOMContentLoaded', initProjectsScrollRestore);
document.addEventListener('DOMContentLoaded', initProjectConfirmForms);
document.addEventListener('DOMContentLoaded', initProjectCreateMenu);
document.addEventListener('DOMContentLoaded', initCreateProjectPanel);
document.addEventListener('DOMContentLoaded', initWalkInInquiryPanel);
document.addEventListener('DOMContentLoaded', initCreateProjectTextValidation);
