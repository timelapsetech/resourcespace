<?php

include_once __DIR__ . '/../include/image_sequence_functions.php';

function HookImage_sequenceAllInitialise()
{
    global $image_sequence_fieldvars;

    config_register_core_fieldvars('Image Sequence plugin', $image_sequence_fieldvars);
}

/**
 * Load Omakase Player (CDN) + import map for Image Sequence frame picking.
 */
function HookImage_sequenceAllAdditionalheaderjs()
{
    global $baseurl_short, $css_reload_key;

    $omakase_ver = '1.1.0';
    $hls_ver = '1.6.15';
    $omakase_css = 'https://cdn.jsdelivr.net/npm/@byomakase/omakase-player@'
        . $omakase_ver . '/dist/omakase-player.css';
    $omakase_js = 'https://cdn.jsdelivr.net/npm/@byomakase/omakase-player@'
        . $omakase_ver . '/dist/omakase-player.es.js';
    $hls_js = 'https://cdn.jsdelivr.net/npm/hls.js@' . $hls_ver . '/dist/hls.mjs';
    $picker_js = $baseurl_short . 'plugins/image_sequence/js/omakase_frame_picker.js?css_reload_key='
        . urlencode((string) $css_reload_key);
    ?>
    <link rel="stylesheet" href="<?php echo escape($omakase_css); ?>" />
    <script type="importmap">
    {
      "imports": {
        "hls.js": <?php echo json_encode($hls_js); ?>,
        "@byomakase/omakase-player": <?php echo json_encode($omakase_js); ?>
      }
    }
    </script>
    <script type="module" src="<?php echo escape($picker_js); ?>"></script>
    <?php
}

/**
 * Create resource type + fields when the plugin is activated.
 */
function HookImage_sequenceAllAfter_activate_plugin($name = '')
{
    if ((string) $name !== 'image_sequence') {
        return false;
    }
    image_sequence_ensure_setup();

    return false;
}

/**
 * Skip StaticSync import for frames already claimed by an image sequence (or extras).
 *
 * @param string $shortpath Relative path under $syncdir
 * @param string $fullpath Absolute path
 */
function HookImage_sequenceAllStaticsync_skip_file(string $shortpath = '', string $fullpath = ''): bool
{
    if ($shortpath === '' && $fullpath !== '') {
        global $syncdir;
        $shortpath = ltrim(str_replace((string) $syncdir . '/', '', str_replace('\\', '/', $fullpath)), '/');
    }

    return $shortpath !== '' && image_sequence_should_skip_staticsync_path($shortpath);
}

/**
 * Prefer the representative still when openai_gpt needs an image for this resource.
 *
 * @param int $ref Resource ID
 *
 * @return string|false Absolute path, or false to fall back to standard preview
 */
function HookImage_sequenceAllOpenai_gpt_image_path($ref = 0)
{
    $ref = (int) $ref;
    if ($ref <= 0 || image_sequence_get_data($ref) === null) {
        return false;
    }

    $path = image_sequence_get_representative_still_path($ref);

    return $path !== '' ? $path : false;
}

/**
 * Include sequence code in search result joins so cards can show it without extra queries.
 *
 * @return list<int>|false
 */
function HookImage_sequenceAllAdditionaljoins()
{
    global $image_sequence_seqcode_field;

    if ((int) $image_sequence_seqcode_field > 0) {
        return [(int) $image_sequence_seqcode_field];
    }

    return false;
}

/**
 * Search / collection cards: show "Sequence" instead of the stored JSON extension.
 *
 * @param array $resource Search-result row
 *
 * @return string|false
 */
function HookImage_sequenceAllResourcecard_filetype_label($resource = [])
{
    global $lang;

    if (!is_array($resource) || !image_sequence_is_sequence_resource($resource)) {
        return false;
    }

    return (string) ($lang['image_sequence_card_filetype'] ?? 'Sequence');
}

