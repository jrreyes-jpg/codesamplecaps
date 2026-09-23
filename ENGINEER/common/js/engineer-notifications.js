// Engineer bell notifications lang ito.
(function () {
    'use strict';

    function initEngineerNotifications() {
        const root = document.querySelector('[data-notification-root][data-engineer-notification-endpoint]');
        if (!root || window.edgeEngineerNotificationsStarted) {
            return;
        }

        window.edgeEngineerNotificationsStarted = true;
        const endpoint = root.dataset.engineerNotificationEndpoint || '';
        const csrfToken = root.dataset.engineerNotificationCsrf || '';
        const badge = root.querySelector('[data-engineer-notification-badge]');
        const countLabel = root.querySelector('[data-engineer-notification-count]');
        const list = root.querySelector('[data-engineer-notification-list]');
        let pollInProgress = false;

        const formatRelativeTime = function (dateTime) {
            const timestamp = Date.parse(String(dateTime || '').replace(' ', 'T'));
            if (!Number.isFinite(timestamp)) return '';

            const seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));
            if (seconds < 60) return 'Just now';
            if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
            if (seconds < 86400) return Math.floor(seconds / 3600) + ' hr ago';
            if (seconds < 604800) return Math.floor(seconds / 86400) + ' day(s) ago';
            return new Date(timestamp).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
        };

        const renderList = function (items) {
            if (!list) return;
            list.replaceChildren();

            if (!Array.isArray(items) || items.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'topbar-notifications__empty';
                empty.textContent = 'No unread notifications.';
                list.appendChild(empty);
                return;
            }

            items.forEach(function (item) {
                const link = document.createElement('a');
                const dot = document.createElement('span');
                const copy = document.createElement('div');
                const title = document.createElement('strong');
                const message = document.createElement('span');
                const time = document.createElement('span');

                link.href = String(item.target_url || '/codesamplecaps/ENGINEER/dashboards/site_inspections.php');
                link.className = 'notification-item notification-item--info';
                link.dataset.engineerNotificationId = String(item.id || '');
                dot.className = 'notification-item__dot';
                copy.className = 'notification-item__copy';
                title.textContent = String(item.title || 'New notification');
                message.textContent = String(item.message || '');
                time.className = 'notification-item__time';
                time.textContent = formatRelativeTime(item.created_at);
                copy.append(title, message);
                link.append(dot, copy, time);
                list.appendChild(link);
            });
        };

        const applyState = function (data) {
            const unreadCount = Math.max(0, Number.parseInt(data.unread_count || '0', 10));
            if (badge) {
                badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
                badge.hidden = unreadCount === 0;
            }
            if (countLabel) {
                countLabel.textContent = unreadCount + ' unread';
            }
            renderList(data.items);
        };

        const pollNotifications = function () {
            if (!endpoint || pollInProgress || document.hidden) return;
            pollInProgress = true;

            fetch(endpoint, { headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then(function (response) {
                    if (!response.ok) throw new Error('Notification check failed.');
                    return response.json();
                })
                .then(function (data) {
                    if (data.success) applyState(data);
                })
                .catch(function () {
                    // Susubok ulit sa next poll kapag may temporary error.
                })
                .finally(function () {
                    pollInProgress = false;
                });
        };

        list?.addEventListener('click', function (event) {
            const link = event.target.closest('[data-engineer-notification-id]');
            if (!link) return;

            event.preventDefault();
            const notificationId = Number.parseInt(link.dataset.engineerNotificationId || '0', 10);
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
                    if (data.success) applyState(data);
                })
                .catch(function () {
                    // Bubukas pa rin ang inspection. Mananatiling unread kapag failed ang update.
                })
                .finally(function () {
                    window.location.assign(targetUrl);
                });
        });

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) pollNotifications();
        });

        pollNotifications();
        window.setInterval(pollNotifications, 30000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEngineerNotifications);
    } else {
        initEngineerNotifications();
    }
})();
