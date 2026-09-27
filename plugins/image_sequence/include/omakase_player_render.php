<?php

include_once __DIR__ . '/omakase_polyfill.php';

/**
 * Shared Omakase NLE chrome for Image Sequence + Video resources.
 *
 * @param array{
 *   ref: int,
 *   fps: float,
 *   frameCount: int,
 *   repFrame: int,
 *   inFrame: int,
 *   outFrame: int,
 *   videoUrl: string,
 *   posterUrl: string,
 *   canEdit: bool,
 *   mode?: string,
 *   aspectRatioCss?: string,
 *   sourceWidth?: int,
 *   sourceHeight?: int,
 *   framingBoxes?: list<array<string, mixed>>
 * } $opts
 */
function image_sequence_render_omakase_player(array $opts): void
{
    global $lang, $baseurl_short, $ffmpeg_preview_extension;
    global $image_sequence_framing_fps_default;

    $ref = (int) ($opts['ref'] ?? 0);
    $fps = (float) ($opts['fps'] ?? 30);
    $frame_count = (int) ($opts['frameCount'] ?? 0);
    $current_rep = (int) ($opts['repFrame'] ?? 0);
    $in_frame = (int) ($opts['inFrame'] ?? 0);
    $out_frame = (int) ($opts['outFrame'] ?? max(0, $frame_count - 1));
    $can_edit = !empty($opts['canEdit']);
    $video_url = image_sequence_player_media_url((string) ($opts['videoUrl'] ?? ''));
    $poster_url = image_sequence_player_media_url((string) ($opts['posterUrl'] ?? ''));
    $mode = (string) ($opts['mode'] ?? 'sequence');
    $aspect_ratio_css = (string) ($opts['aspectRatioCss'] ?? '');
    $render_fps_default = image_sequence_framing_normalise_fps(
        (float) ($image_sequence_framing_fps_default ?? 24)
    );
    if ($aspect_ratio_css === '' && $ref > 0) {
        $preview_ext = $ffmpeg_preview_extension ?: 'mp4';
        $aspect_ratio_css = image_sequence_player_aspect_ratio_css($ref, 'pre', $preview_ext);
    }
    if ($aspect_ratio_css === '') {
        $aspect_ratio_css = '16 / 9';
    }

    $source_width = (int) ($opts['sourceWidth'] ?? 0);
    $source_height = (int) ($opts['sourceHeight'] ?? 0);
    $framing_boxes = is_array($opts['framingBoxes'] ?? null) ? $opts['framingBoxes'] : [];
    if ($ref > 0 && ($source_width <= 0 || $source_height <= 0 || $framing_boxes === [])) {
        image_sequence_ensure_framing_table();
        $resource_data = get_resource_data($ref);
        if (is_array($resource_data)) {
            if ($source_width <= 0 || $source_height <= 0) {
                $dims = image_sequence_source_dimensions($resource_data);
                $source_width = (int) $dims['width'];
                $source_height = (int) $dims['height'];
            }
            if ($framing_boxes === []) {
                $framing_boxes = image_sequence_framing_get_boxes($ref);
            }
        }
    }

    $aspect_presets = image_sequence_framing_aspect_presets();
    $aspects_for_js = [];
    foreach ($aspect_presets as $label => $pair) {
        $aspects_for_js[] = [
            'label' => (string) $label,
            'w' => (int) $pair[0],
            'h' => (int) $pair[1],
        ];
    }
    $default_aspect = image_sequence_framing_default_aspect_label();

    $player_element_id = 'image_sequence_omakase_player_' . $ref;
    $player_style = '--omakase-player-aspect-ratio: ' . $aspect_ratio_css . ';';

    $set_rep_url = generateURL($baseurl_short . 'plugins/image_sequence/pages/set_representative_frame.php', [
        'ref' => $ref,
    ]);
    $set_inout_url = generateURL($baseurl_short . 'plugins/image_sequence/pages/set_inout_frames.php', [
        'ref' => $ref,
    ]);
    $framing_url = generateURL($baseurl_short . 'plugins/image_sequence/pages/framing_boxes.php', [
        'ref' => $ref,
    ]);
    $view_url = generateURL($baseurl_short . 'pages/view.php', [
        'ref' => $ref,
    ]);

    $omakase_config = [
        'ref' => $ref,
        'mode' => $mode,
        'playerElementId' => $player_element_id,
        'videoUrl' => $video_url,
        'posterUrl' => $poster_url,
        'fps' => $fps,
        'frameCount' => $frame_count,
        'repFrame' => $current_rep,
        'inFrame' => $in_frame,
        'outFrame' => $out_frame,
        'canEdit' => $can_edit,
        'repUrl' => $set_rep_url,
        'inoutUrl' => $set_inout_url,
        'framingUrl' => $framing_url,
        'viewUrl' => $view_url,
        'sourceWidth' => $source_width,
        'sourceHeight' => $source_height,
        'aspects' => $aspects_for_js,
        'defaultAspect' => $default_aspect,
        'framingBoxes' => $framing_boxes,
        'renderFpsDefault' => $render_fps_default,
        'renderSizeDefault' => '4k',
        'csrfRep' => json_decode(generate_csrf_js_object('set_representative_frame'), true) ?: [],
        'csrfInout' => json_decode(generate_csrf_js_object('set_inout_frames'), true) ?: [],
        'csrfFraming' => json_decode(generate_csrf_js_object('framing_boxes'), true) ?: [],
        'lang' => [
            'markedIn' => $lang['image_sequence_marked_in'] ?? 'In point marked (click Save in/out to store).',
            'markedOut' => $lang['image_sequence_marked_out'] ?? 'Out point marked (click Save in/out to store).',
            'savingInOut' => $lang['image_sequence_inout_saving'] ?? 'Saving in/out points…',
            'inoutSet' => $lang['image_sequence_inout_set'] ?? 'In/out points updated.',
            'inoutFailed' => $lang['image_sequence_inout_failed'] ?? 'Could not set in/out points.',
            'markedRep' => $lang['image_sequence_marked_rep'] ?? 'Representative frame marked (click Save to store).',
            'savingRep' => $lang['image_sequence_rep_frame_saving'] ?? 'Saving representative frame…',
            'repSet' => $mode === 'video'
                ? ($lang['image_sequence_video_rep_frame_set'] ?? 'Representative frame updated (still extracted).')
                : ($lang['image_sequence_rep_frame_set'] ?? 'Representative frame updated.'),
            'repFailed' => $lang['image_sequence_rep_frame_failed'] ?? 'Could not set representative frame.',
            'loadFailed' => $lang['image_sequence_player_load_failed'] ?? 'Could not load preview player.',
            'framingSaved' => $lang['image_sequence_framing_saved'] ?? 'Framing box saved.',
            'framingSaveFailed' => $lang['image_sequence_framing_save_failed'] ?? 'Could not save framing box.',
            'framingDeleted' => $lang['image_sequence_framing_deleted'] ?? 'Framing box deleted.',
            'framingDeleteFailed' => $lang['image_sequence_framing_delete_failed'] ?? 'Could not delete framing box.',
            'framingRenderQueued' => $lang['image_sequence_framing_render_queued'] ?? 'Render queued…',
            'framingRenderFailed' => $lang['image_sequence_framing_render_failed'] ?? 'Framing render failed.',
            'framingTierOk' => $lang['image_sequence_framing_tier_ok'] ?? '≥ 4K',
            'framingTierWarn' => $lang['image_sequence_framing_tier_warn'] ?? 'Below 4K (will upscale)',
            'framingTierLow' => $lang['image_sequence_framing_tier_low'] ?? 'Below 1080p',
            'framingUnsaved' => $lang['image_sequence_framing_unsaved'] ?? 'Unsaved',
            'framingNoDims' => $lang['image_sequence_framing_no_dims'] ?? 'Source dimensions unknown — framing disabled.',
            'framingPlay' => $lang['image_sequence_framing_play'] ?? 'Play',
            'framingPlayTitle' => $lang['image_sequence_framing_play_title'] ?? 'Open in ResourceSpace player',
            'framingDownload' => $lang['image_sequence_framing_download'] ?? 'Download',
            'framingFps' => $lang['image_sequence_framing_fps'] ?? 'FPS',
            'framingFpsTitle' => $lang['image_sequence_framing_fps_title'] ?? 'Playback frame rate for the rendered MP4',
            'framingRotation' => $lang['image_sequence_framing_rotation'] ?? 'Rotation',
            'framingRotationTitle' => $lang['image_sequence_framing_rotation_title'] ?? 'Rotate image under this box (clockwise degrees)',
            'framingSize' => $lang['image_sequence_framing_size'] ?? 'Size',
            'framingSizeTitle' => $lang['image_sequence_framing_size_title'] ?? 'Output frame size for the rendered MP4',
            'framingCopyJson' => $lang['image_sequence_framing_copy_json'] ?? 'Copy JSON',
            'framingCopyJsonTitle' => $lang['image_sequence_framing_copy_json_title'] ?? 'Copy framing boxes JSON for external renderers',
            'framingCopyJsonDone' => $lang['image_sequence_framing_copy_json_done'] ?? 'Framing JSON copied to clipboard.',
            'framingCopyJsonFailed' => $lang['image_sequence_framing_copy_json_failed'] ?? 'Could not copy framing JSON.',
        ],
    ];

    $ico = static function (string $name): string {
        $icons = [
            'back10' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11.2 6.2 4.5 12l6.7 5.8V14h4.3V10H11.2V6.2zm8.3 0L12.8 12l6.7 5.8V6.2z"/></svg>',
            'back1' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.8 6.1 7.9 12l6.9 5.9V6.1zM6.2 6v12h1.8V6H6.2z"/></svg>',
            'play' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5L8 5.5z"/></svg>',
            'pause' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7V5zm6.5 0H17v14h-3.5V5z"/></svg>',
            'fwd1' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.2 6.1v11.8L16.1 12 9.2 6.1zM16 6h1.8v12H16V6z"/></svg>',
            'fwd10' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 6.2v11.6L11.2 12 4.5 6.2zm8.3 0v3.8H17v4h-4.2v3.8L20 12l-7.2-5.8z"/></svg>',
            'mark_in' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h2.2v16H5V4zm3.8 3.2v9.6L16.5 12 8.8 7.2z"/></svg>',
            'mark_out' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.8 4H19v16h-2.2V4zM7.5 12l7.7 4.8V7.2L7.5 12z"/></svg>',
            'goto_in' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h2.2v16H5V4zm13.2 2.8-6.6 4.1v-3H8.8v6.2h2.8v-3l6.6 4.1V6.8z"/></svg>',
            'goto_out' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.8 4H19v16h-2.2V4zM5.8 6.8v10.4l6.6-4.1v3h2.8V7.8h-2.8v3L5.8 6.8z"/></svg>',
            'rep' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.4 14.4 9l5.9.5-4.5 3.9 1.4 5.7L12 16.5 6.8 19.1l1.4-5.7L3.7 9.5 9.6 9 12 3.4z"/></svg>',
            'save' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h11.2L19 5.8V21H5V3zm2 2v4h8V5H7zm8 14v-6H9v6h6z"/></svg>',
            'fs_enter' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5v2H6v3H4zm10-5h5v5h-2V6h-3V4zM4 15h2v3h3v2H4v-5zm14 0h2v5h-5v-2h3v-3z"/></svg>',
            'fs_exit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H7v3H4v2h5V4zm10 3h-3V4h-2v5h5V7zM7 17v3h2v-5H4v2h3zm10 0h3v-2h-5v5h2v-3z"/></svg>',
            'counter' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v3H4V5zm0 5.5h10v3H4v-3zm0 5.5h13v3H4v-3z"/></svg>',
            'add' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5z"/></svg>',
            'eye' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.2 0 9.5 3.3 11 7-1.5 3.7-5.8 7-11 7S2.5 15.7 1 12c1.5-3.7 5.8-7 11-7zm0 2.5A4.5 4.5 0 1 0 16.5 12 4.5 4.5 0 0 0 12 7.5z"/></svg>',
        ];

        return $icons[$name] ?? '';
    };
    ?>
    <div id="previewimagewrapper" class="image_sequence_omakase_wrap">
        <div class="image_sequence_player_stage">
            <div
                id="<?php echo escape($player_element_id); ?>"
                class="image_sequence_omakase_player"
                style="<?php echo escape($player_style); ?>"
            ></div>
            <div
                id="image_sequence_framing_overlay"
                class="image_sequence_framing_overlay"
                aria-hidden="false"
            ></div>
            <div
                id="image_sequence_frame_overlay"
                class="image_sequence_frame_overlay"
                hidden
                aria-hidden="true"
            >
                <span class="nle-overlay-label">FRAME</span>
                <span class="nle-overlay-frame" id="image_sequence_overlay_frame">0</span>
                <span class="nle-overlay-sep">/</span>
                <span class="nle-overlay-total" id="image_sequence_overlay_total"><?php echo (int) $frame_count; ?></span>
            </div>
        </div>
        <div class="image_sequence_nle" data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>" data-mode="<?php echo escape($mode); ?>">
            <div class="image_sequence_nle_readout" aria-live="polite">
                <span>
                    <span class="nle-label"><?php echo escape($lang['image_sequence_current_frame']); ?></span>
                    <strong id="image_sequence_current_frame">0</strong>/<span id="image_sequence_total_frames"><?php echo (int) $frame_count; ?></span>
                </span>
                <span class="nle-in">
                    <span class="nle-label"><?php echo escape($lang['image_sequence_in_point'] ?? 'In'); ?></span>
                    <strong id="image_sequence_saved_in_frame"><?php echo (int) $in_frame; ?></strong>
                </span>
                <span class="nle-out">
                    <span class="nle-label"><?php echo escape($lang['image_sequence_out_point'] ?? 'Out'); ?></span>
                    <strong id="image_sequence_saved_out_frame"><?php echo (int) $out_frame; ?></strong>
                </span>
                <span class="nle-rep">
                    <span class="nle-label"><?php echo escape($lang['image_sequence_rep_frame_current']); ?></span>
                    <strong id="image_sequence_saved_rep_frame"><?php echo (int) $current_rep; ?></strong>
                </span>
            </div>

            <div
                class="image_sequence_nle_timeline"
                id="image_sequence_timeline"
                role="slider"
                tabindex="0"
                aria-label="<?php echo escape($lang['image_sequence_timeline'] ?? 'Timeline'); ?>"
                aria-valuemin="0"
                aria-valuemax="<?php echo max(0, (int) $frame_count - 1); ?>"
                aria-valuenow="0"
                data-frame-count="<?php echo (int) $frame_count; ?>"
                data-in-frame="<?php echo (int) $in_frame; ?>"
                data-out-frame="<?php echo (int) $out_frame; ?>"
                data-rep-frame="<?php echo (int) $current_rep; ?>"
            >
                <div class="nle-timeline-track">
                    <div class="nle-timeline-range" id="image_sequence_timeline_range"></div>
                    <div class="nle-timeline-mark nle-timeline-in" id="image_sequence_timeline_in" title="In"></div>
                    <div class="nle-timeline-mark nle-timeline-out" id="image_sequence_timeline_out" title="Out"></div>
                    <div class="nle-timeline-mark nle-timeline-rep" id="image_sequence_timeline_rep" title="Rep"></div>
                    <div class="nle-timeline-playhead" id="image_sequence_timeline_playhead"></div>
                </div>
            </div>

            <div class="image_sequence_nle_toolbar" role="toolbar" aria-label="<?php echo escape($lang['image_sequence_nle_toolbar'] ?? 'Editing controls'); ?>">
                <?php if ($can_edit) { ?>
                    <div class="image_sequence_nle_group nle-group-in" role="group" aria-label="<?php echo escape($lang['image_sequence_in_point'] ?? 'In'); ?>">
                        <button type="button" class="image_sequence_nle_btn nle-mark-in" id="image_sequence_mark_in" title="<?php echo escape(($lang['image_sequence_mark_in'] ?? 'Mark In') . ' (I)'); ?>">
                            <?php echo $ico('mark_in'); ?>
                        </button>
                        <button type="button" class="image_sequence_nle_btn nle-goto-in" id="image_sequence_goto_in" title="<?php echo escape(($lang['image_sequence_goto_in'] ?? 'Go to In') . ' (Shift+I)'); ?>">
                            <?php echo $ico('goto_in'); ?>
                        </button>
                    </div>
                    <span class="image_sequence_nle_sep" aria-hidden="true"></span>
                <?php } ?>

                <div class="image_sequence_nle_group nle-group-transport" role="group" aria-label="<?php echo escape($lang['image_sequence_frame_nav'] ?? 'Transport'); ?>">
                    <button type="button" class="image_sequence_nle_btn" id="image_sequence_frame_back_10" title="<?php echo escape(($lang['image_sequence_frame_back_10'] ?? 'Back 10 frames') . ' (Shift+←)'); ?>">
                        <?php echo $ico('back10'); ?>
                    </button>
                    <button type="button" class="image_sequence_nle_btn" id="image_sequence_frame_back" title="<?php echo escape(($lang['image_sequence_frame_back'] ?? 'Previous frame') . ' (←)'); ?>">
                        <?php echo $ico('back1'); ?>
                    </button>
                    <button type="button" class="image_sequence_nle_btn" id="image_sequence_play_toggle" title="<?php echo escape(($lang['image_sequence_play_pause'] ?? 'Play / Pause') . ' (Space)'); ?>" aria-pressed="false">
                        <span class="nle-icon-play"><?php echo $ico('play'); ?></span>
                        <span class="nle-icon-pause" hidden><?php echo $ico('pause'); ?></span>
                    </button>
                    <button type="button" class="image_sequence_nle_btn" id="image_sequence_frame_forward" title="<?php echo escape(($lang['image_sequence_frame_forward'] ?? 'Next frame') . ' (→)'); ?>">
                        <?php echo $ico('fwd1'); ?>
                    </button>
                    <button type="button" class="image_sequence_nle_btn" id="image_sequence_frame_forward_10" title="<?php echo escape(($lang['image_sequence_frame_forward_10'] ?? 'Forward 10 frames') . ' (Shift+→)'); ?>">
                        <?php echo $ico('fwd10'); ?>
                    </button>
                </div>

                <?php if ($can_edit) { ?>
                    <span class="image_sequence_nle_sep" aria-hidden="true"></span>
                    <div class="image_sequence_nle_group nle-group-out" role="group" aria-label="<?php echo escape($lang['image_sequence_out_point'] ?? 'Out'); ?>">
                        <button type="button" class="image_sequence_nle_btn nle-goto-out" id="image_sequence_goto_out" title="<?php echo escape(($lang['image_sequence_goto_out'] ?? 'Go to Out') . ' (Shift+O)'); ?>">
                            <?php echo $ico('goto_out'); ?>
                        </button>
                        <button type="button" class="image_sequence_nle_btn nle-mark-out" id="image_sequence_mark_out" title="<?php echo escape(($lang['image_sequence_mark_out'] ?? 'Mark Out') . ' (O)'); ?>">
                            <?php echo $ico('mark_out'); ?>
                        </button>
                    </div>

                    <span class="image_sequence_nle_sep" aria-hidden="true"></span>
                    <div class="image_sequence_nle_group nle-group-actions" role="group" aria-label="<?php echo escape($lang['image_sequence_section'] ?? 'Actions'); ?>">
                        <button type="button" class="image_sequence_nle_btn nle-rep" id="image_sequence_set_rep_frame" title="<?php echo escape($lang['image_sequence_use_rep_frame'] ?? 'Use as representative frame'); ?>">
                            <?php echo $ico('rep'); ?>
                        </button>
                        <button type="button" class="image_sequence_nle_btn nle-save" id="image_sequence_save_inout" title="<?php echo escape($lang['image_sequence_save_inout'] ?? 'Save in/out'); ?>">
                            <?php echo $ico('save'); ?>
                            <span><?php echo escape($lang['image_sequence_save_inout_short'] ?? 'Save'); ?></span>
                        </button>
                    </div>

                    <span class="image_sequence_nle_sep" aria-hidden="true"></span>
                    <div class="image_sequence_nle_group nle-group-framing" role="group" aria-label="<?php echo escape($lang['image_sequence_framing'] ?? 'Framing'); ?>">
                        <select
                            id="image_sequence_framing_aspect"
                            class="nle-framing-aspect"
                            aria-label="<?php echo escape($lang['image_sequence_framing_aspect'] ?? 'Aspect ratio'); ?>"
                            title="<?php echo escape($lang['image_sequence_framing_aspect'] ?? 'Aspect ratio'); ?>"
                        >
                            <?php foreach ($aspect_presets as $label => $pair) { ?>
                                <option
                                    value="<?php echo escape((string) $label); ?>"
                                    data-w="<?php echo (int) $pair[0]; ?>"
                                    data-h="<?php echo (int) $pair[1]; ?>"
                                    <?php echo ((string) $label === $default_aspect) ? 'selected' : ''; ?>
                                ><?php echo escape((string) $label); ?></option>
                            <?php } ?>
                        </select>
                        <button type="button" class="image_sequence_nle_btn nle-framing-add" id="image_sequence_framing_add" title="<?php echo escape($lang['image_sequence_framing_add'] ?? 'Add framing box'); ?>">
                            <?php echo $ico('add'); ?>
                            <span><?php echo escape($lang['image_sequence_framing_add_short'] ?? 'Box'); ?></span>
                        </button>
                        <button type="button" class="image_sequence_nle_btn nle-framing-toggle" id="image_sequence_framing_toggle" title="<?php echo escape($lang['image_sequence_framing_toggle'] ?? 'Show / hide framing boxes'); ?>" aria-pressed="true">
                            <?php echo $ico('eye'); ?>
                        </button>
                    </div>
                <?php } else { ?>
                    <span class="image_sequence_nle_sep" aria-hidden="true"></span>
                    <div class="image_sequence_nle_group nle-group-framing" role="group" aria-label="<?php echo escape($lang['image_sequence_framing'] ?? 'Framing'); ?>">
                        <button type="button" class="image_sequence_nle_btn nle-framing-toggle" id="image_sequence_framing_toggle" title="<?php echo escape($lang['image_sequence_framing_toggle'] ?? 'Show / hide framing boxes'); ?>" aria-pressed="true">
                            <?php echo $ico('eye'); ?>
                        </button>
                    </div>
                <?php } ?>

                <span class="image_sequence_nle_sep" aria-hidden="true"></span>
                <div class="image_sequence_nle_group nle-group-view" role="group" aria-label="<?php echo escape($lang['image_sequence_view_controls'] ?? 'View'); ?>">
                    <button type="button" class="image_sequence_nle_btn nle-counter" id="image_sequence_frame_overlay_toggle" title="<?php echo escape(($lang['image_sequence_frame_overlay'] ?? 'Frame counter overlay') . ' (C)'); ?>" aria-pressed="false">
                        <?php echo $ico('counter'); ?>
                    </button>
                    <button type="button" class="image_sequence_nle_btn nle-fullscreen" id="image_sequence_fullscreen" title="<?php echo escape(($lang['image_sequence_fullscreen'] ?? 'Fullscreen') . ' (F)'); ?>" aria-pressed="false">
                        <span class="nle-icon-fs-enter"><?php echo $ico('fs_enter'); ?></span>
                        <span class="nle-icon-fs-exit" hidden><?php echo $ico('fs_exit'); ?></span>
                    </button>
                </div>

                <p class="image_sequence_nle_hint">
                    <?php echo escape($lang['image_sequence_nle_hint'] ?? 'I/O mark · Shift+I/O go to mark · ←/→ frame · Space play · C counter · F fullscreen'); ?>
                </p>
            </div>

            <div class="image_sequence_framing_panel" id="image_sequence_framing_panel">
                <div class="image_sequence_framing_panel_head">
                    <strong><?php echo escape($lang['image_sequence_framing'] ?? 'Framing'); ?></strong>
                    <span class="image_sequence_framing_panel_meta">
                        <?php if ($can_edit) { ?>
                            <label class="image_sequence_framing_ctrl_label" for="image_sequence_framing_size" title="<?php echo escape($lang['image_sequence_framing_size_title'] ?? 'Output frame size for the rendered MP4'); ?>">
                                <?php echo escape($lang['image_sequence_framing_size'] ?? 'Size'); ?>
                                <select id="image_sequence_framing_size" class="image_sequence_framing_size">
                                    <option value="4k" selected><?php echo escape($lang['image_sequence_framing_size_4k'] ?? '4K'); ?></option>
                                    <option value="1080p"><?php echo escape($lang['image_sequence_framing_size_1080p'] ?? '1080p'); ?></option>
                                    <option value="720p"><?php echo escape($lang['image_sequence_framing_size_720p'] ?? '720p'); ?></option>
                                </select>
                            </label>
                            <label class="image_sequence_framing_ctrl_label" for="image_sequence_framing_fps" title="<?php echo escape($lang['image_sequence_framing_fps_title'] ?? 'Playback frame rate for the rendered MP4'); ?>">
                                <?php echo escape($lang['image_sequence_framing_fps'] ?? 'FPS'); ?>
                                <input
                                    type="number"
                                    id="image_sequence_framing_fps"
                                    class="image_sequence_framing_fps"
                                    min="1"
                                    max="120"
                                    step="0.001"
                                    value="<?php echo escape((string) $render_fps_default); ?>"
                                >
                            </label>
                        <?php } ?>
                        <button
                            type="button"
                            class="image_sequence_framing_btn"
                            id="image_sequence_framing_copy_json"
                            title="<?php echo escape($lang['image_sequence_framing_copy_json_title'] ?? 'Copy framing boxes JSON for external renderers'); ?>"
                        ><?php echo escape($lang['image_sequence_framing_copy_json'] ?? 'Copy JSON'); ?></button>
                        <span class="image_sequence_framing_source_dims" id="image_sequence_framing_source_dims">
                            <?php
                            if ($source_width > 0 && $source_height > 0) {
                                echo escape($source_width . ' × ' . $source_height);
                            }
                            ?>
                        </span>
                    </span>
                </div>
                <ul class="image_sequence_framing_list" id="image_sequence_framing_list"></ul>
                <p class="image_sequence_framing_empty" id="image_sequence_framing_empty">
                    <?php echo escape($lang['image_sequence_framing_empty'] ?? 'No framing boxes yet. Choose an aspect and click Add box, or drag on the video.'); ?>
                </p>
            </div>

            <span id="image_sequence_frame_status" class="image_sequence_nle_status"></span>
        </div>
    </div>
    <?php image_sequence_render_crypto_random_uuid_polyfill_script(); ?>
    <script>
    window.ImageSequenceOmakaseConfig = <?php echo json_encode($omakase_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    (function () {
        function bootOmakase() {
            if (!window.ImageSequenceOmakase || typeof window.ImageSequenceOmakase.init !== 'function') {
                return false;
            }
            window.ImageSequenceOmakase.init(window.ImageSequenceOmakaseConfig);
            return true;
        }
        if (!bootOmakase()) {
            var tries = 0;
            var timer = setInterval(function () {
                tries++;
                if (bootOmakase()) {
                    clearInterval(timer);
                    return;
                }
                if (tries > 40) {
                    clearInterval(timer);
                    var status = document.getElementById('image_sequence_frame_status');
                    if (status) {
                        status.textContent = <?php echo json_encode(
                            $lang['image_sequence_player_boot_failed']
                                ?? 'Preview player did not load. Check the browser console and network tab for the proxy video.'
                        ); ?>;
                        status.classList.add('image_sequence_status_error');
                    }
                }
            }, 250);
        }
    })();
    </script>
    <?php
}
