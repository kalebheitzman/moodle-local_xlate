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
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTOTRANSLATE = 'autotranslate';
    /** Translation harvested from legacy {mlang}/multilang-span content during migration. */
    public const SOURCE_MLANG = 'mlang';

    /** @var array<int,string|null> */
    protected static array $courseSourceCache = [];

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
     * @param array<int,int>|null $visiblecourseids Only used when $courseid is 0 (system-context
     *        requests): null = no restriction; an array restricts course-associated keys to the
     *        listed courses (global/unassociated keys are always served). Pass the requesting
     *        user's enrolled course ids to prevent arbitrary-key mining of other courses' content.
    * @return array{translations:array<string,string>,sources:array<string,string>,reviewed:array<string,int>,critical:array<string,int>,keyids:array<string,int>} Map of xkey => translation + source metadata.
     */
    public static function get_keys_bundle(string $lang, array $keys, ?\context $context = null, string $pagetype = '', int $courseid = 0, ?array $visiblecourseids = null): array {
        global $DB;

        if (empty($keys)) {
            return ['translations' => [], 'sources' => [], 'reviewed' => [], 'critical' => [], 'keyids' => []];
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
            return ['translations' => [], 'sources' => [], 'reviewed' => [], 'critical' => [], 'keyids' => []];
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
        } else if ($visiblecourseids !== null) {
            // System-context request with a visibility restriction (C5): serve
            // global (unassociated) keys, plus keys associated with the courses
            // the requester may see. An empty array means global keys only.
            if (empty($visiblecourseids)) {
                $coursewhere = " AND NOT EXISTS (SELECT 1 FROM {local_xlate_key_course} kc WHERE kc.keyid = k.id)";
            } else {
                list($vcsql, $vcparams) = $DB->get_in_or_equal(
                    array_map('intval', $visiblecourseids), SQL_PARAMS_NAMED, 'vc');
                $coursewhere = " AND (NOT EXISTS (SELECT 1 FROM {local_xlate_key_course} kc WHERE kc.keyid = k.id)
                                       OR EXISTS (SELECT 1 FROM {local_xlate_key_course} kc2 WHERE kc2.keyid = k.id AND kc2.courseid $vcsql))";
                $params = array_merge($params, $vcparams);
            }
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
        $keyids = [];
        if (empty($recs)) {
            return ['translations' => $map, 'sources' => $sources, 'reviewed' => $reviewedmap, 'critical' => [], 'keyids' => []];
        }

        $trids = [];
        foreach ($recs as $rec) {
            $keyids[$rec->xkey] = (int)$rec->id;
            if (!empty($rec->firsttrid)) {
                $trids[] = (int)$rec->firsttrid;
            }
        }

        $criticalmap = [];
        if (!empty($trids)) {
            list($trsql, $trparams) = $DB->get_in_or_equal($trids, SQL_PARAMS_NAMED, 'tr');
            $sql = "SELECT t.id, k.id AS keyid, k.xkey, k.source, k.critical, t.text, t.reviewed
                      FROM {local_xlate_tr} t
                      JOIN {local_xlate_key} k ON k.id = t.keyid
                     WHERE t.id $trsql";
            $translations = $DB->get_records_sql($sql, $trparams);
            foreach ($translations as $row) {
                $map[$row->xkey] = $row->text;
                $sources[$row->xkey] = $row->source ?? '';
                $reviewedmap[$row->xkey] = (int)$row->reviewed;
                $criticalmap[$row->xkey] = (int)$row->critical;
                $keyids[$row->xkey] = (int)$row->keyid;
            }
        }

        return ['translations' => $map, 'sources' => $sources, 'reviewed' => $reviewedmap, 'critical' => $criticalmap, 'keyids' => $keyids];
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
     * @param \context|null $context Context used to derive component filters (C5: must be
     *        threaded through from the serving endpoint, otherwise no scoping applies).
     * @param string $pagetype Optional pagetype hint for component filtering.
     * @param array<int,int>|null $visiblecourseids Course-visibility restriction for
     *        system-context requests — see get_keys_bundle().
    * @return array{translations:array<string,string>,sources:array<string,string>,sourceMap:array<string,string>,critical:array<string,int>,keyids:array<string,int>,associations?:array<string,bool>} Structured bundle response.
     */
    public static function get_keys_bundle_with_associations(string $lang, array $keys, int $courseid = 0,
            ?\context $context = null, string $pagetype = '', ?array $visiblecourseids = null): array {
        global $DB;

        $bundle = self::get_keys_bundle($lang, $keys, $context, $pagetype, $courseid, $visiblecourseids);
        $translations = $bundle['translations'];
        $reviewedmap = $bundle['reviewed'];
        $sources = $bundle['sources'] ?? [];

        // Build sourceMap from the SCOPED bundle results only. The previous
        // implementation re-queried local_xlate_key for every requested xkey
        // with no context/course filter, leaking source strings for keys the
        // requester was not entitled to see (C5).
        $sourceMap = [];
        foreach ($sources as $xkey => $src) {
            $normalized = self::normalise_source((string)$src);
            if ($normalized !== '' && !isset($sourceMap[$normalized])) {
                $sourceMap[$normalized] = $xkey;
            }
        }

    $criticalmap = $bundle['critical'] ?? [];
    $keyidmap = $bundle['keyids'] ?? [];
    $result = ['translations' => $translations, 'sourceMap' => $sourceMap, 'sources' => $sources, 'reviewed' => $reviewedmap, 'critical' => $criticalmap, 'keyids' => $keyidmap];

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
                    $associatedids = $DB->get_fieldset_sql($sql, $params);
                    $associatedset = array_flip(array_map('intval', $associatedids));

                    foreach ($keyidmap as $xkey => $kid) {
                        $associations[$xkey] = isset($associatedset[(int)$kid]);
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
     * Ensure every key in $xkeys has a local_xlate_key_course row for $courseid.
     *
     * Called automatically from bundle.php on every course-context bundle request.
     * Any key that exists in local_xlate_key but lacks an association for this
     * course gets one created here, making the autotranslation task pick it up on
     * its next run without requiring a manager capture-mode walk.
     *
     * @param array<int,string> $xkeys Raw xkey strings from the bundle request.
     * @param int $courseid Course to associate with.
     * @return int Number of new associations created.
     */
    public static function backfill_key_course_associations(array $xkeys, int $courseid): int {
        global $DB;

        if ($courseid <= 0 || empty($xkeys)) {
            return 0;
        }

        $clean = array_values(array_unique(array_filter(array_map('strval', $xkeys), static function ($k) {
            return preg_match('/^[a-z0-9\-_:]{3,64}$/i', $k);
        })));

        if (empty($clean)) {
            return 0;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($clean, SQL_PARAMS_NAMED, 'bk');
        $params = array_merge(['courseid' => $courseid], $inparams);

        $sql = "SELECT k.id
                  FROM {local_xlate_key} k
                 WHERE k.xkey $insql
                   AND NOT EXISTS (
                       SELECT 1 FROM {local_xlate_key_course} kc
                        WHERE kc.keyid = k.id AND kc.courseid = :courseid
                   )";

        $missing = $DB->get_fieldset_sql($sql, $params);
        if (empty($missing)) {
            return 0;
        }

        $created = 0;
        foreach ($missing as $keyid) {
            try {
                $DB->insert_record('local_xlate_key_course', (object)[
                    'keyid'    => (int)$keyid,
                    'courseid' => $courseid,
                ]);
                $created++;
            } catch (\Throwable $e) {
                // Race condition or duplicate — harmless, skip.
            }
        }

        return $created;
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
    * @return array{translations:array<string,string>,sourceMap:array<string,string>,critical:array<string,int>,keyids:array<string,int>} Cached bundle payload.
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

    $sql = "SELECT k.id, k.xkey, k.source, k.critical, t.text, k.component, t.reviewed
                  FROM {local_xlate_key} k
                  JOIN {local_xlate_tr} t ON t.keyid = k.id
                 WHERE t.lang = :lang AND t.status = 1 $componentsql $coursewhere";
        
        $recs = $DB->get_records_sql($sql, $params);
        
    $bundle = ['translations' => [], 'sourceMap' => [], 'reviewed' => [], 'critical' => [], 'keyids' => []];
        foreach ($recs as $r) {
            $bundle['translations'][$r->xkey] = $r->text;
            $normalized = self::normalise_source($r->source ?? '');
            if ($normalized !== '' && !isset($bundle['sourceMap'][$normalized])) {
                $bundle['sourceMap'][$normalized] = $r->xkey;
            }
            $bundle['reviewed'][$r->xkey] = (int)$r->reviewed;
            $bundle['critical'][$r->xkey] = (int)$r->critical;
            $bundle['keyids'][$r->xkey] = (int)$r->id;
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
    * @param int|null $critical Optional critical flag to store (null to preserve existing value).
    * @return int Database ID for the key record.
    */
    public static function create_or_update_key(string $component, string $xkey, string $source = '', ?int $critical = null): int {
        global $DB;

        $source = self::normalize_utf8_text($source);
        
        $existing = self::get_key_by_component_xkey($component, $xkey);
        $now = time();
        
        if ($existing) {
            // Update existing key
            $existing->source = $source;
            $existing->mtime = $now;
            if ($critical !== null) {
                $existing->critical = (int)$critical;
            }
            $DB->update_record('local_xlate_key', $existing);
            return $existing->id;
        } else {
            // Create new key
            $record = (object) [
                'component' => $component,
                'xkey' => $xkey,
                'source' => $source,
                'mtime' => $now,
                'ctime' => $now,
                'critical' => ($critical !== null) ? (int)$critical : 0
            ];
            return $DB->insert_record('local_xlate_key', $record);
        }
    }

    /**
     * Update the critical flag for a key identified by numeric id.
     *
     * @param int $keyid Local Xlate key id.
     * @param bool $critical Desired critical state.
     * @return bool True when the record was updated.
     */
    public static function set_key_critical_by_id(int $keyid, bool $critical): bool {
        global $DB;

        if ($keyid <= 0) {
            return false;
        }

        $record = $DB->get_record('local_xlate_key', ['id' => $keyid], '*', IGNORE_MISSING);
        if (!$record) {
            return false;
        }

        $record->critical = $critical ? 1 : 0;
        $record->mtime = time();
        $DB->update_record('local_xlate_key', $record);

        return true;
    }

    /**
     * Update the critical flag for every key sharing the supplied xkey.
     *
     * @param string $xkey Stable translation key hash.
     * @param bool $critical Desired critical state.
     * @return array{success:bool,updated:int,keyids:array<int>} Outcome metadata for callers.
     */
    public static function set_key_critical_by_xkey(string $xkey, bool $critical): array {
        global $DB;

        $xkey = trim($xkey);
        if ($xkey === '') {
            return ['success' => false, 'updated' => 0, 'keyids' => []];
        }

        $records = $DB->get_records('local_xlate_key', ['xkey' => $xkey]);
        if (empty($records)) {
            return ['success' => false, 'updated' => 0, 'keyids' => []];
        }

        $updated = 0;
        $keyids = [];
        $criticalvalue = $critical ? 1 : 0;
        $now = time();

        foreach ($records as $record) {
            $record->critical = $criticalvalue;
            $record->mtime = $now;
            $DB->update_record('local_xlate_key', $record);
            $updated++;
            $keyids[] = (int)$record->id;
        }

        return ['success' => $updated > 0, 'updated' => $updated, 'keyids' => $keyids];
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
     * @param string $source Source indicator (manual/autotranslate).
      * @param int $courseid Optional course context id for logging/activity attribution.
      * @param bool $force When true, allows an autotranslate source to overwrite a
      *                     human-reviewed translation. Ignored for non-autotranslate sources.
      * @return int Translation record ID.
     */
        public static function save_translation(int $keyid, string $lang, string $text,
                                            int $status = 1, int $reviewed = 0,
                                                          string $source = self::SOURCE_MANUAL,
                                                          int $courseid = 0, bool $force = false): int {
        global $DB;

            $text = self::normalize_utf8_text($text);
        $text = translation_cleanup::sanitize_html($text);
                $lang = trim($lang);
                $courseid = max(0, (int)$courseid);

        $isautotranslate = ($source === self::SOURCE_AUTOTRANSLATE);
                $suppressactivity = self::should_suppress_source_activity_logging($courseid, $lang);

        $existing = $DB->get_record('local_xlate_tr', ['keyid' => $keyid, 'lang' => $lang]);
        $now = time();

        // Never let machine translation silently clobber a human-reviewed translation.
        // The nightly course task already filters these out before calling the backend;
        // this is the last line of defense for every other caller (batch task, inline
        // autotranslate) that doesn't pre-filter.
        if ($existing && $isautotranslate && (int)$existing->reviewed === 1 && !$force) {
            return (int)$existing->id;
        }

        if ($existing) {
            // Update existing translation
            $previousText = $existing->text ?? '';
            $previousStatus = (int)$existing->status;
            $previousReviewed = (int)$existing->reviewed;

            $textchanged = ($previousText !== $text);
            $statuschanged = ($previousStatus !== $status);
            $reviewchanged = ($previousReviewed !== $reviewed);

            $existing->text = $text;
            $existing->status = $status;
            $existing->reviewed = $reviewed;
            $existing->mtime = $now;
            $DB->update_record('local_xlate_tr', $existing);

            $translationid = (int)$existing->id;
            if ($textchanged) {
                $action = $isautotranslate ? activity_logger::ACTION_AUTOTRANSLATE : activity_logger::ACTION_UPDATE;
                $metadata = [
                    'previouslength' => \core_text::strlen($previousText),
                    'newlength' => \core_text::strlen($text),
                ];
                if ($isautotranslate) {
                    $metadata['origin'] = $source;
                }
                if (!$suppressactivity) {
                    activity_logger::log($keyid, $translationid, $lang, $action, $metadata, $courseid);
                }
            }
            if ($statuschanged) {
                $action = $status === 1
                    ? activity_logger::ACTION_STATUS_ACTIVE
                    : activity_logger::ACTION_STATUS_INACTIVE;
                if (!$suppressactivity) {
                    activity_logger::log($keyid, $translationid, $lang, $action, [
                        'previous' => $previousStatus,
                        'current' => $status,
                    ], $courseid);
                }
            }
            // Only log a standalone review event when the review flag changed WITHOUT a text
            // change. If text was also written, the review flip is part of that work unit and
            // logging it separately would double-count characters for payroll purposes.
            if ($reviewchanged && !$textchanged) {
                $action = $reviewed ? activity_logger::ACTION_REVIEW_MARK : activity_logger::ACTION_REVIEW_CLEAR;
                if (!$suppressactivity) {
                    activity_logger::log($keyid, $translationid, $lang, $action, [], $courseid);
                }
            }

            return $translationid;
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
            $translationid = (int)$DB->insert_record('local_xlate_tr', $record);
            $action = $isautotranslate ? activity_logger::ACTION_AUTOTRANSLATE : activity_logger::ACTION_CREATE;
            $meta = $isautotranslate ? ['origin' => $source] : [];
            if (!$suppressactivity) {
                activity_logger::log($keyid, $translationid, $lang, $action, $meta, $courseid);
            }
            // Do NOT log ACTION_REVIEW_MARK here — the reviewed flag on a new record is an
            // attribute of the creation event, not a separate billable action.
            if ($status !== 1) {
                // New record with non-default status — always inactive since status != 1.
                if (!$suppressactivity) {
                    activity_logger::log($keyid, $translationid, $lang, activity_logger::ACTION_STATUS_INACTIVE, [
                        'previous' => 1,
                        'current' => $status,
                    ], $courseid);
                }
            }
            return $translationid;
        }
    }

    /**
     * Determine whether activity logging should be suppressed for the given course/language pair.
     *
     * Source-language saves (DOM capture events) must never appear in the activity log as
     * billable translator work. When a course context is available we check its configured
     * source language; when there is no course (system/front-page captures) we fall back to
     * $CFG->lang as the site-level source language.
     */
    protected static function should_suppress_source_activity_logging(int $courseid, string $lang): bool {
        global $CFG;

        if ($lang === '') {
            return false;
        }

        $lang = \core_text::strtolower($lang);

        if ($courseid > 0) {
            $sourcelang = self::get_course_source_lang_for_logging($courseid);
            if ($sourcelang === null) {
                return false;
            }
            return $lang === \core_text::strtolower($sourcelang);
        }

        // No course context — use the site language as the source-language fallback.
        // Captures on system pages (front page, admin blocks, etc.) are always in the
        // site language, so matching against $CFG->lang is correct for the common case.
        $sitelang = \core_text::strtolower(isset($CFG->lang) ? (string)$CFG->lang : 'en');
        return $lang === $sitelang;
    }

    /**
     * Fetch and cache the configured source language for a course.
     */
    protected static function get_course_source_lang_for_logging(int $courseid): ?string {
        if ($courseid <= 0) {
            return null;
        }

        if (array_key_exists($courseid, self::$courseSourceCache)) {
            return self::$courseSourceCache[$courseid];
        }

        $source = null;
        try {
            $config = \local_xlate\customfield_helper::get_course_config($courseid);
            if (is_array($config) && !empty($config['source'])) {
                $source = (string)$config['source'];
            }
        } catch (\Throwable $e) {
            $source = null;
        }

        self::$courseSourceCache[$courseid] = $source;
        return $source;
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
    public static function delete_translation(int $keyid, string $lang, int $courseid = 0): bool {
        global $DB;

        $lang = trim($lang);
        if ($keyid <= 0 || $lang === '') {
            return false;
        }

        // Capture the translation id before deleting so the activity log entry is complete.
        $existing = $DB->get_record('local_xlate_tr', ['keyid' => $keyid, 'lang' => $lang], 'id');
        $translationid = $existing ? (int)$existing->id : 0;

        $deleted = $DB->delete_records('local_xlate_tr', [
            'keyid' => $keyid,
            'lang' => $lang
        ]);

        if ($deleted) {
            self::invalidate_bundle_cache($lang);
            self::update_bundle_version($lang);
            if ($translationid > 0) {
                activity_logger::log($keyid, $translationid, $lang, activity_logger::ACTION_DELETE, [], $courseid);
            }
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
     * @param int|null $critical Optional critical flag override for the key.
     * @return int Key ID for the saved translation.
     * @throws \Throwable Propagates lower-level database exceptions for caller handling.
     */
    public static function save_key_with_translation(string $component, string $xkey, string $source, string $lang, string $translation, int $reviewed = 0, int $courseid = 0, string $context = '', ?int $critical = null, string $translationsource = self::SOURCE_MANUAL): int {
        global $DB;
        
        // Reject calls where both source and translation are empty — nothing to persist.
        $source = self::normalize_inline_markup($source);
        $translation = self::normalize_inline_markup($translation);
        if ($source === '' && $translation === '') {
            throw new \coding_exception('save_key_with_translation: source and translation cannot both be empty');
        }
        if ($source === '') {
            $source = $translation;
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            $xkey = self::resolve_preferred_xkey($component, $xkey, $source, $courseid);
            // Create or update the key
            $keyid = self::create_or_update_key($component, $xkey, $source, $critical);
            
            // Save the translation (propagate reviewed flag and originating source).
            self::save_translation($keyid, $lang, $translation, 1, $reviewed, $translationsource, $courseid);

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
            
            $transaction->allow_commit();

            // Invalidate cache and bump bundle version after the transaction commits
            // so a rollback cannot leave the cache out of sync with the DB.
            try {
                self::invalidate_bundle_cache($lang);
                self::update_bundle_version($lang);
            } catch (\Throwable $cacheerr) {
                // Non-fatal: the cache self-heals on TTL expiry.
                debugging('[local_xlate] Failed to update bundle cache/version after commit: ' . $cacheerr->getMessage(), DEBUG_DEVELOPER);
            }

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

    /**
     * Resolve the preferred xkey for incoming source text.
     *
     * Keeps existing xkeys stable when the same component/source pair already
     * exists inside the current course associations.
     *
     * @param string $component Component identifier.
     * @param string $xkey Incoming xkey generated on the client.
     * @param string $source Source text.
     * @param int $courseid Optional course scope.
     * @return string Preferred xkey to persist.
     */
    private static function resolve_preferred_xkey(string $component, string $xkey, string $source, int $courseid = 0): string {
        global $DB;

        $xkey = trim($xkey);
        if ($xkey === '') {
            return $xkey;
        }

        $existing = self::get_key_by_component_xkey($component, $xkey);
        if ($existing) {
            return $xkey;
        }

        if ($courseid <= 0 || trim($source) === '') {
            return $xkey;
        }

        $needle = self::normalise_source($source);
        if ($needle === '') {
            return $xkey;
        }

        $sql = "SELECT k.id, k.xkey, k.source
                  FROM {local_xlate_key_course} kc
                  JOIN {local_xlate_key} k ON k.id = kc.keyid
                 WHERE kc.courseid = :courseid
                   AND k.component = :component
              ORDER BY k.mtime DESC";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'component' => $component,
        ], 0, 500);

        foreach ($records as $record) {
            $candidate = self::normalise_source((string)($record->source ?? ''));
            if ($candidate !== '' && $candidate === $needle) {
                return (string)$record->xkey;
            }
        }

        return $xkey;
    }

    private static function normalize_inline_markup(string $value): string {
        if ($value === '') {
            return $value;
        }
        $value = self::normalize_utf8_text($value);
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('#<\\/([a-z0-9]+)>#i', '</$1>', $decoded);
        $decoded = preg_replace('#<([a-z0-9]+)\\/>#i', '<$1/>', $decoded);
        $decoded = preg_replace('#\\(/|/\\)#', '/', $decoded);
        $decoded = str_replace(['\"', '\\'], ['"', '\\'], $decoded);
        return $decoded;
    }

    /**
     * Best-effort UTF-8 normalization for user-supplied text.
     *
     * @param string $value Input text.
     * @return string UTF-8 safe string.
     */
    private static function normalize_utf8_text(string $value): string {
        if ($value === '') {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1250, ISO-8859-2, ISO-8859-1');
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }

        if (function_exists('iconv')) {
            $fallback = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($fallback) && $fallback !== '') {
                return $fallback;
            }
        }

        return $value;
    }
    
    /**
     * Compute a deterministic bundle version hash for a language.
     *
     * @deprecated Not monotonic: derived from MAX(mtime), so deleting the
     * newest translation lowers the input and REVERTS the version to a value
     * browsers may already have cached in localStorage — which then serves
     * the stale bundle indefinitely. Same-second edits also collide. Kept
     * only for backward compatibility; update_bundle_version() no longer
     * uses it. Do not reintroduce as the version source.
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
     * The version is a MONOTONIC chained hash: each bump hashes the previous
     * version together with the current time, so the value always changes on
     * every bump and can never revert to one a browser has already cached
     * (unlike the old MAX(mtime)-derived hash — see generate_version_hash()).
     * Chaining also guarantees distinct values for same-second bumps.
     *
     * @param string $lang Language code being refreshed.
     * @return string Newly computed version hash.
     */
    public static function update_bundle_version(string $lang): string {
        global $DB;

        $now = time();

        $existing = $DB->get_record('local_xlate_bundle', ['lang' => $lang]);

        // Chain on the previous version so the new value is always novel.
        $previous = $existing ? (string)$existing->version : '';
        $version = sha1($lang . ':' . $previous . ':' . $now);

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
