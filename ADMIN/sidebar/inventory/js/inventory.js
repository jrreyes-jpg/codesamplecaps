(function () {
    'use strict';

    const searchInput = document.querySelector('[data-inventory-report-search]');
    const reportRows = Array.from(document.querySelectorAll('[data-inventory-report-row]'));
    const noResultsRow = document.querySelector('[data-inventory-report-empty]');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = searchInput.value.trim().toLocaleLowerCase();
            let visibleCount = 0;

            reportRows.forEach(function (row) {
                const matches = query === '' || row.textContent.toLocaleLowerCase().includes(query);
                row.hidden = !matches;
                if (matches) {
                    visibleCount += 1;
                }
            });

            if (noResultsRow) {
                noResultsRow.hidden = visibleCount !== 0 || reportRows.length === 0;
            }
        });
    }

    const menuToggles = Array.from(document.querySelectorAll('[data-material-menu-toggle]'));

    function closeRowMenus(exceptToggle) {
        menuToggles.forEach(function (toggle) {
            if (toggle === exceptToggle) return;
            toggle.setAttribute('aria-expanded', 'false');
            const menu = toggle.closest('[data-material-row-menu]');
            const panel = menu ? menu.querySelector('[data-material-menu-panel]') : null;
            if (panel) panel.hidden = true;
        });
    }

    function placeRowMenu(toggle, panel) {
        panel.hidden = false;
        const toggleRect = toggle.getBoundingClientRect();
        const panelRect = panel.getBoundingClientRect();
        const left = Math.max(12, Math.min(toggleRect.right - panelRect.width, window.innerWidth - panelRect.width - 12));
        let top = toggleRect.bottom + 6;
        if (top + panelRect.height > window.innerHeight - 12) {
            top = Math.max(12, toggleRect.top - panelRect.height - 6);
        }
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
    }

    menuToggles.forEach(function (toggle) {
        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            const menu = toggle.closest('[data-material-row-menu]');
            const panel = menu ? menu.querySelector('[data-material-menu-panel]') : null;
            if (!panel) return;
            const willOpen = panel.hidden;
            closeRowMenus(toggle);
            panel.hidden = !willOpen;
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) placeRowMenu(toggle, panel);
        });
    });

    document.addEventListener('click', function () {
        closeRowMenus();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || (removeModal && !removeModal.hidden)) return;
        const openToggle = document.querySelector('[data-material-menu-toggle][aria-expanded="true"]');
        closeRowMenus();
        if (openToggle) openToggle.focus();
    });
    window.addEventListener('resize', function () { closeRowMenus(); });
    window.addEventListener('scroll', function () { closeRowMenus(); }, true);

    const removeModal = document.querySelector('[data-material-remove-modal]');
    const removeMessage = removeModal ? removeModal.querySelector('[data-material-remove-message]') : null;
    const removeClose = removeModal ? removeModal.querySelector('[data-material-remove-close]') : null;
    const removeConfirm = removeModal ? removeModal.querySelector('[data-material-remove-confirm]') : null;
    let pendingRemoveForm = null;
    let removeReturnFocus = null;
    let removeSubmitting = false;

    function closeRemoveModal() {
        if (!removeModal || removeSubmitting) return;
        removeModal.hidden = true;
        document.body.classList.remove('material-create-modal-open');
        pendingRemoveForm = null;
        if (removeReturnFocus) removeReturnFocus.focus();
    }

    document.querySelectorAll('[data-material-remove-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!removeModal || !removeMessage) return;
            pendingRemoveForm = button.closest('form');
            removeReturnFocus = button.closest('[data-material-row-menu]')?.querySelector('[data-material-menu-toggle]') || button;
            removeMessage.textContent = 'Remove ' + (button.dataset.materialName || 'this material') + '? This cannot be undone.';
            closeRowMenus();
            removeModal.hidden = false;
            document.body.classList.add('material-create-modal-open');
            window.requestAnimationFrame(function () { removeClose.focus(); });
        });
    });

    if (removeClose) removeClose.addEventListener('click', closeRemoveModal);
    if (removeModal) {
        removeModal.addEventListener('click', function (event) {
            if (event.target === removeModal) closeRemoveModal();
        });
        removeModal.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeRemoveModal();
            }
            if (event.key === 'Tab' && removeClose && removeConfirm) {
                if (event.shiftKey && document.activeElement === removeClose) {
                    event.preventDefault();
                    removeConfirm.focus();
                } else if (!event.shiftKey && document.activeElement === removeConfirm) {
                    event.preventDefault();
                    removeClose.focus();
                }
            }
        });
    }
    if (removeConfirm) {
        removeConfirm.addEventListener('click', function () {
            if (!pendingRemoveForm || removeSubmitting) return;
            removeSubmitting = true;
            removeConfirm.disabled = true;
            removeClose.disabled = true;
            removeConfirm.textContent = 'Removing...';
            pendingRemoveForm.requestSubmit();
        });
    }

    const modal = document.querySelector('[data-material-modal]');
    const openButton = document.querySelector('[data-material-modal-open]');
    if (!modal || !openButton) {
        return;
    }

    const panel = modal.querySelector('[role="dialog"]');
    const closeButtons = Array.from(modal.querySelectorAll('[data-material-modal-close]'));
    const form = modal.querySelector('[data-material-create-form]');
    const submitButton = modal.querySelector('[data-material-submit]');
    let returnFocus = openButton;
    let isSubmitting = false;

    const focusableSelector = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])';

    function openModal() {
        returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : openButton;
        modal.hidden = false;
        document.body.classList.add('material-create-modal-open');
        const firstField = modal.querySelector('input:not([type="hidden"]), select, textarea');
        window.requestAnimationFrame(function () {
            (firstField || panel).focus();
        });
    }

    function closeModal() {
        if (isSubmitting) {
            return;
        }
        modal.hidden = true;
        document.body.classList.remove('material-create-modal-open');
        if (returnFocus && typeof returnFocus.focus === 'function') {
            returnFocus.focus();
        }
    }

    openButton.addEventListener('click', openModal);
    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }

        const focusable = Array.from(modal.querySelectorAll(focusableSelector));
        if (focusable.length === 0) {
            event.preventDefault();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            if (isSubmitting) {
                event.preventDefault();
                return;
            }
            isSubmitting = true;
            closeButtons.forEach(function (button) {
                button.disabled = true;
            });
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Adding...';
            }
        });
    }

    if (modal.dataset.autoOpen === 'true') {
        openModal();
    }
})();
