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
 * Read-only scan of stored translations for suspected added commentary.
 *
 * Runs backend::detect_commentary() (the check that keeps flagged AI output
 * from being saved) over existing translation rows. Flagged reviewed rows were
 * approved by a person, so they measure the check's false-positive rate;
 * flagged unreviewed rows are candidates for re-review. Makes no changes.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/scan_commentary.php [--lang=ru] [--samples=20] [--sourcelang=en]
 *
 * @package    local_xlate
 * @category   cli
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'lang' => '',
    'samples' => 15,
    'sourcelang' => 'en',
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
    $help = "Scan stored translations for suspected added commentary (read-only).\n\n" .
        "Options:\n" .
        "--lang, -l       Only scan this target language (default: all)\n" .
        "--samples, -s    Flagged rows to print per group (default: 15)\n" .
        "--sourcelang     Source language rows to skip (default: en)\n" .
        "--help, -h       Show this help\n\n" .
        "Example:\n" .
        "sudo -u www-data php local/xlate/cli/scan_commentary.php --lang=ru --samples=30\n";
    cli_writeln($help);
    exit(0);
}

global $DB;

$where = 't.lang <> :sourcelang';
$params = ['sourcelang' => (string)$options['sourcelang']];
if ($options['lang'] !== '') {
    $where .= ' AND t.lang = :lang';
    $params['lang'] = (string)$options['lang'];
}

$rs = $DB->get_recordset_sql(
    "SELECT t.id, t.keyid, t.lang, t.text, t.reviewed, k.xkey, k.source
       FROM {local_xlate_tr} t
       JOIN {local_xlate_key} k ON k.id = t.keyid
      WHERE $where
   ORDER BY t.id ASC", $params
);

$limit = max(0, (int)$options['samples']);
$bylang = [];
$byreason = [0 => [], 1 => []];
$samples = [0 => [], 1 => []];
$scanned = 0;
foreach ($rs as $row) {
    $scanned++;
    $reviewed = (int)$row->reviewed === 1 ? 1 : 0;
    $lang = $row->lang;
    if (!isset($bylang[$lang])) {
        $bylang[$lang] = ['rows' => 0, 0 => 0, 1 => 0];
    }
    $bylang[$lang]['rows']++;

    $reasons = \local_xlate\translation\backend::detect_commentary((string)$row->source, (string)$row->text);
    if (empty($reasons)) {
        continue;
    }
    $bylang[$lang][$reviewed]++;
    foreach ($reasons as $reason) {
        $byreason[$reviewed][$reason] = ($byreason[$reviewed][$reason] ?? 0) + 1;
    }
    if (count($samples[$reviewed]) < $limit) {
        $samples[$reviewed][] = [$row, $reasons];
    }
}
$rs->close();

$snippet = static function (string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim(strip_tags($s)));
    return \core_text::strlen($s) > 110 ? \core_text::substr($s, 0, 107) . '...' : $s;
};

cli_heading('1. Flagged rows by language');
printf("%-8s %-10s %-22s %s\n", 'lang', 'rows', 'flagged unreviewed', 'flagged reviewed (approved by a person)');
ksort($bylang);
foreach ($bylang as $lang => $c) {
    printf("%-8s %-10d %-22d %d\n", $lang, $c['rows'], $c[0], $c[1]);
}
echo "Scanned {$scanned} rows.\n";

cli_heading('2. Reasons');
foreach ([0 => 'unreviewed', 1 => 'reviewed'] as $flag => $label) {
    $parts = [];
    foreach ($byreason[$flag] as $reason => $n) {
        $parts[] = "{$reason}={$n}";
    }
    echo str_pad($label, 12) . (empty($parts) ? 'none' : implode('  ', $parts)) . "\n";
}

foreach ([0 => '3. Samples: flagged UNREVIEWED rows (candidates for re-review)',
          1 => '4. Samples: flagged REVIEWED rows (approved by a person: wrong content, or a false positive)'] as $flag => $title) {
    cli_heading($title);
    if (empty($samples[$flag])) {
        echo "None.\n";
        continue;
    }
    foreach ($samples[$flag] as [$row, $reasons]) {
        printf("keyid=%-7d xkey=%-13s lang=%-4s [%s]\n", $row->keyid, $row->xkey, $row->lang, implode(',', $reasons));
        echo "  src: " . $snippet((string)$row->source) . "\n";
        echo "  out: " . $snippet((string)$row->text) . "\n";
    }
}

echo "\nDone. No changes were made.\n";
