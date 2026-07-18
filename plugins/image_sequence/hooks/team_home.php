<?php

include_once __DIR__ . '/../include/image_sequence_functions.php';

function HookImage_sequenceTeam_homeCustomteamfunction()
{
    global $lang, $baseurl_short;

    if (!image_sequence_can_access_tools()) {
        return false;
    }
    ?>
    <li title="<?php echo escape($lang['image_sequence_team_tooltip']); ?>">
        <a href="<?php echo escape($baseurl_short); ?>plugins/image_sequence/pages/ingest.php"
           onclick="return CentralSpaceLoad(this, true);">
            <i aria-hidden="true" class="icon-images"></i>
            <br />
            <?php echo escape($lang['page-title_image_sequence_ingest']); ?>
        </a>
    </li>
    <?php
}
