<?php
include_once __DIR__ . '/../include/openai_gpt_functions.php';

global $valid_ai_field_types;


/**
 * Add to array of field column data on metadata field editing page
 *
 * @param array     $fieldcolumns   Existing array of columns
 * 
 * @return array    Updated array of columns
 * 
 */
function HookOpenai_gptAllModifyresourcetypefieldcolumns($fieldcolumns)
    {
    global $lang, $valid_ai_field_types, $ref;
    
    $fielddata=get_resource_type_field($ref);
    if(in_array($fielddata["type"],$valid_ai_field_types))
        {
        $addcolumns = [
            'openai_gpt_prompt'        => array($lang['property-openai_gpt_prompt'],'',2,0),
            'openai_gpt_input_field'   => array($lang['property-openai_gpt_input_field'],'',0,0),
            ];    
        return array_merge($fieldcolumns,$addcolumns);
        }
    return false;
    }

/**
 * Alter rendering of the new columns on the metadata field editing page
 *
 * @param int           $ref            Ref of the metadata field being edited
 * @param string        $column         Name of table column for which input is being rendered
 * @param array         $column_detail  Array of metadata field rendering data from the edit page
 * @param array         $fielddata      Array of metadata field information from get_resource_type_field()
 * 
 * @return bool         Is standard display rendering being overridden?
 * 
 */
function HookOpenai_gptAdmin_resource_type_field_editAdmin_field_replace_question($ref,$column,$column_detail,$fielddata)
    {
    global $lang;
    if(!in_array($column,["openai_gpt_input_field","openai_gpt_prompt"]))
        {
        return false;
        }
    
    $currentvalue = $fielddata[$column];
    if($column=="openai_gpt_input_field")
        {
        $fields = get_resource_type_fields();
        ?>
        <div class="Question" >
            <label for="field_edit_<?php echo escape((string) $column); ?>"><?php echo escape((string) $column_detail[0]); ?></label>
            <select id="field_edit_<?php echo escape((string) $column); ?>" name="<?php echo escape((string) $column); ?>" class="stdwidth">
            <option value="" <?php if ($currentvalue == "") { echo "selected"; } ?>><?php echo escape($lang["select"]); ?></option>
            <option value="-1" <?php if ($currentvalue == "-1") { echo "selected"; } ?>><?php echo escape($lang["image"] . ": " . $lang["previewimage"]) ?></option>
            <?php
            foreach($fields as $field)
                {
                if($field["ref"]!=$ref) // Don't show itself as an option
                    {?>
                    <option value="<?php echo (int)$field["ref"]; ?>"<?php if ($currentvalue == $field["ref"]) { echo " selected"; } ?>><?php echo escape($lang["field"]. ": " . i18n_get_translated($field["title"]))  . "&nbsp;(" . (($field["name"]=="") ? "" : escape((string) $field["name"]))  . ")"; ?></option>
                    <?php
                    }
                }
            ?>              
            </select>
        </div>
        <?php
        return true;
        }
    elseif($column=="openai_gpt_prompt")
        {
        ?>
        <div class="Question" >
            <label for="field_edit_<?php echo escape((string) $column_detail[0]); ?>"><?php echo escape((string) $column_detail[0]); ?></label>
            <textarea class="stdwidth" rows="3" id="field_edit_<?php echo escape((string) $column_detail[0]); ?>" name="<?php echo escape((string) $column); ?>"><?php echo escape((string) $currentvalue); ?></textarea>
        </div>      
        <?php
        return true;
        }
    return false;
    }

/**
 * Hook into update_field() to process value changes
 *
 * @param int       $resource       Resource ID
 * @param int       $field          Metadata field ref
 * @param string    $value          New field value (comma separated for nodes)
 * @param string    $existing       Existing field value
 * @param array     $fieldinfo      Array of metadata field information from get_resource_type_field()
 * @param array     $newnodes       Array of new nodes that have been set
 * @param array     $newvalues      Array of new text values that have been set
 * 
 * @return bool
 * 
 */
