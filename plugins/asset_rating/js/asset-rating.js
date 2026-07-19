/**
 * Curated asset rating — click stars to set 0–5 (editors only)
 */
(function () {
    "use strict";

    function placeUnderPreview(wrap) {
        if (!wrap) {
            return;
        }
        var preview = document.querySelector("#previewimagewrapper");
        if (!preview || !preview.parentNode) {
            return;
        }
        // Sit directly under the player / still — not inside tabs or chroma body
        if (preview.nextElementSibling !== wrap) {
            preview.parentNode.insertBefore(wrap, preview.nextSibling);
        }
        wrap.classList.add("is-placed");
    }

    function bindControl(root) {
        if (!root || root.dataset.bound === "1") {
            return;
        }
        if (root.getAttribute("data-editable") !== "1") {
            return;
        }
        root.dataset.bound = "1";

        var stars = Array.prototype.slice.call(root.querySelectorAll("button.asset-rating-star"));
        var valueEl = root.querySelector(".asset-rating-value");
        var clearBtn = root.querySelector(".asset-rating-clear");
        var saveUrl = root.getAttribute("data-save-url") || "";
        var ref = parseInt(root.getAttribute("data-ref") || "0", 10) || 0;
        var csrfId = root.getAttribute("data-csrf-identifier") || "CSRFToken";
        var csrfToken = root.getAttribute("data-csrf-token") || "";

        function paint(rating, preview) {
            var show = typeof preview === "number" ? preview : rating;
            stars.forEach(function (star) {
                var v = parseInt(star.getAttribute("data-value") || "0", 10);
                star.classList.toggle("is-active", v <= rating && rating > 0);
                star.classList.toggle("is-preview", typeof preview === "number" && v <= preview);
            });
            if (valueEl) {
                valueEl.textContent = String(show) + "/5";
            }
            root.setAttribute("data-rating", String(rating));
        }

        function save(rating) {
            if (!saveUrl || !ref) {
                return;
            }
            root.classList.add("is-saving");
            root.classList.remove("is-error");
            var body = new URLSearchParams();
            body.set("ref", String(ref));
            body.set("rating", String(rating));
            body.set("ajax", "true");
            if (csrfId && csrfToken) {
                body.set(csrfId, csrfToken);
            }
            fetch(saveUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: body.toString(),
            })
                .then(function (r) {
                    return r.json().then(function (data) {
                        return { okHttp: r.ok, data: data };
                    });
                })
                .then(function (result) {
                    root.classList.remove("is-saving");
                    if (!result.okHttp || !result.data || !result.data.ok) {
                        root.classList.add("is-error");
                        paint(parseInt(root.getAttribute("data-rating") || "0", 10) || 0);
                        return;
                    }
                    if (result.data.CSRFToken) {
                        root.setAttribute("data-csrf-token", result.data.CSRFToken);
                        csrfToken = result.data.CSRFToken;
                    }
                    paint(parseInt(result.data.rating, 10) || 0);
                })
                .catch(function () {
                    root.classList.remove("is-saving");
                    root.classList.add("is-error");
                    paint(parseInt(root.getAttribute("data-rating") || "0", 10) || 0);
                });
        }

        stars.forEach(function (star) {
            star.addEventListener("mouseenter", function () {
                var v = parseInt(star.getAttribute("data-value") || "0", 10);
                root.classList.add("is-hovering");
                paint(parseInt(root.getAttribute("data-rating") || "0", 10) || 0, v);
            });
            star.addEventListener("mouseleave", function () {
                root.classList.remove("is-hovering");
                paint(parseInt(root.getAttribute("data-rating") || "0", 10) || 0);
            });
            star.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                var v = parseInt(star.getAttribute("data-value") || "0", 10);
                save(v);
            });
        });

        if (clearBtn) {
            clearBtn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                save(0);
            });
        }

        paint(parseInt(root.getAttribute("data-rating") || "0", 10) || 0);
    }

    function boot(scope) {
        var root = scope || document;
        var wraps = root.querySelectorAll(".asset-rating-view-wrap");
        wraps.forEach(function (wrap) {
            placeUnderPreview(wrap);
            var control = wrap.querySelector(".asset-rating");
            if (control) {
                // Allow re-bind after DOM move
                if (control.dataset.bound === "1" && !wrap.classList.contains("is-placed")) {
                    delete control.dataset.bound;
                }
                bindControl(control);
            }
        });
        // Also bind any loose controls
        root.querySelectorAll(".asset-rating[data-editable='1']").forEach(bindControl);
    }

    function bootSoon() {
        boot(document);
        // After chroma layout reshuffle
        window.setTimeout(function () {
            boot(document);
        }, 80);
        window.setTimeout(function () {
            boot(document);
        }, 300);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bootSoon);
    } else {
        bootSoon();
    }

    if (typeof jQuery !== "undefined") {
        jQuery(document).ajaxComplete(function () {
            window.setTimeout(bootSoon, 60);
        });
    }
})();
