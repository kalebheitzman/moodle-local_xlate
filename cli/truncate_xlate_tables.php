<?php
define('CLI_SCRIPT', true);
// CLI script to truncate all local_xlate_* tables in Moodle
// Usage: php truncate_xlate_tables.php

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// List of xlate tables from install.xml
$xlateTables = [
    'local_xlate_key',
    'local_xlate_key_course',
    'local_xlate_tr',
    'local_xlate_bundle',
    'local_xlate_glossary',
    'local_xlate_mlang_migration',
    'local_xlate_course_job',
    'local_xlate_token_batch'
];

// CLI options.
$help = <<<EOF
Truncate all local_xlate_* tables.
Usage: php cli/truncate_xlate_tables.php [options]

Options:
    --dry-run   List affected tables without truncating
    -h, --help  Show this help
EOF;

list($params, $unrecognized) = cli_get_params([
    'dry-run' => false,
    'help' => false,
], [
    'h' => 'help',
]);

if (!empty($unrecognized)) {
    $message = "Unknown options: \n  " . implode("\n  ", $unrecognized);
    cli_error($message);
}

if (!empty($params['help'])) {
    cli_writeln($help);
    exit(0);
}

$dryrun = !empty($params['dry-run']);

if ($dryrun) {
    mtrace("[DRY RUN] The following tables would be truncated:");
    foreach ($xlateTables as $table) {
        mtrace("  - $table");
    }
    mtrace("No changes made.");
    exit(0);
}

foreach ($xlateTables as $table) {
    $sql = "TRUNCATE TABLE {{$table}}";
    try {
        $DB->execute($sql);
        mtrace("Truncated table: $table");
    } catch (Exception $e) {
        mtrace("Error truncating $table: " . $e->getMessage());
    }
}

mtrace("All local_xlate_* tables truncated.");
