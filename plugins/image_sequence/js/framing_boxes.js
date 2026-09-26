/**
 * Aspect-locked framing boxes over the Omakase player.
 * Coordinates are stored in source pixels; screen mapping uses object-fit:contain.
 */

let framingState = null;
let pollTimer = null;
let resizeObserver = null;

function clamp(n, min, max) {
    return Math.min(Math.max(n, min), max);
}

function even(n) {
    const v = Math.floor(n);
    return v - (v % 2);
}

function fitAspect(aspectW, aspectH, containerW, containerH) {
    const aw = Math.max(1, aspectW);
    const ah = Math.max(1, aspectH);
    let width = containerW;
    let height = Math.round((width * ah) / aw);
    if (height > containerH) {
        height = containerH;
        width = Math.round((height * aw) / ah);
    }
    width = even(width);
    height = even(height);
    return {width: Math.max(2, width), height: Math.max(2, height)};
}

function deliveryTargets(aspectW, aspectH) {
    const portrait = aspectH > aspectW;
    const uhdBox = portrait ? [2160, 3840] : [3840, 2160];
    const fhdBox = portrait ? [1080, 1920] : [1920, 1080];
    return {
        uhd: fitAspect(aspectW, aspectH, uhdBox[0], uhdBox[1]),
        fhd: fitAspect(aspectW, aspectH, fhdBox[0], fhdBox[1]),
    };
}

function tierForWidth(boxWidth, aspectW, aspectH) {
    const t = deliveryTargets(aspectW, aspectH);
    if (boxWidth >= t.uhd.width) {
        return 'ok';
    }
    if (boxWidth >= t.fhd.width) {
        return 'warn';
    }
    return 'low';
}

function tierLabel(tier, lang) {
    if (tier === 'warn') {
        return (lang && lang.framingTierWarn) || 'Below 4K (will upscale)';
    }
    if (tier === 'low') {
        return (lang && lang.framingTierLow) || 'Below 1080p';
    }
    return (lang && lang.framingTierOk) || '≥ 4K';
}

function postJson(url, data, csrf) {
    const payload = Object.assign({ajax: 'true'}, csrf || {}, data);
    return jQuery.ajax({
        method: 'POST',
        url: url,
        data: payload,
        dataType: 'json',
    });
}

function getJson(url, data) {
    return jQuery.ajax({
        method: 'GET',
        url: url,
        data: data,
        dataType: 'json',
    });
}

function setStatus(text, isError) {
    const el = jQuery('#image_sequence_frame_status');
    el.text(text || '');
    el.toggleClass('image_sequence_status_error', !!isError);
}

/**
 * Locate the object-fit:contain picture rect inside the overlay (CSS pixels),
 * and the scale factors that map TRUE source pixels ↔ that rect.
 *
 * Browser zoom / player size / proxy resolution only affect the on-screen rect.
 * Box x/y/width/height are always in original source pixels.
 */
function videoContentRect(state) {
    const host = document.getElementById(state.playerElementId);
    const overlay = document.getElementById('image_sequence_framing_overlay');
    if (!host || !overlay) {
        return null;
    }
    if (!(state.sourceWidth > 0) || !(state.sourceHeight > 0)) {
        return null;
    }

    const video = host.querySelector('video');
    const overlayRect = overlay.getBoundingClientRect();
    if (overlayRect.width <= 0 || overlayRect.height <= 0) {
        return null;
    }

    // Intrinsic size of whatever is playing (often a downscaled proxy).
    // Used only to find letterboxing inside the <video> element — NOT as
    // the coordinate space for stored boxes.
    let intrinsicW = state.sourceWidth;
    let intrinsicH = state.sourceHeight;
    if (video && video.videoWidth > 0 && video.videoHeight > 0) {
        intrinsicW = video.videoWidth;
        intrinsicH = video.videoHeight;
    }

    const target = video || host;
    const targetRect = target.getBoundingClientRect();
    const boxW = targetRect.width;
    const boxH = targetRect.height;
    if (boxW <= 0 || boxH <= 0) {
        return null;
    }

    // object-fit: contain — picture keeps intrinsic aspect inside the element.
    const fit = Math.min(boxW / intrinsicW, boxH / intrinsicH);
    const contentW = intrinsicW * fit;
    const contentH = intrinsicH * fit;
    const contentLeft = (targetRect.left - overlayRect.left) + (boxW - contentW) / 2;
    const contentTop = (targetRect.top - overlayRect.top) + (boxH - contentH) / 2;

    // Direct CSS ↔ source mapping (proxy & display size cancel out).
    return {
        left: contentLeft,
        top: contentTop,
        width: contentW,
        height: contentH,
        cssPerSourceX: contentW / state.sourceWidth,
        cssPerSourceY: contentH / state.sourceHeight,
    };
}

