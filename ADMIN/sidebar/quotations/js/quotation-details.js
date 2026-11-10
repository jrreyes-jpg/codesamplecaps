document.addEventListener('DOMContentLoaded', function () {
    const editDraftLink = document.querySelector('[data-quotation-edit-draft]');
    const form = document.querySelector('[data-quotation-send-form]');
    const modal = document.querySelector('[data-quotation-send-modal]');
    const confirmButton = modal?.querySelector('[data-quotation-send-confirm]');
    const cancelButtons = modal?.querySelectorAll('[data-quotation-send-cancel]') || [];
    const liveSection = document.querySelector('[data-quotation-live-status]');
    const statusBadge = liveSection?.querySelector('[data-quotation-status-badge]');
    const historyList = document.querySelector('[data-quotation-status-history]');
    const revisionNote = liveSection?.querySelector('[data-quotation-revision-note]');
    const revisionNoteText = liveSection?.querySelector('[data-quotation-revision-note-text]');
    const reviseAction = liveSection?.querySelector('[data-quotation-revise-action]');
    const createProjectAction = liveSection?.querySelector('[data-quotation-create-project]');
    const historyModal = document.querySelector('[data-quotation-history-modal]');
    const historyOpen = document.querySelector('[data-quotation-history-open]');
    const historyCloseButtons = historyModal?.querySelectorAll('[data-quotation-history-close]') || [];

    const renderHistory = function (history) {
        if (!historyList || !Array.isArray(history)) return;
        historyList.replaceChildren();
        if (history.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = 'No status history yet.';
            historyList.appendChild(empty);
            return;
        }
        history.forEach(function (entry) {
            const article = document.createElement('article');
            const dot = document.createElement('span');
            const content = document.createElement('div');
            const label = document.createElement('strong');
            const details = document.createElement('p');
            dot.className = 'history-dot';
            dot.setAttribute('aria-hidden', 'true');
            label.textContent = String(entry.label || 'Status updated');
            details.textContent = String(entry.actor || 'System') + ' - ' + String(entry.date || 'Not recorded');
            content.append(label, details);
            article.append(dot, content);
            historyList.appendChild(article);
        });
    };

    const renderLiveStatus = function (data) {
        if (statusBadge) {
            statusBadge.classList.remove('is-success', 'is-active', 'is-warning', 'is-error', 'is-neutral');
            statusBadge.classList.add(String(data.status_class || 'is-neutral'));
            statusBadge.textContent = String(data.label || 'Status updated');
        }
        const showRevision = data.status === 'revision_requested';
        if (revisionNote) revisionNote.hidden = !showRevision || !data.client_note;
        if (revisionNoteText) revisionNoteText.textContent = String(data.client_note || '');
        if (reviseAction) reviseAction.hidden = !showRevision;
        if (createProjectAction) createProjectAction.hidden = data.status !== 'accepted';
        renderHistory(data.history);
    };

    const startStatusPolling = function () {
        if (!liveSection || liveSection.dataset.currentStatus !== 'sent') return;
        const quotationId = Number(liveSection.dataset.quotationId || 0);
        if (quotationId <= 0) return;
        let currentStatus = liveSection.dataset.currentStatus;
        let currentUpdatedAt = liveSection.dataset.currentUpdatedAt || '';

        const checkStatus = function () {
            if (document.hidden) {
                window.setTimeout(checkStatus, 15000);
                return;
            }
            const url = new URL('/codesamplecaps/ADMIN/sidebar/quotations/php/quotation_status.php', window.location.origin);
            url.searchParams.set('id', String(quotationId));
            fetch(url.toString(), { headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then(function (response) {
                    if (!response.ok) throw new Error('Status check failed.');
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) throw new Error('Status check failed.');
                    const changed = data.status !== currentStatus || data.updated_at !== currentUpdatedAt;
                    if (changed) {
                        const oldStatus = currentStatus;
                        currentStatus = String(data.status || currentStatus);
                        currentUpdatedAt = String(data.updated_at || currentUpdatedAt);
                        liveSection.dataset.currentStatus = currentStatus;
                        liveSection.dataset.currentUpdatedAt = currentUpdatedAt;
                        renderLiveStatus(data);
                        if (oldStatus === 'sent' && typeof window.showToast === 'function') {
                            const messages = {
                                accepted: 'Client accepted the quotation.',
                                revision_requested: 'Client requested a quotation revision.',
                                rejected: 'Client rejected the quotation.',
                            };
                            const tones = { accepted: 'success', revision_requested: 'warning', rejected: 'error' };
                            if (messages[currentStatus]) window.showToast(messages[currentStatus], tones[currentStatus]);
                        }
                    }
                    if (currentStatus === 'sent') window.setTimeout(checkStatus, 15000);
                })
                .catch(function () {
                    window.setTimeout(checkStatus, 15000);
                });
        };

        window.setTimeout(checkStatus, 15000);
    };

    startStatusPolling();

    const closeHistoryModal = function () {
        if (!historyModal) return;
        historyModal.hidden = true;
        document.body.classList.remove('admin-quotation-history-open');
        historyOpen?.focus();
    };

    historyOpen?.addEventListener('click', function () {
        if (!historyModal) return;
        historyModal.hidden = false;
        document.body.classList.add('admin-quotation-history-open');
        historyModal.querySelector('[data-quotation-history-close]')?.focus();
    });
    historyCloseButtons.forEach(function (button) {
        button.addEventListener('click', closeHistoryModal);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && historyModal && !historyModal.hidden) closeHistoryModal();
    });

    editDraftLink?.addEventListener('click', function (event) {
        event.preventDefault();
        window.location.replace(this.href);
    });

    if (!form || !modal || !confirmButton) {
        return;
    }

    const closeModal = function () {
        modal.hidden = true;
        document.body.classList.remove('admin-quotation-send-open');
        form.querySelector('button[type="submit"]')?.focus();
    };

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        modal.hidden = false;
        document.body.classList.add('admin-quotation-send-open');
        confirmButton.focus();
    });

    cancelButtons.forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    confirmButton.addEventListener('click', function () {
        confirmButton.disabled = true;
        confirmButton.textContent = 'Sending...';
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button').forEach(function (control) {
            control.disabled = true;
        });
        window.edgeActionLoading?.show({
            title: 'Sending quotation...',
            message: 'Please wait. Do not close this page.',
        });
        form.submit();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden && !confirmButton.disabled) {
            closeModal();
        }
    });
});
