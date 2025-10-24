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

// Fetch data
$rows = $DB->get_records_select('spe_sentiment', "speid = :speid AND status = 'done'", ['speid' => $cm->instance]);

// Stream CSV
$filename = 'spe_results.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: private, must-revalidate');
header('Pragma: public');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel)
fputcsv($out, ['Rater','Target','Type','Label','Score','Excerpt']);

foreach ($rows as $r) {
    $rater   = $DB->get_record('user', ['id' => $r->raterid], 'firstname,lastname');
    $ratee   = $DB->get_record('user', ['id' => $r->rateeid], 'firstname,lastname');
    $rname   = $rater ? fullname($rater) : (string)$r->raterid;
    $tname   = $ratee ? fullname($ratee) : (string)$r->rateeid;
    $excerpt = core_text::substr(clean_text((string)$r->text), 0, 120) .
               (core_text::strlen((string)$r->text) > 120 ? '…' : '');

    fputcsv($out, [
        $rname,
        $tname,
        (string)$r->type,
        (string)$r->label,
        is_null($r->sentiment) ? '' : sprintf('%.3f', (float)$r->sentiment),
        $excerpt
    ]);
}
fclose($out);
exit;
