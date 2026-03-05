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
 * CLI tool to compare translation quality across multiple AI models.
 *
 * Fetches a sample of strings from a course, sends them to each specified
 * model, and prints a side-by-side comparison. No translations are saved.
 *
 * Usage:
 *   sudo -u www-data php local/xlate/cli/compare_models.php \
 *     --courseid=42 \
 *     --models=gpt-4o-mini,gpt-4o,gpt-4-turbo \
 *     [--targetlang=es] \
 *     [--count=15] \
 *     [--random]
 *
 * Options:
 *   --courseid=N        (required) Course to sample strings from.
 *   --models=a,b,c      (required) Comma-separated model identifiers to compare.
 *   --targetlang=xx     (optional) Target language code. Defaults to the first
 *                                  target language configured on the course.
 *   --count=N           (optional) Number of strings to sample. Default: 15.
 *   --random            (optional) Sample randomly instead of taking the first N.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
global $DB, $CFG;

// ---------------------------------------------------------------------------
// Parse CLI options.
// ---------------------------------------------------------------------------
$opts = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $opts[$parts[0]] = isset($parts[1]) ? $parts[1] : true;
    }
}

$courseid   = isset($opts['courseid'])   ? (int)$opts['courseid']   : 0;
$count      = isset($opts['count'])      ? (int)$opts['count']      : 15;
$targetlang = isset($opts['targetlang']) ? trim($opts['targetlang']) : '';
$random     = !empty($opts['random']);
$order      = (isset($opts['order']) && strtolower($opts['order']) === 'desc') ? 'DESC' : 'ASC';
$models     = [];
if (!empty($opts['models'])) {
    $models = array_values(array_filter(array_map('trim', explode(',', (string)$opts['models']))));
}

if (!$courseid || empty($models)) {
    echo "Usage: php compare_models.php --courseid=N --models=model1,model2 [--targetlang=xx] [--count=15] [--order=asc|desc] [--random]\n";
    exit(1);
}

if ($count < 1 || $count > 100) {
    echo "Error: --count must be between 1 and 100.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Validate course and resolve languages.
// ---------------------------------------------------------------------------
if (!\local_xlate\customfield_helper::is_course_enabled($courseid)) {
    echo "Error: Course {$courseid} has Xlate disabled.\n";
    exit(1);
}

$config = \local_xlate\customfield_helper::get_course_config($courseid);
if ($config === null || empty($config['source'])) {
    echo "Error: Course {$courseid} has no source language configured.\n";
    exit(1);
}

$sourcelang = $config['source'];

if ($targetlang === '') {
    if (empty($config['targets'])) {
        echo "Error: Course {$courseid} has no target languages configured. Pass --targetlang=xx.\n";
        exit(1);
    }
    $targetlang = reset($config['targets']);
}

if ($targetlang === $sourcelang) {
    echo "Error: Target language '{$targetlang}' is the same as the source language '{$sourcelang}'.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Fetch sample strings from the course.
// ---------------------------------------------------------------------------
$sql = "SELECT k.id AS keyid, k.xkey, k.component, k.source
          FROM {local_xlate_key_course} kc
          JOIN {local_xlate_key} k ON k.id = kc.keyid
         WHERE kc.courseid = :courseid
           AND k.source <> ''
      ORDER BY " . ($random ? "RAND()" : "kc.id {$order}");

$records = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, $count);

if (empty($records)) {
    echo "No strings found for course {$courseid}.\n";
    exit(0);
}

// Build items array for the backend.
$items = [];
foreach ($records as $rec) {
    $items[] = [
        'id'          => (string)$rec->xkey,
        'key'         => (string)$rec->xkey,
        'component'   => (string)$rec->component,
        'source_text' => (string)$rec->source,
        'context'     => '',
    ];
}

// Load glossary for this language pair.
$glossary = \local_xlate\glossary::get_pairs_for_language_pair($sourcelang, $targetlang);

// ---------------------------------------------------------------------------
// Print header.
// ---------------------------------------------------------------------------
$separator = str_repeat('-', 72);
echo "\n";
echo "Course:      {$courseid}\n";
echo "Source lang: {$sourcelang}\n";
echo "Target lang: {$targetlang}\n";
echo "Strings:     " . count($items) . "\n";
echo "Models:      " . implode(', ', $models) . "\n";
if ($random) {
    echo "Sampling:    random\n";
} else {
    echo "Order:       " . strtolower($order) . "\n";
}
echo "\n" . $separator . "\n\n";

// ---------------------------------------------------------------------------
// Call each model and collect results keyed by xkey.
// ---------------------------------------------------------------------------
// $modelresults[model][xkey] = translated string | null
$modelresults = [];

$total = count($models);
foreach ($models as $i => $model) {
    $step = $i + 1;
    echo "  [{$step}/{$total}] {$model}... ";
    flush();

    $result = \local_xlate\translation\backend::translate_batch(
        'compare_' . $model . '_' . time(),
        $sourcelang,
        $targetlang,
        $items,
        $glossary,
        ['model' => $model]
    );

    $modelresults[$model] = [];
    if (!empty($result['ok']) && !empty($result['results'])) {
        foreach ($result['results'] as $r) {
            $id = (string)($r['id'] ?? '');
            if ($id !== '') {
                $modelresults[$model][$id] = (string)($r['translated'] ?? '');
            }
        }
        echo "done\n";
    } else {
        $errs = !empty($result['errors']) ? implode(', ', array_values((array)$result['errors'])) : 'unknown';
        echo "error ({$errs})\n";
    }
}

echo "\n" . $separator . "\n";

// ---------------------------------------------------------------------------
// Display comparison.
// ---------------------------------------------------------------------------
foreach ($items as $item) {
    $xkey  = $item['id'];
    $source = $item['source_text'];

    echo "\nSource: " . $source . "\n\n";

    foreach ($models as $model) {
        $translated = $modelresults[$model][$xkey] ?? null;
        if ($translated === null) {
            echo "  {$model}: [no result]\n";
        } else {
            echo "  {$model}: " . $translated . "\n";
        }
    }

    echo "\n" . $separator . "\n";
}

echo "\nDone.\n";
