<?php

include "../../../include/boot.php";
include "../../../include/authenticate.php";
if (!checkperm('a')) {
    exit(escape($lang['error-permissiondenied'] ?? 'Permission denied.'));
}

$plugin_name = 'image_sequence';
$plugin_page_heading = $lang['image_sequence_configuration'];
if (!in_array($plugin_name, $plugins)) {
    plugin_activate_for_setup($plugin_name);
}

include_once __DIR__ . '/../include/image_sequence_functions.php';
image_sequence_ensure_setup();

$page_def[] = config_add_single_rtype_select('image_sequence_restype', $lang['image_sequence_restype']);
$page_def[] = config_add_single_rtype_select('image_sequence_photo_restype', $lang['image_sequence_photo_restype']);
$page_def[] = config_add_boolean_select('image_sequence_video_nle', $lang['image_sequence_video_nle']);
$page_def[] = config_add_text_input('image_sequence_fps_default', $lang['image_sequence_fps_default']);
$page_def[] = config_add_boolean_select('image_sequence_auto_split', $lang['image_sequence_auto_split']);
$page_def[] = config_add_text_input('image_sequence_min_frames', $lang['image_sequence_min_frames']);
$page_def[] = config_add_text_input('image_sequence_min_files_for_cadence', $lang['image_sequence_min_files_for_cadence']);
$page_def[] = config_add_text_input('image_sequence_max_cadence_sample', $lang['image_sequence_max_cadence_sample']);
$page_def[] = config_add_text_input('image_sequence_minimum_session_gap', $lang['image_sequence_minimum_session_gap']);
$page_def[] = config_add_text_input('image_sequence_minimum_adaptive_gap', $lang['image_sequence_minimum_adaptive_gap']);
$page_def[] = config_add_text_input('image_sequence_extensions', $lang['image_sequence_extensions']);
$page_def[] = config_add_text_input('image_sequence_upload_subdir', $lang['image_sequence_upload_subdir']);
$page_def[] = config_add_text_input('image_sequence_proxy_max_width', $lang['image_sequence_proxy_max_width']);
$page_def[] = config_add_text_input('image_sequence_proxy_bitrate', $lang['image_sequence_proxy_bitrate']);
$page_def[] = config_add_text_input('image_sequence_proxy_options', $lang['image_sequence_proxy_options']);
$page_def[] = config_add_text_input('image_sequence_proxy_max_seconds', $lang['image_sequence_proxy_max_seconds']);

$page_def[] = config_add_single_ftype_select('image_sequence_framecount_field', $lang['image_sequence_framecount_field'], 420);
$page_def[] = config_add_single_ftype_select('image_sequence_duration_field', $lang['image_sequence_duration_field'], 420);
$page_def[] = config_add_single_ftype_select('image_sequence_fps_field', $lang['image_sequence_fps_field'], 420);
$page_def[] = config_add_single_ftype_select('image_sequence_repframe_field', $lang['image_sequence_repframe_field'], 420);
$page_def[] = config_add_single_ftype_select('image_sequence_inframe_field', $lang['image_sequence_inframe_field'], 420);
$page_def[] = config_add_single_ftype_select('image_sequence_outframe_field', $lang['image_sequence_outframe_field'], 420);
$page_def[] = config_add_single_ftype_select('image_sequence_cadence_field', $lang['image_sequence_cadence_field'], 420);
$page_def[] = config_add_single_ftype_select('image_sequence_seqcode_field', $lang['image_sequence_seqcode_field'], 420);
$page_def[] = config_add_single_ftype_select('image_sequence_folder_field', $lang['image_sequence_folder_field'], 420);
$page_def[] = config_add_single_ftype_select('image_sequence_folderpath_field', $lang['image_sequence_folderpath_field'], 420);

config_gen_setup_post($page_def, $plugin_name);

// Keep extensions as a comma-separated string in plugin config (text input).
// Runtime code accepts string or array via image_sequence_supported_extensions().
$cfg = get_plugin_config($plugin_name) ?: [];
if (isset($cfg['image_sequence_extensions']) && is_array($cfg['image_sequence_extensions'])) {
    $cfg['image_sequence_extensions'] = implode(',', array_map('strval', $cfg['image_sequence_extensions']));
    set_plugin_config($plugin_name, $cfg);
}
if (isset($GLOBALS['image_sequence_extensions']) && is_array($GLOBALS['image_sequence_extensions'])) {
    $GLOBALS['image_sequence_extensions'] = implode(',', $GLOBALS['image_sequence_extensions']);
    $image_sequence_extensions = $GLOBALS['image_sequence_extensions'];
}

include "../../../include/header.php";
config_gen_setup_html($page_def, $plugin_name, null, $plugin_page_heading);

global $syncdir;
echo '<div class="PageInformal"><p>' . escape($lang['image_sequence_setup_requirements']) . '</p>';
if (empty($syncdir)) {
    echo '<p><strong>' . escape($lang['image_sequence_syncdir_required']) . '</strong></p>';
} else {
    echo '<p>$syncdir (read-only scan): <code>' . escape((string) $syncdir) . '</code></p>';
}
$staging = image_sequence_staging_root();
if ($staging !== '') {
    echo '<p>Writable staging: <code>' . escape($staging) . '</code></p>';
}
echo '</div>';

echo '<p><a href="' . escape($baseurl_short) . 'plugins/image_sequence/pages/ingest.php">'
    . escape($lang['image_sequence_setup_ingest_link']) . '</a></p>';
include '../../../include/footer.php';
