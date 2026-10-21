// Inquiry modal controls para malinis at walang inline JavaScript.
document.addEventListener('DOMContentLoaded', function () {
    const pageParams = new URLSearchParams(window.location.search);
    if (pageParams.get('walk_in_saved') === '1') {
        try {
            window.sessionStorage.removeItem('codesamplecaps.admin.walkInInquiryDraft');
            window.localStorage.removeItem('codesamplecaps.admin.walkInInquiryDraft');
        } catch (error) {
        }
        pageParams.delete('walk_in_saved');
        const cleanQuery = pageParams.toString();
        window.history.replaceState(window.history.state, '', window.location.pathname + (cleanQuery ? '?' + cleanQuery : '') + window.location.hash);
    }
    const inquiryShell = document.querySelector('.inquiries-shell');
    const listStartAtTopKey = 'edgeInquiryListStartAtTop';
    let latestRevisionId = Number.parseInt(inquiryShell?.dataset.latestRevisionId || '0', 10);
    let latestRevisionUpdatedAt = inquiryShell?.dataset.latestRevisionUpdatedAt || '';
    let latestRejectedId = Number.parseInt(inquiryShell?.dataset.latestRejectedId || '0', 10);
    let latestRejectedAt = inquiryShell?.dataset.latestRejectedAt || '';
    let lastOpenButton = null;
    let pendingConfirmForm = null;
    let pendingDiscardModal = null;
    let pendingDiscardAction = null;
    let pendingDiscardKeepAction = null;
    let pendingQuotationDraftDiscard = null;
    let pendingQuotationItemRemoval = null;

    if ('scrollRestoration' in window.history) {
        window.history.scrollRestoration = 'manual';
    }

    const startInquiryListAtTop = function () {
        window.scrollTo(0, 0);
        window.requestAnimationFrame(function () {
            window.scrollTo(0, 0);
        });
    };

    const showPageLoading = function () {
        if (!inquiryShell) {
            return;
        }

        inquiryShell.classList.add('is-loading');

        const inquiryList = inquiryShell.querySelector(':scope > .inquiry-list');
        const currentEmptyState = inquiryShell.querySelector(':scope > .inquiry-empty');
        const loadingState = document.createElement('div');
        const loadingIndicator = document.createElement('div');
        const spinner = document.createElement('span');
        const loadingText = document.createElement('span');

        loadingState.className = 'inquiry-empty';
        loadingState.setAttribute('role', 'status');
        loadingState.setAttribute('aria-live', 'polite');
        loadingIndicator.className = 'btn-primary inquiry-send-button--loading';
        spinner.className = 'inquiry-send-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        loadingText.textContent = 'Loading inquiries...';
        loadingIndicator.append(spinner, loadingText);
        loadingState.appendChild(loadingIndicator);

        if (inquiryList) {
            inquiryList.replaceChildren(loadingState);
        } else if (currentEmptyState) {
            currentEmptyState.replaceWith(loadingState);
        }
    };

    const playToastSound = function () {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;

            const audioContext = new AudioContextClass();
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.value = 0.05;
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start();
            oscillator.stop(audioContext.currentTime + 0.12);
        } catch (error) {
            // Tahimik lang kapag bawal ng browser ang auto sound.
        }
    };

    const showLiveInquiryToast = function (message, inquiryId, targetTab, type, fallbackStatus) {
        const openToastInquiry = function () {
            const modal = document.getElementById('inquiryModal' + String(inquiryId || ''));
            if (!modal) {
                if (!fallbackStatus) {
                    return;
                }
                const targetUrl = new URL('/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php', window.location.origin);
                targetUrl.searchParams.set('status', fallbackStatus);
                targetUrl.searchParams.set('open', 'inquiryModal' + String(inquiryId || ''));
                targetUrl.searchParams.set('tab', targetTab || 'client');
                window.location.assign(targetUrl.toString());
                return;
            }

            document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(function (openInquiryModal) {
                if (openInquiryModal !== modal) {
                    closeModal(openInquiryModal);
                }
            });

            openModal(modal);
            const requestedTab = targetTab || 'client';
            const activeTab = activateModalTab(modal, requestedTab) ? requestedTab : 'client';
            pushModalHistory(modal, activeTab);
        };

        if (typeof window.showToast === 'function') {
            window.showToast(message, type || 'success', {
                onClick: Number.parseInt(inquiryId || '0', 10) > 0 ? openToastInquiry : null,
            });
        }
        playToastSound();
    };

    const confirmBox = document.createElement('div');
    confirmBox.className = 'inquiry-confirm';
    confirmBox.hidden = true;
    confirmBox.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryConfirmTitle">',
        '<h3 id="inquiryConfirmTitle">Are you sure?</h3>',
        '<p data-inquiry-confirm-message>This action will update the inquiry.</p>',
        '<dl class="inquiry-confirm__details" data-inquiry-confirm-details hidden></dl>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-secondary" data-inquiry-confirm-no>No</button>',
        '<button type="button" class="btn-primary" data-inquiry-confirm-yes>Yes</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(confirmBox);

    const inspectionStockModal = document.createElement('div');
    inspectionStockModal.className = 'inquiry-confirm';
    inspectionStockModal.hidden = true;
    inspectionStockModal.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="inspectionStockTitle">',
        '<h3 id="inspectionStockTitle">Out of Stock Materials</h3>',
        '<p>These materials need stock checking before the project starts.</p>',
        '<div class="inspection-stock-list" data-inspection-stock-list></div>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-primary" data-inspection-stock-close>Close</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(inspectionStockModal);

    const openInspectionStockModal = function (button) {
        const list = inspectionStockModal.querySelector('[data-inspection-stock-list]');
        let items = [];
        try {
            items = JSON.parse(button.dataset.stockItems || '[]');
        } catch (error) {
            items = [];
        }
        list?.replaceChildren();
        items.forEach(function (item) {
            const row = document.createElement('div');
            row.className = 'inspection-stock-list__item';
            const name = document.createElement('strong');
            const detail = document.createElement('span');
            name.textContent = item.name || 'Material';
            detail.textContent = 'Needed: ' + (item.needed || '0') + ' ' + (item.unit || '') + ' | Available: ' + (item.available || '0') + ' ' + (item.unit || '');
            row.append(name, detail);
            list?.appendChild(row);
        });
        inspectionStockModal.hidden = false;
        inspectionStockModal.querySelector('[data-inspection-stock-close]')?.focus();
    };

    const closeInspectionStockModal = function () {
        inspectionStockModal.hidden = true;
    };

    const discardConfirmBox = document.createElement('div');
    discardConfirmBox.className = 'inquiry-confirm';
    discardConfirmBox.hidden = true;
    discardConfirmBox.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryDiscardTitle">',
        '<h3 id="inquiryDiscardTitle">Unsaved changes</h3>',
        '<p>You have unsaved changes. Discard them?</p>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-secondary" data-inquiry-discard-keep>Keep Editing</button>',
        '<button type="button" class="btn-primary" data-inquiry-discard-yes>Discard Changes</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(discardConfirmBox);

    const quotationDraftDiscardBox = document.createElement('div');
    quotationDraftDiscardBox.className = 'inquiry-confirm';
    quotationDraftDiscardBox.hidden = true;
    quotationDraftDiscardBox.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="quotationDraftDiscardTitle">',
        '<h3 id="quotationDraftDiscardTitle">Unsaved quotation changes</h3>',
        '<p>You have changes that haven\'t been saved to this quotation. Leaving now will keep the saved quotation and totals unchanged.</p>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-secondary" data-quotation-draft-discard-keep>Keep Editing</button>',
        '<button type="button" class="btn-primary" data-quotation-draft-discard-yes>Leave Without Saving</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(quotationDraftDiscardBox);

    const prerequisiteNotice = document.createElement('div');
    prerequisiteNotice.className = 'inquiry-confirm inquiry-prerequisite-modal';
    prerequisiteNotice.hidden = true;
    prerequisiteNotice.innerHTML = [
        '<div class="inquiry-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryPrerequisiteTitle">',
        '<h3 id="inquiryPrerequisiteTitle">Required First Step</h3>',
        '<p data-prerequisite-notice-message></p>',
        '<div class="inquiry-confirm__actions">',
        '<button type="button" class="btn-primary" data-prerequisite-notice-ok>OK</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(prerequisiteNotice);

    const inspectionMonitorModal = document.createElement('div');
    inspectionMonitorModal.className = 'inspection-monitor-modal';
    inspectionMonitorModal.hidden = true;
    inspectionMonitorModal.innerHTML = [
        '<div class="inspection-monitor-modal__panel" role="dialog" aria-modal="true" aria-labelledby="inspectionMonitorTitle">',
        '<div class="inspection-monitor-modal__head">',
        '<h3 id="inspectionMonitorTitle">Inspection Monitoring</h3>',
        '<button type="button" class="inquiry-modal__close" data-inspection-monitor-close aria-label="Close inspection monitoring">&times;</button>',
        '</div>',
        '<p class="inspection-monitor-modal__feedback" data-inspection-monitor-feedback hidden aria-live="polite"></p>',
        '<div class="inspection-monitor-modal__content" data-inspection-monitor-content></div>',
        '<div class="inspection-monitor-modal__actions">',
        '<span data-inspection-monitor-refreshed>Not refreshed yet.</span>',
        '<button type="button" class="btn-secondary" data-inspection-monitor-refresh>Refresh</button>',
        '<button type="button" class="btn-primary" data-inspection-monitor-close>Close</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(inspectionMonitorModal);

    const inspectionMonitorContent = inspectionMonitorModal.querySelector('[data-inspection-monitor-content]');
    const inspectionMonitorFeedback = inspectionMonitorModal.querySelector('[data-inspection-monitor-feedback]');
    const inspectionMonitorRefresh = inspectionMonitorModal.querySelector('[data-inspection-monitor-refresh]');
    const inspectionMonitorRefreshed = inspectionMonitorModal.querySelector('[data-inspection-monitor-refreshed]');
    let inspectionMonitorId = 0;
    let inspectionMonitorTrigger = null;
    let inspectionMonitorRefreshing = false;

    const appendMonitorDetail = function (grid, label, value) {
        const item = document.createElement('div');
        item.className = 'inspection-monitor-modal__detail';
        const title = document.createElement('span');
        const text = document.createElement('strong');
        title.textContent = label;
        text.textContent = value || 'Not set';
        item.append(title, text);
        grid.appendChild(item);
    };

    const renderInspectionMonitor = function (inspection) {
        if (!inspectionMonitorContent) return;
        inspectionMonitorContent.replaceChildren();
        const summary = document.createElement('div');
        summary.className = 'inspection-monitor-modal__grid';
        appendMonitorDetail(summary, 'Assigned Engineer', inspection.engineer);
        appendMonitorDetail(summary, 'Current Phase', inspection.status);
        appendMonitorDetail(summary, 'Official Schedule', inspection.scheduled_at);
        if (inspection.started_at && inspection.started_at !== 'Not set') {
            appendMonitorDetail(summary, 'Actual Started', inspection.started_at);
        }
        inspectionMonitorContent.appendChild(summary);

        if (inspection.hold_reason) {
            const hold = document.createElement('section');
            hold.className = 'inspection-monitor-modal__hold';
            const title = document.createElement('strong');
            const reason = document.createElement('p');
            const when = document.createElement('span');
            title.textContent = 'Hold Reason';
            reason.textContent = inspection.hold_reason;
            when.textContent = 'Put on hold: ' + (inspection.held_at || 'Not set');
            hold.append(title, reason, when);
            inspectionMonitorContent.appendChild(hold);
        }

        const timeline = document.createElement('section');
        timeline.className = 'inspection-monitor-modal__timeline';
        const timelineTitle = document.createElement('strong');
        timelineTitle.textContent = 'Progress Timeline';
        const timelineList = document.createElement('div');
        (inspection.timeline || []).forEach(function (step) {
            const row = document.createElement('div');
            const hasTime = step.at && step.at !== 'Not set';
            row.dataset.phase = step.label;
            row.classList.toggle('is-current', step.label === inspection.status);
            row.classList.toggle('is-complete', hasTime && step.label !== inspection.status);
            const label = document.createElement('strong');
            const time = document.createElement('span');
            label.textContent = step.label;
            time.textContent = hasTime ? step.at : '';
            row.append(label, time);
            timelineList.appendChild(row);
        });
        timeline.append(timelineTitle, timelineList);
        inspectionMonitorContent.appendChild(timeline);

        if (inspection.report) {
            const report = document.createElement('section');
            report.className = 'inspection-monitor-modal__report';
            const reportTitle = document.createElement('strong');
            reportTitle.textContent = 'Submitted Report';
            report.appendChild(reportTitle);
            const findings = document.createElement('p');
            findings.textContent = inspection.report.findings || 'No findings provided.';
            report.appendChild(findings);
            const materials = inspection.report.materials || [];
            if (materials.length) {
                const list = document.createElement('div');
                list.className = 'inspection-monitor-modal__materials';
                materials.forEach(function (item) {
                    const row = document.createElement('div');
                    row.textContent = [item.type, item.name, item.quantity + ' ' + item.unit, item.remarks || 'No remarks'].join(' | ');
                    list.appendChild(row);
                });
                report.appendChild(list);
            } else {
                const empty = document.createElement('p');
                empty.textContent = 'No submitted material recommendations.';
                report.appendChild(empty);
            }
            inspectionMonitorContent.appendChild(report);
        }
    };

    const setInspectionMonitorFeedback = function (message, isError) {
        if (!inspectionMonitorFeedback) return;
        inspectionMonitorFeedback.textContent = message || '';
        inspectionMonitorFeedback.hidden = !message;
        inspectionMonitorFeedback.classList.toggle('is-error', Boolean(isError));
    };

    const refreshInspectionMonitor = function () {
        if (!inspectionMonitorId || inspectionMonitorRefreshing) return;
        inspectionMonitorRefreshing = true;
        inspectionMonitorRefresh.disabled = true;
        inspectionMonitorRefresh.textContent = 'Refreshing...';
        setInspectionMonitorFeedback('', false);
        const url = new URL(window.location.href);
        url.searchParams.set('action', 'monitor_inspection');
        url.searchParams.set('inspection_id', String(inspectionMonitorId));
        fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.json().then(function (data) { return { response: response, data: data }; }); })
            .then(function (result) {
                if (!result.response.ok || result.data.success !== true) throw new Error(result.data.message || 'Unable to refresh inspection monitoring.');
                renderInspectionMonitor(result.data.inspection);
                inspectionMonitorRefreshed.textContent = 'Last refreshed: ' + new Date().toLocaleString();
            })
            .catch(function (error) {
                setInspectionMonitorFeedback(error.message || 'Unable to refresh inspection monitoring. Previous data is still shown.', true);
            })
            .finally(function () {
                inspectionMonitorRefreshing = false;
                inspectionMonitorRefresh.disabled = false;
                inspectionMonitorRefresh.textContent = 'Refresh';
            });
    };

    const closeInspectionMonitor = function () {
        if (inspectionMonitorRefreshing) return;
        inspectionMonitorModal.hidden = true;
        inspectionMonitorTrigger?.focus();
    };

    document.addEventListener('click', function (event) {
        const stockButton = event.target.closest('[data-inspection-stock-open]');
        if (stockButton) {
            openInspectionStockModal(stockButton);
            return;
        }
        if (event.target === inspectionStockModal || event.target.closest('[data-inspection-stock-close]')) {
            closeInspectionStockModal();
            return;
        }
        const openButton = event.target.closest('[data-inspection-monitor-open]');
        if (openButton) {
            inspectionMonitorId = Number.parseInt(openButton.dataset.inspectionId || '0', 10);
            if (!inspectionMonitorId) return;
            inspectionMonitorTrigger = openButton;
            inspectionMonitorModal.hidden = false;
            inspectionMonitorModal.querySelector('[data-inspection-monitor-close]')?.focus();
            refreshInspectionMonitor();
            return;
        }
        if (event.target === inspectionMonitorModal || event.target.closest('[data-inspection-monitor-close]')) {
            closeInspectionMonitor();
        }
    });
    inspectionMonitorRefresh?.addEventListener('click', refreshInspectionMonitor);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !inspectionStockModal.hidden) {
            event.preventDefault();
            closeInspectionStockModal();
            return;
        }
        if (event.key === 'Escape' && !inspectionMonitorModal.hidden) {
            event.preventDefault();
            event.stopImmediatePropagation();
            closeInspectionMonitor();
            return;
        }
        if (event.key === 'Tab' && !inspectionMonitorModal.hidden) {
            const controls = Array.from(inspectionMonitorModal.querySelectorAll('button:not([disabled])'));
            if (!controls.length) return;
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    const showConfirm = function (form, message, details, labels = {}) {
        pendingConfirmForm = form;
        form.dataset.confirmationMode = labels.mode || 'submit';
        const titleBox = confirmBox.querySelector('#inquiryConfirmTitle');
        const messageBox = confirmBox.querySelector('[data-inquiry-confirm-message]');
        const detailsBox = confirmBox.querySelector('[data-inquiry-confirm-details]');
        const cancelButton = confirmBox.querySelector('[data-inquiry-confirm-no]');
        const confirmButton = confirmBox.querySelector('[data-inquiry-confirm-yes]');
        if (titleBox) {
            titleBox.textContent = labels.title || 'Are you sure?';
        }

        if (messageBox) {
            messageBox.textContent = message;
        }

        if (cancelButton) {
            cancelButton.textContent = labels.cancel || 'No';
        }

        if (confirmButton) {
            confirmButton.textContent = labels.confirm || 'Yes';
        }

        if (detailsBox) {
            detailsBox.replaceChildren();
            if (details && details.length) {
                details.forEach(function (detail) {
                    const term = document.createElement('dt');
                    const description = document.createElement('dd');
                    term.textContent = detail.label;
                    description.textContent = detail.value || 'Not set';
                    detailsBox.append(term, description);
                });
                detailsBox.hidden = false;
            } else {
                detailsBox.hidden = true;
            }
        }

        confirmBox.hidden = false;
        confirmBox.querySelector('[data-inquiry-confirm-no]')?.focus();
    };

    const closeConfirm = function () {
        if (confirmBox.dataset.submitting === '1') {
            return;
        }
        if (pendingConfirmForm?.dataset.confirmationMode === 'quotation-item-remove') {
            pendingQuotationItemRemoval = null;
        }
        if (pendingConfirmForm) {
            delete pendingConfirmForm.dataset.confirmationMode;
        }
        pendingConfirmForm = null;
        confirmBox.hidden = true;
    };

    const lockConfirmForQuotationDraft = function () {
        const cancelButton = confirmBox.querySelector('[data-inquiry-confirm-no]');
        const confirmButton = confirmBox.querySelector('[data-inquiry-confirm-yes]');
        confirmBox.dataset.submitting = '1';
        if (cancelButton) {
            cancelButton.disabled = true;
        }
        if (confirmButton) {
            confirmButton.disabled = true;
            confirmButton.textContent = 'Creating draft...';
        }
    };

    const inquiryReviewHasChanges = function (modal) {
        return false;
    };

    const discardInquiryReviewChanges = function (modal) {
        return modal;
    };

    const closeDiscardConfirm = function (keepEditing = false) {
        const modal = pendingDiscardModal;
        const keepAction = pendingDiscardKeepAction;
        pendingDiscardModal = null;
        pendingDiscardAction = null;
        pendingDiscardKeepAction = null;
        discardConfirmBox.hidden = true;

        if (keepEditing && modal) {
            keepAction?.();
            modal.querySelector('select[name="engineer_id"], input[name="inspection_date"]')?.focus();
        }
    };

    const showDiscardConfirm = function (modal, onDiscard, onKeepEditing) {
        pendingDiscardModal = modal;
        pendingDiscardAction = onDiscard;
        pendingDiscardKeepAction = onKeepEditing;
        discardConfirmBox.hidden = false;
        discardConfirmBox.querySelector('[data-inquiry-discard-keep]')?.focus();
    };

    const closeQuotationDraftDiscardConfirm = function () {
        pendingQuotationDraftDiscard = null;
        quotationDraftDiscardBox.hidden = true;
    };

    const showQuotationDraftDiscardConfirm = function (onDiscard) {
        pendingQuotationDraftDiscard = onDiscard;
        quotationDraftDiscardBox.hidden = false;
        quotationDraftDiscardBox.querySelector('[data-quotation-draft-discard-keep]')?.focus();
    };

    const closePrerequisiteNotice = function () {
        prerequisiteNotice.hidden = true;
    };

    const showPrerequisiteNotice = function (message) {
        const messageBox = prerequisiteNotice.querySelector('[data-prerequisite-notice-message]');
        if (messageBox) {
            messageBox.textContent = message;
        }

        prerequisiteNotice.hidden = false;
        prerequisiteNotice.querySelector('[data-prerequisite-notice-ok]')?.focus();
    };

    const closeModal = function (modal) {
        if (!modal || modal.dataset.quotationSending === '1' || modal.dataset.reviewSaving === '1') {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('inquiry-modal-open');
        sessionStorage.removeItem('edgeLastInquiryModal');

        if (lastOpenButton) {
            lastOpenButton.focus();
            lastOpenButton = null;
        }

        document.dispatchEvent(new CustomEvent('edge:inquiry-modal-closed'));
    };

    const setQuotationSendingState = function (modal, isSending) {
        if (!modal) {
            return;
        }

        if (isSending) {
            modal.dataset.quotationSending = '1';
            modal.classList.add('is-quotation-sending');
            modal.setAttribute('aria-busy', 'true');
            modal.inert = true;
            modal.querySelectorAll('button').forEach(function (button) {
                button.dataset.sendLockWasDisabled = button.disabled ? '1' : '0';
                button.disabled = true;
            });
            modal.querySelectorAll('.inquiry-quote-edit-link, .inquiry-quote-pdf-link').forEach(function (link) {
                link.dataset.sendLockTabindex = link.getAttribute('tabindex') ?? '__none__';
                link.setAttribute('tabindex', '-1');
                link.setAttribute('aria-disabled', 'true');
            });
            return;
        }

        delete modal.dataset.quotationSending;
        modal.classList.remove('is-quotation-sending');
        modal.removeAttribute('aria-busy');
        modal.inert = false;
        modal.querySelectorAll('[data-send-lock-was-disabled]').forEach(function (button) {
            button.disabled = button.dataset.sendLockWasDisabled === '1';
            delete button.dataset.sendLockWasDisabled;
        });
        modal.querySelectorAll('[data-send-lock-tabindex]').forEach(function (link) {
            const previousTabindex = link.dataset.sendLockTabindex;
            if (previousTabindex === '__none__') {
                link.removeAttribute('tabindex');
            } else {
                link.setAttribute('tabindex', previousTabindex);
            }
            link.removeAttribute('aria-disabled');
            delete link.dataset.sendLockTabindex;
        });
    };

    const setReviewSavingState = function (modal, isSaving) {
        if (!modal) {
            return;
        }

        if (isSaving) {
            modal.dataset.reviewSaving = '1';
            modal.classList.add('is-review-saving');
            modal.setAttribute('aria-busy', 'true');
            modal.querySelectorAll('button, select, textarea, input:not([type="hidden"])').forEach(function (control) {
                control.dataset.reviewLockWasDisabled = control.disabled ? '1' : '0';
                control.disabled = true;
            });
            modal.querySelectorAll('a').forEach(function (link) {
                link.dataset.reviewLockTabindex = link.getAttribute('tabindex') ?? '__none__';
                link.setAttribute('tabindex', '-1');
                link.setAttribute('aria-disabled', 'true');
            });
            return;
        }

        delete modal.dataset.reviewSaving;
        modal.classList.remove('is-review-saving');
        modal.removeAttribute('aria-busy');
        modal.querySelectorAll('[data-review-lock-was-disabled]').forEach(function (control) {
            control.disabled = control.dataset.reviewLockWasDisabled === '1';
            delete control.dataset.reviewLockWasDisabled;
        });
        modal.querySelectorAll('[data-review-lock-tabindex]').forEach(function (link) {
            const previousTabindex = link.dataset.reviewLockTabindex;
            if (previousTabindex === '__none__') {
                link.removeAttribute('tabindex');
            } else {
                link.setAttribute('tabindex', previousTabindex);
            }
            link.removeAttribute('aria-disabled');
            delete link.dataset.reviewLockTabindex;
        });
    };

    const setInspectionSchedulingState = function (modal, isScheduling) {
        if (!modal) {
            return;
        }

        if (isScheduling) {
            modal.dataset.inspectionScheduling = '1';
            modal.classList.add('is-inspection-scheduling');
            modal.setAttribute('aria-busy', 'true');
            // I-lock ang modal para hindi mabago ang schedule habang nagsa-save.
            modal.inert = true;
            modal.querySelectorAll('button, select, textarea, input:not([type="hidden"])').forEach(function (control) {
                control.dataset.scheduleLockWasDisabled = control.disabled ? '1' : '0';
                control.disabled = true;
            });
            modal.querySelectorAll('a').forEach(function (link) {
                link.dataset.scheduleLockTabindex = link.getAttribute('tabindex') ?? '__none__';
                link.setAttribute('tabindex', '-1');
                link.setAttribute('aria-disabled', 'true');
            });
            return;
        }

        delete modal.dataset.inspectionScheduling;
        modal.classList.remove('is-inspection-scheduling');
        modal.removeAttribute('aria-busy');
        modal.inert = false;
        modal.querySelectorAll('[data-schedule-lock-was-disabled]').forEach(function (button) {
            button.disabled = button.dataset.scheduleLockWasDisabled === '1';
            delete button.dataset.scheduleLockWasDisabled;
        });
        modal.querySelectorAll('[data-schedule-lock-tabindex]').forEach(function (link) {
            const previousTabindex = link.dataset.scheduleLockTabindex;
            if (previousTabindex === '__none__') {
                link.removeAttribute('tabindex');
            } else {
                link.setAttribute('tabindex', previousTabindex);
            }
            link.removeAttribute('aria-disabled');
            delete link.dataset.scheduleLockTabindex;
        });
    };

    const applyArchivedReadOnly = function (container) {
        if (!container || container.dataset.readonlyApplied === '1') return;
        container.dataset.readonlyApplied = '1';
        container.querySelectorAll('input:not([type="hidden"]), textarea').forEach(function (field) {
            field.readOnly = true;
            field.setAttribute('aria-readonly', 'true');
            if (field.matches('[type="checkbox"], [type="radio"], [type="file"]')) {
                field.disabled = true;
            }
        });
        container.querySelectorAll('select, button').forEach(function (control) {
            control.disabled = true;
        });
        container.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
            });
        });
    };

    const openModal = function (modal) {
        if (!modal) {
            return;
        }

        applyArchivedReadOnly(modal.querySelector('[data-archived-readonly="true"]'));
        modal.hidden = false;
        document.body.classList.add('inquiry-modal-open');
        sessionStorage.setItem('edgeLastInquiryModal', modal.id);

        const inquiryId = Number.parseInt(modal.dataset.inquiryId || '0', 10);
        if (inquiryId > 0) {
            document.dispatchEvent(new CustomEvent('edge:inquiry-opened', {
                detail: { inquiryId: inquiryId },
            }));
        }

        const closeButton = modal.querySelector('[data-inquiry-modal-close]');
        if (closeButton) {
            closeButton.focus();
        }
    };

    document.querySelectorAll('[data-archived-readonly="true"]').forEach(applyArchivedReadOnly);

    const activateModalTab = function (modal, target) {
        if (!modal || !target || modal.dataset.quotationSending === '1' || modal.dataset.reviewSaving === '1' || modal.dataset.inspectionScheduling === '1') {
            return false;
        }

        const tabs = Array.from(modal.querySelectorAll('[data-inquiry-tab]'));
        const panels = Array.from(modal.querySelectorAll('[data-inquiry-panel]'));
        const targetTab = tabs.find(function (tab) {
            return tab.getAttribute('data-inquiry-tab') === target;
        });
        if (!targetTab || targetTab.disabled || targetTab.classList.contains('chip-disabled')) {
            return false;
        }

        tabs.forEach(function (tab) {
            const isSelected = tab === targetTab;
            tab.classList.toggle('is-active', isSelected);
            tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-inquiry-panel') !== target;
            panel.classList.toggle('is-active', !panel.hidden);
        });
        return true;
    };

    const pushModalHistory = function (modal, target) {
        const url = new URL(window.location.href);
        const currentDepth = window.history.state?.inquiryModalId === modal.id
            ? Number.parseInt(window.history.state.inquiryDepth || '0', 10)
            : 0;
        url.searchParams.set('open', modal.id);
        url.searchParams.set('tab', target);
        window.history.pushState({
            inquiryModalId: modal.id,
            inquiryTab: target,
            inquiryDepth: currentDepth + 1,
        }, '', url);
    };

    // Shared opener ito para sa Admin bell at ibang inquiry links sa parehong page.
    window.edgeOpenInquiryModalAtTab = function (modalId, targetTab) {
        const modal = document.getElementById(String(modalId || ''));
        if (!modal) {
            return false;
        }

        document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(function (openInquiryModal) {
            if (openInquiryModal !== modal) {
                closeModal(openInquiryModal);
            }
        });

        openModal(modal);
        const requestedTab = String(targetTab || 'client');
        const activeTab = activateModalTab(modal, requestedTab) ? requestedTab : 'client';
        pushModalHistory(modal, activeTab);
        return true;
    };

    const requestCloseModal = function (modal) {
        if (modal?.dataset.quotationSending === '1' || modal?.dataset.reviewSaving === '1' || modal?.dataset.inspectionScheduling === '1') {
            return;
        }

        if (inquiryReviewHasChanges(modal)) {
            showDiscardConfirm(modal, function () {
                discardInquiryReviewChanges(modal);
                closeModal(modal);
                window.history.replaceState({}, document.title, 'inquiries.php');
            });
            return;
        }

        closeModal(modal);
        window.history.replaceState({}, document.title, 'inquiries.php');
    };

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-inquiry-modal-open]');
        if (!button) {
            return;
        }

        event.stopPropagation();
        const modalId = button.getAttribute('data-inquiry-modal-open');
        lastOpenButton = button;
        const modal = document.getElementById(modalId);
        openModal(modal);

        if (modal) {
            const requestedTab = button.getAttribute('data-inquiry-open-tab') || 'client';
            let activeTab = requestedTab;
            if (!activateModalTab(modal, activeTab)) {
                activeTab = 'client';
                activateModalTab(modal, activeTab);
            }
            pushModalHistory(modal, activeTab);
        }
    });

    document.querySelectorAll('.inquiry-status-link, .inquiry-view-link').forEach(function (link) {
        link.addEventListener('click', function (event) {
            // Bagong filter view ito, kaya mula sa taas magsisimula ang listahan.
            sessionStorage.removeItem('edgeLastInquiryModal');
            sessionStorage.setItem(listStartAtTopKey, '1');
            showPageLoading();
            event.preventDefault();

            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    window.location.assign(link.href);
                });
            });
        });
    });

    const filterForm = document.querySelector('.inquiry-filter-bar');
    filterForm?.addEventListener('submit', showPageLoading);

    document.addEventListener('click', function (event) {
        const modal = event.target.closest('.inquiry-modal');
        if (modal && (event.target === modal || event.target.closest('[data-inquiry-modal-close]'))) {
            requestCloseModal(modal);
        }
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.inquiry-modal.is-quotation-sending, .inquiry-modal.is-review-saving, .inquiry-modal.is-inspection-scheduling')) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);

    const closeArchiveModal = function (modal) {
        if (modal) {
            modal.hidden = true;
            document.dispatchEvent(new CustomEvent('edge:inquiry-modal-closed'));
        }
    };

    document.addEventListener('click', function (event) {
        const openButton = event.target.closest('[data-archive-modal-open]');
        if (openButton) {
            const modal = document.getElementById(openButton.getAttribute('data-archive-modal-open'));
            if (modal) {
                modal.hidden = false;
                modal.querySelector('textarea')?.focus();
            }
            return;
        }

        const modal = event.target.closest('.inquiry-archive-modal');
        if (modal && (event.target === modal || event.target.closest('[data-archive-modal-close]'))) {
            closeArchiveModal(modal);
        }
    });

    document.querySelectorAll('.inquiry-schedule-form').forEach(function (form) {
        const dateInput = form.querySelector('.js-admin-inspection-date');
        const dateButton = form.querySelector('.js-admin-date-picker-button');
        const modal = form.closest('.inquiry-modal');
        const draftKey = modal?.dataset.inquiryId ? 'edgeInquiryScheduleDraft:' + modal.dataset.inquiryId : '';
        const submitButton = form.querySelector('[data-schedule-submit]');
        const engineerInput = form.querySelector('select[name="engineer_id"]');
        const reasonWrap = form.querySelector('[data-admin-reschedule-reason]');
        const reasonInput = form.querySelector('textarea[name="reschedule_reason"]');
        const reasonFeedback = form.querySelector('[data-admin-reschedule-reason-feedback]');
        const scheduleFeedback = form.querySelector('[data-admin-schedule-feedback]');
        const rescheduleGuidance = form.querySelector('[data-admin-reschedule-guidance]');
        const hasExistingSchedule = form.dataset.hasExistingSchedule === '1';
        const initialSubmitDisabled = Boolean(submitButton?.disabled);
        const defaultSubmitLabel = submitButton?.textContent || 'Confirm Schedule & Notify Client and Engineer';

        const hasAssignmentChanges = function () {
            return (engineerInput?.value || '') !== (form.dataset.originalEngineer || '')
                || dateInput.value !== (form.dataset.originalDate || '');
        };

        const syncInvalidUi = function () {
            if (form.dataset.submitAttempted !== '1') {
                return;
            }

            form.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.classList.toggle('is-invalid', !field.checkValidity());
            });
        };

        if (!dateInput) {
            return;
        }

        if (draftKey && !hasExistingSchedule) {
            try {
                const draft = JSON.parse(sessionStorage.getItem(draftKey) || '{}');
                if (draft.engineer_id) form.querySelector('select[name="engineer_id"]').value = draft.engineer_id;
                if (draft.inspection_date) dateInput.value = draft.inspection_date;
                if (draft.site_notes) form.querySelector('textarea[name="site_notes"]').value = draft.site_notes;
                if (draft.reschedule_reason && reasonInput) reasonInput.value = draft.reschedule_reason;
            } catch (error) {
                sessionStorage.removeItem(draftKey);
            }
        } else if (draftKey && hasExistingSchedule) {
            // Lumang reschedule draft ay hindi dapat magmukhang bagong change.
            sessionStorage.removeItem(draftKey);
        }

        const manilaDateFormatter = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            hourCycle: 'h23',
        });
        let engineerBusyOnDate = false;
        let availabilityRequestId = 0;

        const getManilaNow = function () {
            const parts = manilaDateFormatter.formatToParts(new Date()).reduce(function (values, part) {
                if (part.type !== 'literal') {
                    values[part.type] = part.value;
                }
                return values;
            }, {});

            return {
                date: parts.year + '-' + parts.month + '-' + parts.day,
                hour: Number.parseInt(parts.hour || '0', 10),
            };
        };

        const addDaysToDate = function (dateValue, days) {
            const parts = dateValue.split('-').map(Number);
            const date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] + days));
            return date.toISOString().slice(0, 10);
        };

        const getEarliestScheduleDate = function () {
            const manilaNow = getManilaNow();
            return manilaNow.hour >= 17
                ? addDaysToDate(manilaNow.date, 1)
                : manilaNow.date;
        };

        const syncDateAvailability = function () {
            const earliestDate = getEarliestScheduleDate();
            const latestDate = addDaysToDate(getManilaNow().date, 30);
            const originalDate = form.dataset.originalDate || '';
            const keepExistingDate = originalDate !== ''
                && dateInput.value === originalDate
                && (originalDate < earliestDate || originalDate > latestDate);
            dateInput.min = keepExistingDate ? '' : earliestDate;
            dateInput.max = keepExistingDate ? '' : latestDate;

            if (!keepExistingDate && dateInput.value && (dateInput.value < earliestDate || dateInput.value > latestDate)) {
                dateInput.value = '';
            }
        };

        const loadEngineerAvailability = function () {
            const engineerId = Number.parseInt(engineerInput?.value || '0', 10);
            const selectedDate = dateInput.value;
            const requestId = ++availabilityRequestId;

            if (engineerId <= 0 || selectedDate === '') {
                engineerBusyOnDate = false;
                return;
            }

            const url = new URL('/codesamplecaps/ADMIN/sidebar/inquiries/php/engineer_schedule_availability.php', window.location.origin);
            url.searchParams.set('engineer_id', String(engineerId));
            url.searchParams.set('date', selectedDate);
            url.searchParams.set('exclude_inspection_id', modal?.dataset.inspectionId || '0');

            fetch(url.toString(), { headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then(function (response) {
                    if (!response.ok) throw new Error('Schedule check failed.');
                    return response.json();
                })
                .then(function (data) {
                    if (requestId !== availabilityRequestId || !data.success) return;
                    engineerBusyOnDate = data.engineer_busy === true;
                    syncRescheduleControls(false);
                })
                .catch(function () {
                    if (requestId !== availabilityRequestId) return;
                    // Server validation ang huling safety kapag may temporary connection problem.
                    engineerBusyOnDate = false;
                });
        };

        const scheduleValidationMessage = function () {
            if (!engineerInput?.value) return 'Select an Engineer.';
            if (!dateInput.value) return 'Choose an inspection date.';
            if (!dateInput.checkValidity()) return 'Choose a valid inspection date.';
            if (engineerBusyOnDate) return 'This Engineer already has an inspection on this date.';
            return '';
        };

        const setFeedback = function (element, message) {
            if (!element) return;
            element.textContent = message;
            element.hidden = message === '';
        };

        const setRescheduleGuidance = function (message, state) {
            if (!rescheduleGuidance || rescheduleGuidance.textContent === message) return;
            rescheduleGuidance.textContent = message;
            rescheduleGuidance.dataset.state = state;
            rescheduleGuidance.hidden = message === '';
        };

        const wasScheduleFieldEdited = function () {
            return engineerInput?.dataset.touched === '1'
                || dateInput.dataset.touched === '1';
        };

        const validRescheduleReason = function () {
            const value = reasonInput?.value || '';
            if (value.length > 1000) {
                return 'Reschedule note must not exceed 1000 characters.';
            }
            return '';
        };

        const syncRescheduleControls = function (forceFeedback) {
            if (!submitButton || form.dataset.submitting === '1') {
                return;
            }

            syncDateAvailability();
            if (!hasExistingSchedule) {
                submitButton.disabled = initialSubmitDisabled;
                return;
            }

            const changed = hasAssignmentChanges();
            const scheduleMessage = changed ? scheduleValidationMessage() : '';
            const hasValidScheduleChange = changed && scheduleMessage === '';
            // Huwag magpakita ng red error pag kabukas lang ng reschedule popup.
            const showScheduleMessage = changed && scheduleMessage !== ''
                && forceFeedback
                && form.dataset.submitAttempted === '1';
            setFeedback(scheduleFeedback, showScheduleMessage ? scheduleMessage : '');

            if (reasonWrap && reasonInput) {
                reasonWrap.hidden = !hasValidScheduleChange;
                reasonInput.disabled = !hasValidScheduleChange;
                reasonInput.required = false;

                if (!hasValidScheduleChange) {
                    reasonInput.setCustomValidity('');
                    reasonInput.classList.remove('is-invalid');
                    setFeedback(reasonFeedback, '');
                } else {
                    const reasonMessage = validRescheduleReason();
                    reasonInput.setCustomValidity(reasonMessage);
                    const showReasonMessage = reasonMessage !== ''
                        && (forceFeedback || reasonInput.dataset.touched === '1' || reasonInput.value !== '');
                    reasonInput.classList.toggle('is-invalid', showReasonMessage);
                    setFeedback(reasonFeedback, showReasonMessage ? reasonMessage : '');
                }
            }

            const reasonMessage = hasValidScheduleChange && reasonInput ? validRescheduleReason() : '';
            submitButton.disabled = !hasValidScheduleChange || reasonMessage !== '';

            if (!changed) {
                setRescheduleGuidance('Change the assigned Engineer or date to reschedule.', 'neutral');
            } else if (!hasValidScheduleChange && form.dataset.submitAttempted === '1') {
                setRescheduleGuidance(scheduleMessage, 'error');
            } else {
                setRescheduleGuidance(hasValidScheduleChange ? 'Ready to update the schedule and email the Client and Engineer.' : '', hasValidScheduleChange ? 'ready' : 'neutral');
            }
        };

        const hideDatePickerState = function () {
            dateInput.dataset.pickerOpen = '0';
            dateButton?.classList.remove('is-active');
        };

        dateButton?.addEventListener('click', function () {
            const isOpen = dateInput.dataset.pickerOpen === '1';

            if (isOpen) {
                hideDatePickerState();
                dateInput.blur();
                return;
            }

            dateInput.dataset.pickerOpen = '1';
            dateButton.classList.add('is-active');

            if (typeof dateInput.showPicker === 'function') {
                dateInput.showPicker();
            } else {
                dateInput.focus();
            }
        });

        dateInput.addEventListener('change', function () {
            hideDatePickerState();
            syncDateAvailability();
        });
        dateInput.addEventListener('blur', function () {
            dateInput.dataset.pickerOpen = '0';
            dateButton?.classList.remove('is-active');
        });
        syncDateAvailability();

        const saveDraft = function () {
            if (!draftKey) {
                return;
            }

            const draft = {
                engineer_id: form.querySelector('select[name="engineer_id"]')?.value || '',
                inspection_date: dateInput.value,
                site_notes: form.querySelector('textarea[name="site_notes"]')?.value || '',
                reschedule_reason: reasonInput?.value || '',
            };
            sessionStorage.setItem(draftKey, JSON.stringify(draft));
        };

        form.querySelectorAll('select, input, textarea').forEach(function (field) {
            field.addEventListener('input', saveDraft);
            field.addEventListener('change', saveDraft);
            field.addEventListener('input', function () {
                field.dataset.touched = '1';
                syncRescheduleControls(false);
            });
            field.addEventListener('change', function () {
                field.dataset.touched = '1';
                syncRescheduleControls(false);
            });
            field.addEventListener('input', function () {
                if (form.dataset.submitAttempted === '1') syncInvalidUi();
            });
            field.addEventListener('change', function () {
                if (form.dataset.submitAttempted === '1') syncInvalidUi();
            });
            field.addEventListener('invalid', function () {
                form.dataset.submitAttempted = '1';
                field.classList.add('is-invalid');
            });
        });

        form.addEventListener('submit', function (event) {
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            form.dataset.submitAttempted = '1';
            syncRescheduleControls(true);
            if (hasExistingSchedule) {
                const changed = hasAssignmentChanges();
                const scheduleMessage = changed ? scheduleValidationMessage() : '';
                const reasonMessage = changed && scheduleMessage === '' ? validRescheduleReason() : '';
                if (!changed || scheduleMessage !== '' || reasonMessage !== '') {
                    event.preventDefault();
                    if (scheduleMessage !== '') {
                        (engineerInput?.value ? dateInput : engineerInput)?.focus();
                    } else if (changed && reasonMessage !== '') {
                        reasonInput?.focus();
                    } else {
                        engineerInput?.focus();
                    }
                    return;
                }
            }
            if (!form.checkValidity()) {
                syncInvalidUi();
                form.reportValidity();
                return;
            }

            if (form.dataset.confirmed === '1') {
                const scheduleRequestData = new FormData(form);
                form.dataset.submitting = '1';
                if (submitButton) {
                    const spinner = document.createElement('span');
                    const label = document.createElement('span');
                    spinner.className = 'inquiry-send-spinner';
                    spinner.setAttribute('aria-hidden', 'true');
                    label.textContent = 'Scheduling inspection...';
                    submitButton.disabled = true;
                    submitButton.classList.add('inquiry-send-button--loading');
                    submitButton.replaceChildren(spinner, label);
                }
                setInspectionSchedulingState(modal, true);
                delete form.dataset.confirmed;
                event.preventDefault();

                fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: scheduleRequestData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            return { ok: response.ok, data: data };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.data.success) {
                            throw new Error(result.data.message || 'Unable to save the inspection schedule.');
                        }

                        setInspectionSchedulingState(modal, false);
                        if (draftKey) sessionStorage.removeItem(draftKey);
                        // Tapos na ang update, kaya huwag nang ibalik ang reschedule popup.
                        sessionStorage.removeItem('edgeOpenRescheduleModal');
                        const scheduleEditor = form.closest('[data-inspection-schedule-editor]');
                        if (scheduleEditor) scheduleEditor.hidden = true;

                        // Ibalik si Admin sa Inspection tab para makita ang bagong schedule.
                        const redirectUrl = new URL(result.data.redirect || window.location.href, window.location.origin);
                        redirectUrl.searchParams.set('open', modal.id);
                        redirectUrl.searchParams.set('tab', 'inspection');
                        window.location.assign(redirectUrl.toString());
                    })
                    .catch(function (error) {
                        form.dataset.submitting = '0';
                        setInspectionSchedulingState(modal, false);
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.classList.remove('inquiry-send-button--loading');
                            submitButton.textContent = defaultSubmitLabel;
                        }
                        syncRescheduleControls(true);
                        if (typeof window.showToast === 'function') {
                            window.showToast(error.message || 'Unable to save the inspection schedule.', 'error');
                        } else {
                            window.alert(error.message || 'Unable to save the inspection schedule.');
                        }
                    });
                return;
            }

            event.preventDefault();
            const isReschedule = hasExistingSchedule;
            showConfirm(
                form,
                isReschedule
                    ? 'The updated schedule will be emailed to the Client and Engineer.'
                    : 'The schedule will be emailed to the Client and Engineer.',
                null,
                {
                    title: isReschedule ? 'Update inspection?' : 'Confirm inspection?',
                    cancel: 'Back',
                    confirm: isReschedule ? 'Update Schedule' : 'Confirm',
                }
            );
        });
        engineerInput?.addEventListener('change', loadEngineerAvailability);
        dateInput.addEventListener('change', loadEngineerAvailability);
        loadEngineerAvailability();
        syncRescheduleControls(false);
    });

    const bindInquiryArchiveForm = function (form) {
        if (!form || form.dataset.archiveHandlersBound === '1') {
            return;
        }
        form.dataset.archiveHandlersBound = '1';

        const reasonSelect = form.querySelector('select[name="archive_reason"]');
        const manualReason = form.querySelector('textarea[name="archive_reason_other"]');
        const manualReasonMark = form.querySelector('[data-archive-other-required]');
        const manualReasonLabel = form.querySelector('[data-archive-manual-label]');

        const syncArchiveReasonRequirement = function () {
            if (!reasonSelect || !manualReason) {
                return;
            }

            const isOther = reasonSelect.value === 'Other';
            manualReason.required = isOther;
            if (manualReasonLabel) {
                manualReasonLabel.childNodes[0].nodeValue = isOther
                    ? 'Manual Reason / Notes '
                    : 'Additional Notes (Optional) ';
            }
            if (manualReasonMark) {
                manualReasonMark.hidden = !isOther;
            }
            if (!isOther) {
                manualReason.classList.remove('is-invalid');
                manualReason.setCustomValidity('');
            }
        };

        reasonSelect?.addEventListener('change', syncArchiveReasonRequirement);
        syncArchiveReasonRequirement();

        manualReason?.addEventListener('input', function () {
            manualReason.setCustomValidity('');
        });

        form.querySelectorAll('textarea, input, select').forEach(function (field) {
            field.addEventListener('invalid', function () {
                field.classList.add('is-invalid');
            });
            field.addEventListener('input', function () {
                field.classList.toggle('is-invalid', !field.checkValidity());
            });
        });

        form.addEventListener('submit', function (event) {
            const compactOtherReason = manualReason?.value.replace(/\s+/g, '') || '';
            const hasOtherLetter = /[A-Za-z]/.test(manualReason?.value || '');
            const manualNoteIsRequired = reasonSelect?.value === 'Other';
            const manualNoteHasText = manualReason?.value.trim() !== '';
            if ((manualNoteIsRequired || manualNoteHasText) && (!hasOtherLetter || compactOtherReason.length < 5)) {
                event.preventDefault();
                manualReason.setCustomValidity('Enter a clear manual reason with at least 5 characters.');
                manualReason.reportValidity();
                manualReason.focus();
                return;
            }
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Archive this inquiry? It will move to Archive list.');
        });
    };

    const bindInquiryArchiveForms = function (root) {
        root.querySelectorAll('.inquiry-archive-form').forEach(bindInquiryArchiveForm);
    };

    bindInquiryArchiveForms(document);

    document.querySelectorAll('.inquiry-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Permanently delete this archived inquiry? This cannot be undone.');
        });
    });

    document.querySelectorAll('.inquiry-restore-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Restore this inquiry to active list?');
        });
    });

    document.addEventListener('click', function (event) {
        const tab = event.target.closest('[data-inquiry-tab]');
        const modal = tab?.closest('.inquiry-modal');
        if (!tab || !modal) {
            return;
        }

        const target = tab.getAttribute('data-inquiry-tab');
        if (tab.classList.contains('chip-disabled')) {
            if (target === 'quotation') {
                showPrerequisiteNotice('Quotation is handled by the Engineer after site inspection.');
            } else if (target === 'inspection') {
                const prerequisite = modal.querySelector('[data-prerequisite-check="client-quotation-approval"]');
                showPrerequisiteNotice(
                    prerequisite?.dataset.prerequisiteMessage
                    || 'Complete the quotation and wait for client approval before assigning an Engineer.'
                );
            }
            return;
        }

        if (tab.classList.contains('is-active')) {
            return;
        }

        activateModalTab(modal, target);
        pushModalHistory(modal, target);
    });

    const inquiryRefreshUrl = new URL(window.location.href);
    ['action', 'open', 'tab', 'viewed_inquiry'].forEach(function (key) {
        inquiryRefreshUrl.searchParams.delete(key);
    });
    let inquiryListRefreshInProgress = false;
    let inquiryListRefreshDeferred = false;
    let inquiryListRefreshSerial = 0;
    let requestedInquiryLatestId = 0;
    let appliedInquiryLatestId = Array.from(document.querySelectorAll('[data-inquiry-card-id]')).reduce(function (latestId, card) {
        return Math.max(latestId, Number.parseInt(card.dataset.inquiryCardId || '0', 10));
    }, 0);
    const pendingInquiryCardIds = new Set();

    const inquiryRefreshIsBlocked = function () {
        return Boolean(document.querySelector('.inquiry-modal:not([hidden]), .inquiry-archive-modal:not([hidden])'));
    };

    const updateInquiryCounts = function (nextDocument) {
        nextDocument.querySelectorAll('[data-inquiry-count-key]').forEach(function (nextCount) {
            const key = nextCount.getAttribute('data-inquiry-count-key');
            const currentCount = key
                ? document.querySelector('[data-inquiry-count-key="' + CSS.escape(key) + '"]')
                : null;
            if (currentCount) {
                currentCount.textContent = nextCount.textContent;
            }
        });
    };

    const currentFiltersExpectNewInquiry = function () {
        const view = inquiryRefreshUrl.searchParams.get('view') || 'active';
        const status = inquiryRefreshUrl.searchParams.get('status') || '';
        const search = (inquiryRefreshUrl.searchParams.get('search') || '').trim();
        const quotationFilter = inquiryRefreshUrl.searchParams.get('quotation_filter') || '';

        return view === 'active'
            && search === ''
            && quotationFilter === ''
            && (status === '' || status === 'Pending Review');
    };

    const mergeNewInquiryCards = function (nextDocument) {
        const result = {
            success: false,
            insertedIds: [],
            missingIds: [],
        };
        if (!inquiryShell) {
            return result;
        }

        updateInquiryCounts(nextDocument);
        const nextShell = nextDocument.querySelector('.inquiries-shell');
        const nextList = nextShell?.querySelector(':scope > .inquiry-list');
        const nextEmptyState = nextShell?.querySelector(':scope > .inquiry-empty');
        if (!nextShell || (!nextList && !nextEmptyState)) {
            return result;
        }

        let currentList = inquiryShell.querySelector(':scope > .inquiry-list');
        const nextCards = nextList
            ? Array.from(nextList.querySelectorAll(':scope > [data-inquiry-card-id]'))
            : [];
        const nextCardsById = new Map(nextCards.map(function (card) {
            return [card.dataset.inquiryCardId || '', card];
        }));

        if (!currentList && nextCards.length > 0) {
            currentList = document.createElement('div');
            currentList.className = 'inquiry-list';
            const emptyState = inquiryShell.querySelector(':scope > .inquiry-empty');
            if (emptyState) {
                emptyState.replaceWith(currentList);
            } else {
                inquiryShell.appendChild(currentList);
            }
        }

        const scrollX = window.scrollX;
        const scrollY = window.scrollY;
        const currentIds = new Set(Array.from(inquiryShell.querySelectorAll(':scope > .inquiry-list > [data-inquiry-card-id]')).map(function (card) {
            return card.dataset.inquiryCardId || '';
        }));

        nextCards.slice().reverse().forEach(function (nextCard) {
            const inquiryId = nextCard.dataset.inquiryCardId || '';
            if (!currentList || inquiryId === '' || currentIds.has(inquiryId)) {
                return;
            }

            const importedCard = document.importNode(nextCard, true);
            currentList.prepend(importedCard);
            currentIds.add(inquiryId);
            result.insertedIds.push(inquiryId);
            bindInquiryArchiveForms(importedCard);
        });

        pendingInquiryCardIds.forEach(function (inquiryId) {
            if (currentIds.has(inquiryId)) {
                pendingInquiryCardIds.delete(inquiryId);
                return;
            }

            if (nextCardsById.has(inquiryId)) {
                result.missingIds.push(inquiryId);
                return;
            }

            if (currentFiltersExpectNewInquiry()) {
                result.missingIds.push(inquiryId);
                return;
            }

            // Tama ang response, pero hindi pasok ang card sa kasalukuyang filter.
            pendingInquiryCardIds.delete(inquiryId);
        });

        result.success = result.missingIds.length === 0;

        window.requestAnimationFrame(function () {
            window.scrollTo(scrollX, scrollY);
        });

        return result;
    };

    const refreshInquiryListFromCurrentFilters = function (latestId) {
        requestedInquiryLatestId = Math.max(requestedInquiryLatestId, Number.parseInt(latestId || '0', 10));
        if (!inquiryShell || (requestedInquiryLatestId <= appliedInquiryLatestId && pendingInquiryCardIds.size === 0)) {
            return;
        }

        if (inquiryRefreshIsBlocked()) {
            inquiryListRefreshDeferred = true;
            return;
        }

        if (inquiryListRefreshInProgress) {
            return;
        }

        inquiryListRefreshInProgress = true;
        inquiryListRefreshDeferred = false;
        const refreshSerial = ++inquiryListRefreshSerial;
        const refreshThroughId = requestedInquiryLatestId;
        let refreshSucceeded = false;

        fetch(inquiryRefreshUrl.toString(), {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Inquiry list refresh failed.');
                }
                return response.text();
            })
            .then(function (html) {
                if (refreshSerial !== inquiryListRefreshSerial || inquiryRefreshIsBlocked()) {
                    inquiryListRefreshDeferred = true;
                    return;
                }

                const nextDocument = new DOMParser().parseFromString(html, 'text/html');
                if (!nextDocument.querySelector('.inquiries-shell')) {
                    throw new Error('Inquiry list response is invalid.');
                }

                const mergeResult = mergeNewInquiryCards(nextDocument);
                if (!mergeResult.success) {
                    return;
                }
                appliedInquiryLatestId = Math.max(appliedInquiryLatestId, refreshThroughId);
                refreshSucceeded = true;
            })
            .catch(function () {
                // Panatilihin ang kasalukuyang listahan. Susubok ulit sa susunod na bell check.
            })
            .finally(function () {
                inquiryListRefreshInProgress = false;
                if (refreshSucceeded && requestedInquiryLatestId > appliedInquiryLatestId) {
                    refreshInquiryListFromCurrentFilters(requestedInquiryLatestId);
                }
            });
    };

    document.addEventListener('edge:admin-inquiry-notifications-updated', function (event) {
        const latestId = Number.parseInt(event.detail?.latest_id || '0', 10);
        const newItems = Array.isArray(event.detail?.new_items) ? event.detail.new_items : [];
        newItems.forEach(function (item) {
            const inquiryId = String(Number.parseInt(item?.id || '0', 10));
            if (inquiryId !== '0' && !document.querySelector('[data-inquiry-card-id="' + CSS.escape(inquiryId) + '"]')) {
                pendingInquiryCardIds.add(inquiryId);
            }
        });

        if (latestId > appliedInquiryLatestId || pendingInquiryCardIds.size > 0) {
            refreshInquiryListFromCurrentFilters(latestId);
        }
    });

    document.addEventListener('edge:inquiry-modal-closed', function () {
        if (inquiryListRefreshDeferred && !inquiryRefreshIsBlocked()) {
            refreshInquiryListFromCurrentFilters(requestedInquiryLatestId);
        }
    });

    const quotationStatusModals = Array.from(document.querySelectorAll('.inquiry-modal[data-inquiry-id]'));
    let quotationPollInProgress = false;
    let inspectionResponsePollInProgress = false;

    document.addEventListener('edge:inquiry-opened', function (event) {
        const inquiryId = Number.parseInt(event.detail?.inquiryId || '0', 10);
        const modal = document.querySelector('.inquiry-modal[data-inquiry-id="' + String(inquiryId) + '"]');
        if (modal) {
            delete modal.dataset.inspectionResponsePollReady;
        }
    });

    const updateInspectionResponseNote = function (modal, party, note) {
        const responseGrid = modal.querySelector('.inquiry-schedule-responses');
        if (!responseGrid) return;
        let card = responseGrid.querySelector('[data-schedule-response-note-card="' + party + '"]');
        if (!note) {
            card?.remove();
            return;
        }
        if (!card) {
            card = document.createElement('div');
            card.className = 'inquiry-detail inquiry-detail--wide';
            card.dataset.scheduleResponseNoteCard = party;
            const label = document.createElement('span');
            const value = document.createElement('strong');
            label.textContent = party === 'client' ? 'Client Request' : 'Engineer Request';
            value.dataset.scheduleResponseNote = party;
            card.append(label, value);
            responseGrid.append(card);
        }
        const value = card.querySelector('[data-schedule-response-note]');
        if (value) value.textContent = note;
    };

    const moveInspectionMonitorToTools = function (modal) {
        const monitorButton = modal?.querySelector('[data-inspection-monitor-open]');
        const tools = modal?.querySelector('.inquiry-modal__tools');
        if (!monitorButton || !tools || monitorButton.hidden) return;
        monitorButton.classList.add('inquiry-modal__monitor');
        monitorButton.setAttribute('aria-label', 'Monitor Inspection');
        const archiveButton = tools.querySelector('[data-archive-modal-open]');
        if (archiveButton) {
            archiveButton.insertAdjacentElement('beforebegin', monitorButton);
        } else {
            tools.prepend(monitorButton);
        }
        modal.querySelector('.inquiry-modal__head')?.classList.add('has-inspection-monitor');
    };

    const applyFinalScheduleLock = function (modal, isFinal, phase, isLocked) {
        if (!modal) return;
        const inspectionStarted = isLocked === true
            || ['Ongoing', 'On Hold', 'Completed', 'Submitted'].includes(phase || 'Assigned');
        const scheduleForm = modal.querySelector('[data-inquiry-inspection-form]');
        const responseGrid = modal.querySelector('.inquiry-schedule-responses');
        if (isFinal) {
            if (responseGrid) responseGrid.hidden = true;
        }

        if (inspectionStarted) {
            const scheduleEditor = modal.querySelector('[data-inspection-schedule-editor]');
            const rescheduleButton = modal.querySelector('[data-reschedule-inspection-open]');
            if (scheduleForm) scheduleForm.hidden = true;
            if (scheduleEditor) scheduleEditor.hidden = true;
            if (rescheduleButton) rescheduleButton.hidden = true;
            sessionStorage.removeItem('edgeOpenRescheduleModal');
        }

        const monitorButton = modal.querySelector('[data-inspection-monitor-open]');
        const canMonitor = inspectionStarted;
        if (monitorButton && canMonitor && Number.parseInt(monitorButton.dataset.inspectionId || '0', 10) > 0) {
            monitorButton.removeAttribute('hidden');
        } else if (monitorButton) {
            monitorButton.hidden = true;
        }
        if (canMonitor) moveInspectionMonitorToTools(modal);
    };

    document.querySelectorAll('.inquiry-modal').forEach(moveInspectionMonitorToTools);

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-reschedule-inspection-open]');
        if (!button) return;

        const modal = button.closest('.inquiry-modal');
        const scheduleForm = modal?.querySelector('[data-inquiry-inspection-form]');
        const scheduleEditor = scheduleForm?.closest('[data-inspection-schedule-editor]');
        if (!scheduleForm || scheduleForm.hidden) return;

        if (scheduleEditor) {
            scheduleEditor.hidden = false;
            sessionStorage.setItem('edgeOpenRescheduleModal', modal.id);
        }
        scheduleForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(function () {
            scheduleForm.querySelector('input[name="inspection_date"]')?.focus({ preventScroll: true });
        }, 350);
    });

    document.addEventListener('click', function (event) {
        const closeButton = event.target.closest('[data-reschedule-inspection-close]');
        const rescheduleModal = event.target.closest('[data-inspection-schedule-editor]');
        if (closeButton && rescheduleModal) {
            rescheduleModal.hidden = true;
            sessionStorage.removeItem('edgeOpenRescheduleModal');
            return;
        }

        if (rescheduleModal && event.target === rescheduleModal) {
            rescheduleModal.hidden = true;
            sessionStorage.removeItem('edgeOpenRescheduleModal');
        }
    });

    // Ibalik ang reschedule popup kapag nag-refresh si Admin habang bukas ito.
    window.setTimeout(function () {
        const modalId = sessionStorage.getItem('edgeOpenRescheduleModal');
        const inquiryModal = modalId ? document.getElementById(modalId) : null;
        const rescheduleModal = inquiryModal?.querySelector('[data-inspection-schedule-editor]');
        if (inquiryModal && rescheduleModal && !inquiryModal.hidden) {
            rescheduleModal.hidden = false;
        }
    }, 100);

    const pollInspectionScheduleResponse = function () {
        if (inspectionResponsePollInProgress || document.hidden) return;
        const modal = document.querySelector('.inquiry-modal:not([hidden])[data-inquiry-id]');
        const inspectionPanel = modal?.querySelector('[data-inquiry-panel="inspection"].is-active:not([hidden])');
        const inquiryId = Number.parseInt(modal?.dataset.inquiryId || '0', 10);
        if (!modal || !inspectionPanel || inquiryId <= 0) return;

        const pollUrl = new URL('/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php', window.location.origin);
        pollUrl.searchParams.set('action', 'poll_inspection_schedule_response');
        pollUrl.searchParams.set('inquiry_id', String(inquiryId));
        inspectionResponsePollInProgress = true;

        fetch(pollUrl.toString(), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Unable to check inspection responses.');
                return response.json();
            })
            .then(function (data) {
                if (!data.success || !data.inspection) return;
                const isInitialLoad = modal.dataset.inspectionResponsePollReady !== '1';
                const isFinal = data.inspection.final === true;
                const previousResponses = {};
                ['client', 'engineer'].forEach(function (party) {
                    previousResponses[party] = modal.querySelector('[data-schedule-response-party="' + party + '"]')?.dataset.scheduleResponse || '';
                });
                applyFinalScheduleLock(
                    modal,
                    isFinal,
                    String(data.inspection.phase || 'Assigned'),
                    data.inspection.locked === true
                );
                ['client', 'engineer'].forEach(function (party) {
                    const response = data.inspection[party] || {};
                    const badge = modal.querySelector('[data-schedule-response-party="' + party + '"]');
                    const previousResponse = previousResponses[party];
                    if (badge) {
                        badge.dataset.scheduleResponse = String(response.response || 'not_scheduled');
                        badge.textContent = response.label || 'Not Scheduled';
                    }
                    if (!isFinal) {
                        updateInspectionResponseNote(modal, party, data.inspection.scheduled ? String(response.note || '') : '');
                    }
                    if (!isInitialLoad && previousResponse !== '' && previousResponse !== String(response.response || 'not_scheduled')) {
                        showLiveInquiryToast(
                            (party === 'client' ? 'Client' : 'Engineer') + ' schedule response: ' + (response.label || 'Not Scheduled') + '.',
                            inquiryId,
                            'inspection',
                            response.response === 'reschedule_requested' ? 'warning' : 'success'
                        );
                    }
                });
                modal.dataset.inspectionResponsePollReady = '1';
            })
            .catch(function () {
                // Tahimik lang. Susubok ulit sa next poll.
            })
            .finally(function () {
                inspectionResponsePollInProgress = false;
            });
    };

    const pollQuotationStatuses = function () {
        if (quotationPollInProgress || document.hidden || quotationStatusModals.length === 0) {
            return;
        }

        const inquiryIds = quotationStatusModals
            .map(function (modal) { return modal.dataset.inquiryId || ''; })
            .filter(Boolean);
        const pollUrl = new URL('/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php', window.location.origin);
        pollUrl.searchParams.set('action', 'poll_quotation_status');
        pollUrl.searchParams.set('inquiry_ids', inquiryIds.join(','));
        quotationPollInProgress = true;

        fetch(pollUrl.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Unable to check quotation status.');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data.success || !Array.isArray(data.quotations)) {
                    return;
                }

                const currentRevisionId = Number.parseInt(data.latest_revision_id || '0', 10);
                const currentRevisionUpdatedAt = String(data.latest_revision_updated_at || '');
                const hasNewRevision = currentRevisionUpdatedAt > latestRevisionUpdatedAt
                    || (currentRevisionUpdatedAt === latestRevisionUpdatedAt && currentRevisionId > latestRevisionId);

                if (currentRevisionId > 0 && hasNewRevision) {
                    showLiveInquiryToast(
                        'Notification: A client has requested a revision on their quotation!',
                        Number.parseInt(data.latest_revision_inquiry_id || '0', 10),
                        'quotation'
                    );
                    latestRevisionId = currentRevisionId;
                    latestRevisionUpdatedAt = currentRevisionUpdatedAt;
                    inquiryShell.dataset.latestRevisionId = String(currentRevisionId);
                    inquiryShell.dataset.latestRevisionUpdatedAt = currentRevisionUpdatedAt;
                }

                const currentRejectedId = Number.parseInt(data.latest_rejected_id || '0', 10);
                const currentRejectedAt = String(data.latest_rejected_at || '');
                const hasNewRejection = currentRejectedAt > latestRejectedAt
                    || (currentRejectedAt === latestRejectedAt && currentRejectedId > latestRejectedId);

                if (currentRejectedId > 0 && hasNewRejection) {
                    const rejectionNote = String(data.latest_rejected_note || 'No note provided.');
                    showLiveInquiryToast(
                        'Notification: Client rejected the quotation. Note: ' + rejectionNote,
                        Number.parseInt(data.latest_rejected_inquiry_id || '0', 10),
                        'quotation',
                        'warning',
                        'Rejected'
                    );
                    latestRejectedId = currentRejectedId;
                    latestRejectedAt = currentRejectedAt;
                    inquiryShell.dataset.latestRejectedId = String(currentRejectedId);
                    inquiryShell.dataset.latestRejectedAt = currentRejectedAt;
                }

                data.quotations.forEach(function (quotation) {
                    const modal = document.querySelector('.inquiry-modal[data-inquiry-id="' + String(quotation.inquiry_id) + '"]');
                    if (!modal) {
                        return;
                    }

                    const previousStatus = modal.dataset.quotationStatus || '';
                    const currentStatus = String(quotation.status || '');
                    modal.dataset.quotationStatus = currentStatus;

                    if (!['revision_requested', 'for_revision'].includes(previousStatus)
                        && ['revision_requested', 'for_revision'].includes(currentStatus)) {
                        const statusLabel = modal.querySelector('[data-quotation-status-label]');
                        if (statusLabel) {
                            statusLabel.textContent = quotation.label || 'For Revision';
                            statusLabel.classList.remove('status-draft', 'status-sent', 'status-accepted');
                            statusLabel.classList.add('status-revision');
                        }

                        const revisionNote = modal.querySelector('[data-quotation-revision-note]');
                        if (revisionNote) {
                            revisionNote.textContent = quotation.client_decision_note || 'The client requested changes to this quotation.';
                        }

                        modal.querySelector('[data-quotation-revision-alert]')?.removeAttribute('hidden');
                        modal.querySelector('[data-quotation-revision-action]')?.removeAttribute('hidden');
                    }

                    if (previousStatus !== 'rejected' && currentStatus === 'rejected') {
                        const statusLabel = modal.querySelector('[data-quotation-status-label]');
                        if (statusLabel) {
                            statusLabel.textContent = quotation.label || 'Rejected';
                            statusLabel.classList.remove('status-draft', 'status-sent', 'status-revision', 'status-accepted');
                            statusLabel.classList.add('status-rejected');
                        }

                        const rejectionNote = modal.querySelector('[data-quotation-rejection-note]');
                        if (rejectionNote) {
                            rejectionNote.textContent = quotation.client_decision_note || 'No note provided.';
                        }
                        modal.querySelector('[data-quotation-rejection-alert]')?.removeAttribute('hidden');
                    }

                    if (previousStatus !== 'sent' || currentStatus !== 'accepted') {
                        return;
                    }

                    const inspectionTab = modal.querySelector('[data-inquiry-stage="inspection"]');
                    if (inspectionTab) {
                        inspectionTab.disabled = false;
                        inspectionTab.setAttribute('aria-disabled', 'false');
                        inspectionTab.setAttribute('aria-current', 'step');
                        inspectionTab.classList.remove('chip-disabled');
                        inspectionTab.classList.remove('is-locked');
                        inspectionTab.classList.add('is-current');
                    }

                    const quotationTab = modal.querySelector('[data-inquiry-tab="quotation"]');
                    if (quotationTab) {
                        quotationTab.setAttribute('aria-current', 'false');
                        quotationTab.classList.remove('is-current');
                        quotationTab.classList.add('is-completed');
                    }

                    modal.querySelector('[data-inquiry-inspection-form]')?.removeAttribute('hidden');
                    const quotationPrerequisite = modal.querySelector('[data-prerequisite-check="client-quotation-approval"]');
                    if (quotationPrerequisite) {
                        quotationPrerequisite.hidden = true;
                        quotationPrerequisite.removeAttribute('data-prerequisite-check');
                    }
                    const statusLabel = modal.querySelector('[data-quotation-status-label]');
                    if (statusLabel) {
                        statusLabel.textContent = 'Client Approved';
                        statusLabel.classList.remove('status-draft', 'status-sent', 'status-revision');
                        statusLabel.classList.add('status-accepted');
                    }

                    const nextAction = modal.closest('.inquiry-card')?.querySelector('.inquiry-open-modal');
                    if (nextAction) {
                        nextAction.textContent = 'Schedule Inspection';
                        nextAction.setAttribute('data-inquiry-open-tab', 'inspection');
                    }

                    if (!modal.hidden) {
                        const activeTab = activateModalTab(modal, 'inspection') ? 'inspection' : 'quotation';
                        pushModalHistory(modal, activeTab);
                    }

                    showLiveInquiryToast(
                        'Client approved the quotation. Set the inspection schedule next.',
                        quotation.inquiry_id,
                        'inspection'
                    );
                });
            })
            .catch(function () {
                // Susubok ulit sa next poll kapag may temporary connection problem.
            })
            .finally(function () {
                quotationPollInProgress = false;
            });
    };

    const pollInquiryUpdates = function () {
        pollQuotationStatuses();
        pollInspectionScheduleResponse();
    };

    pollInquiryUpdates();
    window.setInterval(pollInquiryUpdates, 5000);

    document.querySelectorAll('[data-inquiry-history-back]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!document.referrer) {
                return;
            }

            const previousUrl = new URL(document.referrer);
            if (previousUrl.origin === window.location.origin && previousUrl.pathname.endsWith('/ADMIN/sidebar/inquiries/php/inquiries.php')) {
                event.preventDefault();
                window.history.back();
            }
        });
    });

    document.querySelectorAll('.inquiry-quote-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            showConfirm(form, 'Generate quotation draft from engineer costing?');
        });
    });

    document.querySelectorAll('.submitted-inspection-review-form').forEach(function (form) {
        const remarks = form.querySelector('textarea[name="admin_remarks"]');
        const decisionField = form.querySelector('[data-review-decision]');

        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }

            const decision = event.submitter?.value || '';
            const compactReason = remarks?.value.replace(/\s+/g, '') || '';
            const hasLetter = /[A-Za-zÀ-ÖØ-öø-ÿ]/.test(remarks?.value || '');
            if (decision === 'return' && (!hasLetter || compactReason.length < 5)) {
                event.preventDefault();
                remarks.setCustomValidity('Enter a clear return reason with at least 5 letters.');
                remarks.reportValidity();
                remarks.focus();
                return;
            }

            event.preventDefault();
            remarks?.setCustomValidity('');
            if (decisionField) decisionField.value = decision;
            const isApprove = decision === 'approve';
            showConfirm(
                form,
                isApprove
                    ? 'This approves the Engineer costing for the next quotation step.'
                    : 'The Engineer can fix the findings and costing, then submit it again.',
                null,
                isApprove
                    ? { title: 'Approve Inspection Costing?', cancel: 'Cancel', confirm: 'Approve Costing', mode: 'inspection-approve' }
                    : { title: 'Return Costing to Engineer?', cancel: 'Keep Reviewing', confirm: 'Return Costing', mode: 'inspection-return' }
            );
        });

        remarks?.addEventListener('input', function () {
            remarks.setCustomValidity('');
        });
    });

    document.querySelectorAll('.inquiry-quote-send-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const isInitialQuotation = form.dataset.quoteKind === 'initial';
            const itemCount = Number.parseInt(form.dataset.quotationItemCount || '0', 10);
            if (isInitialQuotation && itemCount === 1 && form.dataset.completenessConfirmed !== '1') {
                showConfirm(
                    form,
                    'This quotation contains only 1 cost item. Please confirm that the quotation is complete before sending it to the client.',
                    null,
                    {
                        title: 'Confirm quotation completeness',
                        cancel: 'Review Quotation',
                        confirm: 'Continue to Send',
                        mode: 'quotation-completeness',
                    }
                );
                return;
            }

            if (form.dataset.confirmed !== '1') {
                const recipientDetails = [
                    { label: 'Client', value: form.dataset.quoteRecipientName || '' },
                    { label: isInitialQuotation ? 'Verified Email' : 'Email', value: form.dataset.quoteRecipientEmail || '' },
                    { label: 'Contact', value: form.dataset.quoteRecipientContact || '' },
                ];

                // Para sa revised quotation lang ang source details.
                if (!isInitialQuotation) {
                    recipientDetails.push({ label: 'Source', value: form.dataset.quoteRecipientSource || '' });
                }

                showConfirm(form, isInitialQuotation
                    ? 'Please confirm the client details before sending this initial quotation.'
                    : 'Send this revised quotation to the client by email?', recipientDetails, isInitialQuotation ? {
                    title: 'Confirm Recipient',
                    cancel: 'Cancel',
                    confirm: 'Send Quotation',
                } : {});
                return;
            }

            delete form.dataset.confirmed;
            if (form.dataset.submitting === '1') {
                return;
            }

            form.dataset.submitting = '1';
            const submitButton = form.querySelector('button[type="submit"]');
            const defaultText = submitButton?.textContent || 'Send Initial Quotation to Client';
            const sendingModal = form.closest('.inquiry-modal');
            if (submitButton) {
                submitButton.disabled = true;
                const spinner = document.createElement('span');
                const label = document.createElement('span');
                spinner.className = 'inquiry-send-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                label.textContent = 'Sending quotation...';
                submitButton.classList.add('inquiry-send-button--loading');
                submitButton.replaceChildren(spinner, label);
            }
            if (sendingModal) {
                setQuotationSendingState(sendingModal, true);
            }

            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',   
                },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { response: response, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.response.ok || !result.data.success) {
                        const requestError = new Error(result.data.message || 'Unable to send quotation.');
                        requestError.code = result.data.error_code || '';
                        throw requestError;
                    }
                    sendingModal?.classList.add('is-send-complete');
                    window.setTimeout(function () {
                        window.location.assign(result.data.redirect || window.location.href);
                    }, 180);
                })
                .catch(function (error) {
                    form.dataset.submitting = '0';
                    delete form.dataset.completenessConfirmed;
                    if (sendingModal) {
                        setQuotationSendingState(sendingModal, false);
                        sendingModal.classList.remove('is-send-complete');
                    }
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.classList.remove('inquiry-send-button--loading');
                        submitButton.textContent = error.code === 'quotation_email_send_failed'
                            ? (isInitialQuotation ? 'Retry Send Quotation' : 'Retry Send Revised Quotation')
                            : defaultText;
                    }

                    if (typeof window.showToast === 'function') {
                        window.showToast(error.message || 'Unable to send quotation.', 'error');
                    }
                });
        });
    });

    const quotationCreate = document.querySelector('[data-quotation-create]');
    if (quotationCreate) {
        const form = quotationCreate.querySelector('[data-quotation-create-form]');
        const items = quotationCreate.querySelector('[data-quotation-items]');
        const template = quotationCreate.querySelector('[data-quotation-item-template]');
        const addButton = quotationCreate.querySelector('[data-quotation-add-item]');
        const subtotalOutput = quotationCreate.querySelector('[data-quotation-subtotal]');
        const profitOutput = quotationCreate.querySelector('[data-quotation-profit]');
        const totalOutput = quotationCreate.querySelector('[data-quotation-total]');
        const marginInput = form?.querySelector('input[name="profit_margin_percent"]');
        const markupError = form?.querySelector('[data-quotation-markup-error]');
        const updateSubmitButton = form?.querySelector('[data-quotation-update-submit]');
        const isEditMode = form?.dataset.quotationEditMode === 'true';
        const isInitialQuotation = form?.dataset.initialQuotation === 'true';
        const quotationInquiryId = Number.parseInt(form?.dataset.quotationInquiryId || '0', 10);
        const localDraftKey = quotationInquiryId > 0
            ? 'initial_quotation_draft:' + String(quotationInquiryId)
            : '';
        const localRestoreNoticeKey = quotationInquiryId > 0
            ? 'initial_quotation_restore_notice:' + String(quotationInquiryId)
            : '';
        const localDraftLifetime = 24 * 60 * 60 * 1000;
        const unitOptionsByType = {
            material: ['pcs', 'meter', 'roll', 'box', 'pack', 'set', 'kg', 'liter'],
            labor: ['person', 'hour', 'day', 'lot'],
            equipment: ['unit', 'hour', 'day', 'set', 'lot'],
            service: ['service', 'job', 'lot'],
            other: ['unit', 'lot'],
        };
        const wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person'];
        const serializeQuotationForm = function () {
            return form ? new URLSearchParams(new FormData(form)).toString() : '';
        };

        let initialQuotationState = '';
        let quotationSubmitAccepted = false;
        let quotationLeaveAccepted = false;
        let localDraftTimer = null;

        const isQuotationDirty = function () {
            return serializeQuotationForm() !== initialQuotationState;
        };

        const updateEditSubmitState = function () {
            if (!isEditMode || !updateSubmitButton) {
                return;
            }

            updateSubmitButton.disabled = serializeQuotationForm() === initialQuotationState;
        };

        const readLocalQuotationDraft = function () {
            if (!isInitialQuotation || localDraftKey === '') {
                return null;
            }

            try {
                const rawDraft = window.localStorage.getItem(localDraftKey);
                if (!rawDraft) {
                    return null;
                }

                const draft = JSON.parse(rawDraft);
                const savedAt = Number(draft?.saved_at || 0);
                const isExpired = !Number.isFinite(savedAt) || Date.now() - savedAt > localDraftLifetime;
                if (draft?.version !== 1
                    || Number(draft?.inquiry_id || 0) !== quotationInquiryId
                    || !Array.isArray(draft?.rows)
                    || isExpired) {
                    window.localStorage.removeItem(localDraftKey);
                    if (localRestoreNoticeKey !== '') {
                        window.sessionStorage.removeItem(localRestoreNoticeKey);
                    }
                    return null;
                }

                return draft;
            } catch (error) {
                return null;
            }
        };

        const clearLocalQuotationDraft = function () {
            if (localDraftTimer) {
                window.clearTimeout(localDraftTimer);
                localDraftTimer = null;
            }
            if (!isInitialQuotation || localDraftKey === '') {
                return;
            }

            try {
                window.localStorage.removeItem(localDraftKey);
                if (localRestoreNoticeKey !== '') {
                    window.sessionStorage.removeItem(localRestoreNoticeKey);
                }
            } catch (error) {
                // Walang gagawin kapag hindi available ang browser storage.
            }
        };

        const buildLocalQuotationDraft = function () {
            return {
                version: 1,
                inquiry_id: quotationInquiryId,
                saved_at: Date.now(),
                markup_percent: marginInput?.value || '',
                rows: Array.from(items?.querySelectorAll('[data-quotation-item]') || []).map(function (row) {
                    return {
                        type: row.querySelector('select[name="item_type[]"]')?.value || 'material',
                        material_id: row.querySelector('select[name="material_id[]"]')?.value || '',
                        item_name: row.querySelector('input[name="item_name[]"]')?.value || '',
                        quantity: row.querySelector('input[name="quantity[]"]')?.value || '',
                        unit: row.querySelector('select[name="unit[]"]')?.value || '',
                        unit_cost: row.querySelector('input[name="unit_cost[]"]')?.value || '',
                        notes: row.querySelector('input[name="item_notes[]"]')?.value || '',
                    };
                }),
            };
        };

        const saveLocalQuotationDraft = function (force = false) {
            if (!isInitialQuotation || localDraftKey === '' || (!force && !isQuotationDirty())) {
                return;
            }

            try {
                window.localStorage.setItem(localDraftKey, JSON.stringify(buildLocalQuotationDraft()));
            } catch (error) {
                // Walang gagawin kapag puno o hindi available ang browser storage.
            }
        };

        const scheduleLocalQuotationDraftSave = function () {
            if (!isInitialQuotation) {
                return;
            }

            if (localDraftTimer) {
                window.clearTimeout(localDraftTimer);
            }
            localDraftTimer = window.setTimeout(function () {
                saveLocalQuotationDraft();
                localDraftTimer = null;
            }, 350);
        };

        const updateQuotationPreview = function () {
            let subtotal = 0;
            items?.querySelectorAll('[data-quotation-item]').forEach(function (item) {
                const quantity = Number.parseFloat(item.querySelector('input[name="quantity[]"]')?.value || '0');
                const unitCostInput = item.querySelector('input[name="unit_cost[]"]');
                const unitCost = Number.parseFloat(unitCostInput?.value || '0');
                const lineTotal = isValidEstimatedUnitCost(unitCostInput?.value || '')
                    ? Math.max(0, quantity) * unitCost
                    : 0;
                const lineTotalOutput = item.querySelector('[data-quotation-line-total]');

                subtotal += lineTotal;
                if (lineTotalOutput) lineTotalOutput.textContent = lineTotal.toFixed(2);
            });

            const margin = Number.parseFloat(marginInput?.value || '0');
            const profit = subtotal * (Math.max(0, margin) / 100);

            if (subtotalOutput) subtotalOutput.textContent = subtotal.toFixed(2);
            if (profitOutput) profitOutput.textContent = profit.toFixed(2);
            if (totalOutput) totalOutput.textContent = (subtotal + profit).toFixed(2);

            const rows = items?.querySelectorAll('[data-quotation-item]') || [];
            rows.forEach(function (row) {
                const removeButton = row.querySelector('[data-quotation-remove-item]');
                if (removeButton) removeButton.disabled = rows.length === 1;
            });
        };

        const setQuantityMessage = function (row, message) {
            const quantityInput = row.querySelector('input[name="quantity[]"]');
            const quantityError = row.querySelector('[data-quotation-quantity-error]');
            if (!quantityInput) {
                return;
            }

            quantityInput.setCustomValidity(message);
            quantityInput.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (quantityError) {
                quantityError.textContent = message;
            }
        };

        const setUnitMessage = function (row, message) {
            const unitSelect = row.querySelector('select[name="unit[]"]');
            const unitError = row.querySelector('[data-quotation-unit-error]');
            if (!unitSelect) {
                return;
            }

            unitSelect.setCustomValidity(message);
            unitSelect.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (unitError) {
                unitError.textContent = message;
            }
        };

        const isValidEstimatedUnitCost = function (rawValue) {
            const value = Number.parseFloat(rawValue || '0');
            return /^\d+(?:\.\d{1,2})?$/.test((rawValue || '').trim())
                && Number.isFinite(value)
                && value > 0;
        };

        const validateMarkup = function (showMessage, requireValue) {
            if (!marginInput) {
                return true;
            }

            const rawValue = marginInput.value.trim();
            const value = Number(rawValue);
            let message = '';

            if (rawValue === '') {
                message = requireValue ? 'Markup is required.' : '';
            } else if (!Number.isFinite(value)) {
                message = 'Markup must be a number from 0 to 100%.';
            } else if (value < 0) {
                message = 'Markup cannot be negative.';
            } else if (value > 100) {
                message = 'Markup cannot be greater than 100%.';
            }

            marginInput.setCustomValidity(message);
            marginInput.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (markupError) {
                markupError.textContent = showMessage ? message : '';
            }
            return message === '';
        };

        const validateEstimatedUnitCost = function (row, showMessage, requireValue) {
            const unitCostInput = row.querySelector('input[name="unit_cost[]"]');
            const costError = row.querySelector('[data-quotation-cost-error]');
            if (!unitCostInput) {
                return true;
            }

            const rawValue = unitCostInput.value.trim();
            let message = '';

            if (rawValue === '') {
                message = requireValue ? 'Enter cost.' : '';
            } else if (!isValidEstimatedUnitCost(rawValue)) {
                message = 'Use amount above 0.';
            }

            unitCostInput.setCustomValidity(message);
            unitCostInput.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (costError) {
                costError.textContent = showMessage ? message : '';
            }
            return message === '';
        };

        const validateQuotationQuantity = function (row, requireValue, showMessage) {
            const quantityInput = row.querySelector('input[name="quantity[]"]');
            const unitSelect = row.querySelector('select[name="unit[]"]');
            if (!quantityInput || !unitSelect) {
                return true;
            }

            const rawValue = quantityInput.value.trim();
            const quantity = Number.parseFloat(rawValue || '0');
            const unit = unitSelect.value.toLowerCase();
            let quantityMessage = '';
            let unitMessage = '';

            if (unit === '') {
                unitMessage = requireValue ? 'Pick unit.' : '';
            } else if (rawValue === '') {
                quantityMessage = requireValue ? 'Enter quantity.' : '';
            } else if (!Number.isFinite(quantity) || quantity <= 0) {
                quantityMessage = 'Use quantity above 0.';
            } else if (wholeCountUnits.includes(unit) && !Number.isInteger(quantity)) {
                quantityMessage = 'Whole ' + unit + ' only.';
            }

            setQuantityMessage(row, showMessage ? quantityMessage : '');
            setUnitMessage(row, showMessage ? unitMessage : '');
            return quantityMessage === '' && unitMessage === '';
        };

        const setUnitOptions = function (row, options, preferredUnit, isLocked, allowEmpty = false) {
            const unitSelect = row.querySelector('select[name="unit[]"]');
            if (!unitSelect) {
                return;
            }

            const normalizedPreferredUnit = (preferredUnit || '').toLowerCase();
            const selectedUnit = options.find(function (option) {
                return option.toLowerCase() === normalizedPreferredUnit;
            }) || (allowEmpty ? '' : options[0] || '');

            unitSelect.replaceChildren();
            if (allowEmpty) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select unit';
                placeholder.selected = selectedUnit === '';
                placeholder.disabled = true;
                unitSelect.appendChild(placeholder);
            }
            options.forEach(function (option) {
                const optionElement = document.createElement('option');
                optionElement.value = option;
                optionElement.textContent = option;
                optionElement.selected = option.toLowerCase() === selectedUnit.toLowerCase();
                unitSelect.appendChild(optionElement);
            });

            unitSelect.classList.toggle('is-locked', isLocked);
            unitSelect.setAttribute('aria-readonly', isLocked ? 'true' : 'false');
            unitSelect.tabIndex = isLocked ? -1 : 0;

            const quantityInput = row.querySelector('input[name="quantity[]"]');
            const selectedUnitIsWhole = wholeCountUnits.includes(selectedUnit.toLowerCase());
            if (quantityInput) {
                quantityInput.min = selectedUnitIsWhole ? '1' : '0.01';
                quantityInput.step = selectedUnitIsWhole ? '1' : '0.01';
                quantityInput.inputMode = selectedUnitIsWhole ? 'numeric' : 'decimal';
            }
        };

        const updateMaterialStockStatus = function (row) {
            const materialSelect = row.querySelector('select[name="material_id[]"]');
            const stockStatus = row.querySelector('[data-quotation-material-feedback]');
            const typeSelect = row.querySelector('select[name="item_type[]"]');
            const selectedOption = materialSelect?.selectedOptions[0];
            const materialId = materialSelect?.value || '';
            if (!stockStatus) {
                return;
            }

            if (typeSelect?.value !== 'material' || !/^[1-9]\d*$/.test(materialId)) {
                stockStatus.hidden = true;
                stockStatus.textContent = '';
                stockStatus.className = 'quotation-create-item__material-feedback';
                return;
            }

            const available = Number.parseFloat(selectedOption?.dataset.materialAvailable || '0');
            const reorderLevel = Number.parseFloat(selectedOption?.dataset.materialReorder || '0');
            const unit = selectedOption?.dataset.materialUnit || 'unit';
            const displayQuantity = Number.isFinite(available) ? available.toLocaleString(undefined, { maximumFractionDigits: 2 }) : '0';
            let state = 'is-available';
            let label = 'Available: ' + displayQuantity + ' ' + unit;
            if (!Number.isFinite(available) || available <= 0) {
                state = 'is-out';
                label = 'Out of stock';
            } else if (Number.isFinite(reorderLevel) && available <= reorderLevel) {
                state = 'is-low';
                label = 'Low stock: ' + displayQuantity + ' ' + unit;
            }

            stockStatus.textContent = label;
            stockStatus.className = 'quotation-create-item__material-feedback ' + state;
            stockStatus.hidden = false;
        };

        const setMaterialReferenceMessage = function (row, message) {
            const materialSelect = row.querySelector('select[name="material_id[]"]');
            const materialError = row.querySelector('[data-quotation-material-error]');
            const stockStatus = row.querySelector('[data-quotation-material-feedback]');
            if (!materialSelect) {
                return;
            }

            materialSelect.setCustomValidity(message);
            materialSelect.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (materialError) {
                materialError.textContent = message;
            }
            if (message !== '') {
                stockStatus && (stockStatus.hidden = true);
            } else {
                updateMaterialStockStatus(row);
            }
        };

        const validateMaterialReference = function (row, showMessage) {
            const typeSelect = row.querySelector('select[name="item_type[]"]');
            const materialSelect = row.querySelector('select[name="material_id[]"]');
            if (!materialSelect || typeSelect?.value !== 'material') {
                setMaterialReferenceMessage(row, '');
                return true;
            }

            const message = materialSelect.value === ''
                ? 'Pick material.'
                : '';
            setMaterialReferenceMessage(row, showMessage ? message : '');
            return message === '';
        };

        const syncMaterialReferenceOptionAvailability = function () {
            const materialRows = Array.from(items?.querySelectorAll('[data-quotation-item]') || []);
            const selectedIds = new Set();

            materialRows.forEach(function (row) {
                const typeSelect = row.querySelector('select[name="item_type[]"]');
                const materialSelect = row.querySelector('select[name="material_id[]"]');
                const materialId = materialSelect?.value || '';
                if (typeSelect?.value === 'material' && /^[1-9]\d*$/.test(materialId)) {
                    selectedIds.add(materialId);
                }
            });

            materialRows.forEach(function (row) {
                const materialSelect = row.querySelector('select[name="material_id[]"]');
                if (!materialSelect) {
                    return;
                }

                const currentMaterialId = materialSelect.value || '';
                Array.from(materialSelect.options).forEach(function (option) {
                    const optionMaterialId = option.value || '';
                    // Ang sariling napiling material ay dapat manatiling puwedeng piliin.
                    option.disabled = /^[1-9]\d*$/.test(optionMaterialId)
                        && selectedIds.has(optionMaterialId)
                        && optionMaterialId !== currentMaterialId;
                });
            });
        };

        const validateDuplicateMaterialReferences = function (showMessage) {
            const materialRows = Array.from(items?.querySelectorAll('[data-quotation-item]') || []);
            const selectedIds = new Map();
            materialRows.forEach(function (row) {
                const typeSelect = row.querySelector('select[name="item_type[]"]');
                const materialSelect = row.querySelector('select[name="material_id[]"]');
                const materialId = materialSelect?.value || '';
                if (typeSelect?.value === 'material' && /^[1-9]\d*$/.test(materialId)) {
                    selectedIds.set(materialId, (selectedIds.get(materialId) || 0) + 1);
                }
            });

            let isValid = true;
            materialRows.forEach(function (row) {
                const materialSelect = row.querySelector('select[name="material_id[]"]');
                const materialError = row.querySelector('[data-quotation-material-error]');
                const materialId = materialSelect?.value || '';
                const isDuplicate = /^[1-9]\d*$/.test(materialId) && (selectedIds.get(materialId) || 0) > 1;
                if (isDuplicate) {
                    isValid = false;
                    setMaterialReferenceMessage(row, showMessage ? 'Already added.' : '');
                } else if (materialError?.textContent === 'This material is already added. Edit the existing row instead.') {
                    setMaterialReferenceMessage(row, '');
                }
            });
            syncMaterialReferenceOptionAvailability();
            return isValid;
        };

        const clearMaterialDependentValues = function (row, preserveQuantity = false) {
            const itemName = row.querySelector('input[name="item_name[]"]');
            const quantityInput = row.querySelector('input[name="quantity[]"]');
            const quantityError = row.querySelector('[data-quotation-quantity-error]');
            const unitCostInput = row.querySelector('input[name="unit_cost[]"]');
            const costError = row.querySelector('[data-quotation-cost-error]');
            if (itemName) {
                itemName.value = '';
            }
            if (quantityInput && !preserveQuantity) {
                quantityInput.value = '';
                quantityInput.setCustomValidity('');
                quantityInput.removeAttribute('aria-invalid');
            }
            if (quantityError && !preserveQuantity) {
                quantityError.textContent = '';
            }
            if (unitCostInput) {
                unitCostInput.value = '';
                unitCostInput.setCustomValidity('');
                unitCostInput.removeAttribute('aria-invalid');
            }
            if (costError) {
                costError.textContent = '';
            }
        };

        const syncMaterialReference = function (row, resetForMaterialChange = false) {
            const typeSelect = row.querySelector('select[name="item_type[]"]');
            const materialReference = row.querySelector('[data-quotation-material-reference]');
            const materialSelect = row.querySelector('select[name="material_id[]"]');
            const isMaterial = typeSelect?.value === 'material';
            if (!isMaterial && materialSelect) {
                materialSelect.value = '';
            }
            const materialValue = materialSelect?.value || '';
            const selectedOption = materialSelect?.options[materialSelect.selectedIndex];
            const isLinkedMaterial = isMaterial && /^[1-9]\d*$/.test(materialValue);
            const isManualMaterial = isMaterial && materialValue === 'manual';
            const currentUnit = row.querySelector('select[name="unit[]"]')?.value || '';
            const itemName = row.querySelector('input[name="item_name[]"]');
            const quantityInput = row.querySelector('input[name="quantity[]"]');

            row.classList.toggle('is-material-item', isMaterial);
            row.classList.toggle('has-linked-material', isLinkedMaterial);

            if (materialReference) {
                materialReference.hidden = !isMaterial;
            }

            if (materialSelect) {
                materialSelect.required = isMaterial;
            }

            if (resetForMaterialChange && isMaterial) {
                const quantityRaw = quantityInput?.value.trim() || '';
                const materialUnit = selectedOption?.dataset.materialUnit || '';
                const quantityValue = Number.parseFloat(quantityRaw);
                const keepQuantity = isLinkedMaterial
                    && quantityRaw !== ''
                    && Number.isFinite(quantityValue)
                    && quantityValue > 0
                    && (!wholeCountUnits.includes(materialUnit.toLowerCase()) || Number.isInteger(quantityValue));
                clearMaterialDependentValues(row, keepQuantity);
            }

            if (isLinkedMaterial) {
                setUnitOptions(row, [selectedOption?.dataset.materialUnit || 'unit'], selectedOption?.dataset.materialUnit || 'unit', true);
            } else if (isMaterial) {
                const preserveManualUnit = !resetForMaterialChange && isManualMaterial && currentUnit !== '';
                setUnitOptions(row, unitOptionsByType.material, preserveManualUnit ? currentUnit : '', false, !preserveManualUnit);
            } else {
                setUnitOptions(row, unitOptionsByType[typeSelect?.value] || unitOptionsByType.other, currentUnit, false);
            }

            updateMaterialStockStatus(row);

            if (itemName) {
                itemName.readOnly = isLinkedMaterial;
                itemName.classList.toggle('is-linked-material-name', isLinkedMaterial);
                itemName.setAttribute('aria-readonly', isLinkedMaterial ? 'true' : 'false');

                if (isLinkedMaterial && selectedOption?.dataset.materialName) {
                    itemName.value = selectedOption.dataset.materialName;
                }
            }

            if (resetForMaterialChange && isLinkedMaterial && quantityInput && quantityInput.value.trim() === '') {
                quantityInput.value = '1';
            }

            validateQuotationQuantity(row, false, false);
            validateMaterialReference(row, false);
        };

        const restoreLocalQuotationDraft = function (draft) {
            if (!draft || !items || !template) {
                return false;
            }

            const savedRows = draft.rows.slice(0, 50);
            if (savedRows.length === 0) {
                return false;
            }

            items.replaceChildren();
            savedRows.forEach(function (savedRow) {
                items.appendChild(template.content.cloneNode(true));
                const row = items.lastElementChild;
                const typeSelect = row?.querySelector('select[name="item_type[]"]');
                const materialSelect = row?.querySelector('select[name="material_id[]"]');
                const itemName = row?.querySelector('input[name="item_name[]"]');
                const quantityInput = row?.querySelector('input[name="quantity[]"]');
                const unitSelect = row?.querySelector('select[name="unit[]"]');
                const unitCostInput = row?.querySelector('input[name="unit_cost[]"]');
                const notesInput = row?.querySelector('input[name="item_notes[]"]');
                const savedType = String(savedRow?.type || 'material').toLowerCase();
                const savedMaterialId = String(savedRow?.material_id || '');
                const savedUnit = String(savedRow?.unit || '').toLowerCase();

                if (typeSelect && Array.from(typeSelect.options).some(function (option) { return option.value === savedType; })) {
                    typeSelect.value = savedType;
                }
                if (materialSelect && Array.from(materialSelect.options).some(function (option) { return option.value === savedMaterialId; })) {
                    materialSelect.value = savedMaterialId;
                }
                if (savedType !== 'material') {
                    setUnitOptions(row, unitOptionsByType[savedType] || unitOptionsByType.other, savedUnit, false);
                } else if (savedMaterialId === 'manual') {
                    setUnitOptions(row, unitOptionsByType.material, savedUnit, false, savedUnit === '');
                }
                if (itemName) itemName.value = String(savedRow?.item_name || '');
                if (quantityInput) quantityInput.value = String(savedRow?.quantity || '');
                if (unitSelect && savedUnit !== '' && Array.from(unitSelect.options).some(function (option) { return option.value === savedUnit; })) {
                    unitSelect.value = savedUnit;
                }
                if (unitCostInput) unitCostInput.value = String(savedRow?.unit_cost || '');
                if (notesInput) notesInput.value = String(savedRow?.notes || '');

                syncMaterialReference(row);
            });

            if (marginInput) {
                marginInput.value = String(draft.markup_percent || '');
            }
            return true;
        };

        addButton?.addEventListener('click', function () {
            if (!items || !template || items.children.length >= 50) {
                return;
            }

            items.appendChild(template.content.cloneNode(true));
            syncMaterialReference(items.lastElementChild);
            syncMaterialReferenceOptionAvailability();
            updateQuotationPreview();
            updateEditSubmitState();
            scheduleLocalQuotationDraftSave();
        });

        const hasMeaningfulQuotationItemData = function (row) {
            const type = row.querySelector('select[name="item_type[]"]')?.value || 'material';
            const materialId = row.querySelector('select[name="material_id[]"]')?.value || '';
            const itemName = row.querySelector('input[name="item_name[]"]')?.value.trim() || '';
            const quantity = row.querySelector('input[name="quantity[]"]')?.value.trim() || '';
            const unit = row.querySelector('select[name="unit[]"]')?.value || '';
            const unitCost = row.querySelector('input[name="unit_cost[]"]')?.value.trim() || '';
            const notes = row.querySelector('input[name="item_notes[]"]')?.value.trim() || '';

            return materialId !== ''
                || itemName !== ''
                || quantity !== ''
                || unitCost !== ''
                || notes !== ''
                || type !== 'material'
                || (unit !== '' && unit !== 'pcs');
        };

        const quotationItemLabel = function (row) {
            const itemName = row.querySelector('input[name="item_name[]"]')?.value.trim() || '';
            if (itemName !== '') {
                return itemName;
            }

            const materialSelect = row.querySelector('select[name="material_id[]"]');
            const materialName = materialSelect?.options[materialSelect.selectedIndex]?.textContent?.trim() || '';
            return materialName !== '' && materialSelect?.value !== '' ? materialName : 'this item';
        };

        const removeQuotationItem = function (row) {
            row?.remove();
            validateDuplicateMaterialReferences(true);
            updateQuotationPreview();
            updateEditSubmitState();
            scheduleLocalQuotationDraftSave();
        };

        items?.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-quotation-remove-item]');
            if (!removeButton || items.querySelectorAll('[data-quotation-item]').length <= 1) {
                return;
            }

            const row = removeButton.closest('[data-quotation-item]');
            if (!row) {
                return;
            }

            if (!hasMeaningfulQuotationItemData(row)) {
                removeQuotationItem(row);
                return;
            }

            pendingQuotationItemRemoval = function () {
                removeQuotationItem(row);
            };
            showConfirm(
                form,
                'Remove "' + quotationItemLabel(row) + '" from this quotation? The totals will be recalculated. This change will only be saved after you click Save Quotation Changes.',
                null,
                {
                    title: 'Remove quotation item?',
                    cancel: 'Cancel',
                    confirm: 'Remove Item',
                    mode: 'quotation-item-remove',
                }
            );
        });

        items?.addEventListener('change', function (event) {
            const row = event.target.closest('[data-quotation-item]');
            if (!row) {
                return;
            }

            if (event.target.matches('select[name="item_type[]"]')) {
                syncMaterialReference(row);
                validateDuplicateMaterialReferences(true);
            }
            if (event.target.matches('select[name="material_id[]"]')) {
                syncMaterialReference(row, true);
                validateMaterialReference(row, true);
                validateDuplicateMaterialReferences(true);
            }
            if (event.target.matches('select[name="unit[]"]')) {
                if (event.target.classList.contains('is-locked')) {
                    syncMaterialReference(row);
                } else {
                    validateQuotationQuantity(row, false, true);
                }
            }
            updateQuotationPreview();
            updateEditSubmitState();
            scheduleLocalQuotationDraftSave();
        });

        form?.addEventListener('submit', function (event) {
            if (quotationSubmitAccepted) {
                event.preventDefault();
                return;
            }

            if (!validateMarkup(true, true)) {
                event.preventDefault();
                marginInput?.focus();
                return;
            }

            const invalidMaterialReferenceRow = Array.from(items?.querySelectorAll('[data-quotation-item]') || []).find(function (row) {
                return !validateMaterialReference(row, true);
            });
            if (invalidMaterialReferenceRow) {
                event.preventDefault();
                invalidMaterialReferenceRow.querySelector('select[name="material_id[]"]')?.focus();
                return;
            }

            if (!validateDuplicateMaterialReferences(true)) {
                event.preventDefault();
                items?.querySelector('[data-quotation-material-error]:not(:empty)')?.closest('[data-quotation-material-reference]')?.querySelector('select[name="material_id[]"]')?.focus();
                return;
            }

            const invalidQuantityRow = Array.from(items?.querySelectorAll('[data-quotation-item]') || []).find(function (row) {
                return !validateQuotationQuantity(row, true, true);
            });
            if (invalidQuantityRow) {
                event.preventDefault();
                invalidQuantityRow.querySelector('input[name="quantity[]"]')?.focus();
                return;
            }

            const invalidCostRow = Array.from(items?.querySelectorAll('[data-quotation-item]') || []).find(function (row) {
                return !validateEstimatedUnitCost(row, true, true);
            });
            if (invalidCostRow) {
                event.preventDefault();
                invalidCostRow.querySelector('input[name="unit_cost[]"]')?.focus();
                return;
            }

            if (event.submitter?.hasAttribute('data-confirm-quotation-save')
                && form.dataset.quotationDraftConfirmed !== '1') {
                event.preventDefault();
                showConfirm(
                    form,
                    'This will save the current costing as an initial quotation draft. You can review and edit it before sending it to the client.',
                    null,
                    {
                        title: 'Create initial quotation draft?',
                        cancel: 'Cancel',
                        confirm: 'Create Draft',
                        mode: 'quotation-draft-create',
                    }
                );
                return;
            }

            if (form.dataset.quotationUpdateConfirmed === '1') {
                delete form.dataset.quotationUpdateConfirmed;
            } else if (event.submitter?.hasAttribute('data-confirm-quotation-update')) {
                event.preventDefault();
                showConfirm(
                    form,
                    'This will update the saved quotation using your current items, quantities, costs, notes, markup, and recalculated totals.',
                    null,
                    {
                        title: 'Save quotation changes?',
                        cancel: 'Cancel',
                        confirm: 'Save Changes',
                        mode: 'quotation-update',
                    }
                );
                return;
            }

            saveLocalQuotationDraft(true);
            quotationSubmitAccepted = true;
        });

        const leaveInitialQuotationWithoutSaving = function (destination) {
            showQuotationDraftDiscardConfirm(function () {
                quotationLeaveAccepted = true;
                clearLocalQuotationDraft();
                window.location.assign(destination);
            });
        };

        document.addEventListener('click', function (event) {
            const link = event.target.closest('a[href]');
            if (!link || link.target === '_blank' || link.hasAttribute('download') || !isQuotationDirty()) {
                return;
            }

            const destination = link.getAttribute('href') || '';
            if (destination === '' || destination.startsWith('#') || destination.startsWith('javascript:')) {
                return;
            }

            if (isInitialQuotation) {
                event.preventDefault();
                event.stopImmediatePropagation();
                leaveInitialQuotationWithoutSaving(destination);
                return;
            }

            if (!window.confirm('You have unsaved changes. Leave without saving?')) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);

        window.addEventListener('beforeunload', function (event) {
            if (window.__edgeLogoutInProgress || quotationSubmitAccepted || quotationLeaveAccepted || !isQuotationDirty()) {
                return;
            }

            if (isInitialQuotation) {
                if (!quotationLeaveAccepted) {
                    saveLocalQuotationDraft();
                }
            }

            event.preventDefault();
            event.returnValue = '';
        });

        window.addEventListener('pagehide', function () {
            if (isInitialQuotation && !quotationSubmitAccepted && !quotationLeaveAccepted) {
                saveLocalQuotationDraft();
            }
        });

        form?.querySelector('[data-quotation-cancel]')?.addEventListener('click', function (event) {
            if (!isInitialQuotation || !isQuotationDirty()) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            leaveInitialQuotationWithoutSaving(this.href);
        });

        form?.addEventListener('input', function (event) {
            const row = event.target.closest('[data-quotation-item]');
            if (event.target === marginInput) {
                validateMarkup(true, false);
            }
            if (row && event.target.matches('input[name="quantity[]"]')) {
                validateQuotationQuantity(row, false, true);
            }
            if (row && event.target.matches('input[name="unit_cost[]"]')) {
                validateEstimatedUnitCost(row, true, false);
            }
            updateQuotationPreview();
            updateEditSubmitState();
            scheduleLocalQuotationDraftSave();
        });
        marginInput?.addEventListener('blur', function () {
            validateMarkup(true, true);
        });
        items?.addEventListener('focusout', function (event) {
            const row = event.target.closest('[data-quotation-item]');
            if (!row) {
                return;
            }

            if (event.target.matches('input[name="quantity[]"]')) {
                validateQuotationQuantity(row, true, true);
            }
            if (event.target.matches('input[name="unit_cost[]"]')) {
                validateEstimatedUnitCost(row, true, true);
            }
        });
        form?.addEventListener('invalid', function (event) {
            if (event.target === marginInput) {
                validateMarkup(true, true);
                return;
            }

            const row = event.target.closest('[data-quotation-item]');
            if (!row) {
                return;
            }

            if (event.target.matches('input[name="quantity[]"]')) {
                validateQuotationQuantity(row, true, true);
            }
            if (event.target.matches('input[name="unit_cost[]"]')) {
                validateEstimatedUnitCost(row, true, true);
            }
            if (event.target.matches('select[name="material_id[]"]')) {
                validateMaterialReference(row, true);
            }
        }, true);
        form?.addEventListener('change', updateEditSubmitState);
        items?.querySelectorAll('[data-quotation-item]').forEach(function (row) {
            syncMaterialReference(row);
        });
        syncMaterialReferenceOptionAvailability();
        updateQuotationPreview();
        initialQuotationState = serializeQuotationForm();
        const savedLocalDraft = readLocalQuotationDraft();
        if (restoreLocalQuotationDraft(savedLocalDraft)) {
            updateQuotationPreview();
            validateDuplicateMaterialReferences(false);
            let hasShownRestoreNotice = false;
            try {
                hasShownRestoreNotice = localRestoreNoticeKey !== ''
                    && window.sessionStorage.getItem(localRestoreNoticeKey) === '1';
            } catch (error) {
                hasShownRestoreNotice = false;
            }
            if (!hasShownRestoreNotice && typeof window.showToast === 'function') {
                window.showToast('Unsaved quotation draft restored.', 'info');
                try {
                    window.sessionStorage.setItem(localRestoreNoticeKey, '1');
                } catch (error) {
                    // Walang gagawin kapag hindi available ang browser storage.
                }
            }
        }
        updateEditSubmitState();
    }

    const queryParams = new URLSearchParams(window.location.search);
    const clearInitialQuotationDraftId = Number.parseInt(queryParams.get('clear_initial_quotation_draft') || '0', 10);
    if (clearInitialQuotationDraftId > 0) {
        try {
            window.localStorage.removeItem('initial_quotation_draft:' + String(clearInitialQuotationDraftId));
            window.sessionStorage.removeItem('initial_quotation_restore_notice:' + String(clearInitialQuotationDraftId));
        } catch (error) {
            // Walang gagawin kapag hindi available ang browser storage.
        }
        queryParams.delete('clear_initial_quotation_draft');
        const cleanQuery = queryParams.toString();
        window.history.replaceState({}, '', window.location.pathname + (cleanQuery ? '?' + cleanQuery : '') + window.location.hash);
    }
    const inquiryIdFromUrl = Number.parseInt(queryParams.get('inquiry_id') || '0', 10);
    const urlOpenModalId = queryParams.get('open') || (inquiryIdFromUrl > 0 ? 'inquiryModal' + inquiryIdFromUrl : '');
    const urlOpenTab = queryParams.get('tab') || 'client';
    if (urlOpenModalId) {
        sessionStorage.removeItem(listStartAtTopKey);
        const modal = document.getElementById(urlOpenModalId);
        if (modal) {
            openModal(modal);
            activateModalTab(modal, urlOpenTab);
        }
    } else {
        const shouldStartAtTop = sessionStorage.getItem(listStartAtTopKey) === '1';
        if (shouldStartAtTop) {
            sessionStorage.removeItem(listStartAtTopKey);
            sessionStorage.removeItem('edgeLastInquiryModal');
            startInquiryListAtTop();
        } else {
            const lastOpenModalId = sessionStorage.getItem('edgeLastInquiryModal');
            const lastModal = lastOpenModalId ? document.getElementById(lastOpenModalId) : null;
            const lastCard = lastModal?.closest('.inquiry-card');
            if (lastCard) {
                lastCard.scrollIntoView({ behavior: 'instant', block: 'center' });
            }
        }
    }

    window.addEventListener('popstate', function (event) {
        const modalId = event.state?.inquiryModalId || new URLSearchParams(window.location.search).get('open');
        const targetTab = event.state?.inquiryTab || new URLSearchParams(window.location.search).get('tab') || 'client';
        const targetModal = modalId ? document.getElementById(modalId) : null;
        const openModalBeforeHistoryChange = document.querySelector('.inquiry-modal:not([hidden])');

        if (openModalBeforeHistoryChange?.dataset.quotationSending === '1') {
            const activeTab = openModalBeforeHistoryChange.querySelector('.inquiry-modal-tab.is-active')?.getAttribute('data-inquiry-tab') || 'client';
            if (targetModal !== openModalBeforeHistoryChange || targetTab !== activeTab) {
                pushModalHistory(openModalBeforeHistoryChange, activeTab);
            }
            return;
        }

        if (openModalBeforeHistoryChange?.dataset.reviewSaving === '1') {
            const activeTab = openModalBeforeHistoryChange.querySelector('.inquiry-modal-tab.is-active')?.getAttribute('data-inquiry-tab') || 'client';
            pushModalHistory(openModalBeforeHistoryChange, activeTab);
            return;
        }

        if (openModalBeforeHistoryChange?.dataset.inspectionScheduling === '1') {
            const activeTab = openModalBeforeHistoryChange.querySelector('.inquiry-modal-tab.is-active')?.getAttribute('data-inquiry-tab') || 'inspection';
            pushModalHistory(openModalBeforeHistoryChange, activeTab);
            return;
        }

        if (!targetModal && openModalBeforeHistoryChange && inquiryReviewHasChanges(openModalBeforeHistoryChange)) {
            showDiscardConfirm(
                openModalBeforeHistoryChange,
                function () {
                    discardInquiryReviewChanges(openModalBeforeHistoryChange);
                    closeModal(openModalBeforeHistoryChange);
                },
                function () {
                    const activeTab = openModalBeforeHistoryChange.querySelector('.inquiry-modal-tab.is-active')?.getAttribute('data-inquiry-tab') || 'client';
                    pushModalHistory(openModalBeforeHistoryChange, activeTab);
                }
            );
            return;
        }

        document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(function (modal) {
            if (modal !== targetModal) {
                closeModal(modal);
            }
        });

        if (targetModal) {
            openModal(targetModal);
            activateModalTab(targetModal, targetTab);
        } else {
            document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(closeModal);
        }
    });

    confirmBox.querySelector('[data-inquiry-confirm-no]')?.addEventListener('click', closeConfirm);
    confirmBox.querySelector('[data-inquiry-confirm-yes]')?.addEventListener('click', function () {
        if (!pendingConfirmForm) {
            closeConfirm();
            return;
        }

        const form = pendingConfirmForm;
        const confirmationMode = form.dataset.confirmationMode || 'submit';

        if (confirmationMode === 'quotation-completeness') {
            closeConfirm();
            form.dataset.completenessConfirmed = '1';
            form.requestSubmit();
            return;
        }

        if (confirmationMode === 'quotation-draft-create') {
            form.dataset.quotationDraftConfirmed = '1';
            lockConfirmForQuotationDraft();
            form.requestSubmit();
            return;
        }

        if (confirmationMode === 'quotation-update') {
            closeConfirm();
            form.dataset.quotationUpdateConfirmed = '1';
            form.requestSubmit();
            return;
        }

        if (confirmationMode === 'quotation-item-remove') {
            const removeItem = pendingQuotationItemRemoval;
            pendingQuotationItemRemoval = null;
            closeConfirm();
            removeItem?.();
            return;
        }

        closeConfirm();
        form.dataset.confirmed = '1';
        form.requestSubmit();
    });

    confirmBox.addEventListener('click', function (event) {
        if (event.target === confirmBox) {
            closeConfirm();
        }
    });

    discardConfirmBox.querySelector('[data-inquiry-discard-keep]')?.addEventListener('click', function () {
        closeDiscardConfirm(true);
    });
    discardConfirmBox.querySelector('[data-inquiry-discard-yes]')?.addEventListener('click', function () {
        const discardAction = pendingDiscardAction;
        closeDiscardConfirm();
        discardAction?.();
    });
    discardConfirmBox.addEventListener('click', function (event) {
        if (event.target === discardConfirmBox) {
            closeDiscardConfirm(true);
        }
    });

    quotationDraftDiscardBox.querySelector('[data-quotation-draft-discard-keep]')?.addEventListener('click', closeQuotationDraftDiscardConfirm);
    quotationDraftDiscardBox.querySelector('[data-quotation-draft-discard-yes]')?.addEventListener('click', function () {
        const discardAction = pendingQuotationDraftDiscard;
        closeQuotationDraftDiscardConfirm();
        discardAction?.();
    });
    quotationDraftDiscardBox.addEventListener('click', function (event) {
        if (event.target === quotationDraftDiscardBox) {
            closeQuotationDraftDiscardConfirm();
        }
    });

    prerequisiteNotice.querySelector('[data-prerequisite-notice-ok]')?.addEventListener('click', closePrerequisiteNotice);
    prerequisiteNotice.addEventListener('click', function (event) {
        if (event.target === prerequisiteNotice) {
            closePrerequisiteNotice();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        if (!confirmBox.hidden) {
            closeConfirm();
            return;
        }

        if (!discardConfirmBox.hidden) {
            closeDiscardConfirm(true);
            return;
        }

        if (!quotationDraftDiscardBox.hidden) {
            closeQuotationDraftDiscardConfirm();
            return;
        }

        if (!prerequisiteNotice.hidden) {
            closePrerequisiteNotice();
            return;
        }

        document.querySelectorAll('.inquiry-archive-modal:not([hidden])').forEach(closeArchiveModal);
        document.querySelectorAll('.inquiry-modal:not([hidden])').forEach(requestCloseModal);
    });

    window.addEventListener('beforeunload', function (event) {
        if (window.__edgeLogoutInProgress || !document.querySelector('.inquiry-modal[data-review-saving="1"], .inquiry-modal[data-inspection-scheduling="1"]')) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });
});
