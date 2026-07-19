<?php

declare(strict_types=1);

/**
 * Aria Home — featured field setup and browse helpers.
 */

function aria_home_ensure_setup(): void
{
    $config = get_plugin_config('aria_home') ?: [];
    $changed = false;

    $field_ref = (int) ($config['aria_home_featured_field'] ?? 0);
    $yes_node = (int) ($config['aria_home_featured_node'] ?? 0);

    if ($field_ref <= 0) {
        $existing = (int) ps_value(
            "SELECT ref value FROM resource_type_field WHERE name = ?",
            ['s', 'featured_home'],
            0,
            'schema'
        );
        if ($existing > 0) {
            $field_ref = $existing;
        } else {
            $field_ref = (int) create_resource_type_field(
                'Featured on home',
                0,
                FIELD_TYPE_CHECK_BOX_LIST,
                'featured_home',
                true
            );
        }
        if ($field_ref > 0) {
            $config['aria_home_featured_field'] = $field_ref;
            $changed = true;
        }
    }

    if ($field_ref > 0 && $yes_node <= 0) {
        $nodes = get_nodes($field_ref) ?: [];
        foreach ($nodes as $node) {
            if (strcasecmp((string) ($node['name'] ?? ''), 'Yes') === 0) {
                $yes_node = (int) $node['ref'];
                break;
            }
        }
        if ($yes_node <= 0) {
            $created = set_node(null, $field_ref, 'Yes', null, 10);
            $yes_node = (int) $created;
        }
        if ($yes_node > 0) {
            $config['aria_home_featured_node'] = $yes_node;
            $changed = true;
        }
    }

    // Convert core City / State text fields into dynamic keyword lists (like Country)
    // so they work for faceted browse and controlled keywording.
    foreach ([119 => 'city', 120 => 'state'] as $geo_ref => $geo_key) {
        $info = get_resource_type_field($geo_ref);
        if (!is_array($info)) {
            continue;
        }
        if ((int) ($info['type'] ?? -1) !== FIELD_TYPE_DYNAMIC_KEYWORDS_LIST) {
            ps_query(
                'UPDATE resource_type_field SET type = ? WHERE ref = ?',
                ['i', FIELD_TYPE_DYNAMIC_KEYWORDS_LIST, 'i', $geo_ref],
                'schema'
            );
            clear_query_cache('schema');
            $changed = true;
        }
        $config['aria_home_' . $geo_key . '_field'] = $geo_ref;
    }

    // Seed US states / territories on State if the list is still sparse
    $state_field = (int) ($config['aria_home_state_field'] ?? 120);
    if ($state_field > 0) {
        $seeded = aria_home_seed_state_nodes($state_field);
        if ($seeded) {
            $changed = true;
        }
    }

    $config['aria_home_country_field'] = 3;
    $config['aria_home_other_field'] = 1;
    $config['aria_home_city_field'] = (int) ($config['aria_home_city_field'] ?? 119);
    $config['aria_home_state_field'] = (int) ($config['aria_home_state_field'] ?? 120);

    // Seed / merge facet sections (do not trust globals — saved plugin config may be partial)
    $defaults = [
        'collections' => ['label' => 'Collections', 'field' => 0, 'enabled' => true, 'limit' => 12],
        'country' => ['label' => 'Country', 'field' => 3, 'enabled' => true, 'limit' => 16],
        'state' => ['label' => 'State', 'field' => 120, 'enabled' => true, 'limit' => 20],
        'city' => ['label' => 'City', 'field' => 119, 'enabled' => true, 'limit' => 20],
        'other' => ['label' => 'Keyword', 'field' => 1, 'enabled' => true, 'limit' => 24],
        'subject' => ['label' => 'Subject', 'field' => 73, 'enabled' => true, 'limit' => 20],
        'event' => ['label' => 'Event', 'field' => 74, 'enabled' => true, 'limit' => 16],
        'landmark' => ['label' => 'Landmark', 'field' => 85, 'enabled' => true, 'limit' => 16],
        'person' => ['label' => 'People', 'field' => 29, 'enabled' => true, 'limit' => 16],
        'emotion' => ['label' => 'Emotion', 'field' => 75, 'enabled' => false, 'limit' => 16],
    ];
    $sections = $config['aria_home_facet_sections'] ?? [];
    if (!is_array($sections)) {
        $sections = [];
    }

    // Migrate older "location" section → Country
    if (isset($sections['location']) && !isset($sections['country'])) {
        $loc = is_array($sections['location']) ? $sections['location'] : [];
        $loc_field = (int) ($loc['field'] ?? 0);
        $sections['country'] = [
            'label' => 'Country',
            'field' => in_array($loc_field, [0, 124], true) ? 3 : $loc_field,
            'enabled' => array_key_exists('enabled', $loc) ? !empty($loc['enabled']) : true,
            'limit' => max(1, (int) ($loc['limit'] ?? 16)),
        ];
        unset($sections['location']);
        $changed = true;
    } else {
        unset($sections['location']);
    }

    $merged = [];
    foreach ($defaults as $key => $def) {
        $existing = $sections[$key] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        $row = array_merge($def, $existing);
        $row['label'] = trim((string) ($row['label'] ?? $def['label']));
        $row['field'] = (int) ($row['field'] ?? $def['field']);
        $row['limit'] = max(1, (int) ($row['limit'] ?? $def['limit']));
        if (!array_key_exists('enabled', $existing)) {
            $row['enabled'] = (bool) $def['enabled'];
        } else {
            $row['enabled'] = (bool) $existing['enabled'];
        }
        // Point city off empty aria_city (125) onto core City (119)
        if ($key === 'city' && in_array($row['field'], [0, 125], true)) {
            $row['field'] = 119;
        }
        if ($key === 'country' && in_array($row['field'], [0, 124], true)) {
            $row['field'] = 3;
        }
        if ($key === 'state' && $row['field'] <= 0) {
            $row['field'] = 120;
        }
        if ($key === 'other' && $row['field'] <= 0) {
            $row['field'] = 1;
        }
        // Prefer "Keyword" over the older "Other" sidebar label
        if ($key === 'other' && strcasecmp($row['label'], 'Other') === 0) {
            $row['label'] = 'Keyword';
        }
        $merged[$key] = $row;
    }
    // Keep any custom section keys the admin may have added later
    foreach ($sections as $key => $existing) {
        if (isset($merged[$key]) || !is_array($existing) || $key === 'location') {
            continue;
        }
        $merged[$key] = [
            'label' => trim((string) ($existing['label'] ?? $key)),
            'field' => (int) ($existing['field'] ?? 0),
            'enabled' => !empty($existing['enabled']),
            'limit' => max(1, (int) ($existing['limit'] ?? 16)),
        ];
    }
    if ($merged !== ($config['aria_home_facet_sections'] ?? null)) {
        $changed = true;
    }
    $sections = $merged;
    $config['aria_home_facet_sections'] = $sections;

    if (!isset($config['aria_home_featured_tags_field'])) {
        $config['aria_home_featured_tags_field'] = (int) ($GLOBALS['aria_home_featured_tags_field'] ?? 73);
        $changed = true;
    }
    if (!isset($config['aria_home_featured_tag_nodes'])) {
        $config['aria_home_featured_tag_nodes'] = [];
        $changed = true;
    }

    if ($changed) {
        set_plugin_config('aria_home', $config);
    }

    $GLOBALS['aria_home_featured_field'] = $field_ref;
    $GLOBALS['aria_home_featured_node'] = $yes_node;
    $GLOBALS['aria_home_country_field'] = (int) ($config['aria_home_country_field'] ?? 3);
    $GLOBALS['aria_home_state_field'] = (int) ($config['aria_home_state_field'] ?? 120);
    $GLOBALS['aria_home_city_field'] = (int) ($config['aria_home_city_field'] ?? 119);
    $GLOBALS['aria_home_other_field'] = (int) ($config['aria_home_other_field'] ?? 1);
    $GLOBALS['aria_home_facet_sections'] = $sections;
    $GLOBALS['aria_home_featured_tags_field'] = (int) ($config['aria_home_featured_tags_field'] ?? 73);
    $GLOBALS['aria_home_featured_tag_nodes'] = $config['aria_home_featured_tag_nodes'] ?? [];
}

