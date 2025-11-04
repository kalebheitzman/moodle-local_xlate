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
 * CLI helper to inspect Local Xlate course jobs.
 *
 * Usage: php inspect_job.php <jobid>
 *
 * Retrieves and prints properties of a queued or running course translation
 * job for debugging purposes.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

/**
 * Job identifier provided via CLI argument; defaults to 1 for convenience.
 */
$jobid = isset($argv[1]) ? (int)$argv[1] : 1;

global $DB;
try {
    $job = $DB->get_record('local_xlate_course_job', ['id' => $jobid], '*', IGNORE_MISSING);
    if (!$job) {
        echo "Job not found: $jobid\n";
        exit(1);
    }
    echo "Job ID: " . $job->id . "\n";
    echo "Course ID: " . $job->courseid . "\n";
    echo "User ID: " . $job->userid . "\n";
    echo "Status: " . $job->status . "\n";
    echo "Total: " . $job->total . "\n";
    echo "Processed: " . $job->processed . "\n";
    echo "Batchsize: " . $job->batchsize . "\n";
    echo "Options: " . $job->options . "\n";
    echo "LastID: " . $job->lastid . "\n";
    echo "Created: " . date('c', $job->ctime) . "\n";
    echo "Modified: " . date('c', $job->mtime) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
