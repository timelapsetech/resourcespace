<?php

use Montala\ResourceSpace\CommandPlaceholderArg;

include_once __DIR__ . '/cadence_functions.php';
include_once __DIR__ . '/video_nle_functions.php';
include_once dirname(__DIR__, 3) . '/include/image_processing.php';

/**
 * Ensure resource type, metadata fields, tabs, and plugin config exist.
 */
function image_sequence_ensure_setup(): void
{
    global $image_sequence_restype, $image_sequence_framecount_field, $image_sequence_duration_field,
        $image_sequence_fps_field, $image_sequence_repframe_field, $image_sequence_inframe_field,
        $image_sequence_outframe_field, $image_sequence_cadence_field, $image_sequence_folder_field,
        $image_sequence_folderpath_field, $image_sequence_seqcode_field;

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
        'image_sequence_duration_field' => ['Playback duration', 'imgseq_duration'],
        'image_sequence_fps_field' => ['Playback FPS', 'imgseq_fps'],
        'image_sequence_repframe_field' => ['Representative frame', 'imgseq_repframe'],
        'image_sequence_inframe_field' => ['In point (frame)', 'imgseq_inframe'],
        'image_sequence_outframe_field' => ['Out point (frame)', 'imgseq_outframe'],
        'image_sequence_cadence_field' => ['Capture cadence', 'imgseq_cadence'],
        'image_sequence_folder_field' => ['Folder name', 'imgseq_folder'],
        'image_sequence_folderpath_field' => ['Folder path', 'imgseq_folderpath'],
        'image_sequence_seqcode_field' => ['Sequence code', 'imgseq_seqcode'],
    ];

    image_sequence_ensure_db_columns();

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

    // Group fields onto Sequence / Image tabs (descriptive stays on Default).
    if (image_sequence_ensure_metadata_tabs()) {
        $changed = true;
    }

    // Attach in/out/rep fields to video resource types for the Omakase NLE.
    image_sequence_ensure_video_field_types();

    if ($changed) {
        set_plugin_config('image_sequence', array_merge(get_plugin_config('image_sequence') ?: [], $config));
    }

    image_sequence_ensure_db_indexes();
}

/**
 * Create Image Sequence fields for camera/lens/technical still metadata (ExifTool-mapped)
 * and sequence-level timing/exposure analysis fields.
 *
 * @return bool True if any field was created or updated
 */
function image_sequence_ensure_photo_metadata_fields(int $restype): bool
{
    if ($restype <= 0) {
        return false;
    }

    $defs = array_merge(
        image_sequence_image_metadata_field_defs(),
        image_sequence_sequence_analysis_field_defs()
    );
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

    // Frame file size is created on first extract; ensure the field exists for tab assignment.
    $size_ref = (int) ps_value(
        'SELECT ref value FROM resource_type_field WHERE name = ?',
        ['s', 'imgseq_framesize'],
        0,
        'schema'
    );
    if ($size_ref <= 0) {
        $size_ref = (int) create_resource_type_field(
            'Frame file size',
            $restype,
            FIELD_TYPE_TEXT_BOX_SINGLE_LINE,
            'imgseq_framesize',
            false
        );
        if ($size_ref > 0) {
            $changed = true;
        }
    }

    if ($changed) {
        clear_query_cache('schema');
    }

    return $changed;
}

/**
 * Ensure System tabs exist and assign plugin fields for a clear view-page layout:
 *   Default  — descriptive (title, caption, keywords, AI text, …)
 *   Sequence — timing, edit points, cadence, folder
 *   Image    — camera / EXIF from the representative still
 *
 * @return bool True if tabs or field assignments changed
 */
function image_sequence_ensure_metadata_tabs(): bool
{
    $sequence_tab = image_sequence_ensure_tab('Sequence', 20);
    $image_tab = image_sequence_ensure_tab('Image', 30);
    if ($sequence_tab <= 0 || $image_tab <= 0) {
        return false;
    }

    $layout = image_sequence_metadata_tab_layout($sequence_tab, $image_tab);
    $changed = false;

    foreach ($layout as $shortname => $meta) {
        $field_ref = (int) ps_value(
            'SELECT ref value FROM resource_type_field WHERE name = ?',
            ['s', $shortname],
            0,
            'schema'
        );
        if ($field_ref <= 0) {
            continue;
        }

        $row = ps_query(
            'SELECT tab, order_by, title FROM resource_type_field WHERE ref = ?',
            ['i', $field_ref],
            'schema'
        );
        if ($row === []) {
            continue;
        }

        $cur_tab = (int) ($row[0]['tab'] ?? 0);
        $cur_order = (int) ($row[0]['order_by'] ?? 0);
        $cur_title = (string) ($row[0]['title'] ?? '');
        $want_title = (string) ($meta['title'] ?? $cur_title);

        if ($cur_tab === (int) $meta['tab'] && $cur_order === (int) $meta['order_by'] && $cur_title === $want_title) {
            continue;
        }

        ps_query(
            'UPDATE resource_type_field SET tab = ?, order_by = ?, title = ? WHERE ref = ?',
            ['i', (int) $meta['tab'], 'i', (int) $meta['order_by'], 's', $want_title, 'i', $field_ref]
        );
        $changed = true;
    }

    if ($changed) {
        clear_query_cache('schema');
    }

    return $changed;
}

/**
 * Get or create a system tab (works from CLI without an admin session).
 */
function image_sequence_ensure_tab(string $name, int $order_by): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }

    $ref = (int) ps_value(
        'SELECT ref value FROM tab WHERE name = ?',
        ['s', $name],
        0,
        'schema'
    );
    if ($ref > 0) {
        $current_order = (int) ps_value(
            'SELECT order_by value FROM tab WHERE ref = ?',
            ['i', $ref],
            0,
            'schema'
        );
        if ($current_order !== $order_by) {
            ps_query('UPDATE tab SET order_by = ? WHERE ref = ?', ['i', $order_by, 'i', $ref]);
            clear_query_cache('schema');
        }

        return $ref;
    }

    ps_query('INSERT INTO tab (`name`, order_by) VALUES (?, ?)', ['s', $name, 'i', $order_by]);
    $ref = (int) sql_insert_id();
    clear_query_cache('schema');

    return $ref;
}

/**
 * Field shortname → tab + display order for the view/edit metadata panels.
 *
 * @return array<string, array{tab: int, order_by: int, title: string}>
 */
function image_sequence_metadata_tab_layout(int $sequence_tab, int $image_tab): array
{
    // Sequence: structure / timing / edit points (order_by 2100–2290).
    $sequence = [
        'imgseq_frames' => ['order_by' => 2100, 'title' => 'Frame count'],
        'imgseq_fps' => ['order_by' => 2110, 'title' => 'Playback FPS'],
        'imgseq_duration' => ['order_by' => 2120, 'title' => 'Playback duration'],
        'imgseq_realdur' => ['order_by' => 2130, 'title' => 'Real-time duration'],
        'imgseq_interval' => ['order_by' => 2140, 'title' => 'Interval between frames'],
        'imgseq_cadence' => ['order_by' => 2150, 'title' => 'Capture cadence'],
        'imgseq_firstcap' => ['order_by' => 2160, 'title' => 'First frame capture time'],
        'imgseq_lastcap' => ['order_by' => 2170, 'title' => 'Last frame capture time'],
        'imgseq_inframe' => ['order_by' => 2180, 'title' => 'In point (frame)'],
        'imgseq_outframe' => ['order_by' => 2190, 'title' => 'Out point (frame)'],
        'imgseq_repframe' => ['order_by' => 2200, 'title' => 'Representative frame'],
        'imgseq_expmode' => ['order_by' => 2210, 'title' => 'Exposure program'],
        'imgseq_framesize' => ['order_by' => 2220, 'title' => 'Frame file size'],
        'imgseq_seqcode' => ['order_by' => 2230, 'title' => 'Sequence code'],
        'imgseq_folder' => ['order_by' => 2240, 'title' => 'Folder name'],
        'imgseq_folderpath' => ['order_by' => 2250, 'title' => 'Folder path'],
    ];

    // Image: representative-still camera / technical EXIF (order_by 2300–2490).
    $image = [
        'imgseq_captured' => ['order_by' => 2300, 'title' => 'Capture date'],
        'imgseq_make' => ['order_by' => 2310, 'title' => 'Camera make'],
        'imgseq_model' => ['order_by' => 2320, 'title' => 'Camera model'],
        'imgseq_lens' => ['order_by' => 2330, 'title' => 'Lens'],
        'imgseq_iso' => ['order_by' => 2340, 'title' => 'ISO'],
        'imgseq_aperture' => ['order_by' => 2350, 'title' => 'Aperture'],
        'imgseq_shutter' => ['order_by' => 2360, 'title' => 'Shutter speed'],
        'imgseq_focallen' => ['order_by' => 2370, 'title' => 'Focal length'],
        'imgseq_focal35' => ['order_by' => 2380, 'title' => 'Focal length (35mm)'],
        'imgseq_whitebal' => ['order_by' => 2390, 'title' => 'White balance'],
        'imgseq_flash' => ['order_by' => 2400, 'title' => 'Flash'],
        'imgseq_pixels' => ['order_by' => 2410, 'title' => 'Pixel dimensions'],
        'imgseq_bitdepth' => ['order_by' => 2420, 'title' => 'Bit depth'],
        'imgseq_colorspace' => ['order_by' => 2430, 'title' => 'Color space'],
        'imgseq_orient' => ['order_by' => 2440, 'title' => 'Orientation'],
        'imgseq_software' => ['order_by' => 2450, 'title' => 'Software'],
    ];

    $out = [];
    foreach ($sequence as $shortname => $meta) {
        $out[$shortname] = [
            'tab' => $sequence_tab,
            'order_by' => $meta['order_by'],
            'title' => $meta['title'],
        ];
    }
    foreach ($image as $shortname => $meta) {
        $out[$shortname] = [
            'tab' => $image_tab,
            'order_by' => $meta['order_by'],
            'title' => $meta['title'],
        ];
    }

    return $out;
}

/**
 * Representative-still camera / technical metadata (ExifTool-mapped).
 *
 * @return list<array{title: string, shortname: string, exiftool: string}>
 */
function image_sequence_image_metadata_field_defs(): array
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
    ];
}

/**
 * Sequence-level timing / exposure analysis (not per-tag ExifTool maps).
 *
 * @return list<array{title: string, shortname: string, exiftool: string}>
 */
function image_sequence_sequence_analysis_field_defs(): array
{
    return [
        ['title' => 'First frame capture time', 'shortname' => 'imgseq_firstcap', 'exiftool' => ''],
        ['title' => 'Last frame capture time', 'shortname' => 'imgseq_lastcap', 'exiftool' => ''],
        ['title' => 'Real-time duration', 'shortname' => 'imgseq_realdur', 'exiftool' => ''],
        ['title' => 'Interval between frames', 'shortname' => 'imgseq_interval', 'exiftool' => ''],
        ['title' => 'Exposure program', 'shortname' => 'imgseq_expmode', 'exiftool' => ''],
    ];
}

/**
 * @deprecated Use image_sequence_image_metadata_field_defs() + image_sequence_sequence_analysis_field_defs()
 * @return list<array{title: string, shortname: string, exiftool: string}>
 */
