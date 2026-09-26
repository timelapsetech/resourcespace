<?php

use Montala\ResourceSpace\CommandPlaceholderArg;

/**
 * Framing boxes: aspect-locked crop regions stored in source pixels, plus 4K renders.
 */

/**
 * Ensure resource_framing_box exists (CheckDBStruct also creates from dbstruct).
 */
function image_sequence_ensure_framing_table(): void
{
    $exists = (int) ps_value(
        "SELECT COUNT(*) value FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'resource_framing_box'",
        [],
        0
    );
    if ($exists > 0) {
        return;
    }

    ps_query(
        "CREATE TABLE resource_framing_box (
            ref int(11) NOT NULL AUTO_INCREMENT,
            resource int(11) NOT NULL,
            label varchar(255) DEFAULT NULL,
            aspect_w int(11) NOT NULL,
            aspect_h int(11) NOT NULL,
            x int(11) NOT NULL DEFAULT 0,
            y int(11) NOT NULL DEFAULT 0,
            width int(11) NOT NULL DEFAULT 0,
            height int(11) NOT NULL DEFAULT 0,
            source_width int(11) NOT NULL DEFAULT 0,
            source_height int(11) NOT NULL DEFAULT 0,
            alt_file int(11) DEFAULT NULL,
            render_status varchar(20) DEFAULT NULL,
            render_message varchar(500) DEFAULT NULL,
            created_by int(11) DEFAULT NULL,
            created datetime DEFAULT CURRENT_TIMESTAMP,
            modified timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (ref),
            KEY resource (resource)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        [],
        '',
        -1,
        false
    );
}

/**
 * Configured aspect presets: label => [w, h].
 *
 * @return array<string, array{0: int, 1: int}>
 */
function image_sequence_framing_aspect_presets(): array
{
    global $image_sequence_framing_aspects;

    $defaults = [
        '16:9' => [16, 9],
        '9:16' => [9, 16],
        '1:1' => [1, 1],
        '4:5' => [4, 5],
        '2.39:1' => [239, 100],
    ];
    if (!is_array($image_sequence_framing_aspects) || $image_sequence_framing_aspects === []) {
        return $defaults;
    }

    $out = [];
    foreach ($image_sequence_framing_aspects as $label => $pair) {
        if (!is_array($pair) || count($pair) < 2) {
            continue;
        }
        $w = (int) $pair[0];
        $h = (int) $pair[1];
        if ($w > 0 && $h > 0) {
            $out[(string) $label] = [$w, $h];
        }
    }

    return $out !== [] ? $out : $defaults;
}

function image_sequence_framing_default_aspect_label(): string
{
    global $image_sequence_framing_default_aspect;

    $presets = image_sequence_framing_aspect_presets();
    $label = (string) ($image_sequence_framing_default_aspect ?? '16:9');
    if (isset($presets[$label])) {
        return $label;
    }

    return (string) array_key_first($presets);
}

/**
 * Largest even WxH of aspect_w:aspect_h that fits inside container_w x container_h.
 *
 * @return array{width: int, height: int}
 */
function image_sequence_framing_fit_aspect(int $aspect_w, int $aspect_h, int $container_w, int $container_h): array
{
    $aspect_w = max(1, $aspect_w);
    $aspect_h = max(1, $aspect_h);
    $container_w = max(1, $container_w);
    $container_h = max(1, $container_h);

    // Fit by width first, then clamp by height.
    $width = $container_w;
    $height = (int) round($width * $aspect_h / $aspect_w);
    if ($height > $container_h) {
        $height = $container_h;
        $width = (int) round($height * $aspect_w / $aspect_h);
    }

    // Even dimensions for yuv420p.
    $width -= ($width % 2);
    $height -= ($height % 2);
    $width = max(2, $width);
    $height = max(2, $height);

    return ['width' => $width, 'height' => $height];
}

/**
 * 4K / 1080p delivery targets for a given aspect (portrait uses tall UHD/FHD).
 *
 * @return array{uhd: array{width: int, height: int}, fhd: array{width: int, height: int}}
 */
function image_sequence_framing_delivery_targets(int $aspect_w, int $aspect_h): array
{
    $portrait = $aspect_h > $aspect_w;
    $uhd_box = $portrait ? [2160, 3840] : [3840, 2160];
    $fhd_box = $portrait ? [1080, 1920] : [1920, 1080];

    return [
        'uhd' => image_sequence_framing_fit_aspect($aspect_w, $aspect_h, $uhd_box[0], $uhd_box[1]),
        'fhd' => image_sequence_framing_fit_aspect($aspect_w, $aspect_h, $fhd_box[0], $fhd_box[1]),
    ];
}

/**
 * Tier for a box width against delivery targets: ok | warn | low.
 */
function image_sequence_framing_tier(int $box_width, int $aspect_w, int $aspect_h): string
{
    $targets = image_sequence_framing_delivery_targets($aspect_w, $aspect_h);
    $uhd_w = (int) $targets['uhd']['width'];
    $fhd_w = (int) $targets['fhd']['width'];

    if ($box_width >= $uhd_w) {
        return 'ok';
    }
    if ($box_width >= $fhd_w) {
        return 'warn';
    }

    return 'low';
}

/**
 * Source pixel dimensions for a sequence or video resource (correctly oriented).
 *
 * @return array{width: int, height: int}
 */
/**
 * Read width/height from resource_dimensions if present.
 *
 * @return array{width: int, height: int}
 */
