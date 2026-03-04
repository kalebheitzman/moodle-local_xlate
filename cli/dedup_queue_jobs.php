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
 * CLI tool to remove duplicate Local Xlate course translation jobs.
 *
 * When multiple pending/running jobs exist for the same course and target
 * language set (caused by a race condition during enqueue), this script
 * keeps the most-progressed job per course+lang group and discards the rest,
 * along with any dangling adhoc tasks that would have executed them.
 *
 * Always run with --dry-run first.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/dedup_queue_jobs.php --dry-run
 *   sudo -u www-data php local/xlate/cli/dedup_queue_jobs.php
 *   sudo -u www-data php local/xlate/cli/dedup_queue_jobs.php --courseid=987
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2026 Kaleb Heitzman
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$help = <<<EOF
Deduplicate Local Xlate course translation jobs.

Finds courses with multiple active (pending/running) jobs covering the same
target languages, keeps the most-progressed one per group, and deletes the
duplicates together with their dangling adhoc tasks.

Usage:
  sudo -u www-data php local/xlate/cli/dedup_queue_jobs.php [options]

Options:
  --dry-run          Show what would be deleted without making any changes.
  --courseid=<id>    Limit to a single course (optional).
  -h, --help         Show this help.

EOF;

list($params, $unrecognized) = cli_get_params([
    'dry-run'  => false,
    'courseid' => 0,
    'help'     => false,
], ['h' => 'help']);

if (!empty($unrecognized)) {
    cli_error("Unknown options:\n  " . implode("\n  ", $unrecognized));
}

if (!empty($params['help'])) {
    cli_writeln($help);
    exit(0);
}

$dryrun   = !empty($params['dry-run']);
$courseid = (int)$params['courseid'];

if ($dryrun) {
    mtrace('[DRY RUN] No changes will be made.');
}

// Fetch all pending/running jobs, optionally scoped to one course.
list($statusql, $statusparams) = $DB->get_in_or_equal(['pending', 'running'], SQL_PARAMS_NAMED, 'st');
$wherecourse = '';
if ($courseid > 0) {
    $wherecourse = ' AND courseid = :courseid';
    $statusparams['courseid'] = $courseid;
}

$jobs = $DB->get_records_select(
    'local_xlate_course_job',
    "status $statusql $wherecourse",
    $statusparams,
    'courseid ASC, id ASC'
);

if (empty($jobs)) {
    mtrace('No active jobs found. Nothing to do.');
    exit(0);
}

mtrace('Active jobs found: ' . count($jobs));

// Group jobs by course. Within each course, group by normalised target-lang set.
// Key: courseid . '|' . sorted lang list (e.g. "987|de,ru").
$groups = [];
foreach ($jobs as $job) {
    $opts = [];
    if (!empty($job->options)) {
        $decoded = json_decode((string)$job->options, true);
        if (is_array($decoded)) {
            $opts = $decoded;
        }
    }
    $langs = array_values(array_unique(array_filter(array_map('trim',
        (array)($opts['targetlangs'] ?? $opts['targetlang'] ?? [])))));
    sort($langs);
    $groupkey = $job->courseid . '|' . implode(',', $langs);
    $groups[$groupkey][] = $job;
}

$todelete    = [];  // job IDs to remove.
$taskdelete  = [];  // adhoc task IDs to remove.
$keepcount   = 0;

foreach ($groups as $groupkey => $groupjobs) {
    if (count($groupjobs) < 2) {
        // No duplicates in this group.
        $keepcount++;
        continue;
    }

    // Pick the survivor: highest processed count wins; tie-break on lowest ID
    // (oldest job, most likely to have a valid lastid cursor).
    usort($groupjobs, function($a, $b) {
        if ((int)$b->processed !== (int)$a->processed) {
            return (int)$b->processed - (int)$a->processed;
        }
        return (int)$a->id - (int)$b->id;
    });

    $survivor  = array_shift($groupjobs);
    $keepcount++;

    list($courseid_display, $langlist) = explode('|', $groupkey, 2);
    mtrace('');
    mtrace("Course {$courseid_display} [{$langlist}]: keeping job {$survivor->id} ({$survivor->processed}/{$survivor->total} processed, status={$survivor->status})");

    foreach ($groupjobs as $dup) {
        mtrace("  -> duplicate job {$dup->id} ({$dup->processed}/{$dup->total} processed, status={$dup->status}) — marked for deletion");
        $todelete[] = (int)$dup->id;
    }
}

if (empty($todelete)) {
    mtrace('');
    mtrace('No duplicate jobs found. Queue is clean.');
    exit(0);
}

// Find adhoc tasks whose custom data references a job we are about to delete.
$alladhoc = $DB->get_records_select(
    'task_adhoc',
    "classname = :cls",
    ['cls' => '\local_xlate\task\translate_course_task'],
    '',
    'id, customdata'
);
foreach ($alladhoc as $task) {
    $tdata = json_decode((string)$task->customdata);
    $taskjobid = isset($tdata->jobid) ? (int)$tdata->jobid : 0;
    if (in_array($taskjobid, $todelete, true)) {
        $taskdelete[] = (int)$task->id;
    }
}

mtrace('');
mtrace('Summary:');
mtrace('  Jobs to delete : ' . count($todelete) . ' (' . implode(', ', $todelete) . ')');
mtrace('  Adhoc tasks to delete: ' . count($taskdelete) . ($taskdelete ? ' (' . implode(', ', $taskdelete) . ')' : ''));
mtrace('  Jobs to keep   : ' . $keepcount);

if ($dryrun) {
    mtrace('');
    mtrace('[DRY RUN] No changes made. Re-run without --dry-run to apply.');
    exit(0);
}

// Delete duplicate jobs.
list($injobsql, $injobparams) = $DB->get_in_or_equal($todelete, SQL_PARAMS_NAMED, 'dj');
$DB->delete_records_select('local_xlate_course_job', "id $injobsql", $injobparams);
mtrace('Deleted ' . count($todelete) . ' duplicate job(s).');

// Delete dangling adhoc tasks.
if (!empty($taskdelete)) {
    list($intasksql, $intaskparams) = $DB->get_in_or_equal($taskdelete, SQL_PARAMS_NAMED, 'dt');
    $DB->delete_records_select('task_adhoc', "id $intasksql", $intaskparams);
    mtrace('Deleted ' . count($taskdelete) . ' dangling adhoc task(s).');
}

mtrace('Done.');
