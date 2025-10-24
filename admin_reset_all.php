<?php
// ============================================================================
// SPE — Admin-only global reset: wipe all SPE-related tables for a course/module.
// ============================================================================

require('../../config.php');

$cmid = required_param('id', PARAM_INT); // The SPE module id
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_login($course, false, $cm);

$context = context_module::instance($cm->id);

if (!is_siteadmin() && !has_capability('moodle/site:config', context_system::instance())) {
    print_error('nopermissions', 'error', '', 'reset all SPE data');
}

$PAGE->set_url('/mod/spe/admin_reset_all.php', ['id' => $cm->id]);
$PAGE->set_title('Admin — Reset All SPE Data');
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading('Admin — Reset All SPE Data', 2);

// Confirmation page
if (!$confirm) {
    $confirmurl = new moodle_url('/mod/spe/admin_reset_all.php', [
        'id' => $cm->id,
        'confirm' => 1,
        'sesskey' => sesskey()
    ]);
    $cancelurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);

    echo $OUTPUT->confirm(
        '⚠️ This will delete *all submissions, ratings, sentiments, and scores* for this SPE across the course. Are you sure?',
        $confirmurl,
        $cancelurl
    );

    echo $OUTPUT->footer();
    exit;
}

// =====================================================================
// Perform the reset (only after confirmation)
// =====================================================================
require_sesskey();

$tables = ['spe_submission', 'spe_rating', 'spe_sentiment', 'spe_group_score'];

foreach ($tables as $table) {
    if ($DB->get_manager()->table_exists($table)) {
        $DB->delete_records($table, ['speid' => $cm->instance]);
    }
}

// Notify success
echo $OUTPUT->notification('✅ All SPE data for this activity has been wiped successfully.', 'notifysuccess');

$backurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
echo html_writer::div(html_writer::link($backurl, '← Return to Instructor Dashboard', [
    'class' => 'btn btn-primary',
    'style' => 'margin-top:15px'
]));

echo $OUTPUT->footer();
