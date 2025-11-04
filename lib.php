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
 * Local navigation integration for the Local Xlate plugin.
 *
 * Adds course-level navigation entries so qualified users can jump directly to
 * the translation management UI from a course More menu.
 *
 * @package    local_xlate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extend the course navigation with a Manage Translations link.
 *
 * This will appear in the course More menu for users who have the
 * 'local/xlate:manage' capability at system or course context.
 *
 * @param navigation_node $navigation The navigation node for the course
 * @param stdClass $course The course object
 * @param context $context The course context
 */
function local_xlate_extend_navigation_course($navigation, $course, $context) {
    if (!
        get_config('local_xlate', 'enable')
    ) {
        return;
    }

    // Only show to users with the site manage capability or the course-level manage capability.
    $systemcontext = context_system::instance();
    if (!has_capability('local/xlate:manage', $systemcontext) && !has_capability('local/xlate:managecourse', $context)) {
        return;
    }

    // Add the navigation node. It will show under course navigation (More menu).
    $url = new moodle_url('/local/xlate/manage.php', ['courseid' => $course->id]);
    $text = get_string('manage_translations', 'local_xlate');
    // Use TYPE_SETTING to have it appear as a course setting link.
    $navigation->add($text, $url, navigation_node::TYPE_SETTING);
}
