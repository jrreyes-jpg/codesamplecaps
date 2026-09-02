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
    const taskFromQuery = new URLSearchParams(window.location.search).get('task');

    initTaskFilters();

    if (taskFromQuery) {
        window.setTimeout(() => {
            spotlightTask(taskFromQuery);
        }, 120);
    }
});
