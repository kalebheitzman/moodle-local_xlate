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
 * External service definitions for Local Xlate.
 *
 * Declares the web-service functions exposed to clients via Moodle’s web
 * service registry.
 *
 * @package    local_xlate
 * @category   services
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @var array<string,array<string,mixed>> External service function definitions exposed by the plugin.
 */
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
    'local_xlate_delete_translation' => [
        'classname' => 'local_xlate_external',
        'methodname' => 'delete_translation',
        'classpath' => 'local/xlate/classes/external.php',
        'description' => 'Delete a stored translation for a key/language pair',
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