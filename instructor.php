<?php
// ============================================================================
//  SPE - Instructor Management & Reporting
//  Combined interface for:
//   (1) Approvals & queueing student submissions for sentiment analysis
//   (2) Grouped result view and CSV/PDF export
// ============================================================================

require('../../config.php');

$cmid = required_param('id', PARAM_INT);
$cm   = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// ---------------------------------------------------------------------------
// Page setup
// ---------------------------------------------------------------------------
$PAGE->set_url('/mod/spe/instructor.php', ['id' => $cm->id]);
$PAGE->set_title('SPE — Instructor Panel');
$PAGE->set_heading($course->fullname);

require_capability('mod/spe:manage', $context);
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/pdflib.php');

// Small helper to display a name without triggering fullname() developer notices.
$mkname = function(string $first = '', string $last = ''): string {
    return format_string(trim($first . ' ' . $last));
};

// ============================================================================
//  1. Handle Approval & Queueing
// ============================================================================

echo $OUTPUT->header();
echo $OUTPUT->heading('SPE — Instructor Management');

// Plain-link fallback so the actions are always visible (even if theme hides cards)
echo html_writer::alist([
    html_writer::link(
        new moodle_url('/mod/spe/analyze_push.php', ['id' => $cm->id, 'sesskey' => sesskey()]),
        'Run analysis now'
    ),
    html_writer::link(
        new moodle_url('/mod/spe/analysis_report.php', ['id' => $cm->id]),
        'Open Sentiment Analysis Report'
    ),
    html_writer::link(
        new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
        'Back to activity'
    ),
], ['class' => 'list-unstyled mb-3']);

$approveuserid = optional_param('approveuserid', 0, PARAM_INT);
if ($approveuserid && confirm_sesskey()) {
    // 1) Reflection (from spe_submission)
    if ($sub = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $approveuserid])) {
        $reflection = trim((string)$sub->reflection);
        if ($reflection !== '') {
            $exists = $DB->record_exists('spe_sentiment', [
                'speid' => $cm->instance,
                'raterid' => $approveuserid,
                'rateeid' => $approveuserid,
                'type' => 'reflection',
                'text' => $reflection
            ]);
            if (!$exists) {
                $DB->insert_record('spe_sentiment', (object)[
                    'speid' => $cm->instance,
                    'raterid' => $approveuserid,
                    'rateeid' => $approveuserid,
                    'type' => 'reflection',
                    'text' => $reflection,
                    'status' => 'pending',
                    'timecreated' => time()
                ]);
            }
        }
    }

    // 2) Peer comments
    $ratings = $DB->get_records('spe_rating', [
        'speid' => $cm->instance,
        'raterid' => $approveuserid
    ]);
    foreach ($ratings as $r) {
        $comment = trim((string)$r->comment);
        if ($comment === '') { continue; }

        // Avoid duplicate queue items for the exact same text & pair.
        $exists = $DB->record_exists('spe_sentiment', [
            'speid'   => $cm->instance,
            'raterid' => $approveuserid,
            'rateeid' => $r->rateeid,
            'type'    => 'peer_comment',
            'text'    => $comment
        ]);
        if (!$exists) {
            $DB->insert_record('spe_sentiment', (object)[
                'speid'       => $cm->instance,
                'raterid'     => $approveuserid,
                'rateeid'     => $r->rateeid,
                'type'        => 'peer_comment',
                'text'        => $comment,
                'status'      => 'pending',
                'timecreated' => time()
            ]);
        }
    }

    echo $OUTPUT->notification('Queued texts for analysis.', 'notifysuccess');
}

// ----------------------------------------------------------------------------
// Approvals table
// ----------------------------------------------------------------------------
echo html_writer::tag('h3', '1. Approvals & Queue for Analysis');

