<?php
/**
 * CLI/cron: ingest in-place image sequence folders under configured scan roots
 * (read-only — frames are referenced, not copied or modified).
 *
 * Usage:
 *   php plugins/image_sequence/pages/tools/image_sequence_sync.php [folder]
 *
 * If folder is omitted, walks each configured scan root one level of subfolders
 * (and root itself) looking for stills.
 */

// File lives at plugins/image_sequence/pages/tools/ — 5 levels up is the RS root.
include dirname(__FILE__, 5) . '/include/boot.php';
command_line_only();

// Live progress when piped to tee/logs (PHP buffers stdout by default).
if (function_exists('ob_implicit_flush')) {
    ob_implicit_flush(true);
}
while (ob_get_level() > 0) {
    ob_end_flush();
}

include_once dirname(__FILE__, 3) . '/include/image_sequence_functions.php';

image_sequence_ensure_setup();

$target = $argv[1] ?? '';
$results = ['sequences' => [], 'photos' => []];

if ($target !== '') {
    if (!is_dir($target)) {
        echo "Not a directory: {$target}\n";
        exit(1);
    }

    // Year/archive folders: process each immediate subfolder with progress (faster feedback
    // than one silent recursive pass over tens of thousands of frames).
    $subdirs = [];
    $has_root_stills = false;
    foreach (scandir($target) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }
        $path = $target . '/' . $entry;
        if (is_dir($path)) {
            $subdirs[] = [$entry, $path];
        } elseif (is_file($path) && image_sequence_is_supported_file($path)) {
            $has_root_stills = true;
        }
    }

    if ($subdirs !== []) {
        echo "Scanning {$target} (" . count($subdirs) . " subfolders)\n";
        foreach ($subdirs as [$entry, $path]) {
            echo "  {$entry} ... ";
            flush();
            $batch = image_sequence_ingest_folder($path, [
                'recursive' => true,
                'source_root' => $path,
            ]);
            echo 'sequences=' . count($batch['sequences']) . ' photos=' . count($batch['photos']) . "\n";
            $results['sequences'] = array_merge($results['sequences'], $batch['sequences']);
            $results['photos'] = array_merge($results['photos'], $batch['photos']);
        }
        if ($has_root_stills) {
            echo "  (root stills) ... ";
            flush();
            $batch = image_sequence_ingest_folder($target, [
                'recursive' => false,
                'source_root' => $target,
            ]);
            echo 'sequences=' . count($batch['sequences']) . ' photos=' . count($batch['photos']) . "\n";
            $results['sequences'] = array_merge($results['sequences'], $batch['sequences']);
            $results['photos'] = array_merge($results['photos'], $batch['photos']);
        }
    } else {
        echo "Scanning {$target}\n";
        flush();
        $batch = image_sequence_ingest_folder($target, [
            'recursive' => true,
            'source_root' => $target,
        ]);
        $results['sequences'] = array_merge($results['sequences'], $batch['sequences']);
        $results['photos'] = array_merge($results['photos'], $batch['photos']);
    }
} else {
    foreach (image_sequence_scan_roots() as $root) {
        echo "Scanning {$root}\n";
        // Process immediate subfolders as independent shoot roots, plus files at root.
        $entries = scandir($root) ?: [];
        $has_root_stills = false;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            $path = $root . '/' . $entry;
            if (is_file($path) && image_sequence_is_supported_file($path)) {
                $has_root_stills = true;
            }
            if (is_dir($path)) {
                $batch = image_sequence_ingest_folder($path, [
                    'recursive' => true,
                    'source_root' => $path,
                ]);
                $new_count = count($batch['sequences']);
                echo "  {$entry}: new sequences={$new_count} photos=" . count($batch['photos']) . "\n";
                $results['sequences'] = array_merge($results['sequences'], $batch['sequences']);
                $results['photos'] = array_merge($results['photos'], $batch['photos']);
            }
        }
        if ($has_root_stills) {
            $batch = image_sequence_ingest_folder($root, [
                'recursive' => false,
                'source_root' => $root,
            ]);
            echo "  (root): sequences=" . count($batch['sequences']) . " photos=" . count($batch['photos']) . "\n";
            $results['sequences'] = array_merge($results['sequences'], $batch['sequences']);
            $results['photos'] = array_merge($results['photos'], $batch['photos']);
        }
    }
}

echo "Done. Sequences: " . count($results['sequences']) . ", Photos: " . count($results['photos']) . "\n";
if ($results['sequences'] !== []) {
    echo "Sequence refs: " . implode(', ', $results['sequences']) . "\n";
}
if ($results['photos'] !== []) {
    echo "Photo refs: " . implode(', ', $results['photos']) . "\n";
}
