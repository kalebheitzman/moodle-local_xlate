<?php
namespace local_xlate\local;

defined('MOODLE_INTERNAL') || die();

class api {
    /**
     * Get translations for a specific set of keys only.
     * Returns a flat associative array: { xkey => translation }
     *
     * @param string $lang Language code
     * @param array $keys List of xkeys to fetch
     * @return array Map of xkey => text
     */
    public static function get_keys_bundle(string $lang, array $keys): array {
        global $DB;

        if (empty($keys)) {
            return [];
        }

        // Sanitize keys: allow only base36-ish keys up to 64 chars to be safe
        $clean = [];
        foreach ($keys as $k) {
            $k = (string)$k;
            if ($k === '') { continue; }
            if (preg_match('/^[a-z0-9\-_:]{3,64}$/i', $k)) {
                $clean[] = $k;
            }
        }

        // Hard cap to prevent abuse
        $clean = array_slice(array_values(array_unique($clean)), 0, 2000);

        if (empty($clean)) {
            return [];
        }

        // Build IN clause safely
        list($insql, $inparams) = $DB->get_in_or_equal($clean, SQL_PARAMS_NAMED, 'k');
        $params = array_merge(['lang' => $lang], $inparams);

        $sql = "SELECT k.xkey, t.text\n                  FROM {local_xlate_key} k\n                  JOIN {local_xlate_tr} t ON t.keyid = k.id\n                 WHERE t.lang = :lang AND t.status = 1 AND k.xkey $insql";

        $recs = $DB->get_records_sql($sql, $params);

        $map = [];
        foreach ($recs as $r) {
            $map[$r->xkey] = $r->text;
        }

        return $map;
    }
    
    /**
     * Get page-specific translation bundle with context filtering
     * @param string $lang Language code
     * @param string $pagetype Moodle page type
     * @param \context $context Current context
     * @param \stdClass $user Current user
     * @param int $courseid Course ID if applicable
     * @return array Translation bundle
     */
    public static function get_page_bundle(string $lang, string $pagetype = '', ?\context $context = null, ?\stdClass $user = null, int $courseid = 0): array {
        global $DB, $USER;
        
        $user = $user ?: $USER;
        $context = $context ?: \context_system::instance();
        
        // Create cache key that includes context and user permissions
        // Use only alphanumeric characters for cache key (Moodle requirement)
        $cache_key = $lang . '_' . $context->id . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $pagetype) . '_' . $courseid;
        $cache = \cache::make('local_xlate', 'bundle');
        
        if ($hit = $cache->get($cache_key)) {
            return $hit;
        }
        
        // Build component filter based on page type and context
        $component_filters = self::get_component_filters($pagetype, $context, $courseid);
        
        if (empty($component_filters)) {
            // If no specific filters, return safe UI components only
            $component_filters = ['core', 'theme_%', 'block_%', 'local_xlate'];
        }
        
        // TEMPORARY: Disable filtering to debug
    $sql = "SELECT k.xkey, k.source, t.text, k.component
                  FROM {local_xlate_key} k
                  JOIN {local_xlate_tr} t ON t.keyid = k.id
                 WHERE t.lang = :lang AND t.status = 1";
        
        $params = ['lang' => $lang];
        
        $recs = $DB->get_records_sql($sql, $params);
        
        $bundle = ['translations' => [], 'sourceMap' => []];
        foreach ($recs as $r) {
            $bundle['translations'][$r->xkey] = $r->text;
            $normalized = self::normalise_source($r->source ?? '');
            if ($normalized !== '' && !isset($bundle['sourceMap'][$normalized])) {
                $bundle['sourceMap'][$normalized] = $r->xkey;
            }
        }
        
