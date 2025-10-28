<?php
/**
 * Upgrade steps for local_xlate.
 *
 * @package   local_xlate
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_xlate upgrade steps.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_xlate_upgrade(int $oldversion): bool {

    if ($oldversion < 2025102403) {
        // Placeholder for future upgrade logic.

        upgrade_plugin_savepoint(true, 2025102403, 'local', 'xlate');
    }

    // Add local_xlate_key_course table for course associations
    if ($oldversion < 2025102700) {
        global $DB;

        $dbman = $DB->get_manager();

        // Define table local_xlate_key_course to be added.
        $table = new xmldb_table('local_xlate_key_course');

        // Add fields to table local_xlate_key_course.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('keyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source_hash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('context', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('mtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Add keys
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_keyid', XMLDB_KEY_FOREIGN, ['keyid'], 'local_xlate_key', ['id']);

        // Add indexes
    $table->add_index('ix_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('uq_key_course_source', XMLDB_INDEX_UNIQUE, ['keyid', 'courseid', 'source_hash']);

        // Conditionally launch create table
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Savepoint reached.
        upgrade_plugin_savepoint(true, 2025102700, 'local', 'xlate');
    }

    // Savepoint for adding course-level capability and nav hook
    if ($oldversion < 2025102800) {
        // No DB schema changes required for capabilities; bump savepoint.
        upgrade_plugin_savepoint(true, 2025102800, 'local', 'xlate');
    }

    return true;
}
