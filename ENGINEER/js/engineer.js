const ENGINEER_SIDEBAR_STORAGE_KEY = 'engineer.sidebar.shrink';

const persistSidebarState = (isShrink) => {
    try {
        window.localStorage.setItem(ENGINEER_SIDEBAR_STORAGE_KEY, isShrink ? '1' : '0');
    } catch (error) {
        // Ignore storage failures.
    }
};

const readSidebarState = () => {
    try {
        return window.localStorage.getItem(ENGINEER_SIDEBAR_STORAGE_KEY) === '1';
    } catch (error) {
        return false;
    }
};

const createUiSound = () => {
    let audioContext = null;

    const play = (frequency = 520, duration = 0.045, volume = 0.025) => {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return;
            }

            audioContext = audioContext || new AudioContextClass();

            const startSound = () => {
                const oscillator = audioContext.createOscillator();
                const gain = audioContext.createGain();

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
                audioContext.resume().then(startSound).catch(() => {});
                return;
            }

            startSound();
        } catch (error) {
            // Tahimik lang kapag blocked ng browser ang sound.
        }
    };

    return {
        tap: () => play(520, 0.04, 0.018),
        toggle: () => play(660, 0.05, 0.022),
        logout: () => play(320, 0.06, 0.024),
    };
};

const setSidebarState = (isShrink, shouldPersist = false) => {
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content, .dashboard-main');
    const toggleButton = document.querySelector('[data-sidebar-toggle]');

    if (!sidebar || !mainContent) {
        return;
    }

    sidebar.classList.toggle('shrink', isShrink);
    mainContent.classList.toggle('sidebar-shrink', isShrink);
    document.documentElement.classList.toggle('ops-sidebar-shrink-pref', isShrink && window.innerWidth > 768);

    if (toggleButton) {
        toggleButton.setAttribute('aria-label', isShrink ? 'Expand menu' : 'Collapse menu');
        toggleButton.setAttribute('aria-expanded', String(!isShrink));
    }

    if (shouldPersist) {
        persistSidebarState(isShrink);
    }
};

const closeMobileSidebar = () => {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const mobileToggle = document.querySelector('[data-sidebar-mobile-toggle]');

    sidebar?.classList.remove('mobile-open');
    overlay?.classList.remove('active');
    mobileToggle?.classList.remove('active');
};

const spotlightTask = (taskId) => {
    if (!taskId) {
        return;
    }

    const taskItem = document.querySelector(`[data-task-item-id="${taskId}"]`);

    if (!taskItem) {
        return;
    }

    taskItem.classList.add('is-spotlight');
    taskItem.scrollIntoView({ behavior: 'smooth', block: 'center' });

    window.setTimeout(() => {
        taskItem.classList.remove('is-spotlight');
    }, 2200);
};