function sourcePointFromCss(content, state, localX, localY) {
    const x = ((localX - content.left) / content.width) * state.sourceWidth;
    const y = ((localY - content.top) / content.height) * state.sourceHeight;
    return {
        x: clamp(x, 0, state.sourceWidth),
        y: clamp(y, 0, state.sourceHeight),
    };
}

function cssRectFromSourceBox(content, box) {
    return {
        left: content.left + box.x * content.cssPerSourceX,
        top: content.top + box.y * content.cssPerSourceY,
        width: box.width * content.cssPerSourceX,
        height: box.height * content.cssPerSourceY,
    };
}

function normalizeBox(box, sourceW, sourceH) {
    const aspectW = Math.max(1, Number(box.aspect_w) || 16);
    const aspectH = Math.max(1, Number(box.aspect_h) || 9);
    const maxFit = fitAspect(aspectW, aspectH, sourceW, sourceH);
    let width = even(clamp(Number(box.width) || maxFit.width, 2, maxFit.width));
    let height = even(Math.round((width * aspectH) / aspectW));
    if (height > sourceH) {
        height = even(sourceH);
        width = even(Math.round((height * aspectW) / aspectH));
    }
    let x = even(clamp(Number(box.x) || 0, 0, sourceW - width));
    let y = even(clamp(Number(box.y) || 0, 0, sourceH - height));
    return {
        ...box,
        aspect_w: aspectW,
        aspect_h: aspectH,
        x,
        y,
        width: Math.max(2, width),
        height: Math.max(2, height),
        tier: tierForWidth(Math.max(2, width), aspectW, aspectH),
    };
}

function cloneBoxes(boxes) {
    return (boxes || []).map((b) => Object.assign({}, b));
}

function selectedAspect(state) {
    const sel = document.getElementById('image_sequence_framing_aspect');
    if (sel && sel.selectedOptions && sel.selectedOptions[0]) {
        const opt = sel.selectedOptions[0];
        return {
            label: opt.value,
            w: Number(opt.dataset.w) || 16,
            h: Number(opt.dataset.h) || 9,
        };
    }
    const aspects = state.aspects || [];
    const def = aspects.find((a) => a.label === state.defaultAspect) || aspects[0] || {label: '16:9', w: 16, h: 9};
    return def;
}

function makeLocalId() {
    return 'local_' + Date.now() + '_' + Math.floor(Math.random() * 10000);
}

function addCenteredBox(state) {
    const ready = ensureSourceDimensions(state);
    const run = () => {
        if (!state.canEdit || state.sourceWidth <= 0 || state.sourceHeight <= 0) {
            setStatus(
                (state.lang && state.lang.framingNoDims)
                    || 'Source dimensions unknown — framing disabled.',
                true
            );
            return;
        }
        const aspect = selectedAspect(state);
        const fit = fitAspect(aspect.w, aspect.h, state.sourceWidth, state.sourceHeight);
        // Start at ~80% of max fit so the user can still grow it.
        let width = even(Math.floor(fit.width * 0.8));
        let height = even(Math.round((width * aspect.h) / aspect.w));
        width = Math.max(2, width);
        height = Math.max(2, height);
        const x = even(Math.floor((state.sourceWidth - width) / 2));
        const y = even(Math.floor((state.sourceHeight - height) / 2));
        const box = normalizeBox({
            localId: makeLocalId(),
            ref: 0,
            label: aspect.label,
            aspect_w: aspect.w,
            aspect_h: aspect.h,
            aspect_label: aspect.label,
            x,
            y,
            width,
            height,
            dirty: true,
            render_status: '',
            render_message: '',
            alt_file: null,
            alt_url: '',
        }, state.sourceWidth, state.sourceHeight);
        box.dirty = true;
        state.boxes.push(box);
        state.selectedId = box.ref || box.localId;
        renderAll(state);
        setStatus(
            'Framing box added (' + box.width + '×' + box.height
            + ' source px) — drag to adjust, then Save.'
        );
    };

    if (ready && typeof ready.then === 'function') {
        ready.then((ok) => {
            if (ok) {
                run();
            } else {
                setStatus(
                    (state.lang && state.lang.framingNoDims)
                        || 'Source dimensions unknown — framing disabled.',
                    true
                );
            }
        });
        return;
    }
    run();
}

