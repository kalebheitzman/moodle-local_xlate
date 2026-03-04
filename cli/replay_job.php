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
 * CLI tool to replay a completed course job for debugging.
 *
 * Loads the language config from an existing job, fetches a small sample of
 * items from the course, and calls translate_batch so you can inspect the
 * outgoing request, glossary, and AI response without touching saved translations.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/replay_job.php --jobid=4445
 *   sudo -u www-data php local/xlate/cli/replay_job.php --jobid=4445 --limit=5 --targetlang=ru
 *   sudo -u www-data php local/xlate/cli/replay_job.php --jobid=4445 --dryrun
 *
 * Flags:
 *   --jobid=N          Job ID to replay (required)
 *   --limit=N          Number of source items to include per batch (default: 3)
 *   --targetlang=CODE  Test only one target language instead of all from the job
 *   --dryrun           Print glossary + items but skip the API call
 *   --verbose          Print full outgoing JSON payload and raw API response body
 *
 * Note: This script calls the live AI API and will consume tokens (unless --dryrun).
 *       Translations are NOT saved back to the database.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
global $DB, $CFG;

// ── Parse options ────────────────────────────────────────────────────────────
$opts = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $opts[$parts[0]] = isset($parts[1]) ? $parts[1] : true;
    }
}

if (empty($opts['jobid'])) {
    echo "Usage: php replay_job.php --jobid=N [--limit=3] [--targetlang=CODE] [--dryrun] [--verbose]\n";
    exit(1);
}

$jobid          = (int)$opts['jobid'];
$limit          = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 3;
$targetlangfilt = isset($opts['targetlang']) ? trim((string)$opts['targetlang']) : '';
$dryrun         = !empty($opts['dryrun']);
$verbose        = !empty($opts['verbose']);

// ── Redirect PHP error_log → stdout so debugging() output is visible ─────────
// backend::translate_batch() logs the full outgoing payload and response body
// via debugging(DEBUG_DEVELOPER), which in CLI mode calls error_log(). Pointing
// error_log at php://stdout makes those lines appear inline with the rest of the
// output.  Run with "2>&1" as well to catch anything sent to stderr.
ini_set('error_log', 'php://stdout');
$CFG->debug        = DEBUG_DEVELOPER;
$CFG->debugdisplay = false; // use error_log path, not HTML echo

// ── Load job ─────────────────────────────────────────────────────────────────
$job = $DB->get_record('local_xlate_course_job', ['id' => $jobid], '*', IGNORE_MISSING);
if (!$job) {
    echo "Error: Job {$jobid} not found.\n";
    exit(1);
}

$options = [];
if (!empty($job->options)) {
    $decoded = json_decode((string)$job->options, true);
    if (is_array($decoded)) {
        $options = $decoded;
    }
}

$sourcelang  = trim((string)($options['sourcelang'] ?? ''));
$targetlangs = [];
if (!empty($options['targetlangs'])) {
    $targetlangs = array_values(array_filter(array_map('trim', (array)$options['targetlangs'])));
} elseif (!empty($options['targetlang'])) {
    $targetlangs = array_values(array_filter(array_map('trim', (array)$options['targetlang'])));
}

if ($targetlangfilt !== '') {
    $targetlangs = [$targetlangfilt];
}

if ($sourcelang === '' || empty($targetlangs)) {
    echo "Error: Job {$jobid} has no source/target language configuration.\n";
    echo "Options: " . json_encode($options) . "\n";
    exit(1);
}

// ── Header ───────────────────────────────────────────────────────────────────
$hr = str_repeat('─', 60);
echo "{$hr}\n";
echo "Job {$jobid}  |  course {$job->courseid}  |  status: {$job->status}\n";
echo "Progress: {$job->processed}/{$job->total}\n";
echo "Languages: {$sourcelang} → " . implode(', ', $targetlangs) . "\n";
echo "Item limit: {$limit}" . ($dryrun ? "  [DRY RUN]" : "") . ($verbose ? "  [VERBOSE]" : "") . "\n";
echo "{$hr}\n";

// ── Fetch sample items ────────────────────────────────────────────────────────
$sql = "SELECT kc.id as kc_id, kc.keyid, k.component, k.xkey, k.source
          FROM {local_xlate_key_course} kc
          JOIN {local_xlate_key} k ON k.id = kc.keyid
         WHERE kc.courseid = :courseid
         ORDER BY kc.id ASC";
$records = $DB->get_records_sql($sql, ['courseid' => (int)$job->courseid], 0, $limit);

if (empty($records)) {
    echo "No keys found for course {$job->courseid}.\n";
    exit(0);
}

