<?php
// CLI runner for MLang dry-run. Run as webserver user:
// sudo -u www-data php local/xlate/cli/mlang_dryrun.php

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/mlang_migration.php');

// Prepare options if any can be passed via argv (not implemented: defaults used).
$DB = $GLOBALS['DB'];

echo "Starting local_xlate MLang dry-run...\n";
$report = \local_xlate\mlang_migration::dryrun($DB, []);
echo "Dry-run completed. Report written to: " . ($report['report_file'] ?? 'unknown') . "\n";
echo "Total matches: " . ($report['total_matches'] ?? 0) . "\n";
