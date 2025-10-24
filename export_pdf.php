<?php
// mod/spe/export_pdf.php
// Page mode: shows Back + "Start PDF download" link
// Download mode (?download=1): streams PDF with no HTML/whitespace

require('../../config.php');
require_once($CFG->libdir . '/pdflib.php');

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

// Back target
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
if (!$returnurl) {
    $ref = get_local_referer(false);
    $returnurl = $ref ? $ref : (new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]))->out(false);
}

// PAGE MODE
if (!$download) {
    $PAGE->set_url('/mod/spe/export_pdf.php', ['id' => $cm->id, 'returnurl' => $returnurl]);
    $PAGE->set_context($context);
    $PAGE->set_title('SPE — Export PDF');
    $PAGE->set_heading(format_string($course->fullname));

    echo $OUTPUT->header();
    echo $OUTPUT->heading('Analysis Results & Exports', 2);

    echo html_writer::div(
        html_writer::link(new moodle_url($returnurl), '← Back'),
        '',
        ['style' => 'margin-bottom:12px;']
    );

    $dlurl = new moodle_url('/mod/spe/export_pdf.php', ['id' => $cm->id, 'download' => 1]);
    echo html_writer::div(html_writer::link($dlurl, 'Start PDF download'), '', ['style'=>'margin-top:12px;']);
    echo html_writer::tag('script', "window.location.href = ".json_encode($dlurl->out(false)).";");

    echo $OUTPUT->footer();
    exit;
}

// DOWNLOAD MODE (no HTML/whitespace)
if (class_exists('\core\session\manager')) { \core\session\manager::write_close(); }
while (ob_get_level()) { ob_end_clean(); }
ignore_user_abort(true);

// Data
$rows = $DB->get_records_select('spe_sentiment', "speid = :speid AND status = 'done'", ['speid' => $cm->instance]);
if (!$rows) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "No analyzed results found.";
    exit;
}

// Build PDF
$pdf = new pdf();
$pdf->SetCreator('Moodle SPE');
$pdf->SetAuthor('Moodle SPE Module');
$pdf->SetTitle('SPE Sentiment Results');
$pdf->SetMargins(15, 20, 15);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);

$pdf->Cell(0, 10, 'Structured Self & Peer Evaluation — Sentiment Results', 0, 1, 'C');
$pdf->Ln(5);

// Header row
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(40, 8, 'Rater', 1);
$pdf->Cell(40, 8, 'Target', 1);
$pdf->Cell(25, 8, 'Type', 1);
$pdf->Cell(25, 8, 'Label', 1);
$pdf->Cell(25, 8, 'Score', 1);
$pdf->Cell(0, 8, 'Excerpt', 1, 1);
$pdf->SetFont('helvetica', '', 10);

// Rows
foreach ($rows as $r) {
    $rater   = $DB->get_record('user', ['id' => $r->raterid], 'firstname,lastname');
    $ratee   = $DB->get_record('user', ['id' => $r->rateeid], 'firstname,lastname');
    $rname   = $rater ? fullname($rater) : (string)$r->raterid;
    $tname   = $ratee ? fullname($ratee) : (string)$r->rateeid;
    $label   = ucfirst((string)$r->label);
    $excerpt = core_text::substr(clean_text((string)$r->text), 0, 100) .
               (core_text::strlen((string)$r->text) > 100 ? '…' : '');

    $pdf->Cell(40, 8, $rname, 1);
    $pdf->Cell(40, 8, $tname, 1);
    $pdf->Cell(25, 8, (string)$r->type, 1);
    $pdf->Cell(25, 8, $label, 1);
    $pdf->Cell(25, 8, sprintf('%.3f', (float)$r->sentiment), 1);
    $pdf->MultiCell(0, 8, $excerpt, 1, 'L', false, 1);
}

// Stream
header('Cache-Control: private, must-revalidate');
header('Pragma: public');
$pdf->Output('spe_results.pdf', 'D');
exit;
