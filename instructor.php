<?php
// ============================================================================
//  SPE - Instructor Management & Reporting
//  (1) Approvals & queueing student submissions for sentiment analysis
//  (2) Grouped result view and links to CSV/PDF export pages
// ============================================================================

require('../../config.php');

$cmid = required_param('id', PARAM_INT);
$cm   = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

// ----------------------------------------------------------------------------
// Page setup
// ----------------------------------------------------------------------------
$PAGE->set_url('/mod/spe/instructor.php', ['id' => $cm->id]);
$PAGE->set_title('SPE — Instructor Panel');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('spe-compact spe-left');

// Header buttons (top-right)
$btns = [];

// Teacher/manager: Group Approve
$btns[] = $OUTPUT->single_button(
    new moodle_url('/mod/spe/group_approve.php', ['id' => $cm->id]),
    'Approve Group Scores',
    'get'
);

// Admin-only: Reset All
$sysctx = context_system::instance();
if (is_siteadmin() || has_capability('moodle/site:config', $sysctx)) {
    $btns[] = $OUTPUT->single_button(
        new moodle_url('/mod/spe/admin_reset_all.php', ['id' => $cm->id]),
        'Admin: Reset ALL',
        'get'
    );
}
$PAGE->set_button(implode(' ', $btns));

// Helper for simple names without fullname() notices
$mkname = function(string $first = '', string $last = ''): string {
    return format_string(trim($first . ' ' . $last));
};

// ----------------------------------------------------------------------------
// Output header
// ----------------------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('SPE — Instructor Management');

// Plain-link shortcuts (always visible for staff)
$links = [
    html_writer::link(
        new moodle_url('/mod/spe/analysis_report.php', ['id' => $cm->id]),
        'Open Sentiment Analysis Report'
    ),

    html_writer::link(
        new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
        'Back to activity'
    ),
];

echo html_writer::alist($links, ['class' => 'list-unstyled mb-3']);

// ----------------------------------------------------------------------------
// Queueing approvals (with optional immediate run)
// ----------------------------------------------------------------------------
$approveuserid = optional_param('approveuserid', 0, PARAM_INT);
$run           = optional_param('run', 0, PARAM_BOOL); // run analyzer after queueing?

if ($approveuserid && confirm_sesskey()) {
// 1) Reflection from either submission table or reflection table
$reflectiontext = '';

if ($sub = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $approveuserid])) {
    if (!empty(trim($sub->reflection))) {
        $reflectiontext = trim($sub->reflection);
    }
}

// Optional: also check separate reflection table (if used)
if ($reflectiontext === '' && $DB->get_manager()->table_exists('spe_reflection')) {
    $refrec = $DB->get_record('spe_reflection', ['speid' => $cm->instance, 'userid' => $approveuserid]);
    if ($refrec && !empty(trim($refrec->reflection))) {
        $reflectiontext = trim($refrec->reflection);
    }
}

if ($reflectiontext !== '') {
    $exists = $DB->record_exists('spe_sentiment', [
        'speid'   => $cm->instance,
        'raterid' => $approveuserid,
        'rateeid' => $approveuserid,
        'type'    => 'reflection',
        'text'    => $reflectiontext
    ]);
    if (!$exists) {
        $DB->insert_record('spe_sentiment', (object)[
            'speid'       => $cm->instance,
            'raterid'     => $approveuserid,
            'rateeid'     => $approveuserid,
            'type'        => 'reflection',
            'text'        => $reflectiontext,
            'status'      => 'pending',
            'timecreated' => time()
        ]);
    }
}


    // 2) Peer comments
    $ratings = $DB->get_records('spe_rating', [
        'speid'   => $cm->instance,
        'raterid' => $approveuserid
    ]);
    foreach ($ratings as $r) {
        $comment = trim((string)$r->comment);
        if ($comment === '') { continue; }

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

    \core\notification::success('Queued texts for analysis.');

    // If ?run=1, immediately jump to the analyzer page (runs batch for this activity)
    if ($run) {
        redirect(new moodle_url('/mod/spe/analyze_push.php', [
            'id' => $cm->id,
            'sesskey' => sesskey(),
        ]));
    }
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
            'speid'   => $cm->instance,
            'raterid' => $u->id
        ]);

        $approveurl = new moodle_url('/mod/spe/instructor.php', [
            'id' => $cm->id,
            'approveuserid' => $u->id,
            'run' => 1,               // <— run analyzer right after queueing
            'sesskey' => sesskey()
        ]);
        $btn = html_writer::link($approveurl, 'Approve', ['class' => 'btn btn-secondary']);


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

// ----------------------------------------------------------------------------
// 2. Instructor Results & Export links
// ----------------------------------------------------------------------------
echo html_writer::tag('h3', '2. Analysis Results & Exports');

// Viewing permission (exports themselves will re-check)
require_capability('mod/spe:viewreports', $context);

// Export page links
$here    = $PAGE->url->out_as_local_url(false);
$csvpage = new moodle_url('/mod/spe/export_csv.php', ['id' => $cm->id, 'returnurl' => $here]);
$pdfpage = new moodle_url('/mod/spe/export_pdf.php', ['id' => $cm->id, 'returnurl' => $here]);
echo html_writer::div(
    html_writer::link($csvpage, 'CSV export page') . ' | ' .
    html_writer::link($pdfpage, 'PDF export page'),
    'spe-export-links',
    ['style' => 'margin:10px 0;']
);

// ----------------------------------------------------------------------------
// Ratings table (grouped by Moodle group)
// ----------------------------------------------------------------------------
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

// Group memberships
$allgroups  = groups_get_all_groups($course->id);
$usergroups = [];
foreach ($allgroups as $g) {
    $members = groups_get_members($g->id, 'u.id');
    foreach ($members as $m) {
        $usergroups[$m->id] = $g->name;
    }
}

// Group ratings
$grouped = [];
foreach ($ratings as $r) {
    $group = $usergroups[$r->raterid] ?? 'Ungrouped';
    $grouped[$group][] = $r;
}

// Render grouped table
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