/**
 * Seed US states / DC / territories onto a dynamic-keyword State field.
 * Existing nodes are left alone; returns true if any were added.
 */
function aria_home_seed_state_nodes(int $field): bool
{
    if ($field <= 0) {
        return false;
    }

    $states = [
        'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut',
        'Delaware', 'District of Columbia', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois',
        'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts',
        'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada',
        'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota',
        'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina',
        'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington',
        'West Virginia', 'Wisconsin', 'Wyoming',
        'American Samoa', 'Guam', 'Northern Mariana Islands', 'Puerto Rico', 'U.S. Virgin Islands',
    ];

    $existing = get_nodes($field) ?: [];
    $by_name = [];
    foreach ($existing as $node) {
        $by_name[strtolower(trim((string) ($node['name'] ?? '')))] = true;
    }

    // Only seed when the list is clearly incomplete (keeps custom installs intact)
    if (count($by_name) >= 40) {
        return false;
    }

    $order = (count($existing) + 1) * 10;
    $added = false;
    foreach ($states as $name) {
        $key = strtolower($name);
        if (isset($by_name[$key])) {
            continue;
        }
        $created = set_node(null, $field, $name, null, $order);
        if ((int) $created > 0) {
            $by_name[$key] = true;
            $order += 10;
            $added = true;
        }
    }

    return $added;
}

