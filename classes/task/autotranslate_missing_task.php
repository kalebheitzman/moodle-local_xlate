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
                $missingcount = self::count_missing_course_translations($courseid, $targetlang);
                if ($missingcount === 0) {
                    mtrace("Course {$courseid}: no pending keys for {$targetlang}.");
                    continue;
                }

                if (\local_xlate\local\course_job_manager::has_pending_job($courseid, [$targetlang], true)) {
                    mtrace("Course {$courseid}: pending job already exists for {$targetlang}, skipping enqueue.");
                    continue;
                }

                $result = \local_xlate\local\course_job_manager::enqueue_course_job($courseid, [
                    'batchsize' => $batchsize,
                    'targetlangs' => [$targetlang],
                    'onlymissing' => true,
                ], 0, $missingcount);

                if (!empty($result['success'])) {
                    mtrace("Course {$courseid}: queued job {$result['jobid']} for {$targetlang} ({$missingcount} pending keys).");
                } else {
                    $error = isset($result['error']) ? $result['error'] : 'Unknown error.';
                    mtrace("Course {$courseid}: failed to queue job for {$targetlang}: {$error}");
                }
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
    protected static function count_missing_course_translations(int $courseid, string $targetlang): int {
        global $DB;

        $sql = "SELECT COUNT(1)
                  FROM {local_xlate_key_course} kc
                  JOIN {local_xlate_key} k ON k.id = kc.keyid
             LEFT JOIN {local_xlate_tr} t ON t.keyid = k.id AND t.lang = :targetlang
                 WHERE kc.courseid = :courseid AND (t.id IS NULL OR t.status <> 1)";

        $params = ['courseid' => $courseid, 'targetlang' => $targetlang];
        return (int)$DB->get_field_sql($sql, $params);
    }
}