function image_sequence_resource_dimensions_row(int $ref): array
{
    if ($ref <= 0) {
        return ['width' => 0, 'height' => 0];
    }
    $dims = ps_query(
        'SELECT width, height FROM resource_dimensions WHERE resource = ? LIMIT 1',
        ['i', $ref]
    );
    return [
        'width' => (int) ($dims[0]['width'] ?? 0),
        'height' => (int) ($dims[0]['height'] ?? 0),
    ];
}

/**
 * Probe a video/still path for display width/height (respecting rotation).
 *
 * @return array{width: int, height: int}
 */
function image_sequence_probe_media_dimensions(string $path): array
{
    if ($path === '' || !is_file($path)) {
        return ['width' => 0, 'height' => 0];
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'gif', 'webp', 'bmp'], true)) {
        $info = @getimagesize($path);
        if (is_array($info) && (int) ($info[0] ?? 0) > 0 && (int) ($info[1] ?? 0) > 0) {
            return ['width' => (int) $info[0], 'height' => (int) $info[1]];
        }
    }

    try {
        $info = get_video_info($path);
    } catch (Throwable $e) {
        debug('image_sequence_probe_media_dimensions: ' . $e->getMessage());
        $info = null;
    }
    if (!is_array($info) || empty($info['streams']) || !is_array($info['streams'])) {
        // Last resort for stills ffprobe couldn't read.
        $info2 = @getimagesize($path);
        if (is_array($info2) && (int) ($info2[0] ?? 0) > 0) {
            return ['width' => (int) $info2[0], 'height' => (int) $info2[1]];
        }

        return ['width' => 0, 'height' => 0];
    }

    foreach ($info['streams'] as $stream) {
        if (($stream['codec_type'] ?? '') !== 'video' && ($stream['codec_type'] ?? '') !== '') {
            continue;
        }
        $width = (int) ($stream['width'] ?? 0);
        $height = (int) ($stream['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            continue;
        }
        $rotation = image_sequence_video_stream_rotation($stream);
        if ($rotation === 90 || $rotation === 270 || $rotation === -90 || $rotation === -270) {
            return ['width' => $height, 'height' => $width];
        }

        return ['width' => $width, 'height' => $height];
    }

    return ['width' => 0, 'height' => 0];
}

function image_sequence_source_dimensions(array $resource): array
{
    global $ffmpeg_preview_extension;

    $ref = (int) ($resource['ref'] ?? 0);
    if ($ref <= 0) {
        return ['width' => 0, 'height' => 0];
    }

    // Prefer catalogued dimensions when they look real.
    $row = image_sequence_resource_dimensions_row($ref);
    if ($row['width'] > 0 && $row['height'] > 0) {
        return $row;
    }

    if (image_sequence_is_sequence_resource($resource)) {
        $data = image_sequence_get_data($ref);
        if ($data !== null) {
            // Prefer representative / mid frame over first (first can be black/slate).
            $rep = (int) ($data['representative_frame'] ?? 0);
            foreach ([$rep, 0, (int) floor(((int) ($data['frame_count'] ?? 1)) / 2)] as $idx) {
                $path = image_sequence_member_path_at($data, max(0, $idx));
                if ($path === null) {
                    continue;
                }
                $probed = image_sequence_probe_media_dimensions($path);
                if ($probed['width'] > 0 && $probed['height'] > 0) {
                    return $probed;
                }
            }
        }

        // Full-res representative still / poster in filestore (often unscaled).
        $still = image_sequence_get_representative_still_path($ref);
        if ($still !== '') {
            $probed = image_sequence_probe_media_dimensions($still);
            if ($probed['width'] > 0 && $probed['height'] > 0) {
                return $probed;
            }
        }
        $poster = get_resource_path($ref, true, 'pre', false, 'jpg');
        if (is_string($poster) && is_file($poster)) {
            $probed = image_sequence_probe_media_dimensions($poster);
            if ($probed['width'] > 0 && $probed['height'] > 0) {
                return $probed;
            }
        }

        // Last resort: proxy video intrinsic size (UI can still frame; render scales up).
        $ext = $ffmpeg_preview_extension ?: 'mp4';
        $proxy = get_resource_path($ref, true, 'pre', false, $ext);
        if (is_string($proxy) && is_file($proxy)) {
            return image_sequence_probe_media_dimensions($proxy);
        }

        return ['width' => 0, 'height' => 0];
    }

    if (!image_sequence_is_video_resource($resource)) {
        return ['width' => 0, 'height' => 0];
    }

    foreach (
        [
            image_sequence_video_source_path($resource),
            image_sequence_video_playback_path($resource),
        ] as $path
    ) {
        $probed = image_sequence_probe_media_dimensions($path);
        if ($probed['width'] > 0 && $probed['height'] > 0) {
            return $probed;
        }
    }

    return ['width' => 0, 'height' => 0];
}

/**
 * Scale a box from one source size to another (e.g. proxy → full-res).
 *
 * @param array{x: int, y: int, width: int, height: int, aspect_w: int, aspect_h: int} $box
 * @return array{x: int, y: int, width: int, height: int, aspect_w: int, aspect_h: int, source_width: int, source_height: int}
 */