/**
 * @return array{field:int,node:int}
 */
function aria_home_featured_ids(): array
{
    $config = get_plugin_config('aria_home') ?: [];
    $field = (int) ($GLOBALS['aria_home_featured_field'] ?? $config['aria_home_featured_field'] ?? 0);
    $node = (int) ($GLOBALS['aria_home_featured_node'] ?? $config['aria_home_featured_node'] ?? 0);
    if ($field <= 0 || $node <= 0) {
        aria_home_ensure_setup();
        $config = get_plugin_config('aria_home') ?: [];
        $field = (int) ($config['aria_home_featured_field'] ?? 0);
        $node = (int) ($config['aria_home_featured_node'] ?? 0);
    }
    return ['field' => $field, 'node' => $node];
}

/**
 * Resolve restypes CSV for kind filter.
 */
function aria_home_restypes_for_kind(string $kind): string
{
    global $aria_home_photo_restype, $aria_home_video_restype, $aria_home_sequence_restype;

    $photo = (int) ($aria_home_photo_restype ?: 1);
    $video = (int) ($aria_home_video_restype ?: 3);
    $seq = (int) ($aria_home_sequence_restype ?: 5);

    switch ($kind) {
        case 'image':
            return implode(',', array_filter([$photo, $seq]));
        case 'video':
            return (string) $video;
        case 'all':
        default:
            return '';
    }
}

/**
 * Build search string from featured node / tags / free text.
 *
 * @param list<int> $tag_nodes
 */
function aria_home_build_search(string $base, array $tag_nodes): string
{
    $parts = [];
    $base = trim($base);
    if ($base !== '') {
        $parts[] = $base;
    }
    foreach ($tag_nodes as $node) {
        $node = (int) $node;
        if ($node > 0) {
            $parts[] = NODE_TOKEN_PREFIX . $node;
        }
    }
    return implode(' ', $parts);
}

/**
 * Run browse search and normalise to {total, data}.
 *
 * @param list<int> $tag_nodes
 * @return array{total:int,data:list<array>}
 */
