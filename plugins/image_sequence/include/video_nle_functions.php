<?php

use Montala\ResourceSpace\CommandPlaceholderArg;

/**
 * Video NLE (Omakase): in/out marks + representative frame extraction for
 * standard video resources (ffmpeg-supported extensions).
 */

/**
 * Whether the plugin should offer the Omakase NLE on video resources.
 */
function image_sequence_video_nle_enabled(): bool
{
    global $image_sequence_video_nle;

    return !isset($image_sequence_video_nle) || (bool) $image_sequence_video_nle;
}

/**
 * True when this resource is a playable video (not an Image Sequence).
 */
function image_sequence_is_video_resource(array $resource): bool
{
    global $ffmpeg_supported_extensions, $ffmpeg_preview_gif;

    if (!image_sequence_video_nle_enabled()) {
        return false;
    }
    if (image_sequence_is_sequence_resource($resource)) {
        return false;
    }

    $ext = strtolower((string) ($resource['file_extension'] ?? ''));
    if ($ext === '') {
        return false;
    }
    if ($ext === 'gif') {
        return !empty($ffmpeg_preview_gif);
    }

    $supported = is_array($ffmpeg_supported_extensions) ? $ffmpeg_supported_extensions : [];

    return in_array($ext, $supported, true);
}

/**
 * Resource type IDs that should show video NLE metadata fields.
 *
 * @return list<int>
 */
function image_sequence_video_resource_types(): array
{
    global $ffmpeg_supported_extensions, $image_sequence_video_restypes;

    if (is_array($image_sequence_video_restypes) && $image_sequence_video_restypes !== []) {
        return array_values(array_unique(array_map('intval', $image_sequence_video_restypes)));
    }

    $supported = is_array($ffmpeg_supported_extensions)
        ? array_map('strtolower', $ffmpeg_supported_extensions)
        : [];
    $types = [];
    foreach (get_resource_types('', false, false, true) as $type) {
        $ref = (int) ($type['ref'] ?? 0);
        if ($ref <= 0) {
            continue;
        }
        $name = strtolower((string) ($type['name'] ?? ''));
        if (strpos($name, 'video') !== false) {
            $types[] = $ref;
            continue;
        }
        $allowed = array_filter(array_map(
            static fn ($e) => strtolower(trim((string) $e)),
            explode(',', (string) ($type['allowed_extensions'] ?? ''))
        ));
        if ($allowed !== [] && array_intersect($allowed, $supported) !== []) {
            $types[] = $ref;
        }
    }

    return array_values(array_unique($types));
}

/**
 * Ensure in/out/rep metadata fields also apply to video resource types.
 */
function image_sequence_ensure_video_field_types(): void
{
    if (!image_sequence_video_nle_enabled()) {
        return;
    }

    global $image_sequence_restype, $image_sequence_inframe_field, $image_sequence_outframe_field,
        $image_sequence_repframe_field, $image_sequence_fps_field, $image_sequence_duration_field,
        $image_sequence_framecount_field;

    $video_types = image_sequence_video_resource_types();
    if ($video_types === []) {
        return;
    }

    $targets = $video_types;
    if ((int) $image_sequence_restype > 0) {
        $targets[] = (int) $image_sequence_restype;
    }
    $targets = array_values(array_unique(array_map('intval', $targets)));

    $fields = [
        (int) $image_sequence_inframe_field,
        (int) $image_sequence_outframe_field,
        (int) $image_sequence_repframe_field,
        (int) $image_sequence_fps_field,
        (int) $image_sequence_duration_field,
        (int) $image_sequence_framecount_field,
    ];

    foreach ($fields as $field_ref) {
        if ($field_ref <= 0) {
            continue;
        }
        $info = get_resource_type_field($field_ref);
        if (!is_array($info)) {
            continue;
        }
        if ((int) ($info['global'] ?? 0) === 1) {
            continue;
        }
        $current = array_filter(array_map('intval', explode(',', (string) ($info['resource_types'] ?? ''))));
        $merged = array_values(array_unique(array_merge($current, $targets)));
        sort($current);
        $sorted_merged = $merged;
        sort($sorted_merged);
        if ($current !== $sorted_merged) {
            update_resource_type_field_resource_types($field_ref, $merged);
        }
    }
}

/**
 * Absolute path to the original video file (or preview if original missing).
 */
