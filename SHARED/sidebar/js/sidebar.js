(function () {
    if (window.__edgeSharedSidebarInitialized) {
        return;
    }

    window.__edgeSharedSidebarInitialized = true;

    var storageKey = 'edge.operations.sidebar.shrink';
    var scrollStorageKey = 'edge.operations.sidebar.scroll';
    var pendingActiveKey = 'edge.operations.sidebar.pendingActive';
    var legacyKeys = ['engineer.sidebar.shrink', 'edgeSidebarCollapsed'];
    var scrollSaveTimer = null;

    var readStoredState = function () {
        try {
            var saved = window.localStorage.getItem(storageKey);
            if (saved !== null) {
                return saved === '1';
            }

            for (var i = 0; i < legacyKeys.length; i += 1) {
                var legacySaved = window.localStorage.getItem(legacyKeys[i]);
                if (legacySaved !== null) {
                    window.localStorage.setItem(storageKey, legacySaved === '1' ? '1' : '0');
                    return legacySaved === '1';
                }
            }
        } catch (error) {
            return false;
        }

        return false;
    };

    var persistState = function (isShrink) {
        try {
            window.localStorage.setItem(storageKey, isShrink ? '1' : '0');
        } catch (error) {
            // Ignore storage failures.
        }
    };

    var createSound = function () {
        var audioContext = null;
        var play = function (frequency, duration, volume) {
            try {
                var AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextClass) {
                    return;
                }

                audioContext = audioContext || new AudioContextClass();
                var run = function () {
                    var oscillator = audioContext.createOscillator();
                    var gain = audioContext.createGain();
                    oscillator.type = 'sine';
                    oscillator.frequency.value = frequency;
                    gain.gain.setValueAtTime(volume, audioContext.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + duration);
                    oscillator.connect(gain);
                    gain.connect(audioContext.destination);
                    oscillator.start();
                    oscillator.stop(audioContext.currentTime + duration);
                };

                if (audioContext.state === 'suspended') {
                    audioContext.resume().then(run).catch(function () {});
                    return;
                }

                run();
            } catch (error) {
                // Browser can block audio. Visual behavior still works.
            }
        };

        return {
            tap: function () { play(520, 0.04, 0.018); },
            toggle: function () { play(660, 0.05, 0.022); },
            logout: function () { play(320, 0.06, 0.024); },
        };
    };

    var setSidebarState = function (isShrink, shouldPersist) {
        var sidebar = document.querySelector('[data-shared-sidebar]');
        var mainContent = document.querySelector('.main-content, .dashboard-main');
        var toggleButton = document.querySelector('[data-sidebar-toggle]');

        if (!sidebar || !mainContent) {
            return;
        }

        var shrinkAllowed = isShrink && window.innerWidth > 768;
        sidebar.classList.toggle('shrink', shrinkAllowed);
        mainContent.classList.toggle('sidebar-shrink', shrinkAllowed);
        document.documentElement.classList.toggle('ops-sidebar-shrink-pref', shrinkAllowed);

        if (toggleButton) {
            toggleButton.setAttribute('aria-label', shrinkAllowed ? 'Expand menu' : 'Collapse menu');
            toggleButton.setAttribute('aria-expanded', String(!shrinkAllowed));
        }

        if (shouldPersist) {
            persistState(shrinkAllowed);
        }
    };

    var closeMobileSidebar = function () {
        var sidebar = document.querySelector('[data-shared-sidebar]');
        var overlay = document.querySelector('[data-sidebar-overlay]');
        var mobileToggle = document.querySelector('[data-sidebar-mobile-toggle]');

        sidebar?.classList.remove('mobile-open');
        overlay?.classList.remove('active');
        mobileToggle?.classList.remove('active');
        mobileToggle?.setAttribute('aria-expanded', 'false');
    };

    var saveSidebarScroll = function (sidebar) {
        if (!sidebar) {
            return;
        }

        try {
            window.sessionStorage.setItem(scrollStorageKey, String(Math.max(0, Math.round(sidebar.scrollTop))));
        } catch (error) {
            // Ignore storage failures.
        }
    };

    var readSidebarScroll = function () {
        try {
            var saved = window.sessionStorage.getItem(scrollStorageKey);
            var parsed = saved === null ? 0 : parseInt(saved, 10);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
        } catch (error) {
            return 0;
        }
    };

    var savePendingActiveTarget = function (href) {
        try {
            window.sessionStorage.setItem(pendingActiveKey, href);
        } catch (error) {
            // Ignore storage failures.
        }
    };

    var consumePendingActiveTarget = function () {
        try {
            var saved = window.sessionStorage.getItem(pendingActiveKey);
            window.sessionStorage.removeItem(pendingActiveKey);
            return saved;
        } catch (error) {
            return null;
        }
    };

    var scheduleSidebarScrollSave = function (sidebar) {
        if (scrollSaveTimer !== null) {
            window.clearTimeout(scrollSaveTimer);
        }

        scrollSaveTimer = window.setTimeout(function () {
            saveSidebarScroll(sidebar);
            scrollSaveTimer = null;
        }, 100);
    };

    var keepActiveItemVisible = function (sidebar) {
        var activeItem = sidebar?.querySelector('.menu-link.active-link');
        if (!sidebar || !activeItem) {
            return;
        }

        var sidebarRect = sidebar.getBoundingClientRect();
        var activeRect = activeItem.getBoundingClientRect();
        var isVisible = activeRect.top >= sidebarRect.top && activeRect.bottom <= sidebarRect.bottom;

        if (!isVisible) {
            activeItem.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    };

    var restoreSidebarScroll = function (sidebar) {
        if (!sidebar) {
            return;
        }

        if (consumePendingActiveTarget() === null) {
            var savedScroll = readSidebarScroll();
            var maxScroll = Math.max(0, sidebar.scrollHeight - sidebar.clientHeight);
            sidebar.scrollTop = Math.min(savedScroll, maxScroll);
        }

        keepActiveItemVisible(sidebar);
    };

    var isSidebarCollapsedDesktop = function (sidebar) {
        if (!sidebar || window.innerWidth <= 768) {
            return false;
        }

        return sidebar.classList.contains('shrink') || document.documentElement.classList.contains('ops-sidebar-shrink-pref');
    };

    var setGroupOpenState = function (group, shouldOpen) {
        if (!group) {
            return;
        }

        var toggle = group.querySelector('[data-sidebar-group-toggle]');
        var panel = group.querySelector('.menu-submenu');

        group.classList.toggle('is-open', shouldOpen);

        if (toggle) {
            toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        }

        if (panel) {
            panel.hidden = !shouldOpen;
        }
    };

    var initializeSidebarGroups = function (sidebar) {
        if (!sidebar) {
            return;
        }

        sidebar.querySelectorAll('.nav-menu-group').forEach(function (group) {
            var hasActiveChild = Boolean(group.querySelector('.menu-submenu .menu-link.active-link'));
            setGroupOpenState(group, hasActiveChild);
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        var sidebar = document.querySelector('[data-shared-sidebar]');
        if (!sidebar) {
            return;
        }

        var sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        var mobileToggle = document.querySelector('[data-sidebar-mobile-toggle]');
        var overlay = document.querySelector('[data-sidebar-overlay]');
        var sound = createSound();

        var applyStoredState = function () {
            setSidebarState(readStoredState(), false);
        };

        var restoreAfterLayout = function () {
            window.requestAnimationFrame(function () {
                restoreSidebarScroll(sidebar);
                window.setTimeout(function () {
                    restoreSidebarScroll(sidebar);
                }, 80);
            });
        };

        sidebarToggle?.addEventListener('click', function () {
            sound.toggle();
            saveSidebarScroll(sidebar);
            setSidebarState(!sidebar.classList.contains('shrink'), true);
            restoreAfterLayout();
        });

        mobileToggle?.addEventListener('click', function () {
            sound.toggle();
            var isOpen = sidebar.classList.toggle('mobile-open');
            overlay?.classList.toggle('active', Boolean(isOpen));
            mobileToggle.classList.toggle('active', Boolean(isOpen));
            mobileToggle.setAttribute('aria-expanded', String(Boolean(isOpen)));
            if (isOpen) {
                restoreAfterLayout();
            }
        });

        overlay?.addEventListener('click', closeMobileSidebar);

        sidebar.addEventListener('scroll', function () {
            scheduleSidebarScrollSave(sidebar);
        }, { passive: true });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 768) {
                closeMobileSidebar();
            }

            applyStoredState();
            restoreAfterLayout();
        });

        window.addEventListener('pagehide', function () {
            saveSidebarScroll(sidebar);
        });

        window.addEventListener('beforeunload', function () {
            saveSidebarScroll(sidebar);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMobileSidebar();
            }
        });

        sidebar.querySelectorAll('[data-sidebar-group-toggle]').forEach(function (toggle) {
            toggle.addEventListener('click', function (event) {
                var group = toggle.closest('.nav-menu-group');
                if (!group) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                sound.toggle();

                if (isSidebarCollapsedDesktop(sidebar)) {
                    saveSidebarScroll(sidebar);
                    setSidebarState(false, true);
                    setGroupOpenState(group, true);
                    restoreAfterLayout();
                    return;
                }

                var shouldOpen = toggle.getAttribute('aria-expanded') !== 'true';
                setGroupOpenState(group, shouldOpen);
                saveSidebarScroll(sidebar);
                keepActiveItemVisible(sidebar);
            });
        });

        document.querySelectorAll('.menu-link').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (link.hasAttribute('data-sidebar-group-toggle')) {
                    return;
                }

                var isLogout = link.classList.contains('logout');
                var href = link.getAttribute('href');
                sound[isLogout ? 'logout' : 'tap']();
                if (href && !href.startsWith('#') && !isLogout) {
                    savePendingActiveTarget(href);
                } else {
                    saveSidebarScroll(sidebar);
                }

                if (window.innerWidth <= 768) {
                    closeMobileSidebar();
                }

                if (
                    event.defaultPrevented ||
                    event.button !== 0 ||
                    event.metaKey ||
                    event.ctrlKey ||
                    event.shiftKey ||
                    event.altKey ||
                    link.target === '_blank' ||
                    !href ||
                    href.startsWith('#')
                ) {
                    return;
                }

                var linkUrl = new URL(href, window.location.href);
                var currentUrl = new URL(window.location.href);
                var isSamePage = linkUrl.pathname === currentUrl.pathname && linkUrl.search === currentUrl.search && !linkUrl.hash;

                if (isSamePage) {
                    return;
                }

                event.preventDefault();
                window.setTimeout(function () {
                    window.location.href = linkUrl.href;
                }, isLogout ? 100 : 75);
            });
        });

        applyStoredState();
        initializeSidebarGroups(sidebar);
        restoreAfterLayout();
    });
})();