function image_sequence_framing_scale_box_to_source(array $box, int $from_w, int $from_h, int $to_w, int $to_h): array
{
    if ($from_w <= 0 || $from_h <= 0 || $to_w <= 0 || $to_h <= 0) {
        return image_sequence_framing_normalize_box($box, max(2, $to_w), max(2, $to_h));
    }
    if ($from_w === $to_w && $from_h === $to_h) {
        return image_sequence_framing_normalize_box($box, $to_w, $to_h);
    }

    $sx = $to_w / $from_w;
    $sy = $to_h / $from_h;
    $scaled = [
        'aspect_w' => (int) ($box['aspect_w'] ?? 16),
        'aspect_h' => (int) ($box['aspect_h'] ?? 9),
        'x' => (int) round(((int) ($box['x'] ?? 0)) * $sx),
        'y' => (int) round(((int) ($box['y'] ?? 0)) * $sy),
        'width' => (int) round(((int) ($box['width'] ?? 0)) * $sx),
        'height' => (int) round(((int) ($box['height'] ?? 0)) * $sy),
    ];

    return image_sequence_framing_normalize_box($scaled, $to_w, $to_h);
}

/**
 * Extract display rotation degrees from an ffprobe video stream.
 */
function image_sequence_video_stream_rotation(array $stream): int
{
    if (isset($stream['tags']['rotate']) && is_numeric($stream['tags']['rotate'])) {
        return (int) $stream['tags']['rotate'];
    }

    $side_data = $stream['side_data_list'] ?? [];
    if (!is_array($side_data)) {
        return 0;
    }
    foreach ($side_data as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (isset($entry['rotation']) && is_numeric($entry['rotation'])) {
            return (int) round((float) $entry['rotation']);
        }
    }

    return 0;
}

/**
 * Normalize / clamp a box to source bounds and enforce aspect (height from width).
 *
 * @param array{
 *   x?: int, y?: int, width?: int, height?: int,
 *   aspect_w?: int, aspect_h?: int,
 *   source_width?: int, source_height?: int
 * } $box
 * @return array{x: int, y: int, width: int, height: int, aspect_w: int, aspect_h: int, source_width: int, source_height: int}
 */
function image_sequence_framing_normalize_box(array $box, int $source_width, int $source_height): array
{
    $source_width = max(2, $source_width);
    $source_height = max(2, $source_height);
    $aspect_w = max(1, (int) ($box['aspect_w'] ?? 16));
    $aspect_h = max(1, (int) ($box['aspect_h'] ?? 9));

    $max_fit = image_sequence_framing_fit_aspect($aspect_w, $aspect_h, $source_width, $source_height);
    $width = (int) ($box['width'] ?? $max_fit['width']);
    $width = max(2, min($width, $max_fit['width']));
    $width -= ($width % 2);
    $width = max(2, $width);

    $height = (int) round($width * $aspect_h / $aspect_w);
    $height -= ($height % 2);
    $height = max(2, $height);
    if ($height > $source_height) {
        $height = $source_height - ($source_height % 2);
        $height = max(2, $height);
        $width = (int) round($height * $aspect_w / $aspect_h);
        $width -= ($width % 2);
        $width = max(2, $width);
    }

    $x = (int) ($box['x'] ?? 0);
    $y = (int) ($box['y'] ?? 0);
    $x = max(0, min($x, $source_width - $width));
    $y = max(0, min($y, $source_height - $height));
    // Keep even origins for chroma alignment.
    $x -= ($x % 2);
    $y -= ($y % 2);
    $x = max(0, $x);
    $y = max(0, $y);

    return [
        'x' => $x,
        'y' => $y,
        'width' => $width,
        'height' => $height,
        'aspect_w' => $aspect_w,
        'aspect_h' => $aspect_h,
        'source_width' => $source_width,
        'source_height' => $source_height,
    ];
}

/**
 * Public API shape for one framing box (includes tier + aspect label).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function image_sequence_framing_serialize_box(array $row): array
{
    $aspect_w = (int) ($row['aspect_w'] ?? 16);
    $aspect_h = (int) ($row['aspect_h'] ?? 9);
    $width = (int) ($row['width'] ?? 0);
    $tier = image_sequence_framing_tier($width, $aspect_w, $aspect_h);
    $targets = image_sequence_framing_delivery_targets($aspect_w, $aspect_h);

    $aspect_label = image_sequence_framing_aspect_label_for($aspect_w, $aspect_h);
    $alt_file = (int) ($row['alt_file'] ?? 0);
    $resource = (int) ($row['resource'] ?? 0);

    $alt_url = '';
    if ($alt_file > 0 && $resource > 0) {
        $alt = get_alternative_file($resource, $alt_file);
        if (is_array($alt)) {
            $ext = (string) ($alt['file_extension'] ?? 'mp4');
            $alt_url = (string) get_resource_path($resource, false, '', false, $ext, true, 1, false, '', $alt_file);
        }
    }

    return [
        'ref' => (int) ($row['ref'] ?? 0),
        'resource' => $resource,
        'label' => (string) ($row['label'] ?? ''),
        'aspect_w' => $aspect_w,
        'aspect_h' => $aspect_h,
        'aspect_label' => $aspect_label,
        'x' => (int) ($row['x'] ?? 0),
        'y' => (int) ($row['y'] ?? 0),
        'width' => $width,
        'height' => (int) ($row['height'] ?? 0),
        'source_width' => (int) ($row['source_width'] ?? 0),
        'source_height' => (int) ($row['source_height'] ?? 0),
        'tier' => $tier,
        'target_uhd_width' => (int) $targets['uhd']['width'],
        'target_uhd_height' => (int) $targets['uhd']['height'],
        'target_fhd_width' => (int) $targets['fhd']['width'],
        'target_fhd_height' => (int) $targets['fhd']['height'],
        'alt_file' => $alt_file > 0 ? $alt_file : null,
        'alt_url' => $alt_url,
        'render_status' => (string) ($row['render_status'] ?? ''),
        'render_message' => (string) ($row['render_message'] ?? ''),
    ];
}

function image_sequence_framing_aspect_label_for(int $aspect_w, int $aspect_h): string
{
    foreach (image_sequence_framing_aspect_presets() as $label => $pair) {
        if ((int) $pair[0] === $aspect_w && (int) $pair[1] === $aspect_h) {
            return (string) $label;
        }
    }

    return $aspect_w . ':' . $aspect_h;
}

/**
 * @return list<array<string, mixed>>
 */
