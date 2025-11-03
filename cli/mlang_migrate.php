<?php
// CLI runner for the MLang migration (dry-run by default).
define('CLI_SCRIPT', true);
// CLI is located at local/xlate/cli — go up one more directory to reach Moodle root config.php.
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/mlang_migration.php');

global $DB;

$opts = getopt('', ['execute', 'max::', 'preferred::', 'chunk::', 'sample::', 'tables::']);
$execute = array_key_exists('execute', $opts);
$max = isset($opts['max']) ? (int)$opts['max'] : 0;
$preferred = $opts['preferred'] ?? 'other';
$chunk = isset($opts['chunk']) ? (int)$opts['chunk'] : 200;
$sample = isset($opts['sample']) ? (int)$opts['sample'] : 2000;

$tables = null;
if (!empty($opts['tables'])) {
    // Expect a JSON file path or a comma-separated list of table.column pairs (table:col)
    $arg = $opts['tables'];
    if (file_exists($arg)) {
        $json = json_decode(file_get_contents($arg), true);
        if (is_array($json)) {
            $tables = $json;
        }
    } else {
        // parse comma-separated list like "label:intro,course_sections:summary"
        $pairs = explode(',', $arg);
        $map = [];
        foreach ($pairs as $p) {
            $p = trim($p);
            if ($p === '') { continue; }
            if (strpos($p, ':') !== false) {
                list($t, $c) = explode(':', $p, 2);
                $map[trim($t)][] = trim($c);
            }
        }
        if (!empty($map)) { $tables = $map; }
    }
}

// If no explicit tables provided, always use the hardcoded default_tables mapping for safety and completeness.
if ($tables === null) {
    $tables = \local_xlate\mlang_migration::default_tables();
}

$options = ['tables' => $tables, 'chunk' => $chunk, 'preferred' => $preferred, 'execute' => $execute, 'sample' => $sample];
if ($max > 0) { $options['max_changes'] = $max; }

echo "Running mlang migration (execute=" . ($execute ? 'true' : 'false') . ", preferred={$preferred}, max_changes={$max})\n";

$report = \local_xlate\mlang_migration::migrate($DB, $options);

$outfile = sys_get_temp_dir() . '/local_xlate_mlang_migrate_' . time() . '.json';
file_put_contents($outfile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Report written to: {$outfile}\n";
echo "Changed: " . ($report['changed'] ?? 0) . "\n";
if (!empty($report['samples'])) {
    echo "Samples:\n";
    foreach ($report['samples'] as $s) {
        echo "- {$s['table']}#{$s['id']}.{$s['column']}: " . substr(strip_tags($s['old']), 0, 150) . " -> " . substr(strip_tags($s['new']), 0, 150) . "\n";
    }
}

exit(0);
