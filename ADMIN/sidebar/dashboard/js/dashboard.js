// JS ng Overview page. Ito ang nag-aayos ng analytics panel sa mobile at desktop.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-overview-analytics]').forEach(function (details) {
        let wasDesktop = null;

        const syncOverviewAnalytics = function () {
            const isDesktopOverview = window.innerWidth > 900;
            if (wasDesktop === isDesktopOverview) {
                return;
            }

            wasDesktop = isDesktopOverview;
            details.open = isDesktopOverview;
        };

        syncOverviewAnalytics();
        window.addEventListener('resize', syncOverviewAnalytics);
    });

    document.querySelectorAll('[data-confirm-message]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const message = form.getAttribute('data-confirm-message') || 'Are you sure?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-ph-mobile-input]').forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/[^0-9]/g, '');
            if (input.value && !input.value.startsWith('09')) {
                input.value = '09';
            }
        });
    });
});