function image_sequence_framing_get_boxes(int $resource): array
{
    image_sequence_ensure_framing_table();
    if ($resource <= 0) {
        return [];
    }

    $rows = ps_query(
        'SELECT * FROM resource_framing_box WHERE resource = ? ORDER BY ref ASC',
        ['i', $resource]
    );
    $out = [];
    foreach ($rows as $row) {
        $out[] = image_sequence_framing_serialize_box($row);
    }

    return $out;
}

/**
 * @param array{
 *   ref?: int,
 *   label?: string,
 *   aspect_w: int,
 *   aspect_h: int,
 *   x: int,
 *   y: int,
 *   width: int,
 *   height?: int
 * } $input
 * @return array{ok: bool, message: string, box?: array<string, mixed>}
 */
function image_sequence_framing_save_box(int $resource, array $input): array
{
    global $lang, $userref;

    image_sequence_ensure_framing_table();

    $resource_data = get_resource_data($resource);
    if (!is_array($resource_data)) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_no_data'] ?? 'Resource not found.',
        ];
    }
    if (
        !image_sequence_is_sequence_resource($resource_data)
        && !image_sequence_is_video_resource($resource_data)
    ) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_no_data'] ?? 'Not an image sequence or video resource.',
        ];
    }

    $dims = image_sequence_source_dimensions($resource_data);
    $client_sw = (int) ($input['source_width'] ?? 0);
    $client_sh = (int) ($input['source_height'] ?? 0);

    // Prefer real source dims; fall back to client-reported (proxy) size so UI still works.
    $target_w = $dims['width'] > 0 ? $dims['width'] : $client_sw;
    $target_h = $dims['height'] > 0 ? $dims['height'] : $client_sh;
    if ($target_w <= 0 || $target_h <= 0) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_framing_no_dims'] ?? 'Could not determine source dimensions.',
        ];
    }

    if ($client_sw > 0 && $client_sh > 0 && ($client_sw !== $target_w || $client_sh !== $target_h)) {
        $normalized = image_sequence_framing_scale_box_to_source($input, $client_sw, $client_sh, $target_w, $target_h);
    } else {
        $normalized = image_sequence_framing_normalize_box($input, $target_w, $target_h);
    }
    $label = trim((string) ($input['label'] ?? ''));
    if ($label === '') {
        $label = image_sequence_framing_aspect_label_for($normalized['aspect_w'], $normalized['aspect_h']);
    }
    $label = mb_substr($label, 0, 255);

    $box_ref = (int) ($input['ref'] ?? 0);
    $created_by = (int) ($userref ?? 0);

    if ($box_ref > 0) {
        $existing = ps_query(
            'SELECT ref FROM resource_framing_box WHERE ref = ? AND resource = ?',
            ['i', $box_ref, 'i', $resource]
        );
        if ($existing === []) {
            return [
                'ok' => false,
                'message' => $lang['image_sequence_framing_not_found'] ?? 'Framing box not found.',
            ];
        }
        ps_query(
            'UPDATE resource_framing_box SET
                label = ?, aspect_w = ?, aspect_h = ?,
                x = ?, y = ?, width = ?, height = ?,
                source_width = ?, source_height = ?,
                modified = NOW()
             WHERE ref = ? AND resource = ?',
            [
                's', $label,
                'i', $normalized['aspect_w'],
                'i', $normalized['aspect_h'],
                'i', $normalized['x'],
                'i', $normalized['y'],
                'i', $normalized['width'],
                'i', $normalized['height'],
                'i', $normalized['source_width'],
                'i', $normalized['source_height'],
                'i', $box_ref,
                'i', $resource,
            ]
        );
    } else {
        ps_query(
            'INSERT INTO resource_framing_box
                (resource, label, aspect_w, aspect_h, x, y, width, height,
                 source_width, source_height, created_by, created, modified)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                'i', $resource,
                's', $label,
                'i', $normalized['aspect_w'],
                'i', $normalized['aspect_h'],
                'i', $normalized['x'],
                'i', $normalized['y'],
                'i', $normalized['width'],
                'i', $normalized['height'],
                'i', $normalized['source_width'],
                'i', $normalized['source_height'],
                'i', $created_by > 0 ? $created_by : 0,
            ]
        );
        $box_ref = sql_insert_id();
    }

    $rows = ps_query('SELECT * FROM resource_framing_box WHERE ref = ?', ['i', $box_ref]);
    if ($rows === []) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_framing_save_failed'] ?? 'Could not save framing box.',
        ];
    }

    return [
        'ok' => true,
        'message' => $lang['image_sequence_framing_saved'] ?? 'Framing box saved.',
        'box' => image_sequence_framing_serialize_box($rows[0]),
    ];
}

