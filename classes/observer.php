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
 * Event observers for Local Xlate.
 *
 * @package    local_xlate
 * @category   event
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate;

defined('MOODLE_INTERNAL') || die();

/**
 * Queues course-scoped mlang cleanup when courses are imported or created.
 *
 * The heavy lifting stays in the adhoc task (\local_xlate\task\mlang_course_cleanup_task);
 * observers only enqueue, so restore performance is unaffected.
 *
 * @package local_xlate
 */
class observer {
    /**
     * A course was restored/imported from a backup — the primary source of
     * legacy mlang content entering this site.
     *
     * @param \core\event\course_restored $event Restore event.
     * @return void
     */
    public static function course_restored(\core\event\course_restored $event): void {
        self::queue_cleanup((int)$event->courseid);
    }

    /**
     * A course was created (covers course-copy flows that fire creation).
     *
     * @param \core\event\course_created $event Creation event.
     * @return void
     */
    public static function course_created(\core\event\course_created $event): void {
        self::queue_cleanup((int)$event->courseid);
    }

    /**
     * Queue the course-scoped cleanup adhoc task, deduplicated.
     *
     * Course enablement is deliberately NOT checked here: at event time the
     * course's custom fields may not be fully restored yet. The adhoc task
     * re-checks is_course_enabled() when it actually runs.
     *
     * @param int $courseid Course to clean.
     * @return void
     */
    private static function queue_cleanup(int $courseid): void {
        if ($courseid <= 1) {
            // 0 = invalid, 1 = site course.
            return;
        }
        if (!get_config('local_xlate', 'enable')) {
            return;
        }
        $task = new \local_xlate\task\mlang_course_cleanup_task();
        $task->set_custom_data(['courseid' => $courseid]);
        // Second argument deduplicates against an identical pending task.
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
