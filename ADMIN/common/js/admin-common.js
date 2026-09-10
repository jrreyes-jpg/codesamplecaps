// Load shared duplicate-window guard for Admin pages.
(function () {
    if (document.querySelector('script[src$="/assets/js/app-window-guard.js"]')) {
        return;
    }

    const guardScript = document.createElement('script');
    guardScript.src = '/codesamplecaps/assets/js/app-window-guard.js';
    guardScript.defer = true;
    document.head.appendChild(guardScript);
})();

// ================================
// Canvas particle animation
// ================================
const canvas = document.getElementById('particles');
if (canvas) {
    const ctx = canvas.getContext('2d');
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;

    const particles = [];
    const particleCount = 30;

    class Particle {
        constructor() {
            this.x = Math.random() * canvas.width;
            this.y = Math.random() * canvas.height;
            this.size = Math.random() * 2 + 1;
            this.speedX = Math.random() * 1 - 0.5;
            this.speedY = Math.random() * 1 - 0.5;
            this.opacity = Math.random() * 0.5 + 0.3;
        }

        update() {
            this.x += this.speedX;
            this.y += this.speedY;
            if (this.x > canvas.width) this.x = 0;
            if (this.x < 0) this.x = canvas.width;
            if (this.y > canvas.height) this.y = 0;
            if (this.y < 0) this.y = canvas.height;
        }

        draw() {
            ctx.fillStyle = `rgba(100, 200, 255, ${this.opacity})`;
            ctx.beginPath();
            ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    for (let i = 0; i < particleCount; i++) {
        particles.push(new Particle());
    }

    function animate() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        particles.forEach((particle) => {
            particle.update();
            particle.draw();
        });
        requestAnimationFrame(animate);
    }

    animate();

    window.addEventListener('resize', () => {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    });
}

document.addEventListener('DOMContentLoaded', function () {
    try {
        localStorage.setItem('edge_auth_state', JSON.stringify({
            status: 'logged-in',
            at: Date.now(),
            dashboardPath: window.location.pathname + window.location.search,
        }));
    } catch (error) {
        // Okay lang kahit blocked ang localStorage; normal login flow pa rin.
    }

    document.querySelectorAll('[data-progress-width]').forEach(function (bar) {
        const rawValue = Number(bar.getAttribute('data-progress-width') || '0');
        const normalized = Math.max(0, Math.min(100, rawValue));
        bar.style.setProperty('--pulse-progress', normalized + '%');
    });

    document.querySelectorAll('[data-fill-width]').forEach(function (fill) {
        const rawValue = Number(fill.getAttribute('data-fill-width') || '0');
        const normalized = Math.max(0, Math.min(100, rawValue));
        fill.style.width = normalized + '%';
    });

    document.body.classList.add('page-loaded');

    const counters = document.querySelectorAll('.counter');
    counters.forEach((counter) => {
        const updateCount = () => {
            const target = +counter.getAttribute('data-target');
            const currentValue = +counter.innerText;
            const increment = target / 40;

            if (currentValue < target) {
                counter.innerText = Math.ceil(currentValue + increment);
                setTimeout(updateCount, 30);
            } else {
                counter.innerText = target;
            }
        };

        updateCount();
    });

    const notificationRoot = document.querySelector('[data-notification-root]');
    if (notificationRoot && !window.edgeAdminInquiryNotificationsStarted) {
        window.edgeAdminInquiryNotificationsStarted = true;

        const endpoint = notificationRoot.dataset.inquiryNotificationEndpoint || '';
        const csrfToken = notificationRoot.dataset.inquiryNotificationCsrf || '';
        const badge = notificationRoot.querySelector('[data-inquiry-notification-badge]');
        const countLabel = notificationRoot.querySelector('[data-inquiry-notification-count]');
        const list = notificationRoot.querySelector('[data-inquiry-notification-list]');
        const pendingReads = new Set();
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

        const inquiryUrl = function (inquiryId) {
            return '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?viewed_inquiry=' + encodeURIComponent(String(inquiryId));
        };

        const renderNotificationList = function (items) {
            if (!list) return;
            list.replaceChildren();

            if (!Array.isArray(items) || items.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'topbar-notifications__empty';
                empty.setAttribute('data-inquiry-notification-empty', '');
                empty.textContent = 'No unread inquiries.';
                list.appendChild(empty);
                return;
            }

            items.forEach(function (item) {
                const link = document.createElement('a');
                const dot = document.createElement('span');
                const copy = document.createElement('div');
                const name = document.createElement('strong');
                const details = document.createElement('span');
                const time = document.createElement('span');

                link.href = inquiryUrl(item.id);
                link.className = 'notification-item notification-item--inquiry-unviewed';
                link.dataset.inquiryNotificationId = String(item.id || '');
                dot.className = 'notification-item__dot';
                copy.className = 'notification-item__copy';
                name.textContent = String(item.client_name || 'Client inquiry');
                details.textContent = String(item.service_category || 'Service request') + ' • New inquiry';
                time.className = 'notification-item__time';
                time.textContent = formatRelativeTime(item.created_at);
                copy.append(name, details);
                link.append(dot, copy, time);
                list.appendChild(link);
            });
        };

        const applyNotificationState = function (data) {
            const unreadCount = Math.max(0, Number.parseInt(data.unread_count || '0', 10));
            if (badge) {
                badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
                badge.hidden = unreadCount === 0;
            }
            if (countLabel) {
                countLabel.textContent = unreadCount + ' unread';
            }
            renderNotificationList(data.items);
        };

        const openInquiry = function (inquiryId) {
            window.location.assign(inquiryUrl(inquiryId));
        };

        const markInquiryCardAsRead = function (inquiryId) {
            const normalizedId = String(inquiryId || '');
            const card = Array.from(document.querySelectorAll('[data-inquiry-card-id]')).find(function (item) {
                return item.dataset.inquiryCardId === normalizedId;
            });

            if (!card) return;

            card.classList.remove('is-unviewed');
            card.classList.add('is-viewed');
            card.querySelector('[data-inquiry-unread-indicator]')?.remove();
        };

        const showPollingToast = function (data) {
            if (typeof window.showToast !== 'function') return;

            const newItems = Array.isArray(data.new_items) ? data.new_items : [];
            if (data.show_unread_summary) {
                window.showToast('You have ' + Number.parseInt(data.unread_count || '0', 10) + ' unread inquiries.', 'success');
                return;
            }

            if (newItems.length === 1) {
                const item = newItems[0];
                window.showToast('New inquiry received from ' + String(item.client_name || 'a client') + '.', 'success', {
                    onClick: function () { openInquiry(item.id); },
                });
            } else if (newItems.length > 1) {
                window.showToast(newItems.length + ' new inquiries received.', 'success');
            }
        };

        const pollNotifications = function () {
            if (!endpoint || pollInProgress || document.hidden) return;
            pollInProgress = true;

            fetch(endpoint, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Notification check failed.');
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) return;
                    applyNotificationState(data);
                    showPollingToast(data);
                })
                .catch(function () {
                    // Susubok ulit sa next poll kapag may temporary error.
                })
                .finally(function () {
                    pollInProgress = false;
                });
        };

        const markInquiryRead = function (inquiryId) {
            const normalizedId = Number.parseInt(inquiryId || '0', 10);
            if (!endpoint || !csrfToken || normalizedId <= 0 || pendingReads.has(normalizedId)) return;
            pendingReads.add(normalizedId);

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ inquiry_id: normalizedId, csrf_token: csrfToken }),
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Unable to mark inquiry as read.');
                    return response.json();
                })
                .then(function (data) {
                    if (data.success) {
                        markInquiryCardAsRead(normalizedId);
                        applyNotificationState(data);
                    }
                })
                .catch(function () {
                    // Mananatiling unread kapag hindi naisave sa server.
                })
                .finally(function () {
                    pendingReads.delete(normalizedId);
                });
        };

        document.addEventListener('edge:inquiry-opened', function (event) {
            markInquiryRead(event.detail?.inquiryId);
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) pollNotifications();
        });

        pollNotifications();
        window.setInterval(pollNotifications, 12000);
    }

});