function HookOpenai_gptAllUpdate_field($resource, $field, $value, $existing, $fieldinfo,$newnodes,$newvalues)
    {
    global $valid_ai_field_types, $gpt_fields_processed, $gpt_processing_ref;

    // Keep track of the resource currently being processed and reset fields if this changes
    if (!isset($gpt_processing_ref)) {
        $gpt_processing_ref = $resource;
    } elseif ($resource != $gpt_processing_ref) {
        $gpt_fields_processed = [];
        $gpt_processing_ref = $resource;
    }

    // Keep track of the fields that we have processed so that we can avoid infinite update_field loops
    if (!isset($gpt_fields_processed)) {
        $gpt_fields_processed = [];
    } elseif (in_array($field, $gpt_fields_processed)) {
        return;
    }

    $gpt_fields_processed[] = $field;

    // Is this field referenced by other fields?
    $targetfields = openai_gpt_get_dependent_fields($field);
    foreach ($targetfields as $targetfield) {
        // Create array of new string values that will be passed to the API
        $targetfield = get_resource_type_field($targetfield);
        $source_values = [];
        if (count($newvalues) > 0) {
            $source_values = $newvalues;
        } elseif (count($newnodes) > 0) {
            get_nodes_by_refs($newnodes);
            $source_values = array_column($newnodes,"name");            
        } else {
            $source_values[] = $value;
        }

        // Use this field's value to update the dependent field
        if (in_array($targetfield["type"],$valid_ai_field_types) && count($source_values) > 0) {
            openai_gpt_update_field($resource, $targetfield, $source_values);
        }
    }
    return false;
    }
    
/**
 *  Hook into save_resource_data() and save_resource_data_multi() to process value changes
 *
 * @param int|array     $r                      Resource ID or array of resource IDs
 * @param mixed         $all_nodes_to_add       Passed from hook, unused
 * @param mixed         $all_nodes_to_remove    Passed from hook, unused
 * @param mixed         $autosave_field         Passed from hook, unused
 * @param mixed         $fields                 Array of edited field data
 * @param mixed         $updated_resources      Array of resources & fields that have been updated
 *                                              with resources as the top level key and field IDs as subkeys
 * 
 * @return bool
 * 
 */
function HookOpenai_gptAllAftersaveresourcedata($r, $all_nodes_to_add, $all_nodes_to_remove,$autosave_field, $fields,$updated_resources)
    {
    if(!(is_int_loose($r) || is_array($r)))
        {
        return false;
        }

    // Manual edits of AI-managed fields auto-lock so force_overwrite cannot replace them.
    if (is_array($updated_resources) && count($updated_resources) > 0) {
        openai_gpt_auto_lock_manual_edits($updated_resources);
    }
    
    $refs = (is_array($r) ? $r : [$r]);
    debug("openai_gpt Aftersaveresourcedata - resources to update:  " . implode(",",$refs));
    $success=false;
    // Check if any configured fields have been edited
    foreach($fields as $field)
        {
        $targetfields = openai_gpt_get_dependent_fields($field["ref"]);
        foreach($targetfields as $targetfield)
            {
            $targetfield = get_resource_type_field($targetfield);
            debug("openai_gpt aftersaveresourcedata - processing field #" . $targetfield["ref"] . " (" . $targetfield["title"] . ")");
            foreach($refs as $ref)
                {
                // Has the value been updated?
                if(isset($updated_resources[$ref][$field["ref"]]) && count($updated_resources[$ref][$field["ref"]]) > 0)
                    {
                    if(count($updated_resources[$ref][$field["ref"]]) == 1 && trim($updated_resources[$ref][$field["ref"]][0]) == "")
                        {
                        if (trim($field["value"] ?? "") == "")
                            {
                                continue;
                            }
                        // Empty value - clear the target field
                        debug("openai_gpt - no value set for resource # " . $ref . ", field #" . $field["ref"] . " " . $field["name"] . ", clearing target field #" . $targetfield["ref"]);
                        $updated =  update_field($ref,$targetfield["ref"],"");
                        }
                    else
                        {
                        $updated = openai_gpt_update_field($ref,$targetfield,$updated_resources[$ref][$field["ref"]]);
                        }
                    if($updated)
                        {
                        $success=true;
                        }
                    }
                }
            }
        }
    return $success;
    }
 
/**
 *  Hook into image upload to process the image as GPT input
 * * 
 * @return bool Success if field is updated
 * 
 */
function HookOpenai_gptAllAfterpreviewcreation(int $ref, int $alternative, bool $generate_all = false): bool
    {    
    debug("openai_gpt after preview creation - resource: " . $ref . ", alternative: " . $alternative);
    if ($alternative>0)
        {
        return false;
        }

    $fields = get_resource_field_data($ref, false);
    $fields_with_values = array_filter($fields, function ($f) { return isset($f['value']) && $f['value'] != '';});
    $fields_with_values = array_column($fields_with_values, 'ref');

    $file = openai_gpt_resolve_image_path($ref);
    if ($file === '') {
        return false;
    }

    // Do any fields use image as input?
    $ai_gpt_image_fields = openai_gpt_get_dependent_fields(-1);
    $success = false;
                                    
    foreach($ai_gpt_image_fields as $ai_gpt_image_field)
        {
        $ai_gpt_image_field = get_resource_type_field($ai_gpt_image_field);
        // Don't update if not a valid field type
        if(!in_array($ai_gpt_image_field["type"],$GLOBALS["valid_ai_field_types"]))
            {
            continue;
            }

        // Only update this field if it is empty.
        if (in_array($ai_gpt_image_field['ref'], $fields_with_values))
            {
            continue;
            }

        $updated = openai_gpt_update_field($ref,$ai_gpt_image_field, [],$file);
        if (($updated[$ref] ?? $updated) === true) {
            $success = true;
            }
        }
    return $success;
    }


