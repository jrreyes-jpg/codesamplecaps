(function () {
    'use strict';

    let lockCount = 0;

    window.SharedModalScrollLock = {
        lock: function () {
            lockCount++;
            document.documentElement.classList.add('shared-modal-scroll-lock');
            document.body.classList.add('shared-modal-scroll-lock');
        },
        unlock: function () {
            lockCount = Math.max(0, lockCount - 1);
            if (lockCount === 0) {
                document.documentElement.classList.remove('shared-modal-scroll-lock');
                document.body.classList.remove('shared-modal-scroll-lock');
            }
        },
    };
}());
