<?php
// mod/spe/export_csv.php
// Page mode: shows Back + "Start CSV download" link
// Download mode (?download=1): streams CSV with no HTML/whitespace

require('../../config.php');

$cmid     = required_param('id', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Allow any of these caps for export (admin/teachers)
$allowedcaps = ['mod/spe:viewresults', 'mod/spe:viewreports', 'mod/spe:manage'];
if (!has_any_capability($allowedcaps, $context)) {
    require_capability('mod/spe:viewresults', $context);
}

/* -------- determine Back target (same as PDF) -------- */
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
if (!$returnurl) {
    $ref = get_local_referer(false); // returns local URL or false
    $returnurl = $ref ? $ref : (new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]))->out(false);
}

/* ---------------- PAGE MODE (safe HTML) ---------------- */
if (!$download) {
    $PAGE->set_url('/mod/spe/export_csv.php', ['id' => $cm->id, 'returnurl' => $returnurl]);
    $PAGE->set_context($context);
    $PAGE->set_title('SPE — Export CSV');
    $PAGE->set_heading(format_string($course->fullname));

    echo $OUTPUT->header();
    echo $OUTPUT->heading('Analysis Results & Exports', 2);

    // ← Back link (plain)
    echo html_writer::div(
        html_writer::link(new moodle_url($returnurl), '← Back'),
        '',
        ['style' => 'margin-bottom:12px;']
    );

    $dlurl = new moodle_url('/mod/spe/export_csv.php', ['id' => $cm->id, 'download' => 1]);
    echo html_writer::div(html_writer::link($dlurl, 'Start CSV download'), '', ['style' => 'margin-top:12px;']);
    echo html_writer::tag('script', "window.location.href = " . json_encode($dlurl->out(false)) . ";");

    echo $OUTPUT->footer();
    exit;
}

/* --------------- DOWNLOAD MODE (no HTML) --------------- */
if (class_exists('\core\session\manager')) { \core\session\manager::write_close(); }
while (ob_get_level()) { ob_end_clean(); }
ignore_user_abort(true);

$speid = (int)$cm->instance;

/* --- Fetch NLP rows (completed) --- */
$rows = $DB->get_records_select(
    'spe_sentiment',
    "speid = :speid AND status = 'done'",
    ['speid' => $speid]
);

/* --- Preload disparity flags keyed by "raterid->rateeid" --- */
$disparitymap = [];
$mgr = $DB->get_manager();
if ($mgr->table_exists('spe_disparity')) {
    $drows = $DB->get_records('spe_disparity', ['speid' => $speid], '', 'raterid, rateeid');
    foreach ($drows as $d) {
        $disparitymap[$d->raterid . '->' . $d->rateeid] = true;
    }
}

/* --- Preload users (rater + ratee) --- */
$usercache = [];
$needids = [];
foreach ($rows as $r) {
    $needids[$r->raterid] = true;
    $needids[$r->rateeid] = true;
}
if (!empty($needids)) {
    list($insql, $inparams) = $DB->get_in_or_equal(array_keys($needids), SQL_PARAMS_NAMED);
    $users = $DB->get_records_select('user', "id $insql", $inparams, '', 'id, firstname, lastname');
    foreach ($users as $u) {
        $usercache[$u->id] = $u;
    }
}

/* --- Stream CSV --- */
$filename = 'spe_results.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: private, must-revalidate');
header('Pragma: public');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel-compatible)

/* Header now includes Disparity column */
fputcsv($out, ['Rater','Target','Type','Label','Score','Excerpt','Disparity']);

foreach ($rows as $r) {
    // Names
    $rater = $usercache[$r->raterid] ?? null;
    $ratee = $usercache[$r->rateeid] ?? null;

    $rname = $rater ? fullname($rater) : (string)$r->raterid;
    $tname = $ratee ? fullname($ratee) : (string)$r->rateeid;

    // Excerpt
    $rawtext = (string)$r->text;
    $excerpt = core_text::substr(clean_text($rawtext), 0, 120) .
               (core_text::strlen($rawtext) > 120 ? '…' : '');

    // Disparity flag
    $key  = $r->raterid . '->' . $r->rateeid;
    $disp = isset($disparitymap[$key]) ? 'Yes' : '';

    fputcsv($out, [
        $rname,
        $tname,
        (string)$r->type,
        (string)$r->label,
        is_null($r->sentiment) ? '' : sprintf('%.3f', (float)$r->sentiment),
        $excerpt,
        $disp
    ]);
}

fclose($out);
exit;
