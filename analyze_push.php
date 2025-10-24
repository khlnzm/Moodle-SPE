<?php
// ============================================================================
// SPE - Analyze pending texts via FastAPI (batch)
// - Separates the rendered results into "Reflection" and "Peer comments"
// - Updates spe_sentiment.{sentiment,label,status}
// ============================================================================

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Only teachers/managers
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
    // Back to instructor
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(
        html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

// Prepare FastAPI call
require_once($CFG->libdir . '/filelib.php');
$apiurl   = trim((string)get_config('mod_spe', 'sentiment_url'));
$apitoken = trim((string)get_config('mod_spe', 'sentiment_token')); // optional

if ($apiurl === '') {
    echo $OUTPUT->notification('Sentiment API URL is not configured in plugin settings.', 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(
        html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

// Build batch payload
$items = [];
foreach ($pendings as $row) {
    $items[] = ['id' => (string)$row->id, 'text' => (string)$row->text];
}

// Safety limit (huge payloads)
if (count($items) > 2000) {
    $items = array_slice($items, 0, 2000);
}

$payload = json_encode([
    'items' => $items,
    // token can be sent in body or header; app.py accepts either
    'token' => ($apitoken !== '' ? $apitoken : null),
]);

$curl = new curl();
$headers = ['Content-Type: application/json'];
if ($apitoken !== '') {
    $headers[] = 'X-API-TOKEN: ' . $apitoken;
}

try {
    $resp = $curl->post($apiurl . (strpos($apiurl, '/analyze') === false ? '/analyze' : ''), $payload, [
        'CURLOPT_HTTPHEADER' => $headers,
        'CURLOPT_TIMEOUT'    => 60,
    ]);
} catch (Exception $e) {
    echo $OUTPUT->notification('Error contacting Sentiment API: ' . s($e->getMessage()), 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(
        html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

// Parse response (supports unified/single fallback)
$data = json_decode($resp);
if (!$data) {
    echo $OUTPUT->notification('Invalid response from Sentiment API.', 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(
        html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

// If a single object came back, normalize into the expected shape.
if (is_object($data) && isset($data->label) && !isset($data->ok)) {
    // We sent multiple items, but API returned single — map it
    $data = (object)[
        'ok'      => true,
        'results' => [(object)array_merge(['id' => (string)$items[0]['id']], (array)$data)]
    ];
}

// Defensive checks
if (!is_object($data) || !property_exists($data, 'results') || !is_array($data->results)) {
    echo $OUTPUT->notification('Unexpected response format from Sentiment API.', 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(
        html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
// Update database & render two sections: reflections vs peer comments
// ---------------------------------------------------------------------------

$processedids = [];
foreach ($data->results as $res) {
    $id = (int)$res->id;
    if (!$id) { continue; }
    $processedids[] = $id;

    if ($row = $DB->get_record('spe_sentiment', ['id' => $id, 'speid' => $cm->instance])) {
        // The FastAPI app returns: compound (float), label (string), score (0..1), toxic (bool)
        $compound = isset($res->compound) ? (float)$res->compound : 0.0;
        $label    = isset($res->label)    ? (string)$res->label    : '-';

        $row->sentiment    = $compound;
        $row->label        = $label;  // may be "positive", "neutral", "negative", or "toxic"
        $row->status       = 'done';
        $row->timemodified = time();
        $DB->update_record('spe_sentiment', $row);
    }
}

if (!$processedids) {
    echo $OUTPUT->notification('No items were processed.', 'notifyinfo');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(
        html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

// Fetch updated rows to display (only those we just processed)
list($insql, $inparams) = $DB->get_in_or_equal($processedids, SQL_PARAMS_NAMED, 's');
$rows = $DB->get_records_select('spe_sentiment', "id $insql", $inparams, 'type, raterid, rateeid, id');

if (!$rows) {
    echo $OUTPUT->notification('Processed, but no rows to show.', 'notifyinfo');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div(
        html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

// Build user cache for display names
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

// Split rows by type
$reflections   = [];
$peercomments  = [];
foreach ($rows as $r) {
    if ($r->type === 'reflection') $reflections[] = $r;
    else                          $peercomments[] = $r;
}

// Summary
echo html_writer::div(
    'Processed ' . count($processedids) . ' items — ' .
    count($reflections) . ' reflection(s), ' .
    count($peercomments) . ' peer comment(s).',
    'alert alert-success'
);

// Simple badge for label
$badge = function (string $label): string {
    $style = 'background:#6c757d;';
    if ($label === 'positive') $style = 'background:#1a7f37;';
    if ($label === 'negative') $style = 'background:#b42318;';
    if ($label === 'toxic')    $style = 'background:#000;'; // toxic = black
    return '<span style="display:inline-block;color:#fff;padding:2px 8px;border-radius:12px;font-size:12px;'.$style.'">'.s($label).'</span>';
};

// Render a table helper
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

        $compound = is_null($r->sentiment) ? '' : sprintf('%.3f', (float)$r->sentiment);
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

// Show tables
$render_table('Reflection',  $reflections);
$render_table('Peer comments', $peercomments);

// Back to instructor
$back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
echo html_writer::div(
    html_writer::link($back, '← Back to Instructor', ['class' => 'btn btn-secondary']),
    'mt-3'
);

echo $OUTPUT->footer();
