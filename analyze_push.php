<?php
// ============================================================================
// SPE - Analyze pending texts via FastAPI (batch)
// - Splits display into "Reflection" and "Peer comments"
// - Updates spe_sentiment.{sentiment,label,status}
// - Uses X-API-Token header (from plugin setting 'sentiment_api_token')
//   and API URL from 'sentiment_live_url' (e.g., http://127.0.0.1:8000/analyze)
// - If the API is not reachable, attempt to bootstrap it (Windows) via api_boot.php
//   and wait briefly for readiness.
// ============================================================================

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

$PAGE->set_url('/mod/spe/analyze_push.php', ['id' => $cm->id]);
$PAGE->set_title('SPE — Run Sentiment Analysis');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading('Run sentiment analysis');

// ---------------------------------------------------------------------------
// Fetch all PENDING rows for this SPE
// ---------------------------------------------------------------------------
$pendings = $DB->get_records('spe_sentiment', [
    'speid'  => $cm->instance,
    'status' => 'pending'
], 'timecreated ASC', 'id, speid, raterid, rateeid, type, text, timecreated');

if (!$pendings) {
    echo $OUTPUT->notification('Nothing to analyze — there are no pending items for this activity.', 'notifyinfo');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
// Prepare FastAPI endpoint + probe helpers
// ---------------------------------------------------------------------------
require_once($CFG->libdir . '/filelib.php');

/**
 * Quick API probe: checks /openapi.json or OPTIONS /analyze for 200 response.
 */
function spe_probe_api(string $apiurl): bool {
    $curl = new curl();
    $base = rtrim($apiurl, '/');
    $probe = preg_replace('#/analyze/?$#', '', $base) . '/openapi.json';

    // Try GET /openapi.json
    try {
        $resp = $curl->get($probe, ['timeout' => 5]);
        $info = $curl->get_info();
        if (!empty($info['http_code']) && (int)$info['http_code'] === 200) {
            return true;
        }
    } catch (Exception $e) { }

    // Try OPTIONS /analyze
    try {
        $resp = $curl->options($base, ['timeout' => 5]);
        $info = $curl->get_info();
        if (!empty($info['http_code']) && (int)$info['http_code'] === 200) {
            return true;
        }
    } catch (Exception $e) { }

    return false;
}

/**
 * Attempt to bootstrap FastAPI (Windows) using api_boot.php.
 */
function spe_bootstrap_api_if_needed(string $apiurl, int $cmid): array {
    global $CFG;

    if (spe_probe_api($apiurl)) {
        return [true, 'API already running.'];
    }

    $boot = $CFG->dirroot . '/mod/spe/api_boot.php';
    if (file_exists($boot)) {
        require_once($boot);
        if (function_exists('spe_start_sentiment_api')) {
            list($ok, $msg) = spe_start_sentiment_api($cmid);

            $deadline = time() + 8;
            while (time() < $deadline) {
                if (spe_probe_api($apiurl)) {
                    return [true, $ok ? $msg : 'API started successfully after probe.'];
                }
                usleep(300 * 1000);
            }
            return [false, 'Attempted to start API but it did not become ready. ' . $msg];
        }
    }

    return [false, 'API is not reachable and no bootstrap helper is available.'];
}

// ---------------------------------------------------------------------------
// Load URL and Token (with fallback default for localhost)
// ---------------------------------------------------------------------------
$apiurl   = trim((string)get_config('spe', 'sentiment_live_url'));
$apitoken = trim((string)get_config('spe', 'sentiment_api_token'));

if ($apiurl === '') {
    // Default to localhost if not configured
    $apiurl = 'http://127.0.0.1:8000/analyze';
}
if (strpos($apiurl, '/analyze') === false) {
    $apiurl = rtrim($apiurl, '/') . '/analyze';
}

// Ensure API is up (try to start if needed)
list($ready, $bootmsg) = spe_bootstrap_api_if_needed($apiurl, $cm->id);
if (!$ready) {
    echo $OUTPUT->notification('Sentiment API is not reachable. ' . s($bootmsg), 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
// Build batch payload
// ---------------------------------------------------------------------------
$items = [];
foreach ($pendings as $row) {
    $items[] = [
        'id'   => (string)$row->id,
        'text' => (string)$row->text,
    ];
}
if (count($items) > 2000) {
    $items = array_slice($items, 0, 2000);
}
$payload = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------------------------
// Call FastAPI with token header
// ---------------------------------------------------------------------------
$curl = new curl();
$headers = ['Content-Type: application/json'];
if ($apitoken !== '') {
    $headers[] = 'X-API-Token: ' . $apitoken;
}

try {
    $resp = $curl->post($apiurl, $payload, [
        'CURLOPT_HTTPHEADER' => $headers,
        'timeout'            => 60,
        'CURLOPT_TIMEOUT'    => 60,
    ]);
    $info = $curl->get_info();
    $http = isset($info['http_code']) ? (int)$info['http_code'] : 0;
} catch (Exception $e) {
    echo $OUTPUT->notification('Error contacting Sentiment API: ' . s($e->getMessage()), 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

if ($resp === false || $http >= 400 || $http === 0) {
    $msg = 'Sentiment API returned HTTP ' . $http . '.';
    if ($http === 403) {
        $msg .= ' (Forbidden — check X-API-Token vs SPE_API_TOKEN)';
    }
    echo $OUTPUT->notification($msg, 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
// Parse response
// ---------------------------------------------------------------------------
$data = json_decode($resp);
if ($data === null || (json_last_error() !== JSON_ERROR_NONE)) {
    echo $OUTPUT->notification('Unexpected (non-JSON) response from Sentiment API.', 'notifyproblem');
    echo html_writer::tag('pre', s(substr($resp, 0, 400)));
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// Normalize to batch shape if single result
if (is_object($data) && isset($data->label) && !isset($data->ok)) {
    $data = (object)[
        'ok'      => true,
        'results' => [(object)array_merge(['id' => (string)$items[0]['id']], (array)$data)]
    ];
}

// ---------------------------------------------------------------------------
// Handle API failure case
// ---------------------------------------------------------------------------
if (is_object($data) && property_exists($data, 'ok') && $data->ok === false) {
    echo $OUTPUT->notification(
        'Sentiment API rejected the batch (likely token mismatch). Check "sentiment_api_token" and server SPE_API_TOKEN.',
        'notifyproblem'
    );
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

if (!isset($data->results) || !is_array($data->results)) {
    echo $OUTPUT->notification('Unexpected response format from Sentiment API.', 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
// Update database & render
// ---------------------------------------------------------------------------
$processedids = [];

foreach ($data->results as $res) {
    $id = (int)($res->id ?? 0);
    if (!$id) { continue; }
    $processedids[] = $id;

    if ($row = $DB->get_record('spe_sentiment', ['id' => $id, 'speid' => $cm->instance])) {
        $compound = isset($res->compound) ? (float)$res->compound : 0.0;
        $label    = isset($res->label) ? (string)$res->label : '-';

        $row->sentiment    = $compound;
        $row->label        = $label;
        $row->status       = 'done';
        $row->timemodified = time();
        $DB->update_record('spe_sentiment', $row);
    }
}

if (!$processedids) {
    echo $OUTPUT->notification('No items were processed (check API token).', 'notifyinfo');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
// Display summary
// ---------------------------------------------------------------------------
list($insql, $inparams) = $DB->get_in_or_equal($processedids, SQL_PARAMS_NAMED, 's');
$rows = $DB->get_records_select('spe_sentiment', "id $insql", $inparams, 'type, raterid, rateeid, id');

$userids = [];
foreach ($rows as $r) {
    $userids[$r->raterid] = true;
    $userids[$r->rateeid] = true;
}
$users = [];
if ($userids) {
    list($uinsql, $uinparams) = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'u');
    $users = $DB->get_records_select('user', "id $uinsql", $uinparams, '', 'id, firstname, lastname, username');
}

// Split reflections vs comments
$reflections  = [];
$peercomments = [];
foreach ($rows as $r) {
    if ($r->type === 'reflection') { $reflections[] = $r; }
    else { $peercomments[] = $r; }
}

echo html_writer::div(
    'Processed ' . count($processedids) . ' items — ' .
    count($reflections) . ' reflection(s), ' .
    count($peercomments) . ' peer comment(s).',
    'alert alert-success'
);

// Label badge
$badge = function (string $label): string {
    $style = 'background:#6c757d;';
    if ($label === 'positive') $style = 'background:#1a7f37;';
    if ($label === 'negative') $style = 'background:#b42318;';
    if ($label === 'toxic')    $style = 'background:#000;';
    return '<span style="color:#fff;padding:2px 8px;border-radius:12px;font-size:12px;'.$style.'">'.s($label).'</span>';
};

// Render helper
$render_table = function(string $title, array $data) use ($users, $badge) {
    echo html_writer::tag('h3', $title);
    if (!$data) {
        echo html_writer::div('None.', 'muted');
        return;
    }
    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::tag('tr',
        html_writer::tag('th', 'Rater') .
        html_writer::tag('th', 'Target') .
        html_writer::tag('th', 'Type') .
        html_writer::tag('th', 'Label') .
        html_writer::tag('th', 'Score (compound)') .
        html_writer::tag('th', 'Excerpt')
    );
    foreach ($data as $r) {
        $rater = $users[$r->raterid] ?? null;
        $ratee = $users[$r->rateeid] ?? null;
        $rname = $rater ? fullname($rater) . " ({$rater->username})" : $r->raterid;
        $tname = $ratee ? fullname($ratee) . " ({$ratee->username})" : $r->rateeid;

        $compound = sprintf('%.3f', (float)$r->sentiment);
        $excerpt = s(core_text::substr(clean_text((string)$r->text), 0, 120)) .
                   (core_text::strlen((string)$r->text) > 120 ? '…' : '');

        echo html_writer::tag('tr',
            html_writer::tag('td', $rname) .
            html_writer::tag('td', $tname) .
            html_writer::tag('td', s($r->type)) .
            html_writer::tag('td', $badge((string)$r->label)) .
            html_writer::tag('td', $compound) .
            html_writer::tag('td', $excerpt)
        );
    }
    echo html_writer::end_tag('table');
    echo html_writer::empty_tag('hr');
};

$render_table('Reflection', $reflections);
$render_table('Peer comments', $peercomments);

$back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
echo html_writer::div(html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']), 'mt-3');
echo $OUTPUT->footer();
