<?php

declare(strict_types=1);

/**
 * Curated asset rating (0–5) — shared metadata field + resource.rating sync.
 */

/**
 * Ensure the Rating metadata field and 0–5 nodes exist.
 *
 * @return array{field:int,nodes:array<int,int>} field ref and map of rating value => node ref
 */
function asset_rating_ensure_setup(): array
{
    $config = get_plugin_config('asset_rating') ?: [];
    $changed = false;
    $field_ref = (int) ($config['asset_rating_field'] ?? 0);
    $shortname = (string) ($GLOBALS['asset_rating_shortname'] ?? 'asset_rating');

    if ($field_ref <= 0) {
        $existing = (int) ps_value(
            'SELECT ref value FROM resource_type_field WHERE name = ?',
            ['s', $shortname],
            0,
            'schema'
        );
        if ($existing > 0) {
            $field_ref = $existing;
        } else {
            $field_ref = (int) create_resource_type_field(
                'Rating',
                0,
                FIELD_TYPE_RADIO_BUTTONS,
                $shortname,
                true
            );
            if ($field_ref > 0) {
                // Keep near the top of metadata for easy editing
                ps_query(
                    'UPDATE resource_type_field SET order_by = 5, display_field = 1, advanced_search = 1, simple_search = 0 WHERE ref = ?',
                    ['i', $field_ref]
                );
                clear_query_cache('schema');
            }
        }
        if ($field_ref > 0) {
            $config['asset_rating_field'] = $field_ref;
            $changed = true;
        }
    }

    $nodes_map = [];
    if ($field_ref > 0) {
        $existing_nodes = get_nodes($field_ref) ?: [];
        foreach ($existing_nodes as $node) {
            $name = trim((string) ($node['name'] ?? ''));
            if ($name !== '' && ctype_digit($name) && (int) $name >= 0 && (int) $name <= 5) {
                $nodes_map[(int) $name] = (int) $node['ref'];
            }
        }
        for ($v = 0; $v <= 5; $v++) {
            if (isset($nodes_map[$v])) {
                continue;
            }
            $created = set_node(null, $field_ref, (string) $v, null, ($v + 1) * 10);
            if ($created) {
                $nodes_map[$v] = (int) $created;
                $changed = true;
            }
        }
    }

    if ($changed) {
        set_plugin_config('asset_rating', $config);
    }

    $GLOBALS['asset_rating_field'] = $field_ref;
    $GLOBALS['asset_rating_nodes'] = $nodes_map;

    return ['field' => $field_ref, 'nodes' => $nodes_map];
}

/**
 * @return array{field:int,nodes:array<int,int>}
 */
function asset_rating_ids(): array
{
    $field = (int) ($GLOBALS['asset_rating_field'] ?? 0);
    $nodes = $GLOBALS['asset_rating_nodes'] ?? null;
    if ($field > 0 && is_array($nodes) && $nodes !== []) {
        return ['field' => $field, 'nodes' => $nodes];
    }
    return asset_rating_ensure_setup();
}

/**
 * Current curated rating for a resource (0–5). Prefers resource.rating column.
 */
function asset_rating_get(int $ref, ?array $resource = null): int
{
    if ($ref <= 0) {
        return 0;
    }

    if (is_array($resource) && isset($resource['rating']) && $resource['rating'] !== '' && $resource['rating'] !== null) {
        return max(0, min(5, (int) $resource['rating']));
    }

    $row = get_resource_data($ref);
    if (is_array($row) && isset($row['rating']) && $row['rating'] !== '' && $row['rating'] !== null) {
        return max(0, min(5, (int) $row['rating']));
    }

    $ids = asset_rating_ids();
    if ($ids['field'] <= 0) {
        return 0;
    }
    $attached = get_resource_nodes($ref, $ids['field']);
    if (!is_array($attached) || $attached === []) {
        return 0;
    }
    $flip = array_flip($ids['nodes']);
    foreach ($attached as $node_ref) {
        $node_ref = (int) $node_ref;
        if (isset($flip[$node_ref])) {
            return (int) $flip[$node_ref];
        }
    }
    return 0;
}

/**
 * Set curated rating (0–5). Updates metadata field nodes + resource.rating.
 */
