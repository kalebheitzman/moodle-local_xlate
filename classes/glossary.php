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
 * Glossary data helpers for Local Xlate.
 *
 * Provides CRUD utilities to retrieve, update, and purge glossary terms used
 * during automated translation workflows.
 *
 * @package    local_xlate
 * @category   local
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate;

defined('MOODLE_INTERNAL') || die();

/**
 * Glossary helper class.
 *
 * Wraps CRUD operations for the glossary table and provides lookup helpers
 * used when building prompts or presenting glossary management UIs.
 *
 * @package local_xlate
 */
class glossary {
    /**
     * Lookup glossary entries for a target language.
     *
     * @param string $targetlang Language code the glossary entries translate into.
     * @param int $limit Maximum number of rows to return.
     * @return array<int,\stdClass> Records from `local_xlate_glossary` ordered by id.
     */
    public static function get_by_target($targetlang, $limit = 200) {
        global $DB;
        $sql = "SELECT * FROM {local_xlate_glossary} WHERE target_lang = :t ORDER BY id ASC";
        return $DB->get_records_sql($sql, ['t' => $targetlang], 0, $limit);
    }

    /**
     * Build glossary term/replacement pairs for a source/target language combination.
     *
     * @param string $source_lang Source language code.
     * @param string $target_lang Target language code.
     * @param int $limit Maximum number of entries to return.
     * @return array<int,array{term:string,replacement:string}> Glossary payload suitable for translation prompts.
     */
    public static function get_pairs_for_language_pair(string $source_lang, string $target_lang, int $limit = 200): array {
        global $DB;

        $source_lang = trim($source_lang);
        $target_lang = trim($target_lang);
        if ($source_lang === '' || $target_lang === '') {
            return [];
        }

        $sql = "SELECT id, source_text, target_text
                  FROM {local_xlate_glossary}
                 WHERE source_lang = :s AND target_lang = :t
              ORDER BY id ASC";
        $records = $DB->get_records_sql($sql, ['s' => $source_lang, 't' => $target_lang], 0, $limit);

        $pairs = [];
        foreach ($records as $record) {
            $term = trim((string)($record->source_text ?? ''));
            $replacement = trim((string)($record->target_text ?? ''));
            if ($term === '' || $replacement === '') {
                continue;
            }
            $pairs[] = [
                'term' => $term,
                'replacement' => $replacement
            ];
            if (count($pairs) >= $limit) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * Insert a new glossary entry.
     *
     * @param array|\stdClass $data Row fields matching the glossary table schema.
     * @return int Newly inserted record id.
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
     * Lookup best match(es) for a source string between languages.
     *
     * Performs an exact match on normalized source text; callers can extend to
     * fuzzier matching upstream if needed.
     *
     * @param string $source Raw source text.
     * @param string $source_lang Source language code.
     * @param string $target_lang Target language code.
     * @return array<int,\stdClass> Matching glossary rows.
     */
    public static function lookup_glossary($source, $source_lang, $target_lang) {
        global $DB;
        // Normalize input for now by trimming.
        $norm = trim($source);
        $sourcecmp = $DB->sql_compare_text('source_text');
        $sql = "SELECT * FROM {local_xlate_glossary} WHERE source_lang = :s AND target_lang = :t AND $sourcecmp = :st ORDER BY id ASC";
        $records = $DB->get_records_sql($sql, ['s' => $source_lang, 't' => $target_lang, 'st' => $norm], 0, 10);
        return $records ?: [];
    }

    /**
     * Count distinct source groups (source_lang + source_text).
     *
     * @param string $search Optional substring filter applied to source text.
     * @return int Number of distinct source entries.
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
     * Retrieve distinct source groups with pagination.
     *
     * Each row contains id (MIN(id)), source_lang, source_text, translations_count,
     * mtime, and ctime to power glossary management UIs.
     *
     * @param string $search Optional substring filter applied to source text.
     * @param int $offset Offset for pagination.
     * @param int $limit Maximum number of records to return.
     * @return array<int,\stdClass> Records keyed by the MIN(id) column.
     */
    public static function get_sources($search = '', $offset = 0, $limit = 20) {
        global $DB;
        $params = [];
        $where = '';
        if (!empty($search)) {
            $where = 'WHERE source_text LIKE ?';
            $params[] = '%' . $search . '%';
        }
        // Ensure the first selected column is a unique key (use MIN(id)) so get_records_sql
        // returns an array keyed by a unique value and does not throw duplicate-key errors.
        $sql = "SELECT MIN(id) as id, source_lang, source_text, COUNT(*) as translations_count, MAX(mtime) as mtime, MAX(ctime) as ctime
          FROM {local_xlate_glossary}
          $where
          GROUP BY source_lang, source_text
          ORDER BY LOWER(source_text) ASC";

        return $DB->get_records_sql($sql, $params, $offset, $limit);
    }

    /**
     * Retrieve every translation for a given source string within a language.
     *
     * @param string $source_text Source text to match.
     * @param string $source_lang Source language code.
     * @return array<int,\stdClass> Glossary rows ordered by target language.
     */
    public static function get_translations_for_source($source_text, $source_lang) {
        global $DB;
        $sourcecmp = $DB->sql_compare_text('source_text');
        $sql = "SELECT * FROM {local_xlate_glossary} WHERE source_lang = :s AND $sourcecmp = :st ORDER BY target_lang ASC";
        return $DB->get_records_sql($sql, ['s' => $source_lang, 'st' => $source_text]);
    }

    /**
     * Upsert a translation for a source_lang/source_text/target_lang triple.
     *
     * @param string $source_lang Source language code.
     * @param string $source_text Source text to translate.
     * @param string $target_lang Target language code.
     * @param string $target_text Translation text to store.
     * @param int $userid User id recorded in created_by when inserting.
     * @return int Record id of the inserted or updated row.
     * @throws \invalid_argument_exception When required fields are missing.
     */
    public static function save_translation($source_lang, $source_text, $target_lang, $target_text, $userid = 0) {
        global $DB;
        $now = time();
        $source_text = trim($source_text);
        $target_text = trim($target_text);

        if ($source_text === '' || $target_lang === '') {
            throw new \invalid_argument_exception('source_text and target_lang required');
        }

        $sourcecmp = $DB->sql_compare_text('source_text');
        $sql = "SELECT * FROM {local_xlate_glossary} WHERE source_lang = :s AND target_lang = :t AND $sourcecmp = :st";
        $existing = $DB->get_record_sql($sql, ['s' => $source_lang, 't' => $target_lang, 'st' => $source_text]);

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
