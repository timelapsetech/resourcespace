<?php

include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once __DIR__ . '/../include/image_sequence_functions.php';

$ref = getval('ref', 0, true);
$frame = getval('frame', 0, true);

$send_json = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"ok":false,"message":"JSON encode failed"}';
    }
    echo $json;
    exit;
};

if ($ref <= 0 || !get_edit_access($ref)) {
    $send_json(['ok' => false, 'message' => $lang['error-permissiondenied'] ?? 'Permission denied'], 403);
}

$resource = get_resource_data($ref);
if (!is_array($resource)) {
    $send_json(['ok' => false, 'message' => $lang['image_sequence_no_data'] ?? 'Resource not found.'], 400);
}

$is_sequence = image_sequence_is_sequence_resource($resource);
$is_video = image_sequence_is_video_resource($resource);
if (!$is_sequence && !$is_video) {
    $send_json([
        'ok' => false,
        'message' => $lang['image_sequence_no_data'] ?? 'Not an image sequence or video resource.',
    ], 400);
}

enforcePostRequest(getval('ajax', '') == 'true');

ob_start();
try {
    $result = $is_video
        ? image_sequence_set_video_representative_frame($ref, $frame)
        : image_sequence_set_representative_frame($ref, $frame);
} catch (Throwable $e) {
    ob_end_clean();
    $send_json([
        'ok' => false,
        'message' => ($lang['image_sequence_rep_frame_failed'] ?? 'Could not set representative frame.')
            . ' (' . $e->getMessage() . ')',
    ], 500);
}
ob_end_clean();

if (!is_array($result)) {
    $result = [
        'ok' => (bool) $result,
        'message' => $result
            ? ($lang['image_sequence_rep_frame_set'] ?? 'Representative frame updated.')
            : ($lang['image_sequence_rep_frame_failed'] ?? 'Could not set representative frame.'),
        'frame' => (int) $frame,
    ];
}

$send_json($result);
