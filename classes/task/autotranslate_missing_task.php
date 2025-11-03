<?php
namespace local_xlate\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;

class autotranslate_missing_task extends scheduled_task {
    public function get_name() {
        return get_string('autotranslate_missing_task', 'local_xlate');
    }

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
            foreach ($chunks as $chunk) {
                $items = [];
                foreach ($chunk as $row) {
                    $items[] = [
                        'id' => $row->xkey,
                        'source_text' => $row->source,
                        'context' => '',
                        'placeholders' => []
                    ];
                }
                $result = \local_xlate\translation\backend::translate_batch('autotask-'.uniqid(), 'en', $lang, $items, [], []);
                if (!empty($result['ok']) && !empty($result['results'])) {
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
                    mtrace("Autotranslate error for $lang: ".json_encode($result['errors'] ?? []));
                }
            }
        }
        mtrace('Autotranslate_missing_task complete.');
    }
}
