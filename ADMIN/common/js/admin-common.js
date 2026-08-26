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

    const phTime = document.querySelector('[data-ph-time]');
    const phDate = document.querySelector('[data-ph-date]');
    const notificationRoot = document.querySelector('[data-notification-root]');
    const notificationToggle = document.getElementById('topbarNotificationToggle');
    const notificationDropdown = document.getElementById('topbarNotificationDropdown');
    const profileRoot = document.querySelector('[data-profile-root]');
    const profileToggle = document.getElementById('topbarProfileToggle');
    const profileDropdown = document.getElementById('topbarProfileDropdown');
    const idleTimeoutMs = 15 * 60 * 1000;
    let lastActivityAt = Date.now();
    let idleTimerId = null;

    const redirectIfIdle = function () {
        const idleFor = Date.now() - lastActivityAt;

        if (idleFor >= idleTimeoutMs) {
            window.location.replace('/codesamplecaps/LOGIN/php/logout.php?timeout=1');
            return;
        }

        scheduleIdleLogout();
    };

    const scheduleIdleLogout = function () {
        if (idleTimerId) {
            window.clearTimeout(idleTimerId);
        }

        const remainingMs = Math.max(1000, idleTimeoutMs - (Date.now() - lastActivityAt));
        idleTimerId = window.setTimeout(function () {
            // Huwag mag-auto logout habang nasa ibang tab para hindi biglang login form sa Alt-Tab.
            if (document.hidden) {
                return;
            }

            redirectIfIdle();
        }, remainingMs);
    };

    const markActive = function () {
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

    if (
        phTime &&
        phDate &&
        !window.edgeOperationsHeaderClockStarted &&
        !document.querySelector('script[src$="/SHARED/header/core/operations-header.js"]')
    ) {
        const timeFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true,
        });

        const dateFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric',
        });

        const syncPhilippineClock = function () {
            const now = new Date();
            phTime.textContent = timeFormatter.format(now);
            phDate.textContent = dateFormatter.format(now);
        };

        syncPhilippineClock();
        window.setInterval(syncPhilippineClock, 1000);
    }

    if (notificationRoot && notificationToggle && notificationDropdown) {
        const setNotificationState = function (isOpen) {
            notificationToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            notificationDropdown.hidden = !isOpen;
        };

        notificationToggle.addEventListener('click', function (event) {
            event.preventDefault();
            const isOpen = notificationToggle.getAttribute('aria-expanded') === 'true';
            setNotificationState(!isOpen);

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
    }

    if (
        profileRoot &&
        profileToggle &&
        profileDropdown &&
        !window.edgeHeaderProfileStarted &&
        !document.querySelector('script[src$="/SHARED/header/profile/js/profile.js"]')
    ) {
        const setProfileState = function (isOpen) {
            profileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            profileDropdown.hidden = !isOpen;
        };

        profileToggle.addEventListener('click', function (event) {
            event.preventDefault();
            const isOpen = profileToggle.getAttribute('aria-expanded') === 'true';
            setProfileState(!isOpen);

            if (notificationToggle && notificationDropdown) {
                notificationToggle.setAttribute('aria-expanded', 'false');
                notificationDropdown.hidden = true;
            }
        });

        document.addEventListener('click', function (event) {
            if (!profileRoot.contains(event.target)) {
                setProfileState(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setProfileState(false);
            }
        });

        profileDropdown.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                setProfileState(false);
            });
        });
    }

    document.querySelectorAll('.links a').forEach(function (link) {
        if (link.textContent.includes('Forgot Password')) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                window.location.href = '/codesamplecaps/views/auth/forgot.php';
            });
        }
    });

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

});

const form = document.querySelector('form');

if (form) {
    form.addEventListener('submit', function () {
        const btn = document.getElementById('resetBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerText = 'Sending...';
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const statusField = document.getElementById('status');
    const startDateField = document.getElementById('start_date');
    const startDateHelp = document.getElementById('start-date-help');
    const initialStatusHelp = document.getElementById('initial-status-help');

    if (statusField && startDateField) {
        const syncCreateProjectFields = function () {
            const isDraft = statusField.value === 'draft';
            const isOngoing = statusField.value === 'ongoing';
            startDateField.required = false;

            if (startDateHelp) {
                if (isDraft) {
                    startDateHelp.textContent = 'Optional while draft. Add the purchase order date once it is available.';
                } else if (isOngoing) {
                    startDateHelp.textContent = 'Use the purchase order date for tracking. Completion date will be recorded automatically later.';
                } else {
                    startDateHelp.textContent = 'Use the purchase order date for approved work. You can still update it before completion.';
                }
            }

            if (initialStatusHelp) {
                initialStatusHelp.textContent = isDraft
                    ? 'Draft is safe for incomplete or mistaken entries. Finalize it later before adding tasks.'
                    : isOngoing
                        ? 'Ongoing marks work as active, while the project completion date will only appear once the project is completed.'
                        : 'Pending is the safe default for approved projects that have not started yet.';
            }

            startDateField.setCustomValidity('');
        };

        syncCreateProjectFields();
        statusField.addEventListener('change', syncCreateProjectFields);
    }
});

