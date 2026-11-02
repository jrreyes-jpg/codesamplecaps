(function () {
    "use strict";

    var loader = document.getElementById("pageLoader");
    var shownAt = 0;
    var showDelay = 350;
    var minimumVisibleTime = 300;
    var maximumDisplayTime = 8000;
    var fadeDuration = 180;
    var showTimer;
    var isHidden = false;
    var isVisible = false;

    if (!loader) {
        return;
    }

    function showLoader() {
        if (isHidden) {
            return;
        }

        isVisible = true;
        shownAt = Date.now();
        loader.classList.add("is-visible");
        document.body.classList.add("loader-lock");
    }

    function hideLoader() {
        if (isHidden) {
            return;
        }

        isHidden = true;
        window.clearTimeout(showTimer);
        loader.classList.remove("is-visible");
        loader.classList.add("is-hidden");
        document.body.classList.remove("loader-lock");

        window.setTimeout(function () {
            loader.setAttribute("aria-hidden", "true");
            loader.remove();
        }, fadeDuration);
    }

    function scheduleHide() {
        var elapsed = isVisible ? Date.now() - shownAt : minimumVisibleTime;
        var remaining = Math.max(minimumVisibleTime - elapsed, 0);

        window.setTimeout(hideLoader, remaining);
    }

    showTimer = window.setTimeout(showLoader, showDelay);
    window.addEventListener("load", scheduleHide, { once: true });

    window.setTimeout(hideLoader, maximumDisplayTime);
})();
