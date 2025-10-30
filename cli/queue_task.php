<?php
// Queue a translate_batch_task with provided items and target languages.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

$items = [
    ['id' => 'region_mainpage:1bl2r7zngtgf', 'component' => 'region_mainpage', 'key' => '1bl2r7zngtgf', 'source_text' => 'CourseEditor2'],
    ['id' => 'region_mainpage:ct9vnl1b0v9x', 'component' => 'region_mainpage', 'key' => 'ct9vnl1b0v9x', 'source_text' => 'ProcessMonitor'],
    ['id' => 'region_footer-content-popover:e4ndme1lo133', 'component' => 'region_footer-content-popover', 'key' => 'e4ndme1lo133', 'source_text' => 'Reset user tour on this page'],
    ['id' => 'region_mainpage:xwupbrtur0pq', 'component' => 'region_mainpage', 'key' => 'xwupbrtur0pq', 'source_text' => 'CourseEditor3'],
    ['id' => 'region_mainpage:is43qlo6eih7', 'component' => 'region_mainpage', 'key' => 'is43qlo6eih7', 'source_text' => 'Import or export calendars'],
    ['id' => 'region_day:469dmp12qr7h', 'component' => 'region_day', 'key' => '469dmp12qr7h', 'source_text' => 'Today'],
    ['id' => 'region_mainpage:1it7irbchyb5', 'component' => 'region_mainpage', 'key' => '1it7irbchyb5', 'source_text' => 'Full calendar'],
    ['id' => 'region_calendar:neeii71uhkpn', 'component' => 'region_calendar', 'key' => 'neeii71uhkpn', 'source_text' => 'Sun'],
    ['id' => 'region_calendar:h9h01lz28zle', 'component' => 'region_calendar', 'key' => 'h9h01lz28zle', 'source_text' => 'Sat'],
    ['id' => 'region_calendar:1fcipcu17w18', 'component' => 'region_calendar', 'key' => '1fcipcu17w18', 'source_text' => 'Fri']
];

$task = new \local_xlate\task\translate_batch_task();
$task->set_custom_data((object)[
    'requestid' => uniqid('rb_'),
    'sourcelang' => 'en',
    'targetlang' => ['de'],
    'items' => $items,
    'glossary' => [],
    'options' => []
]);

$taskid = \core\task\manager::queue_adhoc_task($task);
echo "Queued task id: {$taskid}\n";
