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
 * Tools for migrating legacy multilang content to Local Xlate.
 *
 * Contains the dry-run scanner and destructive migration helpers for locating
 * and converting old mlang tags across Moodle database tables.
 *
 * @package    local_xlate
 * @category   local
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper for MLang migration operations (dry-run and destructive variants).
 *
 * Centralises the logic for scanning Moodle tables for legacy multilang tags,
 * reporting matches, and replacing them with Local Xlate-friendly content.
 * The helpers are reused by CLI utilities and adhoc/scheduled tasks.
 *
 * @package local_xlate
 */
class mlang_migration {
    /** Default chunk size for scanning rows. */
    const DEFAULT_CHUNK = 250;

    /**
     * Generic span matcher used for multilang span handling.
     *
     * Deliberately matches ANY span open tag and defers multilang/lang
     * detection to parse_multilang_span(), so attribute order and extra
     * attributes (id, style, dir, ...) never cause tags to be silently
     * skipped. Non-multilang spans are returned untouched by the callbacks.
     *
     * Limitation (pre-existing): the non-greedy body match stops at the first
     * </span>, so multilang spans containing NESTED spans are truncated. In
     * practice multilang spans wrap inline text; nested markup other than
     * spans is unaffected.
     */
    const SPAN_PATTERN = '/<span\b([^>]*)>(.*?)<\/span>/is';

    /**
     * Extract the language code from a span's attribute string when the span
     * is a multilang span; return null for any other span.
     *
     * Tolerant of attribute order (lang before or after class), single or
     * double quotes, extra attributes, and multi-class values such as
     * class="multilang highlight".
     *
     * @param string $attrs Raw attribute string captured from the open tag.
     * @return string|null Lowercase language code, or null if not multilang.
     */
    public static function parse_multilang_span(string $attrs): ?string {
        if (!preg_match('/\bclass\s*=\s*["\'][^"\']*\bmultilang\b[^"\']*["\']/i', $attrs)) {
            return null;
        }
        if (!preg_match('/\blang\s*=\s*["\']([a-zA-Z0-9_-]+)["\']/i', $attrs, $m)) {
            return null;
        }
        return strtolower($m[1]);
    }

    /** Default sample size for report. */
    const DEFAULT_SAMPLE = 1000;

    /**
     * Tables that must never be touched by the migration.
     *
     * These are log, audit, and analytics tables that contain historical
     * snapshots of content. Rewriting mlang tags in them would corrupt the
     * audit trail without providing any user-facing benefit — the original
     * tagged text is the correct historical record.
     */
    const SKIP_TABLES = [
        // Event / audit logs.
        'logstore_standard_log',
        'logstore_database_log',
        'task_log',
        'config_log',
        'upgrade_log',
        // Quiz attempt history — student response data.
        'question_attempts',
        'question_attempt_steps',
        'question_attempt_step_data',
        'question_response_analysis',
        'question_response_count',
        // Grade history tables.
        'grade_items_history',
        'grade_grades_history',
        'grade_categories_history',
        'grade_outcomes_history',
        // Search index — rebuilt automatically, never needs migration.
        'search_simpledb_index',
    ];

