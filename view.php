<?php
require('../../config.php');
require_login();

$id = required_param('id', PARAM_INT); // Course module ID
$cm = get_coursemodule_from_id('spe', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

$PAGE->set_url('/mod/spe/view.php', ['id' => $id]);
$PAGE->set_title('Self & Peer Evaluation');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Welcome to Self & Peer Evaluation');
// Later: display submission form or instructor dashboard.
echo $OUTPUT->footer();
