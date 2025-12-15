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
 * Scheduled task to backfill missing translations via Local Xlate.
 *
 * Iterates supported languages and queues autotranslation batches for keys
 * that lack completed translations.
 *
 * @package    local_xlate
 * @category   task
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;

/**
 * Scheduled task that backfills missing translations using the AI backend.
 *
 * Periodically scans all enabled languages and enqueues batches directly via
 * the translation backend for keys lacking approved translations.
 *
 * @package local_xlate\task
 */
class autotranslate_missing_task extends scheduled_task {
    /**
     * Provide a human-readable task name string.
     *
     * @return string
     */
    public function get_name() {
        return get_string('autotranslate_missing_task', 'local_xlate');
    }

    /**
     * Run the scheduled autotranslation pass.
     *
     * Applies plugin configuration (enabled languages, batch size) to identify
     * untranslated keys per language, submits each batch to the translation
     * backend, and persists new translations when the backend replies
     * successfully.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        if (!get_config('local_xlate', 'autotranslate_task_enabled')) {
            mtrace('Autotranslate scheduled task is disabled in settings.');
            return;
        }
        mtrace('Starting autotranslate_missing_task...');
        $courseids = $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {local_xlate_key_course}");
        if (empty($courseids)) {
            mtrace('No course associations found; nothing to autotranslate.');
            return;
        }

        $configuredbatch = (int)get_config('local_xlate', 'autotranslate_task_batchsize');
        $batchsize = $configuredbatch > 0 ? $configuredbatch : 50;
        foreach ($courseids as $courseid) {
            $courseid = (int)$courseid;
            if ($courseid <= 0) {
                continue;
            }

            $config = \local_xlate\customfield_helper::get_course_config($courseid);
            if ($config === null) {
                mtrace("Skipping course {$courseid}: no xlate configuration.");
                continue;
            }

            $sourcelang = $config['source'];
            $targetlangs = array_filter($config['targets'], static function($code) use ($sourcelang) {
                return $code && $code !== $sourcelang;
            });

            if (empty($targetlangs)) {
                mtrace("Skipping course {$courseid}: no valid target languages configured.");
                continue;
            }

            foreach ($targetlangs as $targetlang) {
                $processed = 0;
                do {
                    $missing = self::get_missing_course_translations($courseid, $targetlang, $batchsize);
                    if (empty($missing)) {
                        if ($processed === 0) {
                            mtrace("Course {$courseid}: no pending keys for {$targetlang}.");
                        }
                        break;
                    }

                    $chunkcount = count($missing);
                    $processed += $chunkcount;
                    mtrace("Course {$courseid}: translating {$chunkcount} keys into {$targetlang} (total this run: {$processed}).");

                    $items = [];
                    foreach ($missing as $row) {
                        $items[] = [
                            'id' => (string)$row->xkey,
                            'key' => (string)$row->xkey,
                            'component' => (string)$row->component,
                            'source_text' => (string)$row->source,
                            'context' => '',
                            'placeholders' => []
                        ];
                    }

                    $result = \local_xlate\translation\backend::translate_batch(
                        'course-auto-' . $courseid . '-' . $targetlang . '-' . uniqid('', true),
                        $sourcelang,
                        $targetlang,
                        $items,
                        [],
                        []
                    );

                    if (empty($result['ok']) || empty($result['results'])) {
                        mtrace("Course {$courseid}: backend error for {$targetlang}: " . json_encode($result['errors'] ?? []));
                        break;
                    }

                    self::persist_batch_results($missing, $result['results'], $targetlang, $courseid);

                    if (class_exists('core_php_time_limit')) {
                        \core_php_time_limit::raise(60);
                    } else {
                        @set_time_limit(60);
                    }
                } while ($chunkcount === $batchsize);
            }
        }

        mtrace('Autotranslate_missing_task complete.');
    }

    /**
     * Fetch keys for a course missing translations in a target language.
     *
     * @param int $courseid Course identifier.
     * @param string $targetlang Target language code.
     * @param int $limit Maximum records to fetch.
     * @return array<int,\stdClass> Records containing key metadata.
     */
    protected static function get_missing_course_translations(int $courseid, string $targetlang, int $limit): array {
        global $DB;

        $sql = "SELECT k.id, k.xkey, k.source, k.component
                  FROM {local_xlate_key_course} kc
                  JOIN {local_xlate_key} k ON k.id = kc.keyid
             LEFT JOIN {local_xlate_tr} t ON t.keyid = k.id AND t.lang = :targetlang
                 WHERE kc.courseid = :courseid AND (t.id IS NULL OR t.status <> 1)
              ORDER BY k.id ASC";

        $params = ['courseid' => $courseid, 'targetlang' => $targetlang];
        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Persist backend results for a single course/target combo.
     *
     * @param array<int,\stdClass> $pendingRows Rows returned from get_missing_course_translations.
     * @param array<int,array> $results Backend results array.
     * @param string $targetlang Target language code.
     * @param int $courseid Course identifier.
     * @return void
     */
    protected static function persist_batch_results(array $pendingRows, array $results, string $targetlang, int $courseid): void {
        if (empty($pendingRows) || empty($results)) {
            return;
        }

        $bykey = [];
        foreach ($pendingRows as $row) {
            $bykey[(string)$row->xkey] = $row;
        }

        foreach ($results as $result) {
            $id = isset($result['id']) ? (string)$result['id'] : '';
            $translated = isset($result['translated']) ? (string)$result['translated'] : '';
            if ($id === '' || $translated === '') {
                continue;
            }

            if (!isset($bykey[$id])) {
                continue;
            }
            $row = $bykey[$id];

            try {
                \local_xlate\local\api::save_key_with_translation(
                    (string)$row->component,
                    (string)$row->xkey,
                    (string)$row->source,
                    $targetlang,
                    $translated,
                    0,
                    $courseid,
                    ''
                );
            } catch (\Throwable $e) {
                debugging('[local_xlate] Failed to persist autotranslate for key ' . $row->xkey . ' (' . $targetlang . '): ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
