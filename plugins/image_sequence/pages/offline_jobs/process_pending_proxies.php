<?php

include '../../../../include/boot.php';
include '../../../../include/authenticate.php';

if (!job_trigger_permission_check()) {
    exit('Permission denied.');
}

$job_user = getval('job_user', 0, true);
$plugin = getval('plugin', 0, true);

if ($plugin) {
    $parent_page = generateURL("{$baseurl_short}pages/manage_jobs.php");
    $breadcrumbs = [
        ['title' => $lang['systemsetup'], 'href' => "{$baseurl_short}pages/admin/admin_home.php", 'menu' => true],
        ['title' => $lang['pluginmanager'], 'href' => "{$baseurl_short}pages/team/team_plugins.php", 'menu' => false],
        ['title' => $lang['image_sequence_configuration'], 'href' => "{$baseurl_short}plugins/image_sequence/pages/setup.php", 'menu' => false],
        ['title' => $lang['job_configure'] . ': ' . $lang['image_sequence_job_rebuild_proxies']],
    ];
} elseif ($job_user == $userref) {
    $parent_page = generateURL("{$baseurl_short}pages/manage_jobs.php", ['job_user' => $job_user]);
    $breadcrumbs = [
        ['title' => $userfullname == '' ? $username : $userfullname, 'href' => "{$baseurl_short}pages/user/user_home.php", 'menu' => true],
        ['title' => $lang['manage_jobs_title'], 'href' => $parent_page, 'menu' => false],
        ['title' => $lang['job_configure'] . ': ' . $lang['image_sequence_job_rebuild_proxies']],
    ];
} else {
    $parent_page = generateURL("{$baseurl_short}pages/manage_jobs.php");
    $breadcrumbs = [
        ['title' => $lang['systemsetup'], 'href' => "{$baseurl_short}pages/admin/admin_home.php", 'menu' => true],
        ['title' => $lang['manage_jobs_title'], 'href' => $parent_page, 'menu' => false],
        ['title' => $lang['job_configure'] . ': ' . $lang['image_sequence_job_rebuild_proxies']],
    ];
}

$job_add_error = false;
$job_added = '';

if (getval('save', '') !== '' && enforcePostRequest(false)) {
    $job_data = [];
    $success = $lang['image_sequence_job_rebuild_proxies_success'] ?? 'Image Sequence proxies rebuilt (%count% ready).';
    $failure = $lang['image_sequence_job_rebuild_proxies_failed'] ?? 'Image Sequence proxy rebuild finished with errors (ok=%ok%, failed=%fail%).';
    $job_added = job_queue_add('process_image_sequence_proxies', $job_data, '', '', $success, $failure);

    if (!is_int_loose($job_added)) {
        $job_add_error = true;
    } else {
        log_activity(
            "Added process_image_sequence_proxies job $job_added",
            LOG_CODE_JOB_ADDED,
            json_encode($job_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'job_queue',
            'job_data',
            $job_added,
            null,
            '',
            null,
            true
        );
        redirect($parent_page);
    }
}

include '../../../../include/header.php';

if ($job_add_error) {
    toast_notification(ToastNotificationType::Error, (string) $job_added);
}
?>
<div class="BasicsBox">
    <h1>
        <?php
        echo escape($lang['job_configure'] . ': ' . $lang['image_sequence_job_rebuild_proxies']);
        render_help_link('user/manage_jobs');
        ?>
    </h1>
    <?php renderBreadcrumbs($breadcrumbs); ?>
    <p><?php echo escape($lang['image_sequence_job_rebuild_proxies_intro']); ?></p>
    <form method="post"
          action="<?php echo generateURL("{$baseurl_short}plugins/image_sequence/pages/offline_jobs/process_pending_proxies.php", ['job_user' => $job_user, 'plugin' => $plugin]); ?>">
        <?php generateFormToken('image_sequence_process_pending_proxies'); ?>
        <div class="QuestionSubmit">
            <label for="save"></label>
            <input type="submit" name="save" value="<?php echo escape($lang['oj_common_create_job']); ?>" />
        </div>
    </form>
</div>
<?php
include '../../../../include/footer.php';
