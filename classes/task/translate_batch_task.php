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
 * Batch translation adhoc task for Local Xlate.
 *
 * Submits request data to the translation backend and commits any returned
 * translations, including optional association metadata.
 *
 * @package    local_xlate
 * @category   task
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;

/**
 * Adhoc task to run translate_batch via backend and persist results when possible.
 */
class translate_batch_task extends adhoc_task {
    public function get_name(): string {
        return get_string('translatebatchtask', 'local_xlate');
    }

    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data) || !is_object($data)) {
            return;
        }

        // Expected custom data: requestid, sourcelang, targetlang, items (array), glossary (array), options (array)
        $requestid = $data->requestid ?? uniqid('rb_');
        $sourcelang = $data->sourcelang ?? 'en';
        $targetlang = $data->targetlang ?? '';
        $items = $data->items ?? [];
        $glossary = $data->glossary ?? [];
        $options = $data->options ?? [];

        // Support a single target language string or an array of target languages.
        $targetlangs = is_array($targetlang) ? $targetlang : [$targetlang];

        foreach ($targetlangs as $tl) {
            if (empty($tl)) {
                continue;
            }

            // Normalize items and glossary (adhoc customdata may contain stdClass objects
            // when the task was queued). Backend expects arrays with string keys so
            // cast any stdClass entries to arrays.
            $normalizeditems = [];
            foreach ($items as $it) {
                if (is_object($it)) {
                    $normalizeditems[] = (array)$it;
                } else {
                    $normalizeditems[] = $it;
                }
            }
            // Replace original items with normalized arrays for downstream processing
            $items = $normalizeditems;

            $normalizedglossary = [];
            foreach ($glossary as $g) {
                if (is_object($g)) {
                    $normalizedglossary[] = (array)$g;
                } else {
                    $normalizedglossary[] = $g;
                }
            }
            // Replace glossary with normalized version
            $glossary = $normalizedglossary;

            // Call backend for this language. Any exceptions or invalid results
            // are ignored per-language so other languages can still proceed.
            try {
                $result = \local_xlate\translation\backend::translate_batch($requestid, $sourcelang, $tl, $normalizeditems, $normalizedglossary, $options);
            } catch (\Exception $ex) {
                continue;
            }

            if (empty($result) || empty($result['ok']) || empty($result['results']) || !is_array($result['results'])) {
                continue;
            }

            // Persist translations when original item includes component and key.
            foreach ($result['results'] as $r) {
                $id = $r['id'] ?? null;
                $translated = $r['translated'] ?? null;
                if ($translated === null) {
                    continue;
                }

                // Match against both `id` and `key` fields to be resilient.
                $orig = null;
                foreach ($items as $it) {
                    $itid = (string)($it['id'] ?? '');
                    $itkey = (string)($it['key'] ?? '');
                    if ($itid === (string)$id || $itkey === (string)$id) {
                        $orig = $it;
                        break;
                    }
                }
                if (!$orig) {
                    continue;
                }

                if (!empty($orig['component']) && !empty($orig['key'])) {
                    try {
                        \local_xlate\local\api::save_key_with_translation(
                            (string)$orig['component'],
                            (string)$orig['key'],
                            (string)($orig['source_text'] ?? ''),
                            $tl,
                            (string)$translated,
                            0, // reviewed flag: machine-translated results are not human-reviewed
                            isset($orig['courseid']) ? (int)$orig['courseid'] : 0,
                            isset($orig['context']) ? (string)$orig['context'] : ''
                        );
                    } catch (\Exception $e) {
                        // Ignore save errors to avoid failing the whole batch.
                    }
                }
            }
        }
    }
}
