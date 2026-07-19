<?php

include_once __DIR__ . '/../include/image_sequence_functions.php';

function HookImage_sequenceViewRenderbeforeresourcedetails()
{
    global $resource;

    if (!is_array($resource) || !image_sequence_is_sequence_resource($resource)) {
        return false;
    }

    // Prefer tab order_by (Default → Sequence → Image) over alphabetical sort.
    $GLOBALS['sort_tabs'] = false;

    // Keep field order within each tab aligned with our layout.
    $GLOBALS['use_order_by_tab_view'] = true;

    return false;
}

function HookImage_sequenceViewRenderinnerresourcepreview()
{
    global $resource, $ref, $lang, $baseurl, $baseurl_short, $ffmpeg_preview_extension;

    if (!is_array($resource) || !image_sequence_is_sequence_resource($resource)) {
        return false;
    }

    image_sequence_ensure_db_columns();

    $data = image_sequence_get_data((int) $ref);
    if ($data === null) {
        echo '<p class="FormHelp">' . escape($lang['image_sequence_no_data']) . '</p>';
        return true;
    }

    $status = $data['proxy_status'] ?? 'pending';
    $ext = $ffmpeg_preview_extension ?: 'mp4';
    $proxy_path = get_resource_path((int) $ref, true, 'pre', false, $ext);

    if ($status === 'ready' && file_exists($proxy_path)) {
        $can_edit = get_edit_access((int) $ref);
        $fps = (float) ($data['fps'] ?? 30);
        $frame_count = (int) ($data['frame_count'] ?? 0);
        $current_rep = (int) ($data['representative_frame'] ?? 0);
        $in_frame = isset($data['in_frame']) && $data['in_frame'] !== null && $data['in_frame'] !== ''
            ? (int) $data['in_frame']
            : 0;
        $out_frame = isset($data['out_frame']) && $data['out_frame'] !== null && $data['out_frame'] !== ''
            ? (int) $data['out_frame']
            : max(0, $frame_count - 1);

        $video_url = get_resource_path((int) $ref, false, 'pre', false, $ext, true, 1, false, '', -1, true);
        if (strpos((string) $video_url, '://') === false) {
            $video_url = rtrim((string) $baseurl, '/') . '/' . ltrim((string) $video_url, '/');
        }
        $poster_url = get_resource_path((int) $ref, false, 'pre', false, 'jpg', true, 1, false, '', -1, true);
        if (strpos((string) $poster_url, '://') === false) {
            $poster_url = rtrim((string) $baseurl, '/') . '/' . ltrim((string) $poster_url, '/');
        }

        $player_element_id = 'image_sequence_omakase_player_' . (int) $ref;

        $set_rep_url = generateURL($baseurl_short . 'plugins/image_sequence/pages/set_representative_frame.php', [
            'ref' => (int) $ref,
        ]);
        $set_inout_url = generateURL($baseurl_short . 'plugins/image_sequence/pages/set_inout_frames.php', [
            'ref' => (int) $ref,
        ]);
        $view_url = generateURL($baseurl_short . 'pages/view.php', [
            'ref' => (int) $ref,
        ]);

        $omakase_config = [
            'ref' => (int) $ref,
            'playerElementId' => $player_element_id,
            'videoUrl' => $video_url,
            'posterUrl' => $poster_url,
            'fps' => $fps,
            'frameCount' => $frame_count,
            'repFrame' => $current_rep,
            'inFrame' => $in_frame,
            'outFrame' => $out_frame,
            'canEdit' => (bool) $can_edit,
            'repUrl' => $set_rep_url,
            'inoutUrl' => $set_inout_url,
            'viewUrl' => $view_url,
            'csrfRep' => json_decode(generate_csrf_js_object('set_representative_frame'), true) ?: [],
            'csrfInout' => json_decode(generate_csrf_js_object('set_inout_frames'), true) ?: [],
            'lang' => [
                'markedIn' => $lang['image_sequence_marked_in'] ?? 'In point marked (click Save in/out to store).',
                'markedOut' => $lang['image_sequence_marked_out'] ?? 'Out point marked (click Save in/out to store).',
                'savingInOut' => $lang['image_sequence_inout_saving'] ?? 'Saving in/out points…',
                'inoutSet' => $lang['image_sequence_inout_set'] ?? 'In/out points updated.',
                'inoutFailed' => $lang['image_sequence_inout_failed'] ?? 'Could not set in/out points.',
                'savingRep' => $lang['image_sequence_rep_frame_saving'] ?? 'Saving representative frame…',
                'repSet' => $lang['image_sequence_rep_frame_set'] ?? 'Representative frame updated.',
                'repFailed' => $lang['image_sequence_rep_frame_failed'] ?? 'Could not set representative frame.',
                'loadFailed' => $lang['image_sequence_player_load_failed'] ?? 'Could not load sequence preview player.',
            ],
        ];
        ?>
        <div id="previewimagewrapper" class="image_sequence_omakase_wrap">
            <div
                id="<?php echo escape($player_element_id); ?>"
                class="image_sequence_omakase_player"
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
            <?php
            // Inline SVG icons — classic NLE mark/transport affordances.
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
                ];
                return $icons[$name] ?? '';
            };
            ?>
            <div class="image_sequence_nle" data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>">
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

                <div class="image_sequence_nle_toolbar" role="toolbar" aria-label="<?php echo escape($lang['image_sequence_nle_toolbar'] ?? 'Sequence editing controls'); ?>">
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
                <span id="image_sequence_frame_status" class="image_sequence_nle_status"></span>
            </div>
        </div>
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
                    if (bootOmakase() || tries > 40) {
                        clearInterval(timer);
                    }
                }, 250);
            }
        })();
        </script>
        <?php
        return true;
    }

    if ($status === 'failed') {
        echo '<p class="FormHelp">' . escape($lang['image_sequence_proxy_failed']) . '</p>';
        return true;
    }

    echo '<p class="FormHelp">' . escape($lang['image_sequence_generating_preview']) . '</p>';
    return true;
}

function HookImage_sequenceViewAfterresourceed()
{
    global $resource, $ref, $lang, $baseurl_short, $access;

    if (!is_array($resource) || !image_sequence_is_sequence_resource($resource)) {
        return false;
    }
    if ((int) $access > 0) {
        return false;
    }

    $zip_url = generateURL($baseurl_short . 'plugins/image_sequence/pages/download_zip.php', [
        'ref' => (int) $ref,
    ]);
    ?>
    <li>
        <a href="<?php echo escape($zip_url); ?>" onclick="return CentralSpaceLoad(this, true);">
            <?php echo escape($lang['image_sequence_download_zip']); ?>
        </a>
    </li>
    <?php
    return false;
}
