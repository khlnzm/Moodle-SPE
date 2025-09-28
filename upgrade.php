<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_spe_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025092800) {
        // Define table spe_teammap to be created.
        $table = new xmldb_table('spe_teammap');

        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('speid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('teamname',    XMLDB_TYPE_CHAR,    '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('rawidnumber', XMLDB_TYPE_CHAR,    '100', null, null, null, null);
        $table->add_field('rawusername', XMLDB_TYPE_CHAR,    '100', null, null, null, null);
        $table->add_field('rawemail',    XMLDB_TYPE_CHAR,    '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('spefk',   XMLDB_KEY_FOREIGN, ['speid'], 'spe',  ['id']);
        $table->add_key('userfk',  XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('spe_user_unique', XMLDB_INDEX_UNIQUE, ['speid','userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2025092800, 'spe');
    }

    return true;
}
