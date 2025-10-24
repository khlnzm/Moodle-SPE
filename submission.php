<?php
// ============================================================================
// SPE - Student Submission Summary Page (Assignment-like view)
// - Shows Opened/Due and a status table
// - Students see only the status + personal PDF link
// - Teachers/Admins also see Reflection + Ratings and a Reset button
// ============================================================================

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT); // staff can inspect others

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Capability flags
$canmanage = has_capability('mod/spe:manage', $context);

// Whose page are we looking at?
if (!$userid) {
    $userid = $USER->id;
}
$target = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, username', MUST_EXIST);

// Students may view their own; staff may view anyone.
if (!$canmanage && (int)$userid !== (int)$USER->id) {
    print_error('nopermissions', 'error', '', 'view this submission');
}

// Activity record (for name/timeopen/timedue if present)
$spe   = $DB->get_record('spe', ['id' => $cm->instance]); // OK if null
$aname = $spe ? format_string($spe->name) : format_string($cm->name);

// ---------------------------------------------------------------------------
// Page chrome
// ---------------------------------------------------------------------------
$PAGE->set_url('/mod/spe/submission.php', ['id' => $cm->id, 'userid' => $userid]);
$PAGE->set_title($aname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('spe-compact spe-left');

// Header button for staff: Instructor dashboard
if ($canmanage) {
    $label = get_string_manager()->string_exists('instructordashboard', 'spe')
        ? get_string('instructordashboard', 'spe')
        : 'Instructor dashboard';
    $PAGE->set_button(
        $OUTPUT->single_button(
            new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]),
            $label,
            'get'
        )
    );
}

echo $OUTPUT->header();

// ---------------------------------------------------------------------------
// Title
// ---------------------------------------------------------------------------
echo html_writer::tag('h2', $aname, ['class' => 'mb-2']);

// ---------------------------------------------------------------------------
// Opened / Due summary (gracefully handles missing times)
// ---------------------------------------------------------------------------
$timeopen = isset($spe->timeopen) ? (int)$spe->timeopen : 0;
$timedue  = isset($spe->timedue)  ? (int)$spe->timedue  : 0;

echo html_writer::start_div('', [
    'style' => 'background:#f7f9fb;border:1px solid #e5e7eb;padding:14px 16px;border-radius:10px;'
]);
echo html_writer::div(
    html_writer::tag('strong', 'Opened: ') .
    ($timeopen ? userdate($timeopen, get_string('strftimedatetime', 'langconfig')) : '-')
);
echo html_writer::div(
    html_writer::tag('strong', 'Due: ') .
    ($timedue ? userdate($timedue, get_string('strftimedatetime', 'langconfig')) : '-')
);
echo html_writer::end_div();

// ---------------------------------------------------------------------------
// Fetch submission & ratings by this user
// ---------------------------------------------------------------------------
$submission   = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $userid]);
$ratings      = $DB->get_records('spe_rating',    ['speid' => $cm->instance, 'raterid' => $userid], 'rateeid, id');
$lastmodified = $submission ? (int)($submission->timemodified ?: $submission->timecreated) : 0;

// Time remaining / early / late string
$timemsg = '-';
$now = time();
if ($timedue > 0) {
    if ($submission) {
        $delta = $timedue - $lastmodified;     // + = early, - = late
        $abs   = abs($delta);
        $when  = format_time($abs);
        $timemsg = ($delta >= 0) ? "Assignment was submitted {$when} early"
                                 : "Assignment was submitted {$when} late";
    } else {
        $remain = $timedue - $now;
        $timemsg = ($remain >= 0) ? 'Time remaining: ' . format_time($remain)
                                  : 'Closed ' . format_time(-$remain) . ' ago';
    }
}

// ---------------------------------------------------------------------------
// Submission status table
// ---------------------------------------------------------------------------
echo html_writer::tag('h3', 'Submission status', ['class' => 'mt-4']);

echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:auto;min-width:60%;']);
$tr = function(string $label, string $value): string {
    return html_writer::tag('tr',
        html_writer::tag('th', $label, ['style' => 'width:240px;']) .
        html_writer::tag('td', $value)
    );
};

// Row: submission status
$substatus   = $submission ? 'Submitted for grading' : 'No submission';
$gradestatus = 'Not graded';

