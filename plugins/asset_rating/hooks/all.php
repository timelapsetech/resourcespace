<?php

declare(strict_types=1);

include_once dirname(__DIR__) . '/include/asset_rating_functions.php';

function HookAsset_ratingAllInitialise(): void
{
    global $orderbyrating;

    if (PHP_SAPI !== 'cli') {
        asset_rating_ensure_setup();
    }

    // Allow search sort by curated resource.rating
    $orderbyrating = true;
}

function HookAsset_ratingAllAdditionalheaderjs(): void
{
    global $baseurl, $css_reload_key;
    $key = (int) $css_reload_key;
    $url = $baseurl . '/plugins/asset_rating';
    echo '<link rel="stylesheet" href="' . $url . '/css/style.css?css_reload_key=' . $key . '">';
    echo '<script src="' . $url . '/js/asset-rating.js?css_reload_key=' . $key . '" defer></script>';
}

/**
 * Curated stars on the resource view — placed under the player by JS.
 * Registered as an All hook so it works whenever view.php calls this hook.
 */
function HookAsset_ratingAllRenderbeforeresourcedetails(): void
{
    global $ref, $resource, $k, $internal_share_access, $pagename;

    // Only on the resource view page (full or CentralSpace AJAX)
    if (($pagename ?? '') !== 'view') {
        return;
    }

    $ref = (int) $ref;
    if ($ref <= 0) {
        return;
    }
    if (($k ?? '') !== '' && empty($internal_share_access)) {
        return;
    }

    $rating = asset_rating_get($ref, is_array($resource) ? $resource : null);
    $can_edit = get_edit_access($ref);
    echo '<div class="asset-rating-view-wrap">';
    asset_rating_render_control($ref, $rating, $can_edit, 'view');
    echo '</div>';
}

/**
 * Hide the raw radio field from the view metadata panel (stars replace it).
 *
 * @param list<array> $fields
 * @return list<array>|false
 */
function HookAsset_ratingAllModified_view_fields($ref = 0, $fields = [])
{
    global $pagename;
    if (($pagename ?? '') !== 'view' || !is_array($fields) || $fields === []) {
        return false;
    }
    $ids = asset_rating_ids();
    $field_ref = (int) ($ids['field'] ?? 0);
    if ($field_ref <= 0) {
        return false;
    }
    $out = [];
    foreach ($fields as $field) {
        if ((int) ($field['ref'] ?? 0) === $field_ref) {
            continue;
        }
        $out[] = $field;
    }
    return $out;
}

/**
 * Keep resource.rating in sync when Rating is saved via edit form.
 *
 * @param int|list<int> $resources
 */
function HookAsset_ratingAllAftersaveresourcedata($resources = null): void
{
    if ($resources === null) {
        return;
    }
    asset_rating_sync_from_field($resources);
}

/**
 * Search / home cards: show rating pill when rated.
 *
 * @param array $resource
 */
function HookAsset_ratingAllResourcecard_pills($resource = []): bool
{
    if (!is_array($resource)) {
        return false;
    }
    $ref = (int) ($resource['ref'] ?? 0);
    $rating = asset_rating_get($ref, $resource);
    if ($rating <= 0) {
        return false;
    }
    global $lang;
    $label = escape($lang['asset_rating'] ?? 'Rating');
    ?>
    <div class="resource-card-pill resource-card-rating" title="<?php echo $label; ?>: <?php echo (int) $rating; ?>/5">
        <span class="asset-rating-pill-stars" aria-hidden="true"><?php echo str_repeat('★', $rating); ?></span>
        <span class="asset-rating-pill-num"><?php echo (int) $rating; ?></span>
    </div>
    <?php
    return false;
}