/**
 * Search cards: sequence-code pill beside status / ID.
 *
 * @param array $resource Search-result row
 */
function HookImage_sequenceAllResourcecard_pills($resource = [])
{
    global $lang;

    if (!is_array($resource)) {
        return false;
    }

    $code = image_sequence_get_card_sequence_code($resource);
    if ($code === '') {
        return false;
    }

    $title = (string) ($lang['image_sequence_card_seqcode_title'] ?? 'Sequence code');
    ?>
    <div class="resource-card-pill resource-card-seqcode" title="<?php echo escape($title); ?>">
        <span><?php echo escape($code); ?></span>
    </div>
    <?php

    return false;
}

/**
 * After a file upload succeeds: if the resource is Image Sequence type and the
 * file is a ZIP, expand under filestore staging and re-ingest with cadence split.
 *
 * @param int $resource_ref Passed as the value of resource_ref from upload_file hook
 */
function HookImage_sequenceAllUploadfilesuccess($resource_ref = 0)
{
    global $image_sequence_restype;

    if ((int) $image_sequence_restype <= 0) {
        return false;
    }

    $ref = (int) $resource_ref;
    if ($ref <= 0) {
        return false;
    }

    $resource = get_resource_data($ref);
    if (!is_array($resource) || (int) $resource['resource_type'] !== (int) $image_sequence_restype) {
        return false;
    }

    if (image_sequence_get_data($ref) !== null) {
        return false;
    }

    $ext = strtolower((string) ($resource['file_extension'] ?? ''));
    $path = get_resource_path($ref, true, '', false, $ext);
    if ($ext !== 'zip' || !is_file($path)) {
        return false;
    }

    $result = image_sequence_ingest_upload_paths([$path], [
        'created_by' => (int) ($resource['created_by'] ?? 0),
    ]);
    // Only remove the placeholder ZIP resource if at least one sequence was created.
    if (!empty($result['sequences'])) {
        delete_resource($ref);
    }

    return false;
}

function HookImage_sequenceAllBeforedeleteresourcefromdb($ref)
{
    image_sequence_cleanup_resource((int) $ref);
}

/**
 * Soft-delete moves archive state first; clear is_transcoding so a later
 * permanent delete is not refused by core.
 *
 * @param array|int $resource
 * @param int       $archive
 */
function HookImage_sequenceAllAfter_update_archive_status($resource = [], $archive = 0)
{
    global $resource_deletion_state, $image_sequence_restype;

    if (!isset($resource_deletion_state) || (int) $archive !== (int) $resource_deletion_state) {
        return;
    }
    if ((int) $image_sequence_restype <= 0) {
        return;
    }

    $refs = is_array($resource) ? $resource : [$resource];
    foreach ($refs as $ref) {
        $ref = (int) $ref;
        if ($ref <= 0) {
            continue;
        }
        $data = get_resource_data($ref);
        if (!is_array($data) || (int) $data['resource_type'] !== (int) $image_sequence_restype) {
            continue;
        }
        image_sequence_clear_transcoding_lock($ref);
    }
}

/**
 * Register batch proxy rebuild in Admin → Manage jobs / Offline jobs.
 *
 * @return list<array<string, mixed>>
 */
function HookImage_sequenceAllAddtriggerablejob(): array
{
    if (isset($GLOBALS['hook_return_value']) && is_array($GLOBALS['hook_return_value'])) {
        $existing_scripts = $GLOBALS['hook_return_value'];
    } else {
        $existing_scripts = [];
    }

    $scripts = [
        ['name' => 'Image Sequence', 'lang_string' => 'image_sequence_section', 'type' => 'group_start'],
        [
            'name' => 'Rebuild pending proxies',
            'lang_string' => 'image_sequence_job_rebuild_proxies',
            'script_name' => 'process_pending_proxies',
            'plugin' => 'image_sequence',
        ],
        ['name' => 'Image Sequence', 'type' => 'group_end'],
    ];

    return array_merge($existing_scripts, $scripts);
}
