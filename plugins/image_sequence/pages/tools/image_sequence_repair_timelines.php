<?php
/**
 * CLI: de-dupe member frames and refresh first/last/duration from EXIF.
 *
 * Usage:
 *   php plugins/image_sequence/pages/tools/image_sequence_repair_timelines.php [folder_rel|resource_ref]
 *
 * Examples:
 *   php .../image_sequence_repair_timelines.php 2012/1212301CO
 *   php .../image_sequence_repair_timelines.php 30
 *   php .../image_sequence_repair_timelines.php          # all sequences
 */

include dirname(__FILE__, 5) . '/include/boot.php';
command_line_only();

if (function_exists('ob_implicit_flush')) {
    ob_implicit_flush(true);
}
while (ob_get_level() > 0) {
    ob_end_flush();
}

include_once dirname(__FILE__, 3) . '/include/image_sequence_functions.php';

image_sequence_ensure_setup();

$target = $argv[1] ?? '';
$results = [];

if ($target === '') {
    $rows = ps_query('SELECT resource FROM resource_image_sequence ORDER BY resource');
    echo 'Repairing ' . count($rows) . " sequences…\n";
    foreach ($rows as $row) {
        $ref = (int) $row['resource'];
        echo "  #{$ref} … ";
        flush();
        $r = image_sequence_repair_sequence_timeline($ref);
        echo $r['frames_before'] . '→' . $r['frames_after'] . ' ' . $r['message'] . "\n";
        $results[] = $r;
    }
} elseif (ctype_digit($target)) {
    $ref = (int) $target;
    echo "Repairing resource #{$ref}…\n";
    flush();
    $r = image_sequence_repair_sequence_timeline($ref);
    echo '  ' . $r['frames_before'] . '→' . $r['frames_after'] . ' ' . $r['message'] . "\n";
    $results[] = $r;
} else {
    $folder = trim(str_replace('\\', '/', $target), '/');
    echo "Repairing folder {$folder}…\n";
    flush();
    $results = image_sequence_repair_folder_timelines($folder);
    foreach ($results as $r) {
        echo '  #' . $r['resource'] . ' ' . $r['frames_before'] . '→' . $r['frames_after']
            . ' ' . $r['message'] . "\n";
    }
}

$ok = count(array_filter($results, static fn ($r) => !empty($r['ok'])));
echo "Done. {$ok}/" . count($results) . " repaired.\n";