function image_sequence_photo_metadata_field_defs(): array
{
    return array_merge(
        image_sequence_image_metadata_field_defs(),
        image_sequence_sequence_analysis_field_defs()
    );
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

    $resource = get_resource_data($ref);
    if (!is_array($resource)) {
        return;
    }
    $manifest_rel = (string) ($resource['file_path'] ?? '');
    $manifest_ext = (string) ($resource['file_extension'] ?? 'json');
    $swapped = false;
    $created_temp_original = '';

    // Point ResourceSpace at the still so ExifTool / get_resource_path resolve correctly.
    // Prefer a sync-root relative path; for local temp copies, park the file at the
    // resource original path briefly (sequences normally keep originals as JSON).
    if ($still_rel !== null) {
        ps_query(
            'UPDATE resource SET file_path = ?, file_extension = ? WHERE ref = ?',
            ['s', $still_rel, 's', $extension, 'i', $ref]
        );
        unset($GLOBALS['get_resource_data_cache'][$ref], $GLOBALS['get_resource_path_fpcache'][$ref]);
        $swapped = true;
    } else {
        $original_path = get_resource_path($ref, true, '', true, $extension);
        if (@copy($frame_path, $original_path) && is_file($original_path)) {
            ps_query(
                'UPDATE resource SET file_path = ?, file_extension = ? WHERE ref = ?',
                ['s', '', 's', $extension, 'i', $ref]
            );
            unset($GLOBALS['get_resource_data_cache'][$ref], $GLOBALS['get_resource_path_fpcache'][$ref]);
            $swapped = true;
            $created_temp_original = $original_path;
            $frame_path = $original_path;
        }
    }

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
    if ($swapped) {
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

        if ($created_temp_original !== '' && is_file($created_temp_original)) {
            @unlink($created_temp_original);
        }
    }
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
    $chunks = array_chunk(array_values($paths), 80);
    $chunk_total = count($chunks);
    $cli = (PHP_SAPI === 'cli');
    // Chunk to stay under ARG_MAX on large sequences.
    foreach ($chunks as $chunk_i => $chunk) {
        if ($cli && $chunk_total > 1) {
            echo 'dating ' . ($chunk_i + 1) . '/' . $chunk_total . "…\n";
            flush();
        }
        $placeholders = [];
        $args = [];
        foreach ($chunk as $i => $path) {
            $token = '%%F' . $i . '%%';
            $placeholders[] = $token;
            $args[$token] = new CommandPlaceholderArg($path, 'image_sequence_is_valid_shell_path');
        }
        // -T: tab-separated FilePath DateTimeOriginal; -n: numeric where possible.
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
            $file = str_replace('\\', '/', $parts[0]);
            $raw = trim($parts[1] ?? '');
            $ts = image_sequence_parse_exif_datetime($raw);
            if ($ts === null) {
                continue;
            }
            $out[$file] = $ts;
            $out[basename($file)] = $ts;
        }
    }

    // Map onto the exact input paths — never realpath() each file (deadly on SMB).
    $mapped = [];
    foreach ($paths as $path) {
        $norm = str_replace('\\', '/', $path);
        if (isset($out[$path])) {
            $mapped[$path] = $out[$path];
        } elseif (isset($out[$norm])) {
            $mapped[$path] = $out[$norm];
        } elseif (isset($out[basename($norm)])) {
            $mapped[$path] = $out[basename($norm)];
        }
    }

    return $mapped;
}

/**
 * Parse ExifTool date/time output to a unix timestamp.
 *
 * Handles epoch numbers, "YYYY:MM:DD HH:MM:SS", fractional seconds, and
 * common ISO variants. Returns null when the value is empty or unparseable.
 */
function image_sequence_parse_exif_datetime(string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '-' || strcasecmp($raw, '0000:00:00 00:00:00') === 0) {
        return null;
    }
    if (is_numeric($raw)) {
        return (float) $raw;
    }

    // ExifTool default / -n date: 2012:01:07 23:08:26 or with fractional seconds.
    if (preg_match(
        '/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(\.\d+)?/',
        $raw,
        $m
    ) === 1) {
        $parsed = strtotime(sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]));
        if ($parsed !== false) {
            return (float) $parsed + (isset($m[7]) ? (float) $m[7] : 0.0);
        }
    }

    $parsed = strtotime($raw);
    if ($parsed === false) {
        return null;
    }

    return (float) $parsed;
}

/**
 * Write timeline fields from EXIF only (never filesystem mtime).
 *
 * Dates first + last frames for start/end/real duration, and samples consecutive
 * pairs along the sequence for capture cadence. Full-folder EXIF dating is
 * reserved for the manual “auto-detect and split shots” action.
 *
 * @param list<array{path: string, date?: float}|string> $segment Paths or path rows
 */
function image_sequence_apply_timeline_from_dated_segment(int $ref, array $segment, ?float $cadence = null): void
{
    if ($ref <= 0 || $segment === []) {
        return;
    }

    $paths = [];
    $seen = [];
    foreach ($segment as $row) {
        $path = is_string($row)
            ? $row
            : (isset($row['path']) ? (string) $row['path'] : '');
        $path = str_replace('\\', '/', $path);
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $paths[] = $path;
    }

    image_sequence_apply_sequence_timeline_sparse($ref, $paths, $cadence);
}

/**
 * Sparse EXIF timeline: first/last capture + sampled cadence. No mtime fallback.
 *
 * @param list<string> $member_paths Absolute paths in sequence order
 */
function image_sequence_apply_sequence_timeline_sparse(int $ref, array $member_paths, ?float $cadence = null): void
{
    if ($ref <= 0 || $member_paths === []) {
        return;
    }

    image_sequence_ensure_setup();

    $paths = array_values($member_paths);
    $n = count($paths);

    // First + last for span; cadence samples for interval.
    $probe = [$paths[0]];
    if ($n > 1) {
        $probe[] = $paths[$n - 1];
    }
    foreach (image_sequence_paths_for_cadence_sample($paths, 24) as $p) {
        $probe[] = $p;
    }
    $probe = array_values(array_unique($probe));

    if (PHP_SAPI === 'cli') {
        echo '  timeline EXIF on ' . count($probe) . " frames (first/last + cadence samples)…\n";
        flush();
    }

    $exif_dates = image_sequence_batch_effective_dates($probe);

    $first_ts = $exif_dates[$paths[0]] ?? null;
    $last_ts = $exif_dates[$paths[$n - 1]] ?? $first_ts;
    if ($first_ts === null || $first_ts <= 0) {
        // No EXIF on the first frame — do not invent times from mtime.
        $first_ts = null;
        $last_ts = null;
    } elseif ($last_ts === null || $last_ts <= 0) {
        $last_ts = $first_ts;
    }

    $real_duration = ($first_ts !== null && $last_ts !== null)
        ? max(0.0, (float) $last_ts - (float) $first_ts)
        : 0.0;

    $interval = $cadence;
    if ($interval === null) {
        $interval = image_sequence_estimate_cadence_from_paths($paths);
    }

    $exposure_summary = '';
    try {
        $exposure_summary = image_sequence_analyze_exposure_mode(
            image_sequence_sample_paths($paths, 5)
        );
    } catch (Throwable $e) {
        debug('image_sequence_apply_sequence_timeline_sparse exposure: ' . $e->getMessage());
    }

    $map = [
        'imgseq_firstcap' => $first_ts !== null ? image_sequence_format_capture_timestamp((float) $first_ts) : '',
        'imgseq_lastcap' => $last_ts !== null ? image_sequence_format_capture_timestamp((float) $last_ts) : '',
        'imgseq_realdur' => $real_duration > 0 ? image_sequence_format_duration_label($real_duration) : '',
        'imgseq_interval' => $interval === null ? '' : image_sequence_format_interval_label($interval),
        'imgseq_expmode' => $exposure_summary,
        'imgseq_captured' => $first_ts !== null ? image_sequence_format_capture_timestamp((float) $first_ts) : '',
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

    if ($interval !== null) {
        ps_query(
            'UPDATE resource_image_sequence SET detected_cadence_seconds = ? WHERE resource = ?',
            ['d', (float) $interval, 'i', $ref]
        );
        global $image_sequence_cadence_field;
        if ((int) $image_sequence_cadence_field > 0) {
            update_field($ref, (int) $image_sequence_cadence_field, (string) round((float) $interval, 3));
        }
    }
}

/**
 * Write sequence timeline / exposure analysis into metadata fields (EXIF sparse).
 *
 * @param list<string> $member_paths
 */
function image_sequence_apply_sequence_timeline_metadata(int $ref, array $member_paths): void
{
    image_sequence_apply_sequence_timeline_sparse($ref, $member_paths, null);
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
            ['%%FILE%%' => new CommandPlaceholderArg($path, 'image_sequence_is_valid_shell_path')]
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

/**
 * Ensure in_frame / out_frame columns exist (CheckDBStruct also adds from dbstruct).
 */
function image_sequence_ensure_db_columns(): void
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

    $columns = [
        'in_frame' => 'int(11) NULL DEFAULT 0',
        'out_frame' => 'int(11) NULL DEFAULT NULL',
    ];
    foreach ($columns as $name => $definition) {
        $has = (int) ps_value(
            "SELECT COUNT(*) value FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'resource_image_sequence'
               AND column_name = ?",
            ['s', $name],
            0
        );
        if ($has > 0) {
            continue;
        }
        ps_query(
            'ALTER TABLE resource_image_sequence ADD COLUMN ' . $name . ' ' . $definition,
            [],
            '',
            -1,
            false
        );
    }
}

function image_sequence_is_sequence_resource(array $resource): bool
{
    global $image_sequence_restype;

    return (int) ($resource['resource_type'] ?? 0) === (int) $image_sequence_restype
        && (int) $image_sequence_restype > 0;
}

/**
 * Sequence code for search/collection cards (from joined field or live lookup).
 */
function image_sequence_get_card_sequence_code(array $resource): string
{
    global $image_sequence_seqcode_field;

    if (!image_sequence_is_sequence_resource($resource)) {
        return '';
    }

    $field = (int) $image_sequence_seqcode_field;
    if ($field <= 0) {
        return '';
    }

    $key = 'field' . $field;
    if (isset($resource[$key]) && trim((string) $resource[$key]) !== '') {
        return trim((string) $resource[$key]);
    }

    $ref = (int) ($resource['ref'] ?? 0);
    if ($ref <= 0) {
        return '';
    }

    return trim((string) get_data_by_field($ref, $field));
}

/**
 * Team tools / web ingest access: sysadmins or groups granted the "is" permission.
 */
function image_sequence_can_access_tools(): bool
{
    return checkperm('a') || checkperm('is');
}

/**
 * Primary scan root (read-only). Used for relative paths of on-disk stills.
 * Never write into this tree — manifests and uploads go elsewhere.
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

    return '';
}

/**
 * Scan roots for CLI folder ingest. Treated as read-only: frames are referenced
 * in place; the plugin must not create, modify, or delete files here.
 *
 * @return list<string>
 */
function image_sequence_scan_roots(): array
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

    return array_values(array_unique($roots));
}

/**
 * Writable staging root for web ZIP/multi-file uploads (under filestore, not scan roots).
 */
function image_sequence_staging_root(): string
{
    global $storagedir, $image_sequence_upload_subdir;

    $subdir = trim((string) $image_sequence_upload_subdir, '/');
    if ($subdir === '') {
        $subdir = 'image_sequences';
    }

    $base = !empty($storagedir) ? rtrim((string) $storagedir, '/') : rtrim(get_temp_dir(false), '/');
    $root = $base . '/' . $subdir;
    if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
        return '';
    }

    return $root;
}

/**
 * Roots allowed for path resolution: read-only scan dirs plus writable staging.
 *
 * @return list<string>
 */
function image_sequence_allowed_roots(): array
{
    $roots = image_sequence_scan_roots();

    $staging = image_sequence_staging_root();
    if ($staging !== '' && is_dir($staging)) {
        $roots[] = $staging;
    }

    return array_values(array_unique($roots));
}

/**
 * Shell-safe path check for ExifTool/FFmpeg args on sequence frames.
 * Extends core is_valid_rs_path() so configured scan roots (e.g. external volumes) are allowed.
 */
function image_sequence_is_valid_shell_path(string $path): bool
{
    global $storagedir, $syncdir, $fstemplate_alt_storagedir, $tempdir;

    $override = image_sequence_allowed_roots();
    foreach ([$storagedir ?? '', $syncdir ?? '', $fstemplate_alt_storagedir ?? '', $tempdir ?? ''] as $extra) {
        $extra = rtrim((string) $extra, '/');
        if ($extra !== '') {
            $override[] = $extra;
        }
    }
    $override[] = dirname(__DIR__, 3) . '/gfx';

    return is_valid_rs_path($path, array_values(array_unique(array_filter($override))));
}

/**
 * Whether an absolute path sits under a configured scan root (read-only stills tree).
 */
