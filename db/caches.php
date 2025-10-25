<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    'bundle' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 20, // Increased for context-specific bundles
        'ttl' => 1800, // 30 minutes (shorter for context-sensitive data)
    ],
    'keymap' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'ttl' => 3600,
    ],
];
