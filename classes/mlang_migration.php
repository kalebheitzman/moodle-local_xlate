<?php
namespace local_xlate;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper for MLang migration operations (dry-run and destructive variants).
 */
class mlang_migration {
    /** Default chunk size for scanning rows. */
    const DEFAULT_CHUNK = 250;

    /** Default sample size for report. */
    const DEFAULT_SAMPLE = 1000;

    /**
     * Run a non-destructive dry-run scan for MLang occurrences.
     *
     * @param \moodle_database $DB
     * @param array $options Optional settings: tables => [col,...], chunk, sample
     * @return array Report containing counts and sample entries.
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
        $vardir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'local_xlate';
        if (!is_dir($vardir)) {
            @mkdir($vardir, 0755, true);
        }
        $filename = $vardir . DIRECTORY_SEPARATOR . 'mlang_dryrun_' . time() . '.json';
        @file_put_contents($filename, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $report['report_file'] = $filename;

        return $report;
    }

    /**
     * Quick test for presence of mlang-like constructs.
     * @param string $text
     * @return bool
     */
    public static function contains_mlang(string $text): bool {
        if ($text === '') { return false; }
        if (stripos($text, '{mlang') !== false) { return true; }
        if (stripos($text, '<span') !== false && stripos($text, 'class="multilang"') !== false) { return true; }
        return false;
    }