/**
 * @return array{ok: bool, message: string}
 */
function image_sequence_framing_delete_box(int $resource, int $box_ref): array
{
    global $lang;

    image_sequence_ensure_framing_table();
    if ($resource <= 0 || $box_ref <= 0) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_framing_not_found'] ?? 'Framing box not found.',
        ];
    }

    $rows = ps_query(
        'SELECT * FROM resource_framing_box WHERE ref = ? AND resource = ?',
        ['i', $box_ref, 'i', $resource]
    );
    if ($rows === []) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_framing_not_found'] ?? 'Framing box not found.',
        ];
    }

    $alt_file = (int) ($rows[0]['alt_file'] ?? 0);
    if ($alt_file > 0) {
        delete_alternative_file($resource, $alt_file);
    }

    ps_query(
        'DELETE FROM resource_framing_box WHERE ref = ? AND resource = ?',
        ['i', $box_ref, 'i', $resource]
    );

    return [
        'ok' => true,
        'message' => $lang['image_sequence_framing_deleted'] ?? 'Framing box deleted.',
    ];
}

function image_sequence_framing_alt_type(int $box_ref): string
{
    return 'image_sequence_framing_' . max(0, $box_ref);
}

/**
 * Queue (or run inline) a 4K crop render for one framing box.
 *
 * @return array{ok: bool, message: string, box?: array<string, mixed>}
 */
function image_sequence_framing_queue_render(int $resource, int $box_ref): array
{
    global $lang, $offline_job_queue, $userref, $baseurl;

    image_sequence_ensure_framing_table();

    $rows = ps_query(
        'SELECT * FROM resource_framing_box WHERE ref = ? AND resource = ?',
        ['i', $box_ref, 'i', $resource]
    );
    if ($rows === []) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_framing_not_found'] ?? 'Framing box not found.',
        ];
    }

    ps_query(
        "UPDATE resource_framing_box SET render_status = 'queued', render_message = ? WHERE ref = ?",
        [
            's', $lang['image_sequence_framing_render_queued'] ?? 'Render queued…',
            'i', $box_ref,
        ]
    );

    $job_data = [
        'resource' => $resource,
        'box_ref' => $box_ref,
    ];
    $success = $lang['image_sequence_framing_render_ready'] ?? 'Framing render ready';
    $failure = $lang['image_sequence_framing_render_failed'] ?? 'Framing render failed';

    if (!empty($offline_job_queue)) {
        $job_user = (int) ($userref ?? 0);
        if ($job_user <= 0) {
            $job_user = image_sequence_default_job_user();
        }
        job_queue_add(
            'image_sequence_framing_render',
            $job_data,
            (string) $job_user,
            '',
            $success,
            $failure,
            'imgseq_framing_' . $box_ref
        );
    } else {
        $ok = image_sequence_render_framing_box($box_ref);
        if (!$ok) {
            $rows = ps_query('SELECT * FROM resource_framing_box WHERE ref = ?', ['i', $box_ref]);
            $msg = (string) ($rows[0]['render_message'] ?? $failure);

            return [
                'ok' => false,
                'message' => $msg,
                'box' => $rows !== [] ? image_sequence_framing_serialize_box($rows[0]) : null,
            ];
        }
    }

    $rows = ps_query('SELECT * FROM resource_framing_box WHERE ref = ?', ['i', $box_ref]);

    return [
        'ok' => true,
        'message' => $lang['image_sequence_framing_render_queued'] ?? 'Render queued…',
        'box' => $rows !== [] ? image_sequence_framing_serialize_box($rows[0]) : null,
        'view_url' => rtrim((string) $baseurl, '/') . '/pages/view.php?ref=' . $resource,
    ];
}

/**
 * Delete all framing boxes (and alt files) for a resource.
 */
function image_sequence_framing_cleanup_resource(int $ref): void
{
    image_sequence_ensure_framing_table();
    if ($ref <= 0) {
        return;
    }

    $exists = (int) ps_value(
        "SELECT COUNT(*) value FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'resource_framing_box'",
        [],
        0
    );
    if ($exists === 0) {
        return;
    }

    $rows = ps_query(
        'SELECT ref, alt_file FROM resource_framing_box WHERE resource = ?',
        ['i', $ref]
    );
    foreach ($rows as $row) {
        $alt = (int) ($row['alt_file'] ?? 0);
        if ($alt > 0) {
            delete_alternative_file($ref, $alt);
        }
    }
    ps_query('DELETE FROM resource_framing_box WHERE resource = ?', ['i', $ref]);
}

/**
 * Encode options for framing renders.
 */
function image_sequence_framing_encode_options(): string
{
    global $image_sequence_framing_render_options;

    $custom = trim((string) ($image_sequence_framing_render_options ?? ''));
    if ($custom !== '') {
        return $custom;
    }

    return '-c:v libx264 -crf 16 -preset slow -pix_fmt yuv420p -movflags +faststart';
}

/**
 * Save rendered mp4 as a replaceable alternative file for this box.
 *
 * @return int Alternative file ref, or 0 on failure
 */
