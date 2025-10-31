<?php
// Run a queued adhoc task payload directly (helper for debugging/repair).
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

if (empty($raw) || !is_array($raw)) {
    fwrite(STDERR, "Could not parse customdata for task id={$taskid}\n");
    exit(4);
}

$requestid = $raw['requestid'] ?? uniqid('rb_');
$sourcelang = $raw['sourcelang'] ?? 'en';
$targetlang = $raw['targetlang'] ?? '';
$items = $raw['items'] ?? [];
$glossary = $raw['glossary'] ?? [];
$options = $raw['options'] ?? [];

// Normalize items (stdClass -> arrays) when necessary
$normalizeditems = [];
foreach ($items as $it) {
    if (is_object($it)) {
        $normalizeditems[] = (array)$it;
    } else {
        $normalizeditems[] = $it;
    }
}

// Normalize glossary
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