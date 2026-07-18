<?php

use Montala\ResourceSpace\CommandPlaceholderArg;

include_once __DIR__ . '/cadence_functions.php';
include_once dirname(__DIR__, 3) . '/include/image_processing.php';

/**
 * Ensure resource type, metadata fields, and plugin config exist.
 */
function image_sequence_ensure_setup(): void
{
    global $image_sequence_restype, $image_sequence_framecount_field, $image_sequence_duration_field,
        $image_sequence_fps_field, $image_sequence_repframe_field, $image_sequence_cadence_field,
        $image_sequence_folder_field;

    $config = get_plugin_config('image_sequence') ?: [];
    $changed = false;

    if ((int) $image_sequence_restype <= 0) {
        $existing = ps_value(
            "SELECT ref value FROM resource_type WHERE name = ? OR name = ?",
            ['s', 'Image Sequence', 's', 'image-sequence'],
            0,
            'schema'
        );
        if ($existing > 0) {
            $image_sequence_restype = (int) $existing;
        } else {
            // Direct insert so CLI/cron setup works without an admin session.
            ps_query("INSERT INTO resource_type (name, icon, allowed_extensions) VALUES (?, ?, ?)", [
                's', 'Image Sequence',
                's', 'images',
                's', 'zip,jpg,jpeg,png,tif,tiff,exr,dpx',
            ]);
            $image_sequence_restype = (int) sql_insert_id();
            clear_query_cache('schema');
            clear_restype_cache();
        }
        $config['image_sequence_restype'] = $image_sequence_restype;
        $changed = true;
    }

    $fields = [
        'image_sequence_framecount_field' => ['Frame count', 'imgseq_frames'],
        'image_sequence_duration_field' => ['Duration', 'imgseq_duration'],
        'image_sequence_fps_field' => ['Playback FPS', 'imgseq_fps'],
        'image_sequence_repframe_field' => ['Representative frame', 'imgseq_repframe'],
        'image_sequence_cadence_field' => ['Detected cadence (s)', 'imgseq_cadence'],
        'image_sequence_folder_field' => ['Sequence folder', 'imgseq_folder'],
    ];

    foreach ($fields as $config_key => [$title, $shortname]) {
        $current = (int) ($GLOBALS[$config_key] ?? 0);
        if ($current > 0) {
            continue;
        }
        $field_ref = (int) create_resource_type_field(
            $title,
            (int) $image_sequence_restype,
            FIELD_TYPE_TEXT_BOX_SINGLE_LINE,
            $shortname,
            false
        );
        if ($field_ref > 0) {
            $GLOBALS[$config_key] = $field_ref;
            $config[$config_key] = $field_ref;
            $changed = true;
        }
    }

    // Photo EXIF / technical fields mapped from the representative still.
    if (image_sequence_ensure_photo_metadata_fields((int) $image_sequence_restype)) {
        $changed = true;
    }

    if ($changed) {
        set_plugin_config('image_sequence', array_merge(get_plugin_config('image_sequence') ?: [], $config));
    }

    image_sequence_ensure_db_indexes();
}

/**
 * Create Image Sequence fields for camera/lens/technical still metadata (ExifTool-mapped).
 *
 * @return bool True if any field was created or updated
 */
function image_sequence_ensure_photo_metadata_fields(int $restype): bool
{
    if ($restype <= 0) {
        return false;
    }

    $defs = image_sequence_photo_metadata_field_defs();
    $changed = false;

    foreach ($defs as $def) {
        $shortname = $def['shortname'];
        $field_ref = (int) ps_value(
            'SELECT ref value FROM resource_type_field WHERE name = ?',
            ['s', $shortname],
            0,
            'schema'
        );

        if ($field_ref <= 0) {
            $field_ref = (int) create_resource_type_field(
                $def['title'],
                $restype,
                FIELD_TYPE_TEXT_BOX_SINGLE_LINE,
                $shortname,
                false
            );
            if ($field_ref <= 0) {
                continue;
            }
            $changed = true;
        } else {
            // Ensure field is attached to the Image Sequence type (or global).
            $linked = (int) ps_value(
                'SELECT COUNT(*) value FROM resource_type_field rtf
                 LEFT JOIN resource_type_field_resource_type rtfrt ON rtfrt.resource_type_field = rtf.ref
                 WHERE rtf.ref = ? AND (rtf.global = 1 OR rtfrt.resource_type = ?)',
                ['i', $field_ref, 'i', $restype],
                0,
                'schema'
            );
            if ($linked === 0) {
                ps_query(
                    'INSERT INTO resource_type_field_resource_type (resource_type_field, resource_type) VALUES (?, ?)',
                    ['i', $field_ref, 'i', $restype]
                );
                $changed = true;
            }
        }

        $current_exif = (string) ps_value(
            'SELECT exiftool_field value FROM resource_type_field WHERE ref = ?',
            ['i', $field_ref],
            '',
            'schema'
        );
        if ($current_exif !== $def['exiftool']) {
            // Empty exiftool string clears mapping for analysis-only fields.
            ps_query(
                'UPDATE resource_type_field SET exiftool_field = ?, title = ? WHERE ref = ?',
                ['s', $def['exiftool'], 's', $def['title'], 'i', $field_ref]
            );
            $changed = true;
        }
    }

    if ($changed) {
        clear_query_cache('schema');
    }

    return $changed;
}

/**
 * Representative-still metadata fields (ExifTool tag lists).
 *
 * @return list<array{title: string, shortname: string, exiftool: string}>
 */
function image_sequence_photo_metadata_field_defs(): array
{
    return [
        ['title' => 'Camera make', 'shortname' => 'imgseq_make', 'exiftool' => 'Make,IFD0:Make'],
        ['title' => 'Camera model', 'shortname' => 'imgseq_model', 'exiftool' => 'Model,IFD0:Model'],
        ['title' => 'Lens', 'shortname' => 'imgseq_lens', 'exiftool' => 'LensModel,LensID,Lens,Composite:LensID'],
        ['title' => 'ISO', 'shortname' => 'imgseq_iso', 'exiftool' => 'ISO,ExifIFD:ISO'],
        ['title' => 'Aperture', 'shortname' => 'imgseq_aperture', 'exiftool' => 'FNumber,Aperture,Composite:Aperture'],
        ['title' => 'Shutter speed', 'shortname' => 'imgseq_shutter', 'exiftool' => 'ExposureTime,ShutterSpeed,Composite:ShutterSpeed'],
        ['title' => 'Focal length', 'shortname' => 'imgseq_focallen', 'exiftool' => 'FocalLength,ExifIFD:FocalLength'],
        ['title' => 'Focal length (35mm)', 'shortname' => 'imgseq_focal35', 'exiftool' => 'FocalLengthIn35mmFormat'],
        ['title' => 'Bit depth', 'shortname' => 'imgseq_bitdepth', 'exiftool' => 'BitsPerSample,BitDepth'],
        ['title' => 'Color space', 'shortname' => 'imgseq_colorspace', 'exiftool' => 'ColorSpace,ICC_Profile:ColorSpaceData'],
        ['title' => 'White balance', 'shortname' => 'imgseq_whitebal', 'exiftool' => 'WhiteBalance'],
        ['title' => 'Flash', 'shortname' => 'imgseq_flash', 'exiftool' => 'Flash'],
        ['title' => 'Orientation', 'shortname' => 'imgseq_orient', 'exiftool' => 'Orientation'],
        ['title' => 'Software', 'shortname' => 'imgseq_software', 'exiftool' => 'Software'],
        ['title' => 'Pixel dimensions', 'shortname' => 'imgseq_pixels', 'exiftool' => 'ImageSize,Composite:ImageSize'],
        ['title' => 'Capture date', 'shortname' => 'imgseq_captured', 'exiftool' => 'DateTimeOriginal,CreateDate'],
        // Sequence-level timing / exposure (filled by analysis, not per-tag ExifTool map).
        ['title' => 'First frame capture time', 'shortname' => 'imgseq_firstcap', 'exiftool' => ''],
        ['title' => 'Last frame capture time', 'shortname' => 'imgseq_lastcap', 'exiftool' => ''],
        ['title' => 'Real-time duration', 'shortname' => 'imgseq_realdur', 'exiftool' => ''],
        ['title' => 'Interval between frames', 'shortname' => 'imgseq_interval', 'exiftool' => ''],
        ['title' => 'Exposure program', 'shortname' => 'imgseq_expmode', 'exiftool' => ''],
    ];
}