function image_sequence_video_source_path(array $resource): string
{
    global $ffmpeg_preview_extension, $video_preview_original;

    $ref = (int) ($resource['ref'] ?? 0);
    $ext = strtolower((string) ($resource['file_extension'] ?? ''));
    if ($ref <= 0 || $ext === '') {
        return '';
    }

    $original = get_resource_path($ref, true, '', false, $ext);
    if (is_string($original) && is_file($original)) {
        return $original;
    }

    $preview_ext = $ffmpeg_preview_extension ?: 'mp4';
    $preview = get_resource_path($ref, true, empty($video_preview_original) ? 'pre' : '', false, $preview_ext);
    if (is_string($preview) && is_file($preview)) {
        return $preview;
    }

    return '';
}

/**
 * Absolute path used for Omakase playback (prefer preview proxy).
 */
function image_sequence_video_playback_path(array $resource): string
{
    global $ffmpeg_preview_extension, $video_preview_original;

    $ref = (int) ($resource['ref'] ?? 0);
    if ($ref <= 0) {
        return '';
    }

    $videosize = !empty($video_preview_original) ? '' : 'pre';
    $videoext = !empty($video_preview_original)
        ? strtolower((string) ($resource['file_extension'] ?? ''))
        : ($ffmpeg_preview_extension ?: 'mp4');
    $path = get_resource_path($ref, true, $videosize, false, $videoext);
    if (is_string($path) && is_file($path)) {
        return $path;
    }

    return image_sequence_video_source_path($resource);
}

/**
 * Same-origin relative URL for Omakase (avoids localhost vs LAN $baseurl mismatches).
 */
