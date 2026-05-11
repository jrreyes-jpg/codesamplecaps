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
    const shouldReplaceSuperAdminHistory = function (event, link) {
        if (!link || event.defaultPrevented) {
            return false;
        }

        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return false;
        }

        if (link.target && link.target.toLowerCase() !== '_self') {
            return false;
        }

        if (link.hasAttribute('download')) {
            return false;
        }

        let destination;
        try {
            destination = new URL(link.href, window.location.href);
        } catch (error) {
            return false;
        }

        if (destination.origin !== window.location.origin) {
            return false;
        }

        const isSuperAdminPath = destination.pathname.startsWith('/codesamplecaps/SUPERADMIN/');
        const isLogoutPath = destination.pathname === '/codesamplecaps/LOGIN/php/logout.php';
        const isSamePageHashOnly = destination.pathname === window.location.pathname
            && destination.search === window.location.search
            && destination.hash !== ''
            && destination.hash !== window.location.hash;

        return (isSuperAdminPath || isLogoutPath) && !isSamePageHashOnly;
    };

    document.addEventListener('click', function (event) {
        const link = event.target.closest && event.target.closest('a[href]');

        if (!shouldReplaceSuperAdminHistory(event, link)) {
            return;
        }

        event.preventDefault();
        window.location.replace(link.href);
    }, true);

    const phTime = document.querySelector('[data-ph-time]');
    const phDate = document.querySelector('[data-ph-date]');
    const notificationRoot = document.querySelector('[data-notification-root]');
    const notificationToggle = document.getElementById('topbarNotificationToggle');
    const notificationDropdown = document.getElementById('topbarNotificationDropdown');
    const profileRoot = document.querySelector('[data-profile-root]');
    const profileToggle = document.getElementById('topbarProfileToggle');
    const profileDropdown = document.getElementById('topbarProfileDropdown');
    const idleTimeoutMs = 15 * 60 * 1000;
    let idleTimerId = null;

    const scheduleIdleLogout = function () {
        if (idleTimerId) {
            window.clearTimeout(idleTimerId);
        }

        idleTimerId = window.setTimeout(function () {
            window.location.href = '/codesamplecaps/LOGIN/php/logout.php?timeout=1';
        }, idleTimeoutMs);
    };

    ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(function (eventName) {
        document.addEventListener(eventName, scheduleIdleLogout, { passive: true });
    });
    scheduleIdleLogout();

    if (phTime && phDate) {
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

    if (profileRoot && profileToggle && profileDropdown) {
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

    document.querySelectorAll('.togglePassword').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = btn.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (!input) return;

            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            btn.textContent = type === 'text' ? 'Hide' : 'Show';
            btn.setAttribute('aria-pressed', type === 'text' ? 'true' : 'false');
        });
    });

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

    // Shared sidebar behavior for dashboard and sidebar pages.
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const mobileToggleBtn = document.getElementById('sidebarMobileToggle');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleIcon = document.getElementById('toggleIcon');

    if (sidebar && overlay) {
        const mainContent = document.querySelector('.main-content');
        const storageKey = 'edgeSidebarCollapsed';
        const isMobile = () => window.innerWidth <= 900;
        let audioContext = null;

        const playSidebarSound = (variant) => {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) {
                return;
            }

            if (!audioContext) {
                audioContext = new AudioCtx();
            }

            if (audioContext.state === 'suspended') {
                audioContext.resume().catch(function () {
                    return null;
                });
            }

            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            const now = audioContext.currentTime;
            const profile = variant === 'toggle'
                ? { start: 540, end: 320, volume: 0.045, duration: 0.14 }
                : { start: 620, end: 460, volume: 0.03, duration: 0.09 };

            oscillator.type = variant === 'toggle' ? 'triangle' : 'sine';
            oscillator.frequency.setValueAtTime(profile.start, now);
            oscillator.frequency.exponentialRampToValueAtTime(profile.end, now + profile.duration);
            gainNode.gain.setValueAtTime(0.0001, now);
            gainNode.gain.exponentialRampToValueAtTime(profile.volume, now + 0.012);
            gainNode.gain.exponentialRampToValueAtTime(0.0001, now + profile.duration);
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.start(now);
            oscillator.stop(now + profile.duration);
        };

        const syncMainContent = () => {
            const shouldShrink = sidebar.classList.contains('shrink') && !isMobile();
            if (mainContent) {
                mainContent.classList.toggle('sidebar-shrink', shouldShrink);
            }
        };

        const closeMobileSidebar = () => {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
            document.body.classList.remove('sidebar-drawer-open');
        };

        const updateToggleUi = () => {
            if (isMobile()) {
                const isOpen = sidebar.classList.contains('mobile-open');
                if (mobileToggleBtn) {
                    mobileToggleBtn.classList.toggle('active', isOpen);
                    mobileToggleBtn.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
                    mobileToggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                }
                return;
            }

            if (mobileToggleBtn) {
                mobileToggleBtn.classList.remove('active');
                mobileToggleBtn.setAttribute('aria-label', 'Open navigation');
                mobileToggleBtn.setAttribute('aria-expanded', 'false');
            }

            if (toggleBtn && toggleIcon) {
                const isCollapsed = sidebar.classList.contains('shrink');
                toggleIcon.classList.toggle('is-collapsed', isCollapsed);
                toggleBtn.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
                toggleBtn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            }
        };

        const applySidebarState = () => {
            if (isMobile()) {
                sidebar.classList.remove('shrink');
                closeMobileSidebar();
                syncMainContent();
                updateToggleUi();
                return;
            }

            const shouldShrink = window.localStorage.getItem(storageKey) === '1';
            sidebar.classList.toggle('shrink', shouldShrink);
            closeMobileSidebar();
            syncMainContent();
            updateToggleUi();
        };

        if (mobileToggleBtn) {
            mobileToggleBtn.addEventListener('click', function () {
                if (!isMobile()) {
                    return;
                }

                const isOpen = sidebar.classList.toggle('mobile-open');
                overlay.classList.toggle('active', isOpen);
                document.body.classList.toggle('sidebar-drawer-open', isOpen);
                playSidebarSound('toggle');
                updateToggleUi();
            });
        }

        if (toggleBtn && toggleIcon) {
            toggleBtn.addEventListener('click', function () {
                if (isMobile()) {
                    return;
                }

                const isCollapsed = sidebar.classList.toggle('shrink');
                window.localStorage.setItem(storageKey, isCollapsed ? '1' : '0');
                syncMainContent();
                playSidebarSound('toggle');
                updateToggleUi();
            });
        }

        overlay.addEventListener('click', function () {
            closeMobileSidebar();
            updateToggleUi();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
                closeMobileSidebar();
                updateToggleUi();
            }
        });

        document.querySelectorAll('.menu-link').forEach(function (link) {
            link.addEventListener('click', function () {
                playSidebarSound('link');

                if (!isMobile()) {
                    return;
                }

                closeMobileSidebar();
                updateToggleUi();
            });
        });

        window.addEventListener('resize', applySidebarState);
        applySidebarState();
    }

    document.body.classList.add('page-loaded');

    document.querySelectorAll('[data-overview-analytics]').forEach(function (details) {
        let wasDesktop = null;
        const syncOverviewAnalytics = function () {
            const isDesktopOverview = window.innerWidth > 900;
            if (wasDesktop === isDesktopOverview) {
                return;
            }

            wasDesktop = isDesktopOverview;
            details.open = isDesktopOverview;
        };

        syncOverviewAnalytics();
        window.addEventListener('resize', syncOverviewAnalytics);

        details.addEventListener('toggle', function () {
            if (!details.open || window.innerWidth > 900) {
                return;
            }

            window.setTimeout(function () {
                details.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                });
            }, 80);
        });
    });

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