/**
 * Pull dimensions, DPI, file size, and mapped EXIF from a still into the sequence resource.
 */
function image_sequence_extract_frame_metadata(int $ref, string $frame_path): void
{
    if ($ref <= 0 || !is_file($frame_path)) {
        return;
    }

    image_sequence_ensure_setup();

    $extension = strtolower(pathinfo($frame_path, PATHINFO_EXTENSION));
    $still_rel = image_sequence_absolute_to_relative($frame_path);
    if ($still_rel === null) {
        return;
    }

    $resource = get_resource_data($ref);
    if (!is_array($resource)) {
        return;
    }
    $manifest_rel = (string) ($resource['file_path'] ?? '');
    $manifest_ext = (string) ($resource['file_extension'] ?? 'json');

    // Point ResourceSpace at the still so ExifTool / get_resource_path resolve correctly.
    ps_query(
        'UPDATE resource SET file_path = ?, file_extension = ? WHERE ref = ?',
        ['s', $still_rel, 's', $extension, 'i', $ref]
    );
    unset($GLOBALS['get_resource_data_cache'][$ref], $GLOBALS['get_resource_path_fpcache'][$ref]);

    // Width / height / DPI / byte size into resource_dimensions (shown in file properties).
    try {
        if (function_exists('exiftool_resolution_calc') && get_utility_path('exiftool') !== false) {
            exiftool_resolution_calc($frame_path, $ref, true);
        }
    } catch (Throwable $e) {
        debug('image_sequence_extract_frame_metadata resolution: ' . $e->getMessage());
    }

    // Fallback if ExifTool resolution calc left empty dimensions.
    $dims = ps_query('SELECT width, height, file_size, resolution FROM resource_dimensions WHERE resource = ?', ['i', $ref]);
    $width = (int) ($dims[0]['width'] ?? 0);
    $height = (int) ($dims[0]['height'] ?? 0);
    $filesize = (int) ($dims[0]['file_size'] ?? 0);
    if ($width <= 0 || $height <= 0 || $filesize <= 0) {
        $info = @getimagesize($frame_path);
        if (is_array($info)) {
            $width = (int) ($info[0] ?? 0);
            $height = (int) ($info[1] ?? 0);
        }
        $filesize = (int) filesize_unlimited($frame_path);
        ps_query('DELETE FROM resource_dimensions WHERE resource = ?', ['i', $ref]);
        ps_query(
            'INSERT INTO resource_dimensions (resource, width, height, file_size, resolution, unit) VALUES (?, ?, ?, ?, ?, ?)',
            [
                'i', $ref,
                'i', $width,
                'i', $height,
                'i', $filesize,
                'd', (float) ($dims[0]['resolution'] ?? 0),
                's', 'inches',
            ]
        );
    }

    // Human-readable frame size field (MB).
    $size_field = (int) ps_value(
        'SELECT ref value FROM resource_type_field WHERE name = ?',
        ['s', 'imgseq_framesize'],
        0,
        'schema'
    );
    if ($size_field <= 0) {
        global $image_sequence_restype;
        $size_field = (int) create_resource_type_field(
            'Frame file size',
            (int) $image_sequence_restype,
            FIELD_TYPE_TEXT_BOX_SINGLE_LINE,
            'imgseq_framesize',
            false
        );
    }
    if ($size_field > 0 && $filesize > 0) {
        $mb = round($filesize / (1024 * 1024), 2);
        update_field($ref, $size_field, $mb . ' MB');
    }

    // Full ExifTool → mapped metadata fields (camera, lens, ISO, etc.).
    $prev_no_exif = $_POST['no_exif'] ?? null;
    $_POST['no_exif'] = ''; // force extract (empty → treated as "yes, extract")
    try {
        extract_exif_comment($ref, $extension);
    } catch (Throwable $e) {
        debug('image_sequence_extract_frame_metadata EXIF: ' . $e->getMessage());
    }
    if ($prev_no_exif === null) {
        unset($_POST['no_exif']);
    } else {
        $_POST['no_exif'] = $prev_no_exif;
    }

    // Restore manifest pointer; keep resource_dimensions from the still.
    ps_query(
        'UPDATE resource SET file_path = ?, file_extension = ? WHERE ref = ?',
        ['s', $manifest_rel, 's', $manifest_ext !== '' ? $manifest_ext : 'json', 'i', $ref]
    );
    unset($GLOBALS['get_resource_data_cache'][$ref], $GLOBALS['get_resource_path_fpcache'][$ref]);
}

/**
 * Analyse first/last capture times, real elapsed duration, inter-frame interval,
 * and whether exposure was fixed / aperture-priority / shutter-priority / variable.
 *
 * @param list<string> $member_paths Absolute paths in sequence order
 * @return array{
 *   first_capture: string,
 *   last_capture: string,
 *   real_duration_seconds: float,
 *   real_duration_label: string,
 *   interval_seconds: float|null,
 *   interval_label: string,
 *   exposure_summary: string
 * }
 */
function image_sequence_analyze_sequence_timeline(array $member_paths): array
{
    $empty = [
        'first_capture' => '',
        'last_capture' => '',
        'real_duration_seconds' => 0.0,
        'real_duration_label' => '',
        'interval_seconds' => null,
        'interval_label' => '',
        'exposure_summary' => '',
    ];
    $member_paths = array_values(array_filter($member_paths, 'is_file'));
    if ($member_paths === []) {
        return $empty;
    }

    // Consecutive capture times only — do not subsample evenly (that multiplies the
    // true interval by the skip stride, e.g. every 7th frame ≈ 21s when frames are 3s apart).
    $dated = image_sequence_dated_member_list($member_paths);
    if ($dated === []) {
        return $empty;
    }

    usort($dated, static fn ($a, $b) => $a['date'] <=> $b['date']);
    $first_ts = (float) $dated[0]['date'];
    $last_ts = (float) $dated[count($dated) - 1]['date'];
    $real_duration = max(0.0, $last_ts - $first_ts);

    $interval = null;
    if (count($dated) >= 2) {
        $interval = image_sequence_detect_normal_interval($dated);
        if ($interval === null && $real_duration > 0) {
            $interval = $real_duration / (count($dated) - 1);
        }
    }

    $exposure_summary = image_sequence_analyze_exposure_mode($member_paths);

    return [
        'first_capture' => image_sequence_format_capture_timestamp($first_ts),
        'last_capture' => image_sequence_format_capture_timestamp($last_ts),
        'real_duration_seconds' => $real_duration,
        'real_duration_label' => image_sequence_format_duration_label($real_duration),
        'interval_seconds' => $interval,
        'interval_label' => $interval === null
            ? ''
            : image_sequence_format_interval_label($interval),
        'exposure_summary' => $exposure_summary,
    ];
}

/**
 * Build path+date list for consecutive members (batched ExifTool when possible).
 *
 * @param list<string> $member_paths
 * @return list<array{path: string, date: float}>
 */
function image_sequence_dated_member_list(array $member_paths): array
{
    $member_paths = array_values(array_filter($member_paths, 'is_file'));
    if ($member_paths === []) {
        return [];
    }

    $dates_by_path = image_sequence_batch_effective_dates($member_paths);
    $dated = [];
    foreach ($member_paths as $path) {
        $ts = $dates_by_path[$path] ?? null;
        if ($ts === null) {
            $ts = image_sequence_get_effective_date($path);
        }
        $dated[] = ['path' => $path, 'date' => (float) $ts];
    }

    return $dated;
}

/**
 * Read capture times for many files in one ExifTool pass.
 *
 * @param list<string> $paths
 * @return array<string, float> absolute path => unix timestamp
 */
