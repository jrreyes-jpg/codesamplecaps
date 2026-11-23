// Common Inventory Clerk page behavior will stay here when two or more pages use it.
(function () {
    'use strict';

    const root = document.querySelector('[data-clerk-notifications]');
    if (!root) return;

    const badge = root.querySelector('.topbar-notifications__badge');
    const list = root.querySelector('[data-delivery-notification-list]');
    const empty = root.querySelector('[data-notification-empty]');
    const staticCount = Number.parseInt(root.dataset.staticAlertCount || '0', 10) || 0;
    const lastToastKey = 'edge:clerk:last-task-notification-toast';

    function escapeText(value) {
        const node = document.createElement('span');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function render(state) {
        if (!list || !badge || !empty) return;
        const items = Array.isArray(state.items) ? state.items : [];
        const total = staticCount + (Number(state.unread_count) || 0);

        list.innerHTML = items.map(function (item) {
            const href = String(item.target_url || '/codesamplecaps/INVENTORY_CLERK/dashboards/delivery_verification.php');
            const separator = href.includes('?') ? '&' : '?';
            return '<a class="notification-item notification-item--info" href="' + escapeText(href + separator + 'notification_id=' + item.id) + '">' +
                '<span class="notification-item__dot" aria-hidden="true"></span>' +
                '<span class="notification-item__copy"><strong>' + escapeText(item.title) + '</strong><span>' + escapeText(item.message) + '</span></span>' +
                '<span class="notification-item__time">' + escapeText(item.relative_time) + '</span></a>';
        }).join('');
        list.hidden = items.length === 0;
        empty.hidden = total > 0;
        badge.hidden = total === 0;
        badge.textContent = total > 99 ? '99+' : String(total);

        const newest = items.reduce(function (latest, item) {
            return Number(item.id) > Number(latest?.id || 0) ? item : latest;
        }, null);
        const lastShownId = Number.parseInt(window.localStorage.getItem(lastToastKey) || '0', 10) || 0;
        if (newest && Number(newest.id) > lastShownId) {
            window.localStorage.setItem(lastToastKey, String(newest.id));
            if (typeof window.showToast === 'function') {
                window.showToast(newest.message, 'warning', {
                    duration: 5000,
                    onClick: function () {
                        window.location.href = String(newest.target_url || '/codesamplecaps/INVENTORY_CLERK/dashboards/dashboard.php');
                    }
                });
            }
        }
    }

    function refreshNotifications() {
        fetch('/codesamplecaps/INVENTORY_CLERK/actions/delivery_notification_state.php', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Unable to load notifications.');
                return response.json();
            })
            .then(render)
            .catch(function () {
                // Tahimik lang kapag mahina ang connection. Susubok ulit mamaya.
            });
    }

    refreshNotifications();
    window.setInterval(refreshNotifications, 30000);
}());
