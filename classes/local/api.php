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
 * Core API for the Local Xlate plugin.
 *
 * Provides bundle generation, cache invalidation, translation persistence,
 * and association helpers used by both UI and scheduled tasks.
 *
 * @package    local_xlate
 * @category   local
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Facade for translation persistence, bundle assembly, and cache coordination.
 *
 * Centralises the data-layer affordances used by the plugin's REST endpoints,
 * UI controllers, and scheduled tasks for reading and writing translation
 * records. Methods in this class encapsulate the SQL access patterns, cache
 * invalidation rules, and version bookkeeping required to keep translation
 * bundles in sync.
 *
 * @package local_xlate\local
 */
class api {
    /**
     * Fetch a translation bundle for explicit keys without extra metadata.
     *
     * Applies component filters derived from the page context and optionally
     * narrows results to keys associated with a course. Useful for AJAX
     * endpoints that only require xkey => translation pairs.
     *
     * @param string $lang Target language code (e.g. `en`, `es`).
     * @param array<int,string> $keys Stable translation keys to resolve.
     * @param \context|null $context Context used to derive component filters; defaults to system.
     * @param string $pagetype Optional pagetype hint (e.g. `mod-forum-view`).
     * @param int $courseid Optional course to scope associations.
     * @return array{translations:array<string,string>,sources:array<string,string>,reviewed:array<string,int>} Map of xkey => translation + source metadata.
     */
    public static function get_keys_bundle(string $lang, array $keys, ?\context $context = null, string $pagetype = '', int $courseid = 0): array {
        global $DB;

        if (empty($keys)) {
            return ['translations' => [], 'sources' => [], 'reviewed' => []];
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
            return ['translations' => [], 'sources' => [], 'reviewed' => []];
        }

        // Build IN clause safely
        list($insql, $inparams) = $DB->get_in_or_equal($clean, SQL_PARAMS_NAMED, 'k');
        $params = array_merge(['lang' => $lang], $inparams);

        $context = $context ?: \context_system::instance();
        $componentsql = '';
        $componentparams = [];

        if ($context instanceof \context) {
            $filters = self::get_component_filters($pagetype, $context, $courseid);
            if (empty($filters)) {
                $filters = ['core', 'theme_%', 'block_%', 'local_xlate'];
            }
            list($componentsql, $componentparams) = self::build_component_filter_sql($filters);
        }

        if (!empty($componentparams)) {
            $params = array_merge($params, $componentparams);
        }

        $coursewhere = '';
        if ($courseid > 0) {
            $coursewhere = " AND (NOT EXISTS (SELECT 1 FROM {local_xlate_key_course} kc WHERE kc.keyid = k.id)
                                   OR EXISTS (SELECT 1 FROM {local_xlate_key_course} kc2 WHERE kc2.keyid = k.id AND kc2.courseid = :courseid))";
            $params['courseid'] = $courseid;
        }

        // Resolve translation ids separately so each get_records_sql call keeps a unique key column.
        $sql = "SELECT k.id, k.xkey,
                       (SELECT MIN(t2.id)
                          FROM {local_xlate_tr} t2
                         WHERE t2.keyid = k.id AND t2.lang = :lang AND t2.status = 1) AS firsttrid
                  FROM {local_xlate_key} k
                 WHERE k.xkey $insql$componentsql$coursewhere";

        $recs = $DB->get_records_sql($sql, $params);

        $map = [];
        $sources = [];
        $reviewedmap = [];
        if (empty($recs)) {
            return ['translations' => $map, 'sources' => $sources, 'reviewed' => $reviewedmap];
        }

        $trids = [];
        foreach ($recs as $rec) {
            if (!empty($rec->firsttrid)) {
                $trids[] = (int)$rec->firsttrid;
            }
        }

        if (!empty($trids)) {
            list($trsql, $trparams) = $DB->get_in_or_equal($trids, SQL_PARAMS_NAMED, 'tr');
            $sql = "SELECT t.id, k.xkey, k.source, t.text, t.reviewed
                      FROM {local_xlate_tr} t
                      JOIN {local_xlate_key} k ON k.id = t.keyid
                     WHERE t.id $trsql";
            $translations = $DB->get_records_sql($sql, $trparams);
            foreach ($translations as $row) {
                $map[$row->xkey] = $row->text;
                $sources[$row->xkey] = $row->source ?? '';
                $reviewedmap[$row->xkey] = (int)$row->reviewed;
            }
        }

