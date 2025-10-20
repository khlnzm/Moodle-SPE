<?php
// mod/spe/analyze_push.php
// Pull pending rows from spe_sentiment, call FastAPI /analyze, persist results.

require('../../config.php');
require_once($CFG->libdir . '/filelib.php'); // Moodle curl helper

$cmid  = required_param('id', PARAM_INT);
$limit = optional_param('limit', 200, PARAM_INT); // batch size safeguard
require_sesskey(); // ✅ protect the action

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

$PAGE->set_url('/mod/spe/analyze_push.php', ['id' => $cm->id, 'limit' => $limit, 'sesskey' => sesskey()]);
$PAGE->set_title('Analyze Pending Texts');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Analyze Pending Texts');

// ---- Config
$cfgurl   = trim((string)get_config('mod_spe', 'sentiment_url'));    // e.g. http://127.0.0.1:8000/analyze
$cfgtoken = trim((string)get_config('mod_spe', 'sentiment_token'));  // optional

if ($cfgurl === '') {
    echo $OUTPUT->notification('Sentiment endpoint not configured. Set mod_spe → sentiment_url.', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// ---- 1) Fetch pending items (bounded)
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

// ---- 2) Build payload
$items = [];
foreach ($rows as $r) {
    $items[] = ['id' => (string)$r->id, 'text' => (string)$r->text];
}
$payload = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);

// ---- 3) Call FastAPI /analyze
$curl = new curl();
$headers = ['Content-Type: application/json'];
if ($cfgtoken !== '') {
    $headers[] = 'X-Api-Token: ' . $cfgtoken; // ✅ standardize header
}
$curl->setHeader($headers);

$options = [
    'timeout' => 30,
    'CURLOPT_HTTPHEADER' => $headers,
    'RETURNTRANSFER' => true,
];

try {
    $response = $curl->post($cfgurl, $payload, $options);
} catch (Exception $e) {
    echo $OUTPUT->notification('HTTP error calling sentiment API: ' . s($e->getMessage()), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// ---- 4) Parse + persist
$data = json_decode($response);
if (!is_object($data) || empty($data->ok) || !isset($data->results) || !is_array($data->results)) {
    echo $OUTPUT->notification('Invalid response from sentiment API.', 'notifyproblem');
    echo html_writer::tag('pre', s($response));
    echo $OUTPUT->footer();
    exit;
}

// Accept either results[*].score OR results[*].compound
$updated = 0;
foreach ($data->results as $res) {
    if (!is_object($res) || !isset($res->id)) { continue; }
    $id = (int)$res->id;

    $rec = $DB->get_record('spe_sentiment', ['id' => $id, 'speid' => $cm->instance], '*', IGNORE_MISSING);
    if (!$rec) { continue; }

    $label = isset($res->label) ? (string)$res->label : null;
    $score = null;
    if (isset($res->score)) {
        $score = (float)$res->score;
    } else if (isset($res->compound)) {
        $score = (float)$res->compound;
    }

    $rec->label        = $label ? substr($label, 0, 20) : null;
    $rec->sentiment    = $score; // NUMBER(10,4) permits NULL or float
    $rec->status       = 'done';
    $rec->timemodified = time();
    $DB->update_record('spe_sentiment', $rec);
    $updated++;
}

echo $OUTPUT->notification("Analyzed and updated {$updated} item(s).", 'notifysuccess');

$links = html_writer::alist([
    html_writer::link(new moodle_url('/mod/spe/analysis_report.php', ['id' => $cm->id]), 'View analysis report'),
    html_writer::link(new moodle_url('/mod/spe/analyze_push.php', ['id' => $cm->id, 'sesskey' => sesskey()]), 'Analyze more'),
], [], 'ul');
echo $links;

echo $OUTPUT->continue_button(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]));
echo $OUTPUT->footer();