function aria_home_browse(
    string $kind = 'all',
    int $collection = 0,
    array $tag_nodes = [],
    int $offset = 0,
    int $per_page = 24
): array {
    $restypes = aria_home_restypes_for_kind($kind);
    $search = '';

    if ($collection > 0) {
        $search = '!collection' . $collection;
        // !collection ignores restypes — post-filter below
        $result = do_search($search, '', 'date', 0, -1, 'desc');
        $rows = is_array($result) ? $result : [];
        if (isset($rows['data']) && is_array($rows['data'])) {
            $rows = $rows['data'];
        }
        if ($restypes !== '') {
            $allowed = array_map('intval', explode(',', $restypes));
            $rows = array_values(array_filter(
                $rows,
                static fn ($r) => in_array((int) ($r['resource_type'] ?? 0), $allowed, true)
            ));
        }
        if ($tag_nodes !== []) {
            // Narrow collection results with a second search of tagged refs
            $tag_search = aria_home_build_search('', $tag_nodes);
            $tagged = do_search($tag_search, $restypes, 'date', 0, -1, 'desc');
            $tagged_rows = is_array($tagged) ? $tagged : [];
            if (isset($tagged_rows['data']) && is_array($tagged_rows['data'])) {
                $tagged_rows = $tagged_rows['data'];
            }
            $tagged_refs = array_column($tagged_rows, 'ref');
            $rows = array_values(array_filter(
                $rows,
                static fn ($r) => in_array((int) ($r['ref'] ?? 0), $tagged_refs, true)
            ));
        }
        $total = count($rows);
        $data = array_slice($rows, $offset, $per_page);
        return ['total' => $total, 'data' => $data];
    }

    $search = aria_home_build_search('', $tag_nodes);
    $result = do_search($search, $restypes, 'date', 0, [$offset, $per_page], 'desc');

    if (is_array($result) && isset($result['data'])) {
        return [
            'total' => (int) ($result['total'] ?? count($result['data'])),
            'data' => array_values($result['data']),
        ];
    }

    $rows = is_array($result) ? $result : [];
    return ['total' => count($rows), 'data' => $rows];
}

/**
 * Featured hero resources (checkbox "Yes"), falling back to recent with previews.
 *
 * @return list<array>
 */
function aria_home_featured_resources(int $limit = 5): array
{
    $ids = aria_home_featured_ids();
    $rows = [];

    if ($ids['node'] > 0) {
        $search = NODE_TOKEN_PREFIX . $ids['node'];
        $result = do_search($search, '', 'date', 0, [0, $limit], 'desc');
        if (is_array($result) && isset($result['data'])) {
            $rows = $result['data'];
        } elseif (is_array($result)) {
            $rows = $result;
        }
    }

    if ($rows === []) {
        $result = do_search('', '', 'date', 0, [0, $limit], 'desc');
        if (is_array($result) && isset($result['data'])) {
            $rows = $result['data'];
        } elseif (is_array($result)) {
            $rows = $result;
        }
    }

    return array_values($rows);
}

/**
 * Collections for sidebar facets.
 *
 * @return list<array{ref:int,name:string,count:int}>
 */
function aria_home_facet_collections(int $limit = 12): array
{
    global $userref;

    $out = [];
    $featured = get_featured_collections(0, ['access_control' => true]);
    if (is_array($featured)) {
        foreach ($featured as $c) {
            if (!(int) ($c['has_resources'] ?? 0)) {
                continue;
            }
            $out[] = [
                'ref' => (int) $c['ref'],
                'name' => i18n_get_translated((string) $c['name']),
                'count' => (int) ps_value(
                    'SELECT COUNT(*) value FROM collection_resource WHERE collection = ?',
                    ['i', (int) $c['ref']],
                    0
                ),
            ];
        }
    }

    if ($out === [] && isset($userref)) {
        $mine = get_user_collections($userref);
        if (is_array($mine)) {
            foreach (array_slice($mine, 0, $limit) as $c) {
                $out[] = [
                    'ref' => (int) $c['ref'],
                    'name' => i18n_get_translated((string) ($c['name'] ?? '')),
                    'count' => (int) ($c['count'] ?? $c['result_count'] ?? 0),
                ];
            }
        }
    }

    return array_slice($out, 0, $limit);
}

/**
 * Plugin config with globals merged for runtime.
 *
 * @return array<string,mixed>
 */
function aria_home_plugin_config(): array
{
    $config = get_plugin_config('aria_home') ?: [];
    if (!is_array($config)) {
        $config = [];
    }
    return $config;
}

/**
 * Curated featured tags for the top toolbar.
 * Falls back to the top used nodes from the source field when none are curated yet.
 * Tags with zero assets are omitted.
 *
 * @return list<array{ref:int,name:string,count:int}>
 */
