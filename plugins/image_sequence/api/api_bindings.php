<?php

/**
 * ResourceSpace API bindings for the Image Sequence plugin.
 *
 * Example (signed userkey auth):
 *   function=image_sequence_get_framing_boxes&param1=<resource_ref>
 *
 * Returns a JSON object with source dimensions, in/out frames, and each box's
 * x/y/width/height (source pixels) plus rotation_degrees_clockwise.
 */

/**
 * List framing crop boxes for a resource (view access required).
 *
 * @param int|string $resource Resource ref
 * @return array<string, mixed>|false
 */
function api_image_sequence_get_framing_boxes($resource)
{
    include_once dirname(__DIR__) . '/include/image_sequence_functions.php';

    return image_sequence_framing_export_resource((int) $resource);
}

/**
 * Fetch one framing box by its framing-box ref (view access on its resource).
 *
 * @param int|string $box_ref Framing box primary key
 * @return array<string, mixed>|false
 */
function api_image_sequence_get_framing_box($box_ref)
{
    include_once dirname(__DIR__) . '/include/image_sequence_functions.php';

    $box_ref = (int) $box_ref;
    if ($box_ref <= 0) {
        return false;
    }

    image_sequence_ensure_framing_table();
    $rows = ps_query('SELECT * FROM resource_framing_box WHERE ref = ?', ['i', $box_ref]);
    if ($rows === []) {
        return false;
    }

    $resource = (int) ($rows[0]['resource'] ?? 0);
    $export = image_sequence_framing_export_resource($resource);
    if ($export === false) {
        return false;
    }

    $box = image_sequence_framing_serialize_box($rows[0]);

    return [
        'ok' => true,
        'schema' => 'image_sequence_framing_v1',
        'coordinate_system' => $export['coordinate_system'],
        'origin' => $export['origin'],
        'rotation_convention' => $export['rotation_convention'],
        'notes' => $export['notes'],
        'resource' => $resource,
        'source_width' => $export['source_width'],
        'source_height' => $export['source_height'],
        'fps' => $export['fps'],
        'in_frame' => $export['in_frame'],
        'out_frame' => $export['out_frame'],
        'box' => $box,
    ];
}
