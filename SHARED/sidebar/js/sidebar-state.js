(function () {
    try {
        var sharedKey = 'edge.operations.sidebar.shrink';
        var oldAdminKey = 'edgeSidebarCollapsed';
        var oldEngineerKey = 'engineer.sidebar.shrink';
        var saved = window.localStorage.getItem(sharedKey);

        if (saved === null) {
            saved = window.localStorage.getItem(oldEngineerKey);
        }

        if (saved === null) {
            saved = window.localStorage.getItem(oldAdminKey);
        }

        if (window.innerWidth > 768 && saved === '1') {
            document.documentElement.classList.add('ops-sidebar-shrink-pref');
        }
    } catch (error) {
        // Safe fallback kapag blocked ang browser storage.
    }
})();
