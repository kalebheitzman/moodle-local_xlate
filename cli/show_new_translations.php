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
 * CLI viewer for freshly added Local Xlate translations.
 *
 * Usage: php show_new_translations.php <jobid>
 *
 * Lists translations inserted after the specified course job was created.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