/**
 * Ensure state.sourceWidth/Height are TRUE original pixels from the server.
 * Never fall back to the browser's video.videoWidth (that is the proxy).
 *
 * @returns {JQuery.Promise<boolean>|boolean}
 */
function ensureSourceDimensions(state) {
    if (!state) {
        return false;
    }
    if (state.sourceWidth > 0 && state.sourceHeight > 0) {
        updateSourceDimsLabel(state);
        return true;
    }
    if (!state.framingUrl) {
        return false;
    }
    if (state._dimsFetch) {
        return state._dimsFetch;
    }

    state._dimsFetch = getJson(state.framingUrl, {action: 'list', ajax: 'true'})
        .then((data) => {
            state._dimsFetch = null;
            const w = Number(data && data.source_width) || 0;
            const h = Number(data && data.source_height) || 0;
            if (w > 0 && h > 0) {
                applySourceDimensions(state, w, h);
                return true;
            }
            return false;
        })
        .catch(() => {
            state._dimsFetch = null;
            return false;
        });

    return state._dimsFetch;
}

function applySourceDimensions(state, width, height) {
    const w = Math.floor(Number(width) || 0);
    const h = Math.floor(Number(height) || 0);
    if (w <= 0 || h <= 0) {
        return;
    }
    const prevW = state.sourceWidth;
    const prevH = state.sourceHeight;
    state.sourceWidth = w;
    state.sourceHeight = h;
    updateSourceDimsLabel(state);

    // If we previously had wrong/proxy dims, rescale any in-progress boxes.
    if (prevW > 0 && prevH > 0 && (prevW !== w || prevH !== h)) {
        const sx = w / prevW;
        const sy = h / prevH;
        state.boxes = state.boxes.map((box) => normalizeBox({
            ...box,
            x: Math.round(box.x * sx),
            y: Math.round(box.y * sy),
            width: Math.round(box.width * sx),
            height: Math.round(box.height * sy),
        }, w, h));
    } else {
        state.boxes = state.boxes.map((box) => normalizeBox(box, w, h));
    }

    if (state._wantEdit) {
        state.canEdit = true;
        const overlay = document.getElementById('image_sequence_framing_overlay');
        if (overlay) {
            overlay.classList.remove('is-readonly');
        }
    }
    renderAll(state);
}

function updateSourceDimsLabel(state) {
    const dimsEl = document.getElementById('image_sequence_framing_source_dims');
    if (!dimsEl || !(state.sourceWidth > 0)) {
        return;
    }
    dimsEl.textContent = 'Source ' + state.sourceWidth + ' × ' + state.sourceHeight + ' px';
}

/** @deprecated name kept for call sites — always resolves true source dims */
function tryAdoptVideoDimensions(state) {
    return ensureSourceDimensions(state);
}

function findBox(state, id) {
    return state.boxes.find((b) => String(b.ref || b.localId) === String(id));
}

