<?php

/**
 * Show a lock indicator on the view page for AI-protected fields.
 *
 * @param array $field Field data
 * @param string $value Already-escaped HTML value
 * @return string|false
 */
function HookOpenai_gptViewValue_mod_after_highlight($field, $value)
{
    global $ref, $lang;

    if (!is_array($field) || !isset($field['ref'])) {
        return false;
    }

    $resource = (int) ($ref ?? 0);
    $field_ref = (int) $field['ref'];
    if ($resource <= 0 || !openai_gpt_field_is_locked($resource, $field_ref)) {
        return false;
    }

    $title = $lang['openai_gpt_field_locked'] ?? 'Protected from AI overwrite';
    return $value
        . ' <i class="icon-lock openai_gpt_view_lock" title="' . escape($title) . '" aria-label="' . escape($title) . '"></i>';
}
