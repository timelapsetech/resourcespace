<?php

/**
 * Ensure the global "Edited" metadata field exists.
 *
 * @return int Field ref (0 on failure).
 */
function edited_flag_ensure_setup(): int
{
    global $edited_flag_field, $edited_flag_shortname, $edited_flag_title;

    $shortname = (string) ($edited_flag_shortname ?: 'edited');
    $title = (string) ($edited_flag_title ?: 'Edited');

    $ref = edited_flag_field_ref();
    if ($ref > 0) {
        $edited_flag_field = $ref;
        return $ref;
    }

    $ref = (int) create_resource_type_field(
        $title,
        0,
        FIELD_TYPE_TEXT_BOX_SINGLE_LINE,
        $shortname,
        true
    );
    if ($ref <= 0) {
        return 0;
    }

    // Global so it applies to every resource type ("any record").
    ps_query('UPDATE resource_type_field SET global = 1 WHERE ref = ?', ['i', $ref], 'schema');
    clear_query_cache('schema');

    $edited_flag_field = $ref;

    $config = get_plugin_config('edited_flag') ?: [];
    if (!is_array($config)) {
        $config = [];
    }
    $config['edited_flag_field'] = $ref;
    set_plugin_config('edited_flag', $config);

    return $ref;
}

/**
 * Resolve the "Edited" field ref by short name (stable across installs).
 */
function edited_flag_field_ref(): int
{
    global $edited_flag_shortname;

    $shortname = (string) ($edited_flag_shortname ?: 'edited');

    return (int) ps_value(
        'SELECT ref value FROM resource_type_field WHERE name = ?',
        ['s', $shortname],
        0,
        'schema'
    );
}

/**
 * Flag one or more resources as manually edited (set the field to "Yes").
 *
 * @param int|list<int> $refs
 */
function edited_flag_mark($refs): void
{
    global $edited_flag_value;

    $field = edited_flag_field_ref();
    if ($field <= 0) {
        $field = edited_flag_ensure_setup();
    }
    if ($field <= 0) {
        return;
    }

    $value = (string) ($edited_flag_value ?: 'Yes');
    $errors = [];

    $list = is_array($refs) ? $refs : [$refs];
    foreach ($list as $ref) {
        $ref = (int) $ref;
        // Only positive refs: skip the transient negative ref used for upload defaults.
        if ($ref <= 0) {
            continue;
        }

        // Skip when already flagged to avoid redundant writes / log noise.
        if (trim((string) get_data_by_field($ref, $field)) === $value) {
            continue;
        }

        update_field($ref, $field, $value, $errors, false);
    }
}
