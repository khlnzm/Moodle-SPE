<?php
require('../../config.php');
require_login();

$courseid = required_param('id', PARAM_INT); // Course ID
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

$PAGE->set_url('/mod/spe/index.php', ['id' => $courseid]);
$PAGE->set_title('Self & Peer Evaluation');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Self & Peer Evaluation activities in this course');
// Later: fetch SPE activities from DB and display.
echo $OUTPUT->footer();
