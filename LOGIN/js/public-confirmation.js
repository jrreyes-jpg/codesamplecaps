/* Shared public confirmation modal para sa inquiry pages. */
(function () {
    'use strict';

    let modal = null;
    let title = null;
    let message = null;
    let cancelButton = null;
    let confirmButton = null;
    let current = null;
    let lastFocusedElement = null;

    const close = function () {
        if (!modal || !current || current.busy) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('public-confirmation-open');
        current = null;
        if (lastFocusedElement instanceof HTMLElement) {
            lastFocusedElement.focus();
        }
        lastFocusedElement = null;
    };

    const ensureModal = function () {
        if (modal) {
            return;
        }

        modal = document.createElement('div');
        modal.className = 'public-confirmation-modal';
        modal.hidden = true;
        modal.innerHTML = [
            '<div class="public-confirmation-modal__panel" role="dialog" aria-modal="true" aria-labelledby="publicConfirmationTitle">',
            '<h2 id="publicConfirmationTitle"></h2>',
            '<p data-public-confirmation-message></p>',
            '<div class="public-confirmation-modal__actions">',
            '<button type="button" class="public-confirmation-modal__button public-confirmation-modal__button--secondary" data-public-confirmation-cancel>Cancel</button>',
            '<button type="button" class="public-confirmation-modal__button public-confirmation-modal__button--primary" data-public-confirmation-confirm>Confirm</button>',
            '</div>',
            '</div>',
        ].join('');
        document.body.appendChild(modal);

        title = modal.querySelector('#publicConfirmationTitle');
        message = modal.querySelector('[data-public-confirmation-message]');
        cancelButton = modal.querySelector('[data-public-confirmation-cancel]');
        confirmButton = modal.querySelector('[data-public-confirmation-confirm]');

        cancelButton?.addEventListener('click', close);
        confirmButton?.addEventListener('click', function () {
            if (!current || current.busy) {
                return;
            }
            current.onConfirm(current.controls);
        });
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                close();
            }
        });
    };

    const setBusy = function (label) {
        if (!current || !cancelButton || !confirmButton) {
            return;
        }

        current.busy = true;
        modal?.setAttribute('data-processing', 'true');
        cancelButton.disabled = true;
        confirmButton.disabled = true;
        confirmButton.textContent = label;
    };

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && current && !current.busy) {
            event.preventDefault();
            close();
        }
    });

    window.EdgePublicConfirmation = {
        open: function (options) {
            if (!options || typeof options.onConfirm !== 'function') {
                return null;
            }

            ensureModal();
            if (!modal || !title || !message || !cancelButton || !confirmButton) {
                return null;
            }

            if (current) {
                return null;
            }

            lastFocusedElement = document.activeElement;
            current = {
                busy: false,
                onConfirm: options.onConfirm,
                controls: { setBusy: setBusy },
            };
            title.textContent = options.title || 'Please confirm';
            message.textContent = options.message || '';
            cancelButton.textContent = options.cancelLabel || 'Cancel';
            confirmButton.textContent = options.confirmLabel || 'Confirm';
            confirmButton.classList.toggle('public-confirmation-modal__button--danger', options.tone === 'danger');
            cancelButton.disabled = false;
            confirmButton.disabled = false;
            modal.removeAttribute('data-processing');
            modal.hidden = false;
            document.body.classList.add('public-confirmation-open');
            cancelButton.focus();
            return current.controls;
        },
    };
})();
