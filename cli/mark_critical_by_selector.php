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
 * Retroactive backfill: mark existing keys as critical based on stored criticalSelector context.
 *
 * After deploying the auto-critical selector feature, keys captured beforehand will not have
 * been evaluated for criticality. This script queries local_xlate_key_course.context for
 * JSON-encoded records containing a criticalSelector field, checks if that selector is in the
 * current default critical selector list, and marks the corresponding key as critical.
 *
 * Keys captured after deployment are evaluated automatically at capture time and do not need
 * to be processed here. This script is purely a one-time retroactive backfill.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/mark_critical_by_selector.php --dry-run
 *   sudo -u www-data php local/xlate/cli/mark_critical_by_selector.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'help'    => false,
    'dry-run' => false,
    'verbose' => false,
], [
    'h' => 'help',
    'v' => 'verbose',
]);

if (!empty($unrecognized)) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n", $unrecognized)));
}

if (!empty($options['help'])) {
    cli_writeln("Retroactive backfill: mark keys as critical based on stored criticalSelector context.");
    cli_writeln("");
    cli_writeln("Options:");
    cli_writeln("  --dry-run   Show what would change without writing to the database.");
    cli_writeln("  --verbose   Show per-key detail for every key evaluated.");
    cli_writeln("  -h, --help  Show this help.");
    cli_writeln("");
    cli_writeln("Example:");
    cli_writeln("  sudo -u www-data php local/xlate/cli/mark_critical_by_selector.php --dry-run");
    cli_writeln("  sudo -u www-data php local/xlate/cli/mark_critical_by_selector.php");
    exit(0);
}

$dryrun  = !empty($options['dry-run']);
$verbose = !empty($options['verbose']);

// ---------------------------------------------------------------------------
// Default critical selectors — must mirror output.php $default_critical_selectors exactly.
// ---------------------------------------------------------------------------
$default_critical_selectors = [
    // Course structure
    '.sectionname',
    '.section-summary .no-overflow',
    '.summary .no-overflow',

    // Activity elements
    '.instancename',
    '.contentwithoutlink',
    '.activity-description',
    '.activity-subtitle',
    '[data-region="activity-card"] .card-title',

    // Page headings
    '.page-header-headings h1',
    '#page-header h1',

    // Quiz / assessment
    '.qtext',
    '.formulation',
    '.answer label',
    '.prompt',
    '.generalfeedback .text_to_html',
    '.outcome .feedback',

    // Book / lesson content headings
    '.book_content h1',
    '.book_content h2',
    '.book_content h3',
];

// Merge in any admin-configured additional selectors.
$extra_critical = get_config('local_xlate', 'critical_selectors');
$critical_selectors = array_unique(array_merge(
    $default_critical_selectors,
    $extra_critical ? preg_split('/\r?\n/', $extra_critical, -1, PREG_SPLIT_NO_EMPTY) : []
));
$critical_selector_set = array_flip($critical_selectors);

cli_writeln("Critical selector list contains " . count($critical_selectors) . " entries.");
if ($dryrun) {
    cli_writeln("DRY RUN — no changes will be written.");
}
cli_writeln("");

// ---------------------------------------------------------------------------
// Query key_course rows that have a JSON context with criticalSelector set.
// ---------------------------------------------------------------------------
$marked        = 0;
$skipped_already = 0;
$skipped_no_match = 0;
$total_evaluated = 0;

// We join key_course with key so we can read and update critical in one pass.
$sql = "SELECT kc.keyid, kc.context, k.critical, k.xkey
          FROM {local_xlate_key_course} kc
          JOIN {local_xlate_key} k ON k.id = kc.keyid
         WHERE " . $DB->sql_like('kc.context', ':pattern', false, false);

$records = $DB->get_records_sql($sql, ['pattern' => '%criticalSelector%']);

cli_writeln("Found " . count($records) . " key_course rows containing a criticalSelector field.");
cli_writeln("");

// Collect affected key IDs to bump bundle versions once at the end.
$affected_keyids = [];

foreach ($records as $rec) {
    $total_evaluated++;

    $ctx = @json_decode($rec->context, true);
    if (!is_array($ctx) || !array_key_exists('criticalSelector', $ctx)) {
        if ($verbose) {
            cli_writeln("  SKIP  keyid={$rec->keyid} xkey={$rec->xkey} — context is not JSON or missing criticalSelector");
        }
        continue;
    }

    $sel = $ctx['criticalSelector'];

    if ($sel === null || !isset($critical_selector_set[$sel])) {
        $skipped_no_match++;
        if ($verbose) {
            cli_writeln("  SKIP  keyid={$rec->keyid} xkey={$rec->xkey} — selector not in critical list: " . var_export($sel, true));
        }
        continue;
    }

    if ((int)$rec->critical === 1) {
        $skipped_already++;
        if ($verbose) {
            cli_writeln("  SKIP  keyid={$rec->keyid} xkey={$rec->xkey} — already critical (selector: {$sel})");
        }
        continue;
    }

    if ($verbose) {
        cli_writeln("  MARK  keyid={$rec->keyid} xkey={$rec->xkey} — matched selector: {$sel}");
    }

    if (!$dryrun) {
        $DB->set_field('local_xlate_key', 'critical', 1, ['id' => $rec->keyid]);
        $affected_keyids[$rec->keyid] = true;
    }

    $marked++;
}

// ---------------------------------------------------------------------------
// Bump bundle versions for all affected languages if any keys were updated.
// ---------------------------------------------------------------------------
if (!$dryrun && !empty($affected_keyids)) {
    // Find all languages that have translations for the affected keys.
    list($in_sql, $in_params) = $DB->get_in_or_equal(array_keys($affected_keyids), SQL_PARAMS_NAMED);
    $langs = $DB->get_fieldset_sql(
        "SELECT DISTINCT lang FROM {local_xlate_tr} WHERE keyid {$in_sql}",
        $in_params
    );

    foreach ($langs as $lang) {
        \local_xlate\local\api::update_bundle_version($lang);
        \local_xlate\local\api::invalidate_bundle_cache($lang);
    }

    if (!empty($langs)) {
        cli_writeln("Bumped bundle versions for: " . implode(', ', $langs));
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
cli_writeln("");
cli_writeln("Summary:");
cli_writeln("  Total key_course rows with criticalSelector: " . count($records));
cli_writeln("  Evaluated:                                  {$total_evaluated}");
cli_writeln("  Already critical (skipped):                 {$skipped_already}");
cli_writeln("  Selector not in list (skipped):             {$skipped_no_match}");
if ($dryrun) {
    cli_writeln("  Would mark as critical:                     {$marked}");
} else {
    cli_writeln("  Marked as critical:                         {$marked}");
}

if ($dryrun) {
    cli_writeln("");
    cli_writeln("Re-run without --dry-run to apply changes.");
}