function asset_rating_set(int $ref, int $rating): bool
{
    if ($ref <= 0 || $rating < 0 || $rating > 5) {
        return false;
    }
    if (!get_edit_access($ref)) {
        return false;
    }

    $ids = asset_rating_ids();
    $field = $ids['field'];
    $node = (int) ($ids['nodes'][$rating] ?? 0);
    if ($field <= 0 || $node <= 0) {
        return false;
    }

    $current = get_resource_nodes($ref, $field);
    if (!is_array($current)) {
        $current = [];
    }
    $current = array_map('intval', $current);
    if ($current !== []) {
        delete_resource_nodes($ref, $current, false);
    }
    add_resource_nodes($ref, [$node], false, true);

    ps_query('UPDATE resource SET rating = ? WHERE ref = ?', ['i', $rating, 'i', $ref]);
    if (isset($GLOBALS['get_resource_data_cache'][$ref])) {
        unset($GLOBALS['get_resource_data_cache'][$ref]);
    }

    return true;
}

/**
 * After metadata save: keep resource.rating in sync with the Rating field.
 *
 * @param int|list<int> $resources
 */
function asset_rating_sync_from_field($resources): void
{
    $ids = asset_rating_ids();
    $field = $ids['field'];
    if ($field <= 0) {
        return;
    }

    $refs = is_array($resources) ? $resources : [$resources];
    foreach ($refs as $ref) {
        $ref = (int) $ref;
        if ($ref <= 0) {
            continue;
        }
        $attached = get_resource_nodes($ref, $field);
        $rating = 0;
        if (is_array($attached) && $attached !== []) {
            $flip = array_flip($ids['nodes']);
            foreach ($attached as $node_ref) {
                $node_ref = (int) $node_ref;
                if (isset($flip[$node_ref])) {
                    $rating = (int) $flip[$node_ref];
                    break;
                }
            }
        }
        ps_query('UPDATE resource SET rating = ? WHERE ref = ?', ['i', $rating, 'i', $ref]);
        if (isset($GLOBALS['get_resource_data_cache'][$ref])) {
            unset($GLOBALS['get_resource_data_cache'][$ref]);
        }
    }
}

/**
 * Render interactive / display star control markup.
 */
function asset_rating_render_control(int $ref, int $rating, bool $can_edit, string $context = 'view'): void
{
    global $lang, $baseurl, $usersession, $CSRF_token_identifier;

    $rating = max(0, min(5, $rating));
    $label = escape($lang['asset_rating'] ?? 'Rating');
    $help = escape($lang['asset_rating_help'] ?? '');
    $clear = escape($lang['asset_rating_clear'] ?? 'Clear rating');
    $save_url = escape($baseurl . '/plugins/asset_rating/pages/ajax/save_rating.php');
    $editable = $can_edit ? '1' : '0';
    $ctx = escape($context);
    $csrf_id = escape((string) ($CSRF_token_identifier ?? 'CSRFToken'));
    $csrf_token = '';
    if ($can_edit && !empty($usersession)) {
        $csrf_token = generateCSRFToken($usersession, 'asset_rating_' . $ref);
    }
    ?>
    <div class="asset-rating"
         data-ref="<?php echo (int) $ref; ?>"
         data-rating="<?php echo (int) $rating; ?>"
         data-editable="<?php echo $editable; ?>"
         data-save-url="<?php echo $save_url; ?>"
         data-csrf-identifier="<?php echo $csrf_id; ?>"
         data-csrf-token="<?php echo escape($csrf_token); ?>"
         data-context="<?php echo $ctx; ?>"
         role="group"
         aria-label="<?php echo $label; ?>">
        <span class="asset-rating-label"><?php echo $label; ?></span>
        <div class="asset-rating-stars">
            <?php for ($n = 1; $n <= 5; $n++) {
                $active = $n <= $rating ? ' is-active' : '';
                $title = $n . ' / 5';
                if ($can_edit) {
                    ?>
                    <button type="button"
                            class="asset-rating-star<?php echo $active; ?>"
                            data-value="<?php echo $n; ?>"
                            aria-label="<?php echo escape($title); ?>"
                            title="<?php echo escape($title); ?>">★</button>
                    <?php
                } else {
                    ?>
                    <span class="asset-rating-star<?php echo $active; ?>"
                          data-value="<?php echo $n; ?>"
                          aria-hidden="true">★</span>
                    <?php
                }
            } ?>
        </div>
        <span class="asset-rating-value" aria-live="polite"><?php echo (int) $rating; ?>/5</span>
        <?php if ($can_edit) { ?>
            <button type="button"
                    class="asset-rating-clear"
                    data-value="0"
                    title="<?php echo $clear; ?>"
                    aria-label="<?php echo $clear; ?>">×</button>
        <?php } ?>
        <?php if ($help !== '' && $context === 'view') { ?>
            <span class="asset-rating-help"><?php echo $help; ?></span>
        <?php } ?>
    </div>
    <?php
}
