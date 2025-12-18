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
 * Scheduled cleanup task for legacy multilang content.
 *
 * Invokes the Local Xlate migration helper to convert legacy multilang tags
 * on a scheduled basis.
 *
 * @package    local_xlate
 * @category   task
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task that performs periodic multilang cleanup migrations.
 *
 * @package local_xlate\task
 */
class mlang_cleanup_task extends \core\task\scheduled_task {
    /**
     * Provide the Moodle display name for the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('mlangcleanuptask', 'local_xlate');
    }

    /**
     * Execute the cleanup pass via the migration helper.
     *
     * Runs the multilang migration in write mode to replace legacy tags and
     * emits summary information to the scheduled task log via mtrace.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        mtrace('[mlang_cleanup_task] Starting scheduled mlang cleanup...');
        $enabledcourses = \local_xlate\customfield_helper::get_enabled_course_ids();
        if (empty($enabledcourses)) {
            mtrace('[mlang_cleanup_task] No courses have Enable Xlate turned on; skipping cleanup run.');
            return;
        }

        $report = \local_xlate\mlang_migration::migrate($DB, [
            'execute' => true,
            'courseids' => $enabledcourses
        ]);
        mtrace('[mlang_cleanup_task] Completed. Changed: ' . ($report['changed'] ?? 0));
        // Optionally, log/report more details or errors here.
    }
}
