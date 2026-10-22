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
 * Recover human translations from the MLang provenance log (audit item A13).
 *
 * Before the 2026-10-22 parser fix (A12), mlang cleanup discarded or mis-paired
 * many human-authored translations. Their original content is still in
 * local_xlate_mlang_migration.old_value. This re-reads every logged original
 * with the fixed parser and adds the translations that are missing.
 *
 * Rules: pairs come from groups of adjacent language blocks only; a pair is
 * skipped when its text still holds {mlang} syntax or the translation is far
 * longer than the source (backend::detect_commentary() length rule). For each
 * (text, language): skip when ANY key with that xkey already has a translation
 * in that language; otherwise add it to the existing key with that xkey, or
 * create a 'core' key when none exists. Never overwrites a translation and
 * never changes course content. Rows are saved reviewed with source 'mlang'
 * through api::save_translation(); the bundle version is bumped and the cache
 * invalidated once per language at the end. Dry run unless --execute.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/reharvest_mlang.php [--lang=ru] [--samples=10] [--existing-only] [--execute]
 *
 * @package    local_xlate
 * @category   cli
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/rehash_hash_lib.php');

list($options, $unrecognized) = cli_get_params([
    'lang' => '',
    'samples' => 8,
    'existing-only' => false,
    'execute' => false,
    'help' => false,
], [
    'l' => 'lang',
    's' => 'samples',
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if ($options['help']) {
    $help = "Recover human translations from the MLang provenance log (dry run unless --execute).\n\n" .
        "Options:\n" .
        "--lang, -l      Only this target language (default: all)\n" .
        "--samples, -s   Sample pairs to print per outcome (default: 8)\n" .
        "--existing-only Only add translations to keys that already exist (captured\n" .
        "                from pages); do not create new 'core' keys\n" .
        "--execute       Write the missing translations\n" .
        "--help, -h      Show this help\n\n" .
        "Example:\n" .
        "sudo -u www-data php local/xlate/cli/reharvest_mlang.php --lang=ru\n" .
        "sudo -u www-data php local/xlate/cli/reharvest_mlang.php --execute\n";
    cli_writeln($help);
    exit(0);
}

global $DB;

$execute = !empty($options['execute']);
$existingonly = !empty($options['existing-only']);
$onlylang = strtolower(trim((string)$options['lang']));
$limit = max(0, (int)$options['samples']);
$sourcelang = get_config('core', 'lang') ?: 'en';
// Only languages installed on this site: legacy content has typos such as
// "cz" for "cs"; those are reported, never guessed.
$installed = array_flip(array_map('strtolower', array_keys(get_string_manager()->get_list_of_translations())));

$snippet = static function (string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim(strip_tags($s)));
    return \core_text::strlen($s) > 70 ? \core_text::substr($s, 0, 67) . '...' : $s;
};

// Pass 1: collect distinct (xkey, lang) pairs from every logged original.
$pairs = [];   // "xkey|lang" => [xkey, lang, source, text, logid].
$c = ['log_rows' => 0, 'groups' => 0, 'pairs_seen' => 0, 'skipped_mlang_syntax' => 0, 'skipped_length' => 0,
    'skipped_bad_lang' => 0, 'skipped_not_installed' => 0, 'distinct_pairs' => 0];
$notinstalled = [];
$rs = $DB->get_recordset_select('local_xlate_mlang_migration', 'tablename <> :b', ['b' => 'block_instances'],
    'id ASC', 'id, old_value');
foreach ($rs as $r) {
    $c['log_rows']++;
    $parsed = \local_xlate\mlang_migration::process_mlang_tags((string)$r->old_value);
    foreach ($parsed['groups'] as $group) {
        $c['groups']++;
        $source = trim((string)$group['source']);
        if ($source === '' || empty($group['translations'])) {
            continue;
        }
        $plain = trim(html_entity_decode(strip_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain === '') {
            continue;
        }
        $xkey = xlate_simple_hash($plain);
        foreach ($group['translations'] as $lang => $text) {
            $lang = strtolower(trim((string)$lang));
            $text = trim((string)$text);
            if ($text === '' || $lang === $sourcelang || ($onlylang !== '' && $lang !== $onlylang)) {
                continue;
            }
            $c['pairs_seen']++;
            if (!preg_match('/^[a-z]{2,3}(?:[_-][a-z0-9]+)?$/', $lang)) {
                $c['skipped_bad_lang']++;
                continue;
            }
            if (!isset($installed[$lang])) {
                $c['skipped_not_installed']++;
                $notinstalled[$lang] = ($notinstalled[$lang] ?? 0) + 1;
                continue;
            }
            if (stripos($source . $text, '{mlang') !== false || strpos($source . $text, "\x01") !== false) {
                $c['skipped_mlang_syntax']++;
                continue;
            }
            if (in_array('length', \local_xlate\translation\backend::detect_commentary($source, $text), true)) {
                $c['skipped_length']++;
                continue;
            }
            $id = $xkey . '|' . $lang;
            if (!isset($pairs[$id])) {
                $pairs[$id] = [$xkey, $lang, $source, $text, (int)$r->id];
            }
        }
    }
}
$rs->close();
$c['distinct_pairs'] = count($pairs);

// Pass 2: classify each pair against existing keys/translations (and write when executing).
$outcomes = ['exists' => 0, 'exists_unreviewed' => 0, 'add_to_existing_key' => 0, 'new_core_key' => 0,
    'new_core_key_deferred' => 0, 'failed' => 0];
$bylang = [];
$samples = ['add_to_existing_key' => [], 'new_core_key' => [], 'exists_unreviewed' => []];
$touchedlangs = [];
$keycache = [];
foreach ($pairs as [$xkey, $lang, $source, $text, $logid]) {
    if (!array_key_exists($xkey, $keycache)) {
        $keycache[$xkey] = array_values($DB->get_records('local_xlate_key', ['xkey' => $xkey], 'id ASC', 'id, component'));
    }
    $keys = $keycache[$xkey];
    $outcome = 'new_core_key';
    if (!empty($keys)) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_map(function($k) { return (int)$k->id; }, $keys), SQL_PARAMS_NAMED);
        $existing = $DB->get_records_select('local_xlate_tr', "keyid $insql AND lang = :lang",
            array_merge($inparams, ['lang' => $lang]), '', 'id, reviewed');
        if (!empty($existing)) {
            $outcomes['exists']++;
            $reviewed = array_filter($existing, function($t) { return (int)$t->reviewed === 1; });
            if (empty($reviewed)) {
                $outcomes['exists_unreviewed']++;
                if (count($samples['exists_unreviewed']) < $limit) {
                    $samples['exists_unreviewed'][] = "log {$logid} {$lang}: " . $snippet($source) . ' => ' . $snippet($text);
                }
            }
            continue;
        }
        $outcome = 'add_to_existing_key';
    }
    if ($outcome === 'new_core_key' && $existingonly) {
        $outcomes['new_core_key_deferred']++;
        continue;
    }
    $outcomes[$outcome]++;
    $bylang[$lang] = ($bylang[$lang] ?? 0) + 1;
    if (count($samples[$outcome]) < $limit) {
        $samples[$outcome][] = "log {$logid} {$lang}: " . $snippet($source) . ' => ' . $snippet($text);
    }
    if (!$execute) {
        continue;
    }
    try {
        $keyid = !empty($keys) ? (int)$keys[0]->id
            : \local_xlate\local\api::create_or_update_key('core', $xkey, $source);
        if ($outcome === 'new_core_key') {
            $keycache[$xkey] = [(object)['id' => $keyid, 'component' => 'core']];
        }
        \local_xlate\local\api::save_translation($keyid, $lang, $text, 1, 1, \local_xlate\local\api::SOURCE_MLANG);
        $touchedlangs[$lang] = true;
    } catch (\Throwable $e) {
        $outcomes['failed']++;
        cli_writeln("FAILED log {$logid} {$lang}: " . $e->getMessage());
    }
}