function image_sequence_batch_effective_dates(array $paths): array
{
    $exiftool = get_utility_path('exiftool');
    if ($exiftool === false || $paths === []) {
        return [];
    }

    $out = [];
    // Chunk to stay under ARG_MAX on large sequences.
    foreach (array_chunk(array_values($paths), 80) as $chunk) {
        $placeholders = [];
        $args = [];
        foreach ($chunk as $i => $path) {
            $token = '%%F' . $i . '%%';
            $placeholders[] = $token;
            $args[$token] = new CommandPlaceholderArg($path, 'is_valid_rs_path');
        }
        // -T: tab-separated FileName DateTimeOriginal; -n: numeric where possible.
        // Use FilePath so we can map back to absolute paths reliably.
        $output = run_command(
            $exiftool . ' -T -filepath -DateTimeOriginal -n ' . implode(' ', $placeholders),
            false,
            $args
        );
        foreach (preg_split('/\r\n|\r|\n/', trim((string) $output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) < 2) {
                continue;
            }
            $file = $parts[0];
            $raw = trim($parts[1] ?? '');
            if ($raw === '' || $raw === '-') {
                continue;
            }
            if (is_numeric($raw)) {
                $ts = (float) $raw;
            } else {
                $parsed = strtotime($raw);
                if ($parsed === false) {
                    continue;
                }
                $ts = (float) $parsed;
            }
            // ExifTool may print paths with different separators; normalize for lookup.
            $out[str_replace('\\', '/', $file)] = $ts;
            $out[$file] = $ts;
        }
    }

    // Map chunk results onto the exact input paths (realpath / basename fallback).
    $mapped = [];
    foreach ($paths as $path) {
        $norm = str_replace('\\', '/', $path);
        if (isset($out[$path])) {
            $mapped[$path] = $out[$path];
        } elseif (isset($out[$norm])) {
            $mapped[$path] = $out[$norm];
        } else {
            $real = realpath($path);
            if ($real !== false && isset($out[$real])) {
                $mapped[$path] = $out[$real];
            } elseif ($real !== false && isset($out[str_replace('\\', '/', $real)])) {
                $mapped[$path] = $out[str_replace('\\', '/', $real)];
            }
        }
    }

    return $mapped;
}

/**
 * Write sequence timeline / exposure analysis into metadata fields.
 *
 * @param list<string> $member_paths
 */
function image_sequence_apply_sequence_timeline_metadata(int $ref, array $member_paths): void
{
    if ($ref <= 0 || $member_paths === []) {
        return;
    }

    image_sequence_ensure_setup();
    $analysis = image_sequence_analyze_sequence_timeline($member_paths);

    $map = [
        'imgseq_firstcap' => $analysis['first_capture'],
        'imgseq_lastcap' => $analysis['last_capture'],
        'imgseq_realdur' => $analysis['real_duration_label'],
        'imgseq_interval' => $analysis['interval_label'],
        'imgseq_expmode' => $analysis['exposure_summary'],
    ];

    foreach ($map as $shortname => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $field_ref = (int) ps_value(
            'SELECT ref value FROM resource_type_field WHERE name = ?',
            ['s', $shortname],
            0,
            'schema'
        );
        if ($field_ref > 0) {
            update_field($ref, $field_ref, (string) $value);
        }
    }

    // Keep plugin cadence column in sync when we have a measured interval.
    if ($analysis['interval_seconds'] !== null) {
        ps_query(
            'UPDATE resource_image_sequence SET detected_cadence_seconds = ? WHERE resource = ?',
            ['d', (float) $analysis['interval_seconds'], 'i', $ref]
        );
        global $image_sequence_cadence_field;
        if ((int) $image_sequence_cadence_field > 0) {
            update_field($ref, (int) $image_sequence_cadence_field, (string) round((float) $analysis['interval_seconds'], 3));
        }
    }
}

/**
 * @param list<string> $paths
 * @return list<string>
 */
function image_sequence_sample_paths(array $paths, int $max_samples): array
{
    $n = count($paths);
    if ($n <= $max_samples) {
        return $paths;
    }
    $out = [];
    for ($i = 0; $i < $max_samples; $i++) {
        $idx = (int) round($i * ($n - 1) / max($max_samples - 1, 1));
        $out[] = $paths[$idx];
    }

    return array_values(array_unique($out));
}

function image_sequence_format_capture_timestamp(float $ts): string
{
    if ($ts <= 0) {
        return '';
    }

    return date('Y-m-d H:i:s', (int) round($ts));
}

function image_sequence_format_duration_label(float $seconds): string
{
    if ($seconds <= 0) {
        return '0s';
    }
    $seconds = (int) round($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    $parts = [];
    if ($h > 0) {
        $parts[] = $h . 'h';
    }
    if ($m > 0 || $h > 0) {
        $parts[] = $m . 'm';
    }
    $parts[] = $s . 's';
    $label = implode(' ', $parts);
    if ($seconds >= 60) {
        $label .= ' (' . $seconds . 's)';
    }

    return $label;
}

function image_sequence_format_interval_label(float $seconds): string
{
    if ($seconds <= 0) {
        return '';
    }
    if ($seconds < 1) {
        return round($seconds * 1000) . ' ms';
    }
    if ($seconds < 60) {
        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.') . ' s';
    }

    return image_sequence_format_duration_label($seconds);
}

/**
 * Classify exposure program / whether settings were fixed across the sequence.
 *
 * @param list<string> $member_paths
 */
function image_sequence_analyze_exposure_mode(array $member_paths): string
{
    $exiftool = get_utility_path('exiftool');
    if ($exiftool === false) {
        return '';
    }

    $sample = image_sequence_sample_paths($member_paths, 24);
    $programs = [];
    $modes = [];
    $apertures = [];
    $shutters = [];
    $isos = [];

    foreach ($sample as $path) {
        $tagged = run_command(
            $exiftool . ' -s -s -ExposureProgram -ExposureMode -FNumber -ExposureTime -ISO %%FILE%%',
            false,
            ['%%FILE%%' => new CommandPlaceholderArg($path, 'is_valid_rs_path')]
        );
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', trim((string) $tagged)) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if ($v !== '' && $v !== '-') {
                $map[strtolower($k)] = $v;
            }
        }
        if (isset($map['exposureprogram'])) {
            $programs[] = $map['exposureprogram'];
        }
        if (isset($map['exposuremode'])) {
            $modes[] = $map['exposuremode'];
        }
        if (isset($map['fnumber'])) {
            $apertures[] = $map['fnumber'];
        }
        if (isset($map['exposuretime'])) {
            $shutters[] = $map['exposuretime'];
        }
        if (isset($map['iso'])) {
            $isos[] = $map['iso'];
        }
    }

    $unique = static fn (array $vals): array => array_values(array_unique($vals));
    $programs_u = $unique($programs);
    $modes_u = $unique($modes);
    $ap_fixed = $apertures === [] ? null : count($unique($apertures)) <= 1;
    $sh_fixed = $shutters === [] ? null : count($unique($shutters)) <= 1;
    $iso_fixed = $isos === [] ? null : count($unique($isos)) <= 1;

    $program = $programs_u[0] ?? ($modes_u[0] ?? '');
    if (count($programs_u) > 1 || (count($modes_u) > 1 && $program === '')) {
        return 'Mixed exposure programs across frames'
            . image_sequence_exposure_settings_note($ap_fixed, $sh_fixed, $iso_fixed);
    }

    $label = image_sequence_classify_exposure_program($program);
    if ($label === '') {
        if ($ap_fixed === true && $sh_fixed === true && $iso_fixed === true) {
            return 'Exposure settings fixed (program unknown)';
        }
        if ($ap_fixed === false || $sh_fixed === false || $iso_fixed === false) {
            return 'Exposure settings variable (program unknown)'
                . image_sequence_exposure_settings_note($ap_fixed, $sh_fixed, $iso_fixed);
        }

        return '';
    }

    return $label . image_sequence_exposure_settings_note($ap_fixed, $sh_fixed, $iso_fixed);
}