function image_sequence_player_media_url(string $url): string
{
    global $baseurl;

    if ($url === '') {
        return '';
    }

    $base = rtrim((string) $baseurl, '/');
    if ($base !== '' && str_starts_with($url, $base)) {
        $relative = substr($url, strlen($base));
        return $relative !== '' ? $relative : '/';
    }

    $parts = parse_url($url);
    if (!empty($parts['path'])) {
        return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    return $url;
}

/**
 * CSS aspect-ratio value from the on-disk preview/proxy (e.g. "1280 / 854" for 3:2).
 */
function image_sequence_player_aspect_ratio_css(int $ref, string $size = 'pre', string $ext = 'mp4'): string
{
    $path = get_resource_path($ref, true, $size, false, $ext);
    if (!is_string($path) || !is_file($path)) {
        return '16 / 9';
    }

    $info = get_video_info($path);
    if (!is_array($info) || empty($info['streams']) || !is_array($info['streams'])) {
        return '16 / 9';
    }

    foreach ($info['streams'] as $stream) {
        if (($stream['codec_type'] ?? '') !== 'video') {
            continue;
        }
        $width = (int) ($stream['width'] ?? 0);
        $height = (int) ($stream['height'] ?? 0);
        if ($width > 0 && $height > 0) {
            return $width . ' / ' . $height;
        }
    }

    return '16 / 9';
}

/**
 * Probe fps / duration / frame count for a video resource.
 *
 * @return array{fps: float, duration: float, frame_count: int}|null
 */
function image_sequence_get_video_timing(int $ref): ?array
{
    $resource = get_resource_data($ref);
    if (!is_array($resource) || !image_sequence_is_video_resource($resource)) {
        return null;
    }

    $path = image_sequence_video_source_path($resource);
    if ($path === '') {
        $path = image_sequence_video_playback_path($resource);
    }
    if ($path === '' || !is_file($path)) {
        return null;
    }

    $fps = 0.0;
    $duration = 0.0;
    $nb_frames = 0;

    try {
        $info = get_video_info($path);
    } catch (Throwable $e) {
        debug('image_sequence_get_video_timing: ' . $e->getMessage());
        $info = null;
    }

    $streams = [];
    if (is_array($info)) {
        if (!empty($info['streams']) && is_array($info['streams'])) {
            $streams = $info['streams'];
        } else {
            $streams = [$info];
        }
    }

    foreach ($streams as $stream) {
        if (!is_array($stream)) {
            continue;
        }
        if (($stream['codec_type'] ?? '') !== '' && ($stream['codec_type'] ?? '') !== 'video') {
            continue;
        }
        if (!empty($stream['width']) || ($stream['codec_type'] ?? '') === 'video' || isset($stream['r_frame_rate'])) {
            if (!empty($stream['r_frame_rate']) && strpos((string) $stream['r_frame_rate'], '/') !== false) {
                [$num, $den] = array_map('floatval', explode('/', (string) $stream['r_frame_rate'], 2));
                if ($den > 0) {
                    $fps = $num / $den;
                }
            } elseif (!empty($stream['avg_frame_rate']) && strpos((string) $stream['avg_frame_rate'], '/') !== false) {
                [$num, $den] = array_map('floatval', explode('/', (string) $stream['avg_frame_rate'], 2));
                if ($den > 0) {
                    $fps = $num / $den;
                }
            }
            if (!empty($stream['duration'])) {
                $duration = (float) $stream['duration'];
            }
            if (!empty($stream['nb_frames'])) {
                $nb_frames = (int) $stream['nb_frames'];
            }
            if (!empty($stream['width'])) {
                break;
            }
        }
    }

    if ($duration <= 0) {
        try {
            $duration = (float) get_video_duration($path);
        } catch (Throwable $e) {
            $duration = 0.0;
        }
    }
    if ($fps <= 0) {
        $fps = 25.0;
    }

    $frame_count = $nb_frames > 0
        ? $nb_frames
        : max(1, (int) round($duration * $fps));

    return [
        'fps' => $fps,
        'duration' => $duration,
        'frame_count' => $frame_count,
    ];
}

/**
 * Read saved in/out/rep marks from metadata (defaults from timing).
 *
 * @return array{in_frame: int, out_frame: int, representative_frame: int, fps: float, frame_count: int, duration: float}
 */
function image_sequence_video_get_marks(int $ref): array
{
    global $image_sequence_inframe_field, $image_sequence_outframe_field, $image_sequence_repframe_field;

    $timing = image_sequence_get_video_timing($ref) ?? [
        'fps' => 25.0,
        'duration' => 0.0,
        'frame_count' => 1,
    ];
    $frame_count = max(1, (int) $timing['frame_count']);

    $read = static function (int $field, int $default) use ($ref): int {
        if ($field <= 0) {
            return $default;
        }
        $raw = trim((string) get_data_by_field($ref, $field));
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return (int) $raw;
    };

    $in = $read((int) $image_sequence_inframe_field, 0);
    $out = $read((int) $image_sequence_outframe_field, $frame_count - 1);
    $rep = $read((int) $image_sequence_repframe_field, 0);

    $in = image_sequence_clamp_frame_index($in, $frame_count);
    $out = image_sequence_clamp_frame_index($out, $frame_count);
    $rep = image_sequence_clamp_frame_index($rep, $frame_count);
    if ($out < $in) {
        $tmp = $in;
        $in = $out;
        $out = $tmp;
    }

    return [
        'in_frame' => $in,
        'out_frame' => $out,
        'representative_frame' => $rep,
        'fps' => (float) $timing['fps'],
        'frame_count' => $frame_count,
        'duration' => (float) $timing['duration'],
    ];
}

/**
 * Extract one still from a video at a zero-based frame index (full resolution).
 */
function image_sequence_extract_video_still(string $video_path, int $frame_index, float $fps, string $dest_path): bool
{
    $ffmpeg = get_utility_path('ffmpeg');
    if ($ffmpeg === false || $video_path === '' || !is_file($video_path) || $dest_path === '') {
        return false;
    }

    $fps = max(0.0001, $fps);
    $frame_index = max(0, $frame_index);
    $time = $frame_index / $fps;

    $dir = dirname($dest_path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    try {
        // Seek after -i for accuracy; output a single high-quality JPEG.
        run_command(
            $ffmpeg . ' -y -i %%SRC%% -ss %%TIME%% -frames:v 1 -q:v 2 -update 1 %%DST%%',
            false,
            [
                '%%SRC%%' => new CommandPlaceholderArg($video_path, 'image_sequence_is_valid_shell_path'),
                '%%TIME%%' => new CommandPlaceholderArg(sprintf('%.6F', $time), [CommandPlaceholderArg::class, 'alwaysValid']),
                '%%DST%%' => new CommandPlaceholderArg($dest_path, 'is_valid_rs_path'),
            ]
        );
    } catch (Throwable $e) {
        debug('image_sequence_extract_video_still: ' . $e->getMessage());

        return false;
    }

    return is_file($dest_path) && filesize($dest_path) > 0;
}

/**
 * Save video in/out marks to metadata.
 *
 * @return array{ok: bool, message: string, in_frame?: int, out_frame?: int}
 */
function image_sequence_set_video_inout_frames(int $ref, int $in_frame, int $out_frame): array
{
    global $lang;

    $resource = get_resource_data($ref);
    if (!is_array($resource) || !image_sequence_is_video_resource($resource)) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_video_not_video'] ?? 'Not a video resource.',
        ];
    }

    $marks = image_sequence_video_get_marks($ref);
    $frame_count = max(1, (int) $marks['frame_count']);
    $in_frame = image_sequence_clamp_frame_index($in_frame, $frame_count);
    $out_frame = image_sequence_clamp_frame_index($out_frame, $frame_count);
    if ($out_frame < $in_frame) {
        $tmp = $in_frame;
        $in_frame = $out_frame;
        $out_frame = $tmp;
    }

    image_sequence_update_metadata_fields($ref, [
        'in_frame' => $in_frame,
        'out_frame' => $out_frame,
        'fps' => $marks['fps'],
        'duration' => $marks['duration'],
        'frame_count' => $frame_count,
    ]);

    return [
        'ok' => true,
        'message' => $lang['image_sequence_inout_set'] ?? 'In/out points updated.',
        'in_frame' => $in_frame,
        'out_frame' => $out_frame,
    ];
}

