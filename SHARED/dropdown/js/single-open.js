/* Isang dropdown lang ang puwedeng naka-open sa isang control group. */
(function () {
    'use strict';

    function closeOtherMenus(group, currentMenu) {
        group.querySelectorAll('[data-single-dropdown][open]').forEach(function (menu) {
            if (menu !== currentMenu) {
                menu.removeAttribute('open');
            }
        });
    }

    document.addEventListener('click', function (event) {
        const summary = event.target.closest('[data-single-dropdown] > summary');
        if (!summary) return;

        const menu = summary.parentElement;
        const group = menu.closest('[data-single-dropdown-group]');
        if (group) closeOtherMenus(group, menu);
    });

    document.addEventListener('pointerdown', function (event) {
        const control = event.target.closest('[data-single-dropdown-close]');
        if (!control) return;

        const group = control.closest('[data-single-dropdown-group]');
        if (group) closeOtherMenus(group, null);
    });

    document.addEventListener('focusin', function (event) {
        const control = event.target.closest('[data-single-dropdown-close]');
        if (!control) return;

        const group = control.closest('[data-single-dropdown-group]');
        if (group) closeOtherMenus(group, null);
    });
}());
