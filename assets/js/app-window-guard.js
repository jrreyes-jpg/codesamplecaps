// Shared window guard: iwas duplicate dashboard windows kapag may old tab/window na nagising.
(function () {
    const rolePaths = [
        '/codesamplecaps/ADMIN/',
        '/codesamplecaps/SUPERADMIN/',
        '/codesamplecaps/ENGINEER/',
        '/codesamplecaps/INVENTORY_CLERK/',
        '/codesamplecaps/FOREMAN/',
        '/codesamplecaps/CLIENT/',
    ];
    const currentRolePath = rolePaths.find((path) => window.location.pathname.startsWith(path));
    const homePath = '/codesamplecaps/LOGIN/php/index.php';

    if (!currentRolePath) {
        return;
    }

    const roleKey = currentRolePath.replace('/codesamplecaps/', '').replace('/', '').toLowerCase();
    const activeKey = `edge.${roleKey}.activeWindow`;
    const windowKey = `edge.${roleKey}.windowId`;

    let windowId = sessionStorage.getItem(windowKey);
    if (!windowId) {
        windowId = String(Date.now()) + '-' + Math.random().toString(16).slice(2);
        sessionStorage.setItem(windowKey, windowId);
    }

    const markActive = function () {
        try {
            localStorage.setItem(activeKey, JSON.stringify({
                id: windowId,
                path: window.location.pathname + window.location.search,
                at: Date.now(),
            }));
        } catch (error) {
            // Kapag blocked ang storage, normal page pa rin.
        }
    };

    const moveOldHiddenWindow = function (state) {
        if (!state || state.id === windowId || !document.hidden) {
            return;
        }

        window.location.replace(homePath);
    };

    window.addEventListener('storage', function (event) {
        if (event.key !== activeKey || !event.newValue) {
            return;
        }

        try {
            moveOldHiddenWindow(JSON.parse(event.newValue));
        } catch (error) {
            // Ignore invalid storage payload.
        }
    });

    window.addEventListener('focus', markActive);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            markActive();
        }
    });

    markActive();
})();
