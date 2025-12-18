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
 * Helper for managing Xlate custom fields.
 *
 * @package    local_xlate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper class for creating and managing custom fields.
 */
class customfield_helper {
    private const ENABLE_FIELD = 'xlate_enable';

    /**
     * Cached list of installed languages keyed by lang code.
     *
     * @var array<string,string>
     */
    protected static $installedlangs = [];

    /**
     * Cached enable-field id lookup.
     *
     * @var int|null
     */
    protected static $enablefieldid = null;

    /**
     * Create or update xlate custom fields category and fields.
     *
     * @return void
     */
    public static function setup_customfields(): void {
        global $DB;

        // Get or create the Xlate category for course custom fields
        $category = self::get_or_create_category();

        // Create enable toggle first so it appears before other fields.
        self::create_enable_field($category);

        // Create source language field (select)
        self::create_source_language_field($category);

        // Create target languages field (multiselect)
        self::create_target_languages_field($category);

        // Ensure expected ordering (toggle, source, then targets).
        self::ensure_field_sortorder($category->get('id'), self::ENABLE_FIELD, 0);
        self::ensure_field_sortorder($category->get('id'), 'xlate_source_lang', 1);
    }

    /**
     * Create the enable/disable checkbox field.
     *
     * @param \core_customfield\category_controller $category
     * @return void
     */
    protected static function create_enable_field(\core_customfield\category_controller $category): void {
        global $DB;

        $existing = $DB->get_record('customfield_field', [
            'categoryid' => $category->get('id'),
            'shortname' => self::ENABLE_FIELD
        ]);

        if ($existing) {
            self::$enablefieldid = (int)$existing->id;
            return;
        }

        $data = (object)[
            'type' => 'checkbox',
            'name' => get_string('xlate_course_enable', 'local_xlate'),
            'shortname' => self::ENABLE_FIELD,
            'description' => get_string('xlate_course_enable_help', 'local_xlate'),
            'descriptionformat' => FORMAT_HTML,
            'categoryid' => $category->get('id'),
            'sortorder' => 0,
            'configdata' => json_encode([
                'required' => 0,
                'uniquevalues' => 0,
                'locked' => 0,
                'visibility' => 2,
                'checkbydefault' => 0,
            ]),
        ];

        $field = \core_customfield\field_controller::create(0, $data);
        $field->save();
        self::$enablefieldid = (int)$field->get('id');
    }

    /**
     * Get or create the Xlate custom field category.
     *
     * @return \core_customfield\category_controller
     */
    protected static function get_or_create_category(): \core_customfield\category_controller {
        global $DB;
        
        $handler = \core_customfield\handler::get_handler('core_course', 'course');
        $component = $handler->get_component();
        $area = $handler->get_area();
        $itemid = $handler->get_itemid();
        
        // Check if category already exists
        $existing = $DB->get_record('customfield_category', [
            'component' => $component,
            'area' => $area,
            'itemid' => $itemid,
            'name' => 'Xlate'
        ]);
        
        if ($existing) {
            return \core_customfield\category_controller::create($existing->id);
        }

        // Create new category
        $data = (object)[
            'name' => 'Xlate',
            'component' => $component,
            'area' => $area,
            'itemid' => $itemid,
            'sortorder' => 0,
        ];
        
        $category = \core_customfield\category_controller::create(0, $data);
        $category->save();
        
        return $category;
    }

    /**
     * Create the source language field.
     *
     * @param \core_customfield\category_controller $category
     * @return void
     */
    protected static function create_source_language_field(\core_customfield\category_controller $category): void {
        global $DB;

        // Check if field already exists
        $existing = $DB->get_record('customfield_field', [
            'categoryid' => $category->get('id'),
            'shortname' => 'xlate_source_lang'
        ]);
        
        if ($existing) {
            return; // Already exists
        }

        // Get installed languages in a predictable display order.
        $installedlangs = self::get_installed_languages();
        if (!empty($installedlangs)) {
            asort($installedlangs, SORT_NATURAL | SORT_FLAG_CASE);
        }
        $options = [];
        foreach ($installedlangs as $code => $name) {
            $options[] = $name;
        }

        // Create the field
        $data = (object)[
            'type' => 'select',
            'name' => get_string('xlate_course_source_lang', 'local_xlate'),
            'shortname' => 'xlate_source_lang',
            'description' => get_string('xlate_course_source_lang_help', 'local_xlate'),
            'descriptionformat' => FORMAT_HTML,
            'categoryid' => $category->get('id'),
            'sortorder' => 0,
            'configdata' => json_encode([
                'required' => 0,
                'uniquevalues' => 0,
                'locked' => 0,
                'visibility' => 2,
                'defaultvalue' => 'English',
                'options' => implode("\n", $options),
            ]),
        ];

        $field = \core_customfield\field_controller::create(0, $data);
        $field->save();
    }

