<?php

include '../../../include/boot.php';
include '../../../include/authenticate.php';
include_once __DIR__ . '/../include/openai_gpt_functions.php';

$ref = getval('ref', 0, true);
$field = getval('field', 0, true);

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

enforcePostRequest(getval('ajax', '') == 'true');

if ($ref <= 0 || $field <= 0 || !get_edit_access($ref)) {
    $send_json(['ok' => false, 'message' => $lang['error-permissiondenied'] ?? 'Permission denied'], 403);
}

if (!openai_gpt_is_ai_managed_field($field)) {
    $send_json(['ok' => false, 'message' => $lang['openai_gpt_not_ai_field'] ?? 'Not an AI-managed field.'], 400);
}

openai_gpt_unlock_field($ref, $field);

$send_json([
    'ok' => true,
    'message' => $lang['openai_gpt_field_unlocked'] ?? 'AI may update this field again.',
    'ref' => $ref,
    'field' => $field,
]);
