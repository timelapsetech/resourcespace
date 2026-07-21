<?php

declare(strict_types=1);

include_once '../../../include/boot.php';
include_once '../../../include/authenticate.php';
include_once dirname(__DIR__) . '/include/aria_home_functions.php';
include_once dirname(__DIR__) . '/include/aria_home_render.php';

header('Content-Type: application/json; charset=UTF-8');

$kind = getval('kind', 'all');
if (!in_array($kind, ['all', 'image', 'video'], true)) {
    $kind = 'all';
}
$collection = (int) getval('collection', 0);
$tags_raw = trim((string) getval('tags', ''));
$active_tags = [];
if ($tags_raw !== '') {
    foreach (explode(',', $tags_raw) as $t) {
        $t = (int) $t;
        if ($t > 0) {
            $active_tags[] = $t;
        }
    }
}
$offset = max(0, (int) getval('offset', 0));
$per_page = max(1, (int) ($GLOBALS['aria_home_per_page'] ?? 12));
$keywords = trim((string) getval('search', ''));

$browse = aria_home_browse($kind, $collection, $active_tags, $offset, $per_page, $keywords);
$returned = count($browse['data']);
$next_offset = $offset + $returned;
$total = (int) $browse['total'];

echo json_encode([
    'ok' => true,
    'total' => $total,
    'offset' => $offset,
    'per_page' => $per_page,
    'returned' => $returned,
    'next_offset' => $next_offset,
    'has_more' => $next_offset < $total,
    'html' => aria_home_render_grid_html($browse['data']),
]);
