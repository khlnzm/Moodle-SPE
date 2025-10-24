<?php
defined('MOODLE_INTERNAL') || die();

/** Declare what this module supports */
function spe_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO: return true;
        default: return null;
    }
}

/** Create instance (insert into mdl_spe) */
function spe_add_instance($data, $mform = null) {
    global $DB;
    $data->timecreated  = time();
    $data->timemodified = time();
    return $DB->insert_record('spe', $data);
}

/** Update instance */
function spe_update_instance($data, $mform = null) {
    global $DB;
    $data->id = $data->instance;       // Moodle sends "instance" for the row id.
    $data->timemodified = time();
    return $DB->update_record('spe', $data);
}

/** Delete instance */
function spe_delete_instance($id) {
    global $DB;
    if (!$DB->record_exists('spe', ['id' => $id])) return false;
    // TODO: delete child records later.
    $DB->delete_records('spe', ['id' => $id]);
    return true;
}

/** Report */
function spe_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $node) {
    global $PAGE;

    // Bail out safely if no CM yet.
    if (empty($PAGE->cm)) {
        return;
    }

    $cmid    = $PAGE->cm->id;
    $context = context_module::instance($cmid);

    // Instructor dashboard (manage capability)
    if (has_capability('mod/spe:manage', $context)) {
        $dashurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cmid]);
        $node->add(
            get_string('instructordashboard', 'spe'),
            $dashurl,
            navigation_node::TYPE_SETTING,
            null,
            'spe_instructor_dashboard',
            new pix_icon('i/settings', '')
        );
    }

    // Direct report link (viewreports capability)
    if (has_capability('mod/spe:viewreports', $context)) {
        $repurl = new moodle_url('/mod/spe/analysis_report.php', ['id' => $cmid]);
        $node->add(
            get_string('analysisreport', 'spe'),
            $repurl,
            navigation_node::TYPE_SETTING,
            null,
            'spe_analysis_report',
            new pix_icon('i/report', '')
        );
    }
}


