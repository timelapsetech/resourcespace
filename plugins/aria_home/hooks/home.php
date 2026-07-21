<?php

declare(strict_types=1);

include_once dirname(__DIR__) . '/include/aria_home_functions.php';
include_once dirname(__DIR__) . '/include/aria_home_render.php';

function HookAria_homeHomeHomebeforepanels(): void
{
    aria_home_ensure_setup();

    $kind = getval('aria_kind', 'all');
    if (!in_array($kind, ['all', 'image', 'video'], true)) {
        $kind = 'all';
    }
    $collection = (int) getval('aria_collection', 0);
    $tags_raw = trim((string) getval('aria_tags', ''));
    $active_tags = [];
    if ($tags_raw !== '') {
        foreach (explode(',', $tags_raw) as $t) {
            $t = (int) $t;
            if ($t > 0) {
                $active_tags[] = $t;
            }
        }
    }

    $search = trim((string) getval('aria_search', ''));

    aria_home_render_page($kind, $collection, $active_tags, $search);

    // Hide default home chrome when Aria home is active
    echo '<style>'
        . '#hero_banner,#HomePanelContainer,#HomeSiteTextPanel,#SlideshowContainer,.slide{display:none!important}'
        . 'body:has(#aria-home) #CentralSpaceContainer,body:has(#aria-home) #CentralSpace{'
        . 'display:block!important;width:100%!important;max-width:none!important;'
        . 'padding-top:0!important;padding-left:0!important;padding-right:0!important;'
        . 'margin-left:0!important;margin-right:0!important;overflow:visible!important}'
        . '</style>';
}
