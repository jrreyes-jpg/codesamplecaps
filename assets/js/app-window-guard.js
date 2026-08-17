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
    const loginLogoutPath = '/codesamplecaps/LOGIN/php/login.php?logout=1';
    const logoutPath = '/codesamplecaps/LOGIN/php/logout.php';
    const authStatusPath = '/codesamplecaps/LOGIN/php/auth_status.php';
    const logoutBroadcastKey = 'edge.auth.logout';
    let lastSessionCheckAt = 0;

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

    const redirectToLoggedOutLogin = function () {
        if (window.location.pathname.startsWith('/codesamplecaps/LOGIN/')) {
            return;
        }

        window.location.replace(loginLogoutPath);
    };

    const broadcastLogout = function () {
        try {
            localStorage.setItem(logoutBroadcastKey, JSON.stringify({
                id: windowId,
                at: Date.now(),
                path: window.location.pathname + window.location.search,
            }));
        } catch (error) {
            // Kapag blocked ang storage, server logout pa rin ang susunod.
        }
    };

    const checkSessionStillValid = function () {
        const now = Date.now();
        if (now - lastSessionCheckAt < 4000) {
            return;
        }
        lastSessionCheckAt = now;

        fetch(authStatusPath, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function (response) {
                return response.ok ? response.json() : { authenticated: false };
            })
            .then(function (payload) {
                if (!payload || payload.authenticated !== true) {
                    redirectToLoggedOutLogin();
                }
            })
            .catch(function () {
                // Kapag network hiccup, huwag agad i-logout para hindi false alarm.
            });
    };

    const bindLogoutLinks = function () {
        document.querySelectorAll('a[href*="/codesamplecaps/LOGIN/php/logout.php"]').forEach(function (link) {
            if (link.dataset.logoutBroadcastBound === '1') {
                return;
            }

            link.dataset.logoutBroadcastBound = '1';
            link.addEventListener('click', broadcastLogout);
        });
    };

    const moveOldHiddenWindow = function (state) {
        if (!state || state.id === windowId || !document.hidden) {
            return;
        }

        window.location.replace(homePath);
    };

    window.addEventListener('storage', function (event) {
        if (event.key === logoutBroadcastKey && event.newValue) {
            redirectToLoggedOutLogin();
            return;
        }

        if (event.key !== activeKey || !event.newValue) {
            return;
        }

        try {
            moveOldHiddenWindow(JSON.parse(event.newValue));
        } catch (error) {
            // Ignore invalid storage payload.
        }
    });

    document.addEventListener('click', function (event) {
        const logoutLink = event.target.closest('a[href*="/codesamplecaps/LOGIN/php/logout.php"]');
        if (logoutLink) {
            broadcastLogout();
        }
    }, true);

    window.addEventListener('focus', function () {
        markActive();
        checkSessionStillValid();
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            markActive();
            checkSessionStillValid();
        }
    });

    bindLogoutLinks();
    markActive();
    checkSessionStillValid();
})();
