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
 * CLI dry-run helper for the autotranslate scheduled task.
 *
 * Prints the source language, target languages, and outstanding translation counts
 * that would be processed by {@see \local_xlate\task\autotranslate_missing_task}
 * without contacting the translation backend or writing any data.
 *
 * Usage examples:
 *   sudo -u www-data php local/xlate/cli/autotranslate_dryrun.php
 *   sudo -u www-data php local/xlate/cli/autotranslate_dryrun.php --courseid=901
 *   sudo -u www-data php local/xlate/cli/autotranslate_dryrun.php --limit=10 --showmissing
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'courseid' => 0,
    'limit' => 0,
    'showmissing' => false,
    'help' => false,
], [
    'c' => 'courseid',
    'l' => 'limit',
    'm' => 'showmissing',
    'h' => 'help',
]);

if (!empty($options['help'])) {
    $help = <<<EOF
Dry-run the Local Xlate autotranslate_missing scheduled task.

Options:
    --courseid=ID   Only inspect a single course id.
    --limit=N       Limit the number of courses inspected (useful with many records).
    --showmissing   Additionally list the first few keys missing per target language.
    -h, --help      Show this help.

EOF;
    cli_writeln($help);
    exit(0);
}

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

$courseids = [];
if (!empty($options['courseid'])) {
    $courseids = [
        (int)$options['courseid']
    ];
} else {
    $courseids = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {local_xlate_key_course} ORDER BY courseid ASC');
}

if (empty($courseids)) {
    cli_writeln('No courses with Local Xlate key associations were found.');
    exit(0);
}

$limit = (int)$options['limit'];
if ($limit > 0) {
    $courseids = array_slice($courseids, 0, $limit);
}

$showmissing = !empty($options['showmissing']);
$maxsamples = 5;

cli_writeln('Local Xlate autotranslate dry run');
cli_writeln(str_repeat('-', 40));

foreach ($courseids as $courseid) {
    $courseid = (int)$courseid;
    if ($courseid <= 0) {
        continue;
    }

    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', IGNORE_MISSING);
    $coursename = $course ? format_string($course->fullname, true, ['context' => context_course::instance($courseid, IGNORE_MISSING)]) : 'Unknown course';

    $config = \local_xlate\customfield_helper::get_course_config($courseid);
    if ($config === null) {
        cli_writeln("Course {$courseid}: {$coursename}");
        cli_writeln('  Skipped: no source language configured.');
        cli_writeln('');
        continue;
    }

    $sourcelang = $config['source'];
    $targetlangs = array_filter($config['targets'], static function(string $code) use ($sourcelang) {
        return $code !== '' && $code !== $sourcelang;
    });

    cli_writeln("Course {$courseid}: {$coursename}");
    cli_writeln("  Source: {$sourcelang}");

    if (empty($targetlangs)) {
        cli_writeln('  Skipped: no valid target languages.');
        cli_writeln('');
        continue;
    }

    foreach ($targetlangs as $targetlang) {
        $missingcount = get_missing_translation_count($courseid, $targetlang);
        cli_writeln("  Target {$targetlang}: {$missingcount} keys pending");

        if ($showmissing && $missingcount > 0) {
            $sample = get_missing_translation_sample($courseid, $targetlang, $maxsamples);
            foreach ($sample as $row) {
                cli_writeln('    - ' . $row->component . ':' . $row->xkey . ' => ' . shorten_text($row->source, 60));
            }
        }
    }

    cli_writeln('');
}

/**
 * Count keys lacking translations for the given course/target combination.
 *
 * @param int $courseid
 * @param string $targetlang
 * @return int
 */
function get_missing_translation_count(int $courseid, string $targetlang): int {
    global $DB;

    $sql = "SELECT COUNT(1)
              FROM {local_xlate_key_course} kc
              JOIN {local_xlate_key} k ON k.id = kc.keyid
         LEFT JOIN {local_xlate_tr} t ON t.keyid = k.id AND t.lang = :targetlang
             WHERE kc.courseid = :courseid AND (t.id IS NULL OR t.status <> 1)";

    return (int)$DB->get_field_sql($sql, ['courseid' => $courseid, 'targetlang' => $targetlang]);
}

/**
 * Fetch a small sample of missing keys for display.
 *
 * @param int $courseid
 * @param string $targetlang
 * @param int $limit
 * @return array<int,\stdClass>
 */
function get_missing_translation_sample(int $courseid, string $targetlang, int $limit = 5): array {
    global $DB;

    // get_records_sql() requires the first selected column to be unique so rows can be keyed safely.
    $sql = "SELECT k.id AS recordid, k.component, k.xkey, k.source
              FROM {local_xlate_key_course} kc
              JOIN {local_xlate_key} k ON k.id = kc.keyid
         LEFT JOIN {local_xlate_tr} t ON t.keyid = k.id AND t.lang = :targetlang
             WHERE kc.courseid = :courseid AND (t.id IS NULL OR t.status <> 1)
          ORDER BY k.id ASC";

    return $DB->get_records_sql($sql, ['courseid' => $courseid, 'targetlang' => $targetlang], 0, $limit);
}
