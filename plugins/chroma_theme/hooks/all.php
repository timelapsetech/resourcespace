<?php

declare(strict_types=1);

/**
 * Chroma Theme — image-forward cinematic UI for ResourceSpace.
 * Visual language inspired by Aria / chroma-canvas-repo.
 */

function HookChroma_themeAllAdditionalheaderjs(): void
{
    global $baseurl, $css_reload_key;

    $plugin_url = "{$baseurl}/plugins/chroma_theme";
    $key = (int) $css_reload_key;

    echo <<<HTML
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="{$plugin_url}/js/chroma-theme.js?css_reload_key={$key}" defer></script>
HTML;
}

function HookChroma_themeAllHeadertop(): void
{
    // Body class is applied via JS; this hook keeps a noscript-friendly marker in the DOM.
    echo '<div id="chroma-theme-root" hidden aria-hidden="true"></div>';
}
