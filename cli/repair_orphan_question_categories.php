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
 * One-off CLI helper to repair question_bank_entries rows whose
 * question_categories row has been deleted (see diagnose_question_chain.php).
 *
 * For each affected question_bank_entries row, gets-or-creates the official
 * Moodle "default category" for a chosen quiz module context (via
 * question_get_default_category(), the same helper Moodle itself calls when
 * a quiz's category is first created) and repoints questioncategoryid at it.
 *
 * Only question_bank_entries.questioncategoryid is modified. The question,
 * question_versions, and question_references rows (content, answers,
 * attempt history) are untouched.
 *
 * Usage:
 *   php repair_orphan_question_categories.php --dry-run   (default)
 *   php repair_orphan_question_categories.php --execute
 *
 * Take a DB backup before running with --execute.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2026 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');

list($options, $unrecognised) = cli_get_params(
    ['execute' => false, 'dry-run' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help']) {
    echo "Repair orphaned question_bank_entries by repointing them at a freshly\n";
    echo "created default category in a chosen quiz's module context.\n\n";
    echo "Options:\n";
    echo "  --dry-run   Show what would change. Default if --execute is not given.\n";
    echo "  --execute   Actually perform the repair.\n";
    exit(0);
}

$execute = (bool)$options['execute'];

global $DB;

// Each cluster: missing categoryid => modcontextid to recreate the default category in.
// modcontextid chosen as the module context of one quiz that uses entries in that cluster
// (for the 3911 cluster, which is shared across 3 courses, this is quiz id 1629 -- the
// quiz named in the original bug report. The other 5 quizzes sharing entry 18202 keep
// working because they reference the entry id, not the category).
$clustertargets = [
    2491 => 34708, // Quiz 1137 "Sample Quiz", course 894.
    3875 => 32886, // Quiz 1092 "APA Formatting Review Quiz BROKEN", course 776.
    3911 => 44655, // Quiz 1629 "Reading Report Gorman or Fee", course 938.
    3939 => 33550, // Quiz 1108 "BrokenWeek 2 Quiz: Research Terms & Distinctions", course 773.
];

// Re-derive the orphaned entries live rather than trusting a hardcoded list.
$sql = "SELECT qbe.id AS entryid, qbe.questioncategoryid AS missingcatid
          FROM {question_bank_entries} qbe
     LEFT JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
         WHERE qc.id IS NULL";
$orphans = $DB->get_records_sql($sql);

if (!$orphans) {
    echo "No orphaned question_bank_entries found. Nothing to do.\n";
    exit(0);
}

$byclusterid = [];
foreach ($orphans as $o) {
    $byclusterid[$o->missingcatid][] = $o->entryid;
}

foreach ($byclusterid as $missingcatid => $entryids) {
    echo "=== Missing categoryid {$missingcatid}: " . count($entryids) . " entr" . (count($entryids) === 1 ? 'y' : 'ies') . " ===\n";

    if (!isset($clustertargets[$missingcatid])) {
        echo "  SKIPPED: no target module context configured for this cluster. Investigate manually.\n\n";
        continue;
    }

    $modcontextid = $clustertargets[$missingcatid];
    $context = \core\context::instance_by_id($modcontextid, IGNORE_MISSING);
    if (!$context || $context->contextlevel !== CONTEXT_MODULE) {
        echo "  SKIPPED: modcontextid {$modcontextid} is not a valid module context.\n\n";
        continue;
    }

    if ($execute) {
        $transaction = $DB->start_delegated_transaction();
        $newcat = question_get_default_category($modcontextid, true);
        echo "  Using/created category id={$newcat->id} \"{$newcat->name}\" in contextid={$modcontextid}\n";

        list($insql, $inparams) = $DB->get_in_or_equal($entryids, SQL_PARAMS_NAMED);
        $DB->set_field_select(
            'question_bank_entries',
            'questioncategoryid',
            $newcat->id,
            "id {$insql}",
            $inparams
        );
        $transaction->allow_commit();

        echo "  Repointed entry ids: " . implode(', ', $entryids) . "\n\n";
    } else {
        // Dry run: show what the target category would be without creating it.
        $existing = $DB->get_records_select(
            'question_categories',
            'contextid = ? AND parent <> 0',
            [$modcontextid],
            'id',
            '*',
            0,
            1
        );
        $existingcat = reset($existing);
        if ($existingcat) {
            echo "  Would reuse existing category id={$existingcat->id} \"{$existingcat->name}\" in contextid={$modcontextid}\n";
        } else {
            echo "  Would create a new default category in contextid={$modcontextid}\n";
        }
        echo "  Would repoint entry ids: " . implode(', ', $entryids) . "\n\n";
    }
}

if ($execute) {
    $cache = \cache::make('core', 'questiondata');
    $cache->purge();
    echo "Purged core/questiondata cache.\n";
    echo "Done. Reload the affected quiz edit pages to verify.\n";
} else {
    echo "Dry run complete. No changes made. Re-run with --execute to apply.\n";
}
