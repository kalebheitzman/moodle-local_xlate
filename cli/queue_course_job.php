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
 * CLI helper to enqueue Local Xlate course translation jobs.
 *
 * Usage: sudo -u www-data php local/xlate/cli/queue_course_job.php --courseid=2 [--batchsize=50] [--targetlangs=es,fr]
 *
 * Creates a new course job record and queues the associated adhoc task without
 * requiring the web service interface.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
// Fix path: config.php is three levels up from this script (moodle root).
require_once(__DIR__ . '/../../../config.php');
global $DB, $CFG, $USER;

// Parse argv style options
/**
 * @var array<string, string|bool> $opts command-line switches keyed without the leading dashes
 */
$opts = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $opts[$parts[0]] = $parts[1] ?? true;
    }
}

$courseid = isset($opts['courseid']) ? (int)$opts['courseid'] : 0;
$batchsize = isset($opts['batchsize']) ? (int)$opts['batchsize'] : 50;
/** @var array<int,string> $targetlangs */
$targetlangs = [];
if (!empty($opts['targetlangs'])) {
    $targetlangs = array_map('trim', explode(',', $opts['targetlangs']));
}

if (!$courseid) {
    echo "Usage: php queue_course_job.php --courseid=ID [--batchsize=50] [--targetlangs=es,fr]\n";
    exit(1);
}

// Get course source language from custom fields
$coursesourcelang = \local_xlate\customfield_helper::get_course_source_lang($courseid);
if (!$coursesourcelang) {
    echo "Error: Course $courseid has no xlate source language configured.\n";
    echo "Please configure source and target languages in the course settings.\n";
    exit(1);
}

// Count keys
$total = $DB->count_records('local_xlate_key_course', ['courseid' => $courseid]);

/** @var stdClass $record row persisted to local_xlate_course_job */
$record = new stdClass();
$record->courseid = $courseid;
$record->userid = isset($USER->id) ? (int)$USER->id : 0;
$record->status = 'pending';
$record->total = $total;
$record->processed = 0;
$record->batchsize = $batchsize;
/**
 * @var array{batchsize:int,targetlang:array<int,string>,sourcelang:string} $options
 *     Persisted job options consumed by \local_xlate\task\translate_course_task.
 */
$options = [
    'batchsize' => $batchsize,
    'targetlang' => $targetlangs,
    'sourcelang' => $coursesourcelang
];
$record->options = json_encode($options);
$record->lastid = 0;
$record->ctime = time();
$record->mtime = time();

$jobid = $DB->insert_record('local_xlate_course_job', $record);
echo "Inserted job id: {$jobid} (courseid={$courseid}, total={$total})\n";

/** @var \local_xlate\task\translate_course_task $task */
$task = new \local_xlate\task\translate_course_task();
$task->set_custom_data((object)['jobid' => $jobid]);
\core\task\manager::queue_adhoc_task($task);
echo "Queued adhoc task for job {$jobid}\n";
