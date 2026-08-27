// Shared idle timeout para pareho ang logout ng lahat ng role.
(function () {
    'use strict';

    if (window.__edgeSessionTimeoutInitialized) {
        return;
    }

    window.__edgeSessionTimeoutInitialized = true;

    var idleTimeoutMs = 15 * 60 * 1000;
    var logoutUrl = '/codesamplecaps/LOGIN/php/logout.php?timeout=1';
    var lastActivityAt = Date.now();
    var idleTimerId = null;
    var isRedirecting = false;

    var redirectIfIdle = function () {
        var idleFor = Date.now() - lastActivityAt;

        if (idleFor >= idleTimeoutMs) {
            if (!isRedirecting) {
                isRedirecting = true;
                window.location.replace(logoutUrl);
            }
            return;
        }

        scheduleIdleLogout();
    };

    var scheduleIdleLogout = function () {
        if (idleTimerId !== null) {
            window.clearTimeout(idleTimerId);
        }

        var remainingMs = Math.max(1000, idleTimeoutMs - (Date.now() - lastActivityAt));
        idleTimerId = window.setTimeout(function () {
            if (!document.hidden) {
                redirectIfIdle();
            }
        }, remainingMs);
    };

    var markActive = function () {
        if (isRedirecting) {
            return;
        }

        lastActivityAt = Date.now();
        scheduleIdleLogout();
    };

    ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(function (eventName) {
        document.addEventListener(eventName, markActive, { passive: true });
    });

    window.addEventListener('focus', redirectIfIdle);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            redirectIfIdle();
        }
    });

    scheduleIdleLogout();
})();