function image_sequence_classify_exposure_program(string $program): string
{
    $p = strtolower(trim($program));
    if ($p === '') {
        return '';
    }
    if (str_contains($p, 'manual')) {
        return 'Manual (fixed exposure mode)';
    }
    if (str_contains($p, 'aperture')) {
        return 'Aperture priority';
    }
    if (str_contains($p, 'shutter') || str_contains($p, 'speed')) {
        return 'Shutter priority';
    }
    if (str_contains($p, 'program') || $p === 'normal' || str_contains($p, 'auto')) {
        return 'Program / auto exposure';
    }
    if (str_contains($p, 'creative') || str_contains($p, 'action') || str_contains($p, 'portrait') || str_contains($p, 'landscape')) {
        return 'Scene mode (' . $program . ')';
    }

    return $program;
}

/**
 * @param bool|null $ap_fixed null = unknown / no samples
 */
function image_sequence_exposure_settings_note(?bool $ap_fixed, ?bool $sh_fixed, ?bool $iso_fixed): string
{
    $bits = [];
    if ($ap_fixed !== null) {
        $bits[] = $ap_fixed ? 'aperture fixed' : 'aperture varied';
    }
    if ($sh_fixed !== null) {
        $bits[] = $sh_fixed ? 'shutter fixed' : 'shutter varied';
    }
    if ($iso_fixed !== null) {
        $bits[] = $iso_fixed ? 'ISO fixed' : 'ISO varied';
    }
    if ($bits === []) {
        return '';
    }

    return ' — ' . implode(', ', $bits);
}

/**
 * Best-effort UNIQUE index on resource_image_sequence.resource (one row per resource).
 */
function image_sequence_ensure_db_indexes(): void
{
    $exists = (int) ps_value(
        "SELECT COUNT(*) value FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'resource_image_sequence'",
        [],
        0
    );
    if ($exists === 0) {
        return;
    }

    $has_unique = (int) ps_value(
        "SELECT COUNT(*) value FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'resource_image_sequence'
           AND column_name = 'resource'
           AND non_unique = 0",
        [],
        0
    );
    if ($has_unique > 0) {
        return;
    }

    $dups = (int) ps_value(
        'SELECT COUNT(*) value FROM (
            SELECT resource FROM resource_image_sequence GROUP BY resource HAVING COUNT(*) > 1
         ) d',
        [],
        0
    );
    if ($dups > 0) {
        return;
    }

    ps_query(
        'ALTER TABLE resource_image_sequence ADD UNIQUE INDEX resource (resource)',
        [],
        '',
        -1,
        false
    );
}

function image_sequence_is_sequence_resource(array $resource): bool
{
    global $image_sequence_restype;

    return (int) ($resource['resource_type'] ?? 0) === (int) $image_sequence_restype
        && (int) $image_sequence_restype > 0;
}

/**
 * Team tools / web ingest access: sysadmins or groups granted the "is" permission.
 */
function image_sequence_can_access_tools(): bool
{
    return checkperm('a') || checkperm('is');
}

/**
 * Primary sync root for staging and relative paths.
 */
function image_sequence_primary_sync_root(): string
{
    global $syncdir, $image_sequence_sync_roots;

    if (is_array($image_sequence_sync_roots) && count($image_sequence_sync_roots) > 0) {
        $root = (string) $image_sequence_sync_roots[0];
        if ($root !== '') {
            return rtrim($root, '/');
        }
    }

    if (!empty($syncdir)) {
        return rtrim((string) $syncdir, '/');
    }

    return rtrim(get_temp_dir(false), '/');
}

/**
 * @return list<string>
 */
function image_sequence_allowed_roots(): array
{
    global $syncdir, $image_sequence_sync_roots;

    $roots = [];
    if (is_array($image_sequence_sync_roots)) {
        foreach ($image_sequence_sync_roots as $root) {
            $root = rtrim((string) $root, '/');
            if ($root !== '' && is_dir($root)) {
                $roots[] = $root;
            }
        }
    }
    if ($roots === [] && !empty($syncdir) && is_dir($syncdir)) {
        $roots[] = rtrim((string) $syncdir, '/');
    }
    if ($roots === []) {
        $fallback = image_sequence_primary_sync_root();
        if ($fallback !== '' && is_dir($fallback)) {
            $roots[] = $fallback;
        }
    }

    return array_values(array_unique($roots));
}

function image_sequence_path_under_allowed_root(string $absolute_path): bool
{
    $absolute_path = realpath($absolute_path) ?: $absolute_path;
    foreach (image_sequence_allowed_roots() as $root) {
        $root_real = realpath($root) ?: $root;
        if (strpos($absolute_path, rtrim($root_real, '/') . '/') === 0 || $absolute_path === $root_real) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function image_sequence_supported_extensions(): array
{
    global $image_sequence_extensions;

    if (is_array($image_sequence_extensions)) {
        return array_map('strtolower', $image_sequence_extensions);
    }

    $parts = array_map(static function ($e) {
        return strtolower(trim($e));
    }, explode(',', (string) $image_sequence_extensions));

    return array_values(array_filter($parts));
}

function image_sequence_is_supported_file(string $path): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return $ext !== '' && in_array($ext, image_sequence_supported_extensions(), true);
}

/**
 * Effective capture date as unix timestamp (Ingestr getEffectiveDate priority).
 */
function image_sequence_get_effective_date(string $path): float
{
    $exiftool = get_utility_path('exiftool');
    if ($exiftool !== false && is_readable($path)) {
        $output = run_command(
            $exiftool . ' -s3 -DateTimeOriginal -DateTime -n %%FILE%%',
            false,
            ['%%FILE%%' => new CommandPlaceholderArg($path, 'is_valid_rs_path')]
        );
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $output)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === '-') {
                continue;
            }
            // ExifTool -n may return epoch or formatted; try both.
            if (is_numeric($line)) {
                return (float) $line;
            }
            $ts = strtotime($line);
            if ($ts !== false) {
                return (float) $ts;
            }
        }
    }

    $mtime = @filemtime($path);

    return $mtime !== false ? (float) $mtime : (float) time();
}

/**
 * Enumerate stills under a folder (non-recursive by default).
 *
 * @return list<array{path: string, date: float}>
 */
function image_sequence_list_stills_in_folder(string $folder, bool $recursive = false): array
{
    $folder = rtrim($folder, '/');
    if (!is_dir($folder)) {
        return [];
    }

    $files = [];
    if ($recursive) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }
            $path = $fileinfo->getPathname();
            $base = $fileinfo->getFilename();
            if ($base[0] === '.' || !image_sequence_is_supported_file($path)) {
                continue;
            }
            $files[] = ['path' => $path, 'date' => image_sequence_get_effective_date($path)];
        }
    } else {
        foreach (scandir($folder) ?: [] as $base) {
            if ($base === '.' || $base === '..' || $base[0] === '.') {
                continue;
            }
            $path = $folder . '/' . $base;
            if (!is_file($path) || !image_sequence_is_supported_file($path)) {
                continue;
            }
            $files[] = ['path' => $path, 'date' => image_sequence_get_effective_date($path)];
        }
    }

    usort($files, static function ($a, $b) {
        return $a['date'] <=> $b['date'] ?: strcmp($a['path'], $b['path']);
    });

    return $files;
}

/**
 * Infer a contiguous printf pattern from member basenames when possible.
 *
 * @param list<string> $member_basenames
 * @return array{pattern: string, start: int, end: int}|null
 */
