<?php
require('../../config.php');

$cmid = required_param('id', PARAM_INT); // course module id (?id=XX)

// Build $cm and $course from the cmid
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// IMPORTANT: this sets up $PAGE->cm, context, and enforces login
require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/spe/view.php', ['id' => $cm->id]);
// $PAGE->set_cm($cm, $course); // not needed because require_course_login already did it
$PAGE->set_title(get_string('pluginname', 'mod_spe'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'mod_spe'));

// Teacher/manager-only tools
if (has_capability('mod/spe:manage', $context)) {
    echo $OUTPUT->single_button(
        new moodle_url('/mod/spe/uploadcsv.php', ['id' => $cm->id]),
        'Upload Student CSV',
        'get'
    );
}

echo $OUTPUT->footer();
