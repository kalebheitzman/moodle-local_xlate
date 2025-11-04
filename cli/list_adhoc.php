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
 * CLI script to list Moodle adhoc tasks.
 *
 * Dumps current entries from the task_adhoc table to assist with diagnosing
 * queued work related to Local Xlate.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

global $DB;
/**
 * Current adhoc task rows fetched for inspection.
 *
 * @var array<int,\stdClass>
 */
$rows = $DB->get_records('task_adhoc');
if (empty($rows)) {
    echo "No adhoc tasks found.\n";
    exit(0);
}
foreach ($rows as $r) {
    echo "--- TASK id={$r->id} ---\n";
    foreach ($r as $k => $v) {
        echo "$k => " . (is_scalar($v) ? $v : json_encode($v)) . "\n";
    }
    echo "\n";
}
