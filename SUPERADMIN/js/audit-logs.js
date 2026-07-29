// JS ng Audit Logs page. Auto-submit filter para mas mabilis maghanap.
document.addEventListener('DOMContentLoaded', () => {
    const entityFilter = document.querySelector('[data-audit-entity-filter]');

    if (!entityFilter) {
        return;
    }

    entityFilter.addEventListener('change', () => {
        entityFilter.form?.requestSubmit();
    });
});
