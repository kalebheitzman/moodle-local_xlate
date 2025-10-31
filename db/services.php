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
    ,
    'local_xlate_associate_keys' => [
        'classname' => 'local_xlate_external',
        'methodname' => 'associate_keys',
        'classpath' => 'local/xlate/classes/external.php',
        'description' => 'Associate multiple keys with a course (create keys if missing)',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ]
    ,
    'local_xlate_autotranslate' => [
        'classname' => 'local_xlate_external',
        'methodname' => 'autotranslate',
        'classpath' => 'local/xlate/classes/external.php',
        'description' => 'Queue an adhoc translation batch via AI backend',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/xlate:manage',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_xlate_autotranslate_progress' => [
        'classname' => 'local_xlate_external',
        'methodname' => 'autotranslate_progress',
        'classpath' => 'local/xlate/classes/external.php',
        'description' => 'Poll persisted translation progress for autotranslate',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/xlate:viewui',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ]
    ,
    'local_xlate_autotranslate_course_enqueue' => [
        'classname' => 'local_xlate_external',
        'methodname' => 'autotranslate_course_enqueue',
        'classpath' => 'local/xlate/classes/external.php',
        'description' => 'Enqueue a course-level autotranslate job',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/xlate:manage',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_xlate_autotranslate_course_progress' => [
        'classname' => 'local_xlate_external',
        'methodname' => 'autotranslate_course_progress',
        'classpath' => 'local/xlate/classes/external.php',
        'description' => 'Poll progress for a course autotranslate job',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/xlate:viewui',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ]
];