    /**
     * Run a non-destructive dry-run scan for MLang occurrences.
     *
     * @param \moodle_database $DB Database handle used for scanning.
     * @param array{tables?:array<string,array<int,string>>,chunk?:int,sample?:int} $options Optional scan settings.
     * @return array<string,mixed> Report containing counts and sample entries.
     */
    public static function dryrun(\moodle_database $DB, array $options = []): array {
        $tables = $options['tables'] ?? self::default_tables();
        $chunk = $options['chunk'] ?? self::DEFAULT_CHUNK;
        $sample = $options['sample'] ?? self::DEFAULT_SAMPLE;

        $report = [
            'run' => date('c'),
            'total_tables' => count($tables),
            'tables' => [],
            'samples' => [],
            'total_matches' => 0,
        ];

        foreach ($tables as $table => $cols) {
            $report['tables'][$table] = ['scanned' => 0, 'matches' => 0];

            // Basic select: assume an 'id' PK exists.
            $colslist = implode(', ', array_map(function($c) { return $c; }, $cols));
            $sql = "SELECT id, " . $colslist . " FROM {" . $table . "}";
            $offset = 0;
            try {
                while ($rows = $DB->get_records_sql($sql, [], $offset, $chunk)) {
                    foreach ($rows as $row) {
                        $report['tables'][$table]['scanned']++;
                        foreach ($cols as $col) {
                            if (!isset($row->{$col}) || $row->{$col} === null) {
                                continue;
                            }
                            $text = (string)$row->{$col};
                            if (!self::contains_mlang($text)) {
                                continue;
                            }
                            $parsed = self::process_mlang_tags($text);
                            $normalized = self::normalise_source($parsed['source_text'] ?? '');
                            //$sourcehash = $normalized === '' ? '' : sha1($normalized);

                            $entry = [
                                'table' => $table,
                                'id' => $row->id,
                                'column' => $col,
                                'snippet' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($text))), 0, 500),
                                'source' => $parsed['source_text'] ?? '',
                                'languages' => array_values($parsed['translations'] ?? []),
                                'detected_lang_codes' => array_keys($parsed['translations'] ?? []),
                            ];

                            $report['tables'][$table]['matches']++;
                            $report['total_matches']++;
                            if (count($report['samples']) < $sample) {
                                $report['samples'][] = $entry;
                            }
                        }
                    }
                    $offset += $chunk;
                }
            } catch (\Exception $e) {
                debugging('[local_xlate] Skipping table ' . $table . ' during dryrun: ' . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
            
        }

        // Persist report to system temp directory under local_xlate so operators can fetch it.
        global $CFG;

        try {
            $vardir = make_temp_directory('local_xlate');
        } catch (\Throwable $e) {
            // Fall back to Moodle's temp dir directly if helper failed.
            debugging('[local_xlate] make_temp_directory(local_xlate) failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $vardir = $CFG->tempdir ?? sys_get_temp_dir();
            if (!is_string($vardir) || $vardir === '') {
                $vardir = null;
            }
        }

        if (!empty($vardir)) {
            $filename = rtrim($vardir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mlang_dryrun_' . time() . '.json';
            @file_put_contents($filename, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $report['report_file'] = $filename;
        } else {
            $report['report_file'] = null;
        }

        return $report;
    }

    /**
     * Quick test for presence of mlang-like constructs.
     *
     * @param string $text String to inspect.
     * @return bool True if legacy multilang markers are present.
     */
    public static function contains_mlang(string $text): bool {
        if ($text === '') { return false; }
        if (stripos($text, '{mlang') !== false) { return true; }
        // Tolerant of quote style, attribute order, and multi-class values;
        // parse_multilang_span() does the precise check downstream.
        if (stripos($text, '<span') !== false && stripos($text, 'multilang') !== false) { return true; }
        return false;
    }

    /**
     * Parse {mlang} and <span lang=".." class="multilang"> occurrences.
     *
     * Extracts source/display text and translations for downstream processing.
     *
     * @param string $text Raw content containing legacy multilang markup.
     * @return array{source_text:string,display_text:string,translations:array<string,string>} Parsed content data.
     */
    public static function process_mlang_tags(string $text): array {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $validlangs = array_map('strtolower', array_keys(get_string_manager()->get_list_of_translations()));
        $validlangs[] = 'other';

        $translations = [];
        $sourcetext = '';
        $displaytext = '';
        $firstcontent = null;

        // Process multilang spans first. Attribute-order tolerant: matches any
        // span and filters via parse_multilang_span(); non-multilang spans are
        // left untouched. Processed spans are removed in the same pass.
        $text = preg_replace_callback(self::SPAN_PATTERN,
            function(array $m) use (&$translations, &$sourcetext, &$displaytext, &$firstcontent, $sitelang) {
                $lang = self::parse_multilang_span($m[1]);
                if ($lang === null) {
                    return $m[0]; // Not a multilang span — keep as-is.
                }
                $content = trim($m[2]);
                if ($firstcontent === null) { $firstcontent = $content; }
                if ($lang === $sitelang || $lang === 'other') {
                    $sourcetext .= $content . ' ';
                    $displaytext .= $content . ' ';
                } else {
                    $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $content : $content;
                }
                return ''; // Remove processed span so it is not handled again.
            }, $text) ?? $text;

        // Process {mlang xx}...{mlang} pairs
        if (preg_match_all('/\{mlang\s+([\w-]+)\}(.+?)\{mlang\}/is', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = strtolower(trim($match[1]));
                $content = trim($match[2]);
                if ($firstcontent === null) { $firstcontent = $content; }
                if ($lang === $sitelang || $lang === 'other') {
                    $sourcetext .= $content . ' ';
                    $displaytext .= $content . ' ';
                } else {
                    $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $content : $content;
                }
            }
        }

        if (!empty($sourcetext)) {
            $sourcetext = trim($sourcetext);
        } else if ($firstcontent !== null) {
            $sourcetext = trim($firstcontent);
            $displaytext = $firstcontent;
        }

        return ['source_text' => $sourcetext, 'display_text' => $displaytext, 'translations' => $translations];
    }

    /**
     * Normalise source text for hashing and matching.
     *
     * @param string $source Text to normalise.
     * @return string Normalised string safe for hashing/comparison.
     */
    public static function normalise_source(string $source): string {
        $s = trim($source);
        if ($s === '') { return ''; }
        if (function_exists('normalizer_normalize')) {
            $s = normalizer_normalize($s, \Normalizer::FORM_C);
        }
        // Collapse whitespace
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }

    /**
     * Strip MLang blocks and return text built from the preferred language.
     *
     * Preferred may be 'other', 'sitelang', or a specific language code. Falls
     * back to the first content found when no preferred match exists.
     *
     * @param string $text Source text containing legacy tags.
     * @param string $preferred Preferred language selector.
     * @return string Cleaned text after removing legacy markup.
     */
    public static function strip_mlang_tags(string $text, string $preferred = 'other'): string {
        $sitelang = get_config('core', 'lang') ?: 'en';

        // Null safety
        if ($text === null) {
            return '';
        }

        // Handle multilang spans with IN-PLACE replacement: each span is
        // replaced at its own position by its content (preferred language) or
        // removed (other languages). The previous implementation concatenated
        // all preferred-language content and PREPENDED it to the text, which
        // scrambled any mid-sentence multilang span. Attribute-order tolerant
        // via parse_multilang_span(); non-multilang spans are left untouched.
        $text = preg_replace_callback(self::SPAN_PATTERN,
            function(array $m) use ($preferred, $sitelang) {
                $lang = self::parse_multilang_span($m[1]);
                if ($lang === null) {
                    return $m[0]; // Not a multilang span — keep as-is.
                }
                $content = trim($m[2]);
                if ($lang === $preferred || ($preferred === 'sitelang' && $lang === $sitelang) || $lang === $sitelang || $lang === 'other') {
                    return $content;
                }
                return '';
            }, $text) ?? $text;

        // Now handle {mlang xx}...{mlang} pairs with offset-based replacement to avoid huge regexes
        $pattern = '/\{mlang\s+([\w-]+)\}(.+?)\{mlang\}/is';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            $result = '';
            $lastpos = 0;
            foreach ($matches as $m) {
                $lang = strtolower(trim($m[1][0] ?? ''));
                $content = trim($m[2][0] ?? '');
                $replacement = '';
                if ($lang === $preferred || ($preferred === 'sitelang' && $lang === $sitelang) || $lang === $sitelang || $lang === 'other') {
                    $replacement = $content;
                }
                $start = $m[0][1];
                $length = strlen($m[0][0]);
                // Append text before match
                $result .= substr($text, $lastpos, $start - $lastpos);
                // Append replacement
                $result .= $replacement;
                $lastpos = $start + $length;
            }
            // Append any remaining text after last match
            $result .= substr($text, $lastpos);
            $text = $result;
        }

        // Finally collapse whitespace (null-safe)
        $text = preg_replace('/\s+/u', ' ', trim($text ?? ''));
        return $text;
    }

    /**
     * Persist translations embedded in legacy mlang content instead of discarding them.
     *
     * The content being stripped by migrate() contains human-authored
     * translations. This writes each non-source-language block into
     * local_xlate_tr as a reviewed row keyed by the same plain-text xkey the
     * JS capture pipeline will generate for the cleaned source text, so the
     * harvested translation is served the moment the string is captured.
     *
     * Never overwrites: any existing translation row for (key, lang) is left
     * untouched — in-system work always wins over harvested legacy content.
     *
     * @param array{source_text:string,display_text:string,translations:array<string,string>} $parsed
     *        Output of process_mlang_tags() for the original content.
     * @param int $courseid Course to associate the key with (0 = none).
     * @param string $context Context string stored on the course association (e.g. "table:column").
     * @return int Number of translation rows created.
     */
    public static function harvest_translations(array $parsed, int $courseid = 0, string $context = ''): int {
        global $DB;

        $translations = $parsed['translations'] ?? [];
        $sourcetext = trim($parsed['source_text'] ?? '');
        if ($sourcetext === '' || empty($translations)) {
            return 0;
        }

        // Compute the plain-text xkey exactly as translator.js / rehash_keys.php do:
        // strip tags, decode entities, hash. Shared implementation lives in
        // cli/rehash_hash_lib.php — the single PHP port of JS simpleHash().
        require_once(__DIR__ . '/../cli/rehash_hash_lib.php');
        $plain = html_entity_decode(strip_tags($sourcetext), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (trim($plain) === '') {
            return 0;
        }
        $xkey = xlate_simple_hash($plain);

        $existingkey = $DB->get_record('local_xlate_key', ['component' => 'core', 'xkey' => $xkey]);

        $count = 0;
        foreach ($translations as $lang => $text) {
            $lang = strtolower(trim((string)$lang));
            $text = trim((string)$text);
            // Skip empty content and implausible language tokens.
            if ($lang === '' || $text === '' || !preg_match('/^[a-z]{2,3}(?:[_-][a-z0-9]+)?$/', $lang)) {
                continue;
            }
            // Never clobber an existing translation for this key+lang.
            if ($existingkey && $DB->record_exists('local_xlate_tr', ['keyid' => $existingkey->id, 'lang' => $lang])) {
                continue;
            }
            try {
                \local_xlate\local\api::save_key_with_translation(
                    'core', $xkey, $sourcetext, $lang, $text,
                    1, // Reviewed: this is human-authored legacy content.
                    $courseid, $context, null,
                    \local_xlate\local\api::SOURCE_MLANG
                );
                $count++;
                if (!$existingkey) {
                    // Re-fetch so subsequent langs in this loop see the key.
                    $existingkey = $DB->get_record('local_xlate_key', ['component' => 'core', 'xkey' => $xkey]);
                }
            } catch (\Throwable $e) {
                debugging('[local_xlate] mlang harvest failed for xkey ' . $xkey . ' lang ' . $lang . ': '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $count;
    }

    /**
     * Perform destructive migration replacing legacy markup with preferred text.
     *
     * Options:
     *  - tables => map of table => column list
     *  - chunk => rows per loop
     *  - preferred => 'other' | 'sitelang' | language code
    *  - execute => bool (default false, dry-run)
     *  - sample => int (max samples to include in report)
     *  - max_changes => int (optional cap on number of rows to update)
    *  - courseids => array<int,int> Optional list of allowed course ids; tables without a course mapping are skipped.
     *
     * @param \moodle_database $DB Database handle used for migration.
     * @param array<string,mixed> $options Migration options.
     * @return array<string,mixed> Report including changed count and samples.
     */
    public static function migrate(\moodle_database $DB, array $options = []): array {
        // Use autodiscovery if tables are not provided.
        $tables = $options['tables'] ?? self::discover_candidate_columns($DB);
        $chunk = $options['chunk'] ?? self::DEFAULT_CHUNK;
        if (isset($options['preferred']) && $options['preferred']) {
            $preferred = $options['preferred'];
        } else {
            $sitelang = get_config('core', 'lang') ?: '';
            $preferred = $sitelang ?: 'other';
        }
        $execute = !empty($options['execute']);
        $sample = $options['sample'] ?? self::DEFAULT_SAMPLE;
        $maxchanges = isset($options['max_changes']) ? (int)$options['max_changes'] : 0;

        $report = ['run' => date('c'), 'changed' => 0, 'harvested' => 0, 'samples' => []];
        $courseidsfilter = [];
        if (!empty($options['courseids']) && is_array($options['courseids'])) {
            $courseidsfilter = array_values(array_unique(array_filter(array_map('intval', $options['courseids']), static function($id) {
                return $id > 0;
            })));
        }

        // Output candidate list to a file for review
        $candidatefile = sys_get_temp_dir() . '/mlang_migration_candidates_' . time() . '.txt';
        $fh = fopen($candidatefile, 'w');
        foreach ($tables as $table => $cols) {
            foreach ($cols as $col) {
                error_log("[mlang_migration] Candidate: $table.$col");
                if ($fh) { fwrite($fh, "$table.$col\n"); }
            }
        }
        if ($fh) { fclose($fh); error_log("[mlang_migration] Candidate list written to $candidatefile"); }

        // Now process as before
        foreach ($tables as $table => $cols) {
            $colslist = implode(', ', array_map(function($c) { return $c; }, $cols));
            $coursecol = null;
            $coursealias = null;
            if (!empty($courseidsfilter)) {
                try {
                    $columns = $DB->get_columns($table);
                } catch (\Throwable $e) {
                    $columns = [];
                }
                if (isset($columns['course'])) {
                    $coursecol = 'course';
                } else if (isset($columns['courseid'])) {
                    $coursecol = 'courseid';
                }

                if ($coursecol === null) {
                    debugging('[local_xlate] Skipping table ' . $table . ' during migrate: cannot enforce course filter.', DEBUG_DEVELOPER);
                    continue;
                }
                $coursealias = '__xlate_course';
            }

            $selectcols = 'id, ' . $colslist;
            if ($coursecol !== null) {
                $selectcols .= ', ' . $coursecol . ' AS ' . $coursealias;
            }
            $lastid = 0;
            $table_update_count = 0;
            $table_exception = null;
            // Push the course filter into SQL so scoped runs (event-driven
            // per-course cleanup, courseids option) only read matching rows
            // instead of scanning the whole table and filtering in PHP.
            $wherecourse = '';
            $courseparams = [];
            if (!empty($courseidsfilter) && $coursecol !== null) {
                list($insql, $courseparams) = $DB->get_in_or_equal($courseidsfilter, SQL_PARAMS_NAMED, 'xlatecourse');
                $wherecourse = " AND {$coursecol} {$insql}";
            }
            while (true) {
                try {
                    // Portable chunking via limitnum instead of a raw LIMIT clause.
                    $sql = "SELECT $selectcols FROM {{$table}} WHERE id > :lastid{$wherecourse} ORDER BY id ASC";
                    $rows = $DB->get_records_sql($sql, array_merge(['lastid' => $lastid], $courseparams), 0, $chunk);
                    if (empty($rows)) {
                        break;
                    }
                    foreach ($rows as $row) {
                        if (!empty($courseidsfilter) && $coursecol !== null) {
                            $rowcourse = isset($row->{$coursealias}) ? (int)$row->{$coursealias} : 0;
                            if ($rowcourse <= 0 || !in_array($rowcourse, $courseidsfilter, true)) {
                                continue;
                            }
                        }
                        foreach ($cols as $col) {
                            if (!isset($row->{$col}) || $row->{$col} === null) {
                                continue;
                            }
                            $orig = (string)$row->{$col};
                            $isblockconfig = ($table === 'block_instances' || $table === 'mdl_block_instances') && $col === 'configdata';
                            $new = $orig;
                            $parsed = null;
                            if ($isblockconfig) {
                                // Handle base64-encoded, serialized configdata for blocks.
                                $decoded = @base64_decode($orig);
                                $changed = false;
                                if ($decoded !== false && $decoded !== '') {
                                    $data = @unserialize($decoded);
                                    if (is_array($data) || is_object($data)) {
                                        $data = (array)$data;
                                        foreach ($data as $k => $v) {
                                            if (is_string($v) && self::contains_mlang($v)) {
                                                $clean = self::strip_mlang_tags($v, $preferred);
                                                if ($clean !== $v) {
                                                    $data[$k] = $clean;
                                                    $changed = true;
                                                }
                                            }
                                        }
                                        if ($changed) {
                                            $new = base64_encode(serialize($data));
                                        }
                                    }
                                }
                            } else {
                                if (!self::contains_mlang($orig)) {
                                    continue;
                                }
                                $parsed = self::process_mlang_tags($orig);
                                $normalized = self::normalise_source($parsed['source_text'] ?? '');
                                $new = self::strip_mlang_tags($orig, $preferred);
                                if ($new === '' && !empty($parsed['source_text'])) { $new = $parsed['source_text']; }
                            }

                            if ($new !== $orig) {
                                if (count($report['samples']) < $sample) {
                                    $report['samples'][] = [
                                        'table' => $table,
                                        'id' => $row->id,
                                        'column' => $col,
                                        'old' => mb_substr($orig, 0, 2000),
                                        'new' => mb_substr($new, 0, 2000),
                                    ];
                                }
                                if ($execute) {
                                    if (method_exists($DB, 'start_delegated_transaction')) {
                                        $transaction = $DB->start_delegated_transaction();
                                        try {
                                            $DB->set_field($table, $col, $new, ['id' => $row->id]);
                                            $prov = new \stdClass();
                                            $prov->tablename = $table;
                                            $prov->recordid = $row->id;
                                            $prov->columnname = $col;
                                            $prov->old_value = $orig;
                                            $prov->new_value = $new;
                                            $prov->migrated_at = time();
                                            $prov->migrated_by = (isloggedin() && !empty($GLOBALS['USER']->id)) ? $GLOBALS['USER']->id : 0;
                                            try {
                                                if ($DB->get_manager()->table_exists(new \xmldb_table('local_xlate_mlang_migration'))) {
                                                    $DB->insert_record('local_xlate_mlang_migration', $prov);
                                                }
                                            } catch (\Exception $e) {
                                                debugging('[local_xlate] provenance insert failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                                            }
                                            $transaction->allow_commit();
                                        } catch (\Exception $e) {
                                            try {
                                                $transaction->rollback($e);
                                            } catch (\Exception $e2) {}
                                            error_log("[mlang_migration]   Update FAILED: $table.$col id=" . ($row->id ?? 'n/a') . " - " . $e->getMessage());
                                            debugging('[local_xlate] migration update failed for ' . $table . ':' . $row->id . ' - ' . $e->getMessage(), DEBUG_DEVELOPER);
                                            continue;
                                        }
                                    } else {
                                        try {
                                            $DB->set_field($table, $col, $new, ['id' => $row->id]);
                                        } catch (\Exception $e) {
                                            debugging('[local_xlate] migration update failed for ' . $table . ':' . $row->id . ' - ' . $e->getMessage(), DEBUG_DEVELOPER);
                                            continue;
                                        }
                                        $prov = new \stdClass();
                                        $prov->tablename = $table;
                                        $prov->recordid = $row->id;
                                        $prov->columnname = $col;
                                        $prov->old_value = $orig;
                                        $prov->new_value = $new;
                                        $prov->migrated_at = time();
                                        $prov->migrated_by = (isloggedin() && !empty($GLOBALS['USER']->id)) ? $GLOBALS['USER']->id : 0;
                                        try {
                                            if ($DB->get_manager()->table_exists(new \xmldb_table('local_xlate_mlang_migration'))) {
                                                $DB->insert_record('local_xlate_mlang_migration', $prov);
                                            }
                                        } catch (\Exception $e) {
                                            debugging('[local_xlate] provenance insert failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                                        }
                                    }
                                }
                                // Harvest embedded translations before they are lost.
                                // Reached only when the update succeeded (failure paths
                                // `continue` above). Runs in execute mode only.
                                if ($execute && is_array($parsed) && !empty($parsed['translations'])) {
                                    $rowcourseid = ($coursecol !== null && isset($row->{$coursealias}))
                                        ? (int)$row->{$coursealias} : 0;
                                    $report['harvested'] += self::harvest_translations(
                                        $parsed, max(0, $rowcourseid), $table . ':' . $col);
                                }
                                $report['changed']++;
                                $table_update_count++;
                                if ($maxchanges > 0 && $report['changed'] >= $maxchanges) {
                                    return $report;
                                }
                            }
                        }
                        if (isset($row->id) && $row->id > $lastid) {
                            $lastid = $row->id;
                        }
                    }
                } catch (\Exception $e) {
                    $table_exception = $e;
                    break;
                }
            }
            if ($table_exception) {
                debugging('[local_xlate] Skipping table ' . $table . ' during migrate: ' . $table_exception->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
            $colnames = implode(', ', $cols);
            error_log("[mlang_migration] Table: $table | Columns: $colnames | Updated: $table_update_count");
        }
        return $report;
    }

    /**
     * Discover candidate text-like columns in the current Moodle database.
     * Returns a map of table => [columns] using table names without prefix.
     * Options may include:
     *  - prefix: explicit table prefix to strip (defaults to $DB->get_prefix())
     *  - include_patterns: array of column-name regex fragments to prefer
     *  - exclude_tables: array of table names (without prefix) to skip
     *  - full_scan: if true, include any text-like column (not just name-matched)
     *
     * This is a read-only operation and intended to be conservative by default.
     */
    public static function discover_candidate_columns(\moodle_database $DB, array $opts = []): array {
        $prefix = $opts['prefix'] ?? $DB->get_prefix();
        // Only include these types for candidate columns
        $types = ["text","tinytext","mediumtext","longtext","varchar"];
        // Merge caller-supplied exclusions with the permanent skip list.
        $excludetables = array_merge(self::SKIP_TABLES, $opts['exclude_tables'] ?? []);
        $map = [];
        $tables = $DB->get_tables();
        foreach ($tables as $tablename) {
            if (stripos($tablename, 'xlate') !== false) {
                continue;
            }
            if (in_array($tablename, $excludetables, true)) {
                continue;
            }
            $columns = $DB->get_columns($tablename);
            foreach ($columns as $col => $info) {
                $type = strtolower($info->type ?? '');
                if (!in_array($type, $types)) continue;
                $map[$tablename][] = $col;
            }
        }
        return $map;
    }
}