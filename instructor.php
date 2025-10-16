<?php
// mod/spe/instructor.php
// Approve submissions and queue texts for sentiment analysis.

require('../../config.php');

$cmid = required_param('id', PARAM_INT); // course module id
$cm   = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

$PAGE->set_url('/mod/spe/instructor.php', ['id' => $cm->id]);
$PAGE->set_title('SPE — Instructor');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('SPE — Approvals & Queue for Analysis');

// Action: approve a single user’s submission → queue texts.
$approveuserid = optional_param('approveuserid', 0, PARAM_INT);
if ($approveuserid && confirm_sesskey()) {
    // 1) Reflection (from spe_submission)
    if ($sub = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $approveuserid])) {
        $reflection = trim((string)$sub->reflection);
        if ($reflection !== '') {
            // Avoid duplicate queueing (same speid+raterid+rateeid+self)
            $exists = $DB->record_exists('spe_sentiment', [
                'speid' => $cm->instance, 'raterid' => $approveuserid, 'rateeid' => $approveuserid, 'type' => 'reflection'
            ]);
            if (!$exists) {
                $DB->insert_record('spe_sentiment', (object)[
                    'speid'       => $cm->instance,
                    'raterid'     => $approveuserid,
                    'rateeid'     => $approveuserid,
                    'type'        => 'reflection',
                    'text'        => $reflection,
                    'status'      => 'pending',
                    'timecreated' => time()
                ]);
            }
        }
    }

    // 2) Peer comments authored by this user (from spe_rating.comment per ratee)
    $ratings = $DB->get_records('spe_rating', [
        'speid'   => $cm->instance,
        'raterid' => $approveuserid
    ]);
    foreach ($ratings as $r) {
        $comment = trim((string)$r->comment);
        if ($comment === '') { continue; }

        // Queue only once per (rater, ratee) text blob — if you stored same comment per criterion,
        // de-dup by key (speid,raterid,rateeid,type=peer_comment,text hash)
        $texthash = sha1($comment);
        $exists = $DB->record_exists_select('spe_sentiment',
            "speid = ? AND raterid = ? AND rateeid = ? AND type = ? AND " .
            $DB->sql_compare_text('text', 1024) . " = " . $DB->sql_compare_text('?', 1024),
            [$cm->instance, $approveuserid, $r->rateeid, 'peer_comment', $comment]
        );
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

// Table: list students with a submission and whether they’ve been queued.
echo html_writer::tag('p', 'Approve a student to queue their Reflection and Peer Comments for sentiment analysis.');

$studentsql = "SELECT u.id, u.firstname, u.lastname, u.username,
                      s.id AS subid, s.timecreated, s.timemodified
                 FROM {user} u
                 JOIN {spe_submission} s
                   ON s.userid = u.id
                WHERE s.speid = :speid
             ORDER BY u.lastname, u.firstname";
$students = $DB->get_records_sql($studentsql, ['speid' => $cm->instance]);

if (!$students) {
    echo $OUTPUT->notification('No submissions yet.', 'notifyinfo');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('table', ['class' => 'generaltable']);
echo html_writer::tag('tr',
    html_writer::tag('th', 'Student') .
    html_writer::tag('th', 'Submission time') .
    html_writer::tag('th', 'Queued items') .
    html_writer::tag('th', 'Action')
);
foreach ($students as $u) {
    // Count queued items for this rater (reflection + peer comments)
    $queued = $DB->count_records('spe_sentiment', [
        'speid' => $cm->instance, 'raterid' => $u->id
    ]);

    $approveurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id, 'approveuserid' => $u->id, 'sesskey' => sesskey()]);
    $btn = html_writer::link($approveurl, 'Approve & Queue', ['class' => 'btn btn-secondary']);

    echo html_writer::tag('tr',
        html_writer::tag('td', fullname($u) . " ({$u->username})") .
        html_writer::tag('td', userdate($u->timemodified ?: $u->timecreated)) .
        html_writer::tag('td', (string)$queued) .
        html_writer::tag('td', $btn)
    );
}
echo html_writer::end_tag('table');

// Navigation shortcuts
$pushurl = new moodle_url('/mod/spe/pushanalysis.php', ['id' => $cm->id]);
$resurl  = new moodle_url('/mod/spe/results.php', ['id' => $cm->id]);

echo html_writer::tag('p',
    html_writer::link($pushurl, 'Send pending texts to Sentiment API') . ' | ' .
    html_writer::link($resurl,  'View analysis results')
);

echo $OUTPUT->footer();
