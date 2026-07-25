<?php
/**
 * Clear orphan offline-job state left when the container is killed/restarted.
 *
 * Workers die with the container, but filestore process locks and
 * job_queue STATUS_INPROGRESS rows persist on the bind mount. Cron then
 * sees "N jobs in progress" and refuses to start new work.
 *
 * Invoked from docker/entrypoint.sh (best-effort; must not block Apache).
 */

include '/var/www/html/include/boot.php';
command_line_only();

$locks_dir = get_temp_dir() . '/process_locks';
$cleared_locks = 0;
if (is_dir($locks_dir)) {
    foreach (glob($locks_dir . '/{job_*,offlinejobs_*}', GLOB_BRACE) ?: [] as $lock) {
        if (@unlink($lock)) {
            $cleared_locks++;
        }
    }
}

$requeued = 0;
$rows = ps_query(
    'SELECT ref, job_data FROM job_queue WHERE status = ?',
    ['i', STATUS_INPROGRESS]
);
foreach ($rows as $row) {
    $job_data = json_decode($row['job_data'], true);
    if (!is_array($job_data)) {
        $job_data = [];
    }
    job_queue_update((int) $row['ref'], $job_data, STATUS_ACTIVE);
    $requeued++;
}

// FFmpeg killed mid-proxy can leave is_transcoding=1 (blocks delete / confuses UI).
$stuck = (int) ps_value(
    'SELECT COUNT(*) value FROM resource WHERE is_transcoding = 1',
    [],
    0
);
if ($stuck > 0) {
    ps_query('UPDATE resource SET is_transcoding = 0 WHERE is_transcoding = 1');
}

echo date('c')
    . " recover_offline_jobs_on_boot: cleared_locks={$cleared_locks}"
    . " requeued_inprogress={$requeued}"
    . " cleared_transcoding={$stuck}\n";
