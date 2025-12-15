<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade steps for local_xlate.
 *
 * @package   local_xlate
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_xlate upgrade steps.
 *
 * @param int $oldversion The plugin version we are upgrading from.
 * @return bool Always returns true once upgrade path completes.
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

    // Create glossary table for existing installs (added in install.xml)
    if ($oldversion < 2025103002) {
        global $DB;

        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_xlate_glossary');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('source_lang', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('target_lang', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('target_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('mtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('created_by', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('ctime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('idx_langpair', XMLDB_INDEX_NOTUNIQUE, ['source_lang', 'target_lang']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025103002, 'local', 'xlate');
    }

    // Savepoint: bump version so new classes (adhoc tasks) are picked up by Moodle.
    if ($oldversion < 2025103004) {
        // No DB schema changes required; this savepoint ensures the plugin version
        // increments so Moodle will detect new classes such as adhoc tasks.
        upgrade_plugin_savepoint(true, 2025103004, 'local', 'xlate');
    }

    // Ensure creation time column (ctime) exists on local_xlate_key so UI can
    // order by creation time rather than modification time. Backfill from mtime
    // for existing records.
    if ($oldversion < 2025103100) {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_xlate_key');
        $field = new xmldb_field('ctime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Add ctime if it doesn't exist.
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            try {
                $dbman->add_field($table, $field);
            } catch (\Exception $e) {
                debugging('[local_xlate] failed to add ctime to local_xlate_key: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Backfill ctime from mtime where ctime is zero or NULL.
        try {
            $DB->execute("UPDATE {local_xlate_key} SET ctime = mtime WHERE (ctime = 0 OR ctime IS NULL) AND mtime IS NOT NULL");
        } catch (\Exception $e) {
            debugging('[local_xlate] failed to backfill ctime: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2025103100, 'local', 'xlate');
    }

    // Add 'reviewed' flag to translations so humans can mark autotranslations as reviewed.
    if ($oldversion < 2025110100) {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_xlate_tr');
        $field = new xmldb_field('reviewed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            try {
                $dbman->add_field($table, $field);
            } catch (\Exception $e) {
                debugging('[local_xlate] failed to add reviewed to local_xlate_tr: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Backfill any missing values just in case
        try {
            $DB->execute("UPDATE {local_xlate_tr} SET reviewed = 0 WHERE (reviewed = 0 OR reviewed IS NULL)");
        } catch (\Exception $e) {
            debugging('[local_xlate] failed to backfill reviewed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2025110100, 'local', 'xlate');
    }

    // Create course-level autotranslate job table.
    if ($oldversion < 2025110101) {
        global $DB;

        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_xlate_course_job');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('total', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('processed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('batchsize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '50');
        $table->add_field('options', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('lastid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('mtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('ctime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('idx_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025110101, 'local', 'xlate');
    }

    // Create course-level autotranslate job table.
    if ($oldversion < 2025110102) {
        upgrade_plugin_savepoint(true, 2025110102, 'local', 'xlate');
    }

    // Add local_xlate_token_batch table for batch-level token usage logging
    if ($oldversion < 2025110307) {
        global $DB;

        $dbman = $DB->get_manager();

        // Define table local_xlate_token_batch to be added.
        $table = new xmldb_table('local_xlate_token_batch');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('lang', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('batchsize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('input_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cached_input_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('output_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('total_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('input_cost', XMLDB_TYPE_NUMBER, '12,6', null, null, null, '0');
        $table->add_field('cached_input_cost', XMLDB_TYPE_NUMBER, '12,6', null, null, null, '0');
        $table->add_field('output_cost', XMLDB_TYPE_NUMBER, '12,6', null, null, null, '0');
        $table->add_field('total_cost', XMLDB_TYPE_NUMBER, '12,6', null, null, null, '0');
        $table->add_field('response_ms', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('jobid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Add keys
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Add indexes
        $table->add_index('idx_timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        $table->add_index('idx_lang', XMLDB_INDEX_NOTUNIQUE, ['lang']);

        // Conditionally launch create table
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025110307, 'local', 'xlate');
    }

    if ($oldversion < 2025110400) {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_xlate_token_batch');

        $fields = [
            'input_tokens' => new xmldb_field('input_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'batchsize'),
            'cached_input_tokens' => new xmldb_field('cached_input_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'input_tokens'),
            'output_tokens' => new xmldb_field('output_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'cached_input_tokens'),
            'total_tokens' => new xmldb_field('total_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'output_tokens'),
            'input_cost' => new xmldb_field('input_cost', XMLDB_TYPE_NUMBER, '12,6', null, null, null, '0', 'total_tokens'),
            'cached_input_cost' => new xmldb_field('cached_input_cost', XMLDB_TYPE_NUMBER, '12,6', null, null, null, '0', 'input_cost'),
            'output_cost' => new xmldb_field('output_cost', XMLDB_TYPE_NUMBER, '12,6', null, null, null, '0', 'cached_input_cost'),
            'total_cost' => new xmldb_field('total_cost', XMLDB_TYPE_NUMBER, '12,6', null, null, null, '0', 'output_cost')
        ];

        foreach ($fields as $name => $field) {
            if (!$dbman->field_exists($table, $field)) {
                try {
                    $dbman->add_field($table, $field);
                } catch (\Exception $e) {
                    debugging('[local_xlate] Failed adding field ' . $name . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        // Backfill legacy data if the old aggregate columns still exist.
        $legacytokens = new xmldb_field('tokens');
        $legacyprompt = new xmldb_field('prompt_tokens');
        $legacycompletion = new xmldb_field('completion_tokens');
        $legacycost = new xmldb_field('cost');

        $haslegacy = $dbman->field_exists($table, $legacytokens) ||
            $dbman->field_exists($table, $legacyprompt) ||
            $dbman->field_exists($table, $legacycompletion) ||
            $dbman->field_exists($table, $legacycost);

        if ($haslegacy) {
            $selects = [
                'id',
                'input_tokens',
                'cached_input_tokens',
                'output_tokens',
                'total_tokens',
                'input_cost',
                'cached_input_cost',
                'output_cost',
                'total_cost'
            ];

            $selects[] = $dbman->field_exists($table, $legacytokens) ? 'tokens' : '0 AS tokens';
            $selects[] = $dbman->field_exists($table, $legacyprompt) ? 'prompt_tokens' : '0 AS prompt_tokens';
            $selects[] = $dbman->field_exists($table, $legacycompletion) ? 'completion_tokens' : '0 AS completion_tokens';
            $selects[] = $dbman->field_exists($table, $legacycost) ? 'cost' : '0 AS cost';

            $sql = 'SELECT ' . implode(',', $selects) . ' FROM {local_xlate_token_batch}';
            $rs = $DB->get_recordset_sql($sql);
            foreach ($rs as $row) {
                $inputtokens = (int)$row->input_tokens;
                $cachedtokens = (int)$row->cached_input_tokens;
                $outputtokens = (int)$row->output_tokens;
                $totaltokens = (int)$row->total_tokens;
                $inputcost = (float)$row->input_cost;
                $cachedcost = (float)$row->cached_input_cost;
                $outputcost = (float)$row->output_cost;
                $totalcost = (float)$row->total_cost;

                $legacyinput = isset($row->prompt_tokens) ? (int)$row->prompt_tokens : 0;
                $legacyoutput = isset($row->completion_tokens) ? (int)$row->completion_tokens : 0;
                $legacytotal = isset($row->tokens) ? (int)$row->tokens : 0;
                $legacycostval = isset($row->cost) ? (float)$row->cost : 0.0;

                $needsupdate = false;

                if ($inputtokens === 0 && $legacyinput > 0) {
                    $inputtokens = $legacyinput;
                    $needsupdate = true;
                }
                if ($outputtokens === 0 && $legacyoutput > 0) {
                    $outputtokens = $legacyoutput;
                    $needsupdate = true;
                }
                if ($totaltokens === 0) {
                    if ($legacytotal > 0) {
                        $totaltokens = $legacytotal;
                        $needsupdate = true;
                    } else if (($inputtokens + $cachedtokens + $outputtokens) > 0) {
                        $totaltokens = $inputtokens + $cachedtokens + $outputtokens;
                        $needsupdate = true;
                    }
                }

                if ($totalcost === 0.0 && $legacycostval > 0) {
                    $totalcost = $legacycostval;
                    $needsupdate = true;
                }

                if ($legacycostval > 0 && ($inputcost === 0.0 && $cachedcost === 0.0 && $outputcost === 0.0)) {
                    $sumtokens = $legacyinput + $legacyoutput;
                    if ($sumtokens > 0) {
                        $ratio = $legacyinput / $sumtokens;
                        $inputcost = round($legacycostval * $ratio, 6);
                        $outputcost = round($legacycostval - $inputcost, 6);
                    } else {
                        $inputcost = 0.0;
                        $outputcost = round($legacycostval, 6);
                    }
                    $needsupdate = true;
                }

                if ($needsupdate) {
                    $update = (object) [
                        'id' => $row->id,
                        'input_tokens' => $inputtokens,
                        'cached_input_tokens' => $cachedtokens,
                        'output_tokens' => $outputtokens,
                        'total_tokens' => $totaltokens,
                        'input_cost' => $inputcost,
                        'cached_input_cost' => $cachedcost,
                        'output_cost' => $outputcost,
                        'total_cost' => round($totalcost, 6)
                    ];

                    try {
                        $DB->update_record('local_xlate_token_batch', $update);
                    } catch (\Exception $e) {
                        debugging('[local_xlate] Failed to backfill token batch #' . $row->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }
            $rs->close();

            // Drop legacy columns now that data is migrated.
            foreach (['tokens', 'prompt_tokens', 'completion_tokens', 'cost'] as $legacyname) {
                $legacyfield = new xmldb_field($legacyname);
                if ($dbman->field_exists($table, $legacyfield)) {
                    try {
                        $dbman->drop_field($table, $legacyfield);
                    } catch (\Exception $e) {
                        debugging('[local_xlate] Failed to drop legacy field ' . $legacyname . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }
        }

        upgrade_plugin_savepoint(true, 2025110400, 'local', 'xlate');
    }

    // Setup Xlate custom fields using Moodle's built-in custom fields API
    if ($oldversion < 2025111705) {
        \local_xlate\customfield_helper::setup_customfields();

        upgrade_plugin_savepoint(true, 2025111705, 'local', 'xlate');
    }

    if ($oldversion < 2025121500) {
        // Ensure newly declared capabilities (viewbundle/viewsystem) are created
        // with their default archetype assignments.
        update_capabilities('local_xlate');

        upgrade_plugin_savepoint(true, 2025121500, 'local', 'xlate');
    }

    return true;
}
