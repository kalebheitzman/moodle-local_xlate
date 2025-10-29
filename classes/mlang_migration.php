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
                            $sourcehash = $normalized === '' ? '' : sha1($normalized);

                            $entry = [
                                'table' => $table,
                                'id' => $row->id,
                                'column' => $col,
                                'snippet' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($text))), 0, 500),
                                'source' => $parsed['source_text'] ?? '',
                                'languages' => array_values($parsed['translations'] ?? []),
                                'detected_lang_codes' => array_keys($parsed['translations'] ?? []),
                                'source_hash' => $sourcehash,
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
     * Default table/column map to scan. Extend as needed via options.
     * Keys are table names (without prefix), values are arrays of column names.
     * @return array
     */
    public static function default_tables(): array {
        return [
            'course' => ['summary'],
            'course_sections' => ['summary'],
            'page' => ['content'],
            'label' => ['intro'],
            'forum_posts' => ['message'],
            'resource' => ['intro'],
            'book_chapters' => ['content'],
            'assign' => ['intro'],
            'quiz' => ['intro'],
            'question' => ['questiontext'],
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
