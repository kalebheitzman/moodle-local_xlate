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

namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

use local_xlate\local\translation_cleanup;

/**
 * Scheduled task that normalises malformed HTML tags in stored translations.
 */
class html_tag_cleanup_task extends \core\task\scheduled_task {
    /**
     * Task name shown in the Moodle scheduled tasks UI.
     *
     * @return string
     */
    public function get_name() {
        return get_string('htmlcleanuptask', 'local_xlate');
    }

    /**
     * Execute the cleanup run.
     */
    public function execute() {
        mtrace('[html_tag_cleanup] Starting scan for malformed tags...');
        $stats = translation_cleanup::cleanup_translations(false);
        mtrace('[html_tag_cleanup] Checked ' . $stats['checked'] . ' translations, cleaned ' . $stats['updated'] . '.');
    }
}
