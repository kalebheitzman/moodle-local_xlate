<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class local_xlate_external extends external_api {

    /**
     * Save key parameters
     * @return external_function_parameters
     */
    public static function save_key_parameters() {
        return new external_function_parameters([
            'component' => new external_value(PARAM_TEXT, 'Component identifier'),
            'key' => new external_value(PARAM_TEXT, 'Translation key'),
            'source' => new external_value(PARAM_TEXT, 'Source text', VALUE_DEFAULT, ''),
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
            'translation' => new external_value(PARAM_TEXT, 'Translation text'),
            'reviewed' => new external_value(PARAM_INT, 'Human reviewed flag', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_DEFAULT, 0),
            'context' => new external_value(PARAM_TEXT, 'Optional capture context', VALUE_DEFAULT, '')
        ]);
    }

    /**
     * Save a translation key
     * @param string $component
     * @param string $key
     * @param string $source
     * @param string $lang
     * @param string $translation
     * @return array
     */
    /**
     * Save a translation key
     * @param string $component
     * @param string $key
     * @param string $source
     * @param string $lang
     * @param string $translation
     * @param int $courseid
     * @param string $context
     * @return array
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
     * Save key returns
     * @return external_single_structure
     */
    public static function save_key_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'keyid' => new external_value(PARAM_INT, 'Key ID')
        ]);
    }

    /**
     * Get key parameters
     * @return external_function_parameters
     */
    public static function get_key_parameters() {
        return new external_function_parameters([
            'component' => new external_value(PARAM_TEXT, 'Component identifier'),
            'key' => new external_value(PARAM_TEXT, 'Translation key')
        ]);
    }

    /**
     * Get a translation key
     * @param string $component
     * @param string $key
     * @return array
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
     * Get key returns
     * @return external_single_structure
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
     * Rebuild bundles parameters
     * @return external_function_parameters
     */
    public static function rebuild_bundles_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Rebuild all translation bundles
     * @return array
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
     * Rebuild bundles returns
     * @return external_single_structure
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
     * Associate keys parameters
     * @return external_function_parameters
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
     * Associate multiple keys with a course (create keys if missing)
     * @param array $keys
     * @param int $courseid
     * @param string $context
     * @return array
     */
    public static function associate_keys($keys, $courseid, $context = '') {
        global $USER;

        $params = self::validate_parameters(self::associate_keys_parameters(), [
            'keys' => $keys,
            'courseid' => $courseid,
            'context' => $context
        ]);

        // Require login but allow any authenticated user to trigger associations
        require_login();

    $details = \local_xlate\local\api::associate_keys_with_course($params['keys'], (int)$params['courseid'], $params['context']);

        return [
            'success' => true,
            'details' => $details
        ];
    }

    /**
     * Associate keys returns
     * @return external_single_structure
     */
    public static function associate_keys_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'details' => new external_single_structure([], 'Details', VALUE_OPTIONAL)
        ]);
    }

    /**
     * Parameters for queuing a translation batch via AI backend.
     * @return external_function_parameters
     */
    public static function autotranslate_parameters() {
        return new external_function_parameters([
            'sourcelang' => new external_value(PARAM_TEXT, 'Source language code', VALUE_DEFAULT, 'en'),
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
     * Queue an adhoc task to translate a batch using the AI backend.
     * @param string $sourcelang
    * @param string|array $targetlang
     * @param array $items
     * @param array $glossary
     * @param array $options
     * @return array
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
     * Returns for autotranslate.
     * @return external_single_structure
     */
    public static function autotranslate_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'taskid' => new external_value(PARAM_INT, 'Queued task id')
        ]);
    }

    /**
     * Parameters for enqueuing a course-level autotranslate job.
     * @return external_function_parameters
     */
    public static function autotranslate_course_enqueue_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            // Options may include batchsize, targetlang (string or array) and sourcelang.
            'options' => new external_single_structure([
                'batchsize' => new external_value(PARAM_INT, 'Batch size', VALUE_DEFAULT, 50),
                'targetlang' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Target language code'),
                    'Target languages', VALUE_OPTIONAL
                ),
                'sourcelang' => new external_value(PARAM_TEXT, 'Source language code', VALUE_DEFAULT, 'en')
            ], 'Options', VALUE_DEFAULT, [])
        ]);
    }

    /**
     * Enqueue a course-level autotranslate job. Returns job id.
     * @param int $courseid
     * @param array $options
     * @return array
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

        // Count total keys associated with the course
        $total = 0;
        try {
            $total = $DB->count_records('local_xlate_key_course', ['courseid' => $params['courseid']]);
        } catch (\Exception $e) {
            $total = 0;
        }

        $record = new \stdClass();
        $record->courseid = (int)$params['courseid'];
        $record->userid = isset($USER->id) ? (int)$USER->id : 0;
        $record->status = 'pending';
        $record->total = (int)$total;
        $record->processed = 0;
        $record->batchsize = isset($params['options']['batchsize']) ? (int)$params['options']['batchsize'] : 50;
        $record->options = !empty($params['options']) ? json_encode($params['options']) : null;
        $record->lastid = 0;
        $record->ctime = time();
        $record->mtime = time();

        $jobid = $DB->insert_record('local_xlate_course_job', $record);

        $task = new \local_xlate\task\translate_course_task();
        $task->set_custom_data((object)['jobid' => $jobid]);
        $taskid = \core\task\manager::queue_adhoc_task($task);

        return ['success' => true, 'jobid' => $jobid, 'taskid' => $taskid];
    }

    public static function autotranslate_course_enqueue_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success'),
            'jobid' => new external_value(PARAM_INT, 'Job id'),
            'taskid' => new external_value(PARAM_INT, 'Queued task id')
        ]);
    }

    /**
     * Parameters for polling a course-level autotranslate job.
     * @return external_function_parameters
     */
    public static function autotranslate_course_progress_parameters() {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_INT, 'Job id')
        ]);
    }

    /**
     * Poll for progress of a course autotranslate job.
     * @param int $jobid
     * @return array
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
     * Parameters for polling autotranslate progress.
     * @return external_function_parameters
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
     * Poll for progress of autotranslate by checking persisted translations for the given items.
     * Returns an array of {id, translated, translation} entries.
     * @param array $items
     * @param string $targetlang
     * @return array
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
     * Returns for autotranslate_progress.
     * @return external_single_structure
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