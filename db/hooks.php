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
 * Hook definitions for Local Xlate.
 *
 * Registers output hooks that inject the translator bootstrap markup.
 *
 * @package    local_xlate
 * @category   hooks
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../classes/hooks/output.php');

/**
 * @var array<int,array<string,mixed>> Hook callback registrations for Moodle core hook dispatcher.
 */
$callbacks = [
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \local_xlate\hooks\output::class . '::before_head',
        // Execute early to minimise FOUT and ensure our CSS is present.
        'priority' => -1000,
    ],
    [
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => \local_xlate\hooks\output::class . '::before_body',
        // Execute early so our bootloader runs promptly.
        'priority' => -1000,
    ],
];