        // Cache for shorter time due to context sensitivity
        $cache->set($cache_key, $bundle);
        return $bundle;
    }

    /**
     * Normalise source text for fuzzy lookups (case/punctuation agnostic)
     * @param string|null $source
     * @return string
     */
    private static function normalise_source(?string $source): string {
        if ($source === null) {
            return '';
        }

        $normalised = trim($source);
        if ($normalised === '') {
            return '';
        }

        if (function_exists('normalizer_normalize')) {
            $normalised = normalizer_normalize($normalised, \Normalizer::FORM_C);
        }

        $normalised = mb_strtolower($normalised, 'UTF-8');

        // Replace any sequence of non-letter/digit characters with a single space
        $normalised = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalised);
        $normalised = preg_replace('/\s+/u', ' ', $normalised);

        return trim($normalised);
    }
    
    /**
     * Get component filters based on page context
     * @param string $pagetype
     * @param \context $context
     * @param int $courseid
     * @return array
     */
    private static function get_component_filters(string $pagetype, \context $context, int $courseid): array {
        $filters = ['core', 'theme_%', 'block_%', 'local_xlate'];
        
        // Add region-based components (for auto-detected content)
        $filters[] = 'region_%';
        
        // Add context-specific components
        if ($context->contextlevel == CONTEXT_COURSE || $courseid > 0) {
            $filters[] = 'course';
            $filters[] = 'grades';
            $filters[] = 'completion';
        }
        
        // Add page-type specific components
        if (strpos($pagetype, 'mod-') === 0) {
            // Activity pages - allow specific module translations
            $modname = substr($pagetype, 4, strpos($pagetype, '-', 4) - 4);
            $filters[] = 'mod_' . $modname;
        }
        
        if (strpos($pagetype, 'course-') === 0) {
            $filters[] = 'course';
            $filters[] = 'enrol_%';
        }
        
        if (strpos($pagetype, 'admin-') === 0) {
            $filters[] = 'admin';
            $filters[] = 'tool_%';
        }
        
        return $filters;
    }

    public static function get_version(string $lang): string {
        global $DB;
        $rec = $DB->get_record('local_xlate_bundle', ['lang' => $lang], '*', IGNORE_MISSING);
        return $rec ? $rec->version : 'dev';
    }
    
    /**
     * Get translation key by component and xkey
     * @param string $component
     * @param string $xkey
     * @return object|false
     */
    public static function get_key_by_component_xkey(string $component, string $xkey) {
        global $DB;
        return $DB->get_record('local_xlate_key', ['component' => $component, 'xkey' => $xkey]);
    }
    
    /**
     * Create or update a translation key
     * @param string $component
     * @param string $xkey  
     * @param string $source
     * @return int Key ID
     */
    public static function create_or_update_key(string $component, string $xkey, string $source = ''): int {
        global $DB;
        
        $existing = self::get_key_by_component_xkey($component, $xkey);
        $now = time();
        
        if ($existing) {
            // Update existing key
            $existing->source = $source;
            $existing->mtime = $now;
            $DB->update_record('local_xlate_key', $existing);
            return $existing->id;
        } else {
            // Create new key
            $record = (object) [
                'component' => $component,
                'xkey' => $xkey,
                'source' => $source,
                'mtime' => $now
            ];
            return $DB->insert_record('local_xlate_key', $record);
        }
    }
    
    /**
     * Save translation for a key
     * @param int $keyid
     * @param string $lang
     * @param string $text
     * @param int $status
     * @return int Translation ID
     */
    public static function save_translation(int $keyid, string $lang, string $text, int $status = 1): int {
        global $DB;
        
        $existing = $DB->get_record('local_xlate_tr', ['keyid' => $keyid, 'lang' => $lang]);
        $now = time();
        
        if ($existing) {
            // Update existing translation
            $existing->text = $text;
            $existing->status = $status;
            $existing->mtime = $now;
            $DB->update_record('local_xlate_tr', $existing);
            return $existing->id;
        } else {
            // Create new translation
            $record = (object) [
                'keyid' => $keyid,
                'lang' => $lang,
                'text' => $text,
                'status' => $status,
                'mtime' => $now
            ];
            return $DB->insert_record('local_xlate_tr', $record);
        }
    }
    
    /**
     * Save key with translation in one operation
     * @param string $component
     * @param string $xkey
     * @param string $source
     * @param string $lang
     * @param string $translation
     * @return int Key ID
     */
    public static function save_key_with_translation(string $component, string $xkey, string $source, string $lang, string $translation, int $courseid = 0, string $context = ''): int {
        global $DB;
        
        $transaction = $DB->start_delegated_transaction();
        
        try {
            // Create or update the key
            $keyid = self::create_or_update_key($component, $xkey, $source);
            
            // Save the translation
            self::save_translation($keyid, $lang, $translation);

            // If a course association was provided, record it (deduped by source hash).
            if (!empty($courseid) && is_int($courseid) && $courseid > 0) {
                global $USER;
                $sourcehash = sha1($source ?? '');
                $existing = $DB->get_record('local_xlate_key_course', [
                    'keyid' => $keyid,
                    'courseid' => $courseid,
                    'source_hash' => $sourcehash
                ]);

                if (!$existing) {
                    $rec = (object)[
                        'keyid' => $keyid,
                        'courseid' => $courseid,
                        'source_hash' => $sourcehash,
                        'userid' => isset($USER->id) ? $USER->id : 0,
                        'context' => $context,
                        'mtime' => time()
                    ];
                    try {
                        $DB->insert_record('local_xlate_key_course', $rec);
                    } catch (\Exception $e) {
                        // Possible race: another request inserted the same unique row concurrently.
                        // Re-check for existing row; if found, treat as benign, otherwise rethrow.
                        $existing2 = $DB->get_record('local_xlate_key_course', [
                            'keyid' => $keyid,
                            'courseid' => $courseid,
                            'source_hash' => $sourcehash
                        ]);
                        if ($existing2) {
                            // benign race; ignore
                            debugging('[local_xlate] Ignored duplicate insert into local_xlate_key_course (race condition)', DEBUG_DEVELOPER);
                        } else {
                            // Unexpected DB error - rethrow to surface the issue and rollback transaction
                            throw $e;
                        }
                    }
                }
            }
            
            // Invalidate cache for this language
            self::invalidate_bundle_cache($lang);
            
            // Update bundle version
            self::update_bundle_version($lang);
            
            $transaction->allow_commit();
            
            return $keyid;
            
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }
    
    /**
     * Generate new version hash for a language
     * @param string $lang
     * @return string
     */
    public static function generate_version_hash(string $lang): string {
        global $DB;
        
        // Get maximum mtime for this language's translations
        $sql = "SELECT MAX(GREATEST(k.mtime, t.mtime)) as maxtime
                FROM {local_xlate_key} k
                JOIN {local_xlate_tr} t ON t.keyid = k.id
                WHERE t.lang = ? AND t.status = 1";
        
        $maxtime = $DB->get_field_sql($sql, [$lang]) ?: time();
        
        // Create hash from language + maxtime
        return sha1($lang . ':' . $maxtime);
    }
    
    /**
     * Update bundle version for a language
     * @param string $lang
     * @return string New version hash
     */
    public static function update_bundle_version(string $lang): string {
        global $DB;
        
        $version = self::generate_version_hash($lang);
        $now = time();
        
        $existing = $DB->get_record('local_xlate_bundle', ['lang' => $lang]);
        
        if ($existing) {
            $existing->version = $version;
            $existing->mtime = $now;
            $DB->update_record('local_xlate_bundle', $existing);
        } else {
            $record = (object) [
                'lang' => $lang,
                'version' => $version,
                'mtime' => $now
            ];
            $DB->insert_record('local_xlate_bundle', $record);
        }
        
        return $version;
    }
    
    /**
     * Invalidate bundle cache for a language
     * @param string $lang
     */
    public static function invalidate_bundle_cache(string $lang): void {
        $cache = \cache::make('local_xlate', 'bundle');
        $cache->delete($lang);
    }
    
    /**
     * Rebuild all bundle versions
     * @return array Languages rebuilt
     */
    public static function rebuild_all_bundles(): array {
        global $DB;
        
        // Get all languages that have translations
        $langs = $DB->get_fieldset_sql(
            "SELECT DISTINCT lang FROM {local_xlate_tr} WHERE status = 1"
        );
        
        $rebuilt = [];
        foreach ($langs as $lang) {
            self::invalidate_bundle_cache($lang);
            self::update_bundle_version($lang);
            $rebuilt[] = $lang;
        }
        
        return $rebuilt;
    }
    
    /**
     * Get all translation keys with pagination
     * @param int $offset
     * @param int $limit
     * @param string $search
     * @return array
     */
    public static function get_keys_paginated(int $offset = 0, int $limit = 50, string $search = ''): array {
        global $DB;
        
        $conditions = [];
        $params = [];
        
        if (!empty($search)) {
            $conditions[] = "(k.component LIKE ? OR k.xkey LIKE ? OR k.source LIKE ?)";
            $searchparam = '%' . $DB->sql_like_escape($search) . '%';
            $params[] = $searchparam;
            $params[] = $searchparam;
            $params[] = $searchparam;
        }
        
        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
        
        $sql = "SELECT k.*, COUNT(t.id) as translation_count
                FROM {local_xlate_key} k
                LEFT JOIN {local_xlate_tr} t ON t.keyid = k.id AND t.status = 1
                {$where}
                GROUP BY k.id, k.component, k.xkey, k.source, k.mtime
                ORDER BY k.component, k.xkey";
        
        return $DB->get_records_sql($sql, $params, $offset, $limit);
    }
    
    /**
     * Count total translation keys
     * @param string $search
     * @return int
     */
    public static function count_keys(string $search = ''): int {
        global $DB;
        
        $conditions = [];
        $params = [];
        
        if (!empty($search)) {
            $conditions[] = "(component LIKE ? OR xkey LIKE ? OR source LIKE ?)";
            $searchparam = '%' . $DB->sql_like_escape($search) . '%';
            $params[] = $searchparam;
            $params[] = $searchparam;
            $params[] = $searchparam;
        }
        
        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
        
        return $DB->count_records_sql("SELECT COUNT(*) FROM {local_xlate_key} {$where}", $params);
    }
}
