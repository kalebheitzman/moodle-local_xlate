<?php
define('CLI_SCRIPT', true);
/**
 * Remove source-language activity log entries that represent DOM capture events,
 * not real translator work.
 *
 * When a manager browses pages in the source language, the translator.js script
 * captures DOM text and fires save_key_with_translation() for each element. This
 * logs a translation_create (or translation_update) entry in local_xlate_activity
 * even though no translation work was done.  These entries inflate character
 * counts for payroll when the source language filter failed to suppress them.
 *
 * This script identifies and deletes those entries.  Safe criteria:
 *   - action IN (translation_create, translation_update, translation_autotranslate)
 *   - lang = the source language (--sourcelang, defaults to $CFG->lang)
 *   - By default only courseid = 0 entries are targeted (safest scope).
 *     Pass --allcourses to also remove course-associated source-lang entries.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/cleanup_sourcelang_activity.php
 *   sudo -u www-data php local/xlate/cli/cleanup_sourcelang_activity.php --sourcelang=en
 *   sudo -u www-data php local/xlate/cli/cleanup_sourcelang_activity.php --allcourses
 *   sudo -u www-data php local/xlate/cli/cleanup_sourcelang_activity.php --execute
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$help = <<<EOF
Remove source-language activity log entries caused by DOM capture events being
incorrectly logged as billable translator work.

Options:
    --sourcelang=XX   Source language code to target (default: site language from \$CFG->lang)
    --allcourses      Also remove course-associated entries (default: only courseid=0)
    --execute         Delete the identified rows (default: dry-run only)
    -h, --help        Show this help

Examples:
    # Preview using the site default source language
    sudo -u www-data php local/xlate/cli/cleanup_sourcelang_activity.php

    # Preview with an explicit source language code
    sudo -u www-data php local/xlate/cli/cleanup_sourcelang_activity.php --sourcelang=en

    # Preview including course-associated entries as well
    sudo -u www-data php local/xlate/cli/cleanup_sourcelang_activity.php --allcourses

    # Delete the noise entries
    sudo -u www-data php local/xlate/cli/cleanup_sourcelang_activity.php --execute
EOF;

list($params, $unrecognized) = cli_get_params([
    'sourcelang' => '',
    'allcourses' => false,
    'execute'    => false,
    'help'       => false,
], [
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    cli_error("Unknown options:\n  " . implode("\n  ", $unrecognized));
}

if (!empty($params['help'])) {
    cli_writeln($help);
    exit(0);
}

$execute    = !empty($params['execute']);
$allcourses = !empty($params['allcourses']);
$sourcelang = trim((string)$params['sourcelang']);

if ($sourcelang === '') {
    $sourcelang = isset($CFG->lang) ? (string)$CFG->lang : 'en';
}
$sourcelang = core_text::strtolower($sourcelang);

mtrace("Source language: $sourcelang");
mtrace("Scope: " . ($allcourses ? 'all courses + system' : 'system pages only (courseid = 0)'));
mtrace("");

// Build query.
$actions = ['translation_create', 'translation_update', 'translation_autotranslate'];
list($actionsql, $actionparams) = $DB->get_in_or_equal($actions, SQL_PARAMS_NAMED, 'act');

$wherecourse = $allcourses ? '' : 'AND a.courseid = 0';

$sql = "SELECT a.id, a.keyid, a.lang, a.userid, a.courseid, a.action, a.timecreated
          FROM {local_xlate_activity} a
         WHERE a.action $actionsql
           AND " . $DB->sql_compare_text('a.lang') . " = :sourcelang
           $wherecourse
      ORDER BY a.timecreated DESC";

$rows = $DB->get_records_sql($sql, array_merge($actionparams, ['sourcelang' => $sourcelang]));
$count = count($rows);

if ($count === 0) {
    mtrace("No source-language activity entries found for lang='$sourcelang'. Nothing to do.");
    exit(0);
}

mtrace(sprintf("Found %d source-language activity %s for lang='%s'.",
    $count, $count === 1 ? 'entry' : 'entries', $sourcelang));

if (!$execute) {
    mtrace("");
    mtrace("Sample (first 20):");
    $shown = 0;
    foreach ($rows as $row) {
        if ($shown >= 20) {
            mtrace("  ... and " . ($count - 20) . " more.");
            break;
        }
        mtrace(sprintf("  id=%-8d  keyid=%-6d  lang=%-6s  userid=%-6d  courseid=%-6d  action=%-30s  time=%s",
            $row->id, $row->keyid, $row->lang, $row->userid, $row->courseid, $row->action,
            userdate($row->timecreated, '%Y-%m-%d %H:%M:%S')));
        $shown++;
    }
    mtrace("");
    mtrace("[DRY RUN] No changes made. Re-run with --execute to delete these rows.");
    exit(0);
}

// Delete in batches.
$ids      = array_column($rows, 'id');
$deleted  = 0;
$batchsize = 200;

foreach (array_chunk($ids, $batchsize) as $batch) {
    list($insql, $inparams) = $DB->get_in_or_equal($batch);
    $DB->delete_records_select('local_xlate_activity', "id $insql", $inparams);
    $deleted += count($batch);
    mtrace("Deleted $deleted / $count ...");
}

mtrace(sprintf("Done. Removed %d source-language activity %s for lang='%s'.",
    $deleted, $deleted === 1 ? 'entry' : 'entries', $sourcelang));
