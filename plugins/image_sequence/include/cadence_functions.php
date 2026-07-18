<?php

/**
 * Image sequence cadence detection — ported from Ingestr RenameViewModel.swift
 * (detectNormalInterval, sequenceSplitThreshold, findSequenceBreaks).
 *
 * Sequences are detected from capture-time spacing, not filename numbering.
 */

/**
 * Median inter-frame interval for gaps in (0, max_cadence_sample].
 *
 * @param array<int, array{path?: string, date: float|int|DateTimeInterface}> $files Sorted by date ascending
 * @return float|null Seconds, or null if cadence cannot be estimated
 */
function image_sequence_detect_normal_interval(array $files, ?int $min_files = null, ?float $max_cadence_sample = null): ?float
{
    $min_files = $min_files ?? (int) ($GLOBALS['image_sequence_min_files_for_cadence'] ?? 3);
    $max_cadence_sample = $max_cadence_sample ?? (float) ($GLOBALS['image_sequence_max_cadence_sample'] ?? 180);

    if (count($files) < $min_files) {
        return null;
    }

    $intervals = [];
    for ($i = 1, $n = count($files); $i < $n; $i++) {
        $interval = image_sequence_date_diff_seconds($files[$i]['date'], $files[$i - 1]['date']);
        if ($interval > 0 && $interval <= $max_cadence_sample) {
            $intervals[] = $interval;
        }
    }

    if ($intervals === []) {
        return null;
    }

    sort($intervals, SORT_NUMERIC);
    $median_index = (int) floor(count($intervals) / 2);

    return (float) $intervals[$median_index];
}

/**
 * Cadence-adaptive gap threshold (seconds) before starting a new sequence.
 */
function image_sequence_split_threshold(float $normal_interval, ?float $minimum_session_gap = null, ?float $minimum_adaptive_gap = null, ?float $max_cadence_sample = null): float
{
    $minimum_session_gap = $minimum_session_gap ?? (float) ($GLOBALS['image_sequence_minimum_session_gap'] ?? 600);
    $minimum_adaptive_gap = $minimum_adaptive_gap ?? (float) ($GLOBALS['image_sequence_minimum_adaptive_gap'] ?? 180);
    $max_cadence_sample = $max_cadence_sample ?? (float) ($GLOBALS['image_sequence_max_cadence_sample'] ?? 180);

    if ($normal_interval <= 30) {
        return max($normal_interval * 5, $minimum_session_gap);
    }
    if ($normal_interval <= 120) {
        return max($normal_interval * 5, $minimum_adaptive_gap);
    }
    if ($normal_interval <= $max_cadence_sample) {
        return max($normal_interval * 3, $minimum_adaptive_gap);
    }

    return max($normal_interval * 3, $minimum_session_gap);
}

/**
 * Return break indices into $files (always includes 0).
 *
 * @param array<int, array{path?: string, date: float|int|DateTimeInterface}> $files Sorted by date
 * @return list<int>
 */
function image_sequence_find_breaks(array $files, float $normal_interval, ?float $minimum_session_gap = null): array
{
    $minimum_session_gap = $minimum_session_gap ?? (float) ($GLOBALS['image_sequence_minimum_session_gap'] ?? 600);
    $breaks = [0];
    $gap_threshold = image_sequence_split_threshold($normal_interval);

    for ($i = 1, $n = count($files); $i < $n; $i++) {
        $interval = image_sequence_date_diff_seconds($files[$i]['date'], $files[$i - 1]['date']);
        if ($interval >= $minimum_session_gap || $interval >= $gap_threshold) {
            $breaks[] = $i;
        }
    }

    return $breaks;
}

/**
 * Split sorted files into segments using Ingestr auto-split rules.
 *
 * @param array<int, array{path: string, date: float|int|DateTimeInterface}> $files
 * @return list<list<array{path: string, date: float|int|DateTimeInterface}>>
 */
function image_sequence_split_files(array $files, bool $auto_split = true): array
{
    if ($files === []) {
        return [];
    }

    $breaks = [0];
    if ($auto_split) {
        $normal_interval = image_sequence_detect_normal_interval($files);
        if ($normal_interval !== null) {
            $breaks = image_sequence_find_breaks($files, $normal_interval);
        }
    }

    $segments = [];
    $count = count($files);
    for ($i = 0, $b = count($breaks); $i < $b; $i++) {
        $start = $breaks[$i];
        $end = ($i + 1 < $b) ? $breaks[$i + 1] : $count;
        $segments[] = array_slice($files, $start, $end - $start);
    }

    return $segments;
}

/**
 * Group files by source-relative parent folder (Ingestr groupFilesBySourceFolder).
 *
 * @param list<array{path: string, date: float|int|DateTimeInterface}> $files Absolute paths
 * @return array<string, list<array{path: string, date: float|int|DateTimeInterface}>>
 */
function image_sequence_group_by_source_folder(array $files, string $source_root): array
{
    $source_root = rtrim(str_replace('\\', '/', $source_root), '/');
    $groups = [];

    foreach ($files as $file) {
        $key = image_sequence_source_relative_group_key($file['path'], $source_root);
        $groups[$key][] = $file;
    }

    return $groups;
}

/**
 * Relative group key for a file under $source_root (parent folder path, or "" at root).
 */
function image_sequence_source_relative_group_key(string $path, string $source_root): string
{
    $source_root = rtrim(str_replace('\\', '/', $source_root), '/');
    $path = str_replace('\\', '/', $path);

    if (strpos($path, $source_root . '/') === 0) {
        $relative = substr($path, strlen($source_root) + 1);
    } else {
        $relative = basename($path);
    }

    $dir = dirname($relative);
    if ($dir === '.' || $dir === '') {
        return '';
    }

    return $dir;
}

/**
 * @param float|int|DateTimeInterface $later
 * @param float|int|DateTimeInterface $earlier
 */
function image_sequence_date_diff_seconds($later, $earlier): float
{
    return image_sequence_to_timestamp($later) - image_sequence_to_timestamp($earlier);
}

/**
 * @param float|int|DateTimeInterface|string $date
 */
function image_sequence_to_timestamp($date): float
{
    if ($date instanceof DateTimeInterface) {
        return (float) $date->format('U.u');
    }
    if (is_numeric($date)) {
        return (float) $date;
    }
    $ts = strtotime((string) $date);

    return $ts === false ? 0.0 : (float) $ts;
}