function aria_home_featured_tags(int $fallback_limit = 10): array
{
    $config = aria_home_plugin_config();
    $curated = $config['aria_home_featured_tag_nodes']
        ?? ($GLOBALS['aria_home_featured_tag_nodes'] ?? []);
    if (!is_array($curated)) {
        $curated = [];
    }
    $curated = array_values(array_filter(array_map('intval', $curated), static fn ($n) => $n > 0));

    if ($curated !== []) {
        $by_ref = [];
        foreach (get_nodes_by_refs($curated) as $node) {
            $by_ref[(int) $node['ref']] = [
                'ref' => (int) $node['ref'],
                'name' => i18n_get_translated((string) ($node['name'] ?? '')),
                'count' => 0,
            ];
        }
        $use_counts = get_nodes_use_count($curated);
        $out = [];
        foreach ($curated as $ref) {
            if (!isset($by_ref[$ref])) {
                continue;
            }
            $count = (int) ($use_counts[$ref] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $by_ref[$ref]['count'] = $count;
            $out[] = $by_ref[$ref];
        }
        return $out;
    }

    $field = (int) ($config['aria_home_featured_tags_field']
        ?? $GLOBALS['aria_home_featured_tags_field']
        ?? 73);
    return aria_home_facet_field_nodes($field, $fallback_limit, true);
}

/**
 * Nodes for a metadata field facet.
 * Only returns tags that are used on at least one resource, sorted by popularity.
 *
 * @return list<array{ref:int,name:string,count:int}>
 */
function aria_home_facet_field_nodes(int $field, int $limit = 16, bool $prefer_used = true): array
{
    if ($field <= 0 || $limit <= 0) {
        return [];
    }

    $nodes = get_nodes($field, null, false, null, null, '', true);
    if (!is_array($nodes) || $nodes === []) {
        return [];
    }

    $mapped = [];
    foreach ($nodes as $n) {
        $mapped[] = [
            'ref' => (int) ($n['ref'] ?? 0),
            'name' => i18n_get_translated((string) ($n['name'] ?? '')),
            'count' => (int) ($n['use_count'] ?? 0),
        ];
    }
    $mapped = array_values(array_filter(
        $mapped,
        static fn ($n) => $n['ref'] > 0 && $n['name'] !== ''
    ));

    // Home facets always hide unused tags; setup curator can pass prefer_used=false.
    if ($prefer_used) {
        $mapped = array_values(array_filter($mapped, static fn ($n) => $n['count'] > 0));
        usort($mapped, static fn ($a, $b) => $b['count'] <=> $a['count']);
    }

    return array_slice($mapped, 0, $limit);
}

/**
 * Enabled sidebar facet sections for the home page.
 * Empty sections (no used values / collections) are omitted.
 *
 * @return list<array{key:string,label:string,type:string,items:list<array>}>
 */
function aria_home_sidebar_sections(): array
{
    aria_home_ensure_setup();
    $config = aria_home_plugin_config();
    $sections = $config['aria_home_facet_sections'] ?? [];
    if (!is_array($sections)) {
        $sections = [];
    }

    $out = [];
    foreach ($sections as $key => $section) {
        if (!is_array($section) || empty($section['enabled'])) {
            continue;
        }
        $label = trim((string) ($section['label'] ?? $key));
        $limit = max(1, (int) ($section['limit'] ?? 16));
        $field = (int) ($section['field'] ?? 0);

        if ($key === 'collections' || ($field === 0 && $key === 'collections')) {
            $items = array_values(array_filter(
                aria_home_facet_collections($limit),
                static fn ($c) => (int) ($c['count'] ?? 0) > 0
            ));
            if ($items === []) {
                continue;
            }
            $out[] = [
                'key' => (string) $key,
                'label' => $label !== '' ? $label : 'Collections',
                'type' => 'collections',
                'items' => $items,
            ];
            continue;
        }

        if ($field <= 0) {
            continue;
        }

        $items = aria_home_facet_field_nodes($field, $limit, true);
        if ($items === []) {
            continue;
        }

        $out[] = [
            'key' => (string) $key,
            'label' => $label !== '' ? $label : (string) $key,
            'type' => 'tags',
            'field' => $field,
            'items' => $items,
        ];
    }

    return $out;
}

/**
 * All candidate nodes for the featured-tags curator UI.
 *
 * @return list<array{ref:int,name:string}>
 */
function aria_home_featured_tag_choices(int $field = 0): array
{
    if ($field <= 0) {
        $config = aria_home_plugin_config();
        $field = (int) ($config['aria_home_featured_tags_field']
            ?? $GLOBALS['aria_home_featured_tags_field']
            ?? 73);
    }
    return aria_home_facet_field_nodes($field, 200, false);
}

/**
 * @deprecated Use aria_home_featured_tags() or aria_home_sidebar_sections()
 * @return list<array{ref:int,name:string}>
 */
function aria_home_facet_tags(int $limit = 24): array
{
    return aria_home_featured_tags($limit);
}

/**
 * Preview URL for a resource row.
 * Image sequences always use the representative-frame poster (not the first still / video proxy).
 */
function aria_home_preview_url(array $resource): string
{
    $ref = (int) ($resource['ref'] ?? 0);
    if (
        $ref > 0
        && function_exists('image_sequence_is_sequence_resource')
        && image_sequence_is_sequence_resource($resource)
        && function_exists('image_sequence_representative_preview_url')
    ) {
        $seq_url = image_sequence_representative_preview_url($ref);
        if ($seq_url !== '') {
            return $seq_url;
        }
    }

    $access = get_resource_access($resource);
    $preview = get_resource_preview($resource, ['scr', 'pre', 'thm'], (int) $access, false);
    if (is_array($preview) && !empty($preview['url'])) {
        return (string) $preview['url'];
    }
    return '';
}

/**
 * Make a resource path/URL absolute when needed.
 */
function aria_home_absolute_url(string $url): string
{
    global $baseurl;
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, '://') !== false) {
        return $url;
    }
    return rtrim((string) $baseurl, '/') . '/' . ltrim($url, '/');
}

