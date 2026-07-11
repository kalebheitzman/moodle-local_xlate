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
 * One-off CLI helper to diagnose "Can't find data record in database" errors
 * on the quiz edit page, caused by question_bank_entries whose
 * question_categories row has been deleted.
 *
 * Usage:
 *   php diagnose_question_chain.php --id=20320
 *   php diagnose_question_chain.php --scan
 *
 * Read-only. Makes no database changes.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2026 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognised) = cli_get_params(
    ['id' => 0, 'scan' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help']) {
    echo "Diagnose broken question -> question_bank_entries -> question_categories chains.\n\n";
    echo "Options:\n";
    echo "  --id=NNNN   Walk the chain for a specific question id.\n";
    echo "  --scan      Site-wide scan for orphaned question_bank_entries and the\n";
    echo "              quizzes/courses that reference them.\n";
    exit(0);
}

global $DB;

if ($options['id']) {
    $questionid = (int)$options['id'];
    echo "=== Chain check for question id {$questionid} ===\n";

    $q = $DB->get_record('question', ['id' => $questionid]);
    echo 'question:               ' . ($q ? "FOUND (qtype={$q->qtype}, name=\"{$q->name}\")" : 'MISSING') . "\n";

    $qv = $q ? $DB->get_record('question_versions', ['questionid' => $questionid]) : false;
    echo 'question_versions:      ' . ($qv ? "FOUND (id={$qv->id}, entryid={$qv->questionbankentryid}, status={$qv->status})" : 'MISSING') . "\n";

    $qbe = $qv ? $DB->get_record('question_bank_entries', ['id' => $qv->questionbankentryid]) : false;
    echo 'question_bank_entries:  ' . ($qbe ? "FOUND (id={$qbe->id}, categoryid={$qbe->questioncategoryid}, ownerid={$qbe->ownerid})" : 'MISSING') . "\n";

    $qc = $qbe ? $DB->get_record('question_categories', ['id' => $qbe->questioncategoryid]) : false;
    echo 'question_categories:    ' . ($qc ? "FOUND (id={$qc->id}, contextid={$qc->contextid}, name=\"{$qc->name}\")" : 'MISSING <-- likely break') . "\n";

    if ($qbe && !$qc) {
        echo "\nLooking up what deleted question_categories id {$qbe->questioncategoryid}...\n";
        $logs = $DB->get_records_select(
            'logstore_standard_log',
            "objecttable = 'question_categories' AND objectid = :catid",
            ['catid' => $qbe->questioncategoryid],
            'timecreated DESC',
            'id, userid, action, timecreated, contextid',
            0,
            5
        );
        if ($logs) {
            foreach ($logs as $log) {
                $user = $DB->get_record('user', ['id' => $log->userid], 'firstname, lastname', IGNORE_MISSING);
                $username = $user ? "{$user->firstname} {$user->lastname}" : "userid {$log->userid}";
                echo '  ' . userdate($log->timecreated) . " - {$log->action} by {$username} (contextid={$log->contextid})\n";
            }
        } else {
            echo "  No matching log entries found (log may have rotated out).\n";
        }
    }

    if ($qv) {
        echo "\n=== Quizzes/slots referencing entry id {$qv->questionbankentryid} ===\n";
        $sql = "SELECT qz.id AS quizid, qz.name AS quizname, c.id AS courseid, c.fullname,
                       qs.id AS slotid, qs.slot
                  FROM {question_references} qr
                  JOIN {quiz_slots} qs ON qs.id = qr.itemid AND qr.questionarea = 'slot' AND qr.component = 'mod_quiz'
                  JOIN {quiz} qz ON qz.id = qs.quizid
                  JOIN {course} c ON c.id = qz.course
                 WHERE qr.questionbankentryid = :entryid";
        $rows = $DB->get_records_sql($sql, ['entryid' => $qv->questionbankentryid]);
        if ($rows) {
            foreach ($rows as $r) {
                echo "  Quiz \"{$r->quizname}\" (id={$r->quizid}) in course \"{$r->fullname}\" (id={$r->courseid}), slot {$r->slot}\n";
            }
        } else {
            echo "  None found.\n";
        }
    }
}

if ($options['scan']) {
    echo "=== Site-wide scan: question_bank_entries with no matching question_categories row ===\n";
    $sql = "SELECT qbe.id AS entryid, qbe.questioncategoryid
              FROM {question_bank_entries} qbe
         LEFT JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE qc.id IS NULL";
    $orphans = $DB->get_records_sql($sql);
    echo count($orphans) . " orphaned entries found.\n\n";

    foreach ($orphans as $o) {
        echo "entry id={$o->entryid}, missing categoryid={$o->questioncategoryid}\n";

        $sql = "SELECT q.id AS questionid, qz.id AS quizid, qz.name AS quizname,
                       c.id AS courseid, c.fullname, qs.slot
                  FROM {question_versions} qv
                  JOIN {question} q ON q.id = qv.questionid
             LEFT JOIN {question_references} qr ON qr.questionbankentryid = qv.questionbankentryid
             LEFT JOIN {quiz_slots} qs ON qs.id = qr.itemid AND qr.questionarea = 'slot' AND qr.component = 'mod_quiz'
             LEFT JOIN {quiz} qz ON qz.id = qs.quizid
             LEFT JOIN {course} c ON c.id = qz.course
                 WHERE qv.questionbankentryid = :entryid";
        $uses = $DB->get_records_sql($sql, ['entryid' => $o->entryid]);
        foreach ($uses as $u) {
            if ($u->quizid) {
                echo "  -> question id={$u->questionid} used in quiz \"{$u->quizname}\" (id={$u->quizid}), course \"{$u->fullname}\" (id={$u->courseid}), slot {$u->slot}\n";
            } else {
                echo "  -> question id={$u->questionid} (not currently used in any quiz slot)\n";
            }
        }
        echo "\n";
    }
}

if (!$options['id'] && !$options['scan']) {
    echo "Nothing to do. Pass --id=NNNN or --scan (or --help).\n";
}
