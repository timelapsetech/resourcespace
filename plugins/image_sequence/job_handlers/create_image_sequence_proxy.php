<?php
/**
 * Offline job: create_image_sequence_proxy
 *
 * $job_data['resource'] — resource ref
 */

include_once __DIR__ . '/../include/image_sequence_functions.php';

$resource = (int) ($job_data['resource'] ?? 0);
if ($resource <= 0) {
    job_queue_update($jobref, $job_data, STATUS_ERROR);
    return;
}

$ok = image_sequence_generate_proxy($resource);
if ($ok) {
    global $offline_job_delete_completed;
    if (!empty($offline_job_delete_completed)) {
        job_queue_delete($jobref);
    } else {
        job_queue_update($jobref, $job_data, STATUS_COMPLETE);
    }
    if (!empty($job_success_text)) {
        message_add((int) ($job['user'] ?? 0), $job_success_text, $baseurl . '/pages/view.php?ref=' . $resource);
    }
} else {
    job_queue_update($jobref, $job_data, STATUS_ERROR);
    if (!empty($job_failure_text)) {
        message_add((int) ($job['user'] ?? 0), $job_failure_text);
    }
}
