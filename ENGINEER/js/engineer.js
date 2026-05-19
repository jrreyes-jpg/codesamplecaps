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

const setSidebarState = (isShrink, shouldPersist = false) => {
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content, .dashboard-main');
    const toggleButton = document.querySelector('[data-sidebar-toggle]');

    if (!sidebar || !mainContent) {
        return;
    }

    sidebar.classList.toggle('shrink', isShrink);
    mainContent.classList.toggle('sidebar-shrink', isShrink);

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
    const applyStoredSidebarState = () => {
        const isDesktop = window.innerWidth > 768;
        setSidebarState(isDesktop ? readSidebarState() : false, false);
    };

    sidebarToggle?.addEventListener('click', () => {
        const sidebar = document.querySelector('.sidebar');
        const nextShrinkState = !sidebar?.classList.contains('shrink');
        setSidebarState(nextShrinkState, true);
    });

    mobileToggle?.addEventListener('click', () => {
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
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                closeMobileSidebar();
            }
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
