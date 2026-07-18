<?php

function HookImage_sequenceAdmin_group_permissionsAdditionalperms()
{
    global $lang, $permissions;
    ?>
    <tr class="ListviewTitleStyle">
        <th colspan="3" class="permheader"><?php echo escape($lang['image_sequence_section']); ?></th>
    </tr>
    <?php
    DrawOption(
        'is',
        $lang['image_sequence_permission_access'],
        false,
        false,
        in_array('a', $permissions, true)
    );
}
