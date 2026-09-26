<?php
/**
 * Rebuild proxies for sequences flagged Inverted=Yes (after orientation encode fix).
 *
 *   docker compose exec resourcespace php docker/rebuild_inverted_proxies.php
 */

include dirname(__DIR__) . '/include/boot.php';
command_line_only();

include_once dirname(__DIR__) . '/plugins/image_sequence/include/image_sequence_functions.php';

$field = (int) ps_value(
    "SELECT ref value FROM resource_type_field WHERE name = 'imgseq_inverted'",
    [],
    0
);
if ($field <= 0) {
    echo "imgseq_inverted field missing\n";
    exit(1);
}

$rows = ps_query(
    "SELECT DISTINCT r.ref, s.folder_path, s.frame_count
     FROM resource r
     INNER JOIN resource_image_sequence s ON s.resource = r.ref
     INNER JOIN resource_node rn ON rn.resource = r.ref
     INNER JOIN node n ON n.ref = rn.node
     WHERE n.resource_type_field = ?
       AND LOWER(TRIM(n.name)) IN ('yes', 'y', '1', 'true')
     ORDER BY r.ref",
    ['i', $field]
);

echo 'inverted_sequences=' . count($rows) . "\n";

$ok = 0;
$fail = 0;
foreach ($rows as $row) {
    $ref = (int) $row['ref'];
    echo "rebuild#{$ref} frames={$row['frame_count']} folder={$row['folder_path']} ... ";
    flush();
    image_sequence_clear_transcoding_lock($ref);
    if (image_sequence_generate_proxy($ref)) {
        $ok++;
        echo "ok\n";
    } else {
        $fail++;
        echo "FAILED\n";
    }
}

echo "summary ok={$ok} fail={$fail}\n";
