<?php
/**
 * Offline job: process_image_sequence_proxies
 *
 * Rebuilds proxy videos for sequences that are pending, failed, or stuck processing.
 */

include_once __DIR__ . '/../include/image_sequence_functions.php';

$rows = ps_query(
    "SELECT resource FROM resource_image_sequence
     WHERE proxy_status IS NULL
        OR proxy_status = ''
        OR proxy_status IN ('pending', 'failed', 'processing')"
);

$ok_count = 0;
$fail_count = 0;
foreach ($rows as $row) {
    $resource = (int) ($row['resource'] ?? 0);
    if ($resource <= 0) {
        continue;
    }
    image_sequence_clear_transcoding_lock($resource);
    if (image_sequence_generate_proxy($resource)) {
        $ok_count++;
    } else {
        $fail_count++;
    }
}

global $offline_job_delete_completed;
if ($fail_count === 0) {
    if (!empty($offline_job_delete_completed)) {
        job_queue_delete($jobref);
    } else {
        job_queue_update($jobref, $job_data, STATUS_COMPLETE);
    }
    if (!empty($job_success_text)) {
        message_add(
            (int) ($job['user'] ?? 0),
            str_replace('%count%', (string) $ok_count, $job_success_text)
        );
    }
} else {
    job_queue_update($jobref, $job_data, STATUS_ERROR);
    if (!empty($job_failure_text)) {
        message_add(
            (int) ($job['user'] ?? 0),
            str_replace(
                ['%ok%', '%fail%'],
                [(string) $ok_count, (string) $fail_count],
                $job_failure_text
            )
        );
    }
}
