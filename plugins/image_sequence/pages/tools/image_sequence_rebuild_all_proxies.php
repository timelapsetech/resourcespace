<?php
/**
 * CLI: regenerate FFmpeg proxy video for every Image Sequence resource.
 *
 * Usage:
 *   php plugins/image_sequence/pages/tools/image_sequence_rebuild_all_proxies.php [--ready-only|--all]
 *
 * Default (--all): rebuild every sequence, including those whose proxy_status is already "ready".
 * --ready-only: only sequences with proxy_status = ready (skip pending/failed with no proxy).
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

$ready_only = in_array('--ready-only', $argv, true);

if ($ready_only) {
    $rows = ps_query(
        "SELECT resource FROM resource_image_sequence WHERE proxy_status = 'ready' ORDER BY resource"
    );
} else {
    $rows = ps_query('SELECT resource FROM resource_image_sequence ORDER BY resource');
}

$total = count($rows);
if ($total === 0) {
    echo "No image sequences found.\n";
    exit(0);
}

echo "Rebuilding proxies for {$total} sequence(s)…\n";

$ok_count = 0;
$fail_count = 0;
$n = 0;

foreach ($rows as $row) {
    $ref = (int) ($row['resource'] ?? 0);
    if ($ref <= 0) {
        continue;
    }
    $n++;
    echo "[{$n}/{$total}] resource {$ref}… ";
    image_sequence_clear_transcoding_lock($ref);
    if (image_sequence_generate_proxy($ref)) {
        $ok_count++;
        echo "ok\n";
    } else {
        $fail_count++;
        echo "failed\n";
    }
}

echo "Done: {$ok_count} ready, {$fail_count} failed.\n";
exit($fail_count > 0 ? 1 : 0);