$students = $DB->get_records_sql("
    SELECT u.id, u.firstname, u.lastname, u.username, s.id AS subid,
           s.timecreated, s.timemodified
      FROM {user} u
      JOIN {spe_submission} s ON s.userid = u.id
     WHERE s.speid = :speid
  ORDER BY u.lastname, u.firstname
", ['speid' => $cm->instance]);

if ($students) {
    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::tag('tr',
        html_writer::tag('th', 'Student') .
        html_writer::tag('th', 'Submission Time') .
        html_writer::tag('th', 'Queued Items') .
        html_writer::tag('th', 'Action')
    );

    foreach ($students as $u) {
        $queued = $DB->count_records('spe_sentiment', [
            'speid' => $cm->instance,
            'raterid' => $u->id
        ]);

        $approveurl = new moodle_url('/mod/spe/instructor.php', [
            'id' => $cm->id, 'approveuserid' => $u->id, 'sesskey' => sesskey()
        ]);
        $btn = html_writer::link($approveurl, 'Approve & Queue', ['class' => 'btn btn-secondary']);

        echo html_writer::tag('tr',
            html_writer::tag('td', $mkname($u->firstname, $u->lastname) . " (" . s($u->username) . ")") .
            html_writer::tag('td', userdate($u->timemodified ?: $u->timecreated)) .
            html_writer::tag('td', (string)$queued) .
            html_writer::tag('td', $btn)
        );
    }
    echo html_writer::end_tag('table');
} else {
    echo $OUTPUT->notification('No student submissions yet.', 'notifyinfo');
}

// ============================================================================
//  2. Instructor Results & Export
// ============================================================================

echo html_writer::tag('h3', '2. Analysis Results & Exports');

// Capability check
require_capability('mod/spe:viewreports', $context);

// Buttons (top of section)
$csvurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id, 'export' => 'csv', 'sesskey' => sesskey()]);
$pdfurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id, 'export' => 'pdf', 'sesskey' => sesskey()]);

