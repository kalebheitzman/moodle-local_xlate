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
 * CLI tool to repair or undo local_xlate_key_course associations for a course.
 *
 * NOTE: The normal fix for missing associations is bundle.php backfill — it runs
 * automatically on every page load and is always safe. Use this script only when
 * you need to manually target a specific key or undo damage from a prior run.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/repair_course_associations.php \
 *     --courseid=42 --undo [--dry-run]
 *
 *   sudo -u www-data php local/xlate/cli/repair_course_associations.php \
 *     --courseid=42 --key=abc123 [--enqueue] [--dry-run]
 *
 *   sudo -u www-data php local/xlate/cli/repair_course_associations.php \
 *     --courseid=42 --global-only [--enqueue] [--dry-run]
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'courseid'    => 0,
    'dry-run'     => false,
    'global-only' => false,
    'key'         => '',
    'enqueue'     => false,
    'undo'        => false,
    'force'       => false,
    'help'        => false,
], [
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if ($options['help']) {
    $help = <<<EOF
Repair or undo local_xlate_key_course associations for a course.

IMPORTANT: The recommended fix for missing associations is the automatic
bundle.php backfill — it runs on every page load and is always safe.
Use this script only when you need surgical control.

Modes (pick one):
  --undo           DELETE all associations for the course and cancel pending
                   autotranslation jobs. bundle.php will rebuild correct
                   associations automatically on the next page load.
  --key=XKEY       Associate one specific key with the course.
  --global-only    Associate only keys that have NO associations with any
                   course (site-wide nav/chrome strings). Safe.

Options:
  --courseid=ID    Course ID (required)
  --dry-run        Preview without writing to the database
  --enqueue        After repair, queue an autotranslation job for this course
  --force          Required for --global-only and --key when not using --dry-run,
                   as a confirmation that you have read the dry-run output first.
  --help           Show this help

Examples:
  # UNDO: clean up incorrectly associated keys for course 945
  sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=945 --undo --dry-run
  sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=945 --undo

  # Fix a single specific key
  sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=945 --key=81sjhr1ym7ht --dry-run
  sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=945 --key=81sjhr1ym7ht --force --enqueue

  # Associate global/nav-only keys (zero existing associations)
  sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=945 --global-only --dry-run
  sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=945 --global-only --force

EOF;
    cli_writeln($help);
    exit(0);
}

global $DB;

$courseid   = (int)$options['courseid'];
$dryrun     = !empty($options['dry-run']);
$globalonly = !empty($options['global-only']);
$singlekey  = trim((string)($options['key'] ?? ''));
$enqueue    = !empty($options['enqueue']);
$undo       = !empty($options['undo']);
$force      = !empty($options['force']);

if ($courseid <= 0) {
    cli_error('--courseid is required and must be a positive integer.');
}

// Exactly one mode required.
$modes = array_filter([$undo, $globalonly, $singlekey !== '']);
if (count($modes) === 0) {
    cli_error("You must specify a mode: --undo, --global-only, or --key=XKEY.\n\nRun with --help for usage.");
}
if (count($modes) > 1) {
    cli_error('--undo, --global-only, and --key are mutually exclusive. Pick one.');
}

// Require --force for any live write in non-undo modes (undo has its own confirmation flow).
if (!$undo && !$dryrun && !$force) {
    cli_error("Live writes require --force. Run with --dry-run first to preview, then add --force to apply.");
}

$course = $DB->get_record('course', ['id' => $courseid], 'id,shortname,fullname', IGNORE_MISSING);
if (!$course) {
    cli_error("Course with id={$courseid} not found.");
}

cli_writeln("Course: [{$course->shortname}] {$course->fullname} (id={$courseid})");
cli_writeln('');

// -------------------------------------------------------------------------
// UNDO MODE
// -------------------------------------------------------------------------
if ($undo) {
    $count = $DB->count_records('local_xlate_key_course', ['courseid' => $courseid]);
    cli_writeln("Found {$count} course association(s) for courseid={$courseid}.");

    list($stinsql, $stinparams) = $DB->get_in_or_equal(['pending', 'running'], SQL_PARAMS_NAMED, 'jst');
    $pendingjobs = $DB->get_records_select(
        'local_xlate_course_job',
        "courseid = :courseid AND status {$stinsql}",
        array_merge(['courseid' => $courseid], $stinparams)
    );
    cli_writeln(sprintf('Found %d pending/running autotranslation job(s) for this course.', count($pendingjobs)));

    if ($dryrun) {
        cli_writeln('');
        cli_writeln('DRY RUN — no changes made.');
        cli_writeln("Would DELETE {$count} rows from local_xlate_key_course.");
        cli_writeln(sprintf('Would DELETE %d pending/running job(s) from local_xlate_course_job.', count($pendingjobs)));
        cli_writeln('');
        cli_writeln('After undo, bundle.php will automatically rebuild correct associations');
        cli_writeln('on the next page load by any user. Then run queue_course_job.php to retranslate.');
        exit(0);
    }

    cli_writeln('');
    cli_writeln('Deleting all course associations...');
    $DB->delete_records('local_xlate_key_course', ['courseid' => $courseid]);
    cli_writeln("Deleted {$count} row(s) from local_xlate_key_course.");

    if (!empty($pendingjobs)) {
        $DB->delete_records_select(
            'local_xlate_course_job',
            "courseid = :courseid AND status {$stinsql}",
            array_merge(['courseid' => $courseid], $stinparams)
        );
        cli_writeln(sprintf('Deleted %d pending/running job(s).', count($pendingjobs)));
    }

    cli_writeln('');
    cli_writeln('Done. bundle.php will automatically rebuild correct associations on the next');
    cli_writeln('page load by any user. Once associations are rebuilt, run:');
    cli_writeln("  sudo -u www-data php local/xlate/cli/queue_course_job.php --courseid={$courseid}");
    exit(0);
}

// -------------------------------------------------------------------------
// REPAIR MODES: --key or --global-only
// -------------------------------------------------------------------------
$params     = ['courseid' => $courseid];
$extrawhere = '';

if ($singlekey !== '') {
    $keyrecord = $DB->get_record('local_xlate_key', ['xkey' => $singlekey], 'id,xkey,source,component', IGNORE_MISSING);
    if (!$keyrecord) {
        cli_error("Key '{$singlekey}' not found in local_xlate_key.");
    }
    $extrawhere = ' AND k.id = :keyid';
    $params['keyid'] = $keyrecord->id;
    cli_writeln("Mode: single key ({$singlekey})");
}

if ($globalonly) {
    $extrawhere .= ' AND NOT EXISTS (SELECT 1 FROM {local_xlate_key_course} kc2 WHERE kc2.keyid = k.id)';
    cli_writeln('Mode: global-only (keys with zero existing course associations)');
}

$sql = "SELECT k.id, k.xkey, k.source, k.component,
               (SELECT COUNT(1) FROM {local_xlate_key_course} kc2 WHERE kc2.keyid = k.id) AS assoc_count
          FROM {local_xlate_key} k
         WHERE NOT EXISTS (
               SELECT 1 FROM {local_xlate_key_course} kc
                WHERE kc.keyid = k.id AND kc.courseid = :courseid
         ){$extrawhere}
         ORDER BY k.id";

$missing = $DB->get_records_sql($sql, $params);

if (empty($missing)) {
    cli_writeln('No missing associations found. Nothing to do.');
    exit(0);
}

cli_writeln(sprintf('Found %d key(s) missing a course association:', count($missing)));
cli_writeln('');

foreach ($missing as $k) {
    $sourcesnippet = mb_substr(str_replace(["\n", "\r", "\t"], ' ', $k->source ?? ''), 0, 80);
    $associnfo = ($k->assoc_count == 0) ? 'global' : "in {$k->assoc_count} other course(s)";
    cli_writeln("  [{$k->component}] {$k->xkey}  ({$associnfo})");
    cli_writeln("    {$sourcesnippet}");
}

cli_writeln('');

if ($dryrun) {
    cli_writeln(sprintf('DRY RUN: %d association(s) would be created.', count($missing)));
    cli_writeln('Re-run with --force (and without --dry-run) to apply.');
    exit(0);
}

$created = 0;
$skipped = 0;

foreach ($missing as $k) {
    $exists = $DB->record_exists('local_xlate_key_course', ['keyid' => $k->id, 'courseid' => $courseid]);
    if ($exists) {
        $skipped++;
        continue;
    }
    try {
        $DB->insert_record('local_xlate_key_course', (object)[
            'keyid'    => $k->id,
            'courseid' => $courseid,
        ]);
        $created++;
    } catch (\Throwable $e) {
        cli_writeln('ERROR on ' . $k->xkey . ': ' . $e->getMessage());
        $skipped++;
    }
}

cli_writeln(sprintf('Done: %d association(s) created, %d skipped.', $created, $skipped));

if ($created > 0 && $enqueue) {
    cli_writeln('');
    $result = \local_xlate\local\course_job_manager::enqueue_course_job($courseid, ['onlymissing' => true], 0);
    if (!empty($result['success'])) {
        cli_writeln("Autotranslation job enqueued: jobid={$result['jobid']}");
    } else {
        cli_writeln('WARNING: Could not enqueue job: ' . ($result['error'] ?? 'unknown'));
    }
} else if ($created > 0 && !$enqueue) {
    cli_writeln('');
    cli_writeln('Run autotranslation when ready:');
    cli_writeln("  sudo -u www-data php local/xlate/cli/queue_course_job.php --courseid={$courseid}");
}