function image_sequence_framing_save_alt_file(int $resource, int $box_ref, string $mp4_path, string $label): int
{
    global $lang;

    if ($resource <= 0 || $box_ref <= 0 || $mp4_path === '' || !is_file($mp4_path)) {
        return 0;
    }

    $extension = 'mp4';
    $basename = 'framing_' . $box_ref . '.mp4';
    $alt_name = $label !== '' ? $label : ('Framing ' . $box_ref);
    $alt_type = image_sequence_framing_alt_type($box_ref);
    $description = sprintf(
        $lang['image_sequence_framing_alt_desc'] ?? '4K framing crop render (box #%d)',
        $box_ref
    );

    $existing = ps_query(
        'SELECT ref FROM resource_alt_files WHERE resource = ? AND alt_type = ?',
        ['i', $resource, 's', $alt_type]
    );
    foreach ($existing as $row) {
        delete_alternative_file($resource, (int) $row['ref']);
    }

    // Also clear any stale alt_file pointer on the box.
    $box_alt = ps_query(
        'SELECT alt_file FROM resource_framing_box WHERE ref = ? AND resource = ?',
        ['i', $box_ref, 'i', $resource]
    );
    $prev = (int) ($box_alt[0]['alt_file'] ?? 0);
    if ($prev > 0) {
        $still = get_alternative_file($resource, $prev);
        if (is_array($still)) {
            delete_alternative_file($resource, $prev);
        }
    }

    $aref = (int) add_alternative_file(
        $resource,
        $alt_name,
        $description,
        $basename,
        $extension,
        0,
        $alt_type
    );
    if ($aref <= 0) {
        return 0;
    }

    $dest = get_resource_path($resource, true, '', true, $extension, -1, 1, false, '', $aref);
    if (!@copy($mp4_path, $dest) || !is_file($dest)) {
        delete_alternative_file($resource, $aref);
        debug("image_sequence_framing_save_alt_file: failed to copy {$mp4_path} → {$dest}");

        return 0;
    }

    $file_size = filesize_unlimited($dest);
    ps_query(
        'UPDATE resource_alt_files
            SET file_name = ?, file_extension = ?, file_size = ?, description = ?, alt_type = ?, creation_date = NOW()
          WHERE resource = ? AND ref = ?',
        [
            's', $basename,
            's', $extension,
            'i', $file_size,
            's', $description,
            's', $alt_type,
            'i', $resource,
            'i', $aref,
        ]
    );
    update_disk_usage($resource);

    return $aref;
}

/**
 * Render one framing box: crop in→out to 4K delivery size, store as alt file.
 */
function image_sequence_render_framing_box(int $box_ref): bool
{
    global $lang;

    image_sequence_ensure_framing_table();
    $rows = ps_query('SELECT * FROM resource_framing_box WHERE ref = ?', ['i', $box_ref]);
    if ($rows === []) {
        return false;
    }
    $box = $rows[0];
    $resource = (int) ($box['resource'] ?? 0);
    $resource_data = get_resource_data($resource);
    if (!is_array($resource_data)) {
        image_sequence_framing_set_render_status($box_ref, 'failed', 'Resource not found.');

        return false;
    }

    ps_query(
        "UPDATE resource_framing_box SET render_status = 'processing', render_message = ? WHERE ref = ?",
        [
            's', $lang['image_sequence_framing_render_processing'] ?? 'Rendering…',
            'i', $box_ref,
        ]
    );

    $ffmpeg = get_utility_path('ffmpeg');
    if ($ffmpeg === false) {
        image_sequence_framing_set_render_status(
            $box_ref,
            'failed',
            $lang['image_sequence_framing_no_ffmpeg'] ?? 'FFmpeg is not available.'
        );

        return false;
    }

    $x = (int) $box['x'];
    $y = (int) $box['y'];
    $w = (int) $box['width'];
    $h = (int) $box['height'];
    $aspect_w = (int) $box['aspect_w'];
    $aspect_h = (int) $box['aspect_h'];
    $stored_sw = (int) ($box['source_width'] ?? 0);
    $stored_sh = (int) ($box['source_height'] ?? 0);

    // Scale stored coords up if they were captured against a proxy-sized frame.
    $actual = image_sequence_source_dimensions($resource_data);
    if (
        $actual['width'] > 0
        && $actual['height'] > 0
        && $stored_sw > 0
        && $stored_sh > 0
        && ($stored_sw !== $actual['width'] || $stored_sh !== $actual['height'])
    ) {
        $scaled = image_sequence_framing_scale_box_to_source(
            [
                'aspect_w' => $aspect_w,
                'aspect_h' => $aspect_h,
                'x' => $x,
                'y' => $y,
                'width' => $w,
                'height' => $h,
            ],
            $stored_sw,
            $stored_sh,
            $actual['width'],
            $actual['height']
        );
        $x = $scaled['x'];
        $y = $scaled['y'];
        $w = $scaled['width'];
        $h = $scaled['height'];
        $box['x'] = $x;
        $box['y'] = $y;
        $box['width'] = $w;
        $box['height'] = $h;
    }

    if ($w < 2 || $h < 2) {
        image_sequence_framing_set_render_status($box_ref, 'failed', 'Invalid box size.');

        return false;
    }

    $targets = image_sequence_framing_delivery_targets($aspect_w, $aspect_h);
    $tw = (int) $targets['uhd']['width'];
    $th = (int) $targets['uhd']['height'];

    $temp_dir = get_temp_dir(false, 'imgseq_framing_' . $box_ref);
    $out_path = rtrim($temp_dir, '/') . '/framing_' . $box_ref . '.mp4';
    $encode_opts = image_sequence_framing_encode_options();

    $is_sequence = image_sequence_is_sequence_resource($resource_data);
    $is_video = image_sequence_is_video_resource($resource_data);

    try {
        if ($is_sequence) {
            $ok = image_sequence_framing_render_sequence(
                $resource,
                $box,
                $tw,
                $th,
                $out_path,
                $encode_opts
            );
        } elseif ($is_video) {
            $ok = image_sequence_framing_render_video(
                $resource_data,
                $box,
                $tw,
                $th,
                $out_path,
                $encode_opts
            );
        } else {
            $ok = false;
        }
    } catch (Throwable $e) {
        debug('image_sequence_render_framing_box: ' . $e->getMessage());
        image_sequence_framing_set_render_status($box_ref, 'failed', $e->getMessage());

        return false;
    }

    if (!$ok || !is_file($out_path) || filesize($out_path) <= 0) {
        image_sequence_framing_set_render_status(
            $box_ref,
            'failed',
            $lang['image_sequence_framing_render_failed'] ?? 'Framing render failed.'
        );
        @unlink($out_path);

        return false;
    }

    $label = (string) ($box['label'] ?? '');
    $aref = image_sequence_framing_save_alt_file($resource, $box_ref, $out_path, $label);
    @unlink($out_path);

    if ($aref <= 0) {
        image_sequence_framing_set_render_status(
            $box_ref,
            'failed',
            $lang['image_sequence_framing_alt_failed'] ?? 'Could not store render as alternative file.'
        );

        return false;
    }

    ps_query(
        "UPDATE resource_framing_box
            SET alt_file = ?, render_status = 'ready', render_message = ?, modified = NOW()
          WHERE ref = ?",
        [
            'i', $aref,
            's', $lang['image_sequence_framing_render_ready'] ?? 'Render ready.',
            'i', $box_ref,
        ]
    );

    return true;
}

