(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const body = document.body;
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        const mobileToggle = document.querySelector(
            '[data-sidebar-mobile-toggle]'
        );
        const desktopToggle = document.querySelector(
            '[data-sidebar-toggle]'
        );
        const groupToggles = document.querySelectorAll(
            '[data-sidebar-group-toggle]'
        );

        const storageKey = 'edge.operations.sidebar.shrink';
        const mobileBreakpoint = 768;

        if (!sidebar) {
            return;
        }

        function isMobile() {
            return window.innerWidth <= mobileBreakpoint;
        }

        function setDesktopCollapsed(collapsed) {
            if (isMobile()) {
                body.classList.remove('sidebar-collapsed');
                sidebar.classList.remove('shrink');
                return;
            }

            body.classList.toggle('sidebar-collapsed', collapsed);
            sidebar.classList.toggle('shrink', collapsed);

            try {
                localStorage.setItem(
                    storageKey,
                    collapsed ? '1' : '0'
                );
            } catch (error) {
                // Safe fallback kapag blocked ang localStorage.
            }

            if (desktopToggle) {
                desktopToggle.setAttribute(
                    'aria-expanded',
                    collapsed ? 'false' : 'true'
                );
            }
        }

        function setMobileOpen(open) {
            sidebar.classList.toggle('mobile-open', open);
            body.classList.toggle('sidebar-mobile-open', open);

            if (overlay) {
                overlay.classList.toggle('active', open);
            }

            if (mobileToggle) {
                mobileToggle.setAttribute(
                    'aria-expanded',
                    open ? 'true' : 'false'
                );
            }
        }

        function getSavedCollapsedState() {
            try {
                return localStorage.getItem(storageKey) === '1';
            } catch (error) {
                return false;
            }
        }

        function applyResponsiveState() {
            if (isMobile()) {
                setMobileOpen(false);
                setDesktopCollapsed(false);
                return;
            }

            setDesktopCollapsed(getSavedCollapsedState());
        }

        if (desktopToggle) {
            desktopToggle.addEventListener('click', function () {
                if (isMobile()) {
                    return;
                }

                const currentlyCollapsed = body.classList.contains(
                    'sidebar-collapsed'
                );

                setDesktopCollapsed(!currentlyCollapsed);
            });
        }

        if (mobileToggle) {
            mobileToggle.addEventListener('click', function () {
                const currentlyOpen = sidebar.classList.contains(
                    'mobile-open'
                );

                setMobileOpen(!currentlyOpen);
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function () {
                setMobileOpen(false);
            });
        }

        groupToggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                const group = toggle.closest('.nav-menu-group');

                if (!group) {
                    return;
                }

                const panel = group.querySelector(
                    '[data-sidebar-group-panel]'
                );

                if (!panel) {
                    return;
                }

                const isOpen = group.classList.toggle('is-open');

                panel.hidden = !isOpen;

                toggle.setAttribute(
                    'aria-expanded',
                    isOpen ? 'true' : 'false'
                );
            });
        });

        document.querySelectorAll('.sidebar a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (isMobile()) {
                    setMobileOpen(false);
                }
            });
        });

        window.addEventListener('resize', applyResponsiveState);

        applyResponsiveState();
    });
})();
