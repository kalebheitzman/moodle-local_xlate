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
 * Dry run: recompute translation key hashes from stored source text.
 *
 * The new algorithm is simply simpleHash(trim(source)) — no HTML stripping,
 * no structural DOM context. Same source text anywhere on the site = same key.
 *
 * This script makes NO changes. Run it to understand the scope before
 * executing the actual migration.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/rehash_keys_dryrun.php
 *   sudo -u www-data php local/xlate/cli/rehash_keys_dryrun.php --verbose
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['help' => false, 'verbose' => false],
    ['h' => 'help',   'v' => 'verbose']
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n", $unrecognized)));
}

if ($options['help']) {
    cli_writeln("Dry run: recompute translation key hashes from source text.");
    cli_writeln("");
    cli_writeln("Options:");
    cli_writeln("  --verbose   Show per-key detail for all changed keys");
    cli_writeln("  -h, --help  Show this help");
    exit(0);
}

$verbose = !empty($options['verbose']);

// ---------------------------------------------------------------------------
// Hash function — exact PHP port of translator.js simpleHash().
// Uses FNV-1a on h1 and a Murmur3-inspired mix on h2, both clamped to
// unsigned 32-bit. Must produce byte-for-byte identical output to the JS.
// ---------------------------------------------------------------------------

/**
 * Simulate JavaScript Math.imul: 32-bit C-style integer multiplication.
 * Splits operands into 16-bit halves to avoid 64-bit overflow in PHP.
 */
function imul32(int $a, int $b): int {
    $a  = $a & 0xFFFFFFFF;
    $b  = $b & 0xFFFFFFFF;
    $ah = ($a >> 16) & 0xFFFF;
    $al = $a & 0xFFFF;
    $bh = ($b >> 16) & 0xFFFF;
    $bl = $b & 0xFFFF;
    // Low 32 bits of a*b = al*bl + ((ah*bl + al*bh) & 0xFFFF) << 16
    return (($al * $bl) + (((($ah * $bl + $al * $bh) & 0xFFFF) << 16))) & 0xFFFFFFFF;
}

/**
 * Replicate translator.js simpleHash() exactly.
 * Processes the string as UTF-16 code units (matching JS charCodeAt).
 * Supplementary characters (U+10000+) are decomposed into surrogate pairs.
 */
function xlate_simple_hash(string $str): string {
    $h1 = 2166136261;  // FNV-1a 32-bit offset basis
    $h2 = 0x9e3779b1;  // 2654435761 — golden ratio constant

    $len = mb_strlen($str, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $c = mb_ord(mb_substr($str, $i, 1, 'UTF-8'), 'UTF-8');

        // Supplementary characters (U+10000+) produce two UTF-16 code units in JS.
        if ($c >= 0x10000) {
            $cp   = $c - 0x10000;
            $units = [0xD800 + ($cp >> 10), 0xDC00 + ($cp & 0x3FF)];
        } else {
            $units = [$c];
        }

        foreach ($units as $cu) {
            // FNV-1a step on h1
            $h1 = imul32($h1 ^ $cu, 16777619);

            // Murmur3-inspired mix on h2
            $h2  = ($h2 + $cu) & 0xFFFFFFFF;
            $k   = ($h2 ^ ($h2 >> 16)) & 0xFFFFFFFF;
            $h2  = imul32($k, 2246822507);
            $k   = ($h2 ^ ($h2 >> 13)) & 0xFFFFFFFF;
            $h2  = imul32($k, 3266489909);
            $h2  = ($h2 ^ ($h2 >> 16)) & 0xFFFFFFFF;
        }
    }

    $s = base_convert((string)$h1, 10, 36) . base_convert((string)$h2, 10, 36);
    if (strlen($s) < 12) {
        $s = substr($s . 'qwertyuiopasdfghjklz', 0, 12);
    } elseif (strlen($s) > 12) {
        $s = substr($s, 0, 12);
    }
    return $s;
}

// ---------------------------------------------------------------------------
// Load all keys with their translation language counts.
// ---------------------------------------------------------------------------

cli_writeln("=== Xlate Key Hash Dry Run ===");
cli_writeln("Algorithm: simpleHash(trim(source)) — no HTML stripping");
cli_writeln(str_repeat('-', 60));

$sql = "SELECT k.id, k.xkey, k.source, k.component,
               COUNT(t.id) AS tr_count
          FROM {local_xlate_key} k
     LEFT JOIN {local_xlate_tr} t ON t.keyid = k.id
      GROUP BY k.id, k.xkey, k.source, k.component
      ORDER BY k.id ASC";