echo html_writer::start_div('spe-export-buttons', ['style' => 'margin:10px 0;']);
echo html_writer::link($csvurl, '⬇️ Download CSV', ['class' => 'btn btn-primary', 'style' => 'margin-right:8px;']);
echo html_writer::link($pdfurl, '🧾 Download PDF', ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

// ---------------------------------------------------------------------------
// Fetch all data (grouped by Moodle group)
//  - Join user tables once (avoid per-row lookups in exports/render)
// ---------------------------------------------------------------------------
$ratings = $DB->get_records_sql("
    SELECT r.id, r.speid, r.raterid, r.rateeid, r.criterion, r.score, r.comment, r.timecreated,
           s.label AS sentiment_label, s.sentiment AS sentiment_value,
           ur.firstname AS rater_first, ur.lastname AS rater_last,
           ue.firstname AS ratee_first, ue.lastname AS ratee_last
      FROM {spe_rating} r
 LEFT JOIN {spe_sentiment} s
        ON s.speid = r.speid AND s.raterid = r.raterid AND s.rateeid = r.rateeid
 LEFT JOIN {user} ur ON ur.id = r.raterid
 LEFT JOIN {user} ue ON ue.id = r.rateeid
     WHERE r.speid = :speid
  ORDER BY r.raterid, r.rateeid, r.criterion
", ['speid' => $cm->instance]);

if (!$ratings) {
    echo $OUTPUT->notification('No evaluations found yet.', 'notifyinfo');
    echo $OUTPUT->footer();
    exit;
}

// Get group memberships
$allgroups = groups_get_all_groups($course->id);
$usergroups = [];
foreach ($allgroups as $g) {
    $members = groups_get_members($g->id, 'u.id');
    foreach ($members as $m) {
        $usergroups[$m->id] = $g->name;
    }
}

// Organize ratings by group
$grouped = [];
foreach ($ratings as $r) {
    $group = $usergroups[$r->raterid] ?? 'Ungrouped';
    $grouped[$group][] = $r;
}

// ---------------------------------------------------------------------------
// Handle CSV export
// ---------------------------------------------------------------------------
$export = optional_param('export', '', PARAM_ALPHA);
if ($export === 'csv' && confirm_sesskey()) {
    $filename = clean_filename("spe_results_course{$course->id}.csv");
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Group', 'Rater', 'Ratee', 'Criterion', 'Score', 'Comment', 'Sentiment Label', 'Confidence']);
    foreach ($grouped as $gname => $rows) {
        foreach ($rows as $r) {
            $rater = $mkname($r->rater_first ?? '', $r->rater_last ?? '');
            $ratee = $mkname($r->ratee_first ?? '', $r->ratee_last ?? '');
            $conf  = sprintf('%.3f', ($r->sentiment_value !== null) ? ($r->sentiment_value + 1) / 2 : 0.5);
            fputcsv($out, [$gname, $rater, $ratee, $r->criterion, $r->score, $r->comment, $r->sentiment_label, $conf]);
        }
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Handle PDF export
// ---------------------------------------------------------------------------
if ($export === 'pdf' && confirm_sesskey()) {
    $filename = "SPE_Results_{$course->shortname}.pdf";
    $pdf = new pdf();
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Self & Peer Evaluation Results', 0, 1, 'C');
    $pdf->Ln(6);
    $pdf->SetFont('helvetica', '', 10);

    foreach ($grouped as $gname => $rows) {
        $pdf->SetFont('', 'B', 12);
        $pdf->Cell(0, 8, "Group: $gname", 0, 1);
        $pdf->SetFont('', '', 10);
        foreach ($rows as $r) {
            $rater = $mkname($r->rater_first ?? '', $r->rater_last ?? '');
            $ratee = $mkname($r->ratee_first ?? '', $r->ratee_last ?? '');
            $conf  = sprintf('%.2f', ($r->sentiment_value !== null) ? ($r->sentiment_value + 1) / 2 : 0.5);
            $line  = "Rater: $rater → Ratee: $ratee | Criterion: {$r->criterion} | Score: {$r->score} | Confidence: $conf\n";
            $line .= "Comment: {$r->comment}\n";
            $line .= "Sentiment: " . ($r->sentiment_label ?? '-') . "\n";
            $pdf->MultiCell(0, 6, $line, 0, 1);
            $pdf->Ln(2);
        }
        $pdf->Ln(4);
    }

    $pdf->Output($filename, 'D');
    exit;
}

// ---------------------------------------------------------------------------
// Render HTML table (grouped by Moodle group)
// ---------------------------------------------------------------------------
foreach ($grouped as $gname => $rows) {
    echo html_writer::tag('h4', 'Group: ' . $gname);
    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::tag('tr',
        html_writer::tag('th', 'Rater') .
        html_writer::tag('th', 'Ratee') .
        html_writer::tag('th', 'Criterion') .
        html_writer::tag('th', 'Score') .
        html_writer::tag('th', 'Comment') .
        html_writer::tag('th', 'Sentiment Label') .
        html_writer::tag('th', 'Confidence')
    );

    foreach ($rows as $r) {
        $rater = $mkname($r->rater_first ?? '', $r->rater_last ?? '');
        $ratee = $mkname($r->ratee_first ?? '', $r->ratee_last ?? '');
        $conf  = sprintf('%.3f', ($r->sentiment_value !== null) ? ($r->sentiment_value + 1) / 2 : 0.5);

        echo html_writer::tag('tr',
            html_writer::tag('td', $rater) .
            html_writer::tag('td', $ratee) .
            html_writer::tag('td', s($r->criterion)) .
            html_writer::tag('td', (int)$r->score) .
            html_writer::tag('td', s($r->comment)) .
            html_writer::tag('td', s($r->sentiment_label ?? '-')) .
            html_writer::tag('td', $conf)
        );
    }
    echo html_writer::end_tag('table');
    echo html_writer::empty_tag('hr');
}

echo $OUTPUT->footer();