function image_sequence_infer_frame_pattern(array $member_basenames): ?array
{
    if (count($member_basenames) < 2) {
        return null;
    }

    $parsed = [];
    foreach ($member_basenames as $name) {
        if (!preg_match('/^(.*?)(\d+)(\.[^.]+)$/', $name, $m)) {
            return null;
        }
        $parsed[] = [
            'prefix' => $m[1],
            'number' => (int) $m[2],
            'pad' => strlen($m[2]),
            'suffix' => $m[3],
        ];
    }

    $prefix = $parsed[0]['prefix'];
    $suffix = $parsed[0]['suffix'];
    $pad = $parsed[0]['pad'];
    foreach ($parsed as $row) {
        if ($row['prefix'] !== $prefix || $row['suffix'] !== $suffix || $row['pad'] !== $pad) {
            return null;
        }
    }

    usort($parsed, static fn ($a, $b) => $a['number'] <=> $b['number']);
    $start = $parsed[0]['number'];
    $end = $parsed[count($parsed) - 1]['number'];
    if (($end - $start + 1) !== count($parsed)) {
        return null;
    }
    for ($i = 1, $n = count($parsed); $i < $n; $i++) {
        if ($parsed[$i]['number'] !== $parsed[$i - 1]['number'] + 1) {
            return null;
        }
    }

    return [
        'pattern' => $prefix . '%0' . $pad . 'd' . $suffix,
        'start' => $start,
        'end' => $end,
    ];
}

/**
 * Basenames already claimed by any Image Sequence in a folder.
 *
 * @return array<string, int> basename => resource ref
 */
function image_sequence_claimed_basenames_in_folder(string $folder_rel): array
{
    $claimed = [];
    $rows = ps_query(
        'SELECT resource, member_files FROM resource_image_sequence WHERE folder_path = ?',
        ['s', $folder_rel]
    );
    foreach ($rows as $row) {
        $members = json_decode((string) $row['member_files'], true);
        if (!is_array($members)) {
            continue;
        }
        foreach ($members as $name) {
            $claimed[(string) $name] = (int) $row['resource'];
        }
    }

    return $claimed;
}

/**
 * Find an existing sequence with the same folder + same member set.
 *
 * @param list<string> $member_basenames
 */
function image_sequence_find_existing_sequence(string $folder_rel, array $member_basenames): int
{
    $wanted = $member_basenames;
    sort($wanted);
    $rows = ps_query(
        'SELECT resource, member_files FROM resource_image_sequence WHERE folder_path = ?',
        ['s', $folder_rel]
    );
    foreach ($rows as $row) {
        $members = json_decode((string) $row['member_files'], true);
        if (!is_array($members)) {
            continue;
        }
        $have = $members;
        sort($have);
        if ($have === $wanted) {
            return (int) $row['resource'];
        }
    }

    return 0;
}

/**
 * Ingest a folder of stills: cadence-split into Image Sequences + Photo extras.
 *
 * @return array{sequences: list<int>, photos: list<int>, skipped: list<int>}
 */
function image_sequence_ingest_folder(string $folder_absolute, array $options = []): array
{
    global $image_sequence_restype, $image_sequence_photo_restype, $image_sequence_auto_split,
        $image_sequence_min_frames, $image_sequence_fps_default, $lang;

    image_sequence_ensure_setup();

    $folder_absolute = rtrim(str_replace('\\', '/', $folder_absolute), '/');
    if (!is_dir($folder_absolute) || !image_sequence_path_under_allowed_root($folder_absolute)) {
        return ['sequences' => [], 'photos' => [], 'skipped' => []];
    }

    $recursive = (bool) ($options['recursive'] ?? false);
    $auto_split = (bool) ($options['auto_split'] ?? $image_sequence_auto_split);
    $min_frames = (int) ($options['min_frames'] ?? $image_sequence_min_frames);
    $source_root = (string) ($options['source_root'] ?? dirname($folder_absolute));
    $created_by = (int) ($options['created_by'] ?? ($GLOBALS['userref'] ?? 0));

    $files = image_sequence_list_stills_in_folder($folder_absolute, $recursive);
    if ($files === []) {
        return ['sequences' => [], 'photos' => [], 'skipped' => []];
    }

    // Drop frames already owned by an existing sequence (idempotent re-sync).
    $files = array_values(array_filter($files, static function (array $file) {
        $folder_rel = image_sequence_absolute_to_relative(dirname($file['path']));
        if ($folder_rel === null) {
            return true;
        }
        $claimed = image_sequence_claimed_basenames_in_folder($folder_rel);
        return !isset($claimed[basename($file['path'])]);
    }));

    if ($files === []) {
        return ['sequences' => [], 'photos' => [], 'skipped' => []];
    }

    // When not recursive, treat the folder itself as one group.
    if ($recursive) {
        $groups = image_sequence_group_by_source_folder($files, $source_root);
    } else {
        $groups = [image_sequence_source_relative_group_key($files[0]['path'], $source_root) => $files];
    }

    // Process groups by earliest date.
    uasort($groups, static function ($a, $b) {
        return $a[0]['date'] <=> $b[0]['date'];
    });

    $sequence_refs = [];
    $photo_refs = [];
    $skipped_refs = [];

    foreach ($groups as $group_files) {
        usort($group_files, static function ($a, $b) {
            return $a['date'] <=> $b['date'] ?: strcmp($a['path'], $b['path']);
        });

        $cadence = image_sequence_detect_normal_interval($group_files);
        $segments = image_sequence_split_files($group_files, $auto_split);

        foreach ($segments as $segment) {
            if (count($segment) < $min_frames) {
                foreach ($segment as $file) {
                    $ref = image_sequence_create_photo_resource($file['path'], $created_by);
                    if ($ref > 0) {
                        $photo_refs[] = $ref;
                    }
                }
                continue;
            }

            $ref = image_sequence_create_sequence_resource($segment, $cadence, $created_by);
            if ($ref > 0) {
                // create_sequence_resource returns existing ref when duplicate; track separately via flag in options later.
                $sequence_refs[] = $ref;
            }
        }
    }

    return ['sequences' => $sequence_refs, 'photos' => $photo_refs, 'skipped' => $skipped_refs];
}

/**
 * Create an in-place Photo resource for an extra still.
 */
function image_sequence_create_photo_resource(string $absolute_path, int $created_by = 0): int
{
    global $image_sequence_photo_restype, $syncdir, $lang;

    $absolute_path = str_replace('\\', '/', $absolute_path);
    if (!is_file($absolute_path)) {
        return 0;
    }

    $relative = image_sequence_absolute_to_relative($absolute_path);
    if ($relative === null) {
        return 0;
    }

    // Skip if already tracked.
    $existing = (int) ps_value('SELECT ref value FROM resource WHERE file_path = ?', ['s', $relative], 0);
    if ($existing > 0) {
        return $existing;
    }

    $title = pathinfo($absolute_path, PATHINFO_FILENAME);
    $extension = strtolower(pathinfo($absolute_path, PATHINFO_EXTENSION));
    $type = (int) $image_sequence_photo_restype;

    $ref = create_resource($type, 999, $created_by > 0 ? $created_by : -1, $lang['createdfromstaticsync'] ?? 'Image sequence extras', $extension);
    if ($ref === false) {
        return 0;
    }

    update_resource($ref, $relative, $type, $title, false, true, $extension);

    return (int) $ref;
}

/**
 * Create Image Sequence resource from a segment of dated files (in place).
 *
 * @param list<array{path: string, date: float}> $segment
 */
