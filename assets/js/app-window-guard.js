// Shared window guard: check login session at sync lang ang tunay na logout.
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
    const loginLogoutPath = '/codesamplecaps/LOGIN/php/login.php?logout=1';
    const loginTimeoutPath = '/codesamplecaps/LOGIN/php/login.php?timeout=1';
    const logoutPath = '/codesamplecaps/LOGIN/php/logout.php';
    const authStatusPath = '/codesamplecaps/LOGIN/php/auth_status.php';
    const logoutBroadcastKey = 'edge.auth.logout';
    let lastSessionCheckAt = 0;

    if (!currentRolePath) {
        return;
    }

    const redirectToLoggedOutLogin = function (reason) {
        if (window.location.pathname.startsWith('/codesamplecaps/LOGIN/')) {
            return;
        }

        window.location.replace(reason === 'timeout' ? loginTimeoutPath : loginLogoutPath);
    };

    const broadcastLogout = function () {
        try {
            localStorage.setItem(logoutBroadcastKey, JSON.stringify({
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
                    redirectToLoggedOutLogin(payload && payload.timeout === true ? 'timeout' : 'logout');
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

    window.addEventListener('storage', function (event) {
        if (event.key === logoutBroadcastKey && event.newValue) {
            redirectToLoggedOutLogin();
        }
    });

    document.addEventListener('click', function (event) {
        const logoutLink = event.target.closest('a[href*="/codesamplecaps/LOGIN/php/logout.php"]');
        if (logoutLink) {
            broadcastLogout();
        }
    }, true);

    window.addEventListener('focus', function () {
        checkSessionStillValid();
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            checkSessionStillValid();
        }
    });

    bindLogoutLinks();
    checkSessionStillValid();
})();
