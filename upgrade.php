<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_spe_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025100301) {
        // spe_submission
        $table = new xmldb_table('spe_submission');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('speid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('reflection', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('wordcount', XMLDB_TYPE_INTEGER, '10', null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('spefk', XMLDB_KEY_FOREIGN, ['speid'], 'spe', ['id']);
        $table->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('unique_by_student', XMLDB_INDEX_UNIQUE, ['speid','userid']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }

        // spe_rating
        $table = new xmldb_table('spe_rating');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('speid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('raterid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('rateeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('criterion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('score', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('spefk', XMLDB_KEY_FOREIGN, ['speid'], 'spe', ['id']);
        $table->add_key('raterfk', XMLDB_KEY_FOREIGN, ['raterid'], 'user', ['id']);
        $table->add_key('rateefk', XMLDB_KEY_FOREIGN, ['rateeid'], 'user', ['id']);
        $table->add_index('by_pair', XMLDB_INDEX_NOTUNIQUE, ['speid','raterid','rateeid','criterion']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }

        $table = new xmldb_table('spe_submission');
        $field = new xmldb_field('selfdesc', XMLDB_TYPE_TEXT, null, null, null, null, null, 'userid'); // after userid
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2025100301, 'spe');

        
    }
    return true;
}
