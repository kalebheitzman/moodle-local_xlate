<?php
namespace local_xlate;

defined('MOODLE_INTERNAL') || die();

/**
 * Glossary helper class.
 *
 * Basic CRUD and lookup helpers for the language glossary.
 */
class glossary {
    /**
     * Lookup glossary entries for a target language.
     * Returns an array of records.
     */
    public static function get_by_target($targetlang, $limit = 200) {
        global $DB;
        $sql = "SELECT * FROM {local_xlate_glossary} WHERE target_lang = :t ORDER BY id ASC";
        return $DB->get_records_sql($sql, ['t' => $targetlang], 0, $limit);
    }

    /**
     * Add or update a glossary entry.
     * $data should be an object with fields matching table columns.
     */
    public static function add_entry($data) {
        global $DB;
        $now = time();
        $record = (object) $data;
        $record->mtime = $now;
        if (empty($record->ctime)) {
            $record->ctime = $now;
        }
        if (empty($record->created_by)) {
            $record->created_by = 0;
        }
        return $DB->insert_record('local_xlate_glossary', $record);
    }

    /**
     * Lookup best match(s) for a source string between languages.
     * Simple exact normalized matching first; can be extended to fuzzy.
     */
    public static function lookup_glossary($source, $source_lang, $target_lang) {
        global $DB;
        // Normalize input for now by trimming.
        $norm = trim($source);
        $sql = "SELECT * FROM {local_xlate_glossary} WHERE source_lang = :s AND target_lang = :t AND source_text = :st ORDER BY id ASC";
        $records = $DB->get_records_sql($sql, ['s' => $source_lang, 't' => $target_lang, 'st' => $norm], 0, 10);
        return $records ?: [];
    }
}
