<?php
// Show translations for the language(s) of a given job since job creation time.
// Usage: php show_new_translations.php <jobid>

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

$jobid = isset($argv[1]) ? (int)$argv[1] : 0;
if (!$jobid) {
    echo "Usage: php show_new_translations.php <jobid>\n";
    exit(1);
}

global $DB;
$job = $DB->get_record('local_xlate_course_job', ['id' => $jobid], '*', IGNORE_MISSING);
if (!$job) {
    echo "Job not found: $jobid\n";
    exit(1);
}
$opts = [];
if (!empty($job->options)) {
    $opts = json_decode($job->options, true) ?: [];
}
$targetlangs = [];
if (!empty($opts['targetlang'])) {
    $targetlangs = is_array($opts['targetlang']) ? $opts['targetlang'] : [$opts['targetlang']];
}
if (empty($targetlangs)) {
    echo "No target languages found in job options.\n";
}
$ctime = (int)$job->ctime;

foreach ($targetlangs as $lang) {
    echo "Translations for lang: $lang since " . date('c', $ctime) . "\n";
    $recs = $DB->get_records_sql("SELECT t.id, t.keyid, t.lang, t.text, t.mtime, k.component, k.xkey
        FROM {local_xlate_tr} t JOIN {local_xlate_key} k ON k.id = t.keyid
        WHERE t.lang = :lang AND t.mtime >= :ctime
        ORDER BY t.mtime DESC LIMIT 50", ['lang' => $lang, 'ctime' => $ctime]);
    if (empty($recs)) {
        echo "  (no records)\n";
        continue;
    }
    foreach ($recs as $r) {
        echo sprintf("  id=%d keyid=%d %s.%s mtime=%s\n    text=%.80s\n", $r->id, $r->keyid, $r->component, $r->xkey, date('c', $r->mtime), $r->text);
    }
}

exit(0);