function renderOverlay(state) {
    const overlay = document.getElementById('image_sequence_framing_overlay');
    if (!overlay) {
        return;
    }
    overlay.innerHTML = '';
    if (!state.visible) {
        overlay.classList.add('is-hidden');
        return;
    }
    overlay.classList.remove('is-hidden');
    overlay.classList.toggle('is-readonly', !state.canEdit);

    const content = videoContentRect(state);
    if (!content) {
        return;
    }

    state.boxes.forEach((box) => {
        const id = box.ref || box.localId;
        const el = document.createElement('div');
        el.className = 'image_sequence_framing_box is-tier-' + (box.tier || 'ok');
        if (String(state.selectedId) === String(id)) {
            el.classList.add('is-selected');
        } else {
            el.classList.add('is-dimmed');
        }
        if (box.dirty) {
            el.classList.add('is-dirty');
        }
        el.dataset.boxId = String(id);

        const rect = cssRectFromSourceBox(content, box);
        el.style.left = rect.left + 'px';
        el.style.top = rect.top + 'px';
        el.style.width = rect.width + 'px';
        el.style.height = rect.height + 'px';

        const label = document.createElement('div');
        label.className = 'image_sequence_framing_box_label';
        label.textContent = (box.label || box.aspect_label || '')
            + ' · ' + box.width + '×' + box.height + ' px';
        el.appendChild(label);

        if (state.canEdit) {
            ['nw', 'ne', 'sw', 'se'].forEach((corner) => {
                const handle = document.createElement('div');
                handle.className = 'image_sequence_framing_handle handle-' + corner;
                handle.dataset.handle = corner;
                el.appendChild(handle);
            });
        }

        overlay.appendChild(el);
    });
}

function renderList(state) {
    const list = document.getElementById('image_sequence_framing_list');
    const empty = document.getElementById('image_sequence_framing_empty');
    if (!list) {
        return;
    }
    list.innerHTML = '';
    if (state.boxes.length === 0) {
        if (empty) {
            empty.hidden = false;
        }
        return;
    }
    if (empty) {
        empty.hidden = true;
    }

    const lang = state.lang || {};
    state.boxes.forEach((box) => {
        const id = box.ref || box.localId;
        const li = document.createElement('li');
        li.className = 'image_sequence_framing_row is-tier-' + (box.tier || 'ok');
        if (String(state.selectedId) === String(id)) {
            li.classList.add('is-selected');
        }
        if (box.dirty) {
            li.classList.add('is-dirty');
        }
        li.dataset.boxId = String(id);

        const head = document.createElement('div');
        head.className = 'image_sequence_framing_row_main';

        if (state.canEdit) {
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'image_sequence_framing_label_input';
            input.value = box.label || '';
            input.placeholder = box.aspect_label || 'Label';
            input.addEventListener('change', () => {
                box.label = input.value.trim();
                box.dirty = true;
                renderAll(state);
            });
            input.addEventListener('click', (e) => e.stopPropagation());
            head.appendChild(input);
        } else {
            const name = document.createElement('strong');
            name.textContent = box.label || box.aspect_label || 'Box';
            head.appendChild(name);
        }

        const meta = document.createElement('span');
        meta.className = 'image_sequence_framing_meta';
        meta.textContent = (box.aspect_label || (box.aspect_w + ':' + box.aspect_h))
            + ' · ' + box.width + '×' + box.height + ' px'
            + (box.dirty ? ' · ' + (lang.framingUnsaved || 'Unsaved') : '');
        head.appendChild(meta);

        const badge = document.createElement('span');
        badge.className = 'image_sequence_framing_tier';
        badge.textContent = tierLabel(box.tier, lang);
        head.appendChild(badge);

        li.appendChild(head);

        const status = document.createElement('div');
        status.className = 'image_sequence_framing_status';
        if (box.render_status) {
            status.textContent = (box.render_message || box.render_status);
            if (box.alt_url && box.render_status === 'ready') {
                const link = document.createElement('a');
                link.href = box.alt_url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = 'Download';
                status.appendChild(document.createTextNode(' · '));
                status.appendChild(link);
            }
        }
        li.appendChild(status);

        if (state.canEdit) {
            const actions = document.createElement('div');
            actions.className = 'image_sequence_framing_actions';

            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'image_sequence_framing_btn';
            saveBtn.textContent = 'Save';
            saveBtn.disabled = !box.dirty && box.ref > 0;
            saveBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                saveBox(state, box);
            });
            actions.appendChild(saveBtn);

            const renderBtn = document.createElement('button');
            renderBtn.type = 'button';
            renderBtn.className = 'image_sequence_framing_btn';
            renderBtn.textContent = 'Render';
            renderBtn.disabled = !(box.ref > 0) || box.dirty
                || box.render_status === 'queued' || box.render_status === 'processing';
            renderBtn.title = box.dirty || !(box.ref > 0)
                ? 'Save the box before rendering'
                : 'Render 4K crop using current in/out';
            renderBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                renderBox(state, box);
            });
            actions.appendChild(renderBtn);

            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'image_sequence_framing_btn is-danger';
            delBtn.textContent = 'Delete';
            delBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                deleteBox(state, box);
            });
            actions.appendChild(delBtn);

            li.appendChild(actions);
        }

        li.addEventListener('click', () => {
            state.selectedId = id;
            renderAll(state);
        });

        list.appendChild(li);
    });
}

