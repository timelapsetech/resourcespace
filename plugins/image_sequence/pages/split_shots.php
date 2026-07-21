<?php
/**
 * Auto-detect cadence shot breaks and optionally split an Image Sequence.
 *
 * AJAX:
 *   POST detect=1  → dry-run preview of shots
 *   POST apply=1   → perform the split
 */

include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once __DIR__ . '/../include/image_sequence_functions.php';

$ref = getval('ref', 0, true);
$detect = getval('detect', '') === '1' || getval('detect', '') === 'true';
$apply = getval('apply', '') === '1' || getval('apply', '') === 'true';

// A JSON action is any detect/apply request. Do NOT key this off `ajax=true`:
// CentralSpaceLoad appends `ajax=true` to every GET it makes, so using it here
// would send the confirmation-page load into the POST-only JSON branch (405).
$json_action = $detect || $apply;

$send_json = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}';
    exit;
};

if ($ref <= 0 || !get_edit_access($ref)) {
    if ($json_action) {
        $send_json(['ok' => false, 'message' => $lang['error-permissiondenied'] ?? 'Permission denied'], 403);
    }
    exit(escape($lang['error-permissiondenied'] ?? 'Permission denied.'));
}

$resource = get_resource_data($ref);
if (!is_array($resource) || !image_sequence_is_sequence_resource($resource)) {
    if ($json_action) {
        $send_json(['ok' => false, 'message' => $lang['image_sequence_no_data'] ?? 'Not an image sequence.'], 400);
    }
    exit(escape($lang['image_sequence_no_data'] ?? 'Not an image sequence.'));
}

if ($json_action) {
    enforcePostRequest(true);
    $created_by = (int) ($GLOBALS['userref'] ?? 0);
    if ($apply) {
        $result = image_sequence_split_sequence_by_cadence($ref, false, $created_by);
        unset($result['segments']);
        $send_json($result);
    }
    if ($detect) {
        $result = image_sequence_split_sequence_by_cadence($ref, true, $created_by);
        unset($result['segments']);
        $send_json($result);
    }
    $send_json(['ok' => false, 'message' => 'Specify detect=1 or apply=1'], 400);
}

// Confirmation page with Detect/Apply buttons. Rendered for both full-page loads
// and CentralSpaceLoad partial loads (ajax=true GET); the buttons then POST
// detect=1 / apply=1 back to the JSON branch above.
include '../../../include/header.php';
$data = image_sequence_get_data($ref);
$frame_count = (int) ($data['frame_count'] ?? 0);
$url = generateURL($baseurl_short . 'plugins/image_sequence/pages/split_shots.php', ['ref' => $ref]);
$view_url = generateURL($baseurl_short . 'pages/view.php', ['ref' => $ref]);

// CSRF token for the detect/apply POST. authenticate.php rejects any non-API POST
// without a valid token with HTTP 400, so it must travel in the request body.
$csrf_identifier = (string) ($GLOBALS['CSRF_token_identifier'] ?? 'CSRFToken');
$csrf_token = (!empty($GLOBALS['CSRF_enabled']) && function_exists('generateCSRFToken'))
    ? generateCSRFToken($GLOBALS['usersession'] ?? null, 'image_sequence_split_shots')
    : '';
?>
<div class="BasicsBox">
    <h1><?php echo escape($lang['image_sequence_split_shots_title'] ?? 'Auto-detect and split shots'); ?></h1>
    <p><?php echo escape($lang['image_sequence_split_shots_intro'] ?? ''); ?></p>
    <p><?php echo escape(str_replace('%n%', (string) $frame_count, $lang['image_sequence_split_shots_frames'] ?? '%n% frames in this sequence')); ?></p>
    <p id="imgseq-split-status" class="FormHelp"></p>
    <div id="imgseq-split-preview"></div>
    <div class="Question">
        <button type="button" class="rs_btn rs_btn_primary" id="imgseq-split-detect">
            <?php echo escape($lang['image_sequence_split_shots_detect'] ?? 'Detect shot breaks'); ?>
        </button>
        <button type="button" class="rs_btn" id="imgseq-split-apply" disabled>
            <?php echo escape($lang['image_sequence_split_shots_apply'] ?? 'Split into separate sequences'); ?>
        </button>
        <a class="rs_btn" href="<?php echo escape($view_url); ?>" onclick="return CentralSpaceLoad(this, true);">
            <?php echo escape($lang['backtoview'] ?? 'Back to resource'); ?>
        </a>
    </div>
</div>
<script>
(function () {
    var url = <?php echo json_encode($url); ?>;
    var csrfIdentifier = <?php echo json_encode($csrf_identifier); ?>;
    var csrfToken = <?php echo json_encode($csrf_token); ?>;
    var statusEl = document.getElementById('imgseq-split-status');
    var previewEl = document.getElementById('imgseq-split-preview');
    var detectBtn = document.getElementById('imgseq-split-detect');
    var applyBtn = document.getElementById('imgseq-split-apply');
    var lastOk = false;

    function setStatus(msg) { statusEl.textContent = msg || ''; }

    function renderShots(shots) {
        if (!shots || !shots.length) {
            previewEl.innerHTML = '';
            return;
        }
        var html = '<table class="ListviewStyle"><thead><tr>'
            + '<th>#</th><th>Frames</th><th>First</th><th>Last</th><th>Duration</th>'
            + '</tr></thead><tbody>';
        shots.forEach(function (s) {
            html += '<tr><td>' + (s.index + 1) + '</td><td>' + s.frames + '</td><td>'
                + (s.first || '') + '</td><td>' + (s.last || '') + '</td><td>'
                + (s.duration || '') + '</td></tr>';
        });
        html += '</tbody></table>';
        previewEl.innerHTML = html;
    }

    function post(action) {
        setStatus(action === 'detect'
            ? <?php echo json_encode($lang['image_sequence_split_shots_detecting'] ?? 'Reading EXIF dates…'); ?>
            : <?php echo json_encode($lang['image_sequence_split_shots_applying'] ?? 'Splitting…'); ?>);
        detectBtn.disabled = true;
        applyBtn.disabled = true;
        var body = 'ajax=true&' + action + '=1';
        if (csrfToken) {
            body += '&' + encodeURIComponent(csrfIdentifier) + '=' + encodeURIComponent(csrfToken);
        }
        return fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.text().then(function (text) {
                var data;
                try { data = JSON.parse(text); } catch (e) { data = null; }
                if (!r.ok || !data) {
                    var detail = (data && (data.message || (data.error && data.error.detail)))
                        || ('Request failed (' + r.status + ')');
                    throw new Error(detail);
                }
                return data;
            });
        }).then(function (data) {
            detectBtn.disabled = false;
            setStatus(data.message || '');
            renderShots(data.shots || []);
            lastOk = !!(data.ok && data.shots && data.shots.length > 1);
            applyBtn.disabled = !lastOk || action === 'apply';
            if (action === 'apply' && data.ok && data.resources && data.resources.length) {
                setStatus((data.message || 'Done.') + ' Resources: ' + data.resources.join(', '));
            }
            return data;
        }).catch(function (err) {
            detectBtn.disabled = false;
            applyBtn.disabled = !lastOk;
            setStatus(String(err));
        });
    }

    detectBtn.addEventListener('click', function () { post('detect'); });
    applyBtn.addEventListener('click', function () {
        if (!confirm(<?php echo json_encode($lang['image_sequence_split_shots_confirm'] ?? 'Split this sequence into the detected shots?'); ?>)) {
            return;
        }
        post('apply');
    });
})();
</script>
<?php
include '../../../include/footer.php';
