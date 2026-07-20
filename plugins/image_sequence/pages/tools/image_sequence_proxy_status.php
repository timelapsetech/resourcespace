<?php
/**
 * CLI: diagnose image sequence proxy + offline job health.
 *   php plugins/image_sequence/pages/tools/image_sequence_proxy_status.php
 */

include dirname(__FILE__, 5) . '/include/boot.php';
command_line_only();

include_once dirname(__FILE__, 3) . '/include/image_sequence_functions.php';

echo "=== Image Sequence proxy status ===\n";

$total = (int) ps_value('SELECT COUNT(*) value FROM resource_image_sequence', [], 0);
echo "total_sequences={$total}\n";

$rows = ps_query(
    "SELECT COALESCE(NULLIF(TRIM(proxy_status), ''), '(empty)') AS st, COUNT(*) AS c
     FROM resource_image_sequence
     GROUP BY st
     ORDER BY c DESC"
);
foreach ($rows as $r) {
    echo 'proxy_status ' . $r['st'] . '=' . $r['c'] . "\n";
}

$locks = (int) ps_value(
    'SELECT COUNT(*) value FROM resource r
     INNER JOIN resource_image_sequence s ON s.resource = r.ref
     WHERE r.is_transcoding = 1',
    [],
    0
);
echo "is_transcoding={$locks}\n";

global $offline_job_queue, $ffmpeg_preview_extension;
echo 'offline_job_queue=' . var_export(!empty($offline_job_queue), true) . "\n";
$ext = $ffmpeg_preview_extension ?: 'mp4';

// Sample recent sequences: does pre proxy file exist?
$sample = ps_query(
    'SELECT resource, proxy_status, frame_count, folder_path
     FROM resource_image_sequence
     ORDER BY resource DESC
     LIMIT 15'
);
echo "\n=== newest 15 sequences ===\n";
$ready_with_file = 0;
$ready_missing_file = 0;
$pendingish = 0;
foreach ($sample as $row) {
    $ref = (int) $row['resource'];
    $status = (string) ($row['proxy_status'] ?? '');
    $path = get_resource_path($ref, true, 'pre', false, $ext);
    $exists = is_string($path) && is_file($path) && filesize($path) > 0;
    $size = $exists ? filesize($path) : 0;
    if ($status === 'ready' && $exists) {
        $ready_with_file++;
    } elseif ($status === 'ready' && !$exists) {
        $ready_missing_file++;
    }
    if (in_array($status, ['pending', 'processing', 'failed', ''], true) || $status === null) {
        $pendingish++;
    }
    echo sprintf(
        "ref=%d status=%s frames=%s file=%s size=%s folder=%s\n",
        $ref,
        $status === '' ? '(empty)' : $status,
        (string) ($row['frame_count'] ?? ''),
        $exists ? 'yes' : 'NO',
        $exists ? (string) $size : '-',
        (string) ($row['folder_path'] ?? '')
    );
}

// Global file checks for ready vs missing
$all = ps_query('SELECT resource, proxy_status FROM resource_image_sequence');
$stats = [
    'ready_ok' => 0,
    'ready_missing' => 0,
    'not_ready' => 0,
    'not_ready_has_file' => 0,
];
foreach ($all as $row) {
    $ref = (int) $row['resource'];
    $status = (string) ($row['proxy_status'] ?? '');
    $path = get_resource_path($ref, true, 'pre', false, $ext);
    $exists = is_string($path) && is_file($path) && filesize($path) > 0;
    if ($status === 'ready') {
        if ($exists) {
            $stats['ready_ok']++;
        } else {
            $stats['ready_missing']++;
        }
    } else {
        $stats['not_ready']++;
        if ($exists) {
            $stats['not_ready_has_file']++;
        }
    }
}

echo "\n=== proxy file audit ===\n";
foreach ($stats as $k => $v) {
    echo "{$k}={$v}\n";
}

echo "\n=== job_queue ===\n";
$by_status = ps_query('SELECT status, COUNT(*) AS c FROM job_queue GROUP BY status ORDER BY c DESC');
if ($by_status === []) {
    echo "(job_queue empty)\n";
}
foreach ($by_status as $r) {
    $st = $r['status'];
    if ($st === null || $st === '') {
        $st = '(empty)';
    }
    echo "status {$st}={$r['c']}\n";
}

$jobs = ps_query(
    "SELECT ref, type, status, user, start_date, complete_date
     FROM job_queue
     WHERE type LIKE '%image_sequence%' OR type LIKE '%proxy%' OR type LIKE '%openai%' OR type LIKE '%ai%'
     ORDER BY ref DESC
     LIMIT 25"
);
if ($jobs === []) {
    echo "(no image_sequence/proxy/ai jobs found)\n";
} else {
    foreach ($jobs as $r) {
        echo sprintf(
            "#%s %s status=%s start=%s done=%s\n",
            $r['ref'],
            $r['type'],
            $r['status'] ?? '',
            $r['start_date'] ?? '',
            $r['complete_date'] ?? ''
        );
    }
}

$pending_jobs = (int) ps_value(
    'SELECT COUNT(*) value FROM job_queue WHERE status IN (0, 1) OR status IS NULL OR status = ?',
    ['s', ''],
    0
);
echo "actionable_jobs={$pending_jobs}\n";

echo "\n=== crontab / offline runner hints ===\n";
$cron = @shell_exec('crontab -l 2>/dev/null') ?: '';
if ($cron === '') {
    echo "(no crontab for container www user / root)\n";
} else {
    echo $cron;
}
$offline = '/var/www/html/pages/tools/offline_jobs.php';
echo 'offline_jobs.php=' . (is_file($offline) ? 'present' : 'missing') . "\n";
