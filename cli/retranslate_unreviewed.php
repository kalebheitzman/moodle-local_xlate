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
 * CLI tool to retranslate unreviewed strings for a course.
 *
 * Queues a course translation job that regenerates AI translations for all
 * strings that have NOT been marked as reviewed by a human. Reviewed
 * translations are always left unchanged.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/retranslate_unreviewed.php --courseid=42
 *   sudo -u www-data php local/xlate/cli/retranslate_unreviewed.php --courseid=42 --dry-run
 *   sudo -u www-data php local/xlate/cli/retranslate_unreviewed.php --courseid=42 --targetlangs=es,fr --batchsize=50
 *
 * Options:
 *   --courseid=N       (required) Course ID to retranslate.
 *   --targetlangs=a,b  (optional) Comma-separated target language codes. Defaults to the
 *                                  languages configured on the course.
 *   --batchsize=N      (optional) Keys per batch task. Default: 50.
 *   --dry-run          (optional) Show what would be queued without making any changes.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
global $DB, $CFG, $USER;

// ---------------------------------------------------------------------------
// Parse CLI options.
// ---------------------------------------------------------------------------
$opts = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $opts[$parts[0]] = isset($parts[1]) ? $parts[1] : true;
    }
}

$courseid  = isset($opts['courseid'])    ? (int)$opts['courseid']    : 0;
$batchsize = isset($opts['batchsize'])   ? (int)$opts['batchsize']   : 50;
$dryrun    = !empty($opts['dry-run']);
/** @var array<int,string> $targetlangs */
$targetlangs = [];
if (!empty($opts['targetlangs'])) {
    $targetlangs = array_map('trim', explode(',', (string)$opts['targetlangs']));
    $targetlangs = array_values(array_filter($targetlangs));
}

if (!$courseid) {
    echo "Usage: php retranslate_unreviewed.php --courseid=N [--targetlangs=es,fr] [--batchsize=50] [--dry-run]\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Validate course.
// ---------------------------------------------------------------------------
if (!\local_xlate\customfield_helper::is_course_enabled($courseid)) {
    echo "Error: Course {$courseid} has Xlate disabled. Enable it in the course settings before retranslating.\n";
    exit(1);
}

$coursesourcelang = \local_xlate\customfield_helper::get_course_source_lang($courseid);
if (!$coursesourcelang) {
    echo "Error: Course {$courseid} has no source language configured.\n";
    echo "Please configure source and target languages in the course settings (Xlate section).\n";
    exit(1);
}

// Resolve target languages: use CLI override if supplied, else course config.
if (empty($targetlangs)) {
    $courseconfig = \local_xlate\customfield_helper::get_course_config($courseid);
    $targetlangs  = !empty($courseconfig['targets']) ? array_values($courseconfig['targets']) : [];
}

if (empty($targetlangs)) {
    echo "Error: No target languages resolved for course {$courseid}.\n";
    echo "Configure target languages in the course settings or pass --targetlangs=es,fr.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Count unreviewed work units per target language.
// ---------------------------------------------------------------------------
echo "Course:        {$courseid}\n";
echo "Source lang:   {$coursesourcelang}\n";
echo "Target langs:  " . implode(', ', $targetlangs) . "\n";
echo "Batch size:    {$batchsize}\n";
if ($dryrun) {
    echo "Mode:          DRY RUN — no changes will be made\n";
}
echo "\n";

$total = 0;
foreach ($targetlangs as $lang) {
    // Match the logic in course_job_manager::count_course_work_units() for
    // the onlyunreviewed=true, onlymissing=false case.
    $sql = "SELECT COUNT(1)
              FROM {local_xlate_key_course} kc
              JOIN {local_xlate_key} k ON k.id = kc.keyid
         LEFT JOIN {local_xlate_tr} t  ON t.keyid = k.id AND t.lang = :targetlang AND t.status = 1
             WHERE kc.courseid = :courseid
               AND (t.id IS NULL OR t.reviewed <> 1)";
    $count = (int)$DB->get_field_sql($sql, ['courseid' => $courseid, 'targetlang' => $lang]);
    echo "  {$lang}: {$count} unreviewed strings\n";
    $total += $count;
}
echo "\nTotal: {$total} strings to retranslate\n";

if ($dryrun) {
    echo "\nDry run complete. Use without --dry-run to queue the job.\n";
    exit(0);
}

if ($total === 0) {
    echo "\nNothing to retranslate. Exiting.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Enqueue the job.
// ---------------------------------------------------------------------------
$result = \local_xlate\local\course_job_manager::enqueue_course_job($courseid, [
    'batchsize'      => $batchsize,
    'targetlangs'    => $targetlangs,
    'onlyunreviewed' => true,
], isset($USER->id) ? (int)$USER->id : 0);

if (empty($result['success'])) {
    $error = isset($result['error']) ? $result['error'] : 'Unknown error.';
    echo "\nError: {$error}\n";
    exit(1);
}

echo "\nQueued job id: {$result['jobid']} (courseid={$courseid})\n";
echo "Adhoc task queued. Run the Moodle task runner to process it.\n";
