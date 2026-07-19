<?php

/**
 * Lightweight web page to stage a ZIP (or stills) under filestore staging and
 * run cadence auto-split ingest into Image Sequence + Photo resources.
 */

include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once __DIR__ . '/../include/image_sequence_functions.php';

if (!image_sequence_can_access_tools()) {
    exit(escape($lang['error-permissiondenied'] ?? 'Permission denied'));
}

image_sequence_ensure_setup();

$staging = image_sequence_staging_root();
if ($staging === '') {
    include "../../../include/header.php";
    echo '<p class="FormHelp">' . escape($lang['image_sequence_staging_unavailable'] ?? 'Could not create a writable staging folder under filestore.') . '</p>';
    include "../../../include/footer.php";
    exit;
}

$result = null;
$error = '';

if (getval('submit', '') !== '' && enforcePostRequest(false)) {
    $paths = [];
    if (!empty($_FILES['userfile']['tmp_name'])) {
        if (is_array($_FILES['userfile']['tmp_name'])) {
            foreach ($_FILES['userfile']['tmp_name'] as $i => $tmp) {
                if (is_uploaded_file($tmp)) {
                    $name = $_FILES['userfile']['name'][$i] ?? ('file_' . $i);
                    $dest = get_temp_dir(false, 'imgseq_upload') . '/' . safe_file_name($name);
                    move_uploaded_file($tmp, $dest);
                    $paths[] = $dest;
                }
            }
        } elseif (is_uploaded_file($_FILES['userfile']['tmp_name'])) {
            $name = $_FILES['userfile']['name'] ?? 'upload.bin';
            $dest = get_temp_dir(false, 'imgseq_upload') . '/' . safe_file_name($name);
            move_uploaded_file($_FILES['userfile']['tmp_name'], $dest);
            $paths[] = $dest;
        }
    }

    if ($paths === []) {
        $error = $lang['image_sequence_ingest_no_files'];
    } else {
        $result = image_sequence_ingest_upload_paths($paths, [
            'created_by' => (int) $userref,
        ]);
    }
}

include "../../../include/header.php";
?>
<div class="BasicsBox">
    <h1><?php echo escape($lang['page-title_image_sequence_ingest']); ?></h1>
    <p><?php echo escape($lang['image_sequence_ingest_intro']); ?></p>

    <?php if ($error !== '') { ?>
        <div class="PageInformal"><?php echo escape($error); ?></div>
    <?php } ?>

    <?php if (is_array($result)) { ?>
        <div class="PageInformal">
            <?php
            echo escape(str_replace(
                ['%seq%', '%photo%'],
                [(string) count($result['sequences']), (string) count($result['photos'])],
                $lang['image_sequence_ingest_result']
            ));
            ?>
            <?php if (!empty($result['sequences'])) { ?>
                <ul>
                    <?php foreach ($result['sequences'] as $sref) { ?>
                        <li><a href="<?php echo escape($baseurl_short); ?>pages/view.php?ref=<?php echo (int) $sref; ?>">
                            <?php echo escape(str_replace('%ref%', (string) (int) $sref, $lang['image_sequence_ingest_sequence_link'])); ?></a></li>
                    <?php } ?>
                </ul>
            <?php } ?>
        </div>
    <?php } ?>

    <form method="post" enctype="multipart/form-data" action="<?php echo escape($_SERVER['PHP_SELF']); ?>">
        <?php generateFormToken('image_sequence_ingest'); ?>
        <div class="Question">
            <label><?php echo escape($lang['image_sequence_ingest_files_label']); ?></label>
            <input type="file" name="userfile[]" multiple accept=".zip,image/*">
            <div class="clearerleft"></div>
        </div>
        <div class="QuestionSubmit">
            <input type="submit" name="submit" value="<?php echo escape($lang['image_sequence_ingest_submit']); ?>">
        </div>
    </form>
</div>
<?php
include "../../../include/footer.php";
