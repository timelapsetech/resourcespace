/**
 * Aria Home — filter / facet interactions + featured hero carousel
 */
(function () {
    "use strict";

    function root() {
        return document.getElementById("aria-home");
    }

    function stateFromDom(el) {
        var tags = (el.getAttribute("data-tags") || "")
            .split(",")
            .map(function (t) {
                return parseInt(t, 10);
            })
            .filter(function (t) {
                return t > 0;
            });
        return {
            kind: el.getAttribute("data-kind") || "all",
            collection: parseInt(el.getAttribute("data-collection") || "0", 10) || 0,
            tags: tags,
        };
    }

    function writeState(el, state) {
        el.setAttribute("data-kind", state.kind);
        el.setAttribute("data-collection", String(state.collection));
        el.setAttribute("data-tags", state.tags.join(","));
    }

    function setActiveButtons(el, state) {
        el.querySelectorAll(".aria-kind-btn").forEach(function (btn) {
            btn.classList.toggle("is-active", btn.getAttribute("data-kind") === state.kind);
        });
        el.querySelectorAll(".aria-facet-row").forEach(function (btn) {
            var c = parseInt(btn.getAttribute("data-collection") || "0", 10) || 0;
            btn.classList.toggle("is-active", c === state.collection);
        });
        el.querySelectorAll(".aria-pill[data-tag], .aria-tag[data-tag]").forEach(function (btn) {
            var t = parseInt(btn.getAttribute("data-tag") || "0", 10);
            btn.classList.toggle("is-active", state.tags.indexOf(t) !== -1);
        });
    }

    function toggleTag(state, tag) {
        var i = state.tags.indexOf(tag);
        if (i === -1) {
            state.tags.push(tag);
        } else {
            state.tags.splice(i, 1);
        }
        return state;
    }

    function refresh(el) {
        var state = stateFromDom(el);
        var ajax = el.getAttribute("data-ajax");
        if (!ajax) {
            return;
        }
        var grid = el.querySelector("#aria-grid");
        var count = el.querySelector("#aria-asset-count-num");
        if (grid) {
            grid.classList.add("is-loading");
        }

        var params = new URLSearchParams({
            kind: state.kind,
            collection: String(state.collection),
            tags: state.tags.join(","),
            offset: "0",
        });

        fetch(ajax + "?" + params.toString(), {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    return;
                }
                if (grid) {
                    grid.innerHTML = data.html || "";
                    grid.classList.remove("is-loading");
                    var cards = grid.querySelectorAll(":scope > .resource-card");
                    cards.forEach(function (card, index) {
                        card.classList.toggle("chroma-span-2", index === 0 && cards.length > 3);
                    });
                }
                if (count) {
                    count.textContent = String(data.total || 0);
                }
                setActiveButtons(el, state);
            })
            .catch(function () {
                if (grid) {
                    grid.classList.remove("is-loading");
                }
            });
    }

    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    }

    function bindHeroCarousel(hero) {
        if (!hero || hero.dataset.heroBound === "1") {
            return;
        }
        var slides = Array.prototype.slice.call(hero.querySelectorAll(".aria-hero-slide"));
        var dots = Array.prototype.slice.call(hero.querySelectorAll(".aria-hero-dot"));
        if (slides.length < 2) {
            return;
        }
        hero.dataset.heroBound = "1";

        var index = 0;
        var timer = null;
        var interval = parseInt(hero.getAttribute("data-interval") || "7000", 10) || 7000;
        if (interval < 3000) {
            interval = 3000;
        }

        function goTo(next) {
            if (next === index) {
                return;
            }
            var prev = slides[index];
            var slide = slides[next];
            if (!slide) {
                return;
            }
            if (prev) {
                prev.classList.remove("is-active");
                prev.setAttribute("aria-hidden", "true");
                prev.querySelectorAll("a").forEach(function (a) {
                    a.setAttribute("tabindex", "-1");
                });
            }
            slide.classList.add("is-active");
            slide.removeAttribute("aria-hidden");
            slide.querySelectorAll("a").forEach(function (a) {
                a.setAttribute("tabindex", "0");
            });
            dots.forEach(function (dot, i) {
                var on = i === next;
                dot.classList.toggle("is-active", on);
                dot.setAttribute("aria-selected", on ? "true" : "false");
            });
            index = next;
        }

        function nextSlide() {
            goTo((index + 1) % slides.length);
        }

        function start() {
            stop();
            if (prefersReducedMotion()) {
                return;
            }
            timer = window.setInterval(nextSlide, interval);
        }

        function stop() {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        dots.forEach(function (dot) {
            dot.addEventListener("click", function () {
                var i = parseInt(dot.getAttribute("data-slide") || "0", 10) || 0;
                goTo(i);
                start();
            });
        });

        hero.addEventListener("mouseenter", stop);
        hero.addEventListener("mouseleave", start);
        hero.addEventListener("focusin", stop);
        hero.addEventListener("focusout", function (e) {
            if (!hero.contains(e.relatedTarget)) {
                start();
            }
        });

        document.addEventListener("visibilitychange", function () {
            if (document.hidden) {
                stop();
            } else {
                start();
            }
        });

        start();
    }

    function bind(el) {
        if (!el || el.dataset.ariaBound === "1") {
            return;
        }
        el.dataset.ariaBound = "1";

        el.addEventListener("click", function (e) {
            var kindBtn = e.target.closest(".aria-kind-btn");
            var facetBtn = e.target.closest(".aria-facet-row");
            var tagBtn = e.target.closest(".aria-pill[data-tag], .aria-tag[data-tag]");
            if (!kindBtn && !facetBtn && !tagBtn) {
                return;
            }
            e.preventDefault();
            var state = stateFromDom(el);

            if (kindBtn) {
                state.kind = kindBtn.getAttribute("data-kind") || "all";
            }
            if (facetBtn) {
                state.collection = parseInt(facetBtn.getAttribute("data-collection") || "0", 10) || 0;
            }
            if (tagBtn) {
                var tag = parseInt(tagBtn.getAttribute("data-tag") || "0", 10);
                if (tag > 0) {
                    toggleTag(state, tag);
                }
            }

            writeState(el, state);
            setActiveButtons(el, state);
            refresh(el);
        });

        bindHeroCarousel(el.querySelector("[data-hero-carousel]"));
    }

    function boot() {
        var el = root();
        if (!el) {
            return;
        }
        // Allow carousel re-bind after CentralSpace swaps even if filters already bound
        if (el.dataset.ariaBound === "1") {
            bindHeroCarousel(el.querySelector("[data-hero-carousel]"));
            return;
        }
        bind(el);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }

    if (typeof jQuery !== "undefined") {
        jQuery(document).ajaxComplete(function () {
            window.setTimeout(boot, 40);
        });
    }
})();
