<?php

include_once __DIR__ . '/../include/image_sequence_functions.php';

function HookImage_sequenceViewRenderinnerresourcepreview()
{
    global $resource, $ref, $lang, $baseurl_short, $ffmpeg_preview_extension, $context, $display;

    if (!is_array($resource) || !image_sequence_is_sequence_resource($resource)) {
        return false;
    }

    $data = image_sequence_get_data((int) $ref);
    $status = $data['proxy_status'] ?? 'pending';
    $ext = $ffmpeg_preview_extension ?: 'mp4';
    $proxy_path = get_resource_path((int) $ref, true, 'pre', false, $ext);

    if ($status === 'ready' && file_exists($proxy_path)) {
        $player_id = ($context ?? 'Root') . '_' . ($display ?? '') . '_introvideo' . (int) $ref;
        ?>
        <div id="previewimagewrapper">
            <?php include __DIR__ . '/../../../pages/video_player.php'; ?>
        </div>
        <?php
        if (get_edit_access((int) $ref)) {
            $fps = (float) ($data['fps'] ?? 30);
            $frame_count = (int) ($data['frame_count'] ?? 0);
            $current_rep = (int) ($data['representative_frame'] ?? 0);
            $set_url = generateURL($baseurl_short . 'plugins/image_sequence/pages/set_representative_frame.php', [
                'ref' => (int) $ref,
            ]);
            $view_url = generateURL($baseurl_short . 'pages/view.php', [
                'ref' => (int) $ref,
            ]);
            ?>
            <div class="image_sequence_rep_frame_panel">
                <p>
                    <span id="image_sequence_current_frame_label"><?php echo escape($lang['image_sequence_current_frame']); ?>:</span>
                    <strong id="image_sequence_current_frame">0</strong>
                    /
                    <?php echo $frame_count; ?>
                    <span class="FormHelp">
                        (<?php echo escape($lang['image_sequence_rep_frame_current']); ?>:
                        <strong id="image_sequence_saved_rep_frame"><?php echo $current_rep; ?></strong>)
                    </span>
                </p>
                <button type="button" class="rs_btn" id="image_sequence_set_rep_frame">
                    <?php echo escape($lang['image_sequence_use_rep_frame']); ?>
                </button>
                <span id="image_sequence_rep_frame_status"></span>
            </div>
            <script>
            (function () {
                var fps = <?php echo json_encode($fps); ?>;
                var frameCount = <?php echo json_encode($frame_count); ?>;
                var setUrl = <?php echo json_encode($set_url); ?>;
                var viewUrl = <?php echo json_encode($view_url); ?>;
                var playerId = <?php echo json_encode($player_id); ?>;
                var csrf = {<?php echo generateAjaxToken('set_representative_frame'); ?>};
                var lastFrame = 0;
                var boundPlayerId = null;
                var bindTries = 0;

                function getPlayer() {
                    var el = document.getElementById(playerId);
                    if (typeof videojs === 'function') {
                        try {
                            var existing = videojs.getPlayer(playerId);
                            if (existing) {
                                return existing;
                            }
                        } catch (e) {}
                        if (el) {
                            try {
                                return videojs(playerId);
                            } catch (e2) {}
                        }
                    }
                    return el;
                }

                function readTime(player) {
                    if (!player) {
                        return 0;
                    }
                    try {
                        if (typeof player.currentTime === 'function') {
                            return Number(player.currentTime()) || 0;
                        }
                        if (typeof player.currentTime === 'number') {
                            return player.currentTime;
                        }
                    } catch (e) {}
                    return 0;
                }

                function frameFromPlayer() {
                    var t = readTime(getPlayer());
                    // Floor avoids rounding up to frame_count at EOF (invalid index).
                    var frame = Math.max(0, Math.floor(t * fps + 1e-6));
                    if (frameCount > 0) {
                        frame = Math.min(frame, frameCount - 1);
                    }
                    return frame;
                }

                function updateLabel() {
                    lastFrame = frameFromPlayer();
                    jQuery('#image_sequence_current_frame').text(lastFrame);
                }

                function bindPlayerEvents() {
                    if (boundPlayerId === playerId) {
                        return;
                    }
                    var player = getPlayer();
                    if (!player) {
                        if (bindTries++ < 40) {
                            setTimeout(bindPlayerEvents, 250);
                        }
                        return;
                    }
                    boundPlayerId = playerId;
                    if (typeof player.on === 'function') {
                        player.on('timeupdate', updateLabel);
                        player.on('seeked', updateLabel);
                        player.on('pause', updateLabel);
                    } else if (player.addEventListener) {
                        player.addEventListener('timeupdate', updateLabel);
                        player.addEventListener('seeked', updateLabel);
                        player.addEventListener('pause', updateLabel);
                    }
                    updateLabel();
                }

                var saving = false;

                // Event delegation survives CentralSpace AJAX reloads; namespace avoids double-binds.
                jQuery(document)
                    .off('click.imgseqRepFrame', '#image_sequence_set_rep_frame')
                    .on('click.imgseqRepFrame', '#image_sequence_set_rep_frame', function (e) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        if (saving) {
                            return;
                        }
                        updateLabel();
                        var status = jQuery('#image_sequence_rep_frame_status');
                        var btn = jQuery(this);
                        saving = true;
                        status.text(<?php echo json_encode($lang['image_sequence_rep_frame_saving']); ?>);
                        btn.prop('disabled', true);

                        var postData = jQuery.extend({
                            ajax: 'true',
                            frame: lastFrame
                        }, csrf);

                        jQuery.ajax({
                            method: 'POST',
                            url: setUrl,
                            data: postData,
                            dataType: 'json'
                        }).done(function (data) {
                            if (data && data.ok) {
                                status.text(data.message || <?php echo json_encode($lang['image_sequence_rep_frame_set']); ?>);
                                jQuery('#image_sequence_saved_rep_frame').text(String(data.frame));
                                // Reload view so poster + EXIF metadata refresh.
                                if (typeof CentralSpaceLoad === 'function') {
                                    CentralSpaceLoad(viewUrl, true);
                                } else {
                                    window.location.href = viewUrl;
                                }
                            } else {
                                saving = false;
                                status.text((data && data.message) || <?php echo json_encode($lang['image_sequence_rep_frame_failed']); ?>);
                                btn.prop('disabled', false);
                            }
                        }).fail(function (xhr) {
                            saving = false;
                            var msg = <?php echo json_encode($lang['image_sequence_rep_frame_failed']); ?>;
                            try {
                                var err = (typeof xhr.responseJSON === 'object' && xhr.responseJSON)
                                    ? xhr.responseJSON
                                    : JSON.parse(xhr.responseText);
                                if (err && err.error && err.error.detail) {
                                    msg = err.error.detail;
                                } else if (err && err.message) {
                                    msg = err.message;
                                }
                            } catch (err2) {}
                            status.text(msg);
                            btn.prop('disabled', false);
                        });
                    });

                jQuery(document)
                    .off('CentralSpaceLoaded.imgseqRepFrame')
                    .on('CentralSpaceLoaded.imgseqRepFrame', function () {
                        boundPlayerId = null;
                        bindTries = 0;
                        setTimeout(bindPlayerEvents, 100);
                    });

                bindPlayerEvents();
            })();
            </script>
            <?php
        }
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
