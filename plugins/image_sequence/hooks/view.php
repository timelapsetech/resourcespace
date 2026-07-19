<?php

include_once __DIR__ . '/../include/image_sequence_functions.php';
include_once __DIR__ . '/../include/omakase_player_render.php';

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
    global $resource, $ref, $lang, $baseurl, $ffmpeg_preview_extension;

    if (!is_array($resource)) {
        return false;
    }

    // --- Image Sequence ---
    if (image_sequence_is_sequence_resource($resource)) {
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

            image_sequence_render_omakase_player([
                'ref' => (int) $ref,
                'mode' => 'sequence',
                'fps' => $fps,
                'frameCount' => $frame_count,
                'repFrame' => $current_rep,
                'inFrame' => $in_frame,
                'outFrame' => $out_frame,
                'videoUrl' => $video_url,
                'posterUrl' => $poster_url,
                'canEdit' => (bool) $can_edit,
            ]);

            return true;
        }

        if ($status === 'failed') {
            echo '<p class="FormHelp">' . escape($lang['image_sequence_proxy_failed']) . '</p>';
            return true;
        }

        echo '<p class="FormHelp">' . escape($lang['image_sequence_generating_preview']) . '</p>';
        return true;
    }

    // --- Standard video resources ---
    if (image_sequence_is_video_resource($resource)) {
        if (isset($resource['is_transcoding']) && (int) $resource['is_transcoding'] !== 0) {
            return false;
        }

        $playback_path = image_sequence_video_playback_path($resource);
        if ($playback_path === '' || !is_file($playback_path)) {
            return false;
        }

        global $video_preview_original;
        $videosize = !empty($video_preview_original) ? '' : 'pre';
        $videoext = !empty($video_preview_original)
            ? strtolower((string) ($resource['file_extension'] ?? ''))
            : ($ffmpeg_preview_extension ?: 'mp4');

        $video_url = get_resource_path((int) $ref, false, $videosize, false, $videoext, true, 1, false, '', -1, true);
        if (strpos((string) $video_url, '://') === false) {
            $video_url = rtrim((string) $baseurl, '/') . '/' . ltrim((string) $video_url, '/');
        }
        $poster_url = get_resource_path((int) $ref, false, 'pre', false, 'jpg', true, 1, false, '', -1, true);
        if (strpos((string) $poster_url, '://') === false) {
            $poster_url = rtrim((string) $baseurl, '/') . '/' . ltrim((string) $poster_url, '/');
        }

        $marks = image_sequence_video_get_marks((int) $ref);
        $can_edit = get_edit_access((int) $ref);

        image_sequence_render_omakase_player([
            'ref' => (int) $ref,
            'mode' => 'video',
            'fps' => (float) $marks['fps'],
            'frameCount' => (int) $marks['frame_count'],
            'repFrame' => (int) $marks['representative_frame'],
            'inFrame' => (int) $marks['in_frame'],
            'outFrame' => (int) $marks['out_frame'],
            'videoUrl' => $video_url,
            'posterUrl' => $poster_url,
            'canEdit' => (bool) $can_edit,
        ]);

        return true;
    }

    return false;
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
