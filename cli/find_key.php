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
    'keyid' => null,
    'help' => false,
], [
    'k' => 'key',
    'i' => 'keyid',
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if ($options['help'] || (empty($options['key']) && empty($options['keyid']))) {
    $help = "Query the local_xlate_key table for a specific key.\n\n" .
        "Options:\n" .
        "--key, -k     Structural hash to inspect\n" .
        "--keyid, -i   Numeric key id to inspect (alternative to --key)\n" .
        "--help, -h    Show this help\n\n" .
        "Example:\n" .
        "sudo -u www-data php local/xlate/cli/find_key.php --key=abc123xyz\n" .
        "sudo -u www-data php local/xlate/cli/find_key.php --keyid=5351\n";
    cli_writeln($help);
    exit(0);
}

global $DB;

if (!empty($options['keyid'])) {
    $record = $DB->get_record('local_xlate_key', ['id' => (int)$options['keyid']], '*', IGNORE_MISSING);
    if (!$record) {
        cli_writeln('No record found for keyid: ' . (int)$options['keyid']);
        exit(0);
    }
} else {
    $key = trim($options['key']);
    if ($key === '') {
        cli_error('Key value cannot be empty.');
    }
    $record = $DB->get_record('local_xlate_key', ['xkey' => $key], '*', IGNORE_MISSING);
    if (!$record) {
        cli_writeln('No record found for key: ' . $key);
        exit(0);
    }
}

cli_writeln('--- Key Record ---');
cli_writeln('ID:        ' . $record->id);
cli_writeln('XKey:      ' . $record->xkey);
cli_writeln('Component: ' . $record->component);
cli_writeln('Critical:  ' . ($record->critical ? 'yes' : 'no'));
cli_writeln('Source:    ' . $record->source);
cli_writeln('Created:   ' . ($record->ctime ? userdate($record->ctime) : ''));
cli_writeln('Modified:  ' . ($record->mtime ? userdate($record->mtime) : ''));

$associations = $DB->get_records('local_xlate_key_course', ['keyid' => $record->id]);
cli_writeln("\n--- Course Associations (" . count($associations) . ") ---");
if (empty($associations)) {
    cli_writeln('(none — this key has no course associations)');
} else {
    foreach ($associations as $assoc) {
        $course = $DB->get_record('course', ['id' => $assoc->courseid], 'id,shortname,fullname', IGNORE_MISSING);
        $label = $course ? "  courseid={$assoc->courseid}  [{$course->shortname}] {$course->fullname}" : "  courseid={$assoc->courseid}  (course not found)";
        cli_writeln($label);
    }
}

$translations = $DB->get_records('local_xlate_tr', ['keyid' => $record->id]);
if (empty($translations)) {
    cli_writeln("\nNo translations found for this key.");
    exit(0);
}

cli_writeln("\n--- Translations (" . count($translations) . ") ---");
foreach ($translations as $tr) {
    cli_writeln('Lang:     ' . $tr->lang);
    cli_writeln('Status:   ' . $tr->status);
    cli_writeln('Reviewed: ' . $tr->reviewed);
    cli_writeln('Text:     ' . $tr->text);
    cli_writeln('mtime:    ' . ($tr->mtime ? userdate($tr->mtime) : ''));
    cli_writeln('-------------------------');
}
