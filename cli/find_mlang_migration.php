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
 * Read-only search of the MLang migration provenance log.
 *
 * Finds rows in local_xlate_mlang_migration whose original (pre-migration)
 * content contains a search string, and shows where they came from plus the
 * old and new values. Use it to see what a field looked like before the
 * destructive mlang cleanup, or to diagnose harvested translations.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/find_mlang_migration.php --search="PERSONAL INFORMATION" [--full] [--limit=10]
 *   sudo -u www-data php local/xlate/cli/find_mlang_migration.php --leftover [--limit=5]
 *
 * @package    local_xlate
 * @category   cli
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'search' => '',
    'limit' => 10,
    'full' => false,
    'leftover' => false,
    'id' => 0,
    'help' => false,
], [
    's' => 'search',
    'l' => 'limit',
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if ($options['help'] || (trim((string)$options['search']) === '' && empty($options['leftover']) && empty($options['id']))) {
    $help = "Search the MLang migration log by original content (read-only).\n\n" .
        "Options:\n" .
        "--search, -s   Text to find in the pre-migration value (required, case-insensitive)\n" .
        "--limit, -l    Maximum rows to show (default: 10)\n" .
        "--full         Print complete old/new values instead of 300-character excerpts\n" .
        "--leftover     Instead of --search: rows whose NEW value still contains a multilang\n" .
        "               span (left behind when wrapped in another span), with a total count\n" .
        "--id           Instead of --search: show one log row by its id\n" .
        "--help, -h     Show this help\n\n" .
        "Example:\n" .
        "sudo -u www-data php local/xlate/cli/find_mlang_migration.php --search=\"PERSONAL INFORMATION\"\n";
    cli_writeln($help);
    exit(0);
}

global $DB;

if (!empty($options['id'])) {
    $select = 'id = :id';
    $params = ['id' => (int)$options['id']];
} else if (!empty($options['leftover'])) {
    $select = $DB->sql_like('new_value', ':ml', false) . ' AND ' . $DB->sql_like('new_value', ':sp', false);
    $params = ['ml' => '%multilang%', 'sp' => '%<span%'];
    echo 'Log rows whose migrated value still contains a multilang span: '
        . $DB->count_records_select('local_xlate_mlang_migration', $select, $params) . "\n";
} else {
    $select = $DB->sql_like('old_value', ':search', false);
    $params = ['search' => '%' . $DB->sql_like_escape((string)$options['search']) . '%'];
}
$rows = $DB->get_records_select('local_xlate_mlang_migration', $select, $params,
    'migrated_at DESC', '*', 0, max(1, (int)$options['limit']));

$show = static function (?string $s) use ($options): string {
    $s = (string)$s;
    if (!empty($options['full']) || \core_text::strlen($s) <= 300) {
        return $s;
    }
    return \core_text::substr($s, 0, 297) . '...';
};

if (empty($rows)) {
    echo "No migration log rows contain that text.\n";
    exit(0);
}

foreach ($rows as $r) {
    cli_heading("log id {$r->id}: {$r->tablename}.{$r->columnname} record {$r->recordid}, " . userdate($r->migrated_at));
    echo "OLD:\n" . $show($r->old_value) . "\n\nNEW:\n" . $show($r->new_value) . "\n";
}

echo "\n" . count($rows) . " row(s). No changes were made.\n";
