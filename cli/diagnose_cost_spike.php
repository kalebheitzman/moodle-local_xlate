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
 * One-off, read-only CLI diagnostic for an unexplained autotranslate cost spike.
 *
 * Reports (no writes, ever):
 *  1. Non-secret autotranslate-relevant settings (never dumps openai_api_key).
 *  2. Monthly cost/token trend for the last 6 months, split by model.
 *  3. This-month cost by course (top 20) with job status breakdown.
 *  4. This-month course_job rows grouped by who queued them (userid) and
 *     by whether "options" indicates a forced/regenerating run (e.g.
 *     retranslate_unreviewed-style jobs), to catch a human- or cron-driven
 *     repeat-regeneration loop that would inflate spend without it being a
 *     "retranslate loop" in the missing-translation sense.
 *  5. Duplicate/overlapping pending+running jobs per course this month.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2026 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

cli_heading('1. Non-secret autotranslate settings');
$whitelist = [
    'enable', 'debughooks',
    'autotranslate_enabled', 'autotranslate_task_enabled', 'autotranslate_task_batchsize',
    'autodetect', 'mlang_cleanup_batch_size',
    'openai_endpoint', 'openai_model',
    'pricing_input_per_million', 'pricing_cached_input_per_million', 'pricing_output_per_million',
    'enabled_languages',
];
foreach ($whitelist as $key) {
    $val = get_config('local_xlate', $key);
    if (is_array($val)) {
        $val = implode(',', $val);
    }
    printf("%-32s %s\n", $key, var_export($val, true));
}

cli_heading('2. Monthly token batches by model (last 6 months)');
$since = strtotime('-6 months', time());
$rs = $DB->get_recordset_sql(
    "SELECT DATE_FORMAT(FROM_UNIXTIME(timecreated), '%Y-%m') AS ym,
            COALESCE(model, '(null)') AS model,
            COUNT(1) AS batches,
            SUM(input_tokens) AS input_tokens,
            SUM(output_tokens) AS output_tokens,
            SUM(total_cost) AS total_cost
       FROM {local_xlate_token_batch}
      WHERE timecreated > :since
   GROUP BY ym, model
   ORDER BY ym, total_cost DESC",
    ['since' => $since]
);
printf("%-9s %-20s %-9s %-14s %-14s %s\n", 'month', 'model', 'batches', 'input_tok', 'output_tok', 'cost');
$found = false;
foreach ($rs as $r) {
    $found = true;
    printf("%-9s %-20s %-9d %-14d %-14d %.2f\n",
        $r->ym, $r->model, $r->batches, (int)$r->input_tokens, (int)$r->output_tokens, (float)$r->total_cost);
}
$rs->close();
if (!$found) {
    echo "No token batches recorded in the last 6 months.\n";
}

cli_heading('3. This-month cost by course (top 20)');
$monthstart = strtotime(date('Y-m-01 00:00:00'));
$rs = $DB->get_recordset_sql(
    "SELECT cj.courseid,
            COUNT(DISTINCT tb.id) AS batches,
            SUM(tb.total_cost) AS total_cost,
            SUM(tb.input_tokens) AS input_tokens,
            SUM(tb.output_tokens) AS output_tokens
       FROM {local_xlate_token_batch} tb
       JOIN {local_xlate_course_job} cj ON cj.id = tb.jobid
      WHERE tb.timecreated > :since
   GROUP BY cj.courseid
   ORDER BY total_cost DESC", ['since' => $monthstart], 0, 20
);
printf("%-10s %-9s %-14s %-14s %s\n", 'courseid', 'batches', 'input_tok', 'output_tok', 'cost');
$found = false;
foreach ($rs as $r) {
    $found = true;
    printf("%-10d %-9d %-14d %-14d %.2f\n",
        $r->courseid, $r->batches, (int)$r->input_tokens, (int)$r->output_tokens, (float)$r->total_cost);
}
$rs->close();
if (!$found) {
    echo "No token batches this month with a resolvable course job.\n";
}
// Batches with no jobid (e.g. inline autotranslate_key calls) counted separately.
$orphan = $DB->get_record_sql(
    "SELECT COUNT(1) AS batches, SUM(total_cost) AS total_cost
       FROM {local_xlate_token_batch}
      WHERE timecreated > :since AND (jobid IS NULL OR jobid = 0)",
    ['since' => $monthstart]
);
if ($orphan && (int)$orphan->batches > 0) {
    printf("%-10s %-9d %-14s %-14s %.2f  (no jobid — e.g. inline single-key autotranslate)\n",
        '(none)', (int)$orphan->batches, '-', '-', (float)$orphan->total_cost);
}

cli_heading('4. This-month course_job rows by queuing user and options shape');
$rs = $DB->get_recordset_sql(
    "SELECT userid,
            COUNT(1) AS jobs,
            SUM(total) AS total_items,
            SUM(processed) AS processed_items
       FROM {local_xlate_course_job}
      WHERE ctime > :since
   GROUP BY userid
   ORDER BY jobs DESC", ['since' => $monthstart]
);
printf("%-10s %-8s %-14s %s\n", 'userid', 'jobs', 'total_items', 'processed_items');
$found = false;
foreach ($rs as $r) {
    $found = true;
    $uname = $r->userid ? ($DB->get_field('user', 'username', ['id' => $r->userid]) ?: '?') : '(cron/system)';
    printf("%-10s %-8d %-14d %d\n", "{$r->userid}:{$uname}", $r->jobs, (int)$r->total_items, (int)$r->processed_items);
}
$rs->close();
if (!$found) {
    echo "No course jobs queued this month.\n";
}

echo "\nSample of this month's job 'options' payloads (first 10, to spot forced/regenerate flags):\n";
$rs = $DB->get_recordset_sql(
    "SELECT id, courseid, userid, status, options, ctime
       FROM {local_xlate_course_job}
      WHERE ctime > :since
   ORDER BY ctime DESC", ['since' => $monthstart], 0, 10
);
foreach ($rs as $j) {
    printf("  jobid=%-6d courseid=%-6d userid=%-6d status=%-16s ctime=%s options=%s\n",
        $j->id, $j->courseid, $j->userid, $j->status, userdate($j->ctime), (string)$j->options);
}
$rs->close();

cli_heading('5. Overlapping pending+running jobs per course, this month (possible duplicate queuing)');
$rs = $DB->get_recordset_sql(
    "SELECT courseid, status, COUNT(1) AS cnt
       FROM {local_xlate_course_job}
      WHERE ctime > :since AND status IN ('pending', 'running')
   GROUP BY courseid, status
     HAVING COUNT(1) > 1
   ORDER BY cnt DESC", ['since' => $monthstart]
);
$found = false;
foreach ($rs as $r) {
    $found = true;
    printf("  courseid=%-6d status=%-10s count=%d\n", $r->courseid, $r->status, $r->cnt);
}
$rs->close();
if (!$found) {
    echo "None — no course has multiple simultaneous pending/running jobs this month.\n";
}

echo "\nDone.\n";
