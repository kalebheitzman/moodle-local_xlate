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

namespace local_xlate\admin\setting;

use admin_setting_configtext;

/**
 * Admin setting that accepts decimal pricing values without enforcing PHP float formatting.
 *
 * Ensures the submitted value is numeric, preserves the administrator's formatting, and trims
 * surrounding whitespace so values like "2.00" or "0.50" store cleanly.
 */
class pricing extends admin_setting_configtext {
    /**
     * @param string $name Config key.
     * @param string $visiblename Display label.
     * @param string $description Help text.
     * @param string $defaultsetting Default value displayed.
     */
    public function __construct(string $name, string $visiblename, string $description, string $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW_TRIMMED);
    }

    /**
     * Validate the submitted value.
     *
     * @param string $data User-entered value.
     * @return bool|string True when valid, else error string identifier.
     */
    public function validate($data) {
        $trimmed = trim((string)$data);
        if ($trimmed === '') {
            return get_string('required');
        }
        if (!is_numeric($trimmed)) {
            return get_string('pricing_value_invalid', 'local_xlate');
        }
        return true;
    }

    /**
     * Persist the configuration value after trimming whitespace.
     *
     * @param string $data
     * @return string|bool
     */
    public function write_setting($data) {
        $trimmed = trim((string)$data);
        return parent::write_setting($trimmed);
    }
}