function image_sequence_create_sequence_resource(array $segment, ?float $cadence, int $created_by = 0): int
{
    global $image_sequence_restype, $image_sequence_fps_default, $lang;

    if ($segment === [] || (int) $image_sequence_restype <= 0) {
        return 0;
    }

    $folder = dirname($segment[0]['path']);
    $folder_rel = image_sequence_absolute_to_relative($folder);
    if ($folder_rel === null) {
        return 0;
    }

    $member_basenames = [];
    foreach ($segment as $file) {
        $base = basename($file['path']);
        if (!image_sequence_is_safe_member_basename($base)) {
            continue;
        }
        $member_basenames[] = $base;
    }
    if ($member_basenames === []) {
        return 0;
    }

    // Idempotent: same folder + same members → return existing resource.
    $existing = image_sequence_find_existing_sequence($folder_rel, $member_basenames);
    if ($existing > 0) {
        return $existing;
    }

    $fps = (float) $image_sequence_fps_default;
    $frame_count = count($member_basenames);
    $duration = $frame_count / max($fps, 0.0001);
    $extension = strtolower(pathinfo($member_basenames[0], PATHINFO_EXTENSION));
    $pattern_info = image_sequence_infer_frame_pattern($member_basenames);

    $ref = create_resource(
        (int) $image_sequence_restype,
        999,
        $created_by > 0 ? $created_by : -1,
        $lang['createdfromstaticsync'] ?? 'Image sequence',
        'json'
    );
    if ($ref === false) {
        return 0;
    }

    $manifest_name = '.rs_imagesequence_' . $ref . '.json';
    $manifest_abs = $folder . '/' . $manifest_name;
    $manifest_rel = ($folder_rel === '' ? '' : $folder_rel . '/') . $manifest_name;

    $payload = [
        'resource' => (int) $ref,
        'folder_path' => $folder_rel,
        'member_files' => $member_basenames,
        'frame_pattern' => $pattern_info['pattern'] ?? null,
        'start_number' => $pattern_info['start'] ?? 0,
        'end_number' => $pattern_info['end'] ?? 0,
        'frame_count' => $frame_count,
        'fps' => $fps,
        'detected_cadence_seconds' => $cadence,
        'created' => date('c'),
    ];
    file_put_contents($manifest_abs, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    ps_query(
        'UPDATE resource SET archive=0, file_path=?, file_extension=?, preview_extension=?, file_modified=NOW(), no_file=0 WHERE ref=?',
        ['s', $manifest_rel, 's', 'json', 's', 'jpg', 'i', $ref]
    );
    unset($GLOBALS['get_resource_data_cache'][$ref]);

    ps_query(
        'INSERT INTO resource_image_sequence
            (resource, folder_path, member_files, frame_pattern, start_number, end_number, frame_count,
             extension, fps, duration_seconds, detected_cadence_seconds, representative_frame, proxy_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            'i', $ref,
            's', $folder_rel,
            's', json_encode($member_basenames, JSON_UNESCAPED_SLASHES),
            's', $pattern_info['pattern'] ?? '',
            'i', $pattern_info['start'] ?? 0,
            'i', $pattern_info['end'] ?? 0,
            'i', $frame_count,
            's', $extension,
            'd', $fps,
            'd', $duration,
            'd', $cadence,
            'i', 0,
            's', 'pending',
        ]
    );

    image_sequence_update_metadata_fields($ref, [
        'frame_count' => $frame_count,
        'duration' => $duration,
        'fps' => $fps,
        'cadence' => $cadence,
        'folder' => $folder_rel,
        'representative_frame' => 0,
    ]);

    $member_paths = [];
    foreach ($segment as $file) {
        if (is_file($file['path'] ?? '')) {
            $member_paths[] = $file['path'];
        }
    }
    try {
        image_sequence_apply_sequence_timeline_metadata($ref, $member_paths);
        if ($member_paths !== []) {
            image_sequence_extract_frame_metadata($ref, $member_paths[0]);
        }
    } catch (Throwable $e) {
        debug('image_sequence_create_sequence_resource timeline/metadata: ' . $e->getMessage());
    }

    image_sequence_queue_proxy_job($ref);

    return (int) $ref;
}

/**
 * @param array{frame_count?: int|float, duration?: float, fps?: float, cadence?: float|null, folder?: string, representative_frame?: int} $values
 */
function image_sequence_update_metadata_fields(int $ref, array $values): void
{
    global $image_sequence_framecount_field, $image_sequence_duration_field, $image_sequence_fps_field,
        $image_sequence_repframe_field, $image_sequence_cadence_field, $image_sequence_folder_field;

    $map = [
        'frame_count' => $image_sequence_framecount_field,
        'duration' => $image_sequence_duration_field,
        'fps' => $image_sequence_fps_field,
        'representative_frame' => $image_sequence_repframe_field,
        'cadence' => $image_sequence_cadence_field,
        'folder' => $image_sequence_folder_field,
    ];

    foreach ($map as $key => $field) {
        if ((int) $field <= 0 || !array_key_exists($key, $values)) {
            continue;
        }
        $value = $values[$key];
        if ($value === null) {
            continue;
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        update_field($ref, (int) $field, (string) $value);
    }
}

function image_sequence_absolute_to_relative(string $absolute_path): ?string
{
    $absolute_path = str_replace('\\', '/', $absolute_path);
    foreach (image_sequence_allowed_roots() as $root) {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($absolute_path === $root) {
            return '';
        }
        if (strpos($absolute_path, $root . '/') === 0) {
            return substr($absolute_path, strlen($root) + 1);
        }
    }

    // Fall back to $syncdir even if not yet in allowed roots list.
    global $syncdir;
    if (!empty($syncdir)) {
        $root = rtrim(str_replace('\\', '/', (string) $syncdir), '/');
        if (strpos($absolute_path, $root . '/') === 0) {
            return substr($absolute_path, strlen($root) + 1);
        }
    }

    return null;
}

function image_sequence_relative_to_absolute(string $relative_path): ?string
{
    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
    foreach (image_sequence_allowed_roots() as $root) {
        $candidate = rtrim($root, '/') . ($relative_path === '' ? '' : '/' . $relative_path);
        if (file_exists($candidate) || is_dir(dirname($candidate))) {
            return $candidate;
        }
    }

    global $syncdir;
    if (!empty($syncdir)) {
        return rtrim((string) $syncdir, '/') . ($relative_path === '' ? '' : '/' . $relative_path);
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function image_sequence_get_data(int $resource): ?array
{
    $rows = ps_query('SELECT * FROM resource_image_sequence WHERE resource = ?', ['i', $resource]);
    if (count($rows) === 0) {
        return null;
    }
    $row = $rows[0];
    $members = json_decode((string) $row['member_files'], true);
    $row['member_files_list'] = is_array($members) ? $members : [];

    return $row;
}

/**
 * Safe member basename: no path separators or parent-dir references.
 */
function image_sequence_is_safe_member_basename(string $name): bool
{
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }
    if ($name[0] === '.') {
        // Allow sequence manifests to be ignored elsewhere; frames should not be hidden files.
        // Still reject path tricks.
    }
    if (strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        return false;
    }
    if (strpos($name, '..') !== false) {
        return false;
    }

    return $name === basename($name);
}

/**
 * Absolute paths for sequence member frames in order.
 *
 * @return list<string>
 */
function image_sequence_member_absolute_paths(array $sequence_data): array
{
    $folder_abs = image_sequence_relative_to_absolute((string) $sequence_data['folder_path']);
    if ($folder_abs === null) {
        return [];
    }
    $folder_abs = rtrim($folder_abs, '/');
    $folder_real = realpath($folder_abs);
    if ($folder_real === false) {
        return [];
    }

    $paths = [];
    foreach ($sequence_data['member_files_list'] as $name) {
        $name = (string) $name;
        if (!image_sequence_is_safe_member_basename($name)) {
            continue;
        }
        $path = $folder_real . DIRECTORY_SEPARATOR . $name;
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            continue;
        }
        // Ensure resolved path stays inside the sequence folder.
        if (strpos($real, $folder_real . DIRECTORY_SEPARATOR) !== 0 && $real !== $folder_real) {
            continue;
        }
        $paths[] = $real;
    }

    return $paths;
}

function image_sequence_queue_proxy_job(int $ref): void
{
    global $lang, $offline_job_queue;

    $job_data = ['resource' => $ref];
    $success = $lang['image_sequence_rep_frame_set'] ?? 'Image sequence proxy ready';
    $failure = $lang['image_sequence_proxy_failed'] ?? 'Image sequence proxy failed';

    if (!empty($offline_job_queue)) {
        job_queue_add('create_image_sequence_proxy', $job_data, '', '', $success, $failure, 'imgseq_proxy_' . $ref);
        return;
    }

    // Run inline when offline jobs are disabled.
    image_sequence_generate_proxy($ref);
}

/**
 * Build FFmpeg proxy + poster thumbnails for a sequence resource.
 */
function image_sequence_generate_proxy(int $ref): bool
{
    global $ffmpeg_preview_extension, $ffmpeg_preview_options, $ffmpeg_preview_max_width,
        $ffmpeg_preview_max_height, $image_sequence_proxy_max_width, $image_sequence_proxy_max_height,
        $image_sequence_proxy_max_seconds, $image_sequence_proxy_options;

    $data = image_sequence_get_data($ref);
    if ($data === null) {
        return false;
    }

    $paths = image_sequence_member_absolute_paths($data);
    if ($paths === []) {
        ps_query("UPDATE resource_image_sequence SET proxy_status = 'failed' WHERE resource = ?", ['i', $ref]);
        return false;
    }

    $ffmpeg = get_utility_path('ffmpeg');
    if ($ffmpeg === false) {
        ps_query("UPDATE resource_image_sequence SET proxy_status = 'failed' WHERE resource = ?", ['i', $ref]);
        return false;
    }

    ps_query("UPDATE resource SET is_transcoding = 1 WHERE ref = ?", ['i', $ref]);
    ps_query("UPDATE resource_image_sequence SET proxy_status = 'processing' WHERE resource = ?", ['i', $ref]);

    $fps = (float) ($data['fps'] ?: 30);
    $ext = $ffmpeg_preview_extension ?: 'mp4';
    $target = get_resource_path($ref, true, 'pre', true, $ext);
    $temp_dir = get_temp_dir(false, 'imgseq_' . $ref);
    $ok = false;
    $has_poster = false;

    $width = (int) ($image_sequence_proxy_max_width ?: $ffmpeg_preview_max_width ?: 1280);
    $height = (int) ($image_sequence_proxy_max_height ?: $ffmpeg_preview_max_height ?: 720);
    $encode_opts = trim((string) ($image_sequence_proxy_options !== '' ? $image_sequence_proxy_options : $ffmpeg_preview_options));
    if ($encode_opts === '') {
        $encode_opts = '-f mp4 -c:v libx264 -pix_fmt yuv420p -profile:v baseline -level 3 -an';
    }

    $duration_limit = (int) $image_sequence_proxy_max_seconds;
    $scale = "scale={$width}:{$height}:force_original_aspect_ratio=decrease,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2,setsar=1";

    try {
        $pattern = (string) ($data['frame_pattern'] ?? '');
        $folder_abs = image_sequence_relative_to_absolute((string) $data['folder_path']);
        if ($pattern !== '' && $folder_abs !== null && (int) $data['start_number'] > 0) {
            $input = rtrim($folder_abs, '/') . '/' . $pattern;
            $cmd = $ffmpeg . ' -y -framerate %%FPS%% -start_number %%START%% -i %%INPUT%% '
                . $encode_opts . ' -vf %%SCALE%%';
            $params = [
                '%%FPS%%' => new CommandPlaceholderArg((string) $fps, [CommandPlaceholderArg::class, 'alwaysValid']),
                '%%START%%' => new CommandPlaceholderArg((string) (int) $data['start_number'], 'is_int_loose'),
                '%%INPUT%%' => new CommandPlaceholderArg($input, [CommandPlaceholderArg::class, 'alwaysValid']),
                '%%SCALE%%' => new CommandPlaceholderArg($scale, [CommandPlaceholderArg::class, 'alwaysValid']),
            ];
            if ($duration_limit > 0) {
                $cmd .= ' -t %%SECONDS%%';
                $params['%%SECONDS%%'] = new CommandPlaceholderArg((string) $duration_limit, 'is_int_loose');
            }
            $cmd .= ' %%TARGET%%';
            $params['%%TARGET%%'] = new CommandPlaceholderArg($target, 'is_valid_rs_path');
            run_command($cmd, false, $params);
            $ok = file_exists($target) && filesize($target) > 0;
        }

        if (!$ok) {
            // Concat demuxer for non-contiguous membership.
            $list_file = $temp_dir . '/concat.txt';
            $fh = fopen($list_file, 'w');
            foreach ($paths as $path) {
                $escaped = str_replace("'", "'\\''", $path);
                fwrite($fh, "file '{$escaped}'\nduration " . (1 / max($fps, 0.0001)) . "\n");
            }
            // Repeat last file path without duration for concat demuxer image sequences.
            $last = str_replace("'", "'\\''", $paths[count($paths) - 1]);
            fwrite($fh, "file '{$last}'\n");
            fclose($fh);

            $cmd = $ffmpeg . ' -y -f concat -safe 0 -r %%FPS%% -i %%LIST%% ' . $encode_opts . ' -vf %%SCALE%%';
            $params = [
                '%%FPS%%' => new CommandPlaceholderArg((string) $fps, [CommandPlaceholderArg::class, 'alwaysValid']),
                '%%LIST%%' => new CommandPlaceholderArg($list_file, 'is_valid_rs_path'),
                '%%SCALE%%' => new CommandPlaceholderArg($scale, [CommandPlaceholderArg::class, 'alwaysValid']),
            ];
            if ($duration_limit > 0) {
                $cmd .= ' -t %%SECONDS%%';
                $params['%%SECONDS%%'] = new CommandPlaceholderArg((string) $duration_limit, 'is_int_loose');
            }
            $cmd .= ' %%TARGET%%';
            $params['%%TARGET%%'] = new CommandPlaceholderArg($target, 'is_valid_rs_path');
            run_command($cmd, false, $params);
            $ok = file_exists($target) && filesize($target) > 0;
        }

        // Poster from representative / middle frame.
        $rep_index = (int) ($data['representative_frame'] ?? 0);
        if ($rep_index < 0 || $rep_index >= count($paths)) {
            $rep_index = (int) floor(count($paths) / 2);
        }
        $poster_source = $paths[$rep_index];
        $poster_jpg = get_resource_path($ref, true, 'pre', true, 'jpg');
        try {
            run_command(
                $ffmpeg . ' -y -i %%SRC%% -frames:v 1 %%DST%%',
                false,
                [
                    '%%SRC%%' => new CommandPlaceholderArg($poster_source, 'is_valid_rs_path'),
                    '%%DST%%' => new CommandPlaceholderArg($poster_jpg, 'is_valid_rs_path'),
                ]
            );
        } catch (Throwable $e) {
            // Non-fatal if still is unsupported by ffmpeg; try copy for jpg/png.
            $ext_src = strtolower(pathinfo($poster_source, PATHINFO_EXTENSION));
            if (in_array($ext_src, ['jpg', 'jpeg', 'png'], true)) {
                @copy($poster_source, $poster_jpg);
            }
        }

        if (file_exists($poster_jpg)) {
            create_previews($ref, false, 'jpg', false, true);
            $has_poster = true;
        }
    } catch (Throwable $e) {
        debug('image_sequence_generate_proxy error: ' . $e->getMessage());
        $ok = false;
    } finally {
        // Always clear the lock — ResourceSpace refuses permanent delete while is_transcoding=1,
        // and a killed/timed-out FFmpeg run must not leave the resource undeletable.
        if ($has_poster) {
            ps_query(
                "UPDATE resource SET has_image = 1, preview_extension = 'jpg', is_transcoding = 0 WHERE ref = ?",
                ['i', $ref]
            );
        } else {
            ps_query('UPDATE resource SET is_transcoding = 0 WHERE ref = ?', ['i', $ref]);
        }
        ps_query(
            'UPDATE resource_image_sequence SET proxy_status = ? WHERE resource = ?',
            ['s', $ok ? 'ready' : 'failed', 'i', $ref]
        );
    }

    return $ok;
}

/**
 * Set representative frame from proxy scrub index; pull EXIF into metadata; refresh poster.
 *
 * @return array{ok: bool, message: string, frame?: int}
 */
function image_sequence_set_representative_frame(int $ref, int $frame_index): array
{
    global $lang;

    $data = image_sequence_get_data($ref);
    if ($data === null) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_no_data'] ?? 'No image sequence data found for this resource.',
        ];
    }
    $paths = image_sequence_member_absolute_paths($data);
    $path_count = count($paths);
    if ($path_count === 0) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_rep_frame_no_files'] ?? 'Sequence frame files are missing on disk.',
        ];
    }

    // Clamp — proxy scrubbing at EOF often reports frame_count (one past last index).
    if ($frame_index < 0) {
        $frame_index = 0;
    }
    if ($frame_index >= $path_count) {
        $frame_index = $path_count - 1;
    }

    ps_query(
        'UPDATE resource_image_sequence SET representative_frame = ? WHERE resource = ?',
        ['i', $frame_index, 'i', $ref]
    );
    image_sequence_update_metadata_fields($ref, ['representative_frame' => $frame_index]);

    $frame_path = $paths[$frame_index];
    $extension = strtolower(pathinfo($frame_path, PATHINFO_EXTENSION));

    try {
        image_sequence_extract_frame_metadata($ref, $frame_path);
        image_sequence_apply_sequence_timeline_metadata($ref, $paths);
    } catch (Throwable $e) {
        debug('image_sequence_set_representative_frame metadata: ' . $e->getMessage());
    }

    // Refresh poster from chosen frame (prefer direct copy for stills).
    // Keep this AJAX request fast — full create_previews() was too heavy here.
    $poster_jpg = get_resource_path($ref, true, 'pre', true, 'jpg');
    $copied = false;
    if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        $copied = @copy($frame_path, $poster_jpg);
    }
    if (!$copied) {
        $ffmpeg = get_utility_path('ffmpeg');
        if ($ffmpeg !== false) {
            try {
                run_command(
                    $ffmpeg . ' -y -i %%SRC%% -frames:v 1 -update 1 %%DST%%',
                    false,
                    [
                        '%%SRC%%' => new CommandPlaceholderArg($frame_path, 'is_valid_rs_path'),
                        '%%DST%%' => new CommandPlaceholderArg($poster_jpg, 'is_valid_rs_path'),
                    ]
                );
            } catch (Throwable $e) {
                debug('image_sequence_set_representative_frame poster: ' . $e->getMessage());
            }
        }
    }
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

    return [
        'ok' => true,
        'message' => $lang['image_sequence_rep_frame_set'] ?? 'Representative frame updated.',
        'frame' => $frame_index,
    ];
}

