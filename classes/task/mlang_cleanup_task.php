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
 * Scheduled cleanup task for legacy multilang content.
 *
 * Invokes the Local Xlate migration helper to convert legacy multilang tags
 * on a scheduled basis.
 *
 * @package    local_xlate
 * @category   task
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

class mlang_cleanup_task extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('mlangcleanuptask', 'local_xlate');
    }

    public function execute() {
        global $DB;
        mtrace('[mlang_cleanup_task] Starting scheduled mlang cleanup...');
        $report = \local_xlate\mlang_migration::migrate($DB, ['execute' => true]);
        mtrace('[mlang_cleanup_task] Completed. Changed: ' . ($report['changed'] ?? 0));
        // Optionally, log/report more details or errors here.
    }
}
