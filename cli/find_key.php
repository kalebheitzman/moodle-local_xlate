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
 * Helper CLI to inspect a single Xlate key by its structural hash.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/find_key.php --key=abc123
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'key' => null,
    'help' => false,
], [
    'k' => 'key',
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if ($options['help'] || empty($options['key'])) {
    $help = "Query the local_xlate_key table for a specific xkey.\n\n" .
        "Options:\n" .
        "--key, -k     Structural hash to inspect (required)\n" .
        "--help, -h    Show this help\n\n" .
        "Example:\n" .
        "sudo -u www-data php local/xlate/cli/find_key.php --key=abc123xyz\n";
    cli_writeln($help);
    exit(0);
}

$key = trim($options['key']);
if ($key === '') {
    cli_error('Key value cannot be empty.');
}

global $DB;

$record = $DB->get_record('local_xlate_key', ['xkey' => $key], '*', IGNORE_MISSING);
if (!$record) {
    cli_writeln('No record found for key: ' . $key);
    exit(0);
}

cli_writeln('--- Key Record ---');
cli_writeln('ID:        ' . $record->id);
cli_writeln('Component: ' . $record->component);
cli_writeln('Source:    ' . $record->source);
cli_writeln('Created:   ' . ($record->ctime ? userdate($record->ctime) : '')); 
cli_writeln('Modified:  ' . ($record->mtime ? userdate($record->mtime) : ''));

$translations = $DB->get_records('local_xlate_tr', ['keyid' => $record->id]);
if (empty($translations)) {
    cli_writeln('No translations found for this key.');
    exit(0);
}

cli_writeln("\n--- Translations ---");
foreach ($translations as $tr) {
    cli_writeln('Lang:   ' . $tr->lang);
    cli_writeln('Status: ' . $tr->status);
    cli_writeln('Text:   ' . $tr->text);
    cli_writeln('Reviewed: ' . $tr->reviewed);
    cli_writeln('mtime: ' . ($tr->mtime ? userdate($tr->mtime) : ''));
    cli_writeln('-------------------------');
}
