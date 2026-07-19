<?php

declare(strict_types=1);

include_once dirname(__DIR__) . '/include/aria_home_functions.php';

function HookAria_homeAllInitialise(): void
{
    // Ensure featured field exists early for edit forms
    if (PHP_SAPI !== 'cli') {
        aria_home_ensure_setup();
    }
}

function HookAria_homeAllAdditionalheaderjs(): void
{
    global $baseurl, $css_reload_key;
    $plugin_url = $baseurl . '/plugins/aria_home';
    $key = (int) $css_reload_key;
    echo '<link rel="stylesheet" href="' . $plugin_url . '/css/style.css?css_reload_key=' . $key . '">';
    echo '<script src="' . $plugin_url . '/js/aria-home.js?css_reload_key=' . $key . '" defer></script>';
}
