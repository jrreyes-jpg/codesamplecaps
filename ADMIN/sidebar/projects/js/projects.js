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
const poDateField = createProjectForm.elements.namedItem('start_date');
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

    if (!clientField) {
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
        start_date: '',
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
            start_date: String(createProjectForm.elements.namedItem('start_date')?.value || ''),
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
document.addEventListener('DOMContentLoaded', initCreateProjectTextValidation);
