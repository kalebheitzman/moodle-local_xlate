<?php
namespace local_xlate\local;

defined('MOODLE_INTERNAL') || die();

class api {
    public static function get_bundle(string $lang): array {
        $cache = \cache::make('local_xlate', 'bundle');
        if ($hit = $cache->get($lang)) {
            return $hit;
        }
        global $DB;
        $sql = "SELECT k.xkey, t.text
                  FROM {local_xlate_key} k
                  JOIN {local_xlate_tr} t ON t.keyid = k.id
                 WHERE t.lang = ? AND t.status = 1";
        $recs = $DB->get_records_sql($sql, [$lang]);
        $bundle = [];
        foreach ($recs as $r) {
            $bundle[$r->xkey] = $r->text;
        }
        $cache->set($lang, $bundle);
        return $bundle;
    }

    public static function get_version(string $lang): string {
        global $DB;
        $rec = $DB->get_record('local_xlate_bundle', ['lang' => $lang], '*', IGNORE_MISSING);
        return $rec ? $rec->version : 'dev';
    }
}
