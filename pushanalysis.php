<?php
// mod/spe/pushanalysis.php
// Send pending spe_sentiment rows to the external FastAPI and store results.

require('../../config.php');
require_once($CFG->libdir . '/filelib.php'); // Moodle curl helper

$cmid = required_param('id', PARAM_INT);
$cm   = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

$PAGE->set_url('/mod/spe/pushanalysis.php', ['id' => $cm->id]);
$PAGE->set_title('SPE — Push to Sentiment API');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('SPE — Push to Sentiment API');

// Gather pending rows for this activity
$pendings = $DB->get_records('spe_sentiment', ['speid' => $cm->instance, 'status' => 'pending']);
if (!$pendings) {
    echo $OUTPUT->notification('No pending items to analyze.', 'notifyinfo');
    echo $OUTPUT->footer();
    exit;
}

// Prepare batch payload
$items = [];
foreach ($pendings as $row) {
    $items[] = ['id' => (string)$row->id, 'text' => (string)$row->text];
}

// Endpoint & token from plugin settings
$cfgurl   = trim((string)get_config('mod_spe', 'sentiment_url'));
$cfgtoken = trim((string)get_config('mod_spe', 'sentiment_token')); // optional

if ($cfgurl === '') {
    echo $OUTPUT->notification('Sentiment API URL is not configured (mod_spe/sentiment_url).', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

$payload = json_encode(['items' => $items, 'token' => null]); // prefer header token
$curl = new curl();
$headers = ['Content-Type: application/json'];
if ($cfgtoken !== '') {
    $headers[] = 'X-API-TOKEN: ' . $cfgtoken;
}

try {
    $response = $curl->post($cfgurl, $payload, [
        'HEADER' => $headers,
        'TIMEOUT' => 30
    ]);
} catch (Exception $e) {
    echo $OUTPUT->notification('HTTP error contacting Sentiment API: ' . s($e->getMessage()), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// Parse response: { ok: bool, results: [{id,label,score,compound,pos,neu,neg,toxic}] }
$data = json_decode($response);
if (!is_object($data) || !property_exists($data, 'ok')) {
    echo $OUTPUT->notification('Invalid response from Sentiment API.', 'notifyproblem');
    echo html_writer::tag('pre', s($response));
    echo $OUTPUT->footer();
    exit;
}

if (!$data->ok) {
    echo $OUTPUT->notification('Sentiment API rejected the request (ok=false). Check token or server logs.', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// Update rows
$updated = 0;
foreach ($data->results as $res) {
    $id = (int)$res->id;
    if (!$row = $DB->get_record('spe_sentiment', ['id' => $id, 'speid' => $cm->instance])) {
        continue;
    }
    // Store main outputs we have columns for: label + compound as "sentiment"
    $row->label        = (string)$res->label;
    $row->sentiment    = (float)$res->compound; // store VADER compound in NUMBER column
    $row->status       = 'done';
    $row->timemodified = time();
    $DB->update_record('spe_sentiment', $row);
    $updated++;
}

echo $OUTPUT->notification("Analyzed & updated {$updated} item(s).", 'notifysuccess');

// Links back
$instrurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
$resurl   = new moodle_url('/mod/spe/results.php', ['id' => $cm->id]);
echo html_writer::tag('p',
    html_writer::link($instrurl, 'Back to Instructor') . ' | ' .
    html_writer::link($resurl, 'View Results')
);

echo $OUTPUT->footer();