/**
 * Return total token usage for the past 30 days.
 *
 * @return  array   Array of data for processing in get_system_status().
 */
function HookOpenai_gptAllExtra_checks() : array
    {
    $message['openai_gpt'] = [
        'status' => 'OK',
        'info' => daily_stat_past_month_by_activity('OpenAI Token Usage')
        ];
    return $message;
    }

/**
 * Hook into offline jobs list to add custom job
 * 
 * @return array Array of existing job data with custom job added
 * 
 */
function HookOpenai_gptAllAddtriggerablejob(): array
{

    // Make sure this check takes place before $GLOBALS["hook_return_value"] can be unset by subsequent calls to hook()
    if (isset($GLOBALS["hook_return_value"]) && is_array($GLOBALS["hook_return_value"])) {
        // @see hook() for an explanation about the hook_return_value global
        $existing_scripts = $GLOBALS["hook_return_value"];
    } else {
        $existing_scripts = [];
    }

    $scripts = [
        0 => ['name' => 'OpenAI/Ollama metadata processing', 'lang_string' => 'openai_gpt', 'type' => 'group_start'],
        1 => ['name' => 'Process existing GPT fields', 'lang_string' => 'openai_gpt_process_existing', 'script_name' => 'process_gpt_existing', 'plugin' => 'openai_gpt'],
        2 => ['name' => 'OpenAI/Ollama metadata processing', 'type' => 'group_end'],
    ];

    return array_merge($existing_scripts, $scripts);
}

/**
 * Show AI-lock controls on the edit form for every field type the same way.
 * Uses afterfielddisplay so we receive the field explicitly (not via globals).
 *
 * @param int   $n     Field index on the edit form
 * @param array $field Field data from get_resource_field_data()
 */
function HookOpenai_gptAllAfterfielddisplay($n, $field)
{
    global $ref, $use, $lang, $multiple, $upload_review_mode;

    if (!empty($multiple) || !empty($upload_review_mode)) {
        return false;
    }

    if (!is_array($field) || !isset($field['ref'])) {
        return false;
    }

    // Prefer the resource being edited; $use can differ when copying-from.
    $resource = (int) ($ref ?? 0);
    if ($resource <= 0 && isset($use)) {
        $resource = (int) $use;
    }

    $field_ref = (int) $field['ref'];
    if ($resource <= 0 || $field_ref <= 0 || !openai_gpt_is_ai_managed_field($field_ref)) {
        return false;
    }

    if (!openai_gpt_field_is_locked($resource, $field_ref)) {
        return false;
    }

    openai_gpt_render_field_lock_ui($resource, $field_ref);
    return false;
}

/**
 * Also render inside the Question (before the input) so the control sits with the
 * field chrome for every type — same float:right pattern as upload lock buttons.
 */
function HookOpenai_gptAllAddfieldextras()
{
    global $field, $ref, $use, $multiple, $upload_review_mode;

    if (!empty($multiple) || !empty($upload_review_mode)) {
        return;
    }

    // Prefer the field passed into display_field via the edit.php loop.
    $current = $field ?? ($GLOBALS['field'] ?? null);
    if (!is_array($current) || !isset($current['ref'])) {
        return;
    }

    $resource = (int) ($ref ?? 0);
    if ($resource <= 0 && isset($use)) {
        $resource = (int) $use;
    }

    $field_ref = (int) $current['ref'];
    if ($resource <= 0 || $field_ref <= 0 || !openai_gpt_is_ai_managed_field($field_ref)) {
        return;
    }

    if (!openai_gpt_field_is_locked($resource, $field_ref)) {
        return;
    }

    // Avoid duplicating if afterfielddisplay will also run — only show the compact
    // in-field lock icon here; the text+link row is rendered after the question.
    global $lang;
    $locked_label = $lang['openai_gpt_field_locked'] ?? 'Protected from AI overwrite';
    $unlock_label = $lang['openai_gpt_unlock_field'] ?? 'Allow AI to update';
    ?>
    <button type="button"
        class="lock_icon openai_gpt_unlock_btn openai_gpt_ai_lock"
        data-field="<?php echo $field_ref; ?>"
        data-resource="<?php echo $resource; ?>"
        title="<?php echo escape($locked_label . ' — ' . $unlock_label); ?>"
        aria-label="<?php echo escape($locked_label . ' — ' . $unlock_label); ?>">
        <i class="icon-lock" aria-hidden="true"></i>
    </button>
    <?php
}