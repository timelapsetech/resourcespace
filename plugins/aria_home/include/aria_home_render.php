<?php

declare(strict_types=1);

include_once dirname(__DIR__) . '/include/aria_home_functions.php';

/**
 * Render one asset card for the Aria home grid.
 * Uses the same .resource-card markup as search so chroma_theme styles apply.
 */
function aria_home_render_card(array $resource, bool $span = false): void
{
    global $baseurl_short, $lang, $k, $internal_share_access, $collection_block_restypes,
        $usercollection_resources, $display_resource_id_in_thumbnail;

    $ref = (int) ($resource['ref'] ?? 0);
    if ($ref <= 0) {
        return;
    }

    $title = aria_home_resource_title($resource);
    $preview = aria_home_preview_url($resource);
    $view_url = generateURL($baseurl_short . 'pages/view.php', ['ref' => $ref]);
    $access = (int) get_resource_access($resource);
    $resource_type = (int) ($resource['resource_type'] ?? 0);

    // Prefer the same preview sizes as xlthumbs search cards
    $resource['preview_extension'] = $resource['preview_extension'] ?? 'jpg';
    if (
        function_exists('image_sequence_is_sequence_resource')
        && image_sequence_is_sequence_resource($resource)
    ) {
        // Keep representative-frame JPEG posters for sequences
        $thumbnail = false;
        if ($preview !== '') {
            $thumbnail = ['url' => $preview, 'width' => 0, 'height' => 0];
        }
    } else {
        $thumbnail = get_resource_preview($resource, ['pre', 'thm', 'scr'], $access, false);
        if (is_array($thumbnail) && !empty($thumbnail['url'])) {
            $preview = (string) $thumbnail['url'];
        }
    }

    $classes = ['resource-card', 'xl'];
    if ($span) {
        $classes[] = 'chroma-span-2';
    }

    $block_types = is_array($collection_block_restypes ?? null) ? $collection_block_restypes : [];
    $in_collection = is_array($usercollection_resources ?? null)
        && in_array($ref, $usercollection_resources, false);
    $can_collect = !checkperm('b')
        && ((($k ?? '') === '') || !empty($internal_share_access))
        && !in_array($resource_type, $block_types, true);

    $types = get_resource_types('', true) ?: [];
    $filetype_label = hook('resourcecard_filetype_label', '', [$resource]);
    if ($filetype_label === false) {
        $ext = strtoupper((string) ($resource['file_extension'] ?? ''));
        $filetype_label = $ext !== '' ? $ext : '';
    }
    ?>
    <div class="<?php echo escape(implode(' ', $classes)); ?>" id="ResourceShell<?php echo $ref; ?>">
        <div class="resource-card-action-bar"></div>

        <a class="resource-card-image xl"
           href="<?php echo escape($view_url); ?>"
           onclick="return CentralSpaceLoad(this, true);"
           title="<?php echo escape($title); ?>">
            <?php
            if ($preview !== '') {
                // Always lazy-load grid thumbs — eager render_resource_image()
                // floods the server with parallel download.php requests on home.
                ?>
                <div class="ImageColourWrapper">
                    <img src="<?php echo escape($preview); ?>"
                         alt="<?php echo escape($title); ?>"
                         loading="lazy"
                         decoding="async">
                </div>
                <?php
            } else {
                echo get_nopreview_html(
                    (string) ($resource['file_extension'] ?? ''),
                    $resource_type
                );
            }
            ?>
        </a>

        <div class="resource-card-content">
            <div class="resource-card-content-top">
                <div class="resource-card-title"
                     title="<?php echo escape($title); ?>">
                    <a href="<?php echo escape($view_url); ?>"
                       onclick="return CentralSpaceLoad(this, true);">
                        <?php echo escape(tidy_trim($title, 80)); ?>
                    </a>
                </div>
            </div>
            <div class="resource-card-content-bottom">
                <div class="resource-card-pill-bar">
                    <?php if (!empty($display_resource_id_in_thumbnail) && $ref > 0) { ?>
                        <div class="resource-card-pill resource-card-id">
                            <span># <?php echo $ref; ?></span>
                        </div>
                    <?php } ?>
                    <?php hook('resourcecard_pills', '', [$resource]); ?>
                </div>
                <div class="resource-card-type-bar">
                    <div class="resource-card-type">
                        <?php
                        foreach ($types as $type) {
                            if ((int) ($type['ref'] ?? 0) === $resource_type && !empty($type['icon'])) {
                                echo '<i title="' . escape((string) $type['name']) . '" class="icon-'
                                    . escape((string) $type['icon']) . '"></i>';
                            }
                        }
                        if ($filetype_label !== '' && $filetype_label !== false) {
                            echo '<span>' . escape((string) $filetype_label) . '</span>';
                        }
                        ?>
                    </div>
                    <div class="resource-card-tools">
                        <?php
                        if ($can_collect) {
                            $remove_class = 'resource-card-add-remove icon-minus';
                            if (!$in_collection) {
                                $remove_class .= ' DisplayNone';
                            }
                            echo remove_from_collection_link(
                                $ref,
                                $remove_class,
                                'toggle_addremove_to_collection_icon(this);',
                                0,
                                $title
                            ) . '</a>';

                            $add_class = 'resource-card-add-remove icon-plus';
                            if ($in_collection) {
                                $add_class .= ' DisplayNone';
                            }
                            echo add_to_collection_link(
                                $ref,
                                'toggle_addremove_to_collection_icon(this);',
                                '',
                                $add_class,
                                $title
                            ) . '</a>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Full Aria home layout.
 *
 * @param list<int> $active_tags
 */
function aria_home_render_page(
    string $kind = 'all',
    int $collection = 0,
    array $active_tags = []
): void {
    global $baseurl, $baseurl_short, $lang, $aria_home_per_page, $aria_home_hero_limit,
        $aria_home_hero_interval_ms;

    $per_page = (int) ($aria_home_per_page ?: 24);
    $hero_limit = max(1, (int) ($aria_home_hero_limit ?: 8));
    $hero_interval = max(3000, (int) ($aria_home_hero_interval_ms ?: 7000));
    $featured_list = aria_home_featured_resources($hero_limit);
    $browse = aria_home_browse($kind, $collection, $active_tags, 0, $per_page);
    $featured_tags = aria_home_featured_tags();
    $facet_sections = aria_home_sidebar_sections();
    $ids = aria_home_featured_ids();

    $ajax_url = $baseurl . '/plugins/aria_home/pages/ajax_browse.php';
    $carousel = count($featured_list) > 1;
    $shown = count($browse['data']);
    $has_more = $shown < (int) $browse['total'];
    ?>
    <div id="aria-home"
         class="aria-home"
         data-ajax="<?php echo escape($ajax_url); ?>"
         data-featured-field="<?php echo (int) $ids['field']; ?>"
         data-kind="<?php echo escape($kind); ?>"
         data-collection="<?php echo (int) $collection; ?>"
         data-tags="<?php echo escape(implode(',', $active_tags)); ?>"
         data-offset="<?php echo (int) $shown; ?>"
         data-per-page="<?php echo (int) $per_page; ?>"
         data-total="<?php echo (int) $browse['total']; ?>">

        <?php if ($featured_list !== []) { ?>
            <section class="aria-hero<?php echo $carousel ? ' aria-hero--carousel' : ''; ?>"
                     data-hero-carousel="1"
                     data-interval="<?php echo (int) $hero_interval; ?>"
                     <?php if ($carousel) { ?>
                         aria-roledescription="carousel"
                         aria-label="<?php echo escape($lang['aria_home_featured'] ?? 'Featured'); ?>"
                     <?php } ?>>
                <div class="aria-hero-slides">
                    <?php foreach ($featured_list as $i => $featured) {
                        $ftitle = aria_home_resource_title($featured);
                        $media = aria_home_hero_media($featured);
                        $fpreview = $media['still'];
                        $fvideo = $media['video'];
                        $fkind = $media['kind'];
                        $fin = (float) ($media['in'] ?? 0);
                        $fout = (float) ($media['out'] ?? 0);
                        $fcaption = aria_home_caption($featured);
                        $furl = generateURL($baseurl_short . 'pages/view.php', ['ref' => (int) $featured['ref']]);
                        $active = $i === 0;
                        $has_video = $fvideo !== '';
                        ?>
                        <article class="aria-hero-slide<?php echo $active ? ' is-active' : ''; ?><?php echo $has_video ? ' aria-hero-slide--motion' : ''; ?>"
                                 data-slide="<?php echo (int) $i; ?>"
                                 <?php echo $has_video ? 'data-has-video="1"' : ''; ?>
                                 <?php echo $active ? '' : 'aria-hidden="true"'; ?>>
                            <?php if ($fpreview !== '') { ?>
                                <img class="aria-hero-image"
                                     <?php if ($i === 0) { ?>
                                         src="<?php echo escape($fpreview); ?>"
                                     <?php } else { ?>
                                         data-src="<?php echo escape($fpreview); ?>"
                                         loading="lazy"
                                     <?php } ?>
                                     alt="<?php echo escape($ftitle); ?>">
                            <?php } ?>
                            <?php if ($has_video) { ?>
                                <video class="aria-hero-video"
                                       muted
                                       playsinline
                                       preload="none"
                                       poster="<?php echo escape($fpreview); ?>"
                                       data-src="<?php echo escape($fvideo); ?>"
                                       data-in-time="<?php echo escape(sprintf('%.4F', $fin)); ?>"
                                       data-out-time="<?php echo escape(sprintf('%.4F', $fout)); ?>"
                                       aria-hidden="true"></video>
                            <?php } ?>
                            <div class="aria-hero-aurora" aria-hidden="true"></div>
                            <div class="aria-hero-fade" aria-hidden="true"></div>
                            <div class="aria-hero-content">
                                <div class="aria-hero-eyebrow">
                                    <?php echo escape($lang['aria_home_featured'] ?? 'Featured'); ?>
                                    · <?php echo escape(strtoupper($fkind)); ?>
                                </div>
                                <h1 class="aria-hero-title"><?php echo escape($ftitle); ?></h1>
                                <?php if ($fcaption !== '') { ?>
                                    <p class="aria-hero-desc"><?php echo escape($fcaption); ?></p>
                                <?php } ?>
                                <div class="aria-hero-actions">
                                    <a class="aria-btn aria-btn-primary"
                                       href="<?php echo escape($furl); ?>"
                                       onclick="return CentralSpaceLoad(this, true);"
                                       tabindex="<?php echo $active ? '0' : '-1'; ?>">
                                        <?php echo escape($lang['aria_home_open_asset'] ?? 'Open asset'); ?>
                                    </a>
                                    <a class="aria-btn aria-btn-ghost"
                                       href="<?php echo escape($baseurl_short); ?>pages/search.php?search=&resetrestypes=yes"
                                       onclick="return CentralSpaceLoad(this, true);"
                                       tabindex="<?php echo $active ? '0' : '-1'; ?>">
                                        <?php echo escape($lang['aria_home_browse_library'] ?? 'Browse library'); ?>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php } ?>
                </div>
                <?php if ($carousel) { ?>
                    <div class="aria-hero-dots" role="tablist" aria-label="<?php echo escape($lang['aria_home_featured'] ?? 'Featured'); ?>">
                        <?php foreach ($featured_list as $i => $featured) { ?>
                            <button type="button"
                                    class="aria-hero-dot<?php echo $i === 0 ? ' is-active' : ''; ?>"
                                    role="tab"
                                    aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                                    aria-label="<?php echo escape(($lang['aria_home_featured'] ?? 'Featured') . ' ' . ($i + 1)); ?>"
                                    data-slide="<?php echo (int) $i; ?>"></button>
                        <?php } ?>
                    </div>
                <?php } ?>
            </section>
        <?php } ?>

        <section class="aria-toolbar-wrap">
            <div class="aria-toolbar">
                <div class="aria-kind-toggle" role="group" aria-label="Asset kind">
                    <?php foreach (['all' => $lang['aria_home_all'] ?? 'All', 'image' => $lang['aria_home_image'] ?? 'Image', 'video' => $lang['aria_home_video'] ?? 'Video'] as $k => $label) { ?>
                        <button type="button"
                                class="aria-kind-btn<?php echo $kind === $k ? ' is-active' : ''; ?>"
                                data-kind="<?php echo escape($k); ?>">
                            <?php echo escape($label); ?>
                        </button>
                    <?php } ?>
                </div>

                <div class="aria-category-pills" aria-label="<?php echo escape($lang['aria_home_featured_tags'] ?? 'Featured tags'); ?>">
                    <?php
                    foreach ($featured_tags as $tag) {
                        $active = in_array((int) $tag['ref'], $active_tags, true);
                        ?>
                        <button type="button"
                                class="aria-pill<?php echo $active ? ' is-active' : ''; ?>"
                                data-tag="<?php echo (int) $tag['ref']; ?>">
                            <span class="aria-pill-label"><?php echo escape($tag['name']); ?></span>
                            <span class="aria-pill-count"><?php echo (int) ($tag['count'] ?? 0); ?></span>
                        </button>
                    <?php } ?>
                    <?php if ($featured_tags === []) { ?>
                        <span class="aria-pill-empty"><?php echo escape($lang['aria_home_no_featured_tags'] ?? 'Curate featured tags in plugin setup'); ?></span>
                    <?php } ?>
                </div>

                <div class="aria-toolbar-meta">
                    <span class="aria-asset-count">
                        <span id="aria-asset-count-num"><?php echo (int) $browse['total']; ?></span>
                        <?php echo escape($lang['aria_home_assets'] ?? 'assets'); ?>
                    </span>
                </div>
            </div>
        </section>

        <section class="aria-body">
            <aside class="aria-facets">
                <?php foreach ($facet_sections as $section) {
                    $stype = (string) ($section['type'] ?? 'tags');
                    ?>
                    <div class="aria-facet-group" data-facet="<?php echo escape((string) $section['key']); ?>">
                        <div class="aria-facet-label"><?php echo escape((string) $section['label']); ?></div>

                        <?php if ($stype === 'collections') { ?>
                            <button type="button"
                                    class="aria-facet-row<?php echo $collection === 0 ? ' is-active' : ''; ?>"
                                    data-collection="0">
                                <span class="aria-facet-dot" aria-hidden="true"></span>
                                <span class="aria-facet-name"><?php echo escape($lang['aria_home_all'] ?? 'All'); ?></span>
                            </button>
                            <?php foreach ($section['items'] as $col) { ?>
                                <button type="button"
                                        class="aria-facet-row<?php echo $collection === (int) $col['ref'] ? ' is-active' : ''; ?>"
                                        data-collection="<?php echo (int) $col['ref']; ?>">
                                    <span class="aria-facet-dot" aria-hidden="true"></span>
                                    <span class="aria-facet-name"><?php echo escape($col['name']); ?></span>
                                    <span class="aria-facet-count"><?php echo (int) $col['count']; ?></span>
                                </button>
                            <?php } ?>
                            <?php if ($section['items'] === []) { ?>
                                <p class="aria-facet-empty"><?php echo escape($lang['aria_home_no_collections'] ?? 'Create a collection to filter here.'); ?></p>
                            <?php } ?>
                        <?php } else { ?>
                            <div class="aria-tag-cloud">
                                <?php foreach ($section['items'] as $tag) {
                                    $active = in_array((int) $tag['ref'], $active_tags, true);
                                    ?>
                                    <button type="button"
                                            class="aria-tag<?php echo $active ? ' is-active' : ''; ?>"
                                            data-tag="<?php echo (int) $tag['ref']; ?>">
                                        <span class="aria-tag-label"><?php echo escape($tag['name']); ?></span>
                                        <span class="aria-tag-count"><?php echo (int) ($tag['count'] ?? 0); ?></span>
                                    </button>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </aside>

            <div class="aria-grid-wrap">
                <div id="aria-grid" class="aria-grid">
                    <?php
                    if ($browse['data'] === []) {
                        echo '<p class="aria-empty">' . escape($lang['aria_home_no_results'] ?? 'No assets match these filters.') . '</p>';
                    } else {
                        foreach ($browse['data'] as $i => $resource) {
                            aria_home_render_card($resource, $i === 0 && count($browse['data']) > 3);
                        }
                    }
                    ?>
                </div>
                <div class="aria-pager">
                    <p class="aria-pager-status" id="aria-pager-status">
                        <?php
                        echo escape($lang['aria_home_showing'] ?? 'Showing')
                            . ' <span id="aria-shown-count">' . (int) $shown . '</span> '
                            . escape($lang['aria_home_of'] ?? 'of')
                            . ' <span class="aria-total-count">' . (int) $browse['total'] . '</span>';
                        ?>
                    </p>
                    <button type="button"
                            id="aria-load-more"
                            class="aria-btn aria-btn-ghost aria-load-more<?php echo $has_more ? '' : ' is-hidden'; ?>">
                        <?php echo escape($lang['aria_home_load_more'] ?? 'Load more'); ?>
                    </button>
                </div>
            </div>
        </section>
    </div>
    <?php
}

/**
 * Render grid HTML fragment for AJAX.
 *
 * @param list<array> $resources
 */
function aria_home_render_grid_html(array $resources): string
{
    ob_start();
    if ($resources === []) {
        global $lang;
        echo '<p class="aria-empty">' . escape($lang['aria_home_no_results'] ?? 'No assets match these filters.') . '</p>';
    } else {
        foreach ($resources as $i => $resource) {
            aria_home_render_card($resource, $i === 0 && count($resources) > 3);
        }
    }
    return (string) ob_get_clean();
}