$items = [];
foreach ($records as $rec) {
    $items[] = [
        'id'          => (string)$rec->xkey,
        'keyid'       => (int)$rec->keyid,
        'component'   => (string)$rec->component,
        'key'         => (string)$rec->xkey,
        'source_text' => (string)$rec->source,
        'courseid'    => (int)$job->courseid,
        'context'     => '',
    ];
}

echo "Items (" . count($items) . "):\n";
foreach ($items as $it) {
    $src = preg_replace('/\s+/', ' ', $it['source_text']);
    $preview = mb_strlen($src) > 70 ? mb_substr($src, 0, 70) . '…' : $src;
    echo "  [{$it['id']}] {$preview}\n";
}

// ── Per target language ───────────────────────────────────────────────────────
foreach ($targetlangs as $targetlang) {
    if ($targetlang === '' || $targetlang === $sourcelang) {
        continue;
    }

    echo "\n{$hr}\n";
    echo ">>> {$sourcelang} → {$targetlang}\n";
    echo "{$hr}\n";

    // Load all glossary terms (critical always included; non-critical matched by batch content).
    $glossary = \local_xlate\glossary::get_pairs_for_language_pair($sourcelang, $targetlang);

    if (empty($glossary)) {
        echo "Glossary: (none for {$sourcelang}→{$targetlang})\n";
    } else {
        $ncritical = count(array_filter($glossary, static function ($p) { return !empty($p['critical']); }));
        echo "Glossary (" . count($glossary) . " term(s), {$ncritical} critical):\n";
        foreach ($glossary as $g) {
            $flag = !empty($g['critical']) ? ' [CRITICAL]' : '';
            $line = "  {$g['term']} => {$g['replacement']}{$flag}";
            if (!empty($g['notes'])) {
                $line .= "  [Note: " . trim($g['notes']) . "]";
            }
            echo $line . "\n";
        }
    }

    // Suppress token logging for replay calls so they don't pollute usage stats.
    $replayoptions = $options;
    unset($replayoptions['jobid'], $replayoptions['usage_jobid']);

    // Strip keyid before sending (task does this too).
    $langitems = [];
    foreach ($items as $it) {
        $stripped = $it;
        unset($stripped['keyid']);
        $langitems[] = $stripped;
    }

    if ($dryrun) {
        $built = \local_xlate\translation\backend::build_payload(
            'dryrun_' . $jobid . '_' . $targetlang,
            $sourcelang,
            $targetlang,
            $langitems,
            $glossary,
            $replayoptions
        );
        if (!empty($built['error'])) {
            echo "\n[DRY RUN] Could not build payload: {$built['error']}\n";
        } else {
            $epurl = get_config('local_xlate', 'openai_endpoint') ?: '(endpoint not configured)';
            echo "\n[DRY RUN] Payload that would be sent to: {$epurl}\n";
            echo json_encode($built['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
        continue;
    }

    echo "\nCalling translate_batch";
    if (!$verbose) {
        echo " (run with --verbose to see full request/response JSON)";
    }
    echo "...\n";

    $result = \local_xlate\translation\backend::translate_batch(
        'replay_' . $jobid . '_' . $targetlang,
        $sourcelang,
        $targetlang,
        $langitems,
        $glossary,
        $replayoptions
    );

    // ── Print result summary ──────────────────────────────────────────────────
    echo "\n--- Result ---\n";
    echo "OK: " . ($result['ok'] ? 'yes' : 'NO') . "\n";

    if (!empty($result['errors'])) {
        echo "Errors: " . json_encode($result['errors'], JSON_PRETTY_PRINT) . "\n";
    }

    if (!empty($result['meta']['usage_tokens'])) {
        $u = $result['meta']['usage_tokens'];
        echo "Tokens used: prompt={$u['prompt']}, completion={$u['completion']}, total={$u['total']}\n";
    }

    if (!empty($result['results'])) {
        echo "\nTranslations (" . count($result['results']) . "):\n";
        foreach ($result['results'] as $r) {
            $t = preg_replace('/\s+/', ' ', (string)($r['translated'] ?? ''));
            $preview = mb_strlen($t) > 80 ? mb_substr($t, 0, 80) . '…' : $t;
            echo "  [{$r['id']}] {$preview}\n";
            if (!empty($r['applied_glossary_terms'])) {
                $applied = array_map(function ($ag) {
                    return ($ag['term'] ?? '?') . ' → ' . ($ag['replacement'] ?? $ag['applied'] ?? '?');
                }, $r['applied_glossary_terms']);
                echo "    Glossary applied: " . implode(', ', $applied) . "\n";
            }
            if (!empty($r['warnings'])) {
                echo "    Warnings: " . implode(', ', $r['warnings']) . "\n";
            }
        }
    }
}

echo "\n{$hr}\n";
echo "Done.\n";
