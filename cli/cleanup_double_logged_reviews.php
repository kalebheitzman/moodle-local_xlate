<?php
define('CLI_SCRIPT', true);
/**
 * Remove double-counted translation_review_mark activity log entries.
 *
 * Before the fix in save_translation(), every CREATE or UPDATE that also
 * toggled the reviewed flag would fire two activity log entries — one for
 * the translation work and one for the review mark — with identical
 * keyid/lang/userid and a timecreated within the same second.  This caused
 * translators to be charged twice for the same piece of work.
 *
 * This script identifies and deletes the spurious review_mark rows: those
 * that are within --window seconds of a create/update/autotranslate entry
 * for the same (keyid, lang, userid).
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/cleanup_double_logged_reviews.php
 *   sudo -u www-data php local/xlate/cli/cleanup_double_logged_reviews.php --execute
 *   sudo -u www-data php local/xlate/cli/cleanup_double_logged_reviews.php --window=5 --execute
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$help = <<<EOF
Remove spurious translation_review_mark activity log entries caused by the
double-logging bug in save_translation().

A review_mark row is considered spurious when a translation_create,
translation_update, or translation_autotranslate row exists for the same
keyid + lang + userid within --window seconds of it.

Options:
    --execute       Delete the identified rows (default: dry-run only)
    --window=N      Time window in seconds to match paired rows (default: 2)
    -h, --help      Show this help

Examples:
    # Preview what would be deleted
    sudo -u www-data php local/xlate/cli/cleanup_double_logged_reviews.php

    # Delete the duplicate entries
    sudo -u www-data php local/xlate/cli/cleanup_double_logged_reviews.php --execute
EOF;

list($params, $unrecognized) = cli_get_params([
    'execute' => false,
    'window'  => 2,
    'help'    => false,
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

$execute = !empty($params['execute']);
$window  = max(1, (int)$params['window']);

// Find all translation_review_mark rows that sit within $window seconds of a
// create/update/autotranslate row for the same keyid + lang + userid.
// MySQL/MariaDB and PostgreSQL both support ABS() and BETWEEN, so this query
// is portable via Moodle's DB layer using a raw SQL fallback.
$sql = "SELECT r.id, r.keyid, r.lang, r.userid, r.timecreated
          FROM {local_xlate_activity} r
         WHERE r.action = 'translation_review_mark'
           AND EXISTS (
               SELECT 1
                 FROM {local_xlate_activity} w
                WHERE w.keyid       = r.keyid
                  AND w.lang        = r.lang
                  AND w.userid      = r.userid
                  AND w.action      IN ('translation_create',
                                        'translation_update',
                                        'translation_autotranslate')
                  AND w.timecreated BETWEEN r.timecreated - :win AND r.timecreated + :win2
           )
      ORDER BY r.timecreated DESC";

$rows = $DB->get_records_sql($sql, ['win' => $window, 'win2' => $window]);
$count = count($rows);

if ($count === 0) {
    mtrace("No spurious translation_review_mark entries found. Nothing to do.");
    exit(0);
}

mtrace(sprintf("Found %d spurious translation_review_mark %s (window: ±%ds).",
    $count, $count === 1 ? 'entry' : 'entries', $window));

if (!$execute) {
    mtrace("");
    mtrace("Sample (first 20):");
    $shown = 0;
    foreach ($rows as $row) {
        if ($shown >= 20) {
            mtrace("  ... and " . ($count - 20) . " more.");
            break;
        }
        mtrace(sprintf("  id=%-8d  keyid=%-6d  lang=%-6s  userid=%-6d  time=%s",
            $row->id, $row->keyid, $row->lang, $row->userid,
            userdate($row->timecreated, '%Y-%m-%d %H:%M:%S')));
        $shown++;
    }
    mtrace("");
    mtrace("[DRY RUN] No changes made. Re-run with --execute to delete these rows.");
    exit(0);
}

// Delete in batches to avoid long-running single transactions.
$ids     = array_column($rows, 'id');
$deleted = 0;
$batchsize = 200;

foreach (array_chunk($ids, $batchsize) as $batch) {
    list($insql, $inparams) = $DB->get_in_or_equal($batch);
    $DB->delete_records_select('local_xlate_activity', "id $insql", $inparams);
    $deleted += count($batch);
    mtrace("Deleted $deleted / $count ...");
}

mtrace(sprintf("Done. Removed %d spurious translation_review_mark %s.",
    $deleted, $deleted === 1 ? 'entry' : 'entries'));
