(function () {
    "use strict";

    var loading = null;

    function getLoading() {
        if (loading) {
            return loading;
        }

        loading = document.createElement("div");
        loading.className = "edge-action-loading";
        loading.hidden = true;
        loading.setAttribute("role", "status");
        loading.setAttribute("aria-live", "polite");
        loading.innerHTML = [
            '<div class="edge-action-loading__panel">',
            '<span class="edge-action-loading__logo-wrap" aria-hidden="true">',
            '<img class="edge-action-loading__logo" src="/codesamplecaps/IMAGES/edge-favicon.png" alt="">',
            '</span>',
            '<strong class="edge-action-loading__title">Please wait...</strong>',
            '<span class="edge-action-loading__message">Do not close this page.</span>',
            '</div>'
        ].join("");
        document.body.appendChild(loading);
        return loading;
    }

    window.edgeActionLoading = {
        show: function (options) {
            var element = getLoading();
            var settings = options || {};
            element.querySelector(".edge-action-loading__title").textContent = settings.title || "Please wait...";
            element.querySelector(".edge-action-loading__message").textContent = settings.message || "Do not close this page.";
            element.setAttribute("aria-label", settings.title || "Loading");
            element.hidden = false;
            document.body.classList.add("edge-action-is-loading");
        },
        hide: function () {
            var element = getLoading();
            element.hidden = true;
            document.body.classList.remove("edge-action-is-loading");
        }
    };
})();
