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
 * One-off CLI helper: check whether
 * \core\task\cleanup_questions_without_categories_task is queued in the
 * adhoc task table. That task hard-deletes any question whose
 * question_bank_entries.questioncategoryid points at a missing
 * question_categories row -- which would destroy the orphaned-but-live
 * questions found by diagnose_question_chain.php --scan.
 *
 * Read-only. Makes no database changes.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2026 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

global $DB;

$tasks = $DB->get_records_sql(
    "SELECT id, classname, nextruntime, faildelay, timecreated
       FROM {task_adhoc}
      WHERE classname LIKE :pat",
    ['pat' => '%cleanup_questions_without_categories%']
);

if (!$tasks) {
    echo "No cleanup_questions_without_categories_task currently queued in task_adhoc.\n";
} else {
    echo "FOUND queued task(s) that will DELETE orphaned questions:\n\n";
    foreach ($tasks as $t) {
        echo "  id={$t->id}\n";
        echo "  classname={$t->classname}\n";
        echo "  nextruntime=" . date('c', $t->nextruntime) . "\n";
        echo "  faildelay={$t->faildelay}\n";
        echo "  created=" . date('c', $t->timecreated) . "\n\n";
    }
}

echo "\n=== Past runs logged in task_log ===\n";
$logs = $DB->get_records_sql(
    "SELECT id, classname, timestarted, timeend, result
       FROM {task_log}
      WHERE classname LIKE :pat
   ORDER BY timestarted DESC",
    ['pat' => '%cleanup_questions_without_categories%'],
    0,
    10
);
if (!$logs) {
    echo "No past runs found in task_log (it has not executed yet, or task_log has rotated).\n";
} else {
    foreach ($logs as $l) {
        $duration = $l->timeend ? ($l->timeend - $l->timestarted) . 's' : 'still running?';
        echo "  started=" . date('c', $l->timestarted) . " duration={$duration} result={$l->result}\n";
    }
}
