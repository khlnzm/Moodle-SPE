<?php
// mod/spe/analyze_push.php
require('../../config.php');
require_once($CFG->libdir . '/filelib.php'); // curl

$cmid      = required_param('id', PARAM_INT);
$limit     = optional_param('limit', 200, PARAM_INT); // batch size safeguard

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

$PAGE->set_url('/mod/spe/analyze_push.php', ['id' => $cm->id, 'limit' => $limit]);
$PAGE->set_title('Analyze Pending Texts');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Analyze Pending Texts');

$cfgurl   = get_config('mod_spe', 'sentiment_url');   // e.g., http://127.0.0.1:8000/analyze
$cfgtoken = get_config('mod_spe', 'sentiment_token'); // optional shared secret

if (empty($cfgurl)) {
    echo $OUTPUT->notification('Sentiment endpoint not configured. Set mod_spe → sentiment_url.', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// 1) Pull pending rows for this activity.
$sql = "SELECT *
          FROM {spe_sentiment}
         WHERE speid = :speid AND status = :status
         ORDER BY timecreated ASC";
$params = ['speid' => $cm->instance, 'status' => 'pending'];

$rows = $DB->get_records_sql($sql, $params, 0, max(1, min($limit, 1000)));
if (!$rows) {
    echo $OUTPUT->notification('No pending items to analyze.', 'notifywarning');
    echo $OUTPUT->continue_button(new moodle_url('/mod/spe/analysis_report.php', ['id' => $cm->id]));
    echo $OUTPUT->footer();
    exit;
}

// 2) Prepare batch payload
$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id'   => (string)$r->id,
        'text' => (string)$r->text
    ];
}

$payload = ['items' => $items];
if (!empty($cfgtoken)) {
    $payload['token'] = $cfgtoken;
}

// 3) Call FastAPI /analyze
$curl = new curl();
$headers = ['Content-Type: application/json'];
if (!empty($cfgtoken)) {
    $headers[] = 'X-Api-Token: ' . $cfgtoken;
}
$options = ['CURLOPT_TIMEOUT' => 30];

$response = $curl->post($cfgurl, json_encode($payload), $options, $headers);
if ($curl->get_errno()) {
    echo $OUTPUT->notification('HTTP error calling sentiment API: ' . s($curl->error), 'notifyproblem');
    echo html_writer::tag('pre', s($response));
    echo $OUTPUT->footer();
    exit;
}

$data = json_decode($response);
if (!$data || !isset($data->ok)) {
    echo $OUTPUT->notification('Invalid response from sentiment API.', 'notifyproblem');
    echo html_writer::tag('pre', s($response));
    echo $OUTPUT->footer();
    exit;
}

if (!$data->ok) {
    echo $OUTPUT->notification('Sentiment API rejected the request (check token?).', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// 4) Persist results back into spe_sentiment
$updated = 0;
foreach ($data->results as $res) {
    $id = (int)$res->id;
    if (!$id) { continue; }

    $rec = $DB->get_record('spe_sentiment', ['id' => $id, 'speid' => $cm->instance], '*', IGNORE_MISSING);
    if (!$rec) { continue; }

    // Store label + score into existing columns, mark done.
    $rec->label        = substr((string)$res->label, 0, 20);
    $rec->sentiment    = isset($res->score) ? (float)$res->score : null; // 0..1 confidence-ish
    $rec->status       = 'done';
    $rec->timemodified = time();
    $DB->update_record('spe_sentiment', $rec);
    $updated++;
}

echo $OUTPUT->notification("Analyzed and updated $updated item(s).", 'notifysuccess');

$links = html_writer::alist([
    html_writer::link(new moodle_url('/mod/spe/analysis_report.php', ['id' => $cm->id]), 'View analysis report'),
    html_writer::link(new moodle_url('/mod/spe/analyze_push.php', ['id' => $cm->id]), 'Analyze more')
], [], 'ul');
echo $links;

echo $OUTPUT->continue_button(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]));
echo $OUTPUT->footer();
