<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_xlate_save_key' => [
        'classname' => 'local_xlate_external',
        'methodname' => 'save_key',
        'classpath' => 'local/xlate/classes/external.php',
        'description' => 'Save a translation key with translation',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/xlate:manage',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_xlate_get_key' => [
        'classname' => 'local_xlate_external',
        'methodname' => 'get_key',
        'classpath' => 'local/xlate/classes/external.php',
        'description' => 'Get a translation key by component and xkey',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/xlate:viewui',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_xlate_rebuild_bundles' => [
        'classname' => 'local_xlate_external',
        'methodname' => 'rebuild_bundles',
        'classpath' => 'local/xlate/classes/external.php',
        'description' => 'Rebuild all translation bundles',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/xlate:manage',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ]
];