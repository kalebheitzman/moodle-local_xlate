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
 * Adhoc task to run the multilang migration for Local Xlate.
 *
 * Wraps the migration helper to permit destructive replacements of legacy
 * multilang tags when explicitly requested.
 *
 * @package    local_xlate
 * @category   task
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;

require_once(dirname(dirname(__DIR__)) . '/classes/mlang_migration.php');

/**
 * Adhoc task wrapper to run a destructive MLang migration (idempotent).
 * By default runs in dry-run mode; must be passed execute=true to perform writes.
 */
class mlang_migrate extends adhoc_task {
    public function get_name() {
        return get_string('mlangmigrate', 'local_xlate');
    }

    public function execute() {
        global $DB;

        $data = $this->get_custom_data() ?: [];
        $options = [];
        if (!empty($data->tables) && is_array($data->tables)) {
            $options['tables'] = (array)$data->tables;
        }
        if (!empty($data->chunk)) { $options['chunk'] = (int)$data->chunk; }
        if (!empty($data->preferred)) { $options['preferred'] = (string)$data->preferred; }
        if (!empty($data->execute)) { $options['execute'] = (bool)$data->execute; }
        if (!empty($data->sample)) { $options['sample'] = (int)$data->sample; }

        $report = \local_xlate\mlang_migration::migrate($DB, $options);

        debugging('[local_xlate] mlang migrate completed; changed=' . ($report['changed'] ?? 0), DEBUG_DEVELOPER);
    }
}