function renderAll(state) {
    renderOverlay(state);
    renderList(state);
}

function pointerToSource(state, clientX, clientY) {
    const overlay = document.getElementById('image_sequence_framing_overlay');
    const content = videoContentRect(state);
    if (!overlay || !content) {
        return null;
    }
    const rect = overlay.getBoundingClientRect();
    const localX = clientX - rect.left;
    const localY = clientY - rect.top;
    return sourcePointFromCss(content, state, localX, localY);
}

function wireOverlayInteractions(state) {
    const overlay = document.getElementById('image_sequence_framing_overlay');
    if (!overlay) {
        return;
    }

    let drag = null;

    function onMove(e) {
        if (!drag) {
            return;
        }
        e.preventDefault();
        const pt = pointerToSource(state, e.clientX, e.clientY);
        if (!pt) {
            return;
        }
        const box = drag.box;

        if (drag.mode === 'draw') {
            const aspect = drag.aspect;
            let w = Math.abs(pt.x - drag.originX);
            // Clamp so box stays inside frame while keeping aspect.
            const maxFit = fitAspect(aspect.w, aspect.h, state.sourceWidth, state.sourceHeight);
            w = Math.min(w, maxFit.width);
            let h = Math.round((w * aspect.h) / aspect.w);
            let x = pt.x < drag.originX ? drag.originX - w : drag.originX;
            let y = pt.y < drag.originY ? drag.originY - h : drag.originY;
            x = clamp(x, 0, state.sourceWidth - w);
            y = clamp(y, 0, state.sourceHeight - h);
            Object.assign(box, normalizeBox({
                ...box,
                x,
                y,
                width: Math.max(2, w),
                height: Math.max(2, h),
            }, state.sourceWidth, state.sourceHeight));
            box.dirty = true;
            renderOverlay(state);
            return;
        }

        if (drag.mode === 'move') {
            const nx = drag.startX + (pt.x - drag.originX);
            const ny = drag.startY + (pt.y - drag.originY);
            Object.assign(box, normalizeBox({
                ...box,
                x: nx,
                y: ny,
                width: box.width,
                height: box.height,
            }, state.sourceWidth, state.sourceHeight));
            box.dirty = true;
            renderOverlay(state);
            return;
        }

        if (drag.mode === 'resize') {
            const aspect = {w: box.aspect_w, h: box.aspect_h};
            const handle = drag.handle;
            let width = handle.indexOf('w') >= 0
                ? Math.abs((drag.startX + drag.startW) - pt.x)
                : Math.abs(pt.x - drag.startX);
            const maxFit = fitAspect(aspect.w, aspect.h, state.sourceWidth, state.sourceHeight);
            width = clamp(width, 2, maxFit.width);
            let height = Math.round((width * aspect.h) / aspect.w);

            let x;
            let y;
            if (handle.indexOf('w') >= 0) {
                x = (drag.startX + drag.startW) - width;
            } else {
                x = drag.startX;
            }
            if (handle.indexOf('n') >= 0) {
                y = (drag.startY + drag.startH) - height;
            } else {
                y = drag.startY;
            }

            Object.assign(box, normalizeBox({
                ...box,
                x,
                y,
                width,
                height,
            }, state.sourceWidth, state.sourceHeight));
            box.dirty = true;
            renderOverlay(state);
        }
    }

    function onUp() {
        if (!drag) {
            return;
        }
        const finished = drag;
        drag = null;
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        if (finished.mode === 'draw' && finished.box.width < 16) {
            // Discard tiny accidental clicks.
            state.boxes = state.boxes.filter((b) => b !== finished.box);
        }
        renderAll(state);
    }

    jQuery(document)
        .off('mousedown.imgseqFraming', '#image_sequence_framing_overlay')
        .on('mousedown.imgseqFraming', '#image_sequence_framing_overlay', function (e) {
            if (state.sourceWidth <= 0) {
                ensureSourceDimensions(state);
            }
            if (!state.canEdit || !state.visible || e.button !== 0) {
                return;
            }
            if (state.sourceWidth <= 0 || state.sourceHeight <= 0) {
                setStatus(
                    (state.lang && state.lang.framingNoDims)
                        || 'Source dimensions unknown — framing disabled.',
                    true
                );
                return;
            }
            // Don't start when clicking list inputs etc.
            const target = e.target;
            const handleEl = target.closest ? target.closest('.image_sequence_framing_handle') : null;
            const boxEl = target.closest ? target.closest('.image_sequence_framing_box') : null;
            const pt = pointerToSource(state, e.clientX, e.clientY);
            if (!pt) {
                return;
            }

            if (handleEl && boxEl) {
                e.preventDefault();
                e.stopPropagation();
                const box = findBox(state, boxEl.dataset.boxId);
                if (!box) {
                    return;
                }
                state.selectedId = box.ref || box.localId;
                drag = {
                    mode: 'resize',
                    handle: handleEl.dataset.handle,
                    box,
                    originX: pt.x,
                    originY: pt.y,
                    startX: box.x,
                    startY: box.y,
                    startW: box.width,
                    startH: box.height,
                };
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
                renderAll(state);
                return;
            }

            if (boxEl) {
                e.preventDefault();
                e.stopPropagation();
                const box = findBox(state, boxEl.dataset.boxId);
                if (!box) {
                    return;
                }
                state.selectedId = box.ref || box.localId;
                drag = {
                    mode: 'move',
                    box,
                    originX: pt.x,
                    originY: pt.y,
                    startX: box.x,
                    startY: box.y,
                };
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
                renderAll(state);
                return;
            }

            // Draw new box on empty overlay area.
            e.preventDefault();
            const aspect = selectedAspect(state);
            const box = normalizeBox({
                localId: makeLocalId(),
                ref: 0,
                label: aspect.label,
                aspect_w: aspect.w,
                aspect_h: aspect.h,
                aspect_label: aspect.label,
                x: pt.x,
                y: pt.y,
                width: 2,
                height: 2,
                dirty: true,
                render_status: '',
                render_message: '',
                alt_file: null,
                alt_url: '',
            }, state.sourceWidth, state.sourceHeight);
            box.dirty = true;
            state.boxes.push(box);
            state.selectedId = box.localId;
            drag = {
                mode: 'draw',
                box,
                aspect,
                originX: pt.x,
                originY: pt.y,
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            renderAll(state);
        });
}

function saveBox(state, box) {
    if (!state.canEdit || state.busy) {
        return;
    }
    state.busy = true;
    setStatus('Saving framing box…');
    postJson(state.framingUrl, {
        action: 'save',
        box_ref: box.ref || 0,
        label: box.label || '',
        aspect_w: box.aspect_w,
        aspect_h: box.aspect_h,
        x: box.x,
        y: box.y,
        width: box.width,
        height: box.height,
        source_width: state.sourceWidth,
        source_height: state.sourceHeight,
    }, state.csrfFraming)
        .done((data) => {
            if (data && data.ok && data.box) {
                const idx = state.boxes.indexOf(box);
                const saved = Object.assign({}, data.box, {dirty: false, localId: undefined});
                if (idx >= 0) {
                    state.boxes[idx] = saved;
                }
                state.selectedId = saved.ref;
                setStatus(data.message || (state.lang.framingSaved || 'Framing box saved.'));
            } else {
                setStatus(
                    (data && data.message) || (state.lang.framingSaveFailed || 'Could not save framing box.'),
                    true
                );
            }
        })
        .fail(() => {
            setStatus(state.lang.framingSaveFailed || 'Could not save framing box.', true);
        })
        .always(() => {
            state.busy = false;
            renderAll(state);
        });
}

function deleteBox(state, box) {
    if (!state.canEdit || state.busy) {
        return;
    }
    if (!window.confirm('Delete this framing box?')) {
        return;
    }
    if (!(box.ref > 0)) {
        state.boxes = state.boxes.filter((b) => b !== box);
        if (String(state.selectedId) === String(box.localId)) {
            state.selectedId = state.boxes[0] ? (state.boxes[0].ref || state.boxes[0].localId) : null;
        }
        renderAll(state);
        return;
    }
    state.busy = true;
    postJson(state.framingUrl, {
        action: 'delete',
        box_ref: box.ref,
    }, state.csrfFraming)
        .done((data) => {
            if (data && data.ok) {
                state.boxes = state.boxes.filter((b) => b.ref !== box.ref);
                state.selectedId = state.boxes[0] ? (state.boxes[0].ref || state.boxes[0].localId) : null;
                setStatus(data.message || (state.lang.framingDeleted || 'Framing box deleted.'));
            } else {
                setStatus(
                    (data && data.message) || (state.lang.framingDeleteFailed || 'Could not delete framing box.'),
                    true
                );
            }
        })
        .fail(() => {
            setStatus(state.lang.framingDeleteFailed || 'Could not delete framing box.', true);
        })
        .always(() => {
            state.busy = false;
            renderAll(state);
        });
}

function renderBox(state, box) {
    if (!state.canEdit || state.busy || !(box.ref > 0) || box.dirty) {
        return;
    }
    state.busy = true;
    setStatus(state.lang.framingRenderQueued || 'Render queued…');
    postJson(state.framingUrl, {
        action: 'render',
        box_ref: box.ref,
    }, state.csrfFraming)
        .done((data) => {
            if (data && data.ok && data.box) {
                const idx = state.boxes.findIndex((b) => b.ref === box.ref);
                if (idx >= 0) {
                    state.boxes[idx] = Object.assign({}, data.box, {dirty: false});
                }
                setStatus(data.message || (state.lang.framingRenderQueued || 'Render queued…'));
                startPolling(state);
            } else {
                setStatus(
                    (data && data.message) || (state.lang.framingRenderFailed || 'Framing render failed.'),
                    true
                );
            }
        })
        .fail(() => {
            setStatus(state.lang.framingRenderFailed || 'Framing render failed.', true);
        })
        .always(() => {
            state.busy = false;
            renderAll(state);
        });
}

function startPolling(state) {
    stopPolling();
    const needsPoll = state.boxes.some(
        (b) => b.render_status === 'queued' || b.render_status === 'processing'
    );
    if (!needsPoll) {
        return;
    }
    pollTimer = setInterval(() => {
        getJson(state.framingUrl, {action: 'list', ajax: 'true'})
            .done((data) => {
                if (!data || !data.ok || !Array.isArray(data.boxes)) {
                    return;
                }
                // Preserve unsaved local boxes; refresh saved ones from server.
                const dirtyLocals = state.boxes.filter((b) => !(b.ref > 0) || b.dirty);
                const serverBoxes = data.boxes.map((b) => Object.assign({}, b, {dirty: false}));
                // Keep dirty edits for matching refs.
                const merged = serverBoxes.map((sb) => {
                    const dirty = dirtyLocals.find((d) => d.ref === sb.ref && d.dirty);
                    return dirty || sb;
                });
                dirtyLocals.forEach((d) => {
                    if (!(d.ref > 0)) {
                        merged.push(d);
                    }
                });
                state.boxes = merged.map((b) => normalizeBox(b, state.sourceWidth, state.sourceHeight));
                renderAll(state);
                const still = state.boxes.some(
                    (b) => b.render_status === 'queued' || b.render_status === 'processing'
                );
                if (!still) {
                    stopPolling();
                    const ready = state.boxes.find((b) => b.render_status === 'ready');
                    if (ready) {
                        setStatus(ready.render_message || 'Render ready.');
                    }
                }
            });
    }, 4000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function wireToolbar(state) {
    jQuery(document)
        .off('click.imgseqFraming', '#image_sequence_framing_add')
        .on('click.imgseqFraming', '#image_sequence_framing_add', function (e) {
            e.preventDefault();
            addCenteredBox(state);
        });

    jQuery(document)
        .off('click.imgseqFraming', '#image_sequence_framing_toggle')
        .on('click.imgseqFraming', '#image_sequence_framing_toggle', function (e) {
            e.preventDefault();
            state.visible = !state.visible;
            const btn = document.getElementById('image_sequence_framing_toggle');
            if (btn) {
                btn.setAttribute('aria-pressed', state.visible ? 'true' : 'false');
                btn.classList.toggle('is-active', state.visible);
            }
            const panel = document.getElementById('image_sequence_framing_panel');
            if (panel) {
                panel.hidden = !state.visible;
            }
            renderAll(state);
        });
}

function observeResize(state) {
    if (resizeObserver) {
        resizeObserver.disconnect();
        resizeObserver = null;
    }
    const stage = document.querySelector('.image_sequence_player_stage');
    if (!stage || typeof ResizeObserver === 'undefined') {
        window.addEventListener('resize', state._onResize);
        return;
    }
    resizeObserver = new ResizeObserver(() => renderOverlay(state));
    resizeObserver.observe(stage);
}

export function initFramingBoxes(config) {
    destroyFramingBoxes();

    if (!config || !config.playerElementId) {
        return;
    }

    const sourceWidth = Number(config.sourceWidth) || 0;
    const sourceHeight = Number(config.sourceHeight) || 0;
    const wantEdit = !!config.canEdit;

    const state = {
        playerElementId: config.playerElementId,
        framingUrl: config.framingUrl,
        csrfFraming: config.csrfFraming || {},
        canEdit: wantEdit && sourceWidth > 0 && sourceHeight > 0,
        _wantEdit: wantEdit,
        sourceWidth,
        sourceHeight,
        aspects: config.aspects || [],
        defaultAspect: config.defaultAspect || '16:9',
        lang: config.lang || {},
        boxes: cloneBoxes(config.framingBoxes || []).map((b) =>
            normalizeBox(
                Object.assign({}, b, {dirty: false}),
                sourceWidth || b.source_width || 1,
                sourceHeight || b.source_height || 1
            )
        ),
        selectedId: null,
        visible: true,
        busy: false,
        _onResize: null,
        _onFs: null,
    };

    if (state.boxes.length > 0) {
        state.selectedId = state.boxes[0].ref || state.boxes[0].localId;
    }

    framingState = state;
    state._onResize = () => renderOverlay(state);
    state._onFs = () => setTimeout(() => renderOverlay(state), 50);

    wireToolbar(state);
    wireOverlayInteractions(state);
    observeResize(state);
    document.addEventListener('fullscreenchange', state._onFs);
    document.addEventListener('webkitfullscreenchange', state._onFs);

    const btn = document.getElementById('image_sequence_framing_toggle');
    if (btn) {
        btn.setAttribute('aria-pressed', 'true');
        btn.classList.add('is-active');
    }
    const panel = document.getElementById('image_sequence_framing_panel');
    if (panel) {
        panel.hidden = false;
    }

    function finishInit() {
        const ready = ensureSourceDimensions(state);
        const after = () => {
            updateSourceDimsLabel(state);
            if (wantEdit && state.sourceWidth <= 0) {
                setStatus(
                    (config.lang && config.lang.framingNoDims)
                        || 'Source dimensions unknown — framing disabled.',
                    true
                );
            }
            renderAll(state);
        };
        if (ready && typeof ready.then === 'function') {
            ready.then(after);
        } else {
            after();
        }
    }

    // Wait for Omakase chroming so the overlay can map onto the video element.
    let tries = 0;
    const boot = setInterval(() => {
        tries++;
        const host = document.getElementById(state.playerElementId);
        const video = host ? host.querySelector('video') : null;
        if ((video && video.videoWidth > 0) || state.sourceWidth > 0 || tries > 40) {
            clearInterval(boot);
            finishInit();
        }
    }, 150);

    startPolling(state);
}

export function destroyFramingBoxes() {
    stopPolling();
    if (typeof jQuery !== 'undefined') {
        jQuery(document).off('.imgseqFraming');
    }
    if (resizeObserver) {
        resizeObserver.disconnect();
        resizeObserver = null;
    }
    if (framingState) {
        if (framingState._onResize) {
            window.removeEventListener('resize', framingState._onResize);
        }
        if (framingState._onFs) {
            document.removeEventListener('fullscreenchange', framingState._onFs);
            document.removeEventListener('webkitfullscreenchange', framingState._onFs);
        }
    }
    framingState = null;
    const overlay = document.getElementById('image_sequence_framing_overlay');
    if (overlay) {
        overlay.innerHTML = '';
    }
}
