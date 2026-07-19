/**
 * Aria Home — filter / facet interactions, pagination, featured hero carousel
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
            offset: parseInt(el.getAttribute("data-offset") || "0", 10) || 0,
            perPage: parseInt(el.getAttribute("data-per-page") || "12", 10) || 12,
            total: parseInt(el.getAttribute("data-total") || "0", 10) || 0,
        };
    }

    function writeState(el, state) {
        el.setAttribute("data-kind", state.kind);
        el.setAttribute("data-collection", String(state.collection));
        el.setAttribute("data-tags", state.tags.join(","));
        el.setAttribute("data-offset", String(state.offset));
        el.setAttribute("data-per-page", String(state.perPage));
        el.setAttribute("data-total", String(state.total));
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

    function updatePager(el, state, hasMore) {
        var shown = el.querySelector("#aria-shown-count");
        var totals = el.querySelectorAll("#aria-asset-count-num, .aria-total-count");
        var more = el.querySelector("#aria-load-more");
        if (shown) {
            shown.textContent = String(state.offset);
        }
        totals.forEach(function (node) {
            node.textContent = String(state.total);
        });
        if (more) {
            more.classList.toggle("is-hidden", !hasMore);
            more.disabled = false;
        }
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

    function applySpan(grid) {
        if (!grid) {
            return;
        }
        var cards = grid.querySelectorAll(":scope > .resource-card");
        cards.forEach(function (card, index) {
            card.classList.toggle("chroma-span-2", index === 0 && cards.length > 3);
        });
    }

    /**
     * @param {HTMLElement} el
     * @param {{reset?: boolean}} opts
     */
    function fetchPage(el, opts) {
        var reset = !!(opts && opts.reset);
        var state = stateFromDom(el);
        var ajax = el.getAttribute("data-ajax");
        if (!ajax) {
            return;
        }
        var grid = el.querySelector("#aria-grid");
        var more = el.querySelector("#aria-load-more");
        var requestOffset = reset ? 0 : state.offset;

        if (grid && reset) {
            grid.classList.add("is-loading");
        }
        if (more && !reset) {
            more.disabled = true;
        }

        var params = new URLSearchParams({
            kind: state.kind,
            collection: String(state.collection),
            tags: state.tags.join(","),
            offset: String(requestOffset),
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
                state.total = parseInt(data.total, 10) || 0;
                state.offset = parseInt(data.next_offset, 10) || 0;
                if (data.per_page) {
                    state.perPage = parseInt(data.per_page, 10) || state.perPage;
                }
                writeState(el, state);

                if (grid) {
                    if (reset) {
                        grid.innerHTML = data.html || "";
                        grid.classList.remove("is-loading");
                    } else if (data.html) {
                        var empty = grid.querySelector(".aria-empty");
                        if (empty) {
                            empty.remove();
                        }
                        grid.insertAdjacentHTML("beforeend", data.html);
                    }
                    applySpan(grid);
                }
                updatePager(el, state, !!data.has_more);
                setActiveButtons(el, state);
            })
            .catch(function () {
                if (grid) {
                    grid.classList.remove("is-loading");
                }
                if (more) {
                    more.disabled = false;
                }
            });
    }

    function refresh(el) {
        fetchPage(el, { reset: true });
    }

    function loadMore(el) {
        fetchPage(el, { reset: false });
    }

    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    }

    /**
     * Prefer stills on slow / metered connections; play motion when bandwidth looks fine.
     */
    function shouldUseHeroVideo() {
        if (prefersReducedMotion()) {
            return false;
        }
        var conn =
            navigator.connection ||
            navigator.mozConnection ||
            navigator.webkitConnection;
        if (!conn) {
            return true;
        }
        if (conn.saveData) {
            return false;
        }
        var type = String(conn.effectiveType || "").toLowerCase();
        if (type === "slow-2g" || type === "2g" || type === "3g") {
            return false;
        }
        if (typeof conn.downlink === "number" && conn.downlink > 0 && conn.downlink < 1.5) {
            return false;
        }
        return true;
    }

    function hydrateHeroSlide(slide) {
        if (!slide) {
            return;
        }
        var img = slide.querySelector("img.aria-hero-image[data-src]");
        if (img && !img.getAttribute("src")) {
            img.setAttribute("src", img.getAttribute("data-src"));
            img.removeAttribute("data-src");
        }
    }

    function pauseHeroVideo(slide) {
        if (!slide) {
            return;
        }
        var video = slide.querySelector("video.aria-hero-video");
        if (video) {
            try {
                video.pause();
            } catch (e) {
                /* ignore */
            }
            if (video._ariaTrimHandler) {
                video.removeEventListener("timeupdate", video._ariaTrimHandler);
                video._ariaTrimHandler = null;
            }
        }
        slide.classList.remove("is-playing-video");
    }

    function heroTrimRange(video) {
        var inTime = parseFloat(video.getAttribute("data-in-time") || "0");
        var outTime = parseFloat(video.getAttribute("data-out-time") || "0");
        if (!isFinite(inTime) || inTime < 0) {
            inTime = 0;
        }
        if (!isFinite(outTime) || outTime <= 0) {
            outTime = 0;
        }
        // Only treat as a trim when out is meaningfully after in
        var hasTrim = outTime > inTime + 0.05;
        return { inTime: inTime, outTime: outTime, hasTrim: hasTrim };
    }

    function attachHeroTrimLoop(video) {
        if (video._ariaTrimHandler) {
            video.removeEventListener("timeupdate", video._ariaTrimHandler);
        }
        var trim = heroTrimRange(video);
        if (!trim.hasTrim) {
            video.loop = true;
            video._ariaTrimHandler = null;
            return;
        }
        video.loop = false;
        video._ariaTrimHandler = function () {
            if (video.paused) {
                return;
            }
            // Restart just before out so the loop feels continuous
            if (video.currentTime >= trim.outTime - 0.04) {
                try {
                    video.currentTime = trim.inTime;
                } catch (e) {
                    /* ignore seek errors mid-load */
                }
            }
        };
        video.addEventListener("timeupdate", video._ariaTrimHandler);
    }

    function seekHeroToIn(video, done) {
        var trim = heroTrimRange(video);
        var target = trim.hasTrim ? trim.inTime : 0;

        function finish() {
            video.removeEventListener("seeked", onSeeked);
            video.removeEventListener("loadedmetadata", onMeta);
            if (typeof done === "function") {
                done();
            }
        }

        function onSeeked() {
            finish();
        }

        function onMeta() {
            try {
                if (Math.abs(video.currentTime - target) > 0.05) {
                    video.currentTime = target;
                    return;
                }
            } catch (e) {
                /* ignore */
            }
            finish();
        }

        video.addEventListener("seeked", onSeeked);
        if (video.readyState >= 1) {
            try {
                video.currentTime = target;
                // If already at target, seeked may not fire
                window.setTimeout(function () {
                    if (Math.abs(video.currentTime - target) < 0.08) {
                        finish();
                    }
                }, 120);
            } catch (e) {
                finish();
            }
        } else {
            video.addEventListener("loadedmetadata", onMeta);
        }
    }

    function playHeroVideo(slide, allowVideo) {
        if (!slide || !allowVideo || slide.getAttribute("data-has-video") !== "1") {
            pauseHeroVideo(slide);
            return;
        }
        var video = slide.querySelector("video.aria-hero-video");
        if (!video) {
            return;
        }
        var src = video.getAttribute("data-src") || "";
        if (!src) {
            return;
        }
        if (!video.getAttribute("src")) {
            video.setAttribute("src", src);
            video.load();
        }
        attachHeroTrimLoop(video);
        seekHeroToIn(video, function () {
            var playPromise = video.play();
            if (playPromise && typeof playPromise.then === "function") {
                playPromise
                    .then(function () {
                        slide.classList.add("is-playing-video");
                    })
                    .catch(function () {
                        // Autoplay blocked or failed — keep the still
                        pauseHeroVideo(slide);
                    });
            } else {
                slide.classList.add("is-playing-video");
            }
        });
    }

    /**
     * Pin the hero to the viewport width with position:fixed.
     * #UICenter uses overflow-y:auto which clips 100vw breakouts.
     */
    function bindHeroFullBleed(hero) {
        if (!hero || hero.dataset.fullBleedBound === "1") {
            syncHeroFullBleed(hero);
            return;
        }
        hero.dataset.fullBleedBound = "1";

        var spacer = hero.previousElementSibling;
        if (!spacer || !spacer.classList.contains("aria-hero-spacer")) {
            spacer = document.createElement("div");
            spacer.className = "aria-hero-spacer";
            spacer.setAttribute("aria-hidden", "true");
            if (hero.parentNode) {
                hero.parentNode.insertBefore(spacer, hero);
            }
        }
        hero._ariaHeroSpacer = spacer;
        hero.classList.add("is-fullbleed");

        var sync = function () {
            syncHeroFullBleed(hero);
        };
        sync();

        var scroller = document.getElementById("UICenter") || window;
        scroller.addEventListener("scroll", sync, { passive: true });
        window.addEventListener("resize", sync);
        if (typeof ResizeObserver !== "undefined") {
            var ro = new ResizeObserver(sync);
            ro.observe(spacer);
        }
    }

    function syncHeroFullBleed(hero) {
        if (!hero || !hero.classList.contains("is-fullbleed")) {
            return;
        }
        var spacer = hero._ariaHeroSpacer || hero.previousElementSibling;
        if (!spacer || !spacer.classList.contains("aria-hero-spacer")) {
            return;
        }

        // Spacer owns in-flow height from CSS (min(52vh, 560px))
        spacer.style.height = "";
        spacer.style.minHeight = "";

        var rect = spacer.getBoundingClientRect();
        hero.style.top = Math.round(rect.top) + "px";
        hero.style.left = "0px";
        hero.style.width = window.innerWidth + "px";
        hero.style.height = Math.round(rect.height) + "px";
    }

    function bindHeroCarousel(hero) {
        if (!hero || hero.dataset.heroBound === "1") {
            bindHeroFullBleed(hero);
            return;
        }
        var slides = Array.prototype.slice.call(hero.querySelectorAll(".aria-hero-slide"));
        var dots = Array.prototype.slice.call(hero.querySelectorAll(".aria-hero-dot"));
        if (!slides.length) {
            return;
        }
        hero.dataset.heroBound = "1";
        bindHeroFullBleed(hero);

        var index = 0;
        var timer = null;
        var allowVideo = shouldUseHeroVideo();
        var interval = parseInt(hero.getAttribute("data-interval") || "7000", 10) || 7000;
        if (interval < 3000) {
            interval = 3000;
        }

        // Warm the first slide immediately
        hydrateHeroSlide(slides[0]);
        playHeroVideo(slides[0], allowVideo);

        // Prefetch the next still (and optionally video metadata) on a quiet tick
        if (slides.length > 1) {
            window.setTimeout(function () {
                hydrateHeroSlide(slides[1]);
            }, 400);
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
            hydrateHeroSlide(slide);
            pauseHeroVideo(prev);
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
            playHeroVideo(slide, allowVideo);
            // Prefetch the following still
            hydrateHeroSlide(slides[(next + 1) % slides.length]);
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
            if (prefersReducedMotion() || slides.length < 2) {
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

        if (slides.length > 1) {
            hero.addEventListener("mouseenter", stop);
            hero.addEventListener("mouseleave", start);
            hero.addEventListener("focusin", stop);
            hero.addEventListener("focusout", function (e) {
                if (!hero.contains(e.relatedTarget)) {
                    start();
                }
            });
        }

        document.addEventListener("visibilitychange", function () {
            if (document.hidden) {
                stop();
                pauseHeroVideo(slides[index]);
            } else {
                playHeroVideo(slides[index], allowVideo);
                start();
            }
        });

        // If connection quality changes mid-session, adapt
        var conn =
            navigator.connection ||
            navigator.mozConnection ||
            navigator.webkitConnection;
        if (conn && typeof conn.addEventListener === "function") {
            conn.addEventListener("change", function () {
                allowVideo = shouldUseHeroVideo();
                if (allowVideo) {
                    playHeroVideo(slides[index], true);
                } else {
                    pauseHeroVideo(slides[index]);
                }
            });
        }

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
            var moreBtn = e.target.closest("#aria-load-more");
            if (!kindBtn && !facetBtn && !tagBtn && !moreBtn) {
                return;
            }
            e.preventDefault();

            if (moreBtn) {
                loadMore(el);
                return;
            }

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

            state.offset = 0;
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
