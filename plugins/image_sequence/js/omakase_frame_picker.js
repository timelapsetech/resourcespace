/**
 * Frame-accurate Image Sequence preview using Omakase Player.
 * Expects window.ImageSequenceOmakaseConfig set by the view hook.
 */
import {
    ChromingTheme,
    MediaTemporalFormat,
    OmakasePlayer,
    PlayerEventType,
} from '@byomakase/omakase-player';

let activePlayer = null;
let activeSubs = [];
let boundConfigKey = null;

function destroyActivePlayer() {
    activeSubs.forEach((sub) => {
        try {
            if (sub && typeof sub.unsubscribe === 'function') {
                sub.unsubscribe();
            }
        } catch (e) {
            /* ignore */
        }
    });
    activeSubs = [];
    if (typeof jQuery !== 'undefined') {
        jQuery(document).off('.imgseqFrameNav');
        jQuery(document).off('.imgseqOmakase');
        jQuery(document).off('.imgseqNle');
        jQuery(document).off('.imgseqTimeline');
    }
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
    if (activePlayer) {
        try {
            activePlayer.destroy();
        } catch (e) {
            /* ignore */
        }
        activePlayer = null;
    }
    boundConfigKey = null;
}

function clampFrame(frame, frameCount) {
    let f = Math.floor(Number(frame) || 0);
    if (f < 0) {
        f = 0;
    }
    if (frameCount > 0 && f >= frameCount) {
        f = frameCount - 1;
    }
    return f;
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

function currentFrame(player, frameCount) {
    try {
        const frame = player.getCurrentTime(MediaTemporalFormat.FRAME_COUNT);
        return clampFrame(frame, frameCount);
    } catch (e) {
        return 0;
    }
}

function setStatus(text, isError) {
    const el = jQuery('#image_sequence_frame_status');
    el.text(text || '');
    el.toggleClass('image_sequence_status_error', !!isError);
}

function updateCurrentLabel(frame) {
    jQuery('#image_sequence_current_frame').text(String(frame));
    jQuery('#image_sequence_overlay_frame').text(String(frame));
}

const FRAME_OVERLAY_STORAGE_KEY = 'image_sequence_frame_overlay';

function isFrameOverlayEnabled() {
    try {
        return window.localStorage.getItem(FRAME_OVERLAY_STORAGE_KEY) === '1';
    } catch (e) {
        return false;
    }
}

function setFrameOverlayEnabled(enabled) {
    const overlay = document.getElementById('image_sequence_frame_overlay');
    const btn = document.getElementById('image_sequence_frame_overlay_toggle');
    if (overlay) {
        overlay.hidden = !enabled;
        overlay.setAttribute('aria-hidden', enabled ? 'false' : 'true');
    }
    if (btn) {
        btn.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        btn.classList.toggle('is-active', !!enabled);
    }
    try {
        window.localStorage.setItem(FRAME_OVERLAY_STORAGE_KEY, enabled ? '1' : '0');
    } catch (e) {
        /* ignore */
    }
}

function toggleFrameOverlay() {
    setFrameOverlayEnabled(!isFrameOverlayEnabled());
}

function timelineMaxFrame(frameCount) {
    return Math.max(0, (Number(frameCount) || 0) - 1);
}

function frameToPercent(frame, frameCount) {
    const max = timelineMaxFrame(frameCount);
    if (max <= 0) {
        return 0;
    }
    return (clampFrame(frame, frameCount) / max) * 100;
}

function updateTimelinePlayhead(frame, frameCount) {
    const playhead = document.getElementById('image_sequence_timeline_playhead');
    const timeline = document.getElementById('image_sequence_timeline');
    if (!playhead) {
        return;
    }
    playhead.style.left = frameToPercent(frame, frameCount) + '%';
    if (timeline) {
        timeline.setAttribute('aria-valuenow', String(clampFrame(frame, frameCount)));
    }
}

function updateTimelineMarks(inFrame, outFrame, repFrame, frameCount) {
    const timeline = document.getElementById('image_sequence_timeline');
    const range = document.getElementById('image_sequence_timeline_range');
    const inMark = document.getElementById('image_sequence_timeline_in');
    const outMark = document.getElementById('image_sequence_timeline_out');
    const repMark = document.getElementById('image_sequence_timeline_rep');
    if (!range || !inMark || !outMark) {
        return;
    }

    const inPct = frameToPercent(inFrame, frameCount);
    const outPct = frameToPercent(outFrame, frameCount);
    const left = Math.min(inPct, outPct);
    const width = Math.abs(outPct - inPct);

    range.style.left = left + '%';
    range.style.width = width + '%';
    inMark.style.left = inPct + '%';
    outMark.style.left = outPct + '%';

    if (repMark) {
        repMark.style.left = frameToPercent(repFrame, frameCount) + '%';
    }

    if (timeline) {
        timeline.dataset.inFrame = String(clampFrame(inFrame, frameCount));
        timeline.dataset.outFrame = String(clampFrame(outFrame, frameCount));
        timeline.dataset.repFrame = String(clampFrame(repFrame, frameCount));
        timeline.dataset.frameCount = String(frameCount || 0);
    }
}

function frameFromTimelinePointer(event, frameCount) {
    const track = document.querySelector('#image_sequence_timeline .nle-timeline-track');
    if (!track) {
        return 0;
    }
    const rect = track.getBoundingClientRect();
    if (rect.width <= 0) {
        return 0;
    }
    const x = Math.min(Math.max(event.clientX - rect.left, 0), rect.width);
    const max = timelineMaxFrame(frameCount);
    return clampFrame(Math.round((x / rect.width) * max), frameCount);
}

function seekTimelineToFrame(player, frameCount, frame, onDone) {
    const next = clampFrame(frame, frameCount);
    updateTimelinePlayhead(next, frameCount);
    updateCurrentLabel(next);
    player.pause().subscribe({
        next: () => {
            player.seekTo(next, MediaTemporalFormat.FRAME_COUNT).subscribe({
                next: () => {
                    if (typeof onDone === 'function') {
                        onDone(next);
                    }
                },
            });
        },
    });
}

function wireTimeline(player, config, getMarks) {
    const frameCount = config.frameCount || 0;
    const timeline = document.getElementById('image_sequence_timeline');
    if (!timeline) {
        return;
    }

    let dragging = false;

    function marks() {
        if (typeof getMarks === 'function') {
            return getMarks();
        }
        return {
            inFrame: Number(timeline.dataset.inFrame || 0),
            outFrame: Number(timeline.dataset.outFrame || Math.max(0, frameCount - 1)),
            repFrame: Number(timeline.dataset.repFrame || 0),
        };
    }

    function refreshMarks() {
        const m = marks();
        updateTimelineMarks(m.inFrame, m.outFrame, m.repFrame, frameCount);
    }

    function seekFromEvent(event) {
        seekTimelineToFrame(player, frameCount, frameFromTimelinePointer(event, frameCount));
    }

    refreshMarks();
    updateTimelinePlayhead(0, frameCount);

    jQuery(document)
        .off('mousedown.imgseqTimeline', '#image_sequence_timeline')
        .on('mousedown.imgseqTimeline', '#image_sequence_timeline', function (e) {
            if (e.button !== 0) {
                return;
            }
            e.preventDefault();
            dragging = true;
            seekFromEvent(e);
        });

    jQuery(document)
        .off('mousemove.imgseqTimeline')
        .on('mousemove.imgseqTimeline', function (e) {
            if (!dragging) {
                return;
            }
            e.preventDefault();
            seekFromEvent(e);
        });

    jQuery(document)
        .off('mouseup.imgseqTimeline')
        .on('mouseup.imgseqTimeline', function () {
            dragging = false;
        });

    jQuery(document)
        .off('keydown.imgseqTimeline', '#image_sequence_timeline')
        .on('keydown.imgseqTimeline', '#image_sequence_timeline', function (e) {
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                e.preventDefault();
                e.stopPropagation();
                const delta = e.key === 'ArrowLeft' ? (e.shiftKey ? -10 : -1) : (e.shiftKey ? 10 : 1);
                seekTimelineToFrame(
                    player,
                    frameCount,
                    currentFrame(player, frameCount) + delta
                );
            }
        });

    // Expose for mark updates from wireControls.
    timeline._imgseqRefreshMarks = refreshMarks;
}

