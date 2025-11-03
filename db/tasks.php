<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_xlate\task\mlang_cleanup_task',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    [
        'classname' => 'local_xlate\task\autotranslate_missing_task',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '2',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
        'disabled' => 0,
        'customised' => 0
    ],
];
