<?php
/**
 * CLI: auto-detect cadence shot breaks and optionally split a sequence.
 *
 * Usage:
 *   php plugins/image_sequence/pages/tools/image_sequence_split_shots.php <ref> [--apply]
 *
 * Without --apply, prints a dry-run preview only.
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

$ref = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 0;
$apply = in_array('--apply', $argv, true);

if ($ref <= 0) {
    echo "Usage: php image_sequence_split_shots.php <resource_ref> [--apply]\n";
    exit(1);
}

echo ($apply ? 'Applying' : 'Detecting') . " shot breaks for #{$ref}…\n";
$result = image_sequence_split_sequence_by_cadence($ref, !$apply, 1);
echo $result['message'] . "\n";
if (!empty($result['cadence'])) {
    echo 'Cadence: ' . $result['cadence'] . "s\n";
}
foreach ($result['shots'] ?? [] as $shot) {
    echo sprintf(
        "  shot %d: %d frames  %s → %s  (%s)\n",
        $shot['index'] + 1,
        $shot['frames'],
        $shot['first'],
        $shot['last'],
        $shot['duration']
    );
}
if ($apply && !empty($result['resources'])) {
    echo 'Resources: ' . implode(', ', $result['resources']) . "\n";
}
exit(!empty($result['ok']) ? 0 : 1);
