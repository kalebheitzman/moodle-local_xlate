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
 * One-off CLI helper: for each orphaned question_bank_entries cluster found
 * by diagnose_question_chain.php --scan, show the course category structure
 * of the affected course(s) and each quiz's module context id, to decide
 * where a recovered question_categories row should live.
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

// Quiz cmids implicated by the scan, grouped by the missing categoryid cluster.
$clusters = [
    2491 => [1137],
    3875 => [1092],
    3911 => [1114, 1115, 1163, 1164, 1628, 1629],
    3939 => [1108],
];

foreach ($clusters as $missingcatid => $quizids) {
    echo "=== Missing categoryid {$missingcatid} ===\n";
    $courseids = [];
    foreach ($quizids as $quizid) {
        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.course
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
              WHERE cm.instance = :quizid",
            ['quizid' => $quizid]
        );
        $modcontext = $DB->get_record('context', ['contextlevel' => CONTEXT_MODULE, 'instanceid' => $cm->id]);
        $course = $DB->get_record('course', ['id' => $cm->course]);
        $courseids[$course->id] = $course->id;
        echo "  quiz id={$quizid} \"{$quiz->name}\" cmid={$cm->id} modcontextid={$modcontext->id} courseid={$course->id} \"{$course->fullname}\" course->category={$course->category}\n";
    }

    echo "  Distinct courses involved: " . count($courseids) . "\n";
    $cats = [];
    foreach ($courseids as $cid) {
        $course = $DB->get_record('course', ['id' => $cid]);
        $cats[$course->category] = $course->category;
    }
    if (count($cats) === 1) {
        $catid = array_key_first($cats);
        $coursecat = $DB->get_record('course_categories', ['id' => $catid]);
        $catcontext = $DB->get_record('context', ['contextlevel' => CONTEXT_COURSECAT, 'instanceid' => $catid]);
        echo "  All courses share course_category id={$catid} \"{$coursecat->name}\" coursecat-contextid={$catcontext->id}\n";
    } else {
        echo "  Courses do NOT share a single course_category (" . implode(',', $cats) . ")\n";
    }
    echo "\n";
}