        return ['translations' => $map, 'sources' => $sources, 'reviewed' => $reviewedmap];
    }

    /**
     * Resolve translations for specific keys and expose source + course metadata.
     *
     * Extends {@see get_keys_bundle()} by returning the normalised source map
     * used for fuzzy lookups and, when a course ID is supplied, a boolean map
     * indicating whether each key is associated with that course.
     *
     * @param string $lang Target language code.
     * @param array<int,string> $keys Stable translation keys to resolve.
     * @param int $courseid Optional course to include association status for.
    * @return array{translations:array<string,string>,sources:array<string,string>,sourceMap:array<string,string>,associations?:array<string,bool>} Structured bundle response.
     */
    public static function get_keys_bundle_with_associations(string $lang, array $keys, int $courseid = 0): array {
        global $DB;

        $bundle = self::get_keys_bundle($lang, $keys);
        $translations = $bundle['translations'];
        $reviewedmap = $bundle['reviewed'];
        $sources = $bundle['sources'] ?? [];

        // Build sourceMap for the returned keys
        $sourceMap = [];
        if (!empty($keys)) {
            list($insql, $inparams) = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED, 'k');
            $sql = "SELECT k.id, k.xkey, k.source FROM {local_xlate_key} k WHERE k.xkey $insql";
            $recs = $DB->get_records_sql($sql, $inparams);
            foreach ($recs as $r) {
                $normalized = self::normalise_source($r->source ?? '');
                if ($normalized !== '' && !isset($sourceMap[$normalized])) {
                    $sourceMap[$normalized] = $r->xkey;
                }
            }
        }

    $result = ['translations' => $translations, 'sourceMap' => $sourceMap, 'sources' => $sources, 'reviewed' => $reviewedmap];

        // If courseid present, compute associations map
        if (!empty($courseid) && is_int($courseid) && $courseid > 0) {
            // Resolve keys -> keyids
            if (!empty($keys)) {
                list($insql, $inparams) = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED, 'k');
                $sql = "SELECT k.id, k.xkey FROM {local_xlate_key} k WHERE k.xkey $insql";
                $recs = $DB->get_records_sql($sql, $inparams);
                $keyidmap = [];
                $ids = [];
                foreach ($recs as $r) {
                    $keyidmap[$r->xkey] = $r->id;
                    $ids[] = $r->id;
                }

                $associations = [];
                if (!empty($ids)) {
                    list($idsql, $idparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'i');
                    $params = array_merge(['courseid' => $courseid], $idparams);
                    $sql = "SELECT kc.keyid FROM {local_xlate_key_course} kc WHERE kc.courseid = :courseid AND kc.keyid $idsql";
                    $rows = $DB->get_records_sql($sql, $params);
                    $associatedids = array_keys($rows);
                    $associatedset = array_flip($associatedids);

                    foreach ($keyidmap as $xkey => $kid) {
                        $associations[$xkey] = isset($associatedset[$kid]);
                    }
                } else {
                    // No keys present in DB; mark all false
                    foreach ($keys as $k) { $associations[$k] = false; }
                }
                $result['associations'] = $associations;
            } else {
                $result['associations'] = [];
            }
        }

        return $result;
    }
    
    /**
     * Build a cached bundle for the current page context.
     *
     * Generates a translation set plus source map tailored to the supplied
     * pagetype, context, and optional course. Results are cached using the
     * context-sensitive cache key helpers until invalidated by write operations.
     *
     * @param string $lang Language code for the bundle.
     * @param string $pagetype Optional Moodle pagetype hint.
     * @param \context|null $context Active execution context (defaults to system).
     * @param \stdClass|null $user Optional user object (defaults to global $USER).
     * @param int $courseid Optional course for scoping results.
     * @return array{translations:array<string,string>,sourceMap:array<string,string>} Cached bundle payload.
     */
    public static function get_page_bundle(string $lang, string $pagetype = '', ?\context $context = null, ?\stdClass $user = null, int $courseid = 0): array {
        global $DB, $USER;
        
        $user = $user ?: $USER;
        $context = $context ?: \context_system::instance();
        
        $cache_key = self::make_bundle_cache_key($lang, $context, $pagetype, $courseid);
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
        
        list($componentsql, $componentparams) = self::build_component_filter_sql($component_filters);
        $coursewhere = '';
        $params = ['lang' => $lang];

        if (!empty($componentparams)) {
            $params = array_merge($params, $componentparams);
        }

        if ($courseid > 0) {
            $coursewhere = " AND (NOT EXISTS (SELECT 1 FROM {local_xlate_key_course} kc WHERE kc.keyid = k.id)
                                   OR EXISTS (SELECT 1 FROM {local_xlate_key_course} kc2 WHERE kc2.keyid = k.id AND kc2.courseid = :courseid))";
            $params['courseid'] = $courseid;
        }

    $sql = "SELECT k.id, k.xkey, k.source, t.text, k.component, t.reviewed
                  FROM {local_xlate_key} k
                  JOIN {local_xlate_tr} t ON t.keyid = k.id
                 WHERE t.lang = :lang AND t.status = 1 $componentsql $coursewhere";
        
        $recs = $DB->get_records_sql($sql, $params);
        
    $bundle = ['translations' => [], 'sourceMap' => [], 'reviewed' => []];
        foreach ($recs as $r) {
            $bundle['translations'][$r->xkey] = $r->text;
            $normalized = self::normalise_source($r->source ?? '');
            if ($normalized !== '' && !isset($bundle['sourceMap'][$normalized])) {
                $bundle['sourceMap'][$normalized] = $r->xkey;
            }
            $bundle['reviewed'][$r->xkey] = (int)$r->reviewed;
        }
        
        // Cache for shorter time due to context sensitivity
        $cache->set($cache_key, $bundle);
        self::remember_bundle_cache_key($lang, $cache_key, $cache);
        return $bundle;
    }

    /**
     * Normalise source text for fuzzy lookups (case/punctuation agnostic).
     *
     * Collapses whitespace, lowercases, and removes punctuation so callers can
     * build deterministic source maps that survive minor content variations.
     *
     * @param string|null $source Raw source string from storage.
     * @return string Normalised key safe for use in associative arrays.
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
     * Derive component filter patterns for the current request.
     *
     * Uses pagetype conventions and context level to build a list of component
     * LIKE patterns that keep translation bundles focused on relevant strings.
     *
     * @param string $pagetype Moodle pagetype string (e.g. `mod-quiz-view`).
     * @param \context $context Active context driving visibility rules.
     * @param int $courseid Optional course influencing course-specific filters.
     * @return array<int,string> Component wildcards to feed into SQL LIKE expressions.
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

    /**
     * Convert component wildcard list into SQL fragments.
     *
     * Produces the WHERE clause snippet and related named parameters that can
     * be appended to translation bundle queries.
     *
     * @param array<int,string> $filters Component patterns produced by {@see get_component_filters()}.
     * @return array{0:string,1:array<string,string>} Tuple of SQL suffix and parameter map.
     */
    private static function build_component_filter_sql(array $filters): array {
        if (empty($filters)) {
            return ['', []];
        }

        $likes = [];
        $params = [];
        foreach ($filters as $i => $filter) {
            // Wildcards already include % when needed; use LIKE for patterns.
            $param = 'component' . $i;
            $likes[] = "k.component LIKE :$param";
            $params[$param] = $filter;
        }

        $sql = ' AND (' . implode(' OR ', $likes) . ')';
        return [$sql, $params];
    }

    /**
     * Compose a cache key for a bundle scoped to lang/context/pagetype/course.
     *
     * @param string $lang Language code.
     * @param \context $context Context instance that constrains visibility.
     * @param string $pagetype Sanitised pagetype string.
     * @param int $courseid Course identifier (0 when global).
     * @return string Cache key safe for use with Moodle cache API.
     */
    private static function make_bundle_cache_key(string $lang, \context $context, string $pagetype, int $courseid): string {
        $sanitisedpagetype = preg_replace('/[^a-zA-Z0-9]/', '', $pagetype);
        return $lang . '_' . $context->id . '_' . $sanitisedpagetype . '_' . $courseid;
    }

    /**
     * Cache key prefix used to store the list of bundle cache entries per lang.
     *
     * @param string $lang Language code.
     * @return string Cache key for the bundle index entry in Moodle cache.
     */
    private static function bundle_index_cache_key(string $lang): string {
        return '__index__' . $lang;
    }

    /**
     * Track a bundle cache key in the per-language index for later invalidation.
     *
     * @param string $lang Language code whose index to update.
     * @param string $cachekey Bundle cache key that was just written.
     * @param \cache $cache Cache store instance managing bundle entries.
     * @return void
     */
    private static function remember_bundle_cache_key(string $lang, string $cachekey, \cache $cache): void {
        $indexkey = self::bundle_index_cache_key($lang);
        $keys = $cache->get($indexkey);
        if (!is_array($keys)) {
            $keys = [];
        }

        if (!in_array($cachekey, $keys, true)) {
            $keys[] = $cachekey;
            $cache->set($indexkey, $keys);
        }
    }

    /**
     * Return the current bundle version string for a language.
     *
     * Falls back to `dev` when no version record exists yet.
     *
     * @param string $lang Language code.
     * @return string Version identifier consumed by client caches.
     */
    public static function get_version(string $lang): string {
        global $DB;
        $rec = $DB->get_record('local_xlate_bundle', ['lang' => $lang], '*', IGNORE_MISSING);
        return $rec ? $rec->version : 'dev';
    }
    
    /**
     * Fetch a translation key record by component + xkey composite.
     *
     * @param string $component Moodle component name (e.g. `local_xlate`).
     * @param string $xkey Stable key identifier.
     * @return \stdClass|false Database record or false when missing.
     */
    public static function get_key_by_component_xkey(string $component, string $xkey) {
        global $DB;
        return $DB->get_record('local_xlate_key', ['component' => $component, 'xkey' => $xkey]);
    }
    
    /**
     * Ensure a translation key exists and return its ID.
     *
     * Updates the source string and mtime when the key already exists; creates
     * a new record otherwise.
     *
     * @param string $component Moodle component identifier.
     * @param string $xkey Translation key identifier.
     * @param string $source Optional source string to store alongside the key.
     * @return int Database ID for the key record.
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
     * Associate multiple translation keys with a course, creating missing keys.
     *
     * Processes keys in chunks to keep queries manageable, creates new key
     * records when necessary, and inserts association rows while handling
     * races gracefully.
     *
     * @param array<int,array{component?:string,xkey:string,source?:string}> $keys Keys to associate.
     * @param int $courseid Course identifier.
     * @param string $context Optional free-form context string stored alongside the association.
     * @return array<string,string> Status per xkey (`created_and_associated`, `associated`, `exists`, `error`, etc.).
     */
    public static function associate_keys_with_course(array $keys, int $courseid, string $context = ''): array {
        global $DB, $USER;

        $details = [];
        if (empty($keys) || empty($courseid) || $courseid <= 0) {
            return $details;
        }

        // Process in chunks to avoid large IN lists
        $chunksize = 200;
        $chunks = array_chunk($keys, $chunksize);

        foreach ($chunks as $chunk) {
            // Resolve existing keys by component+xkey
            $conds = [];
            $params = [];
            $index = 0;
            $lookupmap = [];
            foreach ($chunk as $k) {
                $comp = (string)($k['component'] ?? 'core');
                $xkey = (string)($k['xkey'] ?? '');
                if ($xkey === '') { continue; }
                $paramcomp = 'comp' . $index;
                $paramxkey = 'xkey' . $index;
                $conds[] = "(k.component = :$paramcomp AND k.xkey = :$paramxkey)";
                $params[$paramcomp] = $comp;
                $params[$paramxkey] = $xkey;
                $lookupmap[$comp . '::' . $xkey] = $k;
                $index++;
            }

            if (!empty($conds)) {
                $sql = 'SELECT k.id, k.component, k.xkey FROM {local_xlate_key} k WHERE ' . implode(' OR ', $conds);
                $recs = $DB->get_records_sql($sql, $params);
                $existingmap = [];
                foreach ($recs as $r) {
                    $existingmap[$r->component . '::' . $r->xkey] = $r->id;
                }

                // For each key in chunk, ensure key exists (create if missing), then associate
                foreach ($chunk as $k) {
                    $comp = (string)($k['component'] ?? 'core');
                    $xkey = (string)($k['xkey'] ?? '');
                    $source = (string)($k['source'] ?? '');
                    if ($xkey === '') { continue; }
                    $lookup = $comp . '::' . $xkey;
                    try {
                        if (isset($existingmap[$lookup])) {
                            $keyid = $existingmap[$lookup];
                        } else {
                            // Create key
                            $keyid = self::create_or_update_key($comp, $xkey, $source);
                            $existingmap[$lookup] = $keyid;
                            $details[$xkey] = 'created_key';
                        }

                        // Now associate (dedupe by keyid + courseid only)
                        $rec = (object)[
                            'keyid' => $keyid,
                            'courseid' => $courseid,
                            'context' => $context,
                            'mtime' => time()
                        ];
                        try {
                            $DB->insert_record('local_xlate_key_course', $rec);
                            $details[$xkey] = isset($details[$xkey]) && $details[$xkey] === 'created_key' ? 'created_and_associated' : 'associated';
                        } catch (\Exception $e) {
                            // race or duplicate - check by keyid+courseid
                            $existing2 = $DB->get_record('local_xlate_key_course', [
                                'keyid' => $keyid,
                                'courseid' => $courseid,
                            ]);
                            if ($existing2) {
                                $details[$xkey] = isset($details[$xkey]) && $details[$xkey] === 'created_key' ? 'created_and_associated_exists' : 'exists';
                            } else {
                                $details[$xkey] = 'error';
                            }
                        }
                    } catch (\Exception $e) {
                        $details[$xkey] = 'error';
                    }
                }
            }
        }

        return $details;
    }
    
    /**
     * Upsert a translation record for the given key and language.
     *
     * Updates timestamps/status flags on existing records and creates new
     * rows when no match is present.
     *
     * @param int $keyid Foreign key for the translation key.
     * @param string $lang Language code.
     * @param string $text Translated text to persist.
     * @param int $status Publication status flag (default approved).
     * @param int $reviewed Reviewer flag (0/1).
     * @return int Translation record ID.
     */
    public static function save_translation(int $keyid, string $lang, string $text, int $status = 1, int $reviewed = 0): int {
        global $DB;
        
        $existing = $DB->get_record('local_xlate_tr', ['keyid' => $keyid, 'lang' => $lang]);
        $now = time();
        
        if ($existing) {
            // Update existing translation
            $existing->text = $text;
            $existing->status = $status;
            $existing->reviewed = $reviewed;
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
                'reviewed' => $reviewed,
                'mtime' => $now
            ];
            return $DB->insert_record('local_xlate_tr', $record);
        }
    }

    /**
     * Delete a stored translation for the specified key and language.
     *
     * Also invalidates bundle caches so downstream consumers pick up the
     * removal without waiting for cron-driven rebuilds.
     *
     * @param int $keyid Numeric translation key id.
     * @param string $lang Language code (e.g. en, es).
     * @return bool True when a row was removed.
     */
    public static function delete_translation(int $keyid, string $lang): bool {
        global $DB;

        $lang = trim($lang);
        if ($keyid <= 0 || $lang === '') {
            return false;
        }

        $deleted = $DB->delete_records('local_xlate_tr', [
            'keyid' => $keyid,
            'lang' => $lang
        ]);

        if ($deleted) {
            self::invalidate_bundle_cache($lang);
            self::update_bundle_version($lang);
        }

        return (bool)$deleted;
    }
    
    /**
     * Persist a translation key and translated string within a transaction.
     *
     * Coordinates key creation, translation upsert, optional course association,
     * cache invalidation, and bundle version updates. Rolls back the delegated
     * transaction when any step fails.
     *
     * @param string $component Moodle component identifier.
     * @param string $xkey Translation key identifier.
     * @param string $source Source string paired with the key.
     * @param string $lang Language code for the translation.
     * @param string $translation Translated text.
     * @param int $reviewed Reviewer flag persisted on the translation row.
     * @param int $courseid Optional course association to record.
     * @param string $context Optional context string stored with the association.
     * @return int Key ID for the saved translation.
     * @throws \Throwable Propagates lower-level database exceptions for caller handling.
     */
    public static function save_key_with_translation(string $component, string $xkey, string $source, string $lang, string $translation, int $reviewed = 0, int $courseid = 0, string $context = ''): int {
        global $DB;
        
        $transaction = $DB->start_delegated_transaction();
        
        try {
            $source = self::normalize_inline_markup($source);
            $translation = self::normalize_inline_markup($translation);
            if ($source === '') {
                $source = $translation;
            }
            // Create or update the key
            $keyid = self::create_or_update_key($component, $xkey, $source);
            
            // Save the translation (propagate reviewed flag)
            self::save_translation($keyid, $lang, $translation, 1, $reviewed);

            // If a course association was provided, record it (associate by keyid+courseid).
            if (!empty($courseid) && is_int($courseid) && $courseid > 0) {
                // Associate by keyid+courseid (no source_hash dedupe)
                $existing = $DB->get_record('local_xlate_key_course', [
                    'keyid' => $keyid,
                    'courseid' => $courseid,
                ]);

                if (!$existing) {
                    $rec = (object)[
                        'keyid' => $keyid,
                        'courseid' => $courseid,
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
                            'courseid' => $courseid
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
            
        } catch (\Throwable $e) {
            // Roll back and bubble up so callers can react appropriately.
            $transaction->rollback($e);
            if (!($e instanceof \moodle_exception)) {
                debugging('[local_xlate] save_key_with_translation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            throw $e;
        }
    }

    private static function normalize_inline_markup(string $value): string {
        if ($value === '') {
            return $value;
        }
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('#<\\/([a-z0-9]+)>#i', '</$1>', $decoded);
        $decoded = preg_replace('#<([a-z0-9]+)\\/>#i', '<$1/>', $decoded);
        $decoded = preg_replace('#\\(/|/\\)#', '/', $decoded);
        $decoded = str_replace(['\"', '\\'], ['"', '\\'], $decoded);
        return $decoded;
    }
    
    /**
     * Compute a deterministic bundle version hash for a language.
     *
     * Uses the maximum mtime across keys/translations to invalidate cached
     * bundles whenever content changes.
     *
     * @param string $lang Language code.
     * @return string SHA1 hash representing the latest bundle state.
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
     * Persist a new bundle version hash for the given language.
     *
     * Creates the row when absent and updates the timestamp for existing
     * records.
     *
     * @param string $lang Language code being refreshed.
     * @return string Newly computed version hash.
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
     * Flush all cached bundles for a language.
     *
     * Uses the per-language index to remove every context-specific cache entry
     * plus the language-level item to ensure subsequent reads rebuild bundles.
     *
     * @param string $lang Language whose cache entries should be removed.
     * @return void
     */
    public static function invalidate_bundle_cache(string $lang): void {
        $cache = \cache::make('local_xlate', 'bundle');
        $indexkey = self::bundle_index_cache_key($lang);
        $keys = $cache->get($indexkey);

        if (is_array($keys)) {
            foreach ($keys as $cachekey) {
                $cache->delete($cachekey);
            }
        }

        $cache->delete($indexkey);
        $cache->delete($lang);
    }
    
    /**
     * Recompute bundle versions for every language with approved translations.
     *
     * Clears caches and updates version hashes, returning the list of affected
     * language codes for logging or UI feedback.
     *
     * @return array<int,string> Languages rebuilt.
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
     * Retrieve translation keys with optional search and pagination.
     *
     * @param int $offset Record offset for pagination.
     * @param int $limit Number of rows to return.
     * @param string $search Optional search term applied across component, xkey, and source fields.
     * @return array<int,\stdClass> List of key records including translation_count field.
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
     * Count translation keys matching an optional search term.
     *
     * @param string $search Optional search query applied to component/xkey/source.
     * @return int Total record count.
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