/**
 * Stage uploaded files/ZIP under sync root and ingest with auto-split.
 *
 * @param list<string> $source_paths Absolute paths to images or a single zip
 * @return array{sequences: list<int>, photos: list<int>, folder: string}
 */
function image_sequence_ingest_upload_paths(array $source_paths, array $options = []): array
{
    global $image_sequence_upload_subdir;

    $root = image_sequence_primary_sync_root();
    $batch = 'upload_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8);
    $dest = rtrim($root, '/') . '/' . trim($image_sequence_upload_subdir, '/') . '/' . $batch;
    if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
        return ['sequences' => [], 'photos' => [], 'folder' => ''];
    }

    foreach ($source_paths as $src) {
        if (!is_readable($src)) {
            continue;
        }
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            image_sequence_extract_zip($src, $dest);
            continue;
        }
        if (image_sequence_is_supported_file($src)) {
            $base = basename($src);
            if (!image_sequence_is_safe_member_basename($base)) {
                continue;
            }
            $target = $dest . '/' . $base;
            if (!@copy($src, $target)) {
                @rename($src, $target);
            }
        }
    }

    $result = image_sequence_ingest_folder($dest, array_merge($options, [
        'recursive' => true,
        'source_root' => $dest,
    ]));
    $result['folder'] = $dest;

    return $result;
}

