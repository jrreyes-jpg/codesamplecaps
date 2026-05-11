document.addEventListener('DOMContentLoaded', function () {
    const phTime = document.querySelector('[data-ph-time]');
    const phDate = document.querySelector('[data-ph-date]');
    const notificationRoot = document.querySelector('[data-notification-root]');
    const notificationToggle = document.getElementById('topbarNotificationToggle');
    const notificationDropdown = document.getElementById('topbarNotificationDropdown');
    const profileRoot = document.querySelector('[data-profile-root]');
    const profileToggle = document.getElementById('topbarProfileToggle');
    const profileDropdown = document.getElementById('topbarProfileDropdown');
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const mobileToggleBtn = document.getElementById('sidebarMobileToggle');
    const overlay = document.getElementById('sidebarOverlay');
    const mainContent = document.querySelector('.main-content');
    const storageKey = 'foremanSidebarCollapsed';

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
    }

    if (!sidebar || !overlay) {
        return;
    }

    const isMobile = function () {
        return window.innerWidth <= 768;
    };

    const syncMainContent = function () {
        const shouldShrink = sidebar.classList.contains('shrink') && !isMobile();
        if (mainContent) {
            mainContent.classList.toggle('sidebar-shrink', shouldShrink);
        }
    };

    const closeMobileSidebar = function () {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    };

    const updateToggleUi = function () {
        if (mobileToggleBtn) {
            const isOpen = sidebar.classList.contains('mobile-open');
            mobileToggleBtn.classList.toggle('active', isOpen);
            mobileToggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        if (toggleBtn) {
            const isCollapsed = sidebar.classList.contains('shrink');
            toggleBtn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            toggleBtn.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
        }
    };

    const applySidebarState = function () {
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
            updateToggleUi();
        });
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            if (isMobile()) {
                return;
            }

            const isCollapsed = sidebar.classList.toggle('shrink');
            window.localStorage.setItem(storageKey, isCollapsed ? '1' : '0');
            syncMainContent();
            updateToggleUi();
        });
    }

    overlay.addEventListener('click', function () {
        closeMobileSidebar();
        updateToggleUi();
    });

    document.querySelectorAll('.menu-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (!isMobile()) {
                return;
            }

            closeMobileSidebar();
            updateToggleUi();
        });
    });

    window.addEventListener('resize', applySidebarState);
    applySidebarState();
});