function image_sequence_framing_set_render_status(int $box_ref, string $status, string $message): void
{
    ps_query(
        'UPDATE resource_framing_box SET render_status = ?, render_message = ?, modified = NOW() WHERE ref = ?',
        ['s', $status, 's', mb_substr($message, 0, 500), 'i', $box_ref]
    );
}

/**
 * Build crop+scale(+orientation) video filter for a framing box.
 */
function image_sequence_framing_vf(array $box, int $target_w, int $target_h, string $orient_vf = ''): string
{
    $x = (int) $box['x'];
    $y = (int) $box['y'];
    $w = (int) $box['width'];
    $h = (int) $box['height'];
    $crop = "crop={$w}:{$h}:{$x}:{$y}";
    $scale = "scale={$target_w}:{$target_h}:flags=lanczos,setsar=1";
    $parts = [];
    if ($orient_vf !== '') {
        $parts[] = $orient_vf;
    }
    $parts[] = $crop;
    $parts[] = $scale;

    return implode(',', $parts);
}

/**
 * Render framing crop from an image-sequence resource (in→out member frames).
 */
function image_sequence_framing_render_sequence(
    int $ref,
    array $box,
    int $target_w,
    int $target_h,
    string $out_path,
    string $encode_opts
): bool {
    $ffmpeg = get_utility_path('ffmpeg');
    if ($ffmpeg === false) {
        return false;
    }

    $data = image_sequence_get_data($ref);
    if ($data === null) {
        return false;
    }

    $paths = image_sequence_member_absolute_paths($data);
    if ($paths === []) {
        return false;
    }

    $frame_count = count($paths);
    $fps = (float) ($data['fps'] ?: 30);
    $in_frame = isset($data['in_frame']) && $data['in_frame'] !== null && $data['in_frame'] !== ''
        ? (int) $data['in_frame']
        : 0;
    $out_frame = isset($data['out_frame']) && $data['out_frame'] !== null && $data['out_frame'] !== ''
        ? (int) $data['out_frame']
        : max(0, $frame_count - 1);
    $in_frame = image_sequence_clamp_frame_index($in_frame, $frame_count);
    $out_frame = image_sequence_clamp_frame_index($out_frame, $frame_count);
    if ($out_frame < $in_frame) {
        $tmp = $in_frame;
        $in_frame = $out_frame;
        $out_frame = $tmp;
    }
    $n_frames = $out_frame - $in_frame + 1;

    $orient_vf = image_sequence_orientation_vf($ref);
    $vf = image_sequence_framing_vf($box, $target_w, $target_h, $orient_vf);

    $temp_dir = dirname($out_path);
    $ok = false;

    $pattern = (string) ($data['frame_pattern'] ?? '');
    $folder_abs = image_sequence_relative_to_absolute((string) $data['folder_path']);
    $start_number = (int) ($data['start_number'] ?? 0);

    if ($pattern !== '' && $folder_abs !== null && $start_number > 0) {
        $input = rtrim($folder_abs, '/') . '/' . $pattern;
        $start = $start_number + $in_frame;
        $cmd = $ffmpeg . ' -hide_banner -loglevel error -y -noautorotate'
            . ' -framerate %%FPS%% -start_number %%START%% -i %%INPUT%%'
            . ' -frames:v %%NFRAMES%% ' . $encode_opts . ' -an -vf %%VF%% %%TARGET%%';
        $params = [
            '%%FPS%%' => new CommandPlaceholderArg((string) $fps, [CommandPlaceholderArg::class, 'alwaysValid']),
            '%%START%%' => new CommandPlaceholderArg((string) $start, 'is_int_loose'),
            '%%INPUT%%' => new CommandPlaceholderArg($input, [CommandPlaceholderArg::class, 'alwaysValid']),
            '%%NFRAMES%%' => new CommandPlaceholderArg((string) $n_frames, 'is_int_loose'),
            '%%VF%%' => new CommandPlaceholderArg($vf, [CommandPlaceholderArg::class, 'alwaysValid']),
            '%%TARGET%%' => new CommandPlaceholderArg($out_path, 'is_valid_rs_path'),
        ];
        try {
            run_command($cmd, false, $params);
            $ok = is_file($out_path) && filesize($out_path) > 0;
        } catch (Throwable $e) {
            debug('image_sequence_framing_render_sequence pattern: ' . $e->getMessage());
            $ok = false;
        }
    }

    if (!$ok) {
        $slice = array_slice($paths, $in_frame, $n_frames);
        if ($slice === []) {
            return false;
        }
        $list_file = $temp_dir . '/concat_framing.txt';
        $fh = fopen($list_file, 'w');
        if ($fh === false) {
            return false;
        }
        foreach ($slice as $path) {
            $escaped = str_replace("'", "'\\''", $path);
            fwrite($fh, "file '{$escaped}'\nduration " . (1 / max($fps, 0.0001)) . "\n");
        }
        $last = str_replace("'", "'\\''", $slice[count($slice) - 1]);
        fwrite($fh, "file '{$last}'\n");
        fclose($fh);

        $cmd = $ffmpeg . ' -hide_banner -loglevel error -y -noautorotate'
            . ' -f concat -safe 0 -r %%FPS%% -i %%LIST%%'
            . ' -frames:v %%NFRAMES%% ' . $encode_opts . ' -an -vf %%VF%% %%TARGET%%';
        $params = [
            '%%FPS%%' => new CommandPlaceholderArg((string) $fps, [CommandPlaceholderArg::class, 'alwaysValid']),
            '%%LIST%%' => new CommandPlaceholderArg($list_file, 'is_valid_rs_path'),
            '%%NFRAMES%%' => new CommandPlaceholderArg((string) $n_frames, 'is_int_loose'),
            '%%VF%%' => new CommandPlaceholderArg($vf, [CommandPlaceholderArg::class, 'alwaysValid']),
            '%%TARGET%%' => new CommandPlaceholderArg($out_path, 'is_valid_rs_path'),
        ];
        try {
            run_command($cmd, false, $params);
            $ok = is_file($out_path) && filesize($out_path) > 0;
        } catch (Throwable $e) {
            debug('image_sequence_framing_render_sequence concat: ' . $e->getMessage());
            $ok = false;
        }
        @unlink($list_file);
    }

    return $ok;
}

