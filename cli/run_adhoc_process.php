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
 * CLI helper to replay Local Xlate adhoc tasks.
 *
 * Usage: php run_adhoc_process.php <adhoc_task_id>
 *
 * Loads stored custom data, invokes the translation backend, and persists the
 * results, bypassing the task queue for debugging purposes.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php'); // ensure curl wrapper available

if ($argc < 2) {
    fwrite(STDERR, "Usage: php run_adhoc_process.php <adhoc_task_id>\n");
    exit(2);
}

$taskid = (int)$argv[1];
global $DB;

$row = $DB->get_record('task_adhoc', ['id' => $taskid], '*', IGNORE_MISSING);
if (!$row) {
    fwrite(STDERR, "No adhoc task with id={$taskid} found.\n");
    exit(3);
}

fwrite(STDOUT, "Processing adhoc task id={$taskid}\n");

$raw = null;
// Task customdata is stored as JSON text in this plugin's flows. Try JSON first.
if (!empty($row->customdata)) {
    $raw = json_decode($row->customdata, true);
    if ($raw === null) {
        // Try unserialize fallback
        $raw = @unserialize($row->customdata);
    }
}

/**
 * @var array{
 *     requestid?:string,
 *     sourcelang?:string,
 *     targetlang?:string|array<int,string>,
 *     items?:array<int,array|object>,
 *     glossary?:array<int,array|object>,
 *     options?:array<int|string,mixed>
 * }|false $raw
 */

if (empty($raw) || !is_array($raw)) {
    fwrite(STDERR, "Could not parse customdata for task id={$taskid}\n");
    exit(4);
}

$requestid = $raw['requestid'] ?? uniqid('rb_');
$sourcelang = $raw['sourcelang'] ?? $CFG->lang;
$targetlang = $raw['targetlang'] ?? '';
$items = $raw['items'] ?? [];
$glossary = $raw['glossary'] ?? [];
$options = $raw['options'] ?? [];

// Normalize items (stdClass -> arrays) when necessary
/** @var array<int,array<string,mixed>> $normalizeditems */
$normalizeditems = [];
foreach ($items as $it) {
    if (is_object($it)) {
        $normalizeditems[] = (array)$it;
    } else {
        $normalizeditems[] = $it;
    }
}

// Normalize glossary
/** @var array<int,array<string,mixed>> $normalizedglossary */
$normalizedglossary = [];
foreach ($glossary as $g) {
    if (is_object($g)) {
        $normalizedglossary[] = (array)$g;
    } else {
        $normalizedglossary[] = $g;
    }
}

// Support array or string targetlang
$targetlangs = is_array($targetlang) ? $targetlang : [$targetlang];

foreach ($targetlangs as $tl) {
    if (empty($tl)) {
        continue;
    }
    fwrite(STDOUT, "Calling backend::translate_batch request={$requestid} src={$sourcelang} dst={$tl} items=" . count($normalizeditems) . "\n");
    try {
        /**
         * @var array{
         *     ok?:bool,
         *     results?:array<int,array{id?:string,key?:string,translated?:string}>
         * }|null $result
         */
        $result = \local_xlate\translation\backend::translate_batch($requestid, $sourcelang, $tl, $normalizeditems, $normalizedglossary, $options);
    } catch (\Exception $e) {
        fwrite(STDERR, "backend::translate_batch raised exception: " . $e->getMessage() . "\n");
        continue;
    }

    if (empty($result) || empty($result['ok']) || empty($result['results']) || !is_array($result['results'])) {
        fwrite(STDERR, "No valid results returned for target {$tl}\n");
        continue;
    }

    // Persist translations
    $count = 0;
    foreach ($result['results'] as $r) {
        $id = $r['id'] ?? null;
        $translated = $r['translated'] ?? null;
        if ($translated === null) {
            continue;
        }

        // Match original
        $orig = null;
        foreach ($normalizeditems as $it) {
            $itid = (string)($it['id'] ?? '');
            $itkey = (string)($it['key'] ?? '');
            if ($itid === (string)$id || $itkey === (string)$id) {
                $orig = $it;
                break;
            }
        }
        if (!$orig) {
            continue;
        }

        if (!empty($orig['component']) && !empty($orig['key'])) {
            try {
                \local_xlate\local\api::save_key_with_translation(
                    (string)$orig['component'],
                    (string)$orig['key'],
                    (string)($orig['source_text'] ?? ''),
                    $tl,
                    (string)$translated,
                    0, // reviewed flag: machine-translated results are not human-reviewed
                    isset($orig['courseid']) ? (int)$orig['courseid'] : 0,
                    isset($orig['context']) ? (string)$orig['context'] : ''
                );
                $count++;
            } catch (\Exception $e) {
                fwrite(STDERR, "Failed saving translation for " . ($orig['key'] ?? $id) . ": " . $e->getMessage() . "\n");
            }
        }
    }
    fwrite(STDOUT, "Persisted {$count} translations for target {$tl}\n");
}

fwrite(STDOUT, "Done processing adhoc task id={$taskid}\n");
exit(0);

?>