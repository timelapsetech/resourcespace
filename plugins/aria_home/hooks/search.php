<?php

declare(strict_types=1);

include_once dirname(__DIR__) . '/include/aria_home_functions.php';

/**
 * Preload featured refs before search cards render so thumbs can size them.
 */
function HookAria_homeSearchBeforesearchresults(): void
{
    global $result;

    if (!is_array($result) || $result === []) {
        return;
    }

    aria_home_preload_featured_refs($result);
}
