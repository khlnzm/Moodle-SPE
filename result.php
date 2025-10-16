<?php
// mod/spe/results.php
// Display analyzed sentiment results for this SPE activity.

require('../../config.php');

$cmid = required_param('id', PARAM_INT);
$cm   = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/spe/results.php', ['id' => $cm->id]);
$PAGE->set_title('SPE — Sentiment Results');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('SPE — Sentiment Results');

// Who can see what
$canmanage = has_capability('mod/spe:manage', $context);

// Fetch results
$params = ['speid' => $cm->instance, 'status' => 'done'];
$where = "speid = :speid AND status = :status";
if (!$canmanage) {
    // Students: see only rows where they are rater or ratee (tweak as desired).
    $where .= " AND (raterid = :me OR rateeid = :me)";
    $params['me'] = $USER->id;
}

$rows = $DB->get_records_select('spe_sentiment', $where, $params, 'raterid, rateeid, id');

if (!$rows) {
    echo $OUTPUT->notification('No analyzed results yet. Use the Instructor page to queue texts and push for analysis.', 'notifyinfo');

    if ($canmanage) {
        $instrurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
        echo html_writer::tag('p', html_writer::link($instrurl, 'Go to Instructor'));
    }

    echo $OUTPUT->footer();
    exit;
}

// Build a little map of users for display names
$userids = [];
foreach ($rows as $r) { $userids[$r->raterid] = true; $userids[$r->rateeid] = true; }
$users = [];
if ($userids) {
    list($insql, $inparams) = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'u');
    $users = $DB->get_records_select('user', "id $insql", $inparams, '', 'id, firstname, lastname, username');
}

// Render simple table
echo html_writer::start_tag('table', ['class' => 'generaltable']);
echo html_writer::tag('tr',
    html_writer::tag('th', 'Rater') .
    html_writer::tag('th', 'Target') .
    html_writer::tag('th', 'Type') .
    html_writer::tag('th', 'Label') .
    html_writer::tag('th', 'Score (compound)') .
    html_writer::tag('th', 'Excerpt')
);

foreach ($rows as $r) {
    $rater  = $users[$r->raterid] ?? null;
    $ratee  = $users[$r->rateeid] ?? null;
    $rname  = $rater ? fullname($rater) . " ({$rater->username})" : $r->raterid;
    $tname  = $ratee ? fullname($ratee) . " ({$ratee->username})" : $r->rateeid;

    $label = s((string)$r->label);
    $compound = is_null($r->sentiment) ? '' : sprintf('%.3f', (float)$r->sentiment);
    $excerpt = s(core_text::substr(clean_text((string)$r->text), 0, 120)) . (core_text::strlen((string)$r->text) > 120 ? '…' : '');

    // Light color hint
    $style = '';
    if ($r->label === 'positive')    { $style = 'color:#1a7f37;'; }
    else if ($r->label === 'negative'){ $style = 'color:#b42318;'; }
    else if ($r->label === 'neutral') { $style = 'color:#555;'; }

    echo html_writer::tag('tr',
        html_writer::tag('td', $rname) .
        html_writer::tag('td', $tname) .
        html_writer::tag('td', s($r->type)) .
        html_writer::tag('td', html_writer::tag('strong', $label, ['style' => $style])) .
        html_writer::tag('td', $compound) .
        html_writer::tag('td', $excerpt)
    );
}
echo html_writer::end_tag('table');

// Manage link for staff
if ($canmanage) {
    $pushurl = new moodle_url('/mod/spe/pushanalysis.php', ['id' => $cm->id]);
    echo html_writer::tag('p', html_writer::link($pushurl, 'Re-run pending analysis'));
}

echo $OUTPUT->footer();
