// Shared header notification dropdown behavior.
(function () {
    'use strict';

    function initNotifications() {
        if (window.edgeHeaderNotificationsStarted) {
            return;
        }

        const notificationRoot = document.querySelector('[data-notification-root]');
        const notificationToggle = document.getElementById('topbarNotificationToggle');
        const notificationDropdown = document.getElementById('topbarNotificationDropdown');

        if (!notificationRoot || !notificationToggle || !notificationDropdown) {
            return;
        }

        window.edgeHeaderNotificationsStarted = true;

        const setNotificationState = function (isOpen) {
            notificationToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            notificationDropdown.hidden = !isOpen;
        };

        notificationToggle.addEventListener('click', function (event) {
            event.preventDefault();

            const isOpen = notificationToggle.getAttribute('aria-expanded') === 'true';
            setNotificationState(!isOpen);
            document.dispatchEvent(new CustomEvent('edge:header-notification-toggle'));

            const profileToggle = document.getElementById('topbarProfileToggle');
            const profileDropdown = document.getElementById('topbarProfileDropdown');

            if (profileToggle && profileDropdown) {
                profileToggle.setAttribute('aria-expanded', 'false');
                profileDropdown.hidden = true;
            }
        });

        document.addEventListener('click', function (event) {
            if (!notificationRoot.contains(event.target)) {
                setNotificationState(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setNotificationState(false);
            }
        });
        document.addEventListener('edge:header-profile-toggle', function () {
    setNotificationState(false);
});

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNotifications);
    } else {
        initNotifications();
    }
})();