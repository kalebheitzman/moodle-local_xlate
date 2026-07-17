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
 * CLI diagnostic: is autotranslate re-processing already-translated content?
 *
 * Read-only. Reports:
 *  1. Translation rows by (lang, status, reviewed) — inactive rows
 *     (status<>1) are re-selected by autotranslate_missing_task every run.
 *  2. Inactive rows split into "will be overwritten" (reviewed=0) vs
 *     "token-burning loop" (reviewed=1: re-sent to the API each run, save
 *     silently discarded by the reviewed guard).
 *  3. Reviewed rows whose most recent activity entry is an autotranslate
 *     write — would indicate the reviewed guard was bypassed.
 *  4. Course job churn: jobs per course over the last 48h.
 *  5. Token batch volume per day for the last 14 days.
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

cli_heading('1. Translation rows by (lang, status, reviewed)');
$rs = $DB->get_recordset_sql(
    "SELECT lang, status, reviewed, COUNT(1) AS cnt
       FROM {local_xlate_tr}
   GROUP BY lang, status, reviewed
   ORDER BY lang, status, reviewed"
);
printf("%-8s %-8s %-10s %s\n", 'lang', 'status', 'reviewed', 'count');
foreach ($rs as $r) {
    printf("%-8s %-8s %-10s %d\n", $r->lang, $r->status, $r->reviewed, $r->cnt);
}
$rs->close();

cli_heading('2. Inactive rows (status<>1) with course associations — re-selected every task run');
$rs = $DB->get_recordset_sql(
    "SELECT t.reviewed, COUNT(DISTINCT t.id) AS cnt
       FROM {local_xlate_tr} t
       JOIN {local_xlate_key_course} kc ON kc.keyid = t.keyid
      WHERE t.status <> 1
   GROUP BY t.reviewed"
);
$found = false;
foreach ($rs as $r) {
    $found = true;
    if ((int)$r->reviewed === 1) {
        echo "reviewed=1 (TOKEN LOOP — retranslated every run, save discarded): {$r->cnt}\n";
    } else {
        echo "reviewed=0 (WILL BE OVERWRITTEN + reactivated by autotranslate): {$r->cnt}\n";
    }
}
$rs->close();
if (!$found) {
    echo "None — no inactive translation rows with course associations.\n";
} else {
    echo "\nSample rows (first 15):\n";
    $rs = $DB->get_recordset_sql(
        "SELECT DISTINCT t.id, t.keyid, t.lang, t.status, t.reviewed, t.mtime, k.xkey, k.source
           FROM {local_xlate_tr} t
           JOIN {local_xlate_key} k ON k.id = t.keyid
           JOIN {local_xlate_key_course} kc ON kc.keyid = t.keyid
          WHERE t.status <> 1
       ORDER BY t.reviewed DESC, t.mtime DESC", [], 0, 15
    );
    foreach ($rs as $s) {
        $snippet = \core_text::substr(strip_tags((string)$s->source), 0, 70);
        printf("  keyid=%-8d xkey=%-14s lang=%-5s status=%s reviewed=%s  %s\n",
            $s->keyid, $s->xkey, $s->lang, $s->status, $s->reviewed, $snippet);
    }
    $rs->close();
}

cli_heading('3. Reviewed rows machine-written AFTER their review (guard bypass check, last 30 days)');
$autotranslateaction = \local_xlate\local\activity_logger::ACTION_AUTOTRANSLATE;
$since30 = time() - (30 * DAYSECS);
// Step 1: currently-reviewed rows with a recent autotranslate write. Bounded by
// the action index + time window so it stays cheap on a large audit log.
$candidates = $DB->get_records_sql(
    "SELECT t.id, t.keyid, t.lang, MAX(a.timecreated) AS lastauto
       FROM {local_xlate_activity} a
       JOIN {local_xlate_tr} t ON t.id = a.translationid
      WHERE a.action = :action
        AND a.timecreated > :since
        AND t.reviewed = 1
   GROUP BY t.id, t.keyid, t.lang",
    ['action' => $autotranslateaction, 'since' => $since30], 0, 200
);
// Step 2: keep only rows where no human action came after the machine write —
// the normal flow (AI writes, human reviews later) is fine; machine-last is not.
$suspects = 0;
foreach ($candidates as $c) {
    $laterhuman = $DB->record_exists_select(
        'local_xlate_activity',
        'translationid = :trid AND timecreated > :lastauto AND action <> :action',
        ['trid' => $c->id, 'lastauto' => $c->lastauto, 'action' => $autotranslateaction]
    );
    if ($laterhuman) {
        continue;
    }
    if ($suspects === 0) {
        echo "WARNING: reviewed row(s) whose most recent write is machine translation:\n";
    }
    $suspects++;
    printf("  trid=%-8d keyid=%-8d lang=%-5s machine-written at %s\n",
        $c->id, $c->keyid, $c->lang, userdate($c->lastauto));
    if ($suspects >= 20) {
        echo "  ... (truncated at 20)\n";
        break;
    }
}
if ($suspects === 0) {
    echo "None found — no evidence of autotranslate overwriting reviewed rows. Guard holding.\n";
}

cli_heading('4. Course job churn (last 48h)');
$since = time() - (2 * DAYSECS);
$rs = $DB->get_recordset_sql(
    "SELECT courseid,
            COUNT(1) AS jobs,
            SUM(CASE WHEN status = 'complete' THEN 1 ELSE 0 END) AS complete,
            SUM(CASE WHEN status = 'complete_partial' THEN 1 ELSE 0 END) AS partial,
            SUM(processed) AS processed, SUM(total) AS total
       FROM {local_xlate_course_job}
      WHERE mtime > :since
   GROUP BY courseid
   ORDER BY jobs DESC", ['since' => $since]
);
$found = false;
foreach ($rs as $j) {
    if (!$found) {
        printf("%-10s %-6s %-9s %-8s %-10s %s\n", 'courseid', 'jobs', 'complete', 'partial', 'processed', 'total');
    }
    $found = true;
    printf("%-10d %-6d %-9d %-8d %-10d %d\n",
        $j->courseid, $j->jobs, $j->complete, $j->partial, (int)$j->processed, (int)$j->total);
    if ((int)$j->jobs > 6) {
        echo "  ^^ HIGH CHURN: re-enqueued {$j->jobs} times in 48h — this course's 'missing' set is not shrinking.\n";
    }
}
$rs->close();
if (!$found) {
    echo "No course jobs in the last 48h.\n";
}

cli_heading('5. Token batches per day (last 14 days)');
$since = time() - (14 * DAYSECS);
$rs = $DB->get_recordset_sql(
    "SELECT FLOOR(timecreated / 86400) AS day,
            COUNT(1) AS batches,
            SUM(input_tokens) AS input_tokens,
            SUM(output_tokens) AS output_tokens
       FROM {local_xlate_token_batch}
      WHERE timecreated > :since
   GROUP BY FLOOR(timecreated / 86400)
   ORDER BY day", ['since' => $since]
);
$found = false;
foreach ($rs as $b) {
    if (!$found) {
        printf("%-12s %-9s %-14s %s\n", 'date', 'batches', 'input_tok', 'output_tok');
    }
    $found = true;
    printf("%-12s %-9d %-14d %d\n",
        date('Y-m-d', (int)$b->day * 86400), $b->batches,
        (int)$b->input_tokens, (int)$b->output_tokens);
}
$rs->close();
if (!$found) {
    echo "No token batches in the last 14 days.\n";
}

echo "\nDone.\n";
