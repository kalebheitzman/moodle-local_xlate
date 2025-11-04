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
        $enabledlangs = get_config('local_xlate', 'enabled_languages');
        $enabledlangs = $enabledlangs ? explode(',', $enabledlangs) : [];
        if (empty($enabledlangs)) {
            mtrace('No enabled languages.');
            return;
        }
        $keys = $DB->get_records_menu('local_xlate_key', null, '', 'id,xkey');
        if (empty($keys)) {
            mtrace('No translation keys found.');
            return;
        }
        $batchsize = 20;
        foreach ($enabledlangs as $lang) {
            $lang = trim($lang);
            if ($lang === '') continue;
            // Find all keyids missing a translation for this language.
            $sql = "SELECT k.id, k.xkey, k.source FROM {local_xlate_key} k
                    LEFT JOIN {local_xlate_tr} t ON t.keyid = k.id AND t.lang = ?
                    WHERE t.id IS NULL";
            $missing = $DB->get_records_sql($sql, [$lang]);
            if (empty($missing)) {
                mtrace("All keys translated for $lang");
                continue;
            }
            mtrace("Autotranslating ".count($missing)." keys for $lang...");
            $chunks = array_chunk($missing, $batchsize);
            mtrace("Splitting into ".count($chunks)." batches of up to $batchsize");
            foreach ($chunks as $i => $chunk) {
                $items = [];
                foreach ($chunk as $row) {
                    $items[] = [
                        'id' => $row->xkey,
                        'source_text' => $row->source,
                        'context' => '',
                        'placeholders' => []
                    ];
                }
                mtrace("Batch ".($i+1)."/".count($chunks).": sending ".count($items)." items to backend for $lang");
                $result = \local_xlate\translation\backend::translate_batch('autotask-'.uniqid(), 'en', $lang, $items, [], []);
                if (!empty($result['ok']) && !empty($result['results'])) {
                    mtrace("Received ".count($result['results'])." results for batch ".($i+1)."/$lang");
                    foreach ($result['results'] as $r) {
                        if (!empty($r['id']) && isset($r['translated'])) {
                            // Insert translation if still missing (avoid race/dup).
                            $keyid = array_search($r['id'], $keys);
                            if ($keyid && !$DB->record_exists('local_xlate_tr', ['keyid'=>$keyid, 'lang'=>$lang])) {
                                $rec = (object)[
                                    'keyid' => $keyid,
                                    'lang' => $lang,
                                    'text' => $r['translated'],
                                    'status' => 1,
                                    'reviewed' => 0,
                                    'mtime' => time()
                                ];
                                $DB->insert_record('local_xlate_tr', $rec, false);
                                mtrace("Added autotranslation for $lang:$r[id]");
                            }
                        }
                    }
                } else {
                    mtrace("Autotranslate error for $lang batch ".($i+1).": ".json_encode($result['errors'] ?? []));
                }
            }
        }
        mtrace('Autotranslate_missing_task complete.');
    }
}
