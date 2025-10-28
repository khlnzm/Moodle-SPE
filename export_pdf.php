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

// ---------------- PAGE MODE (safe HTML) ----------------
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
    echo html_writer::div(html_writer::link($dlurl, 'Start PDF download'), '', ['style' => 'margin-top:12px;']);
    echo html_writer::tag('script', "window.location.href = " . json_encode($dlurl->out(false)) . ";");

    echo $OUTPUT->footer();
    exit;
}

// --------------- DOWNLOAD MODE (no HTML) ---------------
if (class_exists('\core\session\manager')) { \core\session\manager::write_close(); }
while (ob_get_level()) { ob_end_clean(); }
ignore_user_abort(true);

$speid = (int)$cm->instance;

// Data: completed NLP rows
$rows = $DB->get_records_select('spe_sentiment', "speid = :speid AND status = 'done'", ['speid' => $speid]);
if (!$rows) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "No analyzed results found.";
    exit;
}

// Preload Disparity flags (keyed by "raterid->rateeid")
$disparitymap = [];
$mgr = $DB->get_manager();
if ($mgr->table_exists('spe_disparity')) {
    $drows = $DB->get_records('spe_disparity', ['speid' => $speid], '', 'raterid, rateeid, timecreated');
    foreach ($drows as $d) {
        $disparitymap[$d->raterid . '->' . $d->rateeid] = true;
    }
}

// Preload user names for efficiency
$usercache = [];
$needids   = [];
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

// Build PDF
$pdf = new pdf();
$pdf->SetCreator('Moodle SPE');
$pdf->SetAuthor('Moodle SPE Module');
$pdf->SetTitle('SPE Sentiment Results');
$pdf->SetMargins(15, 20, 15);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);

// Title
$pdf->Cell(0, 10, 'Structured Self & Peer Evaluation — Sentiment Results', 0, 1, 'C');
$pdf->Ln(5);

// Column widths (fits A4 portrait)
$wRater = 35;
$wRatee = 35;
$wType  = 20;
$wLabel = 22;
$wScore = 18;
$wDisp  = 22; // Disparity column (highlighted if "Yes")
// Excerpt uses remaining width with MultiCell(0, ...)

// Header row
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell($wRater, 8, 'Rater', 1);
$pdf->Cell($wRatee, 8, 'Target', 1);
$pdf->Cell($wType,  8, 'Type', 1);
$pdf->Cell($wLabel, 8, 'Label', 1);
$pdf->Cell($wScore, 8, 'Score', 1);
$pdf->Cell($wDisp,  8, 'Disparity', 1);
$pdf->Cell(0,      8, 'Excerpt', 1, 1);
$pdf->SetFont('helvetica', '', 10);

// Disparity cell fill color (light yellow)
$pdf->SetFillColor(255, 248, 179); // #FFF8B3

// Rows
foreach ($rows as $r) {
    $rater = $usercache[$r->raterid] ?? null;
    $ratee = $usercache[$r->rateeid] ?? null;

    $rname   = $rater ? fullname($rater) : (string)$r->raterid;
    $tname   = $ratee ? fullname($ratee) : (string)$r->rateeid;
    $label   = $r->label ? ucfirst((string)$r->label) : '-';
    $score   = isset($r->sentiment) ? sprintf('%.3f', (float)$r->sentiment) : '';

    $rawtext = (string)$r->text;
    $excerpt = core_text::substr(clean_text($rawtext), 0, 100)
             . (core_text::strlen($rawtext) > 100 ? '…' : '');

    $key      = $r->raterid . '->' . $r->rateeid;
    $hasDisp  = !empty($disparitymap[$key]);
    $dispText = $hasDisp ? 'Yes' : '';

    // Fixed-height cells then MultiCell for excerpt
    $pdf->Cell($wRater, 8, $rname, 1);
    $pdf->Cell($wRatee, 8, $tname, 1);
    $pdf->Cell($wType,  8, (string)$r->type, 1);
    $pdf->Cell($wLabel, 8, $label, 1);
    $pdf->Cell($wScore, 8, $score, 1);

    // Disparity cell (highlighted if Yes)
    $pdf->Cell($wDisp, 8, $dispText, 1, 0, 'C', $hasDisp);

    // Excerpt (takes remaining width)
    $pdf->MultiCell(0, 8, $excerpt, 1, 'L', false, 1);
}

// Spacing (optional)
$pdf->Ln(4);

// Stream
header('Cache-Control: private, must-revalidate');
header('Pragma: public');
$pdf->Output('spe_results.pdf', 'D');
exit;
