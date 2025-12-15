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
 * External web service endpoints for Local Xlate.
 *
 * Exposes translation capture, glossary operations, and batch submission
 * methods to authenticated clients via the Moodle external API framework.
 *
 * @package    local_xlate
 * @category   external
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class local_xlate_external extends external_api {

    /**
     * Describe the parameters accepted by {@see self::save_key()}.
     *
     * @return external_function_parameters Parameter schema used by Moodle external API validation.
     */
    public static function save_key_parameters() {
        return new external_function_parameters([
            'component' => new external_value(PARAM_COMPONENT, 'Component identifier'),
            'key' => new external_value(PARAM_TEXT, 'Translation key'),
            'source' => new external_value(PARAM_RAW_TRIMMED, 'Source text (may include inline HTML)', VALUE_DEFAULT, ''),
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
            'translation' => new external_value(PARAM_RAW_TRIMMED, 'Translation text (may include inline HTML)'),
            'reviewed' => new external_value(PARAM_BOOL, 'Human reviewed flag', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_DEFAULT, 0),
            'context' => new external_value(PARAM_TEXT, 'Optional capture context', VALUE_DEFAULT, '')
        ]);
    }

    /**
     * Persist a translated string through the Local Xlate API.
     *
    * Validates parameters, checks the caller has the {@code local/xlate:manage}
    * capability, and stores both the translation and optional course
     * association. Returns a success flag and the numeric key id for further
     * processing.
     *
     * @param string $component Component responsible for the translation key.
     * @param string $key Stable translation key identifier.
     * @param string $source Source language text for context.
     * @param string $lang Target language code to persist.
     * @param string $translation Translated text to store.
     * @param int $reviewed Whether the translation has a human review flag set.
     * @param int $courseid Course id to associate with the key (0 for global scope).
     * @param string $context Optional capture context string for auditing.
     * @return array Response payload containing success flag and key id.
     */
    public static function save_key($component, $key, $source, $lang, $translation, $reviewed = 0, $courseid = 0, $context = '') {
        global $USER;

        $params = self::validate_parameters(self::save_key_parameters(), [
            'component' => $component,
            'key' => $key,
            'source' => $source,
            'lang' => $lang,
            'translation' => $translation,
            'reviewed' => $reviewed,
            'courseid' => $courseid,
            'context' => $context
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/xlate:manage', $context);

        $keyid = \local_xlate\local\api::save_key_with_translation(
            $params['component'],
            $params['key'],
            $params['source'],
            $params['lang'],
            $params['translation'],
            (int)$params['reviewed'],
            (int)$params['courseid'],
            $params['context']
        );

        return [
            'success' => true,
            'keyid' => $keyid
        ];
    }

    /**
     * Describe the structure returned by {@see self::save_key()}.
     *
     * @return external_single_structure API response definition for clients.
     */
    public static function save_key_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'keyid' => new external_value(PARAM_INT, 'Key ID')
        ]);
    }

    /**
     * Describe the parameters accepted by {@see self::get_key()}.
     *
     * @return external_function_parameters Parameter schema for validation.
     */
    public static function get_key_parameters() {
        return new external_function_parameters([
            'component' => new external_value(PARAM_TEXT, 'Component identifier'),
            'key' => new external_value(PARAM_TEXT, 'Translation key')
        ]);
    }

    /**
     * Retrieve a translation key record by component + key pair.
     *
     * Ensures the caller has {@code local/xlate:viewui} capability before
     * returning limited key metadata.
     *
     * @param string $component Component identifier to search within.
     * @param string $key Translation key to retrieve.
     * @return array Response containing the serialized key record or null.
     */
    public static function get_key($component, $key) {
        $params = self::validate_parameters(self::get_key_parameters(), [
            'component' => $component,
            'key' => $key
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/xlate:viewui', $context);

        $keydata = \local_xlate\local\api::get_key_by_component_xkey(
            $params['component'],
            $params['key']
        );

        return [
            'success' => true,
            'key' => $keydata ? (array)$keydata : null
        ];
    }

    /**
     * Describe the structure returned by {@see self::get_key()}.
     *
     * @return external_single_structure API response definition for clients.
     */
    public static function get_key_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'key' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Key ID'),
                'component' => new external_value(PARAM_TEXT, 'Component'),
                'xkey' => new external_value(PARAM_TEXT, 'Translation key'),
                'source' => new external_value(PARAM_TEXT, 'Source text'),
                'mtime' => new external_value(PARAM_INT, 'Modified time')
            ], 'Key data', VALUE_OPTIONAL)
        ]);
    }

    /**
     * Describe the parameters accepted by {@see self::rebuild_bundles()}.
     *
     * @return external_function_parameters Empty parameter definition.
     */
    public static function rebuild_bundles_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Rebuild all translation bundles by recomputing version hashes.
     *
     * @return array{success:bool,rebuilt:array<int,string>} Response payload consumed by clients.
     */
    public static function rebuild_bundles() {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/xlate:manage', $context);

        $rebuilt = \local_xlate\local\api::rebuild_all_bundles();

        return [
            'success' => true,
            'rebuilt' => $rebuilt
        ];
    }

    /**
     * Describe the response produced by {@see self::rebuild_bundles()}.
     *
     * @return external_single_structure API response definition for clients.
     */
    public static function rebuild_bundles_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'rebuilt' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Language code'),
                'Languages rebuilt'
            )
        ]);
    }

    /**
     * Describe the parameters accepted by {@see self::associate_keys()}.
     *
     * @return external_function_parameters Parameter schema for validation.
     */
    public static function associate_keys_parameters() {
        return new external_function_parameters([
            'keys' => new external_multiple_structure(
                new external_single_structure([
                    'component' => new external_value(PARAM_TEXT, 'Component identifier'),
                    'key' => new external_value(PARAM_TEXT, 'Translation key'),
                    'source' => new external_value(PARAM_TEXT, 'Source text', VALUE_DEFAULT, '')
                ]), 'Keys to associate'
            ),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'context' => new external_value(PARAM_TEXT, 'Optional capture context', VALUE_DEFAULT, '')
        ]);
    }

    /**
     * Associate a batch of keys with a course, creating missing keys.
     *
     * Validates the caller input, enforces authentication, and delegates to the
     * API layer to ensure keys exist and are linked with the provided course
     * id. Returns per-key status details to aid client reconciliation.
     *
     * @param array<int,array{component:string,key:string,source?:string}> $keys Collection of component/key/source triples.
     * @param int $courseid Course id to attach the keys to.
     * @param string $context Optional string describing capture context.
     * @return array{success:bool,details:array<string,string>} Response containing success flag and association details.
     */
    public static function associate_keys($keys, $courseid, $context = '') {
        global $USER;

        $params = self::validate_parameters(self::associate_keys_parameters(), [
            'keys' => $keys,
            'courseid' => $courseid,
            'context' => $context
        ]);

        require_login();

        $courseid = (int)$params['courseid'];
        if ($courseid <= 0) {
            throw new invalid_parameter_exception('Invalid course id.');
        }

        $course = get_course($courseid);
        $coursecontext = \context_course::instance($course->id);

        if (has_capability('local/xlate:managecourse', $coursecontext)) {
            require_capability('local/xlate:managecourse', $coursecontext);
        } else {
            require_capability('local/xlate:manage', \context_system::instance());
        }

        // Ensure the caller is at least enrolled when relying on the course capability.
        if (!is_enrolled($coursecontext, $USER, '', true) && !has_capability('local/xlate:manage', \context_system::instance())) {
            throw new required_capability_exception($coursecontext, 'local/xlate:managecourse', 'nopermissions', 'local_xlate');
        }

        $maxkeys = 200;
        if (count($params['keys']) > $maxkeys) {
            throw new invalid_parameter_exception('Too many keys requested; max ' . $maxkeys . ' per request.');
        }

        $sanitised = [];
        foreach ($params['keys'] as $k) {
            $xkey = trim($k['key']);
            if ($xkey === '') {
                continue;
            }
            $component = trim($k['component']);
            if ($component === '') {
                $component = 'core';
            }
            $source = isset($k['source']) ? clean_param($k['source'], PARAM_TEXT) : '';
            $sanitised[] = [
                'component' => $component,
                'xkey' => $xkey,
                'source' => $source
            ];
        }

        if (empty($sanitised)) {
            return [
                'success' => true,
                'details' => []
            ];
        }

        $details = \local_xlate\local\api::associate_keys_with_course($sanitised, $courseid, $params['context']);

        return [
            'success' => true,
            'details' => $details
        ];
    }

    /**
     * Describe the response produced by {@see self::associate_keys()}.
     *
     * @return external_single_structure API response definition for clients.
     */
    public static function associate_keys_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'details' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_TEXT, 'Translation key identifier', VALUE_OPTIONAL),
                    'status' => new external_value(PARAM_TEXT, 'Association status label', VALUE_OPTIONAL)
                ]),
                'Per-key association outcomes',
                VALUE_OPTIONAL
            )
        ]);
    }

    /**
     * Describe the parameters accepted by {@see self::autotranslate()}.
     *
     * @return external_function_parameters Parameter schema covering items/glossary/options.
     */
    public static function autotranslate_parameters() {
        global $CFG;
        return new external_function_parameters([
            'sourcelang' => new external_value(PARAM_TEXT, 'Source language code', VALUE_DEFAULT, $CFG->lang),
            // Accept either a single target language string or an array of target languages.
            'targetlang' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Target language code'),
                'Target languages'
            ),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_TEXT, 'Item id'),
                    'component' => new external_value(PARAM_TEXT, 'Optional component for persistence', VALUE_DEFAULT, ''),
                    'key' => new external_value(PARAM_TEXT, 'Optional translation key for persistence', VALUE_DEFAULT, ''),
                    'source_text' => new external_value(PARAM_TEXT, 'Source text'),
                    'placeholders' => new external_multiple_structure(new external_value(PARAM_TEXT, 'placeholder'), 'Placeholders', VALUE_DEFAULT, [])
                ]),
                'Items to translate'
            ),
            'glossary' => new external_multiple_structure(
                new external_single_structure([
                    'term' => new external_value(PARAM_TEXT, 'Glossary term'),
                    'replacement' => new external_value(PARAM_TEXT, 'Preferred translation')
                ]),
                'Glossary entries',
                VALUE_DEFAULT,
                []
            ),
            'options' => new external_single_structure([], 'Options', VALUE_DEFAULT, [])
        ]);
    }

    /**
     * Queue an adhoc batch translation task via the AI backend.
     *
     * Performs validation, capability checks, and then schedules
     * {@see \\local_xlate\\task\\translate_batch_task} with the provided
     * items, glossary, and options. Throws if validation fails or the task
     * cannot be queued.
     *
     * @param string $sourcelang Source language code for submitted items.
     * @param array<int,string>|string $targetlang One or more target language codes.
     * @param array<int,array{id:string,component?:string,key?:string,source_text:string,placeholders?:array<int,string>}> $items Collection of item records to translate.
     * @param array<int,array{term:string,replacement:string}> $glossary Optional glossary overrides.
     * @param array<string,mixed> $options Optional backend tuning parameters.
     * @return array{success:bool,taskid:int} Response containing success flag and queued task id.
     */
    public static function autotranslate($sourcelang, $targetlang, $items, $glossary = [], $options = []) {
        global $USER;
        try {
            $params = self::validate_parameters(self::autotranslate_parameters(), [
                'sourcelang' => $sourcelang,
                'targetlang' => $targetlang,
                'items' => $items,
                'glossary' => $glossary,
                'options' => $options,
            ]);
        } catch (\invalid_parameter_exception $e) {
            // Log the raw inputs to help diagnose validation failures at developer level.
            $dump = '';
            try {
                $dump = json_encode([
                    'sourcelang' => $sourcelang,
                    'targetlang' => $targetlang,
                    'items' => $items,
                    'glossary' => $glossary,
                    'options' => $options
                ], JSON_PARTIAL_OUTPUT_ON_ERROR);
            } catch (\Exception $ex) {
                $dump = 'Failed to json_encode inputs: ' . $ex->getMessage();
            }
            debugging('[local_xlate] autotranslate parameter validation failed: ' . $e->getMessage() . ' inputs=' . $dump, DEBUG_DEVELOPER);
            // Rethrow so the external API returns the same invalid parameter error to the client.
            throw $e;
        }

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/xlate:manage', $context);

        try {
            // Minimal validation: ensure items is non-empty.
            if (empty($params['items'])) {
                throw new \invalid_parameter_exception('No items to translate');
            }

            // Debug: log invocation parameters at developer level to help diagnose failures.
            debugging('[local_xlate] autotranslate called: sourcelang=' . $params['sourcelang'] . ', targetlang=' . json_encode($params['targetlang']) . ', items=' . count($params['items']), DEBUG_DEVELOPER);

            $task = new \local_xlate\task\translate_batch_task();
            $task->set_custom_data((object)[
                'requestid' => uniqid('rb_'),
                'sourcelang' => $params['sourcelang'],
                // Pass the target languages through (string or array). The task
                // already supports iterating multiple target languages.
                'targetlang' => $params['targetlang'],
                'items' => $params['items'],
                'glossary' => $params['glossary'],
                'options' => $params['options'] ?? []
            ]);

            $taskid = \core\task\manager::queue_adhoc_task($task);

            debugging('[local_xlate] autotranslate queued task id: ' . $taskid, DEBUG_DEVELOPER);

            return ['success' => true, 'taskid' => $taskid];
        } catch (\Exception $e) {
            // Log full exception details to developer debug log for diagnosis, then rethrow so the external API returns an error.
            debugging('[local_xlate] autotranslate failed: ' . $e->getMessage() . '\n' . $e->getTraceAsString(), DEBUG_DEVELOPER);
            throw $e;
        }
    }

    /**
     * Describe the response produced by {@see self::autotranslate()}.
     *
     * @return external_single_structure API response definition for clients.
     */
    public static function autotranslate_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'taskid' => new external_value(PARAM_INT, 'Queued task id')
        ]);
    }

    /**
     * Describe the parameters accepted by {@see self::autotranslate_course_enqueue()}.
     *
     * @return external_function_parameters Parameter schema covering course id and options.
     */
    public static function autotranslate_course_enqueue_parameters() {
        global $CFG;
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            // Options may include batchsize, targetlang (string or array) and sourcelang.
            'options' => new external_single_structure([
                'batchsize' => new external_value(PARAM_INT, 'Batch size', VALUE_DEFAULT, 50),
                'targetlang' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Target language code'),
                    'Target languages', VALUE_OPTIONAL
                ),
                'sourcelang' => new external_value(PARAM_TEXT, 'Source language code', VALUE_DEFAULT, $CFG->lang)
            ], 'Options', VALUE_DEFAULT, [])
        ]);
    }

    /**
     * Enqueue a course-scoped autotranslation job and queue its adhoc task.
     *
     * Validates input, ensures the caller has {@code local/xlate:manage},
     * persists a row in {@code local_xlate_course_job}, and schedules
     * {@see \\local_xlate\\task\\translate_course_task}.
     *
     * @param int $courseid Course identifier to translate.
     * @param array<string,mixed> $options Optional job options (batch size, languages, etc.).
     * @return array{success:bool,jobid:int,taskid:int} Response containing success flag, job id, and task id.
     */
    public static function autotranslate_course_enqueue($courseid, $options = []) {
        global $DB, $USER;

        $params = self::validate_parameters(self::autotranslate_course_enqueue_parameters(), [
            'courseid' => $courseid,
            'options' => $options
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/xlate:manage', $context);

        // Check if course has xlate language configuration
        $config = \local_xlate\customfield_helper::get_course_config((int)$params['courseid']);
        if ($config === null) {
            throw new \moodle_exception('Course has no xlate language configuration. Please set source and target languages in course settings.');
        }

        $sourcelang = $config['source'];

        $installedlangs = array_keys(get_string_manager()->get_list_of_translations());
        $requestedtargets = [];
        if (!empty($params['options']['targetlang'])) {
            $requestedtargets = (array)$params['options']['targetlang'];
        } elseif (!empty($params['options']['targetlangs'])) {
            $requestedtargets = (array)$params['options']['targetlangs'];
        }
        $requestedtargets = array_values(array_unique(array_filter(array_map('trim', $requestedtargets), function ($code) {
            return $code !== '';
        })));
        if (!empty($requestedtargets)) {
            $requestedtargets = array_values(array_intersect($requestedtargets, $installedlangs));
        }
        $requestedtargets = array_values(array_filter($requestedtargets, function ($code) use ($sourcelang) {
            return $code && $code !== $sourcelang;
        }));

        $defaultTargets = array_values(array_filter($config['targets'], function ($code) use ($sourcelang) {
            return $code && $code !== $sourcelang;
        }));

        $targetlangs = !empty($requestedtargets) ? $requestedtargets : $defaultTargets;

        if (empty($targetlangs)) {
            throw new \moodle_exception('Course has no target languages configured. Please select at least one target language in course settings or the Manage UI card.');
        }

        // Count total keys associated with the course
        $total = 0;
        try {
            $total = $DB->count_records('local_xlate_key_course', ['courseid' => $params['courseid']]);
        } catch (\Exception $e) {
            $total = 0;
        }

        // Merge course config into options
        $merged_options = $params['options'] ?: [];
        $merged_options['sourcelang'] = $sourcelang;
        $merged_options['targetlangs'] = $targetlangs;

        $record = new \stdClass();
        $record->courseid = (int)$params['courseid'];
        $record->userid = isset($USER->id) ? (int)$USER->id : 0;
        $record->status = 'pending';
        $record->total = (int)$total;
        $record->processed = 0;
        $record->batchsize = isset($params['options']['batchsize']) ? (int)$params['options']['batchsize'] : 50;
        $record->options = json_encode($merged_options);
        $record->lastid = 0;
        $record->ctime = time();
        $record->mtime = time();

        $jobid = $DB->insert_record('local_xlate_course_job', $record);

        $task = new \local_xlate\task\translate_course_task();
        $task->set_custom_data((object)['jobid' => $jobid]);
        $taskid = \core\task\manager::queue_adhoc_task($task);

        return ['success' => true, 'jobid' => $jobid, 'taskid' => $taskid];
    }

    /**
     * Describe the response produced by {@see self::autotranslate_course_enqueue()}.
     *
     * @return external_single_structure API response definition for clients.
     */
    public static function autotranslate_course_enqueue_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'jobid' => new external_value(PARAM_INT, 'Job id'),
            'taskid' => new external_value(PARAM_INT, 'Queued task id')
        ]);
    }

    /**
     * Describe the parameters accepted by {@see self::autotranslate_course_progress()}.
     *
     * @return external_function_parameters Parameter schema for validation.
     */
    public static function autotranslate_course_progress_parameters() {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_INT, 'Job id')
        ]);
    }

    /**
     * Retrieve progress information for a queued course translation job.
     *
     * Ensures the caller holds {@code local/xlate:viewui} capability before
     * exposing job metadata to the client.
     *
     * @param int $jobid Job identifier to inspect.
     * @return array{success:bool,job?:array<string,int|string>,error?:string} Response containing job summary data or error flag.
     */
    public static function autotranslate_course_progress($jobid) {
        global $DB;

        $params = self::validate_parameters(self::autotranslate_course_progress_parameters(), ['jobid' => $jobid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/xlate:viewui', $context);

        $job = $DB->get_record('local_xlate_course_job', ['id' => $params['jobid']]);
        if (!$job) {
            return ['success' => false, 'error' => 'Job not found'];
        }

        return [
            'success' => true,
            'job' => [
                'id' => (int)$job->id,
                'courseid' => (int)$job->courseid,
                'status' => (string)$job->status,
                'total' => (int)$job->total,
                'processed' => (int)$job->processed,
                'batchsize' => (int)$job->batchsize,
                'lastid' => (int)$job->lastid,
                'mtime' => (int)$job->mtime,
                'ctime' => (int)$job->ctime,
            ]
        ];
    }

    /**
     * Describe the response produced by {@see self::autotranslate_course_progress()}.
     *
     * @return external_single_structure API response definition for clients.
     */
    public static function autotranslate_course_progress_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'job' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Job id'),
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'status' => new external_value(PARAM_TEXT, 'Job status'),
                'total' => new external_value(PARAM_INT, 'Total items'),
                'processed' => new external_value(PARAM_INT, 'Processed items'),
                'batchsize' => new external_value(PARAM_INT, 'Batch size'),
                'lastid' => new external_value(PARAM_INT, 'Last processed cursor id'),
                'mtime' => new external_value(PARAM_INT, 'Modified time'),
                'ctime' => new external_value(PARAM_INT, 'Created time')
            ])
        ]);
    }

    /**
     * Describe the parameters accepted by {@see self::autotranslate_progress()}.
     *
     * @return external_function_parameters Parameter schema for validation.
     */
    public static function autotranslate_progress_parameters() {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Item id in the form component:key'),
                'Items to check'
            ),
            'targetlang' => new external_value(PARAM_TEXT, 'Target language code')
        ]);
    }

    /**
     * Determine translation status for specific items and target language.
     *
     * Checks stored translations for the supplied keys and returns
     * {id, translated, translation} tuples so the client UI can update in
     * near real time.
     *
     * @param array<int,string> $items Array of item identifiers (`component:key` or just key).
     * @param string $targetlang Target language to check.
     * @return array{success:bool,results:array<int,array{id:string,translated:bool,translation:?string}>} Response containing success flag and per-item results.
     */
    public static function autotranslate_progress($items, $targetlang) {
        global $DB;

        $params = self::validate_parameters(self::autotranslate_progress_parameters(), [
            'items' => $items,
            'targetlang' => $targetlang
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/xlate:viewui', $context);

        $results = [];
        foreach ($params['items'] as $idparam) {
            // Accept either 'component:key' or just 'key' as input. We will return the
            // plain key (hash) in the response as the caller requested.
            $parts = explode(':', $idparam, 2);
            if (count($parts) === 2) {
                $component = $parts[0];
                $xkey = $parts[1];
            } else {
                $component = '';
                $xkey = $idparam;
            }

            // If we don't have a component, try to find a key record by xkey only.
            if (!empty($component)) {
                $keydata = $DB->get_record('local_xlate_key', ['component' => $component, 'xkey' => $xkey]);
            } else {
                // Note: get_record will return the first matching record; if there are
                // multiple records with the same xkey across components, this will
                // pick one arbitrarily. That's acceptable for the UI polling use-case
                // where the xkey is the unique identifier in practice.
                $keydata = $DB->get_record('local_xlate_key', ['xkey' => $xkey]);
            }
            if (!$keydata) {
                $results[] = [
                    'id' => $xkey,
                    'translated' => false,
                    'translation' => null
                ];
                continue;
            }
            $tr = $DB->get_record('local_xlate_tr', ['keyid' => $keydata->id, 'lang' => $params['targetlang']]);
            if ($tr) {
                // Sanitize translation text to ensure it meets external API return
                // validation (no NUL bytes or other control characters, valid UTF-8).
                $translation = isset($tr->text) ? (string)$tr->text : '';
                // Remove NUL and other C0/C1 control chars except common allowed (tab, newline, carriage return).
                $translation = preg_replace('/[\x00\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $translation);
                // Ensure valid UTF-8 and strip any invalid sequences.
                if (!mb_check_encoding($translation, 'UTF-8')) {
                    $translation = mb_convert_encoding($translation, 'UTF-8', 'UTF-8');
                }

                $results[] = [
                    // Return only the key/hash portion to the caller.
                    'id' => $xkey,
                    'translated' => true,
                    'translation' => $translation
                ];
            } else {
                $results[] = [
                    'id' => $xkey,
                    'translated' => false,
                    'translation' => null
                ];
            }
        }

        return ['success' => true, 'results' => $results];
    }

    /**
     * Describe the response produced by {@see self::autotranslate_progress()}.
     *
     * @return external_single_structure API response definition for clients.
     */
    public static function autotranslate_progress_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_TEXT, 'Item id'),
                    'translated' => new external_value(PARAM_BOOL, 'Whether translation exists'),
                    'translation' => new external_value(PARAM_TEXT, 'Translation text', VALUE_OPTIONAL, null)
                ]),
                'Per-item progress'
            )
        ]);
    }
}