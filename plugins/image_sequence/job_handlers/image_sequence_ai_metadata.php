<?php
/**
 * Offline job: image_sequence_ai_metadata
 *
 * $job_data['ref'] — resource ref
 * $job_data['force_overwrite'] — regenerate even when fields already have values
 */

include_once __DIR__ . '/../include/image_sequence_functions.php';

global $baseurl, $offline_job_delete_completed;

$resource = (int) ($job_data['ref'] ?? ($job_data['resource'] ?? 0));
$force_overwrite = !empty($job_data['force_overwrite']);

if ($resource <= 0 || image_sequence_get_data($resource) === null) {
    job_queue_update($jobref, $job_data, STATUS_ERROR);
    return;
}

try {
    image_sequence_process_ai_metadata($resource, $force_overwrite);
    if (!empty($offline_job_delete_completed)) {
        job_queue_delete($jobref);
    } else {
        job_queue_update($jobref, $job_data, STATUS_COMPLETE);
    }
    if (!empty($job_success_text)) {
        message_add((int) ($job['user'] ?? 0), $job_success_text, $baseurl . '/pages/view.php?ref=' . $resource);
    }
} catch (Throwable $e) {
    debug('image_sequence_ai_metadata job: ' . $e->getMessage());
    job_queue_update($jobref, $job_data, STATUS_ERROR);
    if (!empty($job_failure_text)) {
        message_add((int) ($job['user'] ?? 0), $job_failure_text);
    }
}