function showQR(src) {
    document.getElementById('qrModal').style.display = 'flex';
    document.getElementById('qrModalImg').src = src;
}

const modal = document.getElementById('qrModal');
if (modal) {
    modal.onclick = function () {
        this.style.display = 'none';
    };
}

document.addEventListener('DOMContentLoaded', function () {
    function scorePassword(value) {
        let score = 0;
        if (value.length >= 12) score++;
        if (/[A-Z]/.test(value)) score++;
        if (/[a-z]/.test(value)) score++;
        if (/\d/.test(value)) score++;
        if (/[^A-Za-z0-9]/.test(value)) score++;
        return score;
    }

    function applyStrengthUI(input, indicator) {
        const score = scorePassword(input.value);
        let text = 'Weak';
        let cls = 'weak';

        if (score >= 5) {
            text = 'Super Strong';
            cls = 'super-strong';
        } else if (score === 4) {
            text = 'Strong';
            cls = 'strong';
        } else if (score === 3) {
            text = 'Medium';
            cls = 'medium';
        }

        indicator.textContent = 'Strength: ' + text;
        indicator.className = 'pass-indicator ' + cls;
        input.classList.remove('weak-border', 'medium-border', 'strong-border');
        if (cls === 'weak') input.classList.add('weak-border');
        else if (cls === 'medium') input.classList.add('medium-border');
        else input.classList.add('strong-border');
    }

    const tempPass = document.getElementById('password');
    const tempIndicator = document.getElementById('tempPassStrength');
    if (tempPass && tempIndicator) {
        tempPass.addEventListener('input', function () {
            applyStrengthUI(tempPass, tempIndicator);
        });
    }

    document.querySelectorAll('[data-ph-phone-lock-prefix]').forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/[^0-9]/g, '');
            if (!input.value.startsWith('09')) {
                input.value = '09';
            }
        });
    });

    document.querySelectorAll('.user-row').forEach(function (row) {
        const editBtn = row.querySelector('[data-edit-btn]');
        const saveBtn = row.querySelector('[data-save-btn]');
        const cancelBtn = row.querySelector('[data-cancel-btn]');
        const inputs = row.querySelectorAll('.table-input');
        const rowId = row.getAttribute('data-row-id');
        const saveForm = document.getElementById('save-form-' + rowId);
        const originals = Array.from(inputs).map((input) => input.value);

        editBtn.addEventListener('click', function () {
            inputs.forEach((input) => input.removeAttribute('readonly'));
            editBtn.hidden = true;
            saveBtn.hidden = false;
            cancelBtn.hidden = false;
        });

        cancelBtn.addEventListener('click', function () {
            inputs.forEach((input, index) => {
                input.value = originals[index];
                input.setAttribute('readonly', 'readonly');
            });
            editBtn.hidden = false;
            saveBtn.hidden = true;
            cancelBtn.hidden = true;
        });

        saveBtn.addEventListener('click', function () {
            const byField = {};
            inputs.forEach((input) => {
                byField[input.getAttribute('data-field')] = input.value;
            });
            saveForm.querySelector('[data-save-field="full_name"]').value = byField.full_name || '';
            saveForm.querySelector('[data-save-field="email"]').value = byField.email || '';
            const phoneField = saveForm.querySelector('[data-save-field="phone"]');
            if (phoneField) {
                phoneField.value = byField.phone || phoneField.value || '';
            }
            saveForm.submit();
        });
    });

    const userManagementShell = document.querySelector('[data-user-management-shell]');
    const createUserModal = document.querySelector('[data-user-create-modal]');
    if (userManagementShell && createUserModal) {
        const openButtons = document.querySelectorAll('[data-open-create-modal]');
        const closeButtons = document.querySelectorAll('[data-close-create-modal]');
        const initialFocusTarget = createUserModal.querySelector('#full_name');
        const shouldOpenOnLoad = userManagementShell.getAttribute('data-create-modal-default-open') === 'true';

        const openCreateModal = function () {
            createUserModal.hidden = false;
            createUserModal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            if (initialFocusTarget) {
                window.setTimeout(function () {
                    initialFocusTarget.focus();
                }, 50);
            }
        };

        const closeCreateModal = function () {
            createUserModal.hidden = true;
            createUserModal.classList.remove('is-open');
            document.body.style.overflow = '';
        };

        openButtons.forEach(function (button) {
            button.addEventListener('click', openCreateModal);
        });

        closeButtons.forEach(function (button) {
            button.addEventListener('click', closeCreateModal);
        });

        createUserModal.addEventListener('click', function (event) {
            if (event.target === createUserModal) {
                closeCreateModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && createUserModal.classList.contains('is-open')) {
                closeCreateModal();
            }
        });

        if (shouldOpenOnLoad) {
            openCreateModal();
        }
    }

    const userSearchInput = document.querySelector('[data-user-search]');
    const userTableBody = document.querySelector('[data-user-table-body]');
    if (userSearchInput && userTableBody) {
        const rows = Array.from(userTableBody.querySelectorAll('.user-row'));
        const emptySearchRow = userTableBody.querySelector('.user-search-empty-row');

        const syncSearchResults = function () {
            const query = userSearchInput.value.trim().toLowerCase();
            let visibleCount = 0;

            rows.forEach(function (row) {
                const haystack = row.getAttribute('data-user-search') || '';
                const matches = query === '' || haystack.includes(query);
                row.hidden = !matches;
                if (matches) {
                    visibleCount += 1;
                }
            });

            if (emptySearchRow) {
                emptySearchRow.hidden = visibleCount !== 0;
            }
        };

        userSearchInput.addEventListener('input', syncSearchResults);
        syncSearchResults();
    }
});

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
