<?php
/**
 * Delete existing 2012/2013 Image Sequence (+ leftover Photo extras) resources,
 * then re-ingest year folders with one-folder-one-sequence EXIF-sparse logic.
 *
 * Usage:
 *   php plugins/image_sequence/pages/tools/image_sequence_reingest_years.php [2012] [2013]
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
setup_user(get_user(1));

$years = [];
$no_delete = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-delete') {
        $no_delete = true;
        continue;
    }
    if (preg_match('/^\d{4}$/', $arg) === 1) {
        $years[] = $arg;
    }
}
if ($years === []) {
    $years = ['2012', '2013'];
}

$roots = image_sequence_scan_roots();
$originals_root = null;
foreach ($roots as $root) {
    if (strpos($root, 'Time Lapse/Originals') !== false || basename($root) === 'Originals') {
        $originals_root = rtrim(str_replace('\\', '/', $root), '/');
        break;
    }
}
if ($originals_root === null || !is_dir($originals_root)) {
    echo "Could not find Time Lapse/Originals scan root.\n";
    exit(1);
}

echo 'Originals root: ' . $originals_root . "\n";
echo 'Years: ' . implode(', ', $years) . "\n";
echo 'Delete existing: ' . ($no_delete ? 'no' : 'yes') . "\n";

$created_sequences = [];

if (!$no_delete) {
// --- 1) Collect resources to delete ---
$seq_refs = [];
$photo_refs = [];
foreach ($years as $year) {
    $like = $year . '/%';
    $rows = ps_query(
        'SELECT resource, folder_path, frame_count FROM resource_image_sequence WHERE folder_path = ? OR folder_path LIKE ?',
        ['s', $year, 's', $like]
    );
    // Also match folder_path LIKE year/%
    $rows = ps_query(
        'SELECT resource, folder_path, frame_count FROM resource_image_sequence WHERE folder_path LIKE ?',
        ['s', $like]
    );
    foreach ($rows as $row) {
        $seq_refs[] = (int) $row['resource'];
        echo '  queue delete sequence #' . $row['resource'] . ' (' . $row['folder_path']
            . ', ' . $row['frame_count'] . " frames)\n";
    }

    $photos = ps_query(
        'SELECT ref, file_path, resource_type FROM resource WHERE file_path LIKE ?',
        ['s', $like]
    );
    foreach ($photos as $row) {
        $ref = (int) $row['ref'];
        if (in_array($ref, $seq_refs, true)) {
            continue;
        }
        // Sequence resources have empty file_path; these are Photo extras.
        if ((string) $row['file_path'] === '') {
            continue;
        }
        $photo_refs[] = $ref;
        echo '  queue delete photo #' . $ref . ' (' . $row['file_path'] . ")\n";
    }
}

$seq_refs = array_values(array_unique($seq_refs));
$photo_refs = array_values(array_unique($photo_refs));
$all_refs = array_values(array_unique(array_merge($seq_refs, $photo_refs)));

echo 'Deleting ' . count($seq_refs) . ' sequences + ' . count($photo_refs) . " photos…\n";
flush();

// Cancel jobs that reference these resources.
if ($all_refs !== []) {
    foreach ($all_refs as $ref) {
        ps_query(
            "UPDATE job_queue SET status = 5 WHERE status IN (1,3) AND (job_data LIKE ? OR job_code LIKE ?)",
            ['s', '%"resource":' . $ref . '%', 's', '%_' . $ref]
        );
        ps_query(
            "UPDATE job_queue SET status = 5 WHERE status IN (1,3) AND job_data LIKE ?",
            ['s', '%"ref":' . $ref . '%']
        );
    }
}

foreach ($all_refs as $ref) {
    echo "  delete_resource({$ref})…\n";
    flush();
    try {
        // Clear locks that block permanent delete.
        ps_query('UPDATE resource SET is_transcoding = 0 WHERE ref = ?', ['i', $ref]);
        // First call soft-deletes (archive → deletion state); second permanently purges.
        delete_resource($ref);
        $archive = (int) ps_value('SELECT archive value FROM resource WHERE ref = ?', ['i', $ref], -1);
        global $resource_deletion_state;
        if (isset($resource_deletion_state) && $archive === (int) $resource_deletion_state) {
            delete_resource($ref);
        }
    } catch (Throwable $e) {
        echo '    WARN: ' . $e->getMessage() . "\n";
    }
    // Always drop plugin claim rows so frames can be re-ingested.
    ps_query('DELETE FROM resource_image_sequence WHERE resource = ?', ['i', $ref]);
}

echo "Cleanup done. Remaining 2012/2013 sequences: "
    . ps_value(
        "SELECT COUNT(*) value FROM resource_image_sequence WHERE folder_path LIKE '2012/%' OR folder_path LIKE '2013/%'",
        [],
        0
    )
    . "\n";
} else {
    echo "Skipping delete (--no-delete); will only create sequences for unclaimed frames.\n";
}
foreach ($years as $year) {
    $year_path = $originals_root . '/' . $year;
    if (!is_dir($year_path)) {
        echo "Missing year folder: {$year_path}\n";
        continue;
    }

    $subdirs = [];
    foreach (scandir($year_path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }
        $path = $year_path . '/' . $entry;
        if (is_dir($path)) {
            $subdirs[] = [$entry, $path];
        }
    }

    echo '===== ' . $year . ' START ' . date('c') . ' (' . count($subdirs) . " shoots) =====\n";
    flush();

    foreach ($subdirs as [$entry, $path]) {
        echo '=== ' . date('H:i:s') . " {$entry} ===\n";
        flush();
        $batch = image_sequence_ingest_folder($path, [
            // Shoot folders are flat; avoid RecursiveDirectoryIterator on SMB (slow/flaky).
            'recursive' => false,
            'source_root' => $path,
            'auto_split' => false,
            'created_by' => 1,
        ]);
        $nseq = count($batch['sequences']);
        $nphoto = count($batch['photos']);
        echo '  sequences=' . $nseq . ' photos=' . $nphoto;
        if ($batch['sequences'] !== []) {
            echo ' refs=[' . implode(',', $batch['sequences']) . ']';
            $created_sequences = array_merge($created_sequences, $batch['sequences']);
        }
        echo "\n";
        flush();
    }
    echo '===== ' . $year . ' DONE ' . date('c') . " =====\n";
}

echo 'Created sequences: ' . count($created_sequences) . "\n";
if ($created_sequences !== []) {
    echo 'Refs: ' . implode(', ', $created_sequences) . "\n";
}

echo "Done. Proxy/AI jobs will drain via offline_jobs.\n";
