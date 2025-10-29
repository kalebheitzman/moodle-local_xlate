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

    // Remove userid column from local_xlate_key_course - no longer needed
    if ($oldversion < 2025102900) {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_xlate_key_course');
        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025102900, 'local', 'xlate');
    }

    // Create provenance table for mlang destructive migration
    if ($oldversion < 2025103000) {
        global $DB;

        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_xlate_mlang_migration');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tablename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('recordid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('columnname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('old_value', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('new_value', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('source_hash', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('migrated_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('migrated_by', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('idx_table_record', XMLDB_INDEX_NOTUNIQUE, ['tablename', 'recordid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025103000, 'local', 'xlate');
    }

    // Remove source_hash columns and adjust unique index on key_course
    if ($oldversion < 2025103001) {
        global $DB;

        $dbman = $DB->get_manager();

        // local_xlate_key_course: replace unique index (keyid,courseid,source_hash) with (keyid,courseid)
        $table = new xmldb_table('local_xlate_key_course');

        // First, drop the old unique index if present. This must be done before dropping the column.
        $idxold = new xmldb_index('uq_key_course_source', XMLDB_INDEX_UNIQUE, ['keyid', 'courseid', 'source_hash']);
        if ($dbman->index_exists($table, $idxold)) {
            try {
                $dbman->drop_index($table, $idxold);
            } catch (\Exception $e) {
                debugging('[local_xlate] failed to drop old index uq_key_course_source: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Next, ensure there are no duplicate (keyid, courseid) rows that would prevent creating a unique index.
        try {
            $dupSql = "SELECT keyid, courseid, COUNT(*) as c FROM {local_xlate_key_course} GROUP BY keyid, courseid HAVING COUNT(*) > 1";
            $dups = $DB->get_records_sql($dupSql);
        } catch (\Exception $e) {
            $dups = [];
        }

        if (!empty($dups)) {
            debugging('[local_xlate] Upgrade detected ' . count($dups) . ' duplicate keyid+courseid groups in local_xlate_key_course; consolidating by keeping lowest id per group.', DEBUG_DEVELOPER);
            foreach ($dups as $d) {
                $keyid = (int)$d->keyid;
                $courseid = (int)$d->courseid;
                // Find the lowest id to keep
                $keep = $DB->get_field_select('local_xlate_key_course', 'MIN(id)', 'keyid = ? AND courseid = ?', [$keyid, $courseid]);
                if ($keep === false || $keep === null) { continue; }
                // Delete all other rows for this keyid+courseid
                try {
                    $DB->delete_records_select('local_xlate_key_course', 'keyid = ? AND courseid = ? AND id != ?', [$keyid, $courseid, $keep]);
                } catch (\Exception $e) {
                    debugging('[local_xlate] failed to remove duplicate local_xlate_key_course rows for keyid=' . $keyid . ' courseid=' . $courseid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        // Now add the new unique index on (keyid, courseid)
        $idxnew = new xmldb_index('uq_key_course', XMLDB_INDEX_UNIQUE, ['keyid', 'courseid']);
        if (!$dbman->index_exists($table, $idxnew)) {
            try {
                $dbman->add_index($table, $idxnew);
            } catch (\Exception $e) {
                debugging('[local_xlate] failed to add new unique index uq_key_course: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Finally, drop the source_hash field if present (after indexes adjusted)
        $field = new xmldb_field('source_hash', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            try {
                $dbman->drop_field($table, $field);
            } catch (\Exception $e) {
                debugging('[local_xlate] failed to drop source_hash from local_xlate_key_course: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // local_xlate_mlang_migration: drop source_hash field if present
        $table2 = new xmldb_table('local_xlate_mlang_migration');
        $field2 = new xmldb_field('source_hash', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        if ($dbman->table_exists($table2) && $dbman->field_exists($table2, $field2)) {
            try {
                $dbman->drop_field($table2, $field2);
            } catch (\Exception $e) {
                debugging('[local_xlate] failed to drop source_hash from local_xlate_mlang_migration: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        upgrade_plugin_savepoint(true, 2025103001, 'local', 'xlate');
    }

    return true;
}
