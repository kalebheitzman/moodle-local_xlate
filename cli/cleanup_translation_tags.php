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
 * CLI utility to normalise malformed HTML tags in Local Xlate translations.
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'lang' => null,
    'limit' => 0,
    'dry-run' => false,
], [
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    $unrecognized = implode(PHP_EOL . '  ', $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help'])) {
    $help = <<<EOF
Cleanup malformed translation tags.

Options:
--lang=xx        Limit cleanup to a specific language code.
--limit=N        Stop after updating N translations (default: unlimited).
--dry-run        Scan and report without updating the database.
-h, --help       Show this help.

Example:
sudo -u www-data php local/xlate/cli/cleanup_translation_tags.php --limit=100
EOF;

    cli_writeln($help);
    exit(0);
}

$lang = $options['lang'] ?? null;
$limit = (int)($options['limit'] ?? 0);
$maxupdates = $limit > 0 ? $limit : null;
$dryrun = !empty($options['dry-run']);

$stats = \local_xlate\local\translation_cleanup::cleanup_translations($dryrun, $maxupdates, $lang);

cli_writeln('Checked translations : ' . $stats['checked']);
cli_writeln('Cleaned translations  : ' . $stats['updated'] . ($dryrun ? ' (dry-run)' : ''));
if (!empty($stats['limitreached'])) {
    cli_writeln('Limit reached. Re-run to continue.');
}
