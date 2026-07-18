Image Sequence plugin for ResourceSpace
=======================================

Ingest folders of sequentially numbered stills as Image Sequence resources.
Frames stay on disk under $syncdir (or configured sync roots) — they are not
copied into filestore. The plugin builds an FFmpeg proxy video for preview,
supports picking a representative frame (EXIF into metadata), and auto-splits
large still folders using capture-time cadence (Ingestr-style).

What you get
------------

- Image Sequence resource type + metadata fields (auto-created on activate/setup)
- Cadence auto-split: long segments → sequences; short segments → Photos
- CLI sync over $syncdir (idempotent; skips frames already claimed)
- Web ingest page (ZIP / multi-file → staged under sync root)
- Proxy video job + representative-frame scrubber on the view page
- ZIP download of member frames (inline or offline job)


Requirements
------------

- ResourceSpace with PHP ZipArchive
- $syncdir set in include/config.php (absolute path, writable by the web/CLI user)
- FFmpeg available (same as core video preview)
- ExifTool available for representative-frame metadata
- One small core change (see below) so StaticSync does not re-import claimed frames


Install on another ResourceSpace system
---------------------------------------

IMPORTANT: This plugin needs one core StaticSync hook (not in vanilla RS yet).
Apply the patch before relying on folder sync alongside staticsync.php.

1. Copy the entire plugins/image_sequence/ directory into the target install.

2. Apply the StaticSync hook (required). In pages/tools/staticsync.php, where
   existing sync files are skipped, ensure this call is present:

     hook('staticsync_skip_file', 'staticsync', [$shortpath, $fullpath])

   Example (replace the simple existing-check):

     if (
         $existing > 0
         || hook('staticsync_skip_file', 'staticsync', [$shortpath, $fullpath])
         || hook('staticsync_plugin_add_to_done')
     ) {
         ...
     }

   A patch file is included at:
     plugins/image_sequence/patches/staticsync_skip_file.patch

3. In Admin → System → Manage plugins, activate "Image Sequence".

4. Open the plugin setup page once (creates resource type + fields if needed).
   Confirm $syncdir is set; adjust FPS / cadence thresholds if desired.

5. Grant the "is" permission (Image Sequence ingest) to non-admin groups that
   should see Team → Ingest Image Sequences. Sysadmins (a) always have access.

6. Schedule CLI sync (cron), for example:

     php /path/to/resourcespace/plugins/image_sequence/pages/tools/image_sequence_sync.php

   Optionally keep running core staticsync.php as usual — claimed sequence
   frames will be skipped via the hook above.

7. Optional: Admin → Manage jobs → Image Sequence → Rebuild pending proxies
   to regenerate failed/stuck proxy videos.


Usage
-----

Team centre
  Users with admin (a) or Image Sequence (is) permission see
  "Ingest Image Sequences" on the Team home page.

Folder sync
  Place numbered stills under $syncdir/<folder>/ and run image_sequence_sync.php.

Web ingest
  Team → Ingest Image Sequences (or plugins/image_sequence/pages/ingest.php) —
  upload a ZIP or stills. Files are extracted under $syncdir/image_sequences/
  (configurable), then cadence-split into sequences and leftover photos.

Upload as Image Sequence type
  Uploading a ZIP to a new Image Sequence resource expands and re-ingests the
  same way; the placeholder ZIP resource is removed only if ingest succeeds.

View page
  Scrub the proxy, set representative frame, download ZIP of frames.
  Setting a representative frame pulls still metadata into the asset:
  width/height/DPI/file size (resource properties) plus camera make/model,
  lens, ISO, aperture, shutter, focal length, bit depth, color space, and
  related ExifTool-mapped fields.

  On create (and when updating the representative frame), the plugin also
  analyses the whole sequence for:
  - First / last frame capture times (from EXIF DateTimeOriginal)
  - Real-time duration (last − first capture)
  - Interval between frames (median cadence)
  - Exposure program (manual / aperture priority / shutter priority / etc.)
    plus whether aperture, shutter, and ISO were fixed or varied

Offline jobs
  Admin → Manage jobs → Image Sequence → Rebuild pending/failed sequence proxies.


Notes
-----

- Frames are referenced by relative path under the sync root; deleting a
  sequence removes the plugin DB row and .rs_imagesequence_*.json manifest,
  not the still files on disk.
- Do not ship local include/config.php, filestore/, syncdir/, or vendor/ with
  this plugin.
- Unit test: tests/test_list/001590_image_sequence_cadence.php