function stepFrames(player, frameCount, delta, onDone) {
    player.pause().subscribe({
        next: () => {
            const next = clampFrame(currentFrame(player, frameCount) + delta, frameCount);
            player.seekTo(next, MediaTemporalFormat.FRAME_COUNT).subscribe({
                next: () => {
                    if (typeof onDone === 'function') {
                        onDone(next);
                    }
                },
            });
        },
    });
}

function setPlayToggleUi(isPlaying) {
    const btn = document.getElementById('image_sequence_play_toggle');
    if (!btn) {
        return;
    }
    btn.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
    const playIcon = btn.querySelector('.nle-icon-play');
    const pauseIcon = btn.querySelector('.nle-icon-pause');
    if (playIcon) {
        playIcon.hidden = !!isPlaying;
    }
    if (pauseIcon) {
        pauseIcon.hidden = !isPlaying;
    }
}

function getFullscreenElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || null;
}

function getPlayerWrap() {
    return document.querySelector('#previewimagewrapper.image_sequence_omakase_wrap');
}

function setFullscreenUi(isFullscreen) {
    const btn = document.getElementById('image_sequence_fullscreen');
    if (!btn) {
        return;
    }
    btn.setAttribute('aria-pressed', isFullscreen ? 'true' : 'false');
    const enterIcon = btn.querySelector('.nle-icon-fs-enter');
    const exitIcon = btn.querySelector('.nle-icon-fs-exit');
    if (enterIcon) {
        enterIcon.hidden = !!isFullscreen;
    }
    if (exitIcon) {
        exitIcon.hidden = !isFullscreen;
    }
}

