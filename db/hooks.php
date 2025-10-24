<?php

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../classes/hooks/output.php');

$callbacks = [
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \local_xlate\hooks\output::class . '::before_head',
        // Execute early to minimise FOUT and ensure our CSS is present.
        'priority' => -1000,
    ],
    [
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => \local_xlate\hooks\output::class . '::before_body',
        // Execute early so our bootloader runs promptly.
        'priority' => -1000,
    ],
];
