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
 * Delete specific translation rows so they are translated again.
 *
 * Takes explicit keyid:lang pairs (e.g. from cli/scan_commentary.php). Dry run
 * by default; --execute deletes through api::delete_translation(), which bumps
 * the bundle version, invalidates the cache and writes the activity log. The
 * scheduled autotranslate task then re-translates the keys. Reviewed rows lose
 * their reviewed status (the replacement is an unreviewed AI translation).
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/delete_translations.php --pairs=2992:ru,6241:bg [--execute]
 *
 * @package    local_xlate
 * @category   cli
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'pairs' => '',
    'execute' => false,
    'help' => false,
], [
    'p' => 'pairs',
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if ($options['help'] || trim((string)$options['pairs']) === '') {
    $help = "Delete specific translation rows so they are translated again.\n\n" .
        "Options:\n" .
        "--pairs, -p   Comma-separated keyid:lang pairs, e.g. 2992:ru,6241:bg (required)\n" .
        "--execute     Actually delete (default is a dry run)\n" .
        "--help, -h    Show this help\n\n" .
        "Example:\n" .
        "sudo -u www-data php local/xlate/cli/delete_translations.php --pairs=2992:ru,2999:ru\n" .
        "sudo -u www-data php local/xlate/cli/delete_translations.php --pairs=2992:ru,2999:ru --execute\n";
    cli_writeln($help);
    exit(0);
}

global $DB;

$pairs = [];
foreach (explode(',', (string)$options['pairs']) as $raw) {
    $raw = trim($raw);
    if ($raw === '') {
        continue;
    }
    if (!preg_match('/^(\d+):([a-z]{2,3}(?:_[a-z0-9]+)?)$/i', $raw, $m)) {
        cli_error("Invalid pair '{$raw}'. Expected keyid:lang, e.g. 2992:ru");
    }
    $pairs[] = [(int)$m[1], strtolower($m[2])];
}

$execute = !empty($options['execute']);
cli_heading($execute ? 'Deleting translations' : 'Dry run (no changes) — add --execute to delete');

$snippet = static function (string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim(strip_tags($s)));
    return \core_text::strlen($s) > 80 ? \core_text::substr($s, 0, 77) . '...' : $s;
};

$found = 0;
$deleted = 0;
foreach ($pairs as [$keyid, $lang]) {
    $tr = $DB->get_record('local_xlate_tr', ['keyid' => $keyid, 'lang' => $lang], 'id, text, reviewed', IGNORE_MISSING);
    if (!$tr) {
        echo "keyid={$keyid} lang={$lang}: no translation row — skipped\n";
        continue;
    }
    $found++;
    $source = (string)$DB->get_field('local_xlate_key', 'source', ['id' => $keyid]);
    echo "keyid={$keyid} lang={$lang} reviewed={$tr->reviewed}\n";
    echo "  src: " . $snippet($source) . "\n";
    echo "  out: " . $snippet((string)$tr->text) . "\n";
    if ($execute) {
        if (\local_xlate\local\api::delete_translation($keyid, $lang)) {
            $deleted++;
            echo "  DELETED\n";
        } else {
            echo "  NOT deleted (already gone?)\n";
        }
    }
}

echo "\n" . ($execute ? "Deleted {$deleted} of {$found} rows found." : "{$found} rows would be deleted.") . "\n";
