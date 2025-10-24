<?php
// mod/spe/group_approve.php
// Compute and publish group-average scores for this SPE.
// Saves each student's published score in user preferences:
//   name:  mod_spe_groupscore_<cmid>
//   value: {"avgpoints": float, "percent": float, "stars": float, "raters": int}

require('../../config.php');

$cmid    = required_param('id', PARAM_INT);
$approve = optional_param('approve', 0, PARAM_INT); // when 1, publish to user preferences

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Teachers/Managers only
require_capability('mod/spe:manage', $context);

$PAGE->set_url('/mod/spe/group_approve.php', ['id' => $cm->id]);
$PAGE->set_title('Approve Group Scores');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

// ---- Determine one (first) Moodle group the teacher cares about.
// If your activity uses separate groups, you can add a dropdown.
// For now we aggregate by each member's FIRST group in this course.
$allgroups = groups_get_all_groups($course->id);

// Build group membership map: user -> group name (first group wins)
$usergroup = [];
foreach ($allgroups as $g) {
    $members = groups_get_members($g->id, 'u.id, u.firstname, u.lastname, u.username');
    foreach ($members as $u) {
        if (!isset($usergroup[$u->id])) {
            $usergroup[$u->id] = $g->name;
        }
    }
}

// Get all ratings for this activity.
$ratings = $DB->get_records('spe_rating', ['speid' => $cm->instance], 'rateeid, raterid, id');

// Index totals: received points per (target user) from each rater.
$received = []; // [$rateeid]['sumfrom'][$raterid] => totalPoints (5..25)
$raterset = []; // [$rateeid] => set of raters (for counts)

foreach ($ratings as $r) {
    // Skip self-ratings for "received from peers" average:
    if ((int)$r->rateeid === (int)$r->raterid) continue;

    // Sum across criteria per (rater -> rateeid)
    $key = $r->rateeid . ':' . $r->raterid;
    if (!isset($received[$r->rateeid]['sumfrom'][$r->raterid])) {
        $received[$r->rateeid]['sumfrom'][$r->raterid] = 0;
    }
    $received[$r->rateeid]['sumfrom'][$r->raterid] += (int)$r->score;

    $raterset[$r->rateeid][$r->raterid] = true;
}

// Compute averages per user
// Your rule: average_of_total_points * 100 / 20  (=> percentage)
// Then stars (out of 5) = percent * 5 / 100
$rows = []; // [userid] => data
foreach ($received as $uid => $info) {
    $totals = array_values($info['sumfrom'] ?? []);
    $numraters = count($totals);
    if ($numraters === 0) continue;

    $avgpoints = array_sum($totals) / $numraters; // e.g. avg of (5..25 or <=20 if 4 criteria)
    $percent   = ($avgpoints * 100.0) / 20.0;     // per your spec
    if ($percent < 0)   $percent = 0;
    if ($percent > 100) $percent = 100;

    $stars = ($percent * 5.0) / 100.0;            // 0..5

    $rows[$uid] = [
        'userid'    => (int)$uid,
        'group'     => $usergroup[$uid] ?? 'Ungrouped',
        'avgpoints' => round($avgpoints, 3),
        'percent'   => round($percent, 1),
        'stars'     => round($stars, 2),
        'raters'    => $numraters,
    ];
}

// If approving, publish to user preferences for this cm.
if ($approve && confirm_sesskey()) {
    foreach ($rows as $uid => $d) {
        $prefname = 'mod_spe_groupscore_' . $cm->id;
        set_user_preference($prefname, json_encode($d), $uid);
    }
    redirect(
        new moodle_url('/mod/spe/group_approve.php', ['id' => $cm->id]),
        'Group scores published. They can now be shown on student submission pages.',
        2
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Approve Group Scores');

// Instruction
echo html_writer::div(
    'Below are per-student averages based on <strong>received</strong> peer ratings. ' .
    'Percent = (avg total points × 100 / 20). Stars = Percent × 5 / 100. ' .
    'Click <em>Publish</em> to save each score as a user preference so it can be shown on student submission pages.',
    'mb-3'
);

// Publish button
if (!empty($rows)) {
    $puburl = new moodle_url('/mod/spe/group_approve.php', ['id' => $cm->id, 'approve' => 1, 'sesskey' => sesskey()]);
    echo html_writer::div(
        html_writer::link($puburl, '✅ Publish group scores', ['class' => 'btn btn-primary mb-3']),
        ''
    );
}

// Render table
echo html_writer::start_tag('table', ['class' => 'generaltable']);
echo html_writer::tag('tr',
    html_writer::tag('th', 'User') .
    html_writer::tag('th', 'Group') .
    html_writer::tag('th', 'Raters') .
    html_writer::tag('th', 'Avg points') .
    html_writer::tag('th', 'Percent') .
    html_writer::tag('th', 'Stars / 5')
);

// Fetch user names in one go
if ($rows) {
    $uids = array_keys($rows);
    list($in, $params) = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED);
    $names = $DB->get_records_select('user', "id $in", $params, '', 'id, firstname, lastname, username');

    foreach ($rows as $uid => $d) {
        $u = $names[$uid] ?? null;
        $uname = $u ? fullname($u) . ' (' . s($u->username) . ')' : $uid;

        echo html_writer::tag('tr',
            html_writer::tag('td', $uname) .
            html_writer::tag('td', s($d['group'])) .
            html_writer::tag('td', (string)$d['raters']) .
            html_writer::tag('td', (string)$d['avgpoints']) .
            html_writer::tag('td', $d['percent'] . '%') .
            html_writer::tag('td', $d['stars'] . ' / 5')
        );
    }
}
echo html_writer::end_tag('table');

// Back links
$back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
echo html_writer::div(
    html_writer::link($back, '← Back to Instructor dashboard', ['class' => 'btn btn-secondary mt-3'])
);

echo $OUTPUT->footer();
