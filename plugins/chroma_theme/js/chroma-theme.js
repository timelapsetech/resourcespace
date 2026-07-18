/**
 * Chroma Theme — layout enhancements for image-forward DAM UI.
 * Works with CentralSpaceLoad / ModalLoad AJAX navigation.
 */
(function () {
    "use strict";

    var DESCRIPTIVE_FIELD_HINTS = [
        "caption",
        "description",
        "title",
        "abstract",
        "notes",
        "comment",
        "keyword",
        "subject",
        "tag",
    ];

    function markBody() {
        document.documentElement.classList.add("chroma-theme");
        if (document.body) {
            document.body.classList.add("chroma-theme");
        }
    }

    function enhanceGallery(root) {
        var scope = root || document;
        var cards = scope.querySelectorAll("#CentralSpaceResources > .resource-card");
        if (!cards.length) {
            return;
        }

        cards.forEach(function (card, index) {
            card.classList.toggle("chroma-span-2", index === 0 && cards.length > 3);
        });
    }

    function isDescriptiveField(item) {
        var heading = item.querySelector("h3");
        if (!heading) {
            return false;
        }
        var label = (heading.textContent || "").toLowerCase().trim();
        return DESCRIPTIVE_FIELD_HINTS.some(function (hint) {
            return label.indexOf(hint) !== -1;
        });
    }

    function markDescriptiveFields(scope) {
        var items = scope.querySelectorAll("#Metadata .item, #Metadata .itemNarrow");
        items.forEach(function (item) {
            if (isDescriptiveField(item)) {
                item.classList.add("chroma-field-block");
            }
        });
    }

    function enhanceAssetView(root) {
        var scope = root || document;
        var recordResources = scope.querySelectorAll(".RecordResource");
        if (!recordResources.length) {
            return;
        }

        recordResources.forEach(function (recordResource) {
            if (recordResource.dataset.chromaEnhanced === "1") {
                return;
            }

            var preview = recordResource.querySelector("#previewimagewrapper");
            var download = recordResource.querySelector(":scope > .RecordDownload, :scope > .clearerleft + .RecordDownload");
            // Prefer direct children / siblings under RecordResource
            if (!download) {
                download = recordResource.querySelector(".RecordDownload");
            }
            var metadata = recordResource.querySelector("#Metadata");
            var panel1 = recordResource.querySelector("#Panel1");

            if (!preview || (!download && !metadata && !panel1)) {
                return;
            }

            recordResource.dataset.chromaEnhanced = "1";

            var body = document.createElement("div");
            body.className = "chroma-asset-body";

            var main = document.createElement("div");
            main.className = "chroma-asset-main";

            var aside = document.createElement("div");
            aside.className = "chroma-asset-aside";

            // Title block from nearest RecordHeader
            var panel = recordResource.closest(".RecordPanel");
            var header = panel ? panel.querySelector(".RecordHeader") : null;
            var titleEl = header ? header.querySelector("h1") : null;
            if (titleEl) {
                var titleText = titleEl.textContent.replace(/\u00a0/g, " ").trim();
                if (titleText && titleText.toLowerCase() !== "error") {
                    var titleBlock = document.createElement("div");
                    titleBlock.className = "chroma-title-block";

                    var eyebrow = document.createElement("div");
                    eyebrow.className = "chroma-eyebrow";
                    eyebrow.textContent = "Asset";

                    var titleClone = document.createElement("h1");
                    titleClone.className = "chroma-title";
                    titleClone.textContent = titleText;

                    titleBlock.appendChild(eyebrow);
                    titleBlock.appendChild(titleClone);
                    main.appendChild(titleBlock);
                }
            }

            // Technical properties → aside
            var nonMeta = metadata ? metadata.querySelector(".NonMetadataProperties") : null;
            if (nonMeta) {
                aside.appendChild(nonMeta);
            }

            // Descriptive metadata → main
            if (panel1) {
                main.appendChild(panel1);
            }
            if (metadata && !main.contains(metadata)) {
                main.appendChild(metadata);
            }

            // Downloads / tools → aside
            if (download) {
                aside.appendChild(download);
            }

            body.appendChild(main);
            if (aside.childNodes.length) {
                body.appendChild(aside);
            }

            // Insert body after preview stage
            if (preview.parentNode === recordResource) {
                if (preview.nextSibling) {
                    recordResource.insertBefore(body, preview.nextSibling);
                } else {
                    recordResource.appendChild(body);
                }
            } else {
                recordResource.appendChild(body);
            }

            markDescriptiveFields(recordResource);
        });
    }

    function enhance(root) {
        markBody();
        enhanceGallery(root);
        enhanceAssetView(root);
        markDescriptiveFields(root || document);
    }

    function patchAjaxLoaders() {
        if (typeof jQuery === "undefined") {
            return;
        }

        // Re-run after CentralSpace / Modal content swaps
        jQuery(document).ajaxComplete(function (_event, _xhr, settings) {
            var url = (settings && settings.url) || "";
            if (
                url.indexOf("search.php") !== -1 ||
                url.indexOf("view.php") !== -1 ||
                url.indexOf("collections") !== -1 ||
                url.indexOf("home.php") !== -1
            ) {
                window.setTimeout(function () {
                    enhance(document);
                }, 30);
            }
        });

        // Mutation observer for CentralSpace swaps that don't fire ajaxComplete cleanly
        var central = document.getElementById("CentralSpace");
        if (central && typeof MutationObserver !== "undefined") {
            var timer = null;
            var observer = new MutationObserver(function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    enhance(document);
                }, 50);
            });
            observer.observe(central, { childList: true, subtree: false });
        }

        var modal = document.getElementById("modal");
        if (modal && typeof MutationObserver !== "undefined") {
            var modalTimer = null;
            var modalObserver = new MutationObserver(function () {
                window.clearTimeout(modalTimer);
                modalTimer = window.setTimeout(function () {
                    enhance(modal);
                }, 50);
            });
            modalObserver.observe(modal, { childList: true, subtree: true });
        }
    }

    function boot() {
        enhance(document);
        patchAjaxLoaders();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
