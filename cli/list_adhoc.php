<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

global $DB;
$rows = $DB->get_records('task_adhoc');
if (empty($rows)) {
    echo "No adhoc tasks found.\n";
    exit(0);
}
foreach ($rows as $r) {
    echo "--- TASK id={$r->id} ---\n";
    foreach ($r as $k => $v) {
        echo "$k => " . (is_scalar($v) ? $v : json_encode($v)) . "\n";
    }
    echo "\n";
}
