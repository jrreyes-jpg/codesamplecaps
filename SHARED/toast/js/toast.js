document.addEventListener('DOMContentLoaded', function () {
    function closeToast(toast) {
        if (!toast || toast.classList.contains('is-hiding')) {
            return;
        }

        toast.classList.add('is-hiding');
        window.setTimeout(function () {
            toast.remove();
        }, 220);
    }

    document.querySelectorAll('[data-shared-toast]').forEach(function (toast) {
        var closeButton = toast.querySelector('[data-shared-toast-close]');
        var delay = toast.classList.contains('shared-toast--error') ? 8000 : 5000;

        if (closeButton) {
            closeButton.addEventListener('click', function () {
                closeToast(toast);
            });
        }

        window.setTimeout(function () {
            closeToast(toast);
        }, delay);
    });
});