$keys = $DB->get_records_sql($sql);
$total = count($keys);

cli_writeln("Total keys in DB: $total");
cli_writeln("");

if ($total === 0) {
    cli_writeln("No keys found. Nothing to do.");
    exit(0);
}

// Build a map of existing xkey → key id for collision detection.
$existing_xkey_to_id = [];
foreach ($keys as $key) {
    $existing_xkey_to_id[$key->xkey] = (int)$key->id;
}

// Pre-load all translation languages per key in a single query to avoid
// N+1 queries during merge conflict detection below.
$key_langs      = [];   // keyid → [lang, ...]
$key_tr_reviewed = [];   // keyid → [lang → bool]
$tr_rs = $DB->get_recordset_sql("SELECT id, keyid, lang, reviewed FROM {local_xlate_tr}");
foreach ($tr_rs as $tr) {
    $key_langs[(int)$tr->keyid][] = $tr->lang;
    $key_tr_reviewed[(int)$tr->keyid][$tr->lang] = (bool)$tr->reviewed;
}
$tr_rs->close();

// Pre-load course association counts per key.
// Simple xkey updates don't touch local_xlate_key_course (keyid doesn't change).
// Merges delete the losing key → CASCADE DELETE removes its course associations.
// Surviving key may need those course associations consolidated (UNIQUE keyid,courseid).
$key_course_counts = [];
$kc_rs = $DB->get_recordset_sql(
    "SELECT keyid, COUNT(id) AS cnt FROM {local_xlate_key_course} GROUP BY keyid"
);
foreach ($kc_rs as $kc) {
    $key_course_counts[(int)$kc->keyid] = (int)$kc->cnt;
}
$kc_rs->close();

// Pre-load activity log counts per key.
// local_xlate_activity has no CASCADE DELETE on keyid — rows become orphaned
// if the losing key is deleted during a merge.
$key_activity_counts = [];
$act_rs = $DB->get_recordset_sql(
    "SELECT keyid, COUNT(id) AS cnt FROM {local_xlate_activity} GROUP BY keyid"
);
foreach ($act_rs as $act) {
    $key_activity_counts[(int)$act->keyid] = (int)$act->cnt;
}
$act_rs->close();

// ---------------------------------------------------------------------------
// Categorise each key.
// ---------------------------------------------------------------------------

$count_unchanged   = 0;
$count_update      = 0;   // xkey changes, no collision with another existing key
$count_merge       = 0;   // new xkey already belongs to a different key → merge needed
$count_empty       = 0;   // source is empty — cannot hash, flag for review

$update_rows = [];  // ['id', 'old_xkey', 'new_xkey', 'source', 'tr_count', 'component']
$merge_rows  = [];  // same + 'target_id', 'conflict_langs'

// Tracks new xkeys claimed during this pass to catch intra-batch collisions:
// two keys that don't currently collide with anything but would collide with
// each other after rehashing (e.g. same source text captured from two DOM locations).
$claimed_new_xkey_to_id = [];

