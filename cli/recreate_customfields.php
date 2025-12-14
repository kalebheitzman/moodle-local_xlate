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
 * CLI script to recreate xlate custom fields.
 *
 * @package    local_xlate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

// Get the Xlate category
$handler = \core_customfield\handler::get_handler('core_course', 'course');
$component = $handler->get_component();
$area = $handler->get_area();
$itemid = $handler->get_itemid();

do {
    $category = $DB->get_record('customfield_category', [
        'component' => $component,
        'area' => $area,
        'itemid' => $itemid,
        'name' => 'Xlate'
    ]);

    if ($category) {
        break;
    }

    echo "Xlate category not found. Creating it now...\n";
    \local_xlate\customfield_helper::setup_customfields();

    // Re-query to confirm creation succeeded.
    $category = $DB->get_record('customfield_category', [
        'component' => $component,
        'area' => $area,
        'itemid' => $itemid,
        'name' => 'Xlate'
    ]);
} while (false);

if (!$category) {
    cli_error('Unable to create Xlate category. Ensure the plugin is installed/upgraded.');
}

echo "Found Xlate category (ID: {$category->id})\n";

// Delete all existing fields in this category
$existingfields = $DB->get_records('customfield_field', ['categoryid' => $category->id]);
foreach ($existingfields as $field) {
    echo "Deleting field: {$field->shortname}\n";
    $fieldobj = \core_customfield\field_controller::create($field->id);
    $fieldobj->delete();
}

echo "\nRecreating fields...\n";

// Now recreate them
\local_xlate\customfield_helper::setup_customfields();

echo "\nDone! Custom fields have been recreated.\n";
