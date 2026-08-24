// Header profile dropdown. Simple lang: open, close, ESC, outside click.
(function () {
    'use strict';

    if (window.edgeHeaderProfileStarted) {
        return;
    }

    window.edgeHeaderProfileStarted = true;

    var initProfile = function () {
        var roots = Array.from(document.querySelectorAll('[data-profile-root]'));

        roots.forEach(function (root) {
            var toggle = root.querySelector('[data-profile-toggle], #topbarProfileToggle');
            var dropdown = root.querySelector('.topbar-profile__dropdown');

            if (!toggle || !dropdown) {
                return;
            }

            var setOpen = function (isOpen) {
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                dropdown.hidden = !isOpen;
            };

            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                setOpen(toggle.getAttribute('aria-expanded') !== 'true');
                document.dispatchEvent(new CustomEvent('edge:header-profile-toggle'));
            });

            dropdown.querySelectorAll('a, button').forEach(function (item) {
                item.addEventListener('click', function () {
                    setOpen(false);
                });
            });

            document.addEventListener('click', function (event) {
                if (!root.contains(event.target)) {
                    setOpen(false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setOpen(false);
                }
            });

            document.addEventListener('edge:header-notification-toggle', function () {
                setOpen(false);
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProfile, { once: true });
    } else {
        initProfile();
    }
})();