const initTaskFilters = () => {
    const searchInput = document.querySelector('[data-task-search]');
    const statusFilter = document.querySelector('[data-task-status-filter]');
    const deadlineFilter = document.querySelector('[data-task-deadline-filter]');
    const taskItems = Array.from(document.querySelectorAll('[data-task-item]'));
    const quickButtons = Array.from(document.querySelectorAll('[data-task-quick-filter]'));
    const defaultQuickFilter = document.querySelector('[data-default-quick-filter]')?.value ?? '';

    if (!searchInput || !statusFilter || !deadlineFilter || taskItems.length === 0) {
        return;
    }

    let quickFilterValue = defaultQuickFilter;

    const setQuickFilter = (value) => {
        quickFilterValue = value;

        quickButtons.forEach((button) => {
            const buttonFilter = button.dataset.taskQuickFilter ?? '';
            button.classList.toggle('active', value !== '' && buttonFilter === value);
        });
    };

    const applyFilters = () => {
        const searchValue = searchInput.value.trim().toLowerCase();
        const statusValue = statusFilter.value;
        const deadlineValue = deadlineFilter.value;

        taskItems.forEach((item) => {
            const taskName = item.dataset.taskName ?? '';
            const projectName = item.dataset.projectName ?? '';
            const taskStatus = item.dataset.taskStatus ?? '';
            const deadlineGroup = item.dataset.deadlineGroup ?? '';
            const hasUpdate = item.dataset.taskHasUpdate ?? 'no';
            const isDueToday = item.dataset.taskIsDueToday ?? 'no';
            const isOverdue = item.dataset.taskIsOverdue ?? 'no';
            const isBlocked = item.dataset.taskIsBlocked ?? 'no';
            const isLocked = item.dataset.taskIsLocked ?? 'no';

            const matchesSearch =
                searchValue === '' ||
                taskName.includes(searchValue) ||
                projectName.includes(searchValue);
            const matchesStatus = statusValue === '' || taskStatus === statusValue;
            const matchesDeadline = deadlineValue === '' || deadlineGroup === deadlineValue;
            const matchesQuick =
                quickFilterValue === '' ||
                (quickFilterValue === 'all-open' && taskStatus !== 'completed' && isLocked !== 'yes') ||
                (quickFilterValue === 'overdue' && isOverdue === 'yes') ||
                (quickFilterValue === 'due-today' && isDueToday === 'yes') ||
                (quickFilterValue === 'no-update' && hasUpdate === 'no' && taskStatus !== 'completed' && isLocked !== 'yes') ||
                (quickFilterValue === 'blocked' && isBlocked === 'yes' && isLocked !== 'yes');

            item.hidden = !(matchesSearch && matchesStatus && matchesDeadline && matchesQuick);
        });
    };

    quickButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const nextQuickFilter = button.dataset.taskQuickFilter ?? '';
            const isResetButton = button.hasAttribute('data-reset-task-filters');

            searchInput.value = '';
            statusFilter.value = '';
            deadlineFilter.value = '';
            setQuickFilter(isResetButton ? '' : nextQuickFilter);
            applyFilters();
        });
    });

    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
    deadlineFilter.addEventListener('change', applyFilters);

    setQuickFilter(defaultQuickFilter);
    applyFilters();
};

document.addEventListener('DOMContentLoaded', () => {
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const mobileToggle = document.querySelector('[data-sidebar-mobile-toggle]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const uiSound = createUiSound();
    const applyStoredSidebarState = () => {
        const isDesktop = window.innerWidth > 768;
        setSidebarState(isDesktop ? readSidebarState() : false, false);
    };

    sidebarToggle?.addEventListener('click', () => {
        uiSound.toggle();
        const sidebar = document.querySelector('.sidebar');
        const nextShrinkState = !sidebar?.classList.contains('shrink');
        setSidebarState(nextShrinkState, true);
    });

    mobileToggle?.addEventListener('click', () => {
        uiSound.toggle();
        const sidebar = document.querySelector('.sidebar');
        const isOpen = sidebar?.classList.toggle('mobile-open');
        overlay?.classList.toggle('active', Boolean(isOpen));
        mobileToggle.classList.toggle('active', Boolean(isOpen));
    });

    overlay?.addEventListener('click', closeMobileSidebar);

    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
            closeMobileSidebar();
        }

        applyStoredSidebarState();
    });

    document.querySelectorAll('.menu-link').forEach((link) => {
        link.addEventListener('click', (event) => {
            const isLogout = link.classList.contains('logout');
            const href = link.getAttribute('href');
            uiSound[isLogout ? 'logout' : 'tap']();

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

            const linkUrl = new URL(href, window.location.href);
            const currentUrl = new URL(window.location.href);
            const isSamePage = linkUrl.pathname === currentUrl.pathname && linkUrl.search === currentUrl.search && !linkUrl.hash;

            if (isSamePage) {
                return;
            }

            event.preventDefault();
            window.setTimeout(() => {
                window.location.href = linkUrl.href;
            }, isLogout ? 100 : 75);
        });
    });

    const taskFromQuery = new URLSearchParams(window.location.search).get('task');
    initTaskFilters();
    applyStoredSidebarState();

    if (taskFromQuery) {
        window.setTimeout(() => {
            spotlightTask(taskFromQuery);
        }, 120);
    }
});
