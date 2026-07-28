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
});
