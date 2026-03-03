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
 * Migrate translation key hashes to the plain-text simpleHash algorithm.
 *
 * The new algorithm: simpleHash(html_entity_decode(strip_tags(trim(source))))
 * Tags are stripped and entities decoded so the key is always derived from
 * visible plain text, not raw HTML. Same visible text = same key everywhere.
 *
 * What this script does:
 *   1. Recomputes xkeys for all 27k+ keys using the new algorithm.
 *   2. Keys whose source text is identical collapse into one (merge).
 *   3. When merging, human-reviewed translations are always preserved.
 *   4. Rebuilds all bundle version hashes and clears the Moodle cache.
 *
 * IMPORTANT: Take a database backup before running this script.
 * Run rehash_keys_dryrun.php first to understand the scope.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/rehash_keys.php
 *   sudo -u www-data php local/xlate/cli/rehash_keys.php --verbose
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/rehash_hash_lib.php');

list($options, $unrecognized) = cli_get_params(
    ['help' => false, 'verbose' => false],
    ['h' => 'help', 'v' => 'verbose']
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n", $unrecognized)));
}

if ($options['help']) {
    cli_writeln("Migrate translation key hashes to simpleHash(trim(source)) algorithm.");
    cli_writeln("");
    cli_writeln("IMPORTANT: Take a database backup before running this script.");
    cli_writeln("Run rehash_keys_dryrun.php first to understand scope.");
    cli_writeln("");
    cli_writeln("Options:");
    cli_writeln("  --verbose   Show per-key detail as each key is processed");
    cli_writeln("  -h, --help  Show this help");
    exit(0);
}

$verbose = !empty($options['verbose']);

// ---------------------------------------------------------------------------
// Load all keys and pre-load supporting maps.
// ---------------------------------------------------------------------------

cli_writeln("=== Xlate Key Hash Migration ===");
cli_writeln("Algorithm: simpleHash(html_entity_decode(strip_tags(trim(source))))");

cli_writeln(str_repeat('-', 60));

$sql = "SELECT k.id, k.xkey, k.source, k.component,
               COUNT(t.id) AS tr_count
          FROM {local_xlate_key} k
     LEFT JOIN {local_xlate_tr} t ON t.keyid = k.id
      GROUP BY k.id, k.xkey, k.source, k.component
      ORDER BY k.id ASC";

$keys  = $DB->get_records_sql($sql);
$total = count($keys);

cli_writeln("Total keys in DB: $total");

if ($total === 0) {
    cli_writeln("No keys found. Nothing to do.");
    exit(0);
}

$existing_xkey_to_id = [];
foreach ($keys as $key) {
    $existing_xkey_to_id[$key->xkey] = (int)$key->id;
}

// Pre-load translation languages and reviewed status per key.
$key_langs       = [];   // keyid → [lang, ...]
$key_tr_reviewed = [];   // keyid → [lang → bool]
$tr_rs = $DB->get_recordset_sql("SELECT id, keyid, lang, reviewed FROM {local_xlate_tr}");
foreach ($tr_rs as $tr) {
    $key_langs[(int)$tr->keyid][]             = $tr->lang;
    $key_tr_reviewed[(int)$tr->keyid][$tr->lang] = (bool)$tr->reviewed;
}
$tr_rs->close();

// ---------------------------------------------------------------------------
// Categorise each key (same logic as rehash_keys_dryrun.php).
// ---------------------------------------------------------------------------

$count_unchanged = 0;
$count_update    = 0;
$count_merge     = 0;
$count_empty     = 0;

$update_rows = [];
$merge_rows  = [];

$claimed_new_xkey_to_id = [];

