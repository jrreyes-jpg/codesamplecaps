document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileToggle = document.getElementById('sidebarMobileToggle');
    const desktopToggle = document.getElementById('sidebarToggle');
    const toggleIcon = document.getElementById('toggleIcon');
    const sectionLinks = document.querySelectorAll('[data-section-link]');
    const tabButtons = document.querySelectorAll('[data-tab-target]');
    const jumpButtons = document.querySelectorAll('[data-jump-section], [data-jump-tab]');
    const sectionPanels = document.querySelectorAll('.tab-content');
    const hasDashboardSections = sectionPanels.length > 0;
    const projectSearchRoot = document.querySelector('[data-client-project-search]');
    const projectSearchInput = document.getElementById('client-project-search');
    const projectSearchClear = document.getElementById('client-project-search-clear');
    const projectSearchDropdown = document.getElementById('client-project-search-dropdown');
    const projectSearchHint = document.getElementById('client-project-search-hint');
    const projectSearchCount = document.getElementById('client-project-search-count');
    const projectSearchEmpty = document.getElementById('client-project-search-empty');
    const projectCards = Array.from(document.querySelectorAll('[data-client-project-card]'));
    const notificationRoot = document.querySelector('[data-notification-root]');
    const notificationToggle = document.getElementById('topbarNotificationToggle');
    const notificationPanel = document.getElementById('topbarNotificationDropdown');
    const profileRoot = document.querySelector('[data-profile-root]');
    const profileToggle = document.getElementById('topbarProfileToggle');
    const profilePanel = document.getElementById('topbarProfileDropdown');
    const phDate = document.querySelector('[data-ph-date]');
    const phTime = document.querySelector('[data-ph-time]');
    const collapsedStorageKey = 'edgeClientSidebarCollapsed';
    const mobileMedia = window.matchMedia('(max-width: 992px)');
    const defaultSectionId = 'overview-section';
    let projectSearchDebounceId = null;
    let activeProjectSuggestionIndex = -1;

    if (!sidebar) {
        return;
    }

    const setMobileOpen = function (isOpen) {
        sidebar.classList.toggle('mobile-open', isOpen);
        body.classList.toggle('sidebar-mobile-open', isOpen);

        if (overlay) {
            overlay.classList.toggle('active', isOpen);
        }

        if (mobileToggle) {
            mobileToggle.classList.toggle('active', isOpen);
            mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    };

    const updateDesktopToggleState = function (isCollapsed) {
        if (desktopToggle) {
            desktopToggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            desktopToggle.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
        }

        if (toggleIcon) {
            toggleIcon.classList.toggle('is-collapsed', isCollapsed);
        }
    };

    const setDesktopCollapsed = function (isCollapsed) {
        if (mobileMedia.matches) {
            body.classList.remove('sidebar-collapsed');
            sidebar.classList.remove('shrink');
            updateDesktopToggleState(false);
            return;
        }

        body.classList.toggle('sidebar-collapsed', isCollapsed);
        sidebar.classList.toggle('shrink', isCollapsed);
        window.localStorage.setItem(collapsedStorageKey, isCollapsed ? '1' : '0');
        updateDesktopToggleState(isCollapsed);
    };

    const syncNavigationState = function (activeSectionId) {
        if (!hasDashboardSections) {
            return;
        }

        sectionLinks.forEach(function (link) {
            const isActive = link.dataset.sectionLink === activeSectionId;
            link.classList.toggle('active-link', isActive);
            link.classList.toggle('active', isActive);
            link.setAttribute('aria-current', isActive ? 'page' : 'false');
        });

        tabButtons.forEach(function (button) {
            const isActive = button.dataset.tabTarget === activeSectionId;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
    };

    const activateSection = function (sectionId, options) {
        if (!hasDashboardSections) {
            return;
        }

        const settings = Object.assign({ updateHash: true }, options || {});
        let targetPanel = document.getElementById(sectionId);

        if (!targetPanel || !targetPanel.classList.contains('tab-content')) {
            targetPanel = document.getElementById(defaultSectionId);
            sectionId = defaultSectionId;
        }

        sectionPanels.forEach(function (panel) {
            const isActive = panel === targetPanel;
            panel.classList.toggle('active', isActive);
            panel.style.display = isActive ? 'block' : 'none';
        });

        if (settings.updateHash) {
            window.history.replaceState(null, '', '#' + sectionId);
        }

        syncNavigationState(sectionId);
    };

    const initDropdown = function (root, toggle, panel, onOpen) {
        if (!root || !toggle || !panel) {
            return function () {};
        }

        const setOpen = function (isOpen) {
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            panel.hidden = !isOpen;

            if (typeof onOpen === 'function' && isOpen) {
                onOpen();
            }
        };

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';
            setOpen(!isOpen);
        });

        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                setOpen(false);
            }
        });

        panel.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                setOpen(false);
            });
        });

        return function () {
            setOpen(false);
        };
    };

    const escapeHtml = function (value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const highlightMatch = function (text, query) {
        const normalizedText = String(text);
        const lowerText = normalizedText.toLowerCase();
        const lowerQuery = query.toLowerCase();
        const matchIndex = lowerText.indexOf(lowerQuery);

        if (matchIndex === -1 || lowerQuery === '') {
            return escapeHtml(normalizedText);
        }

        const before = escapeHtml(normalizedText.slice(0, matchIndex));
        const matched = escapeHtml(normalizedText.slice(matchIndex, matchIndex + lowerQuery.length));
        const after = escapeHtml(normalizedText.slice(matchIndex + lowerQuery.length));

        return before + '<mark>' + matched + '</mark>' + after;
    };

    const getProjectSuggestionLinks = function () {
        return Array.from(projectSearchDropdown?.querySelectorAll('.client-project-search__result') || []);
    };

    const syncProjectSuggestionFocus = function () {
        getProjectSuggestionLinks().forEach(function (link, index) {
            link.classList.toggle('is-active', index === activeProjectSuggestionIndex);
        });
    };

    const updateProjectSearchClear = function () {
        if (!projectSearchInput || !projectSearchClear) {
            return;
        }

        projectSearchClear.classList.toggle('is-visible', projectSearchInput.value.trim() !== '');
    };

    const updateProjectSearchDropdown = function (matches, query) {
        if (!projectSearchDropdown) {
            return;
        }

        if (!query) {
            projectSearchDropdown.hidden = true;
            projectSearchDropdown.innerHTML = '';
            activeProjectSuggestionIndex = -1;
            if (projectSearchInput) {
                projectSearchInput.setAttribute('aria-expanded', 'false');
            }
            return;
        }

        if (matches.length === 0) {
            projectSearchDropdown.innerHTML = '<div class="client-project-search__empty">No matching projects yet.</div>';
            projectSearchDropdown.hidden = false;
            activeProjectSuggestionIndex = -1;
            if (projectSearchInput) {
                projectSearchInput.setAttribute('aria-expanded', 'true');
            }
            return;
        }

        projectSearchDropdown.innerHTML = matches.slice(0, 6).map(function (card) {
            const title = card.getAttribute('data-title') || 'Project';
            const engineer = card.getAttribute('data-engineer') || 'Not assigned';
            const status = card.getAttribute('data-status') || 'pending';
            const timeline = card.getAttribute('data-timeline') || 'Not set';

            return '<a href="#' + card.id + '" class="client-project-search__result">' +
                '<strong>' + highlightMatch(title, query) + '</strong>' +
                '<span>' + escapeHtml(engineer) + ' | ' + escapeHtml(status) + ' | ' + escapeHtml(timeline) + '</span>' +
                '</a>';
        }).join('');
        projectSearchDropdown.hidden = false;
        activeProjectSuggestionIndex = -1;
        syncProjectSuggestionFocus();
        if (projectSearchInput) {
            projectSearchInput.setAttribute('aria-expanded', 'true');
        }
    };

    const applyProjectSearch = function () {
        if (!projectSearchInput) {
            return;
        }

        const query = projectSearchInput.value.trim().toLowerCase();
        let visibleCount = 0;
        const matches = [];

        projectCards.forEach(function (card) {
            const searchText = (card.getAttribute('data-search') || '').toLowerCase();
            const isMatch = query === '' || searchText.includes(query);
            card.hidden = !isMatch;

            if (isMatch) {
                visibleCount += 1;
                matches.push(card);
            }
        });

        if (projectSearchCount) {
            projectSearchCount.textContent = visibleCount + ' project(s)';
        }

        if (projectSearchHint) {
            projectSearchHint.textContent = query === ''
                ? 'Type your keyword, then pause for 3 seconds to search.'
                : 'Showing matches for "' + projectSearchInput.value.trim() + '".';
        }

        if (projectSearchEmpty) {
            projectSearchEmpty.hidden = query === '' || visibleCount > 0;
        }

        updateProjectSearchDropdown(matches, query);
    };

    const queueProjectSearch = function () {
        if (!projectSearchInput) {
            return;
        }

        if (projectSearchDebounceId) {
            window.clearTimeout(projectSearchDebounceId);
        }

        if (projectSearchHint) {
            projectSearchHint.textContent = projectSearchInput.value.trim() === ''
                ? 'Type your keyword, then pause for 3 seconds to search.'
                : 'Waiting 3 seconds before searching...';
        }

        projectSearchDebounceId = window.setTimeout(function () {
            applyProjectSearch();
            if (projectSearchInput) {
                const cursorPosition = projectSearchInput.value.length;
                projectSearchInput.focus();
                projectSearchInput.setSelectionRange(cursorPosition, cursorPosition);
            }
        }, 3000);
    };

    const closeNotifications = initDropdown(notificationRoot, notificationToggle, notificationPanel);
    const closeProfile = initDropdown(profileRoot, profileToggle, profilePanel, closeNotifications);

    if (mobileToggle) {
        mobileToggle.addEventListener('click', function () {
            setMobileOpen(!sidebar.classList.contains('mobile-open'));
        });
    }

    if (desktopToggle) {
        desktopToggle.addEventListener('click', function () {
            if (mobileMedia.matches) {
                return;
            }

            setDesktopCollapsed(!body.classList.contains('sidebar-collapsed'));
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            setMobileOpen(false);
        });
    }

    sectionLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!hasDashboardSections) {
                return;
            }

            const sectionId = link.dataset.sectionLink;

            if (!sectionId) {
                return;
            }

            event.preventDefault();
            activateSection(sectionId, { updateHash: true });
            setMobileOpen(false);
        });
    });

    jumpButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (!hasDashboardSections) {
                return;
            }

            const sectionId = button.dataset.jumpSection || button.dataset.jumpTab;

            if (!sectionId) {
                return;
            }

            activateSection(sectionId, { updateHash: true });
            setMobileOpen(false);
        });
    });

    tabButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (!hasDashboardSections) {
                return;
            }

            const sectionId = button.dataset.tabTarget;
            if (!sectionId) {
                return;
            }

            activateSection(sectionId, { updateHash: true });
        });
    });

    if (projectSearchInput && projectSearchRoot) {
        projectCards.forEach(function (card, index) {
            if (!card.id) {
                card.id = 'client-project-card-' + index;
            }
        });

        projectSearchInput.addEventListener('input', function () {
            updateProjectSearchClear();
            queueProjectSearch();
        });

        projectSearchInput.addEventListener('focus', function () {
            if (projectSearchInput.value.trim() !== '') {
                applyProjectSearch();
            }
        });

        projectSearchInput.addEventListener('keydown', function (event) {
            const links = getProjectSuggestionLinks();

            if (projectSearchDropdown?.hidden || links.length === 0) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    if (projectSearchDebounceId) {
                        window.clearTimeout(projectSearchDebounceId);
                    }
                    applyProjectSearch();
                }
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeProjectSuggestionIndex = (activeProjectSuggestionIndex + 1) % links.length;
                syncProjectSuggestionFocus();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeProjectSuggestionIndex = activeProjectSuggestionIndex <= 0 ? links.length - 1 : activeProjectSuggestionIndex - 1;
                syncProjectSuggestionFocus();
                return;
            }

            if (event.key === 'Enter' && activeProjectSuggestionIndex >= 0 && links[activeProjectSuggestionIndex]) {
                event.preventDefault();
                links[activeProjectSuggestionIndex].click();
                return;
            }

            if (event.key === 'Escape' && projectSearchDropdown) {
                projectSearchDropdown.hidden = true;
                projectSearchInput.setAttribute('aria-expanded', 'false');
            }
        });
    }

    if (projectSearchClear) {
        projectSearchClear.addEventListener('click', function () {
            if (!projectSearchInput) {
                return;
            }

            if (projectSearchDebounceId) {
                window.clearTimeout(projectSearchDebounceId);
            }

            projectSearchInput.value = '';
            updateProjectSearchClear();
            applyProjectSearch();
            projectSearchInput.focus();
        });
    }

    if (!window.__clientProjectSearchOutsideBound) {
        document.addEventListener('click', function (event) {
            if (!event.target.closest('[data-client-project-search]') && projectSearchDropdown) {
                projectSearchDropdown.hidden = true;
                if (projectSearchInput) {
                    projectSearchInput.setAttribute('aria-expanded', 'false');
                }
            }
        });

        window.__clientProjectSearchOutsideBound = true;
    }

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        closeNotifications();
        closeProfile();
        setMobileOpen(false);
    });

    if (phDate && phTime) {
        const dateFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric',
        });

        const timeFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true,
        });

        const updateClock = function () {
            const now = new Date();
            phDate.textContent = dateFormatter.format(now);
            phTime.textContent = timeFormatter.format(now);
        };

        updateClock();
        window.setInterval(updateClock, 1000);
    }

    const applyResponsiveSidebarState = function () {
        if (mobileMedia.matches) {
            setMobileOpen(false);
            body.classList.remove('sidebar-collapsed');
            sidebar.classList.remove('shrink');
            updateDesktopToggleState(false);
            return;
        }

        setDesktopCollapsed(window.localStorage.getItem(collapsedStorageKey) === '1');
    };

    setMobileOpen(false);
    applyResponsiveSidebarState();

    if (mobileMedia.addEventListener) {
        mobileMedia.addEventListener('change', applyResponsiveSidebarState);
    } else if (mobileMedia.addListener) {
        mobileMedia.addListener(applyResponsiveSidebarState);
    }

    if (hasDashboardSections) {
        activateSection(window.location.hash.replace('#', '') || defaultSectionId, { updateHash: false });
    }
    updateProjectSearchClear();
    applyProjectSearch();

    if (hasDashboardSections) {
        window.addEventListener('hashchange', function () {
            activateSection(window.location.hash.replace('#', '') || defaultSectionId, { updateHash: false });
        });
    }
});
