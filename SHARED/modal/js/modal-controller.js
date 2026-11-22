(function () {
    'use strict';

    const focusableSelector = [
        'button:not([disabled])',
        'a[href]',
        'input:not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',');

    window.SharedModalController = {
        create: function (options) {
            const settings = options || {};
            const modal = settings.modal;
            if (!modal) return null;

            let trigger = null;
            let isOpen = false;

            const visibleControls = function () {
                return Array.from(modal.querySelectorAll(focusableSelector)).filter(function (control) {
                    return !control.closest('[hidden]');
                });
            };

            const close = function (closeOptions) {
                const closeSettings = closeOptions || {};
                if (!isOpen || (settings.canClose && !settings.canClose() && !closeSettings.force)) return false;
                modal.hidden = true;
                isOpen = false;
                window.SharedModalScrollLock?.unlock();
                settings.onClose?.();
                if (closeSettings.restoreFocus !== false) trigger?.focus();
                trigger = null;
                return true;
            };

            const open = function (openOptions) {
                const openSettings = openOptions || {};
                if (isOpen) return;
                trigger = openSettings.trigger || document.activeElement;
                modal.hidden = false;
                isOpen = true;
                window.SharedModalScrollLock?.lock();
                settings.onOpen?.();
                window.requestAnimationFrame(function () {
                    (openSettings.focus || visibleControls()[0])?.focus();
                });
            };

            modal.addEventListener('click', function (event) {
                if (event.target === modal) close();
            });

            modal.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    close();
                    return;
                }
                if (event.key !== 'Tab') return;
                const controls = visibleControls();
                if (controls.length === 0) return;
                const first = controls[0];
                const last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });

            return {
                open: open,
                close: close,
                isOpen: function () { return isOpen; },
            };
        },
    };
}());
