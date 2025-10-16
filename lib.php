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