    /**
     * Parse {mlang} and <span lang=".." class="multilang"> occurrences and extract source and translations.
     * Returns array with keys: source_text, display_text, translations (lang => text)
     * @param string $text
     * @return array
     */
    public static function process_mlang_tags(string $text): array {
        $sitelang = get_config('core', 'lang') ?: 'en';
        $validlangs = array_map('strtolower', array_keys(get_string_manager()->get_list_of_translations()));
        $validlangs[] = 'other';

        $translations = [];
        $sourcetext = '';
        $displaytext = '';
        $firstcontent = null;

        // Process <span lang="xx" class="multilang"> first
        $pattern = '/<span\s+lang=["\']([a-zA-Z-]+)["\']\s+class=["\']multilang["\']>(.*?)<\/span>/is';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = strtolower($match[1]);
                $content = trim($match[2]);
                if ($firstcontent === null) { $firstcontent = $content; }
                if ($lang === $sitelang || $lang === 'other') {
                    $sourcetext .= $content . ' ';
                    $displaytext .= $content . ' ';
                } else {
                    $translations[$lang] = isset($translations[$lang]) ? $translations[$lang] . ' ' . $content : $content;
                }
            }
            // Remove processed spans so they are not handled again.
            $text = preg_replace($pattern, '', $text);
        }

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
     * @param string $source
     * @return string
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
     * Strip MLang blocks from text and produce a replacement string using the preferred source.
     * Preferred may be 'other' or a sitelang code. Falls back to the first content found.
     * @param string $text
     * @param string $preferred
     * @return string
     */
    public static function strip_mlang_tags(string $text, string $preferred = 'other'): string {
        $sitelang = get_config('core', 'lang') ?: 'en';

        // Replace <span lang="xx" class="multilang">...</span>
        $result = '';
        $offset = 0;

        // We'll iterate over both span and {mlang ...} constructs; simple approach: replace spans first.
        $patternspan = '/<span\s+lang=["\']([a-zA-Z-]+)["\']\s+class=["\']multilang["\']>(.*?)<\/span>/is';
        if (preg_match_all($patternspan, $text, $matches, PREG_SET_ORDER)) {
            // Build replacement by extracting matching-language pieces in order.
            $built = '';
            foreach ($matches as $m) {
                $lang = strtolower($m[1]);
                $content = trim($m[2]);
                if ($lang === $preferred || ($preferred === 'sitelang' && $lang === $sitelang) || $lang === $sitelang || $lang === 'other') {
                    // prefer preferred, but accept sitelang/other as fallback
                    $built .= $content . ' ';
                }
            }
            if (trim($built) !== '') {
                // Remove all processed spans and replace them with built text.
                $text = preg_replace($patternspan, '', $text);
                $text = trim($built) . ' ' . $text;
            }
        }

        // Now handle {mlang xx}...{mlang} pairs.
        $pattern = '/\{mlang\s+([\w-]+)\}(.+?)\{mlang\}/is';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            // We will replace each occurrence with the chosen language content.
            foreach ($matches as $m) {
                $lang = strtolower(trim($m[1]));
                $content = trim($m[2]);
                $replacement = '';
                if ($lang === $preferred || ($preferred === 'sitelang' && $lang === $sitelang) || $lang === $sitelang || $lang === 'other') {
                    $replacement = $content;
                }
                // Replace this specific instance with the replacement (may be empty).
                $text = preg_replace('/\{mlang\s+' . preg_quote($m[1], '/') . '\}' . preg_quote($m[2], '/') . '\{mlang\}/is', $replacement, $text, 1);
            }
        }

        // Finally collapse whitespace.
        $text = preg_replace('/\s+/u', ' ', trim($text));
        return $text;
    }

    /**
     * Perform destructive migration to replace MLang with chosen source across candidate columns.
     * By default runs in dry-run mode (no writes). Set 'execute' => true to actually modify rows.
     * Options:
     *  - tables => map table => [cols]
     *  - chunk => rows per loop
     *  - preferred => 'other' | 'sitelang' | language code
     *  - execute => bool (default false)
     *  - sample => int (max samples to include in returned report)
     * @param \moodle_database $DB
     * @param array $options
     * @return array report including changed count and sample entries
     */
    public static function migrate(\moodle_database $DB, array $options = []): array {
        $tables = $options['tables'] ?? self::default_tables();
        $chunk = $options['chunk'] ?? self::DEFAULT_CHUNK;
        $preferred = $options['preferred'] ?? 'other';
        $execute = !empty($options['execute']);
        $sample = $options['sample'] ?? self::DEFAULT_SAMPLE;
        $maxchanges = isset($options['max_changes']) ? (int)$options['max_changes'] : 0;

        $report = ['run' => date('c'), 'changed' => 0, 'samples' => []];

        foreach ($tables as $table => $cols) {
            $colslist = implode(', ', array_map(function($c) { return $c; }, $cols));
            $sql = "SELECT id, " . $colslist . " FROM {" . $table . "}";
            $offset = 0;
            try {
                while ($rows = $DB->get_records_sql($sql, [], $offset, $chunk)) {
                    foreach ($rows as $row) {
                        foreach ($cols as $col) {
                            if (!isset($row->{$col}) || $row->{$col} === null) { continue; }
                            $orig = (string)$row->{$col};
                            if (!self::contains_mlang($orig)) { continue; }

                            $parsed = self::process_mlang_tags($orig);
                            $normalized = self::normalise_source($parsed['source_text'] ?? '');
                            // source_hash removed: provenance/reporting no longer requires a separate hash value.

                            // Build replacement text based on preference.
                            $new = self::strip_mlang_tags($orig, $preferred);

                            // If strip produced empty string but parsed has source_text, prefer that.
                            if ($new === '' && !empty($parsed['source_text'])) { $new = $parsed['source_text']; }

                            if ($new !== $orig) {
                                // Record sample regardless of execute to allow review.
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
                                    // Do a safe transactional update and record provenance.
                                    // Use delegated transaction if available; otherwise do best-effort updates without explicit transaction.
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
                                            // source_hash removed from provenance recording.
                                            $prov->migrated_at = time();
                                            $prov->migrated_by = (isloggedin() && !empty($GLOBALS['USER']->id)) ? $GLOBALS['USER']->id : 0;

                                            try {
                                                if ($DB->get_manager()->table_exists(new \xmldb_table('local_xlate_mlang_migration'))) {
                                                    $DB->insert_record('local_xlate_mlang_migration', $prov);
                                                }
                                            } catch (\Exception $e) {
                                                debugging('[local_xlate] provenance insert failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                                            }

                                            // Commit by allowing transaction to complete.
                                            $transaction->allow_commit();
                                        } catch (\Exception $e) {
                                            try {
                                                $transaction->rollback($e);
                                            } catch (\Exception $e2) {
                                                // ignore rollback failures
                                            }
                                            debugging('[local_xlate] migration update failed for ' . $table . ':' . $row->id . ' - ' . $e->getMessage(), DEBUG_DEVELOPER);
                                            continue;
                                        }
                                    } else {
                                        // Best-effort fallback for older DB implementations: update and try to insert provenance.
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
                                        // source_hash removed from provenance recording.
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

                                $report['changed']++;
                                // If a max_changes cap is provided, stop early when reached.
                                if ($maxchanges > 0 && $report['changed'] >= $maxchanges) {
                                    // Return immediately with current report and samples.
                                    return $report;
                                }
                            }
                        }
                    }
                    $offset += $chunk;
                }
            } catch (\Exception $e) {
                debugging('[local_xlate] Skipping table ' . $table . ' during migrate: ' . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
        }

        return $report;
    }

    /**
     * Default table/column map to scan. Extend as needed via options.
     * Keys are table names (without prefix), values are arrays of column names.
     * @return array
     */
    public static function default_tables(): array {
        // Expanded default table/column mapping includes common title/name fields
        // so the migration is portable and thorough without requiring external JSON.
        return [
            'course' => ['fullname', 'summary'],
            'course_sections' => ['name', 'summary'],
            'course_categories' => ['name', 'description'],
            'page' => ['name', 'content'],
            'label' => ['name', 'intro'],
            'resource' => ['name', 'intro'],
            'book_chapters' => ['title', 'content'],
            'assign' => ['name', 'intro'],
            'quiz' => ['name', 'intro'],
            'question' => ['questiontext'],
            'forum' => ['name', 'intro'],
            'forum_posts' => ['subject', 'message'],
            'glossary' => ['name', 'intro'],
            'glossary_entries' => ['concept', 'definition'],
            'workshop' => ['name', 'intro'],
            'lesson' => ['name', 'content'],
            'url' => ['name', 'intro'],
            'scorm' => ['name', 'intro'],
            'choice' => ['name', 'intro'],
            'data' => ['name', 'intro'],
            'wiki' => ['name'],
            // Local plugin table for translations (if present) so text rows can be cleaned.
            'local_xlate_tr' => ['text'],
        ];
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
     * @param \moodle_database $DB
     * @param array $opts
     * @return array
     */
    public static function discover_candidate_columns(\moodle_database $DB, array $opts = []): array {
        global $CFG;

        $prefix = $opts['prefix'] ?? $DB->get_prefix();
        $includepatterns = $opts['include_patterns'] ?? ['content','intro','summary','description','message','body','text','note','feedback','response','html','heading'];
        $exclude = $opts['exclude_tables'] ?? ['cache','temp','task_','log','backup_'];
        $fullscan = !empty($opts['full_scan']);

        // Text-like types we consider safe to scan.
        $types = ["varchar","char","text","tinytext","mediumtext","longtext","json"];

        // Query information_schema for this database.
        // Try to obtain the current DB name if available; otherwise fall back to DATABASE().
        $dbname = null;
        try {
            $mgr = $DB->get_manager();
            if (is_object($mgr) && method_exists($mgr, 'get_database_name')) {
                $dbname = $mgr->get_database_name();
            }
        } catch (\Exception $e) {
            $dbname = null;
        }

        $placeholders = [];
        $typelist = "'" . implode("','", $types) . "'";
        $sql = "SELECT table_name, column_name, data_type
                  FROM information_schema.columns
                 WHERE data_type IN ($typelist)";
        if ($dbname !== null) {
            $sql .= " AND table_schema = '" . $DB->get_manager()->get_database_name() . "'";
        } else {
            $sql .= " AND table_schema = DATABASE()";
        }

        // Limit to tables that start with the Moodle prefix for safety.
        if (!empty($prefix)) {
            $sql .= " AND table_name LIKE '" . $DB->get_prefix() . "%'";
        }

        $sql .= " ORDER BY table_name, column_name";

        $candidates = [];
        try {
            $rows = $DB->get_records_sql($sql);
        } catch (\Exception $e) {
            // If information_schema is not accessible or query fails, fall back to defaults.
            debugging('[local_xlate] discover_candidate_columns failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::default_tables();
        }

        foreach ($rows as $r) {
            $tbl = $r->table_name;
            // Strip prefix if present.
            if (!empty($prefix) && strpos($tbl, $prefix) === 0) {
                $short = substr($tbl, strlen($prefix));
            } else {
                $short = $tbl;
            }

            // Skip excluded table name patterns.
            $skip = false;
            foreach ($exclude as $ex) {
                if (stripos($short, $ex) === 0 || stripos($short, $ex) !== false && substr($ex, -1) === '_') {
                    $skip = true; break;
                }
            }
            if ($skip) { continue; }

            $col = $r->column_name;
            $add = false;
            if ($fullscan) {
                $add = true;
            } else {
                foreach ($includepatterns as $p) {
                    if (stripos($col, $p) !== false) { $add = true; break; }
                }
            }
            if (!$add) { continue; }

            if (!isset($candidates[$short])) { $candidates[$short] = []; }
            if (!in_array($col, $candidates[$short])) { $candidates[$short][] = $col; }
        }

        // If discovery yielded nothing, fall back to default_tables to be safe.
        if (empty($candidates)) {
            return self::default_tables();
        }

        // Optionally prune tables with zero rows to avoid scanning empty tables.
        $final = [];
        foreach ($candidates as $table => $cols) {
            try {
                $count = $DB->count_records($table);
            } catch (\dml_exception $e) {
                // Table might not exist in this install; skip it.
                continue;
            }
            if ($count === 0) { continue; }
            $final[$table] = $cols;
        }

        return !empty($final) ? $final : self::default_tables();
    }
}
