<?php
/**
 * One-shot repair:
 * 1) Mark stuck "processing" sequences ready when a proxy mp4 already exists
 * 2) Rebuild missing poster/thumbs from the proxy (or member still)
 * 3) Regenerate truly failed proxies (no usable mp4)
 * 4) Queue AI metadata for sequences missing title (force_overwrite=false)
 *
 * Usage:
 *   docker compose exec resourcespace php docker/repair_proxies_and_queue_ai.php
 *   docker compose exec resourcespace php docker/repair_proxies_and_queue_ai.php --dry-run
 */

include dirname(__DIR__) . '/include/boot.php';
command_line_only();

include_once dirname(__DIR__) . '/plugins/image_sequence/include/image_sequence_functions.php';

$dry_run = in_array('--dry-run', $argv ?? [], true);

global $ffmpeg_preview_extension;
$ext = $ffmpeg_preview_extension ?: 'mp4';
$ffmpeg = get_utility_path('ffmpeg');

/**
 * Ensure poster + thumbs exist for a sequence that already has a proxy video.
 */
function repair_ensure_poster_and_thumbs(int $ref, string $proxy_path, $ffmpeg): bool
{
    $poster = get_resource_path($ref, true, 'pre', true, 'jpg');
    $need_poster = !is_file($poster) || filesize($poster) <= 0;

    if ($need_poster) {
        $ok = false;

        // Prefer extracting a frame from the existing proxy (works for ARW sources).
        if ($ffmpeg !== false && is_file($proxy_path)) {
            try {
                run_command(
                    $ffmpeg . ' -hide_banner -loglevel error -y -ss 0 -i %%SRC%% -frames:v 1 -q:v 2 -update 1 %%DST%%',
                    false,
                    [
                        '%%SRC%%' => new CommandPlaceholderArg($proxy_path, 'is_valid_rs_path'),
                        '%%DST%%' => new CommandPlaceholderArg($poster, 'is_valid_rs_path'),
                    ]
                );
                $ok = is_file($poster) && filesize($poster) > 0;
            } catch (Throwable $e) {
                debug('repair poster from proxy: ' . $e->getMessage());
            }
        }

        if (!$ok) {
            $data = image_sequence_get_data($ref);
            if (is_array($data)) {
                $paths = image_sequence_member_absolute_paths($data);
                if ($paths !== []) {
                    $rep = (int) ($data['representative_frame'] ?? 0);
                    if ($rep < 0 || $rep >= count($paths)) {
                        $rep = (int) floor(count($paths) / 2);
                    }
                    $src = $paths[$rep];
                    $src_ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
                    if (in_array($src_ext, ['jpg', 'jpeg', 'png'], true)) {
                        $ok = @copy($src, $poster) && is_file($poster) && filesize($poster) > 0;
                    } elseif ($ffmpeg !== false) {
                        try {
                            run_command(
                                $ffmpeg . ' -hide_banner -loglevel error -y -i %%SRC%% -frames:v 1 -q:v 2 -update 1 %%DST%%',
                                false,
                                [
                                    '%%SRC%%' => new CommandPlaceholderArg($src, [CommandPlaceholderArg::class, 'alwaysValid']),
                                    '%%DST%%' => new CommandPlaceholderArg($poster, 'is_valid_rs_path'),
                                ]
                            );
                            $ok = is_file($poster) && filesize($poster) > 0;
                        } catch (Throwable $e) {
                            debug('repair poster from still: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        if (!$ok) {
            return false;
        }
    }

    image_sequence_derive_thumbs_from_poster($ref, $poster);
    ps_query(
        "UPDATE resource SET has_image = 1, preview_extension = 'jpg', is_transcoding = 0 WHERE ref = ?",
        ['i', $ref]
    );

    return true;
}

echo $dry_run ? "=== DRY RUN ===\n" : "=== REPAIR ===\n";

// --- 1+2: stuck processing with existing proxy ---
$stuck = ps_query(
    "SELECT resource FROM resource_image_sequence
     WHERE proxy_status = 'processing'
     ORDER BY resource"
);

$marked_ready = 0;
$poster_fixed = 0;
$poster_failed = 0;
$stuck_no_file = [];

foreach ($stuck as $row) {
    $ref = (int) $row['resource'];
    $proxy = get_resource_path($ref, true, 'pre', false, $ext);
    $has_proxy = is_string($proxy) && is_file($proxy) && filesize($proxy) > 0;

    if (!$has_proxy) {
        $stuck_no_file[] = $ref;
        echo "stuck#{$ref}: no proxy file — will regenerate\n";
        continue;
    }

    echo "stuck#{$ref}: mark ready + ensure poster/thumbs\n";
    if (!$dry_run) {
        ps_query(
            "UPDATE resource_image_sequence SET proxy_status = 'ready' WHERE resource = ?",
            ['i', $ref]
        );
        image_sequence_clear_transcoding_lock($ref);
        if (repair_ensure_poster_and_thumbs($ref, $proxy, $ffmpeg)) {
            $poster_fixed++;
        } else {
            $poster_failed++;
            echo "  WARN: could not build poster/thumbs for #{$ref}\n";
        }
    }
    $marked_ready++;
}

// --- 3: failed or processing-without-file ---
$to_regen = ps_query(
    "SELECT resource FROM resource_image_sequence
     WHERE proxy_status = 'failed'
        OR (proxy_status = 'processing' AND resource IN (" .
        (count($stuck_no_file) > 0 ? implode(',', array_map('intval', $stuck_no_file)) : '0') .
        "))
     ORDER BY resource"
);

// Deduplicate refs
$regen_refs = [];
foreach ($to_regen as $row) {
    $regen_refs[(int) $row['resource']] = true;
}
foreach ($stuck_no_file as $ref) {
    $regen_refs[$ref] = true;
}
$regen_refs = array_keys($regen_refs);

$regen_ok = 0;
$regen_fail = 0;
foreach ($regen_refs as $ref) {
    echo "regen#{$ref}: generating proxy\n";
    if ($dry_run) {
        continue;
    }
    image_sequence_clear_transcoding_lock($ref);
    if (image_sequence_generate_proxy($ref)) {
        $regen_ok++;
        echo "  ok\n";
    } else {
        $regen_fail++;
        echo "  FAILED\n";
    }
}

// --- 4: queue AI for sequences missing title ---
$title_field = (int) ps_value(
    "SELECT ref value FROM resource_type_field WHERE name = 'title'",
    [],
    0
);

$queued_ai = 0;
$skipped_ai = 0;
if ($title_field > 0) {
    $missing = ps_query(
        "SELECT r.ref
         FROM resource r
         INNER JOIN resource_image_sequence s ON s.resource = r.ref
         WHERE r.archive <> 99 AND r.ref > 0 AND r.resource_type = 5
           AND NOT EXISTS (
             SELECT 1
             FROM resource_node rn
             INNER JOIN node n ON n.ref = rn.node
             WHERE rn.resource = r.ref
               AND n.resource_type_field = ?
               AND TRIM(n.name) <> ''
           )
         ORDER BY r.ref",
        ['i', $title_field]
    );

    echo 'AI candidates missing title: ' . count($missing) . "\n";
    foreach ($missing as $row) {
        $ref = (int) $row['ref'];
        if ($dry_run) {
            $queued_ai++;
            continue;
        }
        // force_overwrite=false: fill empty AI fields only
        image_sequence_queue_ai_metadata($ref, false);
        $queued_ai++;
    }
} else {
    echo "WARN: title field not found; skipping AI queue\n";
    $skipped_ai = 1;
}

echo "\n=== summary ===\n";
echo "marked_ready={$marked_ready}\n";
echo "poster_fixed={$poster_fixed}\n";
echo "poster_failed={$poster_failed}\n";
echo "regen_ok={$regen_ok}\n";
echo "regen_fail={$regen_fail}\n";
echo "ai_queued={$queued_ai}\n";

if (!$dry_run) {
    $pending = (int) ps_value(
        'SELECT COUNT(*) value FROM job_queue WHERE status = ?',
        ['i', STATUS_ACTIVE],
        0
    );
    echo "job_queue_active={$pending}\n";
}

echo "done\n";