function onFullscreenChange() {
    const wrap = getPlayerWrap();
    setFullscreenUi(!!wrap && getFullscreenElement() === wrap);
}

function togglePlayerFullscreen() {
    const wrap = getPlayerWrap();
    if (!wrap) {
        return;
    }
    if (getFullscreenElement() === wrap) {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        }
        return;
    }
    if (wrap.requestFullscreen) {
        wrap.requestFullscreen();
    } else if (wrap.webkitRequestFullscreen) {
        wrap.webkitRequestFullscreen();
    }
}

function flashButton(selector) {
    const el = document.querySelector(selector);
    if (!el) {
        return;
    }
    el.classList.add('is-active');
    setTimeout(() => el.classList.remove('is-active'), 220);
}

function isTypingTarget(target) {
    const tag = (target && target.tagName) ? target.tagName.toLowerCase() : '';
    return tag === 'input' || tag === 'textarea' || tag === 'select' || !!(target && target.isContentEditable);
}

function wireFrameNavigation(player, config) {
    const frameCount = config.frameCount || 0;
    let isPlaying = false;

    function refreshFromPlayer() {
        const frame = currentFrame(player, frameCount);
        updateCurrentLabel(frame);
        updateTimelinePlayhead(frame, frameCount);
    }

    function step(delta) {
        stepFrames(player, frameCount, delta, refreshFromPlayer);
    }

    function togglePlay() {
        if (isPlaying) {
            player.pause().subscribe({
                next: () => {
                    isPlaying = false;
                    setPlayToggleUi(false);
                    refreshFromPlayer();
                },
            });
            return;
        }
        player.play().subscribe({
            next: () => {
                isPlaying = true;
                setPlayToggleUi(true);
            },
        });
    }

    jQuery(document)
        .off('click.imgseqNle', '#image_sequence_frame_back')
        .on('click.imgseqNle', '#image_sequence_frame_back', function (e) {
            e.preventDefault();
            step(-1);
        });

    jQuery(document)
        .off('click.imgseqNle', '#image_sequence_frame_forward')
        .on('click.imgseqNle', '#image_sequence_frame_forward', function (e) {
            e.preventDefault();
            step(1);
        });

    jQuery(document)
        .off('click.imgseqNle', '#image_sequence_frame_back_10')
        .on('click.imgseqNle', '#image_sequence_frame_back_10', function (e) {
            e.preventDefault();
            step(-10);
        });

    jQuery(document)
        .off('click.imgseqNle', '#image_sequence_frame_forward_10')
        .on('click.imgseqNle', '#image_sequence_frame_forward_10', function (e) {
            e.preventDefault();
            step(10);
        });

    jQuery(document)
        .off('click.imgseqNle', '#image_sequence_play_toggle')
        .on('click.imgseqNle', '#image_sequence_play_toggle', function (e) {
            e.preventDefault();
            togglePlay();
        });

    jQuery(document)
        .off('click.imgseqNle', '#image_sequence_fullscreen')
        .on('click.imgseqNle', '#image_sequence_fullscreen', function (e) {
            e.preventDefault();
            togglePlayerFullscreen();
        });

    jQuery(document)
        .off('click.imgseqNle', '#image_sequence_frame_overlay_toggle')
        .on('click.imgseqNle', '#image_sequence_frame_overlay_toggle', function (e) {
            e.preventDefault();
            toggleFrameOverlay();
        });

    setFrameOverlayEnabled(isFrameOverlayEnabled());

    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);
    onFullscreenChange();

    jQuery(document)
        .off('keydown.imgseqNle')
        .on('keydown.imgseqNle', function (e) {
            if (!document.getElementById(config.playerElementId)) {
                return;
            }
            if (isTypingTarget(e.target)) {
                return;
            }

            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                step(e.shiftKey ? -10 : -1);
                return;
            }
            if (e.key === 'ArrowRight') {
                e.preventDefault();
                step(e.shiftKey ? 10 : 1);
                return;
            }
            if (e.key === ' ' || e.code === 'Space') {
                e.preventDefault();
                togglePlay();
                return;
            }
            if ((e.key === 'c' || e.key === 'C') && !e.metaKey && !e.ctrlKey && !e.altKey) {
                e.preventDefault();
                toggleFrameOverlay();
                return;
            }
            if ((e.key === 'f' || e.key === 'F') && !e.metaKey && !e.ctrlKey && !e.altKey) {
                e.preventDefault();
                togglePlayerFullscreen();
                return;
            }

            // Mark / jump shortcuts only when edit controls are present.
            if (!config.canEdit) {
                return;
            }
            const key = (e.key || '').toLowerCase();
            if (key === 'i') {
                e.preventDefault();
                if (e.shiftKey) {
                    jQuery('#image_sequence_goto_in').trigger('click');
                } else {
                    jQuery('#image_sequence_mark_in').trigger('click');
                }
                return;
            }
            if (key === 'o') {
                e.preventDefault();
                if (e.shiftKey) {
                    jQuery('#image_sequence_goto_out').trigger('click');
                } else {
                    jQuery('#image_sequence_mark_out').trigger('click');
                }
            }
        });

    activeSubs.push(
        player.onEvent$.subscribe({
            next: (event) => {
                if (event.type === PlayerEventType.PLAYER_PLAY) {
                    isPlaying = true;
                    setPlayToggleUi(true);
                }
                if (
                    event.type === PlayerEventType.PLAYER_PAUSE
                    || event.type === PlayerEventType.PLAYER_ENDED
                ) {
                    isPlaying = false;
                    setPlayToggleUi(false);
                }
                if (
                    event.type === PlayerEventType.PLAYER_PLAYBACK_PROGRESS
                    || event.type === PlayerEventType.PLAYER_SEEKED
                    || event.type === PlayerEventType.PLAYER_PAUSE
                ) {
                    refreshFromPlayer();
                }
            },
        })
    );
    setPlayToggleUi(false);
    refreshFromPlayer();
}

