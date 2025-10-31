<?php
// CLI helper to enqueue a course autotranslate job (bypasses webservice/cap checks).
// Usage: sudo -u www-data php local/xlate/cli/queue_course_job.php --courseid=2 [--batchsize=50] [--targetlangs=es,fr]

define('CLI_SCRIPT', true);
// Fix path: config.php is three levels up from this script (moodle root).
require_once(__DIR__ . '/../../../config.php');
global $DB, $CFG, $USER;

// Parse argv style options
$opts = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $opts[$parts[0]] = $parts[1] ?? true;
    }
}

$courseid = isset($opts['courseid']) ? (int)$opts['courseid'] : 0;
$batchsize = isset($opts['batchsize']) ? (int)$opts['batchsize'] : 50;
$targetlangs = [];
if (!empty($opts['targetlangs'])) {
    $targetlangs = array_map('trim', explode(',', $opts['targetlangs']));
}

if (!$courseid) {
    echo "Usage: php queue_course_job.php --courseid=ID [--batchsize=50] [--targetlangs=es,fr]\n";
    exit(1);
}

// Count keys
$total = $DB->count_records('local_xlate_key_course', ['courseid' => $courseid]);

$record = new stdClass();
$record->courseid = $courseid;
$record->userid = isset($USER->id) ? (int)$USER->id : 0;
$record->status = 'pending';
$record->total = $total;
$record->processed = 0;
$record->batchsize = $batchsize;
$options = [
    'batchsize' => $batchsize,
    'targetlang' => $targetlangs,
    'sourcelang' => 'en'
];
$record->options = json_encode($options);
$record->lastid = 0;
$record->ctime = time();
$record->mtime = time();

$jobid = $DB->insert_record('local_xlate_course_job', $record);
echo "Inserted job id: {$jobid} (courseid={$courseid}, total={$total})\n";

$task = new \local_xlate\task\translate_course_task();
$task->set_custom_data((object)['jobid' => $jobid]);
\core\task\manager::queue_adhoc_task($task);
echo "Queued adhoc task for job {$jobid}\n";
