<?php

# Defaults for the Image Sequence plugin.
# Override via Admin → Plugins → Image Sequence setup, or a local config.php beside this file.

# Resource type ID for Image Sequence (0 = auto-create on activate/setup).
$image_sequence_restype = 0;

# Playback FPS used for proxy video and catalog duration (not capture cadence).
$image_sequence_fps_default = 30;

# Allowed absolute roots for in-place frame storage (read-only). Empty = use $syncdir when set.
# The plugin never writes into these directories — manifests go to filestore; web uploads to staging.
$image_sequence_sync_roots = [];

# Staging subfolder under filestore ($storagedir) for web ZIP/multi-file uploads (writable).
$image_sequence_upload_subdir = 'image_sequences';

# Cadence auto-split (ported from Ingestr).
$image_sequence_auto_split = true;
$image_sequence_min_frames = 10;
$image_sequence_min_files_for_cadence = 3;
$image_sequence_max_cadence_sample = 180;
$image_sequence_minimum_session_gap = 600;
$image_sequence_minimum_adaptive_gap = 180;

# Supported still extensions (comma-separated string in plugin config; normalized to array on load).
$image_sequence_extensions = 'jpg,jpeg,png,tif,tiff,heic,gif,raw,cr2,crw,nef,arw,dng,exr,dpx';

# Proxy encode settings (fall back to core $ffmpeg_preview_* when empty).
$image_sequence_proxy_max_width = 0;
$image_sequence_proxy_max_height = 0;
$image_sequence_proxy_max_seconds = 0;
$image_sequence_proxy_options = '';

# Metadata field refs (0 = unset / auto-created on activate/setup).
$image_sequence_framecount_field = 0;
$image_sequence_duration_field = 0;
$image_sequence_fps_field = 0;
$image_sequence_repframe_field = 0;
$image_sequence_inframe_field = 0;
$image_sequence_outframe_field = 0;
$image_sequence_cadence_field = 0;
$image_sequence_folder_field = 0;

# Photo resource type used for extras (stock Photo = 1).
$image_sequence_photo_restype = 1;

# Protect field configs from accidental deletion when plugin is active.
$image_sequence_fieldvars = [
    'image_sequence_framecount_field',
    'image_sequence_duration_field',
    'image_sequence_fps_field',
    'image_sequence_repframe_field',
    'image_sequence_inframe_field',
    'image_sequence_outframe_field',
    'image_sequence_cadence_field',
    'image_sequence_folder_field',
];