echo $tr('Submission status', $substatus);
echo $tr('Grading status',   $gradestatus);
echo $tr('Time remaining',   s($timemsg));

// Row: last modified
$lm = $lastmodified ? userdate($lastmodified, get_string('strftimedatetime', 'langconfig')) : '-';
echo $tr('Last modified', $lm);

// Row: file submissions (always allow own PDF; staff can view anyone’s)
$pdfurl  = new moodle_url('/mod/spe/submission_pdf.php', ['id' => $cm->id, 'userid' => $userid]);
$pdflink = html_writer::link($pdfurl, 'Download submission PDF');
echo $tr('File submissions', $pdflink);

// After building $tr(), before closing the table:
$pub = json_decode(get_user_preferences('mod_spe_groupscore_' . $cm->id, '', $userid), true);
if (is_array($pub) && isset($pub['stars'])) {
    $display = $pub['stars'] . ' / 5 (' . $pub['percent'] . '%)';
    echo $tr('Group score', $display);
}


echo html_writer::end_tag('table');

// ---------------------------------------------------------------------------
// Reflection (STAFF-ONLY)
// ---------------------------------------------------------------------------
if ($canmanage) {
    if ($submission) {
        echo html_writer::tag('h4', 'Reflection', ['class' => 'mt-4']);
        echo html_writer::tag(
            'div',
            format_text($submission->reflection, FORMAT_HTML),
            [
                'style' => 'white-space:pre-wrap;border:1px solid #e5e7eb;' .
                           'padding:10px;border-radius:6px;margin-bottom:12px'
            ]
        );
    }
}

// ---------------------------------------------------------------------------
// Ratings given (STAFF-ONLY)
// ---------------------------------------------------------------------------
if ($canmanage && $ratings) {
    echo html_writer::tag('h4', 'Ratings given', ['class' => 'mt-3']);

    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::tag('tr',
        html_writer::tag('th', 'Ratee') .
        html_writer::tag('th', 'Criterion') .
        html_writer::tag('th', 'Score') .
        html_writer::tag('th', 'Comment')
    );

    // Fetch ratee names in one go
    $ids = array_unique(array_map(fn($r) => $r->rateeid, $ratings));
    $names = [];
    if ($ids) {
        list($in, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $names = $DB->get_records_select('user', "id $in", $params, '', 'id, firstname, lastname');
    }

    foreach ($ratings as $r) {
        $rateename = isset($names[$r->rateeid]) ? fullname($names[$r->rateeid]) : (string)$r->rateeid;
        echo html_writer::tag('tr',
            html_writer::tag('td', s($rateename)) .
            html_writer::tag('td', s($r->criterion)) .
            html_writer::tag('td', (int)$r->score) .
            html_writer::tag('td', s($r->comment))
        );
    }
    echo html_writer::end_tag('table');
}

// ---------------------------------------------------------------------------
// Reset button (STAFF-ONLY) — shown even if no submission
// ---------------------------------------------------------------------------
if ($canmanage) {
    $reseturl = new moodle_url('/mod/spe/submission_reset.php', [
        'id' => $cm->id,
        'userid' => $userid,
        'sesskey' => sesskey()
    ]);

    echo html_writer::div(
        html_writer::link(
            $reseturl,
            '🧹 Reset this submission (allow resubmission)',
            [
                'class' => 'btn btn-danger',
                'style' => 'margin-top:14px',
                'onclick' => "return confirm('Reset this student\\'s SPE? This deletes their submission, ratings, and sentiment rows.');"
            ]
        )
    );
}

// ---------------------------------------------------------------------------
// Back buttons
// ---------------------------------------------------------------------------
$backcourse = new moodle_url('/course/view.php', ['id' => $course->id]);
$backact    = new moodle_url('/mod/spe/view.php',  ['id' => $cm->id]);

echo html_writer::div(
    html_writer::link($backact, '← Back to activity', [
        'class' => 'btn btn-secondary',
        'style' => 'margin-top:12px;margin-right:8px;'
    ]) .
    html_writer::link($backcourse, '← Back to course', [
        'class' => 'btn btn-secondary',
        'style' => 'margin-top:12px;'
    ])
);

echo $OUTPUT->footer();
