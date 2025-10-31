<?php
// Simple CLI helper to inspect a local_xlate_course_job record.
// Usage: php inspect_job.php <jobid>

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

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
