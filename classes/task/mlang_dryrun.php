<?php
namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;

require_once(dirname(dirname(__DIR__)) . '/classes/mlang_migration.php');

/**
 * Adhoc task wrapper to run a dry-run MLang scan and produce a JSON report.
 */
class mlang_dryrun extends adhoc_task {
    /**
     * Human-readable name for the task.
     * @return string
     */
    public function get_name() {
        return get_string('mlangdryrun', 'local_xlate');
    }

    public function execute() {
        global $DB;

        $data = $this->get_custom_data() ?: [];
        // Map incoming arrays to our dryrun options.
        $options = [];
        if (!empty($data->tables) && is_array($data->tables)) {
            $options['tables'] = (array)$data->tables;
        }
        if (!empty($data->chunk)) { $options['chunk'] = (int)$data->chunk; }
        if (!empty($data->sample)) { $options['sample'] = (int)$data->sample; }

        // If no explicit tables were provided, attempt to auto-discover candidate text columns.
        if (empty($options['tables'])) {
            $discoveropts = [];
            // Allow callers to pass autodiscover settings in custom data (optional).
            if (!empty($data->discover) && is_array($data->discover)) {
                $discoveropts = (array)$data->discover;
            }
            $options['tables'] = \local_xlate\mlang_migration::discover_candidate_columns($DB, $discoveropts);
        }

        $report = \local_xlate\mlang_migration::dryrun($DB, $options);

        // Log a brief message for admin logs.
        debugging('[local_xlate] mlang dryrun completed: ' . ($report['report_file'] ?? 'no-file'), DEBUG_DEVELOPER);
    }
}