function image_sequence_path_under_scan_root(string $absolute_path): bool
{
    $absolute_path = str_replace('\\', '/', $absolute_path);
    $absolute_real = realpath($absolute_path) ?: $absolute_path;
    $absolute_real = str_replace('\\', '/', $absolute_real);

    foreach (image_sequence_scan_roots() as $root) {
        $root_real = realpath($root) ?: $root;
        $root_real = rtrim(str_replace('\\', '/', $root_real), '/');
        if ($absolute_real === $root_real || strpos($absolute_real, $root_real . '/') === 0) {
            return true;
        }
    }

    return false;
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
 * Effective capture date as unix timestamp from EXIF only (never filesystem mtime).
 */
function image_sequence_get_effective_date(string $path): float
{
    $exiftool = get_utility_path('exiftool');
    if ($exiftool !== false && is_readable($path)) {
        $output = run_command(
            $exiftool . ' -s3 -DateTimeOriginal -DateTime -n %%FILE%%',
            false,
            ['%%FILE%%' => new CommandPlaceholderArg($path, 'image_sequence_is_valid_shell_path')]
        );
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $output)) ?: [];
        foreach ($lines as $line) {
            $ts = image_sequence_parse_exif_datetime($line);
            if ($ts !== null) {
                return $ts;
            }
        }
    }

    return 0.0;
}

/**
 * Enumerate stills under a folder (non-recursive by default).
 *
 * Returns paths only (date=0). Capture times are never taken from mtime —
 * timeline/cadence EXIF reads happen later on first/last + samples.
 * Paths are de-duplicated (some SMB iterators yield the same file repeatedly).
 * Order is natural filename order (one folder = one continuous shot).
 *
 * Flat numbered shoots prefer pattern discovery (binary-search first/last) over
 * scandir — SMB directory listings are often incomplete or hang on large folders.
 *
 * @return list<array{path: string, date: float}>
 */
function image_sequence_list_stills_in_folder(string $folder, bool $recursive = false): array
{
    $folder = rtrim(str_replace('\\', '/', $folder), '/');
    if (!is_dir($folder)) {
        return [];
    }

    // path => true (assoc de-dupes iterator repeats on flaky network FS).
    $path_set = [];
    if ($recursive) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $folder,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
            )
        );
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }
            $base = $fileinfo->getFilename();
            if ($base === '' || $base[0] === '.') {
                continue;
            }
            $path = str_replace('\\', '/', $fileinfo->getPathname());
            if (!image_sequence_is_supported_file($path)) {
                continue;
            }
            $path_set[$path] = true;
        }
    } else {
        // Prefer pattern discovery — avoids hung/incomplete SMB readdir on big shoots.
        $discovered = image_sequence_discover_flat_numbered_stills($folder);
        if ($discovered !== null) {
            foreach ($discovered as $path) {
                $path_set[$path] = true;
            }
        } else {
            // Flat shoot folders: filter by extension only — per-file is_file()/stat on SMB
            // is extremely slow (thousands of round-trips) and was hanging re-ingest.
            foreach (scandir($folder) ?: [] as $base) {
                if ($base === '.' || $base === '..' || $base[0] === '.') {
                    continue;
                }
                if (!image_sequence_is_supported_file($base)) {
                    continue;
                }
                $path_set[$folder . '/' . $base] = true;
            }
            // SMB mounts sometimes return incomplete / duplicated directory listings.
            $paths = array_keys($path_set);
            if ($paths !== []) {
                $paths = image_sequence_expand_numbered_stills_listing($folder, $paths);
                $path_set = array_fill_keys($paths, true);
            }
        }
    }

    $paths = array_keys($path_set);

    if (PHP_SAPI === 'cli' && $paths !== []) {
        echo '  found ' . count($paths) . " stills\n";
        flush();
    }

    $files = [];
    foreach ($paths as $path) {
        $files[] = ['path' => $path, 'date' => 0.0];
    }

    usort($files, static function ($a, $b) {
        return strnatcasecmp(basename($a['path']), basename($b['path']));
    });

    return $files;
}

/**
 * Discover a contiguous numbered still sequence from the folder basename without
 * readdir. Uses a few is_file probes + binary search for the last frame.
 *
 * Expected name: {folder}/{folderName}_{NNNNN}.JPG (underscore/dash/none, pad 3–7).
 *
 * @return list<string>|null Absolute paths, or null if no pattern matches
 */
function image_sequence_discover_flat_numbered_stills(string $folder): ?array
{
    $folder = rtrim(str_replace('\\', '/', $folder), '/');
    $code = basename($folder);
    if ($code === '' || $code === '.' || $code === '..') {
        return null;
    }

    $exts = [];
    foreach (image_sequence_supported_extensions() as $ext) {
        $ext = strtolower(ltrim((string) $ext, '.'));
        if ($ext === '') {
            continue;
        }
        // Prefer uppercase first — these archives are typically .JPG on SMB.
        $exts[strtoupper($ext)] = true;
        $exts[$ext] = true;
    }
    if ($exts === []) {
        $exts = ['JPG' => true, 'jpg' => true];
    }

    $seps = ['_', '-', ''];
    $pads = [5, 4, 6, 3, 7];

    foreach ($seps as $sep) {
        foreach (array_keys($exts) as $ext) {
            foreach ($pads as $pad) {
                $start = null;
                foreach ([1, 0, 2] as $n) {
                    $candidate = $folder . '/' . $code . $sep
                        . str_pad((string) $n, $pad, '0', STR_PAD_LEFT) . '.' . $ext;
                    if (@is_file($candidate)) {
                        $start = $n;
                        break;
                    }
                }
                if ($start === null) {
                    continue;
                }

                // Exponential search for an upper bound past the last frame.
                $lo = $start;
                $hi = $start + 1;
                while ($hi - $start < 500000) {
                    $candidate = $folder . '/' . $code . $sep
                        . str_pad((string) $hi, $pad, '0', STR_PAD_LEFT) . '.' . $ext;
                    if (!@is_file($candidate)) {
                        break;
                    }
                    $lo = $hi;
                    $hi = ($hi === $start + 1) ? $start + 2 : $hi * 2;
                }

                // Binary search last existing frame in ($lo, $hi).
                $end = $lo;
                $left = $lo + 1;
                $right = $hi - 1;
                while ($left <= $right) {
                    $mid = intdiv($left + $right, 2);
                    $candidate = $folder . '/' . $code . $sep
                        . str_pad((string) $mid, $pad, '0', STR_PAD_LEFT) . '.' . $ext;
                    if (@is_file($candidate)) {
                        $end = $mid;
                        $left = $mid + 1;
                    } else {
                        $right = $mid - 1;
                    }
                }

                if ($end < $start) {
                    continue;
                }

                $paths = [];
                for ($n = $start; $n <= $end; $n++) {
                    $paths[] = $folder . '/' . $code . $sep
                        . str_pad((string) $n, $pad, '0', STR_PAD_LEFT) . '.' . $ext;
                }

                if (PHP_SAPI === 'cli') {
                    echo '  discovered numbered sequence '
                        . $code . $sep . '%0' . $pad . 'd.' . $ext
                        . " frames {$start}–{$end} (" . count($paths) . ")\n";
                    flush();
                }

                return $paths;
            }
        }
    }

    return null;
}

/**
 * When a flat folder listing looks like a padded frame sequence but is missing
 * numbers in [min,max], probe a few gaps and — if they exist on disk — rebuild
 * the full contiguous path list from the pattern (no per-frame listing needed).
 *
 * @param list<string> $paths
 * @return list<string>
 */
function image_sequence_expand_numbered_stills_listing(string $folder, array $paths): array
{
    $folder = rtrim(str_replace('\\', '/', $folder), '/');
    $parsed = [];
    foreach ($paths as $path) {
        $base = basename($path);
        if (!preg_match('/^(.*?)(\d+)(\.[^.]+)$/', $base, $m)) {
            return $paths;
        }
        $parsed[] = [
            'prefix' => $m[1],
            'number' => (int) $m[2],
            'pad' => strlen($m[2]),
            'suffix' => $m[3],
        ];
    }
    if (count($parsed) < 3) {
        return $paths;
    }

    $prefix = $parsed[0]['prefix'];
    $suffix = $parsed[0]['suffix'];
    $pad = $parsed[0]['pad'];
    foreach ($parsed as $row) {
        if ($row['prefix'] !== $prefix || $row['suffix'] !== $suffix || $row['pad'] !== $pad) {
            return $paths;
        }
    }

    $numbers = [];
    foreach ($parsed as $row) {
        $numbers[$row['number']] = true;
    }
    $min = min(array_keys($numbers));
    $max = max(array_keys($numbers));
    $expected = $max - $min + 1;
    $listed = count($numbers);
    if ($expected <= $listed) {
        return $paths; // contiguous (or denser than range — already complete)
    }

    // Spot-check unlisted numbers in the span — SMB often omits names from
    // readdir while the files remain reachable by path.
    $missing = [];
    for ($n = $min; $n <= $max; $n++) {
        if (!isset($numbers[$n])) {
            $missing[] = $n;
        }
    }
    $probe_count = min(12, count($missing));
    $probe_hits = 0;
    if ($probe_count > 0) {
        $step = max(1, (int) floor(count($missing) / $probe_count));
        for ($i = 0; $i < $probe_count; $i++) {
            $n = $missing[min(count($missing) - 1, $i * $step)];
            $candidate = $folder . '/' . $prefix . str_pad((string) $n, $pad, '0', STR_PAD_LEFT) . $suffix;
            if (@is_file($candidate)) {
                $probe_hits++;
            }
        }
    }
    // Require most probes to exist before trusting a full expand.
    if ($probe_hits < max(2, (int) ceil($probe_count * 0.6))) {
        if (PHP_SAPI === 'cli') {
            echo "  listing incomplete ({$listed}/{$expected}) but gap probes failed"
                . " ({$probe_hits}/{$probe_count}) — keeping listed files only\n";
            flush();
        }
        return $paths;
    }

    // Widen bounds a little in case min/max were also missing from the listing.
    $widen_hits = 0;
    for ($guard = 0; $guard < 5000; $guard++) {
        $n = $min - 1;
        $candidate = $folder . '/' . $prefix . str_pad((string) $n, $pad, '0', STR_PAD_LEFT) . $suffix;
        if (!@is_file($candidate)) {
            break;
        }
        $min = $n;
        $widen_hits++;
    }
    for ($guard = 0; $guard < 5000; $guard++) {
        $n = $max + 1;
        $candidate = $folder . '/' . $prefix . str_pad((string) $n, $pad, '0', STR_PAD_LEFT) . $suffix;
        if (!@is_file($candidate)) {
            break;
        }
        $max = $n;
        $widen_hits++;
    }

    $expanded = [];
    for ($n = $min; $n <= $max; $n++) {
        $expanded[] = $folder . '/' . $prefix . str_pad((string) $n, $pad, '0', STR_PAD_LEFT) . $suffix;
    }

    if (PHP_SAPI === 'cli') {
        echo '  expanded numbered listing ' . $listed . ' → ' . count($expanded)
            . " (pattern {$prefix}%0{$pad}d{$suffix}, probes {$probe_hits}/{$probe_count}"
            . ($widen_hits > 0 ? ", widened +{$widen_hits}" : '') . ")\n";
        flush();
    }

    return $expanded;
}

/**
 * Paths to EXIF-date for cadence estimation: consecutive pairs sampled along the sequence.
 *
 * @param list<string> $paths
 * @return list<string>
 */
function image_sequence_paths_for_cadence_sample(array $paths, int $max_pairs = 24): array
{
    $n = count($paths);
    if ($n < 2) {
        return $paths;
    }

    $max_pairs = max(1, min($max_pairs, $n - 1));
    $indices = [];
    for ($i = 0; $i < $max_pairs; $i++) {
        $start = (int) round($i * ($n - 2) / max($max_pairs - 1, 1));
        $indices[$start] = true;
        $indices[$start + 1] = true;
    }
    // Always include the very first and last consecutive pairs when possible.
    $indices[0] = true;
    $indices[1] = true;
    if ($n >= 3) {
        $indices[$n - 2] = true;
        $indices[$n - 1] = true;
    }

    ksort($indices, SORT_NUMERIC);
    $out = [];
    foreach (array_keys($indices) as $idx) {
        $out[] = $paths[$idx];
    }

    return $out;
}

/**
 * Estimate normal inter-frame interval from EXIF on sampled consecutive pairs.
 *
 * @param list<string> $paths Absolute paths in sequence order
 */
