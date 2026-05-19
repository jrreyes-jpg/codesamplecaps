const activateTab = (tabId) => {
    const targetPanel = document.getElementById(tabId);

    if (!targetPanel) {
        return;
    }

    document.querySelectorAll('.tab-content').forEach((panel) => {
        panel.classList.remove('active');
    });

    document.querySelectorAll('[data-tab-target]').forEach((button) => {
        button.classList.remove('active');
    });

    targetPanel.classList.add('active');
    document.querySelector(`[data-tab-target="${tabId}"]`)?.classList.add('active');
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-tab-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const tabId = button.dataset.tabTarget;

            if (!tabId) {
                return;
            }

            activateTab(tabId);
            window.location.hash = tabId;
        });
    });

    const hashTabId = window.location.hash.replace('#', '');
    const defaultTabId = document.querySelector('[data-tab-target].active')?.dataset.tabTarget ?? document.querySelector('[data-tab-target]')?.dataset.tabTarget ?? 'projects-tab';
    activateTab(hashTabId || defaultTabId);
});
