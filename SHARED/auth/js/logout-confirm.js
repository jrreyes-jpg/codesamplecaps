// Iisang logout confirm para pareho ang gamit ng lahat ng role.
(function () {
    'use strict';

    if (window.__edgeLogoutConfirmInitialized) {
        return;
    }
    window.__edgeLogoutConfirmInitialized = true;

    var pendingLogoutUrl = '';
    var isLoggingOut = false;
    var modal = document.createElement('div');

    modal.className = 'shared-logout-confirm';
    modal.hidden = true;
    modal.innerHTML = [
        '<div class="shared-logout-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="sharedLogoutConfirmTitle">',
        '<h2 id="sharedLogoutConfirmTitle">Log out?</h2>',
        '<p>Are you sure you want to log out of your account?</p>',
        '<p class="shared-logout-confirm__error" data-logout-confirm-error role="alert" hidden></p>',
        '<div class="shared-logout-confirm__actions">',
        '<button type="button" class="shared-logout-confirm__cancel" data-logout-confirm-cancel>Cancel</button>',
        '<button type="button" class="shared-logout-confirm__submit" data-logout-confirm-submit>Log Out</button>',
        '</div>',
        '</div>',
    ].join('');

    var closeModal = function () {
        if (isLoggingOut) {
            return;
        }
        pendingLogoutUrl = '';
        modal.hidden = true;
    };

    var showModal = function (logoutUrl) {
        pendingLogoutUrl = logoutUrl;
        var errorBox = modal.querySelector('[data-logout-confirm-error]');
        errorBox.hidden = true;
        errorBox.textContent = '';
        modal.hidden = false;
        modal.querySelector('[data-logout-confirm-cancel]')?.focus();
    };

    var setLoading = function (loading) {
        var cancelButton = modal.querySelector('[data-logout-confirm-cancel]');
        var submitButton = modal.querySelector('[data-logout-confirm-submit]');
        isLoggingOut = loading;
        cancelButton.disabled = loading;
        submitButton.disabled = loading;
        submitButton.textContent = loading ? 'Logging out...' : 'Log Out';
    };

    var showLogoutError = function () {
        var errorBox = modal.querySelector('[data-logout-confirm-error]');
        errorBox.textContent = 'Logout failed. Please check your connection and try again.';
        errorBox.hidden = false;
        if (typeof window.showToast === 'function') {
            window.showToast(errorBox.textContent, 'error');
        }
    };

    var isManualLogoutLink = function (link) {
        if (!link) {
            return false;
        }

        try {
            var url = new URL(link.href, window.location.origin);
            return url.pathname === '/codesamplecaps/LOGIN/php/logout.php'
                && !url.searchParams.has('timeout');
        } catch (error) {
            return false;
        }
    };

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!isManualLogoutLink(link) || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        if (!modal.hidden || isLoggingOut) {
            return;
        }
        showModal(link.href);
    }, true);

    modal.querySelector('[data-logout-confirm-cancel]')?.addEventListener('click', closeModal);
    modal.querySelector('[data-logout-confirm-submit]')?.addEventListener('click', function () {
        if (!pendingLogoutUrl || isLoggingOut) {
            return;
        }

        var logoutUrl = pendingLogoutUrl;
        var errorBox = modal.querySelector('[data-logout-confirm-error]');
        errorBox.hidden = true;
        errorBox.textContent = '';
        setLoading(true);
        window.__edgeLogoutInProgress = true;

        fetch(logoutUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'follow',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function (response) {
                var targetUrl = new URL(response.url || '', window.location.origin);
                if (!response.ok || targetUrl.pathname !== '/codesamplecaps/LOGIN/php/login.php') {
                    throw new Error('Logout response was not valid.');
                }

                window.dispatchEvent(new CustomEvent('edge:logout-complete'));
                window.location.replace(targetUrl.href);
            })
            .catch(function () {
                window.__edgeLogoutInProgress = false;
                setLoading(false);
                showLogoutError();
                modal.querySelector('[data-logout-confirm-submit]')?.focus();
            });
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            event.preventDefault();
            closeModal();
        }
    });

    document.body.appendChild(modal);
})();