/**
 * Render framing crop from a video resource (in→out frames, with audio).
 */
function image_sequence_framing_render_video(
    array $resource,
    array $box,
    int $target_w,
    int $target_h,
    string $out_path,
    string $encode_opts
): bool {
    $ffmpeg = get_utility_path('ffmpeg');
    if ($ffmpeg === false) {
        return false;
    }

    $ref = (int) ($resource['ref'] ?? 0);
    $marks = image_sequence_video_get_marks($ref);
    $fps = max(0.0001, (float) $marks['fps']);
    $in_frame = (int) $marks['in_frame'];
    $out_frame = (int) $marks['out_frame'];
    if ($out_frame < $in_frame) {
        $tmp = $in_frame;
        $in_frame = $out_frame;
        $out_frame = $tmp;
    }
    $n_frames = max(1, $out_frame - $in_frame + 1);
    $ss = $in_frame / $fps;

    $source = image_sequence_video_source_path($resource);
    if ($source === '' || !is_file($source)) {
        return false;
    }

    $vf = image_sequence_framing_vf($box, $target_w, $target_h, '');

    // Accurate seek after -i; keep AAC audio when present.
    $cmd = $ffmpeg . ' -hide_banner -loglevel error -y'
        . ' -i %%SRC%% -ss %%TIME%% -frames:v %%NFRAMES%%'
        . ' ' . $encode_opts . ' -c:a aac -b:a 192k -vf %%VF%% %%TARGET%%';
    $params = [
        '%%SRC%%' => new CommandPlaceholderArg($source, 'image_sequence_is_valid_shell_path'),
        '%%TIME%%' => new CommandPlaceholderArg(sprintf('%.6F', $ss), [CommandPlaceholderArg::class, 'alwaysValid']),
        '%%NFRAMES%%' => new CommandPlaceholderArg((string) $n_frames, 'is_int_loose'),
        '%%VF%%' => new CommandPlaceholderArg($vf, [CommandPlaceholderArg::class, 'alwaysValid']),
        '%%TARGET%%' => new CommandPlaceholderArg($out_path, 'is_valid_rs_path'),
    ];

    try {
        run_command($cmd, false, $params);
    } catch (Throwable $e) {
        // Retry without audio if the source has no audio stream.
        debug('image_sequence_framing_render_video audio pass: ' . $e->getMessage());
        @unlink($out_path);
        $cmd = $ffmpeg . ' -hide_banner -loglevel error -y'
            . ' -i %%SRC%% -ss %%TIME%% -frames:v %%NFRAMES%%'
            . ' ' . $encode_opts . ' -an -vf %%VF%% %%TARGET%%';
        try {
            run_command($cmd, false, $params);
        } catch (Throwable $e2) {
            debug('image_sequence_framing_render_video: ' . $e2->getMessage());

            return false;
        }
    }

    return is_file($out_path) && filesize($out_path) > 0;
}