function wireControls(player, config) {
    const frameCount = config.frameCount || 0;
    let pendingIn = clampFrame(config.inFrame, frameCount);
    let pendingOut = config.outFrame == null
        ? (frameCount > 0 ? frameCount - 1 : 0)
        : clampFrame(config.outFrame, frameCount);
    let pendingRep = clampFrame(config.repFrame, frameCount);
    let saving = false;

    jQuery('#image_sequence_saved_in_frame').text(String(pendingIn));
    jQuery('#image_sequence_saved_out_frame').text(String(pendingOut));
    jQuery('#image_sequence_saved_rep_frame').text(String(pendingRep));

    function refreshMarksUi() {
        updateTimelineMarks(pendingIn, pendingOut, pendingRep, frameCount);
    }

    function refreshFromPlayer() {
        const frame = currentFrame(player, frameCount);
        updateCurrentLabel(frame);
        updateTimelinePlayhead(frame, frameCount);
    }

    refreshMarksUi();

    jQuery(document)
        .off('click.imgseqOmakase', '#image_sequence_mark_in')
        .on('click.imgseqOmakase', '#image_sequence_mark_in', function (e) {
            e.preventDefault();
            pendingIn = currentFrame(player, frameCount);
            if (pendingOut < pendingIn) {
                pendingOut = pendingIn;
            }
            jQuery('#image_sequence_saved_in_frame').text(String(pendingIn));
            refreshMarksUi();
            flashButton('#image_sequence_mark_in');
            setStatus(config.lang.markedIn || 'In point marked (not saved yet).');
        });

    jQuery(document)
        .off('click.imgseqOmakase', '#image_sequence_mark_out')
        .on('click.imgseqOmakase', '#image_sequence_mark_out', function (e) {
            e.preventDefault();
            pendingOut = currentFrame(player, frameCount);
            if (pendingOut < pendingIn) {
                pendingIn = pendingOut;
            }
            jQuery('#image_sequence_saved_out_frame').text(String(pendingOut));
            refreshMarksUi();
            flashButton('#image_sequence_mark_out');
            setStatus(config.lang.markedOut || 'Out point marked (not saved yet).');
        });

    jQuery(document)
        .off('click.imgseqOmakase', '#image_sequence_goto_in')
        .on('click.imgseqOmakase', '#image_sequence_goto_in', function (e) {
            e.preventDefault();
            player.pause().subscribe({
                next: () => {
                    player.seekTo(pendingIn, MediaTemporalFormat.FRAME_COUNT).subscribe({
                        next: () => refreshFromPlayer(),
                    });
                },
            });
        });

    jQuery(document)
        .off('click.imgseqOmakase', '#image_sequence_goto_out')
        .on('click.imgseqOmakase', '#image_sequence_goto_out', function (e) {
            e.preventDefault();
            player.pause().subscribe({
                next: () => {
                    player.seekTo(pendingOut, MediaTemporalFormat.FRAME_COUNT).subscribe({
                        next: () => refreshFromPlayer(),
                    });
                },
            });
        });

    jQuery(document)
        .off('click.imgseqOmakase', '#image_sequence_save_inout')
        .on('click.imgseqOmakase', '#image_sequence_save_inout', function (e) {
            e.preventDefault();
            if (saving || !config.canEdit) {
                return;
            }
            saving = true;
            const btn = jQuery(this);
            btn.prop('disabled', true);
            setStatus(config.lang.savingInOut || 'Saving in/out points…');

            postJson(
                config.inoutUrl,
                {in_frame: pendingIn, out_frame: pendingOut},
                config.csrfInout
            )
                .done(function (data) {
                    if (data && data.ok) {
                        pendingIn = clampFrame(data.in_frame, frameCount);
                        pendingOut = clampFrame(data.out_frame, frameCount);
                        jQuery('#image_sequence_saved_in_frame').text(String(pendingIn));
                        jQuery('#image_sequence_saved_out_frame').text(String(pendingOut));
                        refreshMarksUi();
                        setStatus(data.message || config.lang.inoutSet || 'In/out points updated.');
                    } else {
                        setStatus(
                            (data && data.message) || config.lang.inoutFailed || 'Could not set in/out points.',
                            true
                        );
                    }
                })
                .fail(function () {
                    setStatus(config.lang.inoutFailed || 'Could not set in/out points.', true);
                })
                .always(function () {
                    saving = false;
                    btn.prop('disabled', false);
                });
        });

    jQuery(document)
        .off('click.imgseqOmakase', '#image_sequence_set_rep_frame')
        .on('click.imgseqOmakase', '#image_sequence_set_rep_frame', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (saving || !config.canEdit) {
                return;
            }
            const frame = currentFrame(player, frameCount);
            saving = true;
            const btn = jQuery(this);
            btn.prop('disabled', true);
            setStatus(config.lang.savingRep || 'Saving representative frame…');

            postJson(config.repUrl, {frame: frame}, config.csrfRep)
                .done(function (data) {
                    if (data && data.ok) {
                        pendingRep = clampFrame(data.frame, frameCount);
                        jQuery('#image_sequence_saved_rep_frame').text(String(pendingRep));
                        refreshMarksUi();
                        setStatus(data.message || config.lang.repSet || 'Representative frame updated.');
                        if (typeof CentralSpaceLoad === 'function' && config.viewUrl) {
                            CentralSpaceLoad(config.viewUrl, true);
                        }
                    } else {
                        setStatus(
                            (data && data.message) || config.lang.repFailed || 'Could not set representative frame.',
                            true
                        );
                        saving = false;
                        btn.prop('disabled', false);
                    }
                })
                .fail(function () {
                    setStatus(config.lang.repFailed || 'Could not set representative frame.', true);
                    saving = false;
                    btn.prop('disabled', false);
                });
        });
}

