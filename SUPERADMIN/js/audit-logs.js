// JS ng Audit Logs page. Auto-submit filter para mas mabilis maghanap.
document.addEventListener('DOMContentLoaded', () => {
    const entityFilter = document.querySelector('[data-audit-entity-filter]');
    const actionFilter = document.querySelector('[data-audit-action-filter]');
    const dateFilter = document.querySelector('[data-audit-date-filter]');

    [entityFilter, actionFilter, dateFilter].forEach((filter) => {
        filter?.addEventListener('change', () => {
            filter.form?.requestSubmit();
        });
    });

    const searchInput = document.querySelector('input[name="q"]');
    const auditRows = Array.from(document.querySelectorAll('[data-audit-row]'));
    const emptyRow = document.querySelector('[data-audit-empty-row]');

    if (searchInput && auditRows.length > 0) {
        const syncAuditSearch = () => {
            const query = searchInput.value.trim().toLowerCase();
            let visibleCount = 0;

            auditRows.forEach((row) => {
                const haystack = row.getAttribute('data-audit-search') || '';
                const matches = query === '' || haystack.includes(query);
                row.hidden = !matches;
                if (matches) {
                    visibleCount += 1;
                }
            });

            if (emptyRow) {
                emptyRow.hidden = visibleCount !== 0;
            }
        };

        searchInput.addEventListener('input', syncAuditSearch);
        syncAuditSearch();
    }

    const modal = document.querySelector('[data-audit-modal]');
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

    document.querySelectorAll('[data-audit-open]').forEach((button) => {
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

    document.querySelector('[data-audit-close]')?.addEventListener('click', closeModal);

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
});
