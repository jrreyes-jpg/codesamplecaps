(function () {
    function closeToast(toast) {
        if (!toast || toast.classList.contains('is-hiding')) {
            return;
        }

        toast.classList.add('is-hiding');
        window.setTimeout(function () {
            toast.remove();
        }, 220);
    }

    function startToast(toast, delay) {
        if (toast.hasAttribute('data-shared-toast-ready')) {
            return;
        }

        toast.setAttribute('data-shared-toast-ready', '');
        var closeButton = toast.querySelector('[data-shared-toast-close]');

        closeButton?.addEventListener('click', function (event) {
            event.stopPropagation();
            closeToast(toast);
        });

        window.setTimeout(function () {
            closeToast(toast);
        }, delay);
    }

    window.showToast = function (message, type, options) {
        var safeType = ['success', 'error', 'warning'].includes(type) ? type : 'success';
        var settings = options || {};
        var requestedDuration = Number(settings.duration);
        var delay = Number.isFinite(requestedDuration) && requestedDuration >= 1000
            ? requestedDuration
            : (safeType === 'error' ? 8000 : 5000);
        var toast = document.createElement('div');
        var text = document.createElement('span');
        var closeButton = document.createElement('button');
        var progress = document.createElement('span');

        toast.className = 'shared-toast shared-toast--' + safeType;
        if (delay === 3000) {
            toast.classList.add('shared-toast--duration-3s');
        }
        toast.setAttribute('data-shared-toast', '');
        toast.setAttribute('data-dynamic-toast', '');
        toast.setAttribute('role', safeType === 'error' ? 'alert' : 'status');
        text.textContent = String(message || '');
        closeButton.type = 'button';
        closeButton.className = 'shared-toast__close';
        closeButton.setAttribute('data-shared-toast-close', '');
        closeButton.setAttribute('aria-label', 'Close notification');
        closeButton.textContent = '\u00d7';
        progress.className = 'shared-toast__progress';
        progress.setAttribute('aria-hidden', 'true');
        toast.append(text, closeButton, progress);

        if (typeof settings.onClick === 'function') {
            toast.classList.add('shared-toast--clickable');
            toast.setAttribute('role', 'button');
            toast.setAttribute('tabindex', '0');
            toast.addEventListener('click', function () {
                settings.onClick();
                closeToast(toast);
            });
            toast.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    settings.onClick();
                    closeToast(toast);
                }
            });
        }

        document.querySelector('[data-shared-toast][data-dynamic-toast]')?.remove();
        document.body.appendChild(toast);
        startToast(toast, delay);
        return toast;
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-shared-toast]').forEach(function (toast) {
            var delay = toast.classList.contains('shared-toast--error') ? 8000 : 5000;
            startToast(toast, delay);
        });
    });
})();
