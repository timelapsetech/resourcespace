<?php

include_once __DIR__ . '/../include/edited_flag_functions.php';

/**
 * Protect the field config from garbage collection while the plugin is active.
 */
function HookEdited_flagAllInitialise()
{
    global $edited_flag_fieldvars;

    if (function_exists('config_register_core_fieldvars') && is_array($edited_flag_fieldvars ?? null)) {
        config_register_core_fieldvars('Edited Flag plugin', $edited_flag_fieldvars);
    }
}

/**
 * Create the "Edited" field when the plugin is activated.
 *
 * @param string $name
 */
function HookEdited_flagAllAfter_activate_plugin($name = '')
{
    if ((string) $name !== 'edited_flag') {
        return false;
    }
    edited_flag_ensure_setup();

    return false;
}

/**
 * Mark a resource as manually edited after its metadata is saved.
 *
 * Fired by save_resource_data() / save_resource_data_multi() (the edit page and
 * batch edit). Automated updates (ingest, AI enrichment) call update_field()
 * directly and do not trigger this hook, so they never set the flag.
 *
 * @param int|list<int> $ref            Resource ref, or list of refs (batch edit).
 * @param array         $nodes_to_add
 * @param array         $nodes_to_remove
 * @param mixed         $autosave_field
 * @param array         $fields
 * @param array         $updated_resources
 *
 * @return bool
 */
function HookEdited_flagAllAftersaveresourcedata(
    $ref = 0,
    $nodes_to_add = [],
    $nodes_to_remove = [],
    $autosave_field = '',
    $fields = [],
    $updated_resources = []
) {
    edited_flag_mark($ref);

    return false;
}