/**
 * Motion preview (mp4 proxy) URL for hero carousel, when available.
 * Covers standard videos and image-sequence proxies.
 */
function aria_home_video_preview_url(array $resource): string
{
    global $ffmpeg_preview_extension;

    $ref = (int) ($resource['ref'] ?? 0);
    if ($ref <= 0) {
        return '';
    }

    $ext = (string) ($ffmpeg_preview_extension ?: 'mp4');

    // Image sequence — use generated proxy when ready
    if (
        function_exists('image_sequence_is_sequence_resource')
        && image_sequence_is_sequence_resource($resource)
        && function_exists('image_sequence_get_data')
    ) {
        $data = image_sequence_get_data($ref);
        if (!is_array($data) || ($data['proxy_status'] ?? '') !== 'ready') {
            return '';
        }
        $path = get_resource_path($ref, true, 'pre', false, $ext);
        if (!is_string($path) || !is_file($path)) {
            return '';
        }
        $url = get_resource_path($ref, false, 'pre', false, $ext, true, 1, false, '', -1, true);
        return aria_home_absolute_url((string) $url);
    }

    // Standard video resources (or ffmpeg-supported originals)
    $is_video = aria_home_resource_kind($resource) === 'video'
        || (
            function_exists('image_sequence_is_video_resource')
            && image_sequence_is_video_resource($resource)
        );
    if (!$is_video) {
        return '';
    }

    $path = get_resource_path($ref, true, 'pre', false, $ext);
    if ((!is_string($path) || !is_file($path)) && function_exists('image_sequence_video_playback_path')) {
        $path = image_sequence_video_playback_path($resource);
    }
    if (!is_string($path) || $path === '' || !is_file($path)) {
        return '';
    }

    $url = get_resource_path($ref, false, 'pre', false, $ext, true, 1, false, '', -1, true);
    return aria_home_absolute_url((string) $url);
}

/**
 * In/out trim for hero video playback, as seconds.
 * Uses sequence table marks or video NLE metadata when available.
 *
 * @return array{in:float,out:float,fps:float}
 */