/**
 * Set representative frame for a video: extract full-res still, update poster,
 * replace managed alternative file, optionally create/replace a related Photo.
 *
 * @return array{ok: bool, message: string, frame?: int, still_ref?: int}
 */
function image_sequence_set_video_representative_frame(int $ref, int $frame_index): array
{
    global $lang, $image_sequence_photo_restype;

    $resource = get_resource_data($ref);
    if (!is_array($resource) || !image_sequence_is_video_resource($resource)) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_video_not_video'] ?? 'Not a video resource.',
        ];
    }

    $marks = image_sequence_video_get_marks($ref);
    $frame_count = max(1, (int) $marks['frame_count']);
    $fps = (float) $marks['fps'];
    $frame_index = image_sequence_clamp_frame_index($frame_index, $frame_count);

    $source = image_sequence_video_source_path($resource);
    if ($source === '') {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_video_no_file'] ?? 'Video file is missing on disk.',
        ];
    }

    $temp_dir = get_temp_dir(false, 'imgseq_video_' . $ref);
    $still_path = rtrim($temp_dir, '/') . '/rep_frame_' . $frame_index . '.jpg';
    if (!image_sequence_extract_video_still($source, $frame_index, $fps, $still_path)) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_video_extract_failed'] ?? 'Could not extract frame from video.',
        ];
    }

    image_sequence_update_metadata_fields($ref, [
        'representative_frame' => $frame_index,
        'fps' => $fps,
        'duration' => $marks['duration'],
        'frame_count' => $frame_count,
    ]);

    // Full-res still as replaceable alternative on the video asset.
    try {
        image_sequence_save_representative_alt_file($ref, $still_path, $frame_index);
    } catch (Throwable $e) {
        debug('image_sequence_set_video_representative_frame alt: ' . $e->getMessage());
    }

    // Refresh video poster / small previews from the extracted still.
    $poster_jpg = get_resource_path($ref, true, 'pre', true, 'jpg');
    @copy($still_path, $poster_jpg);
    if (file_exists($poster_jpg)) {
        foreach (['thm', 'col', 'tiny'] as $size) {
            $dest = get_resource_path($ref, true, $size, true, 'jpg');
            @copy($poster_jpg, $dest);
        }
        ps_query(
            "UPDATE resource SET has_image = 1, preview_extension = 'jpg' WHERE ref = ?",
            ['i', $ref]
        );
    }

    // Separate Photo resource (related), replaced when the rep frame changes.
    $still_ref = 0;
    try {
        $still_ref = image_sequence_save_video_still_resource($ref, $still_path, $frame_index);
    } catch (Throwable $e) {
        debug('image_sequence_set_video_representative_frame still resource: ' . $e->getMessage());
    }

    @unlink($still_path);

    // AI captions from the new still when openai_gpt is available.
    image_sequence_queue_ai_metadata($ref, true);

    $message = $lang['image_sequence_video_rep_frame_set']
        ?? 'Representative frame updated (still extracted).';

    return [
        'ok' => true,
        'message' => $message,
        'frame' => $frame_index,
        'still_ref' => $still_ref,
    ];
}

