<?php

/**
 * Cadence auto-split tests — ported from Ingestr SequenceDetectionTests.swift
 */

command_line_only();

include_once __DIR__ . '/../../plugins/image_sequence/include/cadence_functions.php';

$GLOBALS['image_sequence_min_files_for_cadence'] = 3;
$GLOBALS['image_sequence_max_cadence_sample'] = 180;
$GLOBALS['image_sequence_minimum_session_gap'] = 600;
$GLOBALS['image_sequence_minimum_adaptive_gap'] = 180;

function imgseq_test_fail(string $message): bool
{
    echo $message;
    return false;
}

// Threshold tiers
if (image_sequence_split_threshold(5.0) != 600.0) {
    return imgseq_test_fail('Fast timelapse 5s cadence should require 600s gap');
}
if (image_sequence_split_threshold(30.0) != 600.0) {
    return imgseq_test_fail('Fast timelapse 30s cadence should require 600s gap');
}
if (image_sequence_split_threshold(60.0) != 300.0) {
    return imgseq_test_fail('Medium 60s cadence should split at 300s');
}
if (image_sequence_split_threshold(120.0) != 600.0) {
    return imgseq_test_fail('Medium 120s cadence should split at 600s');
}

// Fast timelapse: 2-minute pause should NOT split
$base = 0.0;
$files = [];
for ($i = 0; $i < 5; $i++) {
    $files[] = ['path' => "/tmp/{$i}.jpg", 'date' => $base + ($i * 5)];
}
$files[] = ['path' => '/tmp/5.jpg', 'date' => $base + 120];
$files[] = ['path' => '/tmp/6.jpg', 'date' => $base + 125];
$breaks = image_sequence_find_breaks($files, 5.0);
if ($breaks !== [0]) {
    return imgseq_test_fail('A 2-minute pause on a 5s timelapse should stay one sequence');
}

// Fast timelapse: 11-minute gap should split
$files = [];
for ($i = 0; $i < 5; $i++) {
    $files[] = ['path' => "/tmp/a{$i}.jpg", 'date' => $base + ($i * 5)];
}
$files[] = ['path' => '/tmp/b0.jpg', 'date' => $base + 660];
$files[] = ['path' => '/tmp/b1.jpg', 'date' => $base + 665];
$breaks = image_sequence_find_breaks($files, 5.0);
if ($breaks !== [0, 5]) {
    return imgseq_test_fail('An 11-minute gap should start a new sequence');
}

// Exactly 10-minute gap should split
$files = [];
for ($i = 0; $i < 3; $i++) {
    $files[] = ['path' => "/tmp/a{$i}.jpg", 'date' => $base + ($i * 3)];
}
$files[] = ['path' => '/tmp/b0.jpg', 'date' => $base + 9 + 600];
$files[] = ['path' => '/tmp/b1.jpg', 'date' => $base + 9 + 603];
$breaks = image_sequence_find_breaks($files, 3.0);
if ($breaks !== [0, 3]) {
    return imgseq_test_fail('A 10-minute gap should start a new sequence');
}

// 60s cadence: 2-minute extra pause should NOT split
$files = [];
for ($i = 0; $i < 4; $i++) {
    $files[] = ['path' => "/tmp/{$i}.jpg", 'date' => $base + ($i * 60)];
}
$files[] = ['path' => '/tmp/4.jpg', 'date' => $base + 240 + 120];
$files[] = ['path' => '/tmp/5.jpg', 'date' => $base + 240 + 180];
$breaks = image_sequence_find_breaks($files, 60.0);
if ($breaks !== [0]) {
    return imgseq_test_fail('A 2-minute extra pause on 60s cadence should stay one sequence');
}

// 60s cadence: 5-minute gap should split
$files = [];
for ($i = 0; $i < 4; $i++) {
    $files[] = ['path' => "/tmp/{$i}.jpg", 'date' => $base + ($i * 60)];
}
$files[] = ['path' => '/tmp/4.jpg', 'date' => $base + 180 + 300];
$files[] = ['path' => '/tmp/5.jpg', 'date' => $base + 180 + 360];
$breaks = image_sequence_find_breaks($files, 60.0);
if ($breaks !== [0, 4]) {
    return imgseq_test_fail('A 5-minute gap on 60s cadence should start a new sequence, got ' . json_encode($breaks));
}

// Median cadence ignores session gaps
$files = [
    ['path' => '/tmp/0.jpg', 'date' => 0],
    ['path' => '/tmp/1.jpg', 'date' => 10],
    ['path' => '/tmp/2.jpg', 'date' => 20],
    ['path' => '/tmp/3.jpg', 'date' => 30],
    ['path' => '/tmp/4.jpg', 'date' => 30 + 3600],
];
$cadence = image_sequence_detect_normal_interval($files);
if ($cadence !== 10.0) {
    return imgseq_test_fail('Median cadence should be 10s ignoring hour gap, got ' . json_encode($cadence));
}

// Split + segment routing sizes
$files = [];
for ($i = 0; $i < 12; $i++) {
    $files[] = ['path' => "/tmp/a{$i}.jpg", 'date' => $base + ($i * 5)];
}
for ($i = 0; $i < 12; $i++) {
    $files[] = ['path' => "/tmp/b{$i}.jpg", 'date' => $base + 1000 + ($i * 5)];
}
$segments = image_sequence_split_files($files, true);
if (count($segments) !== 2 || count($segments[0]) !== 12 || count($segments[1]) !== 12) {
    return imgseq_test_fail('Expected two 12-frame segments, got ' . json_encode(array_map('count', $segments)));
}

// Source folder grouping
$source = '/data/Source';
$grouped = image_sequence_group_by_source_folder([
    ['path' => $source . '/ShootA/a.jpg', 'date' => 100],
    ['path' => $source . '/ShootB/sub/b.jpg', 'date' => 200],
    ['path' => $source . '/c.jpg', 'date' => 50],
], $source);
if (!isset($grouped['ShootA'], $grouped['ShootB/sub'], $grouped[''])) {
    return imgseq_test_fail('Source folder grouping keys incorrect: ' . json_encode(array_keys($grouped)));
}

return true;
