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
 * Adhoc course-scoped mlang cleanup task.
 *
 * Queued by \local_xlate\observer when a course is restored/imported or
 * created, so legacy mlang content is cleaned (and its embedded translations
 * harvested) within minutes of arrival, scoped to that single course.
 *
 * @package    local_xlate
 * @category   task
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Runs the mlang migration in write mode for a single course.
 *
 * @package local_xlate\task
 */
class mlang_course_cleanup_task extends \core\task\adhoc_task {
    /**
     * Execute the course-scoped cleanup.
     *
     * Re-checks course enablement at run time (custom fields may not have
     * existed at queue time, e.g. mid-restore). Uses the same migration
     * helper as the scheduled task, scoped via the courseids option.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $courseid = (int)($data->courseid ?? 0);
        if ($courseid <= 1) {
            return;
        }
        if (!get_config('local_xlate', 'enable')) {
            mtrace('[mlang_course_cleanup_task] Plugin disabled; skipping course ' . $courseid . '.');
            return;
        }
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            mtrace('[mlang_course_cleanup_task] Course ' . $courseid . ' no longer exists; skipping.');
            return;
        }
        if (!\local_xlate\customfield_helper::is_course_enabled($courseid)) {
            mtrace('[mlang_course_cleanup_task] Xlate not enabled for course ' . $courseid . '; skipping.');
            return;
        }

        mtrace('[mlang_course_cleanup_task] Starting scoped mlang cleanup for course ' . $courseid . '...');
        $report = \local_xlate\mlang_migration::migrate($DB, [
            'execute'   => true,
            'courseids' => [$courseid],
        ]);
        mtrace('[mlang_course_cleanup_task] Course ' . $courseid
            . ' completed. Changed: ' . ($report['changed'] ?? 0)
            . ', harvested translations: ' . ($report['harvested'] ?? 0));
    }
}
