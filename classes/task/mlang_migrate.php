<?php
namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;

require_once(dirname(dirname(__DIR__)) . '/classes/mlang_migration.php');

/**
 * Adhoc task wrapper to run a destructive MLang migration (idempotent).
 * By default runs in dry-run mode; must be passed execute=true to perform writes.
 */
class mlang_migrate extends adhoc_task {
    public function get_name() {
        return get_string('mlangmigrate', 'local_xlate');
    }

    public function execute() {
        global $DB;

        $data = $this->get_custom_data() ?: [];
        $options = [];
        if (!empty($data->tables) && is_array($data->tables)) {
            $options['tables'] = (array)$data->tables;
        }
        if (!empty($data->chunk)) { $options['chunk'] = (int)$data->chunk; }
        if (!empty($data->preferred)) { $options['preferred'] = (string)$data->preferred; }
        if (!empty($data->execute)) { $options['execute'] = (bool)$data->execute; }
        if (!empty($data->sample)) { $options['sample'] = (int)$data->sample; }

        $report = \local_xlate\mlang_migration::migrate($DB, $options);

        debugging('[local_xlate] mlang migrate completed; changed=' . ($report['changed'] ?? 0), DEBUG_DEVELOPER);
    }
}