foreach ($keys as $key) {
    $source = trim((string)($key->source ?? ''));

    if ($source === '') {
        $count_empty++;
        continue;
    }

    // Normalise to plain text before hashing — mirrors JS extractPlainText().
    $plain = html_entity_decode(strip_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $new_xkey = xlate_simple_hash($plain);
    if (strlen($new_xkey) !== 12) {
        cli_writeln("  WARNING: hash generation produced unexpected length for key {$key->id} — skipping.");
        continue;
    }

    if ($new_xkey === $key->xkey) {
        $count_unchanged++;
        continue;
    }

    $row = [
        'id'             => (int)$key->id,
        'old_xkey'       => $key->xkey,
        'new_xkey'       => $new_xkey,
        'source'         => $source,
        'tr_count'       => (int)$key->tr_count,
        'component'      => $key->component,
        'course_count'   => $key_course_counts[(int)$key->id] ?? 0,
        'activity_count' => $key_activity_counts[(int)$key->id] ?? 0,
    ];

    // Resolve the target for collision: prefer an existing DB key, then a key
    // already claimed earlier in this same pass (intra-batch collision).
    $collision_target = null;
    if (isset($existing_xkey_to_id[$new_xkey]) && $existing_xkey_to_id[$new_xkey] !== (int)$key->id) {
        $collision_target = $existing_xkey_to_id[$new_xkey];
    } else if (isset($claimed_new_xkey_to_id[$new_xkey]) && $claimed_new_xkey_to_id[$new_xkey] !== (int)$key->id) {
        $collision_target = $claimed_new_xkey_to_id[$new_xkey];
    }

    if ($collision_target !== null) {
        // The new hash is already used by a different key — these two keys
        // have the same source text and would need to be merged.
        $target_id = $collision_target;

        // Find languages that both keys have translations for (conflict check).
        // Uses the pre-loaded map — no extra DB queries.
        $langs_this     = $key_langs[(int)$key->id] ?? [];
        $langs_target   = $key_langs[$target_id] ?? [];
        $conflict_langs = array_values(array_intersect($langs_this, $langs_target));

        // Classify each conflict by reviewed status to determine which translation
        // the migration should keep.
        //   losing_wins  — losing key is reviewed, winning key is not → must keep losing
        //   both         — both reviewed → keep winning (already approved), flag for info
        //   winning_wins — winning reviewed or neither reviewed → keep winning (default)
        $conflict_losing_wins = [];
        $conflict_both        = [];
        foreach ($conflict_langs as $lang) {
            $losing_reviewed  = $key_tr_reviewed[(int)$key->id][$lang] ?? false;
            $winning_reviewed = $key_tr_reviewed[$target_id][$lang]    ?? false;
            if ($losing_reviewed && !$winning_reviewed) {
                $conflict_losing_wins[] = $lang;
            } else if ($losing_reviewed && $winning_reviewed) {
                $conflict_both[] = $lang;
            }
            // winning_reviewed || neither: default keep-winning behaviour is fine.
        }

        $row['target_id']             = $target_id;
        $row['conflict_langs']        = $conflict_langs;
        $row['conflict_losing_wins']  = $conflict_losing_wins;
        $row['conflict_both']         = $conflict_both;
        $row['target_course_count']   = $key_course_counts[$target_id] ?? 0;
        $row['target_activity_count'] = $key_activity_counts[$target_id] ?? 0;
        $count_merge++;
        $merge_rows[] = $row;
    } else {
        // Register this new xkey so later keys with the same source text
        // are detected as intra-batch collisions (merges), not simple updates.
        $claimed_new_xkey_to_id[$new_xkey] = (int)$key->id;
        $count_update++;
        $update_rows[] = $row;
    }
}

// ---------------------------------------------------------------------------
// Summary report.
// ---------------------------------------------------------------------------

cli_writeln("SUMMARY");
cli_writeln(str_repeat('-', 60));
cli_writeln(sprintf("  %-30s %d", "Unchanged (hash already correct):", $count_unchanged));
cli_writeln(sprintf("  %-30s %d", "Simple xkey update:", $count_update));
cli_writeln(sprintf("  %-30s %d", "Require merge (duplicate source):", $count_merge));
cli_writeln(sprintf("  %-30s %d", "Empty source (skipped):", $count_empty));
cli_writeln("");

// Translation rows affected by simple updates.
$tr_rows_updated = array_sum(array_column($update_rows, 'tr_count'));
$tr_rows_merged  = array_sum(array_column($merge_rows,  'tr_count'));
cli_writeln(sprintf("  %-30s %d", "Translation rows affected (update):", $tr_rows_updated));
cli_writeln(sprintf("  %-30s %d", "Translation rows affected (merge):",  $tr_rows_merged));
cli_writeln("");

// Course associations lost via CASCADE DELETE when losing keys are deleted in merges.
// Simple xkey updates keep the same keyid — local_xlate_key_course is unaffected.
$kc_cascade_total = array_sum(array_column($merge_rows, 'course_count'));
cli_writeln(sprintf("  %-30s %d", "Course assoc. rows cascade-deleted:", $kc_cascade_total));
if ($kc_cascade_total > 0) {
    cli_writeln("  (Surviving key may need these consolidated; UNIQUE keyid,courseid applies.)");
}
cli_writeln("");

// Activity rows that would be orphaned — local_xlate_activity has no CASCADE DELETE.
$act_orphan_total = array_sum(array_column($merge_rows, 'activity_count'));
cli_writeln(sprintf("  %-30s %d", "Activity rows orphaned (merge):", $act_orphan_total));
if ($act_orphan_total > 0) {
    cli_writeln("  (Real migration must re-point or delete orphaned activity rows.)");
}
cli_writeln("");

// Bundle versions that must be rebuilt after any migration.
$bundles = $DB->get_records('local_xlate_bundle', null, 'lang ASC', 'id, lang, version, mtime');
cli_writeln(sprintf("  %-30s %d", "Bundle versions to rebuild:", count($bundles)));
cli_writeln("");

// Merge conflict detail.
$total_conflicts      = 0;
$total_losing_wins    = 0;
$total_both_reviewed  = 0;
foreach ($merge_rows as $row) {
    $total_conflicts     += count($row['conflict_langs']);
    $total_losing_wins   += count($row['conflict_losing_wins']);
    $total_both_reviewed += count($row['conflict_both']);
}
if ($count_merge > 0) {
    cli_writeln(sprintf("  %-30s %d", "Merge conflict languages total:", $total_conflicts));
    cli_writeln(sprintf("  %-30s %d", "  Reviewed lost (needs swap):", $total_losing_wins));
    cli_writeln(sprintf("  %-30s %d", "  Both reviewed (keep winning):", $total_both_reviewed));
    cli_writeln("  (A conflict means both keys have a translation for the same lang.");
    cli_writeln("   'Reviewed lost' = losing key is reviewed, winning is not — migration");
    cli_writeln("   must keep the losing key's translation instead of the winning key's.)");
    cli_writeln("");
}

// ---------------------------------------------------------------------------
// Detail sections.
// ---------------------------------------------------------------------------

if (!empty($update_rows)) {
    $shown = $verbose ? $update_rows : array_slice($update_rows, 0, 10);
    $label = $verbose ? "ALL SIMPLE UPDATES" : "SAMPLE SIMPLE UPDATES (first 10)";
    cli_writeln($label);
    cli_writeln(str_repeat('-', 60));
    foreach ($shown as $row) {
        cli_writeln(sprintf(
            "  key %d | %s → %s | %d translation(s) | %s",
            $row['id'], $row['old_xkey'], $row['new_xkey'],
            $row['tr_count'], $row['component']
        ));
        cli_writeln("    " . mb_substr($row['source'], 0, 100));
    }
    if (!$verbose && count($update_rows) > 10) {
        cli_writeln("  ... " . (count($update_rows) - 10) . " more (use --verbose to see all)");
    }
    cli_writeln("");
}

if (!empty($merge_rows)) {
    $shown = $verbose ? $merge_rows : array_slice($merge_rows, 0, 10);
    $label = $verbose ? "ALL MERGES" : "SAMPLE MERGES (first 10)";
    cli_writeln($label);
    cli_writeln(str_repeat('-', 60));
    foreach ($shown as $row) {
        if (empty($row['conflict_langs'])) {
            $conflicts = 'no conflicts';
        } else {
            $conflicts = 'CONFLICTS: ' . implode(', ', $row['conflict_langs']);
        }
        if (!empty($row['conflict_losing_wins'])) {
            $conflicts .= ' | REVIEWED AT RISK: ' . implode(', ', $row['conflict_losing_wins']);
        }
        $kc_note  = $row['course_count']   > 0 ? " | {$row['course_count']} course assoc."   : '';
        $act_note = $row['activity_count'] > 0 ? " | {$row['activity_count']} activity rows" : '';
        cli_writeln(sprintf(
            "  key %d → merges into key %d | %s | %d translation(s)%s%s | %s",
            $row['id'], $row['target_id'], $conflicts,
            $row['tr_count'], $kc_note, $act_note, $row['component']
        ));
        cli_writeln("    " . mb_substr($row['source'], 0, 100));
    }
    if (!$verbose && count($merge_rows) > 10) {
        cli_writeln("  ... " . (count($merge_rows) - 10) . " more (use --verbose to see all)");
    }
    cli_writeln("");
}

if ($count_empty > 0) {
    cli_writeln("EMPTY SOURCE KEYS (cannot be rehashed — review manually)");
    cli_writeln(str_repeat('-', 60));
    $empty_keys = $DB->get_records_select(
        'local_xlate_key',
        $DB->sql_isempty('local_xlate_key', 'source', false, false) . ' OR source IS NULL',
        [],
        'id ASC',
        'id, xkey, component',
        0, 20
    );
    foreach ($empty_keys as $ek) {
        cli_writeln("  key {$ek->id} | {$ek->xkey} | {$ek->component}");
    }
    cli_writeln("");
}

if (!empty($bundles)) {
    cli_writeln("BUNDLE VERSIONS TO REBUILD");
    cli_writeln(str_repeat('-', 60));
    foreach ($bundles as $bundle) {
        cli_writeln(sprintf(
            "  lang %-10s  version %s",
            $bundle->lang, $bundle->version
        ));
    }
    cli_writeln("  (All listed bundles must have their version hash regenerated and");
    cli_writeln("   the Moodle application cache invalidated after migration.)");
    cli_writeln("");
}

cli_writeln(str_repeat('-', 60));
cli_writeln("Dry run complete. No changes were made.");
cli_writeln("To execute: sudo -u www-data php local/xlate/cli/rehash_keys.php");
