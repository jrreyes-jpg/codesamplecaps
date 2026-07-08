(function () {
    'use strict';

    var endpoint = '/codesamplecaps/api/live_updates.php?scope=super_admin_overview';
    var pollMs = 30000;
    var numberFormatter = new Intl.NumberFormat('en-PH');
    var labelMap = {
        'assigned projects': 'assigned_projects',
        'active projects': 'active_projects',
        'completed projects': 'completed_projects',
        'open tasks': 'open_tasks',
        'your projects': 'total_projects',
        'projects': 'total_projects',
        'tasks': 'open_tasks',
        'quotations': 'pending_quotations',
        'delayed tasks': 'delayed_tasks',
        'inventory alerts': 'inventory_alerts',
        'on-hold projects': 'on_hold_projects',
        'in progress': 'ongoing_projects',
        'pending': 'pending_projects',
        'on hold': 'on_hold_projects',
        'total assets': 'total_assets',
        'assets in use': 'assets_in_use',
        'needs attention': 'needs_attention',
        'usage logs today': 'usage_logs_today',
        'scans today': 'scans_today',
        'workers today': 'workers_today'
    };

    function normalizeLabel(value) {
        return String(value || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function formatValue(value) {
        if (typeof value === 'number' && Number.isFinite(value)) {
            return numberFormatter.format(value);
        }

        return String(value == null ? '' : value);
    }

    function flash(element) {
        element.classList.remove('edge-live-updated');
        window.requestAnimationFrame(function () {
            element.classList.add('edge-live-updated');
        });
    }

    function setText(element, value) {
        var next = formatValue(value);
        if (element && element.textContent.trim() !== next) {
            element.textContent = next;
            flash(element.closest('.stat-card, .metric-card, .metric-tile, .overview-attention-card, .mini-overview-card, .aside-stat, .status-strip__item, .automation-metric') || element);
        }
    }

    function findMetricValueElement(card) {
        return card.querySelector('[data-live-value], strong, p');
    }

    function updateExplicitTargets(metrics) {
        document.querySelectorAll('[data-live-metric]').forEach(function (element) {
            var key = element.getAttribute('data-live-metric');
            if (key && Object.prototype.hasOwnProperty.call(metrics, key)) {
                setText(element, metrics[key]);
            }
        });
    }

    function updateCardsByLabel(metrics) {
        var root = document.querySelector('[data-superadmin-overview]');
        var cards = (root || document).querySelectorAll('.stat-card, .metric-card, .metric-tile, .overview-attention-card, .mini-overview-card, .aside-stat, .status-strip__item, .automation-metric');

        cards.forEach(function (card) {
            var labelNode = card.querySelector('h4, span, .stat-card__content > span');
            var valueNode = findMetricValueElement(card);

            if (!labelNode || !valueNode) {
                return;
            }

            var key = labelMap[normalizeLabel(labelNode.textContent)];
            if (key && Object.prototype.hasOwnProperty.call(metrics, key)) {
                setText(valueNode, metrics[key]);
            }
        });
    }

    function updateNotificationBadge(data) {
        var badge = document.querySelector('.topbar-notifications__badge');
        var toggle = document.getElementById('topbarNotificationToggle');
        var count = Number(data.notification_count || (data.metrics && data.metrics.needs_attention) || 0);

        if (!toggle) {
            return;
        }

        if (count <= 0) {
            if (badge) {
                badge.remove();
            }
            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'topbar-notifications__badge';
            toggle.appendChild(badge);
        }

        setText(badge, count > 99 ? '99+' : count);
    }

    function updateNotificationPanel(data) {
        var panel = document.getElementById('topbarNotificationDropdown');
        if (!panel || !Array.isArray(data.notifications)) {
            return;
        }

        var section = panel.querySelector('.topbar-notifications__section');
        if (!section) {
            return;
        }

        var title = section.querySelector('.topbar-notifications__section-title');
        var html = title ? title.outerHTML : '';

        if (data.notifications.length === 0) {
            html += '<div class="topbar-notifications__empty">No urgent updates right now.</div>';
        } else {
            html += data.notifications.slice(0, 5).map(function (item) {
                var tone = item.tone || 'neutral';
                return '<article class="notification-item notification-item--' + tone + '">' +
                    '<span class="notification-item__dot"></span>' +
                    '<div class="notification-item__copy">' +
                    '<strong>' + escapeHtml(item.title || 'Update') + '</strong>' +
                    '<span>' + escapeHtml(item.detail || '') + '</span>' +
                    '</div>' +
                    '</article>';
            }).join('');
        }

        section.innerHTML = html;
    }

    function updateRecentActivity(data) {
        var feed = document.querySelector('[data-live-activity-feed]');
        if (!feed || !Array.isArray(data.recent_activity)) {
            return;
        }

        if (data.recent_activity.length === 0) {
            feed.innerHTML = '<div class="alert-empty">No recent activity yet.</div>';
            return;
        }

        feed.innerHTML = data.recent_activity.slice(0, 5).map(function (item) {
            var badge = String(item.badge || 'audit').replace(/[^a-z0-9_-]/ig, '').toLowerCase() || 'audit';
            var badgeLetter = String(item.badge || 'A').charAt(0).toUpperCase();

            return '<article class="activity-item">' +
                '<span class="activity-badge activity-' + badge + '">' + escapeHtml(badgeLetter) + '</span>' +
                '<span class="activity-copy">' +
                '<strong>' + escapeHtml(item.title || 'Activity') + '</strong>' +
                '<span>' + escapeHtml(item.details || '') + '</span>' +
                '</span>' +
                '<time datetime="' + escapeHtml(item.created_at || '') + '">' +
                '<span class="activity-time-relative">' + escapeHtml(item.relative_time || '') + '</span>' +
                '</time>' +
                '</article>';
        }).join('');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function applyPayload(data) {
        if (!data || data.status !== 'success') {
            return;
        }

        var metrics = data.metrics || {};
        updateExplicitTargets(metrics);
        updateCardsByLabel(metrics);
        updateNotificationBadge(data);
        updateNotificationPanel(data);
        updateRecentActivity(data);
        document.body.classList.remove('edge-live-error');
    }

    function fetchUpdates() {
        if (document.hidden) {
            return;
        }

        window.fetch(endpoint, {
            headers: {
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Live update failed');
                }
                return response.json();
            })
            .then(applyPayload)
            .catch(function () {
                document.body.classList.add('edge-live-error');
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.querySelector('[data-superadmin-overview]')) {
            return;
        }

        fetchUpdates();
        window.setInterval(fetchUpdates, pollMs);
        document.addEventListener('visibilitychange', fetchUpdates);
    });
})();
