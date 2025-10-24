<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_spe_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Use a new target version > current version.php (you'll bump version.php too).
    $target = 2025100401;

    if ($oldversion < $target) {

        // ---- Table: spe
        $table = new xmldb_table('spe');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('course',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name',         XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('intro',        XMLDB_TYPE_TEXT,    null, null, null, null, null);
            $table->add_field('introformat',  XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary',  XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('coursefk', XMLDB_KEY_FOREIGN, ['course'], 'course', ['id']);

            $dbman->create_table($table);
        }

        // ---- Table: spe_submission
        $table = new xmldb_table('spe_submission');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('speid',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('selfdesc',     XMLDB_TYPE_TEXT,     null, null, null, null, null);
            $table->add_field('reflection',   XMLDB_TYPE_TEXT,     null, null, null, null, null);
            $table->add_field('wordcount',    XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('spefk',   XMLDB_KEY_FOREIGN, ['speid'],  'spe',  ['id']);
            $table->add_key('userfk',  XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $index = new xmldb_index('unique_by_student', XMLDB_INDEX_UNIQUE, ['speid','userid']);
            $table->add_index($index);

            $dbman->create_table($table);
        }

        // ---- Table: spe_rating
        $table = new xmldb_table('spe_rating');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('speid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('raterid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rateeid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('criterion',   XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('score',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('comment',     XMLDB_TYPE_TEXT,     null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('spefk',   XMLDB_KEY_FOREIGN, ['speid'],   'spe',  ['id']);
            $table->add_key('raterfk', XMLDB_KEY_FOREIGN, ['raterid'], 'user', ['id']);
            $table->add_key('rateefk', XMLDB_KEY_FOREIGN, ['rateeid'], 'user', ['id']);

            $index = new xmldb_index('by_pair', XMLDB_INDEX_NOTUNIQUE, ['speid','raterid','rateeid','criterion']);
            $table->add_index($index);

            $dbman->create_table($table);
        }

        // ---- Table: spe_teammap
        $table = new xmldb_table('spe_teammap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('speid',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('teamname',    XMLDB_TYPE_CHAR,    '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rawidnumber', XMLDB_TYPE_CHAR,    '100', null, null, null, null);
            $table->add_field('rawusername', XMLDB_TYPE_CHAR,    '100', null, null, null, null);
            $table->add_field('rawemail',    XMLDB_TYPE_CHAR,    '255', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('spefk',   XMLDB_KEY_FOREIGN, ['speid'],  'spe',  ['id']);
            $table->add_key('userfk',  XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $index = new xmldb_index('spe_user_unique', XMLDB_INDEX_UNIQUE, ['speid','userid']);
            $table->add_index($index);

            $dbman->create_table($table);
        }

        // ---- Table: spe_sentiment
        $table = new xmldb_table('spe_sentiment');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('speid',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('raterid',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('rateeid',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('type',         XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, null); // peer_comment|reflection
            $table->add_field('text',         XMLDB_TYPE_TEXT,     null,  null, null, null, null);
            $table->add_field('sentiment',    XMLDB_TYPE_NUMBER,  '10,4',null, null, null, null);
            $table->add_field('label',        XMLDB_TYPE_CHAR,    '20',  null, null, null, null);
            $table->add_field('status',       XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, null); // pending|done|error
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

            $dbman->create_table($table);
        }

        // Mark upgrade complete
        upgrade_mod_savepoint(true, $target, 'spe');
    }

    return true;
}
