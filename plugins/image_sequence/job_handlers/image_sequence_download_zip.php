<?php
/**
 * Offline job: image_sequence_download_zip
 *
 * $job_data['resource'], $job_data['user']
 */

include_once __DIR__ . '/../include/image_sequence_functions.php';

$resource = (int) ($job_data['resource'] ?? 0);
$user = (int) ($job_data['user'] ?? ($job['user'] ?? 0));

if ($resource <= 0) {
    job_queue_update($jobref, $job_data, STATUS_ERROR);
    return;
}

$zip = image_sequence_create_download_zip($resource);
if ($zip === null) {
    job_queue_update($jobref, $job_data, STATUS_ERROR);
    if (!empty($job_failure_text)) {
        message_add($user, $job_failure_text);
    }
    return;
}

// Move into a downloadable user temp location.
$userfile = get_temp_dir(false) . '/sequence_' . $resource . '_' . time() . '.zip';
@rename($zip, $userfile);
$link = $baseurl . '/pages/download.php?userfile=' . urlencode(basename($userfile));

global $offline_job_delete_completed;
if (!empty($offline_job_delete_completed)) {
    job_queue_delete($jobref);
} else {
    job_queue_update($jobref, $job_data, STATUS_COMPLETE);
}

message_add($user, $job_success_text ?: 'Sequence ZIP ready', $link);