function aria_home_hero_trim(array $resource): array
{
    $ref = (int) ($resource['ref'] ?? 0);
    $trim = ['in' => 0.0, 'out' => 0.0, 'fps' => 0.0];
    if ($ref <= 0) {
        return $trim;
    }

    if (
        function_exists('image_sequence_is_sequence_resource')
        && image_sequence_is_sequence_resource($resource)
        && function_exists('image_sequence_get_data')
    ) {
        $data = image_sequence_get_data($ref);
        if (!is_array($data)) {
            return $trim;
        }
        $fps = (float) ($data['fps'] ?? 0);
        if ($fps <= 0) {
            $fps = 30.0;
        }
        $frame_count = max(1, (int) ($data['frame_count'] ?? 1));
        $in_frame = isset($data['in_frame']) && $data['in_frame'] !== null && $data['in_frame'] !== ''
            ? (int) $data['in_frame']
            : 0;
        $out_frame = isset($data['out_frame']) && $data['out_frame'] !== null && $data['out_frame'] !== ''
            ? (int) $data['out_frame']
            : ($frame_count - 1);
        if (function_exists('image_sequence_clamp_frame_index')) {
            $in_frame = image_sequence_clamp_frame_index($in_frame, $frame_count);
            $out_frame = image_sequence_clamp_frame_index($out_frame, $frame_count);
        }
        if ($out_frame < $in_frame) {
            $tmp = $in_frame;
            $in_frame = $out_frame;
            $out_frame = $tmp;
        }

        return [
            'in' => $in_frame / $fps,
            'out' => ($out_frame + 1) / $fps, // exclusive end of last included frame
            'fps' => $fps,
        ];
    }

    if (
        function_exists('image_sequence_is_video_resource')
        && image_sequence_is_video_resource($resource)
        && function_exists('image_sequence_video_get_marks')
    ) {
        $marks = image_sequence_video_get_marks($ref);
        $fps = (float) ($marks['fps'] ?? 0);
        if ($fps <= 0) {
            $fps = 25.0;
        }
        $in_frame = (int) ($marks['in_frame'] ?? 0);
        $out_frame = (int) ($marks['out_frame'] ?? 0);

        return [
            'in' => $in_frame / $fps,
            'out' => ($out_frame + 1) / $fps,
            'fps' => $fps,
        ];
    }

    return $trim;
}

/**
 * Hero media payload: still always, video when a motion proxy exists.
 *
 * @return array{still:string,video:string,kind:string,in:float,out:float}
 */
function aria_home_hero_media(array $resource): array
{
    $still = aria_home_preview_url($resource);
    $video = aria_home_video_preview_url($resource);
    $kind = aria_home_resource_kind($resource);
    if ($video !== '' && $kind === 'image') {
        // Sequences with a motion proxy read as video in the hero eyebrow
        $kind = 'video';
    }
    $trim = $video !== '' ? aria_home_hero_trim($resource) : ['in' => 0.0, 'out' => 0.0, 'fps' => 0.0];

    return [
        'still' => $still,
        'video' => $video,
        'kind' => $kind,
        'in' => (float) ($trim['in'] ?? 0),
        'out' => (float) ($trim['out'] ?? 0),
    ];
}

function aria_home_resource_title(array $resource): string
{
    global $view_title_field;
    $key = 'field' . (int) $view_title_field;
    $title = i18n_get_translated((string) ($resource[$key] ?? ''));
    if ($title === '') {
        $title = '#' . (int) ($resource['ref'] ?? 0);
    }
    return $title;
}

function aria_home_resource_kind(array $resource): string
{
    global $aria_home_video_restype, $ffmpeg_supported_extensions;
    $type = (int) ($resource['resource_type'] ?? 0);
    $ext = strtolower((string) ($resource['file_extension'] ?? ''));
    $video_type = (int) ($aria_home_video_restype ?: 3);
    if ($type === $video_type) {
        return 'video';
    }
    if (is_array($ffmpeg_supported_extensions ?? null) && in_array($ext, $ffmpeg_supported_extensions, true)) {
        return 'video';
    }
    return 'image';
}

function aria_home_caption(array $resource): string
{
    foreach (['field18', 'field25', 'field10'] as $key) {
        $val = trim(strip_tags(i18n_get_translated((string) ($resource[$key] ?? ''))));
        if ($val !== '') {
            return $val;
        }
    }
    return '';
}
