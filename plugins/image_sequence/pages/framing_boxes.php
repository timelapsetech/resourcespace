<?php

include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once __DIR__ . '/../include/image_sequence_functions.php';

$ref = getval('ref', 0, true);
$action = trim((string) getval('action', ''));

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

if ($ref <= 0) {
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

image_sequence_ensure_framing_table();

// list is readable for anyone who can view the resource; mutating actions need edit.
if ($action === 'list') {
    $dims = image_sequence_source_dimensions($resource);
    $send_json([
        'ok' => true,
        'boxes' => image_sequence_framing_get_boxes($ref),
        'source_width' => $dims['width'],
        'source_height' => $dims['height'],
    ]);
}

if (!get_edit_access($ref)) {
    $send_json(['ok' => false, 'message' => $lang['error-permissiondenied'] ?? 'Permission denied'], 403);
}

enforcePostRequest(getval('ajax', '') == 'true');

ob_start();
try {
    switch ($action) {
        case 'save':
            $input = [
                'ref' => getval('box_ref', 0, true),
                'label' => getval('label', ''),
                'aspect_w' => getval('aspect_w', 16, true),
                'aspect_h' => getval('aspect_h', 9, true),
                'x' => getval('x', 0, true),
                'y' => getval('y', 0, true),
                'width' => getval('width', 0, true),
                'height' => getval('height', 0, true),
            ];
            $result = image_sequence_framing_save_box($ref, $input);
            break;

        case 'delete':
            $result = image_sequence_framing_delete_box($ref, getval('box_ref', 0, true));
            break;

        case 'render':
            $result = image_sequence_framing_queue_render($ref, getval('box_ref', 0, true));
            break;

        default:
            $result = [
                'ok' => false,
                'message' => $lang['image_sequence_framing_bad_action'] ?? 'Unknown framing action.',
            ];
            break;
    }
} catch (Throwable $e) {
    ob_end_clean();
    $send_json([
        'ok' => false,
        'message' => ($lang['image_sequence_framing_failed'] ?? 'Framing request failed.')
            . ' (' . $e->getMessage() . ')',
    ], 500);
}
ob_end_clean();

if (!is_array($result)) {
    $result = ['ok' => false, 'message' => $lang['image_sequence_framing_failed'] ?? 'Framing request failed.'];
}

$send_json($result, !empty($result['ok']) ? 200 : 400);