foreach (array_keys($touchedlangs) as $lang) {
    \local_xlate\local\api::update_bundle_version($lang);
    \local_xlate\local\api::invalidate_bundle_cache($lang);
}

cli_heading($execute ? 'Recovery written' : 'Dry run (no changes) — add --execute to write');
foreach ($c as $k => $v) {
    echo str_pad($k, 24) . $v . "\n";
}
if (!empty($notinstalled)) {
    ksort($notinstalled);
    echo 'Not installed (skipped): ' . implode(' ', array_map(function($l, $n) { return "{$l}={$n}"; },
        array_keys($notinstalled), $notinstalled)) . "\n";
}
echo "\n";
foreach ($outcomes as $k => $v) {
    echo str_pad($k, 24) . $v . ($k === 'exists_unreviewed' ? '   (an AI translation exists; harvested human text NOT used)' : '') . "\n";
}
echo "\nTo write, by language:";
ksort($bylang);
foreach ($bylang as $lang => $n) {
    echo " {$lang}={$n}";
}
echo "\n";
foreach ($samples as $k => $list) {
    cli_heading("Samples: {$k}");
    echo empty($list) ? "none\n" : implode("\n", $list) . "\n";
}
echo $execute ? "\nDone.\n" : "\nDone. No changes were made.\n";
