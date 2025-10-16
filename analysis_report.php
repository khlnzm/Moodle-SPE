<?php
// mod/spe/analysis_report.php
require('../../config.php');

$cmid = required_param('id', PARAM_INT);

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:viewreports', $context);

$PAGE->set_url('/mod/spe/analysis_report.php', ['id' => $cm->id]);
$PAGE->set_title('SPE Analysis Report');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('SPE Analysis Report');

// 1) Show each rater→ratee scores (averaged) and the comment text (if any)
$sqlratings = "SELECT r.rateeid,
                      r.raterid,
                      u1.firstname AS rater_first, u1.lastname AS rater_last,
                      u2.firstname AS ratee_first, u2.lastname AS ratee_last,
                      r.criterion, r.score, r.comment, r.timecreated
                 FROM {spe_rating} r
                 JOIN {user} u1 ON u1.id = r.raterid
                 JOIN {user} u2 ON u2.id = r.rateeid
                WHERE r.speid = :speid
             ORDER BY r.raterid, r.rateeid, r.criterion";
$params = ['speid' => $cm->instance];

$rows = $DB->get_records_sql($sqlratings, $params);

echo html_writer::tag('h3', 'Scores & Comments');
if (!$rows) {
    echo $OUTPUT->notification('No ratings found.', 'notifywarning');
} else {
    // Group by pair (rater→ratee)
    $byPair = [];
    foreach ($rows as $r) {
        $key = $r->raterid . '->' . $r->rateeid;
        if (!isset($byPair[$key])) {
            $byPair[$key] = [
                'rater' => fullname((object)['firstname'=>$r->rater_first,'lastname'=>$r->rater_last]),
                'ratee' => fullname((object)['firstname'=>$r->ratee_first,'lastname'=>$r->ratee_last]),
                'scores' => [],
                'comment' => $r->comment // last seen comment for this pair (stored per criterion)
            ];
        }
        $byPair[$key['scores']][$r->criterion] = (int)$r->score;
        // keep the latest non-empty comment if present
        if (!empty($r->comment)) { $byPair[$key]['comment'] = $r->comment; }
    }

    $table = new html_table();
    $table->head = ['Rater', 'Ratee', 'Avg Score', 'Comment (excerpt)'];
    foreach ($byPair as $pair) {
        $avg = 0.0;
        if (!empty($pair['scores'])) {
            $avg = array_sum($pair['scores']) / max(1, count($pair['scores']));
        }
        $excerpt = s(core_text::substr($pair['comment'] ?? '', 0, 140));
        $table->data[] = [s($pair['rater']), s($pair['ratee']), format_float($avg, 2), $excerpt];
    }
    echo html_writer::table($table);
}

// 2) Show NLP result status for queued texts in spe_sentiment
echo html_writer::tag('h3', 'Queued Texts & NLP Results');

$sqlsent = "SELECT s.id, s.raterid, s.rateeid, s.type, s.label, s.sentiment, s.status, s.text, s.timecreated, s.timemodified,
                   ur.firstname AS rater_first, ur.lastname AS rater_last,
                   ue.firstname AS ratee_first, ue.lastname AS ratee_last
              FROM {spe_sentiment} s
              JOIN {user} ur ON ur.id = s.raterid
              JOIN {user} ue ON ue.id = s.rateeid
             WHERE s.speid = :speid
          ORDER BY s.timecreated DESC, s.id DESC";
$sentrows = $DB->get_records_sql($sqlsent, $params);

if (!$sentrows) {
    echo $OUTPUT->notification('No NLP queue entries found for this activity.', 'notifywarning');
} else {
    $table2 = new html_table();
    $table2->head = ['ID', 'Type', 'Rater', 'Target', 'Status', 'Label', 'Score', 'Excerpt'];
    foreach ($sentrows as $srow) {
        $rater  = fullname((object)['firstname'=>$srow->rater_first,'lastname'=>$srow->rater_last]);
        $ratee  = fullname((object)['firstname'=>$srow->ratee_first,'lastname'=>$srow->ratee_last]);
        $label  = $srow->label ? s($srow->label) : '-';
        $score  = isset($srow->sentiment) ? format_float((float)$srow->sentiment, 4) : '-';
        $status = s($srow->status);
        $excerpt = s(core_text::substr($srow->text ?? '', 0, 140));
        $table2->data[] = [$srow->id, s($srow->type), s($rater), s($ratee), $status, $label, $score, $excerpt];
    }
    echo html_writer::table($table2);
}

$buttons = html_writer::div(
    $OUTPUT->single_button(new moodle_url('/mod/spe/analyze_push.php', ['id' => $cm->id]), 'Analyze pending now', 'get')
    . ' ' .
    $OUTPUT->single_button(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]), 'Back to activity', 'get')
);
echo $buttons;

echo $OUTPUT->footer();
