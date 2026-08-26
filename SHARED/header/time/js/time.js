// Header time: one shared Philippine clock for Admin and Engineer.
(function () {
    'use strict';

    if (window.edgeOperationsHeaderClockStarted) {
        return;
    }

    var startClock = function () {
        var timeElements = Array.from(document.querySelectorAll('[data-operations-time], [data-ph-time], [data-engineer-time]'));
        var dateElements = Array.from(document.querySelectorAll('[data-operations-date], [data-ph-date], [data-engineer-date]'));

        if (timeElements.length === 0 || dateElements.length === 0) {
            return;
        }

        window.edgeOperationsHeaderClockStarted = true;

        var timeFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true,
        });

        var dateFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric',
        });

        var updateClock = function () {
            var now = new Date();
            var timeText = timeFormatter.format(now);
            var dateText = dateFormatter.format(now);

            timeElements.forEach(function (element) {
                element.textContent = timeText;
            });

            dateElements.forEach(function (element) {
                element.textContent = dateText;
            });
        };

        updateClock();
        window.setInterval(updateClock, 1000);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startClock, { once: true });
    } else {
        startClock();
    }
})();
