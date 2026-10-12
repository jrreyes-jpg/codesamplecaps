// Client bell notifications gamit ang shared user_notifications table.
(function () {
    'use strict';

    function initClientNotifications() {
        const root = document.querySelector('[data-notification-root][data-client-notification-endpoint]');
        if (!root) return;

        const endpoint = root.dataset.clientNotificationEndpoint || '';
        const csrfToken = root.dataset.clientNotificationCsrf || '';
        const list = root.querySelector('[data-client-notification-list]');
        const badge = root.querySelector('[data-client-notification-badge]');
        const countLabel = root.querySelector('[data-client-notification-count]');

        list?.addEventListener('click', function (event) {
            const link = event.target.closest('[data-client-notification-id]');
            if (!link) return;

            event.preventDefault();
            const notificationId = Number.parseInt(link.dataset.clientNotificationId || '0', 10);
            const targetUrl = link.href;
            if (!endpoint || !csrfToken || notificationId <= 0) {
                window.location.assign(targetUrl);
                return;
            }

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ notification_id: notificationId, csrf_token: csrfToken }),
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Unable to mark notification as read.');
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) return;
                    const unreadCount = Math.max(0, Number.parseInt(data.unread_count || '0', 10));
                    if (badge) {
                        badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
                        badge.hidden = unreadCount === 0;
                    }
                    if (countLabel) countLabel.textContent = unreadCount + ' unread';
                })
                .catch(function () {
                    // Bubukas pa rin ang page. Mananatiling unread kapag failed ang update.
                })
                .finally(function () {
                    window.location.assign(targetUrl);
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initClientNotifications);
    } else {
        initClientNotifications();
    }
})();
