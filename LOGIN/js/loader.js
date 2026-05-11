(function () {
    "use strict";

    var loader = document.getElementById("pageLoader");
    var startedAt = Date.now();
    var minimumDisplayTime = 2000;
    var maximumDisplayTime = 2200;
    var fadeDuration = 420;
    var isHidden = false;

    if (!loader) {
        return;
    }

    document.body.classList.add("loader-lock");

    function hideLoader() {
        if (isHidden) {
            return;
        }

        isHidden = true;
        loader.classList.add("is-hidden");
        document.body.classList.remove("loader-lock");

        window.setTimeout(function () {
            loader.setAttribute("aria-hidden", "true");
            loader.remove();
        }, fadeDuration);
    }

    function scheduleHide() {
        var elapsed = Date.now() - startedAt;
        var remaining = Math.max(minimumDisplayTime - elapsed, 0);

        window.setTimeout(hideLoader, remaining);
    }

    window.addEventListener("load", function () {
        scheduleHide();
    });

    window.setTimeout(hideLoader, maximumDisplayTime);
})();
