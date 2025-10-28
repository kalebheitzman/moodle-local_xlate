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
    public static function save_key($component, $key, $source, $lang, $translation, $courseid = 0, $context = '') {
        global $USER;

        $params = self::validate_parameters(self::save_key_parameters(), [
            'component' => $component,
            'key' => $key,
            'source' => $source,
            'lang' => $lang,
            'translation' => $translation,
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
}