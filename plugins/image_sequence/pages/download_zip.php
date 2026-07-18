<?php

include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once __DIR__ . '/../include/image_sequence_functions.php';

$ref = getval('ref', 0, true);
if ($ref <= 0 || get_resource_access($ref) !== 0) {
    exit(escape($lang['error-permissiondenied'] ?? 'Permission denied'));
}

$resource = get_resource_data($ref);
if (!is_array($resource) || !image_sequence_is_sequence_resource($resource)) {
    exit(escape($lang['image_sequence_no_data']));
}

global $offline_job_queue, $lang, $userref;

if (!empty($offline_job_queue)) {
    job_queue_add(
        'image_sequence_download_zip',
        ['resource' => $ref, 'user' => (int) $userref],
        (string) $userref,
        '',
        $lang['image_sequence_download_queued'],
        $lang['image_sequence_proxy_failed'],
        'imgseq_zip_' . $ref
    );
    include "../../../include/header.php";
    echo '<p>' . escape($lang['image_sequence_download_queued']) . '</p>';
    include "../../../include/footer.php";
    exit;
}

$zip = image_sequence_create_download_zip($ref);
if ($zip === null || !file_exists($zip)) {
    exit(escape($lang['image_sequence_no_data']));
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="sequence_' . $ref . '.zip"');
header('Content-Length: ' . filesize($zip));
readfile($zip);
@unlink($zip);
exit;