    /**
     * Create the target languages field.
     *
     * @param \core_customfield\category_controller $category
     * @return void
     */
    protected static function create_target_languages_field(\core_customfield\category_controller $category): void {
        global $DB;

        // Get installed languages in a predictable display order.
        $installedlangs = self::get_installed_languages();
        if (!empty($installedlangs)) {
            asort($installedlangs, SORT_NATURAL | SORT_FLAG_CASE);
        }
        
        // Create a checkbox field for each installed language
        $sortorder = 2;
        foreach ($installedlangs as $code => $name) {
            $shortname = 'xlate_target_' . $code;
            
            // Check if field already exists
            $existing = $DB->get_record('customfield_field', [
                'categoryid' => $category->get('id'),
                'shortname' => $shortname
            ]);
            
            if ($existing) {
                $sortorder++;
                continue; // Already exists
            }

            // Create checkbox field for this language
            $data = (object)[
                'type' => 'checkbox',
                'name' => $name,
                'shortname' => $shortname,
                'description' => '',
                'descriptionformat' => FORMAT_HTML,
                'categoryid' => $category->get('id'),
                'sortorder' => $sortorder,
                'configdata' => json_encode([
                    'required' => 0,
                    'uniquevalues' => 0,
                    'locked' => 0,
                    'visibility' => 2,
                    'checkbydefault' => 0,
                ]),
            ];

            $field = \core_customfield\field_controller::create(0, $data);
            $field->save();
            $sortorder++;
        }
    }

    /**
     * Get course source language from custom field.
     *
     * @param int $courseid
     * @return string|null Language code or null if not configured
     */
    public static function get_course_source_lang(int $courseid): ?string {
        if (!$courseid) {
            return null;
        }
        
        $handler = \core_customfield\handler::get_handler('core_course', 'course');
        $datas = $handler->get_instance_data($courseid);
        $installedlangs = self::get_installed_languages();
        
        foreach ($datas as $data) {
            $field = $data->get_field();
            if ($field->get('shortname') !== 'xlate_source_lang') {
                continue;
            }

            $value = trim((string)$data->get_value());
            if ($value === '') {
                return null;
            }

            $value = self::map_select_index_to_option($value, $field);

            $normalized = self::normalize_lang_value($value, $installedlangs);
            return $normalized ?? $value;
        }
        
        return null;
    }

    /**
     * Get course language configuration.
     *
     * @param int $courseid
     * @return array{source:string|null,targets:array}|null Configuration or null if not set
     */
    public static function get_course_config(int $courseid): ?array {
        if (!$courseid) {
            return null;
        }

        if (!self::is_course_enabled($courseid)) {
            return null;
        }

        $sourcelang = self::get_course_source_lang($courseid);
        $targetlangs = self::get_course_target_langs($courseid);

        // Only return config if source language is set
        if ($sourcelang === null) {
            return null;
        }

        return [
            'source' => $sourcelang,
            'targets' => $targetlangs
        ];
    }

    /**
     * Get course target languages from custom fields.
     *
     * @param int $courseid
     * @return array Array of language codes
     */
    public static function get_course_target_langs(int $courseid): array {
        $handler = \core_customfield\handler::get_handler('core_course', 'course');
        $datas = $handler->get_instance_data($courseid);
        
        $targetlangs = [];
        foreach ($datas as $data) {
            $shortname = $data->get_field()->get('shortname');
            // Check if it's a target language checkbox (starts with xlate_target_)
            if (strpos($shortname, 'xlate_target_') === 0 && $data->get_value()) {
                // Extract language code from shortname (e.g., xlate_target_es -> es)
                $langcode = substr($shortname, 13);
                $targetlangs[] = $langcode;
            }
        }
        
        return array_values(array_unique($targetlangs));
    }

    /**
     * Determine whether the course-level toggle allows Xlate to run.
     *
     * @param int $courseid
     * @return bool True when course is enabled or toggle missing.
     */
    public static function is_course_enabled(int $courseid): bool {
        if ($courseid <= 0) {
            return true;
        }

        $handler = \core_customfield\handler::get_handler('core_course', 'course');
        $datas = $handler->get_instance_data($courseid);

        foreach ($datas as $data) {
            $field = $data->get_field();
            if ($field->get('shortname') !== self::ENABLE_FIELD) {
                continue;
            }

            $value = $data->get_value();
            if ($value === '' || $value === null) {
                return true;
            }

            return (bool)$value;
        }

        return true;
    }

    /**
     * Fetch the list of courses that currently allow Local Xlate to run.
     *
     * @return array<int,int> Sequential array of course ids.
     */
    public static function get_enabled_course_ids(): array {
        global $DB;

        $courseids = $DB->get_fieldset_select('course', 'id', 'id > 0');
        if (empty($courseids)) {
            return [];
        }

        $courseids = array_map('intval', $courseids);

        $fieldid = self::get_enable_field_id();
        if (!$fieldid) {
            return $courseids;
        }

        $records = $DB->get_records('customfield_data', ['fieldid' => $fieldid], '', 'instanceid,intvalue');
        if (empty($records)) {
            return $courseids;
        }

        $disabled = [];
        foreach ($records as $record) {
            $value = isset($record->intvalue) ? (int)$record->intvalue : 0;
            if ($value !== 1) {
                $disabled[] = (int)$record->instanceid;
            }
        }

        if (empty($disabled)) {
            return $courseids;
        }

        return array_values(array_diff($courseids, $disabled));
    }

