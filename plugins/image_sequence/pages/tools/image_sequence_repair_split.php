<?php
/**
 * CLI: repair a sequence whose shot-split truncated it to the first shot.
 *
 * A pre-1.21 split bug (find_existing_sequence folder short-circuit) trimmed the
 * original resource down to shot 1 and never created the other shots' resources,
 * leaving the remaining frames on disk but referenced by no resource. This tool
 * rebuilds the resource's member list from every still currently in its source
 * folder, so you can then re-run "Auto-detect and split shots" to split correctly.
 *
 * Usage:
 *   php plugins/image_sequence/pages/tools/image_sequence_repair_split.php <ref> [--apply]
 *
 * Without --apply this is a dry run (reports what would change).
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
    echo "Usage: php image_sequence_repair_split.php <resource_ref> [--apply]\n";
    exit(1);
}

$data = image_sequence_get_data($ref);
if ($data === null) {
    echo "#{$ref} is not an image sequence.\n";
    exit(1);
}

$folder_rel = (string) ($data['folder_path'] ?? '');
$folder_abs = image_sequence_relative_to_absolute($folder_rel);
if ($folder_abs === null || !is_dir($folder_abs)) {
    echo "Cannot resolve source folder for #{$ref} (folder_path='{$folder_rel}').\n";
    exit(1);
}

$current = $data['member_files_list'] ?? [];
$current_count = is_array($current) ? count($current) : 0;

// Other live sequences in the same folder would have their frames re-claimed by a
// full rebuild — refuse in that case so we don't merge distinct shots by mistake.
$others = ps_query(
    'SELECT ris.resource, ris.frame_count
       FROM resource_image_sequence ris
       JOIN resource r ON r.ref = ris.resource
      WHERE ris.folder_path = ? AND ris.resource <> ?',
    ['s', $folder_rel, 'i', $ref]
);
if ($others !== []) {
    echo "Refusing to repair: other sequence resources share this folder:\n";
    foreach ($others as $row) {
        echo '  #' . (int) $row['resource'] . ' (' . (int) $row['frame_count'] . " frames)\n";
    }
    echo "Delete/merge those first, or repair manually.\n";
    exit(1);
}

$files = image_sequence_list_stills_in_folder($folder_abs, false);
$basenames = [];
$seen = [];
foreach ($files as $file) {
    $base = basename((string) $file['path']);
    if (!image_sequence_is_safe_member_basename($base) || isset($seen[$base])) {
        continue;
    }
    $seen[$base] = true;
    $basenames[] = $base;
}
usort($basenames, 'strnatcasecmp');

$folder_total = count($basenames);
if ($folder_total === 0) {
    echo "No stills found in {$folder_abs} — nothing to rebuild.\n";
    exit(1);
}

echo "Resource #{$ref}\n";
echo "  Folder:          {$folder_abs}\n";
echo "  Current frames:  {$current_count}\n";
echo "  Frames in folder:{$folder_total}\n";
echo '  Frames to add:   ' . max(0, $folder_total - $current_count) . "\n";

if (!$apply) {
    echo "\nDry run only. Re-run with --apply to rebuild the full member list.\n";
    exit(0);
}

if (!image_sequence_replace_sequence_members($ref, $basenames, null)) {
    echo "Rebuild failed.\n";
    exit(1);
}

echo "\nRebuilt #{$ref} with {$folder_total} frames (proxy queued for regeneration).\n";
echo "Next: run the split again, e.g.\n";
echo "  php plugins/image_sequence/pages/tools/image_sequence_split_shots.php {$ref} --apply\n";
exit(0);