/**
 * Create or replace a related Photo resource holding the representative still.
 *
 * @return int Photo resource ref, or 0 on failure
 */
function image_sequence_save_video_still_resource(int $video_ref, string $still_path, int $frame_index): int
{
    global $lang, $image_sequence_photo_restype, $userref;

    if ($video_ref <= 0 || $still_path === '' || !is_file($still_path)) {
        return 0;
    }

    $photo_type = (int) ($image_sequence_photo_restype ?: 1);
    $existing = image_sequence_find_video_still_resource($video_ref);

    if ($existing > 0) {
        $ok = image_sequence_replace_resource_file($existing, $still_path, 'jpg');
        if ($ok) {
            image_sequence_update_video_still_title($existing, $video_ref, $frame_index);
            update_related_resource($video_ref, [$existing], true);

            return $existing;
        }
    }

    $created_by = (int) ($userref ?? 0);
    $new_ref = create_resource(
        $photo_type,
        999,
        $created_by > 0 ? $created_by : -1,
        $lang['image_sequence_video_still_created'] ?? 'Extracted from video',
        'jpg'
    );
    if ($new_ref === false || (int) $new_ref <= 0) {
        return 0;
    }
    $new_ref = (int) $new_ref;

    if (!image_sequence_replace_resource_file($new_ref, $still_path, 'jpg')) {
        delete_resource($new_ref);

        return 0;
    }

    ps_query('UPDATE resource SET archive = 0 WHERE ref = ?', ['i', $new_ref]);
    image_sequence_update_video_still_title($new_ref, $video_ref, $frame_index);
    update_related_resource($video_ref, [$new_ref], true);

    return $new_ref;
}

/**
 * Find previously extracted still resource for a video (related photo by title marker).
 */
function image_sequence_find_video_still_resource(int $video_ref): int
{
    global $view_title_field;

    $needle = 'video #' . $video_ref;
    $related = get_related_resources($video_ref);
    foreach ($related as $row) {
        $r = (int) $row;
        if ($r <= 0) {
            continue;
        }
        $data = get_resource_data($r);
        if (!is_array($data)) {
            continue;
        }
        $ext = strtolower((string) ($data['file_extension'] ?? ''));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'tif', 'tiff'], true)) {
            continue;
        }
        $title = '';
        if ((int) $view_title_field > 0) {
            $title = (string) get_data_by_field($r, (int) $view_title_field);
        }
        if (strpos($title, $needle) !== false) {
            return $r;
        }
    }

    return 0;
}

function image_sequence_update_video_still_title(int $still_ref, int $video_ref, int $frame_index): void
{
    global $lang, $view_title_field;

    if ((int) $view_title_field <= 0) {
        return;
    }
    $title = sprintf(
        $lang['image_sequence_video_still_title'] ?? 'Representative frame from video #%d (frame %d)',
        $video_ref,
        $frame_index
    );
    update_field($still_ref, (int) $view_title_field, $title);
}

/**
 * Copy a still into a resource's original filestore path and rebuild previews.
 */
function image_sequence_replace_resource_file(int $ref, string $source_path, string $extension): bool
{
    if ($ref <= 0 || !is_file($source_path)) {
        return false;
    }
    $extension = strtolower($extension);
    $dest = get_resource_path($ref, true, '', true, $extension);
    if (!@copy($source_path, $dest) || !is_file($dest)) {
        return false;
    }

    ps_query(
        "UPDATE resource SET file_extension = ?, preview_extension = 'jpg', no_file = 0, file_modified = NOW(), has_image = 1 WHERE ref = ?",
        ['s', $extension, 'i', $ref]
    );
    unset($GLOBALS['get_resource_data_cache'][$ref], $GLOBALS['get_resource_path_fpcache'][$ref]);

    try {
        create_previews($ref, false, $extension, false, false);
    } catch (Throwable $e) {
        // At least copy poster sizes from the still.
        $poster = get_resource_path($ref, true, 'pre', true, 'jpg');
        @copy($source_path, $poster);
        foreach (['thm', 'col', 'tiny'] as $size) {
            @copy($source_path, get_resource_path($ref, true, $size, true, 'jpg'));
        }
        debug('image_sequence_replace_resource_file previews: ' . $e->getMessage());
    }
    update_disk_usage($ref);

    return true;
}
