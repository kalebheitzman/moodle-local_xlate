<?php
namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

class mlang_cleanup_task extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('mlangcleanuptask', 'local_xlate');
    }

    public function execute() {
        global $DB;
        mtrace('[mlang_cleanup_task] Starting scheduled mlang cleanup...');
        $report = \local_xlate\mlang_migration::migrate($DB, ['execute' => true]);
        mtrace('[mlang_cleanup_task] Completed. Changed: ' . ($report['changed'] ?? 0));
        // Optionally, log/report more details or errors here.
    }
}
