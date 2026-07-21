<?php

# Defaults for the Edited Flag plugin.

# Metadata field ref for the "Edited" flag (0 = auto-create on activate/setup).
$edited_flag_field = 0;

# Short name used for the auto-created field.
$edited_flag_shortname = 'edited';

# Title shown to users.
$edited_flag_title = 'Edited';

# Value written when a resource is manually edited.
$edited_flag_value = 'Yes';

# Protect the field config from accidental deletion while the plugin is active.
$edited_flag_fieldvars = [
    'edited_flag_field',
];