export function initImageSequenceOmakase(config) {
    if (!config || !config.playerElementId || !config.videoUrl) {
        return;
    }

    const host = document.getElementById(config.playerElementId);
    if (!host) {
        return;
    }

    const key = config.playerElementId + '|' + config.videoUrl + '|' + String(config.ref || '');
    if (boundConfigKey === key && activePlayer && host.childNodes.length > 0) {
        return;
    }

    destroyActivePlayer();

    // Clear previous chroming DOM if CentralSpace re-injected the wrapper incompletely.
    host.innerHTML = '';

    const player = new OmakasePlayer({
        playerHtmlElementId: config.playerElementId,
        // No overlay transport — our NLE bar below (and in fullscreen) owns controls.
        chromingTheme: ChromingTheme.CHROMELESS,
    });

    activePlayer = player;
    boundConfigKey = key;
    window.imageSequenceOmakasePlayer = player;

    const loadOptions = {
        frameRate: config.fps || 30,
    };
    if (config.posterUrl) {
        loadOptions.poster = config.posterUrl;
    }

    activeSubs.push(
        player.loadMainMedia(config.videoUrl, loadOptions).subscribe({
            next: () => {
                wireFrameNavigation(player.player, config);
                wireTimeline(player.player, config, function () {
                    const timeline = document.getElementById('image_sequence_timeline');
                    return {
                        inFrame: Number((timeline && timeline.dataset.inFrame) || config.inFrame || 0),
                        outFrame: Number(
                            (timeline && timeline.dataset.outFrame)
                            || config.outFrame
                            || Math.max(0, (config.frameCount || 1) - 1)
                        ),
                        repFrame: Number((timeline && timeline.dataset.repFrame) || config.repFrame || 0),
                    };
                });
                if (config.canEdit) {
                    wireControls(player.player, config);
                } else {
                    updateTimelineMarks(
                        config.inFrame || 0,
                        config.outFrame == null ? Math.max(0, (config.frameCount || 1) - 1) : config.outFrame,
                        config.repFrame || 0,
                        config.frameCount || 0
                    );
                }
                // Start at the in point (frame 0 when none is set), not the representative frame.
                const startFrame = clampFrame(config.inFrame, config.frameCount || 0);
                if (startFrame > 0) {
                    player.player.seekTo(startFrame, MediaTemporalFormat.FRAME_COUNT).subscribe({
                        next: () => updateTimelinePlayhead(startFrame, config.frameCount || 0),
                    });
                } else {
                    updateTimelinePlayhead(0, config.frameCount || 0);
                }
            },
            error: (err) => {
                console.error('Image Sequence Omakase load failed', err);
                setStatus((config.lang && config.lang.loadFailed) || 'Could not load sequence preview player.', true);
            },
        })
    );
}

export function destroyImageSequenceOmakase() {
    destroyActivePlayer();
}

window.ImageSequenceOmakase = {
    init: initImageSequenceOmakase,
    destroy: destroyImageSequenceOmakase,
};

if (typeof jQuery !== 'undefined') {
    jQuery(document)
        .off('CentralSpaceLoaded.imgseqOmakase')
        .on('CentralSpaceLoaded.imgseqOmakase', function () {
            if (window.ImageSequenceOmakaseConfig && document.getElementById(window.ImageSequenceOmakaseConfig.playerElementId)) {
                initImageSequenceOmakase(window.ImageSequenceOmakaseConfig);
            } else if (!document.querySelector('.image_sequence_omakase_player')) {
                destroyImageSequenceOmakase();
            }
        });
}
