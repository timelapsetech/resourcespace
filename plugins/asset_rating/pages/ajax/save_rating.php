<?php

declare(strict_types=1);

include_once dirname(__DIR__, 4) . '/include/boot.php';
include_once dirname(__DIR__, 4) . '/include/authenticate.php';
include_once dirname(__DIR__, 2) . '/include/asset_rating_functions.php';

header('Content-Type: application/json; charset=utf-8');

$ref = (int) getval('ref', 0, true);
$rating = (int) getval('rating', -1, true);

if ($ref <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_ref']);
    exit;
}

if ($rating < 0 || $rating > 5) {
    http_response_code(400);
    global $lang;
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_rating',
        'message' => $lang['asset_rating_invalid'] ?? 'Rating must be between 0 and 5.',
    ]);
    exit;
}

if (!get_edit_access($ref)) {
    http_response_code(403);
    global $lang;
    echo json_encode([
        'ok' => false,
        'error' => 'denied',
        'message' => $lang['asset_rating_denied'] ?? 'You do not have permission to rate this asset.',
    ]);
    exit;
}

$ok = asset_rating_set($ref, $rating);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
}

global $lang, $usersession;
echo json_encode([
    'ok' => true,
    'ref' => $ref,
    'rating' => $rating,
    'message' => $lang['asset_rating_saved'] ?? 'Rating saved',
    'CSRFToken' => !empty($usersession)
        ? generateCSRFToken($usersession, 'asset_rating_' . $ref)
        : '',
]);
