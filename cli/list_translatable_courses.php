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
 * CLI helper to list courses ready for Local Xlate autotranslation.
 *
 * Displays course id, fullname, source language, and configured target languages
 * for every course that has a source language and at least one target selected.
 *
 * Usage: sudo -u www-data php local/xlate/cli/list_translatable_courses.php
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

/** @var array<int,stdClass> $courses */
$courses = $DB->get_records('course', null, 'id ASC', 'id, fullname, shortname');

if (empty($courses)) {
    mtrace('No courses found.');
    exit(0);
}

$header = sprintf("%-8s %-50s %-12s %s", 'ID', 'Course', 'Source', 'Targets');
$divider = str_repeat('-', 8) . ' ' . str_repeat('-', 50) . ' ' . str_repeat('-', 12) . ' ' . str_repeat('-', 30);

mtrace($header);
mtrace($divider);

$found = false;
foreach ($courses as $course) {
    $coursecontext = \context_course::instance($course->id);
    $fullname = format_string($course->fullname, true, ['context' => $coursecontext]);

    $config = \local_xlate\customfield_helper::get_course_config($course->id);
    if ($config === null || empty($config['targets'])) {
        continue; // Course not ready for autotranslation.
    }

    $found = true;
    $targets = implode(',', $config['targets']);
    $line = sprintf("%-8d %-50s %-12s %s", $course->id, $fullname, $config['source'], $targets);
    mtrace($line);
}

if (!$found) {
    mtrace('No courses currently meet the autotranslation requirements.');
}