/**
 * Extract a ZIP into $dest, rejecting path traversal (zip slip).
 */
function image_sequence_extract_zip(string $zip_path, string $dest): void
{
    if (!class_exists('ZipArchive')) {
        return;
    }
    $dest = rtrim(str_replace('\\', '/', $dest), '/');
    if (!is_dir($dest)) {
        return;
    }
    $dest_real = realpath($dest);
    if ($dest_real === false) {
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return;
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            continue;
        }
        $entry = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
        if ($entry === '' || str_ends_with($entry, '/')) {
            continue;
        }
        // Reject absolute paths, parent traversal, and macOS metadata.
        if (
            $entry[0] === '/'
            || strpos($entry, '../') !== false
            || strpos($entry, '/..') !== false
            || str_starts_with($entry, '__MACOSX/')
            || strpos($entry, '/__MACOSX/') !== false
        ) {
            continue;
        }

        $target = $dest_real . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
        $target_dir = dirname($target);
        if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
            continue;
        }

        // Confirm final directory is still under dest before writing.
        $target_dir_real = realpath($target_dir);
        if (
            $target_dir_real === false
            || (strpos($target_dir_real, $dest_real . DIRECTORY_SEPARATOR) !== 0 && $target_dir_real !== $dest_real)
        ) {
            continue;
        }

        $contents = $zip->getFromIndex($i);
        if ($contents === false) {
            continue;
        }
        file_put_contents($target, $contents);
    }

    $zip->close();
}

/**
 * Clear the core is_transcoding lock for a resource.
 * Permanent delete is refused while this flag is set.
 */
function image_sequence_clear_transcoding_lock(int $ref): void
{
    if ($ref <= 0) {
        return;
    }
    ps_query('UPDATE resource SET is_transcoding = 0 WHERE ref = ?', ['i', $ref]);
}

/**
 * Remove plugin rows / manifests when a resource is deleted.
 */
function image_sequence_cleanup_resource(int $ref): void
{
    // Ensure permanent delete is not blocked if a proxy job left the lock set.
    image_sequence_clear_transcoding_lock($ref);

    $data = image_sequence_get_data($ref);
    if ($data !== null) {
        $manifest_rel = '';
        $resource = get_resource_data($ref);
        if (is_array($resource) && !empty($resource['file_path'])) {
            $manifest_rel = (string) $resource['file_path'];
        } else {
            $folder = (string) ($data['folder_path'] ?? '');
            $manifest_rel = ($folder === '' ? '' : $folder . '/') . '.rs_imagesequence_' . $ref . '.json';
        }
        $manifest_abs = image_sequence_relative_to_absolute($manifest_rel);
        if ($manifest_abs !== null && is_file($manifest_abs) && strpos(basename($manifest_abs), '.rs_imagesequence_') === 0) {
            @unlink($manifest_abs);
        }
        ps_query('DELETE FROM resource_image_sequence WHERE resource = ?', ['i', $ref]);
    }
}

/**
 * Whether StaticSync should skip this relative path (claimed by a sequence or already a photo extra).
 */
function image_sequence_should_skip_staticsync_path(string $shortpath): bool
{
    $shortpath = ltrim(str_replace('\\', '/', $shortpath), '/');
    $base = basename($shortpath);
    if (strpos($base, '.rs_imagesequence_') === 0) {
        return true;
    }

    // Exact file_path match (photos / manifests).
    $existing = (int) ps_value('SELECT ref value FROM resource WHERE file_path = ? LIMIT 1', ['s', $shortpath], 0);
    if ($existing > 0) {
        return true;
    }

    $folder = dirname($shortpath);
    if ($folder === '.') {
        $folder = '';
    }
    $name = basename($shortpath);

    $rows = ps_query(
        'SELECT member_files FROM resource_image_sequence WHERE folder_path = ?',
        ['s', $folder]
    );
    foreach ($rows as $row) {
        $members = json_decode((string) $row['member_files'], true);
        if (is_array($members) && in_array($name, $members, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Build a ZIP of sequence frames into temp and return path, or queue offline.
 */
function image_sequence_create_download_zip(int $ref): ?string
{
    $data = image_sequence_get_data($ref);
    if ($data === null) {
        return null;
    }
    $paths = image_sequence_member_absolute_paths($data);
    if ($paths === [] || !class_exists('ZipArchive')) {
        return null;
    }

    $zip_path = get_temp_dir(false, 'imgseq_zip_' . $ref) . '/sequence_' . $ref . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }
    foreach ($paths as $path) {
        $zip->addFile($path, basename($path));
    }
    $zip->close();

    return file_exists($zip_path) ? $zip_path : null;
}
