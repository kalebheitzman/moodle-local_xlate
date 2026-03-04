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
 * CLI utility to find and delete activity-date translation keys.
 *
 * These keys are dynamic strings (e.g. "Closes: Sunday, 4 October 2026, 1:12 PM")
 * captured from .activity-dates elements before the selector was excluded.
 * They should not be in the translation database because the date portion changes.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/delete_activity_date_keys.php
 *   sudo -u www-data php local/xlate/cli/delete_activity_date_keys.php --execute
 *   sudo -u www-data php local/xlate/cli/delete_activity_date_keys.php --pattern='%<strong>Due:</strong>%'
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'help'    => false,
    'execute' => false,
    'pattern' => null,
    'limit'   => 0,
], [
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if (!empty($options['help'])) {
    $help = <<<EOF
Find and delete activity-date translation keys captured from .activity-dates elements.

These are dynamic strings like "<strong>Closes:</strong> Sunday, 4 October 2026, 1:12 PM"
that should never have been captured. Without --execute the script is a dry run.

Options:
  --execute         Actually delete matching keys and their translations.
                    Without this flag the script only reports what would be deleted.
  --pattern=<str>   Use a custom SQL LIKE pattern instead of the built-in list.
                    Example: --pattern='%<strong>Due:</strong>%'
  --limit=N         Stop after processing N matching keys (default: unlimited).
  -h, --help        Show this help.

Example:
  sudo -u www-data php local/xlate/cli/delete_activity_date_keys.php
  sudo -u www-data php local/xlate/cli/delete_activity_date_keys.php --execute
EOF;
    cli_writeln($help);
    exit(0);
}

$execute = !empty($options['execute']);
$limit   = (int)($options['limit'] ?? 0);

// SQL LIKE patterns that match known activity-date source strings.
// Each pattern targets the <strong>Label:</strong> prefix injected by Moodle's
// activity-dates renderer (mod/*/classes/dates.php → output/activity_dates.php).
$defaultPatterns = [
    '%<strong>Closes:</strong>%',
    '%<strong>Opens:</strong>%',
    '%<strong>Due:</strong>%',
    '%<strong>Available from:</strong>%',
    '%<strong>Available until:</strong>%',
    '%<strong>Cutoff:</strong>%',
    '%<strong>Extension:</strong>%',
    '%<strong>Lasts until:</strong>%',
];

if (!empty($options['pattern'])) {
    $patterns = [trim($options['pattern'])];
    mtrace('Using custom pattern: ' . $patterns[0]);
} else {
    $patterns = $defaultPatterns;
    mtrace('Using ' . count($patterns) . ' built-in activity-date patterns.');
}

if (!$execute) {
    mtrace('[DRY RUN] No changes will be made. Pass --execute to delete.');
}
mtrace('');

global $DB;

// Collect matching key IDs across all patterns (deduplicated).
$matchedKeys = [];

// Match by component name (e.g. "region_activity-dates").
$componentSql = "SELECT id, xkey, component, source
                   FROM {local_xlate_key}
                  WHERE " . $DB->sql_like('component', ':comppattern', false);
$rows = $DB->get_records_sql($componentSql, ['comppattern' => '%activity-dates%']);
foreach ($rows as $row) {
    $matchedKeys[$row->id] = $row;
}

// Also match by source text patterns.
foreach ($patterns as $pattern) {
    $sql = "SELECT id, xkey, component, source
              FROM {local_xlate_key}
             WHERE " . $DB->sql_like('source', ':pattern', false);
    $rows = $DB->get_records_sql($sql, ['pattern' => $pattern]);
    foreach ($rows as $row) {
        $matchedKeys[$row->id] = $row;
    }
}

if (empty($matchedKeys)) {
    mtrace('No matching keys found.');
    exit(0);
}

// Apply limit if set.
if ($limit > 0 && count($matchedKeys) > $limit) {
    $matchedKeys = array_slice($matchedKeys, 0, $limit, true);
    mtrace("Limit applied: processing first {$limit} keys only.");
}

mtrace('Matching keys found: ' . count($matchedKeys));
mtrace('');

// Gather all affected languages before deletion (for bundle invalidation).
$keyIds = array_keys($matchedKeys);

// Build a comma-separated placeholder list for the IN clause.
list($inSql, $inParams) = $DB->get_in_or_equal($keyIds);
$affectedLangs = $DB->get_fieldset_sql(
    "SELECT DISTINCT lang FROM {local_xlate_tr} WHERE keyid {$inSql}",
    $inParams
);

// Report what will be / was deleted.
foreach ($matchedKeys as $key) {
    $preview = mb_strlen($key->source) > 120
        ? mb_substr($key->source, 0, 120) . '…'
        : $key->source;
    mtrace("  [{$key->xkey}] {$preview}");
}

mtrace('');
mtrace('Affected languages: ' . (empty($affectedLangs) ? '(none)' : implode(', ', $affectedLangs)));
mtrace('');

if (!$execute) {
    mtrace('[DRY RUN] Done. ' . count($matchedKeys) . ' key(s) and their translations would be removed.');
    mtrace('Re-run with --execute to apply changes.');
    exit(0);
}

// Delete translations, course associations, then keys.
list($inSql, $inParams) = $DB->get_in_or_equal($keyIds);

$DB->delete_records_select('local_xlate_tr',         "keyid {$inSql}", $inParams);
$DB->delete_records_select('local_xlate_key_course', "keyid {$inSql}", $inParams);
$DB->delete_records_select('local_xlate_key',        "id {$inSql}",    $inParams);

mtrace('Deleted ' . count($matchedKeys) . ' key(s) and all associated translations/course links.');

// Invalidate bundle caches for each affected language.
if (!empty($affectedLangs)) {
    foreach ($affectedLangs as $lang) {
        \local_xlate\local\api::invalidate_bundle_cache($lang);
        \local_xlate\local\api::update_bundle_version($lang);
    }
    mtrace('Bundle cache invalidated for: ' . implode(', ', $affectedLangs));
}

mtrace('Done.');