foreach ($keys as $key) {
    $source = trim((string)($key->source ?? ''));

    if ($source === '') {
        $count_empty++;
        continue;
    }

    // Normalise to plain text before hashing — mirrors JS extractPlainText().
    // strip_tags removes inline HTML (<strong> etc.); html_entity_decode converts
    // &amp; → & so entity encoding inconsistencies across browsers don't produce
    // different keys for the same content.
    $plain = html_entity_decode(strip_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $new_xkey = xlate_simple_hash($plain);
    if (strlen($new_xkey) !== 12) {
        cli_writeln("  WARNING: unexpected hash length for key {$key->id} — skipping.");
        continue;
    }

    if ($new_xkey === $key->xkey) {
        $count_unchanged++;
        continue;
    }

    $row = [
        'id'        => (int)$key->id,
        'old_xkey'  => $key->xkey,
        'new_xkey'  => $new_xkey,
        'source'    => $source,
        'component' => $key->component,
    ];

    $collision_target = null;
    if (isset($existing_xkey_to_id[$new_xkey]) && $existing_xkey_to_id[$new_xkey] !== (int)$key->id) {
        $collision_target = $existing_xkey_to_id[$new_xkey];
    } else if (isset($claimed_new_xkey_to_id[$new_xkey]) && $claimed_new_xkey_to_id[$new_xkey] !== (int)$key->id) {
        $collision_target = $claimed_new_xkey_to_id[$new_xkey];
    }

    if ($collision_target !== null) {
        $target_id      = $collision_target;
        $langs_this     = $key_langs[(int)$key->id] ?? [];
        $langs_target   = $key_langs[$target_id] ?? [];
        $conflict_langs = array_values(array_intersect($langs_this, $langs_target));

        // Determine which side to keep for each conflicting language.
        // Losing key's reviewed translation beats an unreviewed winning translation.
        $conflict_losing_wins = [];
        foreach ($conflict_langs as $lang) {
            $losing_reviewed  = $key_tr_reviewed[(int)$key->id][$lang] ?? false;
            $winning_reviewed = $key_tr_reviewed[$target_id][$lang]    ?? false;
            if ($losing_reviewed && !$winning_reviewed) {
                $conflict_losing_wins[] = $lang;
            }
            // Both reviewed or winning reviewed: keep winning (no action needed).
        }

        $row['target_id']            = $target_id;
        $row['conflict_langs']       = $conflict_langs;
        $row['conflict_losing_wins'] = $conflict_losing_wins;
        $count_merge++;
        $merge_rows[] = $row;
    } else {
        $claimed_new_xkey_to_id[$new_xkey] = (int)$key->id;
        $count_update++;
        $update_rows[] = $row;
    }
}

cli_writeln(sprintf("  Unchanged:     %d", $count_unchanged));
cli_writeln(sprintf("  Simple update: %d", $count_update));
cli_writeln(sprintf("  Merge:         %d", $count_merge));
cli_writeln(sprintf("  Empty source:  %d (skipped)", $count_empty));
cli_writeln("");

// ---------------------------------------------------------------------------
// Track affected languages for bundle rebuild at the end.
// ---------------------------------------------------------------------------

$affected_langs = [];

foreach ($update_rows as $row) {
    foreach (($key_langs[$row['id']] ?? []) as $lang) {
        $affected_langs[$lang] = true;
    }
}
foreach ($merge_rows as $row) {
    foreach (($key_langs[$row['id']] ?? []) as $lang) {
        $affected_langs[$lang] = true;
    }
    foreach (($key_langs[$row['target_id']] ?? []) as $lang) {
        $affected_langs[$lang] = true;
    }
}

// ---------------------------------------------------------------------------
// Step 1: Simple xkey updates — two phases to avoid UNIQUE constraint conflicts.
//
// The UNIQUE index is on (component, xkey). Updating A's xkey to Y while B
// still holds xkey Y would violate the constraint. Phase 1a parks every key
// being updated at a temporary unique xkey so phase 1b can assign final values
// without any intermediate conflicts.
// ---------------------------------------------------------------------------

$done_updates = 0;
$batch_size   = 500;

if (!empty($update_rows)) {
    $total_updates = count($update_rows);
    cli_writeln("--- Step 1a: parking $total_updates keys at temporary xkeys...");

    $offset = 0;
    while ($offset < $total_updates) {
        $batch       = array_slice($update_rows, $offset, $batch_size);
        $transaction = $DB->start_delegated_transaction();
        foreach ($batch as $row) {
            // 'tmp' + id is always unique and never a valid 12-char base36 hash.
            $DB->set_field('local_xlate_key', 'xkey', 'tmp' . $row['id'], ['id' => $row['id']]);
        }
        $transaction->allow_commit();
        $offset += $batch_size;
    }

    cli_writeln("--- Step 1b: assigning final xkeys...");

    $offset = 0;
    while ($offset < $total_updates) {
        $batch       = array_slice($update_rows, $offset, $batch_size);
        $transaction = $DB->start_delegated_transaction();
        foreach ($batch as $row) {
            $DB->update_record('local_xlate_key', (object)[
                'id'    => $row['id'],
                'xkey'  => $row['new_xkey'],
                'mtime' => time(),
            ]);
            $done_updates++;
            if ($verbose) {
                cli_writeln(sprintf(
                    "  updated key %d: %s → %s",
                    $row['id'], $row['old_xkey'], $row['new_xkey']
                ));
            }
        }
        $transaction->allow_commit();
        $offset += $batch_size;

        if (!$verbose) {
            cli_writeln(sprintf("  ... %d / %d", $done_updates, $total_updates));
        }
    }
}

// ---------------------------------------------------------------------------
// Step 2: Merges — for each losing key, move its translations and course
// associations to the winning key, then delete the losing key.
// ---------------------------------------------------------------------------

$done_merges = 0;
$errors      = 0;

if (!empty($merge_rows)) {
    $total_merges = count($merge_rows);
    cli_writeln("--- Step 2: processing $total_merges merges...");

    foreach ($merge_rows as $row) {
        $losing_id  = $row['id'];
        $winning_id = $row['target_id'];

        try {
            $transaction = $DB->start_delegated_transaction();

            // Live-query winning key's current translations. We cannot rely on the
            // pre-loaded $key_langs map here because earlier merges in this loop
            // may have already moved translations onto this winning key, making
            // the pre-loaded data stale. Stale data caused UNIQUE constraint
            // violations in the original version of this code.
            $winning_langs = [];
            $winning_trs   = $DB->get_records('local_xlate_tr', ['keyid' => $winning_id],
                                              '', 'lang, text, reviewed, id');
            foreach ($winning_trs as $wtr) {
                $winning_langs[$wtr->lang] = $wtr;
            }

            // Process each translation on the losing key individually.
            $losing_trs = $DB->get_records('local_xlate_tr', ['keyid' => $losing_id],
                                           '', 'id, lang, text, reviewed');
            foreach ($losing_trs as $ltr) {
                $lang = $ltr->lang;
                if (isset($winning_langs[$lang])) {
                    // Conflict: winning key already has this language.
                    // If losing is reviewed and winning is not, overwrite winning.
                    if ((bool)$ltr->reviewed && !(bool)$winning_langs[$lang]->reviewed) {
                        $DB->execute(
                            "UPDATE {local_xlate_tr} SET text = ?, reviewed = 1, mtime = ? WHERE keyid = ? AND lang = ?",
                            [$ltr->text, time(), $winning_id, $lang]
                        );
                    }
                    // Discard the losing translation either way.
                    $DB->delete_records('local_xlate_tr', ['id' => $ltr->id]);
                } else {
                    // No conflict: move translation to winning key.
                    $DB->set_field('local_xlate_tr', 'keyid', $winning_id, ['id' => $ltr->id]);
                }
            }

            // 2d. Migrate course associations the winning key doesn't already have.
            //     The CASCADE DELETE on local_xlate_key.id handles the rest when
            //     the losing key is deleted in step 2f.
            $losing_kcs = $DB->get_records('local_xlate_key_course', ['keyid' => $losing_id]);
            foreach ($losing_kcs as $kc) {
                if (!$DB->record_exists('local_xlate_key_course', ['keyid' => $winning_id, 'courseid' => $kc->courseid])) {
                    $DB->insert_record('local_xlate_key_course', [
                        'keyid'    => $winning_id,
                        'courseid' => $kc->courseid,
                        'context'  => $kc->context,
                        'mtime'    => $kc->mtime,
                    ]);
                }
            }

            // 2e. Re-point activity log rows to the winning key.
            //     (local_xlate_activity has no CASCADE DELETE on keyid.)
            $DB->execute(
                "UPDATE {local_xlate_activity} SET keyid = ? WHERE keyid = ?",
                [$winning_id, $losing_id]
            );

            // 2f. Delete the losing key. CASCADE removes its key_course rows.
            $DB->delete_records('local_xlate_key', ['id' => $losing_id]);

            $transaction->allow_commit();

            $done_merges++;

            if ($verbose) {
                cli_writeln(sprintf(
                    "  merged key %d → key %d | %s",
                    $losing_id, $winning_id, mb_substr($row['source'], 0, 80)
                ));
            } else if ($done_merges % 500 === 0) {
                cli_writeln(sprintf("  ... %d / %d", $done_merges, $total_merges));
            }

        } catch (Exception $e) {
            $errors++;
            cli_writeln(sprintf(
                "  ERROR merging key %d → key %d: %s",
                $losing_id, $winning_id, $e->getMessage()
            ));
        }
    }

    cli_writeln(sprintf("  ... %d / %d", $done_merges, $total_merges));
}

// ---------------------------------------------------------------------------
// Step 3: Rebuild bundle version hashes and invalidate Moodle cache.
// ---------------------------------------------------------------------------

if (!empty($affected_langs)) {
    $lang_count = count($affected_langs);
    cli_writeln("--- Step 3: rebuilding $lang_count bundle version(s)...");
    foreach (array_keys($affected_langs) as $lang) {
        \local_xlate\local\api::update_bundle_version($lang);
        \local_xlate\local\api::invalidate_bundle_cache($lang);
        if ($verbose) {
            cli_writeln("  rebuilt bundle: $lang");
        }
    }
}

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------

cli_writeln("");
cli_writeln(str_repeat('-', 60));
cli_writeln("Migration complete.");
cli_writeln(sprintf("  Keys updated:    %d / %d", $done_updates, $count_update));
cli_writeln(sprintf("  Keys merged:     %d / %d", $done_merges, $count_merge));
cli_writeln(sprintf("  Bundles rebuilt: %d", count($affected_langs)));
if ($errors > 0) {
    cli_writeln(sprintf("  Errors:          %d  ← review output above", $errors));
}
cli_writeln("");
cli_writeln("Run 'php admin/cli/purge_caches.php' to clear all Moodle caches.");