    /**
     * Return enabled language codes from plugin config intersected with installed languages.
     *
     * @return array<int,string>
     */
    public static function get_enabled_language_codes(): array {
        global $CFG;

        $configured = get_config('local_xlate', 'enabled_languages');
        $raw = [];
        if (!empty($configured)) {
            $raw = array_map('trim', explode(',', $configured));
            $raw = array_filter($raw, static function($value) {
                return $value !== '';
            });
        }

        $installed = self::get_installed_languages();
        $filtered = array_values(array_filter($raw, static function($code) use ($installed) {
            return isset($installed[$code]);
        }));

        if (empty($filtered)) {
            // Fallback to site language to ensure we always have at least one entry.
            $filtered = [$CFG->lang];
        }

        return array_values(array_unique($filtered));
    }

    /**
     * Resolve effective source/target languages for a course (or globally when courseid is 0).
     *
     * @param int|null $courseid
     * @return array{source:string,targets:array<int,string>,enabled:array<int,string>}
     */
    public static function resolve_languages(?int $courseid = null): array {
        global $CFG;

        $enabled = self::get_enabled_language_codes();
        $installed = self::get_installed_languages();
        $source = null;
        if (!empty($courseid)) {
            $source = self::get_course_source_lang((int)$courseid);
        }

        if ($source === null || !isset($installed[$source])) {
            $source = $CFG->lang;
        }

        if (!in_array($source, $enabled, true)) {
            array_unshift($enabled, $source);
            $enabled = array_values(array_unique($enabled));
        }

        $targets = [];
        if (!empty($courseid)) {
            $targets = array_values(array_intersect(self::get_course_target_langs((int)$courseid), $enabled));
        }

        if (empty($targets)) {
            $targets = array_values(array_filter($enabled, static function($lang) use ($source) {
                return $lang !== $source;
            }));
        }

        return [
            'source' => $source,
            'targets' => $targets,
            'enabled' => $enabled,
        ];
    }

    /**
     * Cached installed language list.
     *
     * @return array<string,string>
     */
    protected static function get_installed_languages(): array {
        if (empty(self::$installedlangs)) {
            self::$installedlangs = get_string_manager()->get_list_of_translations();
        }

        return self::$installedlangs;
    }

    /**
     * Ensure a field keeps a predictable sort order within the category.
     *
     * @param int $categoryid
     * @param string $shortname
     * @param int $sortorder
     * @return void
     */
    protected static function ensure_field_sortorder(int $categoryid, string $shortname, int $sortorder): void {
        global $DB;

        $field = $DB->get_record('customfield_field', [
            'categoryid' => $categoryid,
            'shortname' => $shortname
        ], 'id,sortorder');

        if (!$field) {
            return;
        }

        if ((int)$field->sortorder === $sortorder) {
            return;
        }

        $field->sortorder = $sortorder;
        $DB->update_record('customfield_field', $field);
    }

    /**
     * Resolve the enable/disable checkbox field id.
     *
     * @return int|null
     */
    protected static function get_enable_field_id(): ?int {
        global $DB;

        if (self::$enablefieldid !== null) {
            return self::$enablefieldid ?: null;
        }

        $record = $DB->get_record('customfield_field', ['shortname' => self::ENABLE_FIELD], 'id');
        if ($record) {
            self::$enablefieldid = (int)$record->id;
            return self::$enablefieldid;
        }

        self::$enablefieldid = 0;
        return null;
    }

    /**
     * Normalize a stored custom field value to a Moodle language code.
     *
     * @param string $value Raw stored value from the custom field.
     * @param array<string,string> $installedlangs Map of langcode => name.
     * @return string|null
     */
    protected static function normalize_lang_value(string $value, array $installedlangs): ?string {
        if ($value === '') {
            return null;
        }

        if (isset($installedlangs[$value])) {
            return $value;
        }

        foreach ($installedlangs as $code => $name) {
            if (strcasecmp($name, $value) === 0) {
                return $code;
            }
        }

        if (preg_match('/\(([a-z]{2,10}(?:_[a-z]{2})?)\)\s*$/i', $value, $matches)) {
            $candidate = strtolower($matches[1]);
            if (isset($installedlangs[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Convert a select field's stored numeric index into its option label.
     *
     * @param string $value Raw value coming from customfield_select.
     * @param \core_customfield\field_controller $field Select field controller.
     * @return string
     */
    protected static function map_select_index_to_option(string $value, \core_customfield\field_controller $field): string {
        if ($value === '' || !ctype_digit($value)) {
            return $value;
        }

        $options = $field->get_options();
        $index = (int)$value;

        if (array_key_exists($index, $options)) {
            return $options[$index];
        }

        $onebased = $index - 1;
        if ($onebased >= 0 && array_key_exists($onebased, $options)) {
            return $options[$onebased];
        }

        return $value;
    }
}
