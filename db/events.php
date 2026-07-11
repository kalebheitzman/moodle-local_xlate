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
 * Event observer registrations for Local Xlate.
 *
 * Courses are continuously imported into this Moodle carrying legacy
 * {mlang}/multilang-span content. These observers queue a course-scoped
 * mlang cleanup immediately after import/restore/creation, so cleanup is
 * event-driven and scoped instead of relying solely on the site-wide
 * scheduled scan.
 *
 * @package    local_xlate
 * @category   event
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_restored',
        'callback'  => '\local_xlate\observer::course_restored',
    ],
    [
        'eventname' => '\core\event\course_created',
        'callback'  => '\local_xlate\observer::course_created',
    ],
];
