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

    /**
     * Count distinct source groups (by source_lang + source_text) optionally filtered by search.
     */
    public static function count_sources($search = '') {
        global $DB;
        $params = [];
        $where = '';
        if (!empty($search)) {
            $where = 'WHERE source_text LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $sql = "SELECT COUNT(DISTINCT source_lang, source_text) as c FROM {local_xlate_glossary} $where";
        return (int)$DB->get_field_sql($sql, $params);
    }

    /**
     * Get distinct source groups with pagination.
     * Returns array of objects with fields: source_lang, source_text, translations_count, id (min id)
     */
    public static function get_sources($search = '', $offset = 0, $limit = 20) {
        global $DB;
        $params = [];
        $where = '';
        if (!empty($search)) {
            $where = 'WHERE source_text LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $sql = "SELECT source_lang, source_text, MIN(id) as id, COUNT(*) as translations_count, MAX(mtime) as mtime, MAX(ctime) as ctime
                  FROM {local_xlate_glossary}
                  $where
                  GROUP BY source_lang, source_text
                  ORDER BY ctime DESC";
        return $DB->get_records_sql($sql, $params, $offset, $limit);
    }

    /**
     * Get all translations for a given source_text + source_lang.
     */
    public static function get_translations_for_source($source_text, $source_lang) {
        global $DB;
        $sql = "SELECT * FROM {local_xlate_glossary} WHERE source_lang = :s AND source_text = :st ORDER BY target_lang ASC";
        return $DB->get_records_sql($sql, ['s' => $source_lang, 'st' => $source_text]);
    }

    /**
     * Save or update a translation for a source_text/source_lang/target_lang.
     * Returns the record id of the inserted/updated row.
     */
    public static function save_translation($source_lang, $source_text, $target_lang, $target_text, $userid = 0) {
        global $DB;
        $now = time();
        $source_text = trim($source_text);
        $target_text = trim($target_text);

        if ($source_text === '' || $target_lang === '') {
            throw new \invalid_argument_exception('source_text and target_lang required');
        }

        $existing = $DB->get_record('local_xlate_glossary', [
            'source_lang' => $source_lang,
            'source_text' => $source_text,
            'target_lang' => $target_lang
        ]);

        if ($existing) {
            $existing->target_text = $target_text;
            $existing->mtime = $now;
            return $DB->update_record('local_xlate_glossary', $existing);
        } else {
            $rec = new \stdClass();
            $rec->source_lang = $source_lang;
            $rec->source_text = $source_text;
            $rec->target_lang = $target_lang;
            $rec->target_text = $target_text;
            $rec->mtime = $now;
            $rec->ctime = $now;
            $rec->created_by = $userid ?: 0;
            return $DB->insert_record('local_xlate_glossary', $rec);
        }
    }
}
