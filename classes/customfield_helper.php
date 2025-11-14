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
    /**
     * Create or update xlate custom fields category and fields.
     *
     * @return void
     */
    public static function setup_customfields(): void {
        global $DB;

        // Get or create the Xlate category for course custom fields
        $category = self::get_or_create_category();

        // Create source language field (select)
        self::create_source_language_field($category);

        // Create target languages field (multiselect)
        self::create_target_languages_field($category);
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

        // Get installed languages
        $installedlangs = get_string_manager()->get_list_of_translations();
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

        // Get installed languages
        $installedlangs = get_string_manager()->get_list_of_translations();
        
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
        
        foreach ($datas as $data) {
            if ($data->get_field()->get('shortname') === 'xlate_source_lang') {
                $value = $data->get_value();
                // Return null if empty (not configured)
                return !empty($value) ? $value : null;
            }
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
        
        return $targetlangs;
    }
}
