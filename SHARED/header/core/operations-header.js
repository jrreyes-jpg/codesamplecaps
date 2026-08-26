// Shared header loader for header components.
(function () {
    'use strict';

    var loadScriptOnce = function (src) {
        if (document.querySelector('script[src="' + src + '"]')) {
            return;
        }

        var script = document.createElement('script');
        script.src = src;
        script.defer = true;
        document.head.appendChild(script);
    };

    loadScriptOnce('/codesamplecaps/SHARED/header/time/js/time.js');
    loadScriptOnce('/codesamplecaps/SHARED/header/profile/js/profile.js');
    loadScriptOnce('/codesamplecaps/SHARED/header/notifications/js/notifications.js');
})();