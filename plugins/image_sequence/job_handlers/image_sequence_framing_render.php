<?php
/**
 * Offline job: image_sequence_framing_render
 *
 * $job_data['resource'] — resource ref
 * $job_data['box_ref']  — framing box ref
 */

include_once __DIR__ . '/../include/image_sequence_functions.php';

global $baseurl, $offline_job_delete_completed;

$resource = (int) ($job_data['resource'] ?? 0);
$box_ref = (int) ($job_data['box_ref'] ?? 0);
if ($resource <= 0 || $box_ref <= 0) {
    job_queue_update($jobref, $job_data, STATUS_ERROR);
    return;
}

$ok = image_sequence_render_framing_box($box_ref);
if ($ok) {
    if (!empty($offline_job_delete_completed)) {
        job_queue_delete($jobref);
    } else {
        job_queue_update($jobref, $job_data, STATUS_COMPLETE);
    }
    if (!empty($job_success_text)) {
        message_add(
            (int) ($job['user'] ?? 0),
            $job_success_text,
            $baseurl . '/pages/view.php?ref=' . $resource
        );
    }
} else {
    job_queue_update($jobref, $job_data, STATUS_ERROR);
    if (!empty($job_failure_text)) {
        message_add((int) ($job['user'] ?? 0), $job_failure_text);
    }
}
