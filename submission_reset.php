<?php
require('../../config.php');

$cmid = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$cm = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

require_capability('mod/spe:manage', $context); // teachers/admin only
require_sesskey();

// Delete submission + related ratings
$DB->delete_records('spe_submission', ['speid' => $cm->instance, 'userid' => $userid]);
$DB->delete_records('spe_rating', ['speid' => $cm->instance, 'raterid' => $userid]);
$DB->delete_records('spe_sentiment', ['speid' => $cm->instance, 'raterid' => $userid]);

redirect(
    new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]),
    'Submission reset successfully.',
    2
);