function image_sequence_estimate_cadence_from_paths(array $paths): ?float
{
    global $image_sequence_max_cadence_sample;

    $paths = array_values($paths);
    $n = count($paths);
    if ($n < 2) {
        return null;
    }

    $sample = image_sequence_paths_for_cadence_sample($paths, 24);
    $dates = image_sequence_batch_effective_dates($sample);
    if ($dates === []) {
        return null;
    }

    $max_sample = (float) ($image_sequence_max_cadence_sample ?? 180);
    $index_of = array_flip($paths);
    $intervals = [];

    // Consecutive pairs within the sample that are also adjacent in the full sequence.
    $sample_list = array_values(array_unique($sample));
    usort($sample_list, static function ($a, $b) use ($index_of) {
        return ($index_of[$a] ?? 0) <=> ($index_of[$b] ?? 0);
    });

    for ($i = 1, $m = count($sample_list); $i < $m; $i++) {
        $prev = $sample_list[$i - 1];
        $curr = $sample_list[$i];
        $ip = $index_of[$prev] ?? -1;
        $ic = $index_of[$curr] ?? -1;
        if ($ip < 0 || $ic !== $ip + 1) {
            continue;
        }
        $t0 = $dates[$prev] ?? null;
        $t1 = $dates[$curr] ?? null;
        if ($t0 === null || $t1 === null) {
            continue;
        }
        $gap = (float) $t1 - (float) $t0;
        if ($gap > 0 && $gap <= $max_sample) {
            $intervals[] = $gap;
        }
    }

    if ($intervals === []) {
        return null;
    }

    sort($intervals, SORT_NUMERIC);

    return (float) $intervals[(int) floor(count($intervals) / 2)];
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
 * Basenames already claimed by any live Image Sequence in a folder.
 *
 * Soft-deleted resources (archive = $resource_deletion_state) do not claim frames,
 * so a folder can be re-ingested after delete.
 *
 * @return array<string, int> basename => resource ref
 */
function image_sequence_claimed_basenames_in_folder(string $folder_rel): array
{
    global $resource_deletion_state;

    $claimed = [];
    $rows = ps_query(
        'SELECT ris.resource, ris.member_files, r.archive
           FROM resource_image_sequence ris
           JOIN resource r ON r.ref = ris.resource
          WHERE ris.folder_path = ?',
        ['s', $folder_rel]
    );
    foreach ($rows as $row) {
        if (isset($resource_deletion_state) && (int) $row['archive'] === (int) $resource_deletion_state) {
            continue;
        }
        $members = json_decode((string) $row['member_files'], true);
        if (!is_array($members)) {
            continue;
        }
        foreach ($members as $name) {
            $claimed[(string) $name] = (int) $row['resource'];
            $claimed[strtolower((string) $name)] = (int) $row['resource'];
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
    global $resource_deletion_state;

    $wanted = array_map('strtolower', $member_basenames);
    sort($wanted);
    $rows = ps_query(
        'SELECT ris.resource, ris.member_files, ris.frame_count, r.archive
           FROM resource_image_sequence ris
           JOIN resource r ON r.ref = ris.resource
          WHERE ris.folder_path = ?',
        ['s', $folder_rel]
    );
    foreach ($rows as $row) {
        if (isset($resource_deletion_state) && (int) $row['archive'] === (int) $resource_deletion_state) {
            continue;
        }
        // One folder = one live sequence: any live row for this path counts.
        if ((int) $row['frame_count'] > 0) {
            return (int) $row['resource'];
        }
        $members = json_decode((string) $row['member_files'], true);
        if (!is_array($members)) {
            continue;
        }
        $have = array_map('strtolower', $members);
        sort($have);
        if ($have === $wanted) {
            return (int) $row['resource'];
        }
    }

    return 0;
}

/**
 * Ingest a folder of stills as Image Sequence resource(s).
 *
 * By default each source folder is one continuous sequence (no cadence break
 * splitting). Capture times come from EXIF samples only (first/last + cadence
 * pairs) — never filesystem mtime. Use the manual “auto-detect and split shots”
 * action later if a folder actually contains multiple shots.
 *
 * When $options['auto_split'] is true (or the legacy setup flag), frames are
 * fully EXIF-dated and split with Ingestr cadence rules (expensive on NAS).
 *
 * @return array{sequences: list<int>, photos: list<int>, skipped: list<int>}
 */
function image_sequence_ingest_folder(string $folder_absolute, array $options = []): array
{
    global $image_sequence_restype, $image_sequence_photo_restype, $image_sequence_auto_split,
        $image_sequence_min_frames, $lang;

    image_sequence_ensure_setup();

    $folder_absolute = rtrim(str_replace('\\', '/', $folder_absolute), '/');
    if (!is_dir($folder_absolute) || !image_sequence_path_under_allowed_root($folder_absolute)) {
        return ['sequences' => [], 'photos' => [], 'skipped' => []];
    }

    $recursive = (bool) ($options['recursive'] ?? false);
    // Default: do not split on ingest (one folder = one shot).
    $auto_split = array_key_exists('auto_split', $options)
        ? (bool) $options['auto_split']
        : (bool) ($image_sequence_auto_split ?? false);
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
        $base = basename($file['path']);
        return !isset($claimed[$base]) && !isset($claimed[strtolower($base)]);
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

    // Stable order by first filename in each group.
    uasort($groups, static function ($a, $b) {
        return strnatcasecmp(basename($a[0]['path']), basename($b[0]['path']));
    });

    $sequence_refs = [];
    $photo_refs = [];
    $skipped_refs = [];

    foreach ($groups as $group_files) {
        // Filename order — one continuous shot per folder.
        usort($group_files, static function ($a, $b) {
            return strnatcasecmp(basename($a['path']), basename($b['path']));
        });

        if ($auto_split) {
            // Legacy / explicit: full EXIF dating then cadence break split.
            if (PHP_SAPI === 'cli') {
                echo '  auto-split: reading EXIF dates for ' . count($group_files) . " frames…\n";
                flush();
            }
            $paths = array_column($group_files, 'path');
            $dates = image_sequence_batch_effective_dates($paths);
            $dated = [];
            foreach ($group_files as $file) {
                $ts = $dates[$file['path']] ?? 0.0;
                if ($ts <= 0) {
                    continue; // skip frames with no EXIF — never fall back to mtime
                }
                $dated[] = ['path' => $file['path'], 'date' => (float) $ts];
            }
            usort($dated, static function ($a, $b) {
                return $a['date'] <=> $b['date'] ?: strnatcasecmp(basename($a['path']), basename($b['path']));
            });
            $cadence = image_sequence_detect_normal_interval($dated);
            $segments = image_sequence_split_files($dated, true);
        } else {
            // Sparse EXIF for cadence happens inside create → apply_timeline_sparse
            // (one ExifTool pass for first/last + samples). Do not pre-date here.
            $cadence = null;
            $segments = [$group_files];
        }

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
 * @param ?string $sequence_code Optional override (e.g. CODE-1 from shot split); null uses folder-derived code
 */
function image_sequence_create_sequence_resource(array $segment, ?float $cadence, int $created_by = 0, ?string $sequence_code = null): int
{
    global $image_sequence_restype, $image_sequence_fps_default, $lang;

    if ($segment === [] || (int) $image_sequence_restype <= 0) {
        return 0;
    }

    $folder = dirname($segment[0]['path']);
    $folder = rtrim(str_replace('\\', '/', $folder), '/');
    $folder_rel = image_sequence_absolute_to_relative($folder);
    if ($folder_rel === null) {
        return 0;
    }
    $folder_meta = image_sequence_folder_metadata($folder);
    $resolved_sequence_code = ($sequence_code !== null && trim($sequence_code) !== '')
        ? trim($sequence_code)
        : $folder_meta['sequence_code'];

    $member_basenames = [];
    $seen_bases = [];
    foreach ($segment as $file) {
        $base = basename($file['path']);
        if (!image_sequence_is_safe_member_basename($base) || isset($seen_bases[$base])) {
            continue;
        }
        $seen_bases[$base] = true;
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

    // Manifest lives in filestore — never write into the (read-only) scan tree.
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
    $manifest_abs = get_resource_path($ref, true, '', true, 'json');
    file_put_contents($manifest_abs, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Empty file_path → ResourceSpace resolves the original via filestore.
    ps_query(
        'UPDATE resource SET archive=0, file_path=?, file_extension=?, preview_extension=?, file_modified=NOW(), no_file=0 WHERE ref=?',
        ['s', '', 's', 'json', 's', 'jpg', 'i', $ref]
    );
    unset($GLOBALS['get_resource_data_cache'][$ref], $GLOBALS['get_resource_path_fpcache'][$ref]);

    ps_query(
        'INSERT INTO resource_image_sequence
            (resource, folder_path, member_files, frame_pattern, start_number, end_number, frame_count,
             extension, fps, duration_seconds, detected_cadence_seconds, representative_frame,
             in_frame, out_frame, proxy_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
            'i', 0,
            'i', max(0, $frame_count - 1),
            's', 'pending',
        ]
    );

    image_sequence_update_metadata_fields($ref, [
        'frame_count' => $frame_count,
        'duration' => $duration,
        'fps' => $fps,
        'cadence' => $cadence,
        'folder' => $folder_meta['folder_name'],
        'folder_path' => $folder_meta['folder_path'],
        'sequence_code' => $resolved_sequence_code,
        'representative_frame' => 0,
        'in_frame' => 0,
        'out_frame' => max(0, $frame_count - 1),
    ]);

    $member_paths = [];
    foreach ($segment as $file) {
        if (!empty($file['path'])) {
            $member_paths[] = (string) $file['path'];
        }
    }
    try {
        // Sparse EXIF: first/last + cadence samples only (never mtime).
        image_sequence_apply_timeline_from_dated_segment($ref, $segment, $cadence);
        if ($member_paths !== []) {
            image_sequence_extract_frame_metadata($ref, $member_paths[0]);
            // Default representative still (frame 0) into managed storage.
            image_sequence_save_representative_alt_file($ref, $member_paths[0], 0);
        }
    } catch (Throwable $e) {
        debug('image_sequence_create_sequence_resource timeline/metadata: ' . $e->getMessage());
    }

    image_sequence_queue_proxy_job($ref);

    return (int) $ref;
}

/**
 * Derive folder name, absolute path, and sequence code from a sequence folder.
 *
 * Sequence code = leading characters of the folder basename up to (but not
 * including) the first breaking character: space, dash, or underscore.
 * If there is no breaker, the whole folder name is used.
 *
 * @return array{folder_name: string, folder_path: string, sequence_code: string}
 */
function image_sequence_folder_metadata(string $folder_absolute): array
{
    $folder_path = rtrim(str_replace('\\', '/', $folder_absolute), '/');
    $folder_name = basename($folder_path);
    if (preg_match('/^([^ \-_]+)/', $folder_name, $matches) === 1) {
        $sequence_code = $matches[1];
    } else {
        $sequence_code = $folder_name;
    }
    if ($sequence_code === '') {
        $sequence_code = $folder_name;
    }

    return [
        'folder_name' => $folder_name,
        'folder_path' => $folder_path,
        'sequence_code' => $sequence_code,
    ];
}

/**
 * @param array{
 *   frame_count?: int|float,
 *   duration?: float,
 *   fps?: float,
 *   cadence?: float|null,
 *   folder?: string,
 *   folder_path?: string,
 *   sequence_code?: string,
 *   representative_frame?: int,
 *   in_frame?: int,
 *   out_frame?: int
 * } $values
 */
function image_sequence_update_metadata_fields(int $ref, array $values): void
{
    global $image_sequence_framecount_field, $image_sequence_duration_field, $image_sequence_fps_field,
        $image_sequence_repframe_field, $image_sequence_inframe_field, $image_sequence_outframe_field,
        $image_sequence_cadence_field, $image_sequence_folder_field, $image_sequence_folderpath_field,
        $image_sequence_seqcode_field;

    $map = [
        'frame_count' => $image_sequence_framecount_field,
        'duration' => $image_sequence_duration_field,
        'fps' => $image_sequence_fps_field,
        'representative_frame' => $image_sequence_repframe_field,
        'in_frame' => $image_sequence_inframe_field,
        'out_frame' => $image_sequence_outframe_field,
        'cadence' => $image_sequence_cadence_field,
        'folder' => $image_sequence_folder_field,
        'folder_path' => $image_sequence_folderpath_field,
        'sequence_code' => $image_sequence_seqcode_field,
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
 * De-dupe stored member_files and refresh first/last/duration from EXIF.
 *
 * Use after fast-ingest runs that stored mtime-based timelines (wrong for
 * multi-day shoots whose files were bulk-copied) or when SMB listing
 * duplicated frames.
 *
 * @return array{ok: bool, resource: int, frames_before: int, frames_after: int, message: string}
 */
function image_sequence_repair_sequence_timeline(int $ref): array
{
    global $image_sequence_fps_default;

    $result = [
        'ok' => false,
        'resource' => $ref,
        'frames_before' => 0,
        'frames_after' => 0,
        'message' => '',
    ];

    $data = image_sequence_get_data($ref);
    if ($data === null) {
        $result['message'] = 'not an image sequence';
        return $result;
    }

    $before = $data['member_files_list'];
    $result['frames_before'] = count($before);

    $unique = [];
    $seen = [];
    foreach ($before as $name) {
        $name = (string) $name;
        if (!image_sequence_is_safe_member_basename($name) || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $unique[] = $name;
    }
    $result['frames_after'] = count($unique);
    if ($unique === []) {
        $result['message'] = 'no valid member frames';
        return $result;
    }

    $fps = (float) ($data['fps'] ?? $image_sequence_fps_default);
    if ($fps <= 0) {
        $fps = (float) $image_sequence_fps_default;
    }
    $frame_count = count($unique);
    $duration = $frame_count / max($fps, 0.0001);
    $pattern_info = image_sequence_infer_frame_pattern($unique);
    $cadence = isset($data['detected_cadence_seconds']) ? (float) $data['detected_cadence_seconds'] : null;
    if ($cadence !== null && $cadence <= 0) {
        $cadence = null;
    }

    ps_query(
        'UPDATE resource_image_sequence
            SET member_files = ?, frame_pattern = ?, start_number = ?, end_number = ?,
                frame_count = ?, duration_seconds = ?, out_frame = ?
            WHERE resource = ?',
        [
            's', json_encode($unique, JSON_UNESCAPED_SLASHES),
            's', $pattern_info['pattern'] ?? '',
            'i', $pattern_info['start'] ?? 0,
            'i', $pattern_info['end'] ?? 0,
            'i', $frame_count,
            'd', $duration,
            'i', max(0, $frame_count - 1),
            'i', $ref,
        ]
    );

    // Keep filestore manifest in sync when present.
    $manifest_abs = get_resource_path($ref, true, '', false, 'json');
    if (is_string($manifest_abs) && $manifest_abs !== '' && is_file($manifest_abs)) {
        $payload = json_decode((string) file_get_contents($manifest_abs), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['member_files'] = $unique;
        $payload['frame_count'] = $frame_count;
        $payload['fps'] = $fps;
        $payload['frame_pattern'] = $pattern_info['pattern'] ?? null;
        $payload['start_number'] = $pattern_info['start'] ?? 0;
        $payload['end_number'] = $pattern_info['end'] ?? 0;
        file_put_contents($manifest_abs, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    image_sequence_update_metadata_fields($ref, [
        'frame_count' => $frame_count,
        'duration' => $duration,
        'fps' => $fps,
        'out_frame' => max(0, $frame_count - 1),
    ]);

    $data = image_sequence_get_data($ref);
    $paths = $data !== null ? image_sequence_member_absolute_paths($data) : [];
    $segment = [];
    foreach ($paths as $path) {
        $segment[] = ['path' => $path, 'date' => 0.0];
    }
    // Re-estimate cadence from EXIF samples (ignore any stale mtime-based value).
    image_sequence_apply_timeline_from_dated_segment($ref, $segment, null);

    $result['ok'] = true;
    $result['message'] = $result['frames_before'] === $result['frames_after']
        ? 'timeline refreshed from EXIF'
        : 'de-duped members and refreshed timeline from EXIF';

    return $result;
}

/**
 * Repair all image sequences under a relative folder path (e.g. 2012/1212301CO).
 *
 * @return list<array{ok: bool, resource: int, frames_before: int, frames_after: int, message: string}>
 */
function image_sequence_repair_folder_timelines(string $folder_rel): array
{
    $folder_rel = trim(str_replace('\\', '/', $folder_rel), '/');
    $rows = ps_query(
        'SELECT resource FROM resource_image_sequence WHERE folder_path = ? ORDER BY resource',
        ['s', $folder_rel]
    );
    $out = [];
    foreach ($rows as $row) {
        $out[] = image_sequence_repair_sequence_timeline((int) $row['resource']);
    }

    return $out;
}

/**
 * Detect cadence shot breaks for an existing sequence (full EXIF pass).
 *
 * @return array{
 *   ok: bool,
 *   message: string,
 *   cadence: float|null,
 *   shots: list<array{index: int, frames: int, first: string, last: string, duration: string, start_basename: string, end_basename: string}>
 * }
 */
function image_sequence_detect_shot_breaks(int $ref): array
{
    $empty = [
        'ok' => false,
        'message' => '',
        'cadence' => null,
        'shots' => [],
    ];

    $data = image_sequence_get_data($ref);
    if ($data === null) {
        $empty['message'] = 'not an image sequence';
        return $empty;
    }

    $paths = image_sequence_member_absolute_paths($data);
    if (count($paths) < 2) {
        $empty['message'] = 'need at least two frames';
        return $empty;
    }

    if (PHP_SAPI === 'cli') {
        echo '  dating ' . count($paths) . " frames for shot-break detection…\n";
        flush();
    }

    $dates = image_sequence_batch_effective_dates($paths);
    $dated = [];
    foreach ($paths as $path) {
        $ts = $dates[$path] ?? 0.0;
        if ($ts <= 0) {
            continue;
        }
        $dated[] = ['path' => $path, 'date' => (float) $ts];
    }
    if (count($dated) < 2) {
        $empty['message'] = 'not enough frames with EXIF DateTimeOriginal';
        return $empty;
    }

    usort($dated, static function ($a, $b) {
        return $a['date'] <=> $b['date'] ?: strnatcasecmp(basename($a['path']), basename($b['path']));
    });

    $cadence = image_sequence_detect_normal_interval($dated);
    $segments = image_sequence_split_files($dated, true);
    $shots = [];
    foreach ($segments as $i => $segment) {
        $first_ts = (float) $segment[0]['date'];
        $last_ts = (float) $segment[count($segment) - 1]['date'];
        $shots[] = [
            'index' => $i,
            'frames' => count($segment),
            'first' => image_sequence_format_capture_timestamp($first_ts),
            'last' => image_sequence_format_capture_timestamp($last_ts),
            'duration' => image_sequence_format_duration_label(max(0.0, $last_ts - $first_ts)),
            'start_basename' => basename($segment[0]['path']),
            'end_basename' => basename($segment[count($segment) - 1]['path']),
        ];
    }

    return [
        'ok' => true,
        'message' => count($shots) <= 1
            ? 'No shot breaks detected — sequence stays as one shot.'
            : ('Detected ' . count($shots) . ' shots from cadence gaps.'),
        'cadence' => $cadence,
        'shots' => $shots,
        'segments' => $segments,
    ];
}

/**
 * Sequence code currently on a resource, or folder-derived code as fallback.
 */
function image_sequence_base_sequence_code(int $ref): string
{
    global $image_sequence_seqcode_field;

    $field = (int) ($image_sequence_seqcode_field ?? 0);
    if ($field > 0) {
        $code = trim((string) get_data_by_field($ref, $field));
        if ($code !== '') {
            return $code;
        }
    }

    $data = image_sequence_get_data($ref);
    if ($data === null) {
        return '';
    }
    $folder_abs = image_sequence_relative_to_absolute((string) ($data['folder_path'] ?? ''));
    if ($folder_abs === null) {
        return '';
    }

    return image_sequence_folder_metadata($folder_abs)['sequence_code'];
}

/**
 * Replace member list on an existing sequence and refresh sparse EXIF timeline + proxy.
 *
 * @param list<string> $member_basenames
 */
function image_sequence_replace_sequence_members(int $ref, array $member_basenames, ?float $cadence = null): bool
{
    global $image_sequence_fps_default;

    $data = image_sequence_get_data($ref);
    if ($data === null || $member_basenames === []) {
        return false;
    }

    $unique = [];
    $seen = [];
    foreach ($member_basenames as $name) {
        $name = (string) $name;
        if (!image_sequence_is_safe_member_basename($name) || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $unique[] = $name;
    }
    if ($unique === []) {
        return false;
    }

    $fps = (float) ($data['fps'] ?? $image_sequence_fps_default);
    if ($fps <= 0) {
        $fps = (float) $image_sequence_fps_default;
    }
    $frame_count = count($unique);
    $duration = $frame_count / max($fps, 0.0001);
    $pattern_info = image_sequence_infer_frame_pattern($unique);

    ps_query(
        'UPDATE resource_image_sequence
            SET member_files = ?, frame_pattern = ?, start_number = ?, end_number = ?,
                frame_count = ?, duration_seconds = ?, representative_frame = 0,
                in_frame = 0, out_frame = ?, proxy_status = ?, detected_cadence_seconds = ?
            WHERE resource = ?',
        [
            's', json_encode($unique, JSON_UNESCAPED_SLASHES),
            's', $pattern_info['pattern'] ?? '',
            'i', $pattern_info['start'] ?? 0,
            'i', $pattern_info['end'] ?? 0,
            'i', $frame_count,
            'd', $duration,
            'i', max(0, $frame_count - 1),
            's', 'pending',
            'd', $cadence !== null ? (float) $cadence : 0.0,
            'i', $ref,
        ]
    );

    $manifest_abs = get_resource_path($ref, true, '', false, 'json');
    if (is_string($manifest_abs) && $manifest_abs !== '' && is_file($manifest_abs)) {
        $payload = json_decode((string) file_get_contents($manifest_abs), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['member_files'] = $unique;
        $payload['frame_count'] = $frame_count;
        $payload['fps'] = $fps;
        $payload['detected_cadence_seconds'] = $cadence;
        file_put_contents($manifest_abs, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    image_sequence_update_metadata_fields($ref, [
        'frame_count' => $frame_count,
        'duration' => $duration,
        'fps' => $fps,
        'cadence' => $cadence,
        'representative_frame' => 0,
        'in_frame' => 0,
        'out_frame' => max(0, $frame_count - 1),
    ]);

    $data = image_sequence_get_data($ref);
    $paths = $data !== null ? image_sequence_member_absolute_paths($data) : [];
    image_sequence_apply_sequence_timeline_sparse($ref, $paths, $cadence);
    if ($paths !== []) {
        try {
            image_sequence_extract_frame_metadata($ref, $paths[0]);
            image_sequence_save_representative_alt_file($ref, $paths[0], 0);
        } catch (Throwable $e) {
            debug('image_sequence_replace_sequence_members metadata: ' . $e->getMessage());
        }
    }
    image_sequence_queue_proxy_job($ref);

    return true;
}

/**
 * Auto-detect cadence shot breaks and split into separate Image Sequence resources.
 *
 * Keeps the first shot on $ref; creates new resources for subsequent shots.
 *
 * @return array{
 *   ok: bool,
 *   message: string,
 *   dry_run: bool,
 *   cadence: float|null,
 *   shots: list<array<string, mixed>>,
 *   resources: list<int>
 * }
 */
function image_sequence_split_sequence_by_cadence(int $ref, bool $dry_run = true, int $created_by = 0): array
{
    global $image_sequence_min_frames;

    $detection = image_sequence_detect_shot_breaks($ref);
    $result = [
        'ok' => (bool) ($detection['ok'] ?? false),
        'message' => (string) ($detection['message'] ?? ''),
        'dry_run' => $dry_run,
        'cadence' => $detection['cadence'] ?? null,
        'shots' => $detection['shots'] ?? [],
        'resources' => [$ref],
    ];

    if (!$result['ok']) {
        return $result;
    }

    $segments = $detection['segments'] ?? [];
    if (count($segments) <= 1) {
        $result['message'] = 'No shot breaks detected — nothing to split.';
        return $result;
    }

    if ($dry_run) {
        $result['message'] = 'Dry run: would split into ' . count($segments) . ' sequences.';
        return $result;
    }

    $min_frames = (int) ($image_sequence_min_frames ?? 10);
    $cadence = $detection['cadence'] ?? null;
    $new_refs = [];
    $base_code = image_sequence_base_sequence_code($ref);

    // First segment stays on the original resource.
    $first = $segments[0];
    $first_bases = array_map(static fn ($row) => basename($row['path']), $first);
    if (!image_sequence_replace_sequence_members($ref, $first_bases, $cadence)) {
        $result['ok'] = false;
        $result['message'] = 'Failed to update original sequence with the first shot.';
        return $result;
    }
    $new_refs[] = $ref;

    $shot_suffix = 0;
    for ($i = 1, $n = count($segments); $i < $n; $i++) {
        $segment = $segments[$i];
        if (count($segment) < $min_frames) {
            // Short leftover tails become Photos.
            foreach ($segment as $file) {
                image_sequence_create_photo_resource($file['path'], $created_by);
            }
            continue;
        }
        $shot_suffix++;
        $split_code = $base_code !== '' ? ($base_code . '-' . $shot_suffix) : null;
        $created = image_sequence_create_sequence_resource($segment, $cadence, $created_by, $split_code);
        if ($created > 0) {
            $new_refs[] = $created;
        }
    }

    $result['resources'] = $new_refs;
    $result['message'] = 'Split into ' . count($new_refs) . ' sequence(s).';
    // Drop heavy segment payloads from the API response.
    unset($detection['segments']);

    return $result;
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
 * Avoids per-frame realpath()/is_file() — those are extremely slow on SMB/NAS
 * volumes (hundreds of frames × round-trips). Basenames are already validated;
 * callers that need a specific file should check that path only.
 *
 * @return list<string>
 */
function image_sequence_member_absolute_paths(array $sequence_data): array
{
    $folder_abs = image_sequence_relative_to_absolute((string) $sequence_data['folder_path']);
    if ($folder_abs === null) {
        return [];
    }
    $folder_abs = rtrim(str_replace('\\', '/', $folder_abs), '/');
    // One directory resolve is enough; do not touch every frame.
    $folder_real = realpath($folder_abs);
    if ($folder_real !== false) {
        $folder_abs = rtrim(str_replace('\\', '/', $folder_real), '/');
    } elseif (!is_dir($folder_abs)) {
        return [];
    }

    $paths = [];
    $seen = [];
    foreach ($sequence_data['member_files_list'] as $name) {
        $name = (string) $name;
        if (!image_sequence_is_safe_member_basename($name) || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $paths[] = $folder_abs . '/' . $name;
    }

    return $paths;
}

/**
 * Absolute path for a single zero-based member frame (fast — no full-sequence scan).
 */
function image_sequence_member_path_at(array $sequence_data, int $frame_index): ?string
{
    $members = $sequence_data['member_files_list'] ?? [];
    if (!is_array($members) || $members === []) {
        return null;
    }
    $frame_index = image_sequence_clamp_frame_index($frame_index, count($members));
    $name = (string) ($members[$frame_index] ?? '');
    if (!image_sequence_is_safe_member_basename($name)) {
        return null;
    }

    $folder_abs = image_sequence_relative_to_absolute((string) $sequence_data['folder_path']);
    if ($folder_abs === null) {
        return null;
    }
    $folder_abs = rtrim(str_replace('\\', '/', $folder_abs), '/');
    $folder_real = realpath($folder_abs);
    if ($folder_real !== false) {
        $folder_abs = rtrim(str_replace('\\', '/', $folder_real), '/');
    }

    $path = $folder_abs . '/' . $name;

    return is_file($path) ? $path : null;
}

function image_sequence_queue_proxy_job(int $ref): void
{
    global $lang, $offline_job_queue, $userref;

    $job_data = ['resource' => $ref];
    $success = $lang['image_sequence_proxy_ready'] ?? ($lang['image_sequence_rep_frame_set'] ?? 'Image sequence proxy ready');
    $failure = $lang['image_sequence_proxy_failed'] ?? 'Image sequence proxy failed';

    if (!empty($offline_job_queue)) {
        // Offline jobs require a real user; CLI sync often has none.
        $job_user = (int) ($userref ?? 0);
        if ($job_user <= 0) {
            $job_user = image_sequence_default_job_user();
        }
        job_queue_add(
            'create_image_sequence_proxy',
            $job_data,
            (string) $job_user,
            '',
            $success,
            $failure,
            'imgseq_proxy_' . $ref
        );
        return;
    }

    // Run inline when offline jobs are disabled.
    image_sequence_generate_proxy($ref);
}

/**
 * Fallback user for CLI-queued offline jobs (admin / first system user).
 */
function image_sequence_default_job_user(): int
{
    $admin = (int) ps_value(
        'SELECT ref value FROM user WHERE username = ? OR usergroup = 3 ORDER BY ref ASC LIMIT 1',
        ['s', 'admin'],
        0
    );
    if ($admin > 0) {
        return $admin;
    }

    return (int) ps_value('SELECT MIN(ref) value FROM user', [], 1);
}

/**
 * FFmpeg encode flags for sequence proxy video (1280px-wide stills need higher bitrate than core video previews).
 */
function image_sequence_proxy_encode_options(): string
{
    global $image_sequence_proxy_options, $image_sequence_proxy_bitrate;

    $custom = trim((string) $image_sequence_proxy_options);
    if ($custom !== '') {
        return $custom;
    }

    $bitrate = trim((string) ($image_sequence_proxy_bitrate ?? '2000k'));
    if ($bitrate === '' || $bitrate === '0') {
        $bitrate = '2000k';
    } elseif (preg_match('/^\d+$/', $bitrate)) {
        $bitrate .= 'k';
    }

    // Target ~2 Mbps average; allow brief peaks for I-frames on detailed stills.
    $maxrate = $bitrate;
    if (preg_match('/^(\d+)k$/i', $bitrate, $matches)) {
        $maxrate = (int) round((int) $matches[1] * 1.25) . 'k';
    }

    return '-f mp4 -c:v libx264 -b:v ' . $bitrate
        . ' -maxrate ' . $maxrate
        . ' -bufsize 4000k -pix_fmt yuv420p -profile:v main -level 4.0 -preset medium -an -movflags +faststart';
}

/**
 * JPEG quality for ImageMagick on Ubuntu IM6: `-quality N` is broken (treats N as a filename).
 * Use `-define jpeg:quality=N` instead.
 */
function image_sequence_im_jpeg_quality_arg(int $quality = 80): string
{
    $quality = max(1, min(100, $quality));

    return '-define jpeg:quality=' . $quality;
}

/**
 * Build FFmpeg proxy + poster thumbnails for a sequence resource.
 */
function image_sequence_generate_proxy(int $ref): bool
{
    global $ffmpeg_preview_extension,
        $image_sequence_proxy_max_width, $image_sequence_proxy_max_seconds;

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

    $width = (int) ($image_sequence_proxy_max_width ?: 1280);
    if ($width < 2) {
        $width = 1280;
    }
    // Even width for yuv420p / libx264.
    $width -= ($width % 2);

    $encode_opts = image_sequence_proxy_encode_options();

    $duration_limit = (int) $image_sequence_proxy_max_seconds;
    // Fit inside max width; keep source aspect ratio (no crop, no letter/pillar box).
    // Height -2 = auto, even. min(W,iw) avoids upscaling smaller stills.
    $scale = "scale='trunc(min({$width}\\,iw)/2)*2':-2,setsar=1";

    try {
        $pattern = (string) ($data['frame_pattern'] ?? '');
        $folder_abs = image_sequence_relative_to_absolute((string) $data['folder_path']);
        if ($pattern !== '' && $folder_abs !== null && (int) $data['start_number'] > 0) {
            $input = rtrim($folder_abs, '/') . '/' . $pattern;
            $cmd = $ffmpeg . ' -hide_banner -loglevel error -y -framerate %%FPS%% -start_number %%START%% -i %%INPUT%% '
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

            $cmd = $ffmpeg . ' -hide_banner -loglevel error -y -f concat -safe 0 -r %%FPS%% -i %%LIST%% '
                . $encode_opts . ' -vf %%SCALE%%';
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
                $ffmpeg . ' -hide_banner -loglevel error -y -i %%SRC%% -frames:v 1 -q:v 2 %%DST%%',
                false,
                [
                    '%%SRC%%' => new CommandPlaceholderArg($poster_source, 'image_sequence_is_valid_shell_path'),
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

        if (file_exists($poster_jpg) && filesize($poster_jpg) > 0) {
            // Avoid core create_previews() here — Ubuntu IM6 breaks on `-quality N` (opens "N" as a file).
            image_sequence_derive_thumbs_from_poster($ref, $poster_jpg);
            $has_poster = true;
        }

        // Hover-scrub strip for search cards (same snapshot_N.jpg naming as core videos).
        if ($ok) {
            image_sequence_generate_snapshots($ref, $paths, $target);
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
 * Build search-card hover scrub snapshots (snapshot_1.jpg …) for an image sequence.
 * Prefers evenly spaced member stills; falls back to sampling the proxy video.
 *
 * @param list<string> $paths Absolute member still paths (optional)
 * @param string       $proxy_path Absolute proxy video path (optional fallback)
 */
function image_sequence_generate_snapshots(int $ref, array $paths = [], string $proxy_path = ''): int
{
    global $ffmpeg_snapshot_frames;

    $snap_count = (int) $ffmpeg_snapshot_frames;
    if ($ref <= 0 || $snap_count < 2) {
        return 0;
    }

    $template = get_resource_path($ref, true, 'snapshot', false, 'jpg', -1, 1, false, '');
    if (!is_string($template) || $template === '') {
        return 0;
    }

    // Clear previous strip so get_video_snapshots() does not keep stale extras.
    for ($i = 1; $i <= $snap_count + 5; $i++) {
        $old = str_replace('snapshot', 'snapshot_' . $i, $template);
        if (is_file($old)) {
            @unlink($old);
        }
    }

    $written = 0;
    if ($paths !== []) {
        $written = image_sequence_write_snapshots_from_stills($ref, $paths, $snap_count, $template);
    }
    if ($written < 2 && $proxy_path !== '' && is_file($proxy_path)) {
        $written = image_sequence_write_snapshots_from_video($ref, $proxy_path, $snap_count, $template);
    }

    return $written;
}

/**
 * @param list<string> $paths
 */
function image_sequence_write_snapshots_from_stills(int $ref, array $paths, int $snap_count, string $template): int
{
    $frame_count = count($paths);
    if ($frame_count === 0) {
        return 0;
    }

    $indices = image_sequence_snapshot_sample_indices($frame_count, $snap_count);
    $written = 0;
    $ffmpeg = get_utility_path('ffmpeg');
    $convert = get_utility_path('im-convert');
    $jpeg_q = image_sequence_im_jpeg_quality_arg(80);

    foreach ($indices as $i => $frame_index) {
        $src = $paths[$frame_index] ?? '';
        if ($src === '' || !is_file($src)) {
            continue;
        }
        $dest = str_replace('snapshot', 'snapshot_' . ($i + 1), $template);
        $ok = false;

        // Prefer FFmpeg: reliable on large stills; avoids Ubuntu IM6 `-quality` bug.
        if ($ffmpeg !== false) {
            try {
                run_command(
                    $ffmpeg . ' -hide_banner -loglevel error -y -i %%SRC%% -frames:v 1 -vf scale=640:-2 -q:v 3 -update 1 %%DST%%',
                    false,
                    [
                        '%%SRC%%' => new CommandPlaceholderArg($src, 'image_sequence_is_valid_shell_path'),
                        '%%DST%%' => new CommandPlaceholderArg($dest, 'is_valid_rs_path'),
                    ]
                );
                $ok = is_file($dest) && filesize($dest) > 0;
            } catch (Throwable $e) {
                debug('image_sequence_write_snapshots_from_stills ffmpeg: ' . $e->getMessage());
            }
        }

        if (!$ok && $convert !== false) {
            try {
                run_command(
                    $convert . ' %%SRC%%[0] -auto-orient -resize %%GEOM%% ' . $jpeg_q . ' %%DST%%',
                    false,
                    [
                        '%%SRC%%' => new CommandPlaceholderArg($src, 'image_sequence_is_valid_shell_path'),
                        '%%GEOM%%' => new CommandPlaceholderArg('640x640', [CommandPlaceholderArg::class, 'alwaysValid']),
                        '%%DST%%' => new CommandPlaceholderArg($dest, 'is_valid_rs_path'),
                    ]
                );
                $ok = is_file($dest) && filesize($dest) > 0;
            } catch (Throwable $e) {
                debug('image_sequence_write_snapshots_from_stills convert: ' . $e->getMessage());
            }
        }

        if (!$ok) {
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $ok = @copy($src, $dest) && is_file($dest);
            }
        }

        if ($ok) {
            $written++;
        }
    }

    return $written;
}

function image_sequence_write_snapshots_from_video(int $ref, string $video_path, int $snap_count, string $template): int
{
    $ffmpeg = get_utility_path('ffmpeg');
    if ($ffmpeg === false || !is_file($video_path)) {
        return 0;
    }

    $duration = 0.0;
    try {
        $duration = (float) get_video_duration($video_path);
    } catch (Throwable $e) {
        $duration = 0.0;
    }
    if ($duration <= 0) {
        try {
            $info = get_video_info($video_path);
            if (is_array($info)) {
                foreach (($info['streams'] ?? [$info]) as $stream) {
                    if (!empty($stream['duration'])) {
                        $duration = (float) $stream['duration'];
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $duration = 0.0;
        }
    }
    if ($duration <= 0) {
        return 0;
    }

    $step = max($duration / $snap_count, 0.01);
    $written = 0;
    for ($i = 1, $t = 0.0; $i <= $snap_count && $t <= $duration + 0.001; $i++, $t += $step) {
        $dest = str_replace('snapshot', 'snapshot_' . $i, $template);
        try {
            run_command(
                $ffmpeg . ' -hide_banner -loglevel error -y -ss %%TIME%% -i %%SRC%% -frames:v 1 -vf scale=640:-2 -q:v 3 -update 1 %%DST%%',
                false,
                [
                    '%%TIME%%' => new CommandPlaceholderArg(sprintf('%.3F', $t), [CommandPlaceholderArg::class, 'alwaysValid']),
                    '%%SRC%%' => new CommandPlaceholderArg($video_path, 'is_valid_rs_path'),
                    '%%DST%%' => new CommandPlaceholderArg($dest, 'is_valid_rs_path'),
                ]
            );
            if (is_file($dest) && filesize($dest) > 0) {
                $written++;
            }
        } catch (Throwable $e) {
            debug('image_sequence_write_snapshots_from_video: ' . $e->getMessage());
        }
    }

    return $written;
}

/**
 * Evenly spaced zero-based frame indices for hover scrub snapshots.
 *
 * @return list<int>
 */
function image_sequence_snapshot_sample_indices(int $frame_count, int $snap_count): array
{
    if ($frame_count <= 0) {
        return [];
    }
    if ($frame_count <= $snap_count) {
        return range(0, $frame_count - 1);
    }
    $indices = [];
    for ($i = 0; $i < $snap_count; $i++) {
        $indices[] = (int) round($i * ($frame_count - 1) / ($snap_count - 1));
    }

    return array_values(array_unique($indices));
}

/**
 * Emit search-card hover-scrub JS (same UX as core video snapshots).
 */
function image_sequence_render_search_scrub_script(int $ref, string $thumbnail_url): void
{
    global $ffmpeg_snapshot_frames;

    if ($ref <= 0 || (int) $ffmpeg_snapshot_frames < 2) {
        return;
    }
    if (get_video_snapshots($ref, false, true) < 2) {
        return;
    }

    $snapshots = get_video_snapshots($ref, false, false, true);
    if (!is_array($snapshots) || count($snapshots) < 2) {
        return;
    }
    ?>
    <script>
    jQuery('#CentralSpace #ResourceShell<?php echo (int) $ref; ?> a img').off('mousemove.imgseqScrub mouseout.imgseqScrub')
        .on('mousemove.imgseqScrub', function (event) {
            var x_coord = event.pageX - jQuery(this).offset().left;
            var video_snapshots = <?php echo json_encode($snapshots); ?>;
            var keys = Object.keys(video_snapshots);
            var snapshot_segment_px = Math.ceil(jQuery(this).width() / keys.length);
            var snapshot_number = x_coord == 0 ? 1 : Math.ceil(x_coord / snapshot_segment_px);
            if (snapshot_number < 1) {
                snapshot_number = 1;
            }
            if (snapshot_number > keys.length) {
                snapshot_number = keys.length;
            }
            if (typeof ss_img_<?php echo (int) $ref; ?> === 'undefined') {
                ss_img_<?php echo (int) $ref; ?> = [];
            }
            if (!ss_img_<?php echo (int) $ref; ?>[snapshot_number]) {
                ss_img_<?php echo (int) $ref; ?>[snapshot_number] = new Image();
                ss_img_<?php echo (int) $ref; ?>[snapshot_number].src = video_snapshots[snapshot_number];
            }
            jQuery(this).attr('src', ss_img_<?php echo (int) $ref; ?>[snapshot_number].src);
        })
        .on('mouseout.imgseqScrub', function () {
            jQuery(this).attr('src', <?php echo json_encode($thumbnail_url); ?>);
        });
    </script>
    <?php
}

/**
 * Stable name / alt_type for the managed full-res representative still.
 * Replaced whenever a new representative frame is chosen.
 */
function image_sequence_representative_alt_name(): string
{
    global $lang;

    return $lang['image_sequence_rep_frame_alt_name'] ?? 'Representative frame';
}

function image_sequence_representative_alt_type(): string
{
    return 'image_sequence_representative';
}

/**
 * Copy the chosen still into filestore as an alternative file (full resolution).
 * Any previous representative-frame alternative is removed first.
 *
 * @return int Alternative file ref, or 0 on failure
 */
function image_sequence_save_representative_alt_file(int $ref, string $frame_path, int $frame_index = -1): int
{
    global $lang;

    if ($ref <= 0 || $frame_path === '' || !is_file($frame_path)) {
        return 0;
    }

    $extension = strtolower(pathinfo($frame_path, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'jpg';
    }
    $basename = basename($frame_path);
    $alt_name = image_sequence_representative_alt_name();
    $alt_type = image_sequence_representative_alt_type();
    $description = $lang['image_sequence_rep_frame_alt_desc'] ?? 'Full-resolution representative still from the image sequence';
    if ($frame_index >= 0) {
        $description .= ' (frame ' . $frame_index . ')';
    }

    // Replace any prior representative alt (by type, then by name for older rows).
    $existing = ps_query(
        'SELECT ref FROM resource_alt_files WHERE resource = ? AND (alt_type = ? OR name = ?)',
        ['i', $ref, 's', $alt_type, 's', $alt_name]
    );
    foreach ($existing as $row) {
        delete_alternative_file($ref, (int) $row['ref']);
    }

    $aref = (int) add_alternative_file(
        $ref,
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

    $dest = get_resource_path($ref, true, '', true, $extension, -1, 1, false, '', $aref);
    if (!@copy($frame_path, $dest) || !is_file($dest)) {
        delete_alternative_file($ref, $aref);
        debug("image_sequence_save_representative_alt_file: failed to copy {$frame_path} → {$dest}");

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
            'i', $ref,
            'i', $aref,
        ]
    );
    update_disk_usage($ref);

    return $aref;
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

    $members = $data['member_files_list'] ?? [];
    $path_count = is_array($members) ? count($members) : 0;
    if ($path_count === 0) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_rep_frame_no_files'] ?? 'Sequence frame files are missing on disk.',
        ];
    }

    // Clamp — proxy scrubbing at EOF often reports frame_count (one past last index).
    $frame_index = image_sequence_clamp_frame_index($frame_index, $path_count);

    // Resolve only the chosen frame (never realpath the whole sequence — slow on NAS).
    $frame_path = image_sequence_member_path_at($data, $frame_index);
    if ($frame_path === null) {
        return [
            'ok' => false,
            'message' => $lang['image_sequence_rep_frame_no_files'] ?? 'Sequence frame files are missing on disk.',
        ];
    }

    ps_query(
        'UPDATE resource_image_sequence SET representative_frame = ? WHERE resource = ?',
        ['i', $frame_index, 'i', $ref]
    );
    image_sequence_update_metadata_fields($ref, ['representative_frame' => $frame_index]);

    $extension = strtolower(pathinfo($frame_path, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'jpg';
    }

    // One NAS→local copy, then do EXIF / alt / poster from the local file so we
    // don't re-read a 10MB still over SMB (especially while a proxy encode is running).
    $work_path = $frame_path;
    $temp_copy = '';
    if (image_sequence_path_under_scan_root($frame_path)) {
        $temp_dir = get_temp_dir(false, 'imgseq_rep_' . $ref);
        $temp_copy = $temp_dir . '/frame_' . $frame_index . '.' . $extension;
        if (@copy($frame_path, $temp_copy) && is_file($temp_copy)) {
            $work_path = $temp_copy;
        } else {
            $temp_copy = '';
        }
    }

    // EXIF from this still only — do not re-run full-sequence timeline analysis here
    // (that walks every frame and can take minutes on SMB while a proxy encode is running).
    try {
        image_sequence_extract_frame_metadata($ref, $work_path);
    } catch (Throwable $e) {
        debug('image_sequence_set_representative_frame metadata: ' . $e->getMessage());
    }

    // Full-res still into managed storage as a replaceable alternative file.
    try {
        image_sequence_save_representative_alt_file($ref, $work_path, $frame_index);
    } catch (Throwable $e) {
        debug('image_sequence_set_representative_frame alt file: ' . $e->getMessage());
    }

    // Refresh search/view poster from the chosen frame without a full create_previews().
    image_sequence_refresh_poster_from_still($ref, $work_path, $extension);

    if ($temp_copy !== '' && is_file($temp_copy)) {
        @unlink($temp_copy);
    }

    // Re-run image AI fields against the chosen representative frame (offline — Moondream is slow).
    image_sequence_queue_ai_metadata($ref, true);

    return [
        'ok' => true,
        'message' => $lang['image_sequence_rep_frame_set'] ?? 'Representative frame updated.',
        'frame' => $frame_index,
    ];
}

/**
 * Write pre/thm/col/tiny JPEGs from a still without copying multi‑MB masters into thumbs.
 */
function image_sequence_refresh_poster_from_still(int $ref, string $frame_path, string $extension = ''): void
{
    if ($ref <= 0 || $frame_path === '' || !is_file($frame_path)) {
        return;
    }
    if ($extension === '') {
        $extension = strtolower(pathinfo($frame_path, PATHINFO_EXTENSION));
    }

    $poster_jpg = get_resource_path($ref, true, 'pre', true, 'jpg');
    $wrote = false;

    // Prefer ImageMagick: one decode, sensible preview size, then derive thumbs.
    // Geometry must be a placeholder — an unquoted ">" is a shell redirect.
    // Use -define jpeg:quality (not -quality): Ubuntu IM6 treats `-quality N` as a filename.
    $convert = get_utility_path('im-convert');
    if ($convert !== false) {
        try {
            run_command(
                $convert . ' %%SRC%%[0] -auto-orient -resize %%GEOM%% '
                    . image_sequence_im_jpeg_quality_arg(85) . ' %%DST%%',
                false,
                [
                    '%%SRC%%' => new CommandPlaceholderArg($frame_path, 'image_sequence_is_valid_shell_path'),
                    '%%GEOM%%' => new CommandPlaceholderArg('1280x1280', [CommandPlaceholderArg::class, 'alwaysValid']),
                    '%%DST%%' => new CommandPlaceholderArg($poster_jpg, 'is_valid_rs_path'),
                ]
            );
            $wrote = is_file($poster_jpg) && filesize($poster_jpg) > 0;
        } catch (Throwable $e) {
            debug('image_sequence_refresh_poster_from_still convert: ' . $e->getMessage());
        }
    }

    if (!$wrote && in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        $wrote = @copy($frame_path, $poster_jpg) && is_file($poster_jpg);
    }

    if (!$wrote) {
        $ffmpeg = get_utility_path('ffmpeg');
        if ($ffmpeg !== false) {
            try {
                run_command(
                    $ffmpeg . ' -hide_banner -loglevel error -y -i %%SRC%% -frames:v 1 -q:v 2 -update 1 %%DST%%',
                    false,
                    [
                        '%%SRC%%' => new CommandPlaceholderArg($frame_path, 'image_sequence_is_valid_shell_path'),
                        '%%DST%%' => new CommandPlaceholderArg($poster_jpg, 'is_valid_rs_path'),
                    ]
                );
                $wrote = is_file($poster_jpg) && filesize($poster_jpg) > 0;
            } catch (Throwable $e) {
                debug('image_sequence_refresh_poster_from_still ffmpeg: ' . $e->getMessage());
            }
        }
    }

    if (!$wrote || !is_file($poster_jpg)) {
        return;
    }

    image_sequence_derive_thumbs_from_poster($ref, $poster_jpg);

    // Bump file_modified so download cache-busting picks up the new posters.
    ps_query(
        "UPDATE resource SET has_image = 1, preview_extension = 'jpg', file_modified = NOW() WHERE ref = ?",
        ['i', $ref]
    );
}

/**
 * Derive scr/thm/col/tiny JPEGs from an existing poster without core create_previews().
 * Avoids Ubuntu ImageMagick's broken `-quality N` (opens "N" as an input file).
 */
function image_sequence_derive_thumbs_from_poster(int $ref, string $poster_jpg): void
{
    if ($ref <= 0 || !is_file($poster_jpg)) {
        return;
    }

    $convert = get_utility_path('im-convert');
    $ffmpeg = get_utility_path('ffmpeg');
    $jpeg_q = image_sequence_im_jpeg_quality_arg(80);
    $thumb_specs = [
        'scr' => '1280x1280',
        'thm' => '250x250',
        'col' => '100x100',
        'tiny' => '75x75',
    ];

    foreach ($thumb_specs as $size => $geometry) {
        $dest = get_resource_path($ref, true, $size, true, 'jpg');
        $ok = false;

        if ($convert !== false) {
            try {
                run_command(
                    $convert . ' %%SRC%%[0] -auto-orient -thumbnail %%GEOM%% ' . $jpeg_q . ' %%DST%%',
                    false,
                    [
                        '%%SRC%%' => new CommandPlaceholderArg($poster_jpg, 'is_valid_rs_path'),
                        '%%GEOM%%' => new CommandPlaceholderArg($geometry, [CommandPlaceholderArg::class, 'alwaysValid']),
                        '%%DST%%' => new CommandPlaceholderArg($dest, 'is_valid_rs_path'),
                    ]
                );
                $ok = is_file($dest) && filesize($dest) > 0;
            } catch (Throwable $e) {
                debug('image_sequence_derive_thumbs_from_poster convert: ' . $e->getMessage());
            }
        }

        if (!$ok && $ffmpeg !== false) {
            $dims = explode('x', $geometry);
            $w = (int) ($dims[0] ?? 250);
            try {
                run_command(
                    $ffmpeg . ' -hide_banner -loglevel error -y -i %%SRC%% -frames:v 1 -vf scale=%%W%%:-2 -q:v 3 -update 1 %%DST%%',
                    false,
                    [
                        '%%SRC%%' => new CommandPlaceholderArg($poster_jpg, 'is_valid_rs_path'),
                        '%%W%%' => new CommandPlaceholderArg((string) $w, 'is_positive_int_loose'),
                        '%%DST%%' => new CommandPlaceholderArg($dest, 'is_valid_rs_path'),
                    ]
                );
                $ok = is_file($dest) && filesize($dest) > 0;
            } catch (Throwable $e) {
                debug('image_sequence_derive_thumbs_from_poster ffmpeg: ' . $e->getMessage());
            }
        }

        if (!$ok) {
            @copy($poster_jpg, $dest);
        }
    }
}

/**
 * Clamp a zero-based frame index into [0, path_count - 1].
 */
function image_sequence_clamp_frame_index(int $frame_index, int $path_count): int
{
    if ($path_count <= 0) {
        return 0;
    }
    if ($frame_index < 0) {
        return 0;
    }
    if ($frame_index >= $path_count) {
        return $path_count - 1;
    }

    return $frame_index;
}

/**
 * Alternative file ref for the managed representative still, if present.
 */
function image_sequence_get_representative_alt_ref(int $ref): int
{
    if ($ref <= 0) {
        return 0;
    }

    return (int) ps_value(
        'SELECT ref value FROM resource_alt_files WHERE resource = ? AND alt_type = ? ORDER BY ref DESC LIMIT 1',
        ['i', $ref, 's', image_sequence_representative_alt_type()],
        0
    );
}

/**
 * Public preview URL for an image-sequence resource, preferring the representative frame.
 * Uses regenerated JPEG posters (pre/thm/scr) — never the video proxy first frame.
 */
function image_sequence_representative_preview_url(int $ref, int $access = -1): string
{
    if ($ref <= 0 || image_sequence_get_data($ref) === null) {
        return '';
    }

    $resource = get_resource_data($ref);
    if (!is_array($resource)) {
        return '';
    }

    // Ensure preview lookups use JPEG posters, not the mp4 proxy beside them.
    $resource['preview_extension'] = 'jpg';

    if ($access < 0) {
        $access = (int) get_resource_access($resource);
    }

    // Prefer posters refreshed from the representative still (avoid stale first-frame scr alone).
    $preview = get_resource_preview($resource, ['pre', 'thm', 'scr'], $access, false);
    if (is_array($preview) && !empty($preview['url'])) {
        return (string) $preview['url'];
    }

    // Fall back to the full-res representative alternative file.
    $alt = image_sequence_get_representative_alt_ref($ref);
    if ($alt > 0) {
        $alt_row = get_alternative_file($ref, $alt);
        $ext = is_array($alt_row) ? strtolower((string) ($alt_row['file_extension'] ?? 'jpg')) : 'jpg';
        if ($ext === '') {
            $ext = 'jpg';
        }
        $path = get_resource_path($ref, true, '', false, $ext, true, 1, false, '', $alt);
        if (is_string($path) && is_file($path)) {
            return (string) get_resource_path($ref, false, '', false, $ext, true, 1, false, '', $alt);
        }
    }

    return '';
}

/**
 * Absolute path to the representative still for AI / metadata use.
 * Prefers the managed alternative file, then a JPEG/PNG sequence still,
 * otherwise falls back to the generated poster preview.
 */
function image_sequence_get_representative_still_path(int $ref): string
{
    // Managed full-res representative alt (sequence or video).
    $alt_type = image_sequence_representative_alt_type();
    $alts = ps_query(
        'SELECT ref, file_extension FROM resource_alt_files WHERE resource = ? AND alt_type = ? ORDER BY ref DESC LIMIT 1',
        ['i', $ref, 's', $alt_type]
    );
    if ($alts !== []) {
        $ext = strtolower((string) ($alts[0]['file_extension'] ?? 'jpg'));
        $alt_path = get_resource_path($ref, true, '', false, $ext, -1, 1, false, '', (int) $alts[0]['ref']);
        if (is_string($alt_path) && is_file($alt_path)) {
            return $alt_path;
        }
    }

    $data = image_sequence_get_data($ref);
    if ($data !== null) {
        $paths = image_sequence_member_absolute_paths($data);
        if (count($paths) > 0) {
            $rep_index = image_sequence_clamp_frame_index((int) ($data['representative_frame'] ?? 0), count($paths));
            $frame_path = $paths[$rep_index];
            $ext = strtolower(pathinfo($frame_path, PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) && file_exists($frame_path)) {
                return $frame_path;
            }
            if (file_exists($frame_path)) {
                // Non-browser/AI-friendly originals: prefer poster derived from this frame.
                $poster = get_resource_path($ref, true, 'pre', false, 'jpg');
                if (is_string($poster) && $poster !== '' && file_exists($poster)) {
                    return $poster;
                }

                return $frame_path;
            }
        }
    }

    $poster = get_resource_path($ref, true, 'pre', false, 'jpg');
    if (is_string($poster) && $poster !== '' && file_exists($poster)) {
        return $poster;
    }

    return '';
}

/**
 * Run openai_gpt image fields for an image-sequence resource using the representative frame.
 *
 * @param bool $force_overwrite Re-generate even when target fields already have values
 */
function image_sequence_process_ai_metadata(int $ref, bool $force_overwrite = false): void
{
    if (!function_exists('openai_gpt_process_image_fields')) {
        $openai_functions = dirname(__DIR__, 2) . '/openai_gpt/include/openai_gpt_functions.php';
        if (!file_exists($openai_functions)) {
            return;
        }
        include_once $openai_functions;
    }

    if (!function_exists('openai_gpt_process_image_fields')) {
        return;
    }

    try {
        openai_gpt_process_image_fields($ref, $force_overwrite);
    } catch (Throwable $e) {
        debug('image_sequence_process_ai_metadata: ' . $e->getMessage());
    }
}

/**
 * Queue offline AI metadata generation for an image-sequence resource.
 */
function image_sequence_queue_ai_metadata(int $ref, bool $force_overwrite = false): void
{
    global $lang, $userref;

    if (!function_exists('job_queue_add')) {
        image_sequence_process_ai_metadata($ref, $force_overwrite);
        return;
    }

    $success = $lang['image_sequence_ai_done'] ?? 'AI metadata generated from representative frame';
    $failure = $lang['image_sequence_ai_failed'] ?? 'AI metadata generation failed';
    $job_user = (int) ($userref ?? 0);
    if ($job_user <= 0) {
        $job_user = image_sequence_default_job_user();
    }
    // job_code dedupes pending jobs for the same resource.
    job_queue_add(
        'image_sequence_ai_metadata',
        ['ref' => $ref, 'force_overwrite' => $force_overwrite],
        (string) $job_user,
        '',
        $success,
        $failure,
        'imgseq_ai_' . $ref
    );
}

/**
 * Persist in/out frame points on the sequence and metadata fields.
 *
 * @return array{ok: bool, message: string, in_frame?: int, out_frame?: int}
 */
function image_sequence_set_inout_frames(int $ref, int $in_frame, int $out_frame): array
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
        $path_count = max(1, (int) ($data['frame_count'] ?? 1));
    }

    $in_frame = image_sequence_clamp_frame_index($in_frame, $path_count);
    $out_frame = image_sequence_clamp_frame_index($out_frame, $path_count);
    if ($out_frame < $in_frame) {
        $tmp = $in_frame;
        $in_frame = $out_frame;
        $out_frame = $tmp;
    }

    ps_query(
        'UPDATE resource_image_sequence SET in_frame = ?, out_frame = ? WHERE resource = ?',
        ['i', $in_frame, 'i', $out_frame, 'i', $ref]
    );
    image_sequence_update_metadata_fields($ref, [
        'in_frame' => $in_frame,
        'out_frame' => $out_frame,
    ]);

    return [
        'ok' => true,
        'message' => $lang['image_sequence_inout_set'] ?? 'In/out points updated.',
        'in_frame' => $in_frame,
        'out_frame' => $out_frame,
    ];
}

/**
 * Stage uploaded files/ZIP under filestore staging (not the read-only scan tree)
 * and ingest with auto-split.
 *
 * @param list<string> $source_paths Absolute paths to images or a single zip
 * @return array{sequences: list<int>, photos: list<int>, folder: string}
 */
function image_sequence_ingest_upload_paths(array $source_paths, array $options = []): array
{
    $root = image_sequence_staging_root();
    if ($root === '') {
        return ['sequences' => [], 'photos' => [], 'folder' => ''];
    }

    $batch = 'upload_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8);
    $dest = rtrim($root, '/') . '/' . $batch;
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
 * Remove plugin rows / writable manifests when a resource is deleted.
 * Never deletes or modifies files under scan roots (read-only stills tree).
 */
function image_sequence_cleanup_resource(int $ref): void
{
    // Ensure permanent delete is not blocked if a proxy job left the lock set.
    image_sequence_clear_transcoding_lock($ref);

    $data = image_sequence_get_data($ref);
    if ($data === null) {
        return;
    }

    // Filestore original (current layout).
    $filestore_manifest = get_resource_path($ref, true, '', false, 'json');
    if (is_file($filestore_manifest) && !image_sequence_path_under_scan_root($filestore_manifest)) {
        @unlink($filestore_manifest);
    }

    // Legacy: older versions wrote .rs_imagesequence_*.json beside frames.
    // Leave those untouched so the scan directory stays read-only.
    ps_query('DELETE FROM resource_image_sequence WHERE resource = ?', ['i', $ref]);
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
