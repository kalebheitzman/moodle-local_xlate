<?php
// Search database for literal '{mlang ' occurrences across all text-like columns
// and emit a JSON report with counts and a small sample per column.

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/mlang_migration.php');

global $DB, $CFG;

$pattern = '%{mlang %';
$report = ['run' => date('c'), 'matches' => []];

// Get candidate columns from information_schema for this DB
$sql = "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = :db
          AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')
          AND CHARACTER_MAXIMUM_LENGTH IS NOT NULL";
$params = ['db' => $CFG->dbname];
$cols = $DB->get_records_sql($sql, $params);

foreach ($cols as $c) {
    $table = $c->table_name;
    $column = $c->column_name;
    // build a safe SQL using backticks and the real DB name
    $sqlcount = "SELECT COUNT(*) AS cnt FROM `{$CFG->dbname}`.`{$table}` WHERE `{$column}` LIKE ?";
    try {
        $rec = $DB->get_record_sql($sqlcount, [$pattern]);
    } catch (Exception $e) {
        // skip tables/columns we can't query
        continue;
    }
    $cnt = (int)($rec->cnt ?? 0);
    if ($cnt > 0) {
        $entry = ['table' => $table, 'column' => $column, 'count' => $cnt, 'samples' => []];
        // try to find primary key name for the table
        $pksql = "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND CONSTRAINT_NAME = 'PRIMARY' LIMIT 1";
        $pkrec = $DB->get_record_sql($pksql, ['db' => $CFG->dbname, 'table' => $table]);
        $pk = $pkrec ? $pkrec->column_name : null;
        // fetch up to 5 sample rows
        if ($pk) {
            $sqlsample = "SELECT `{$pk}`, `{$column}` FROM `{$CFG->dbname}`.`{$table}` WHERE `{$column}` LIKE ? LIMIT 5";
            try {
                $rows = $DB->get_records_sql($sqlsample, [$pattern]);
                foreach ($rows as $r) {
                    $entry['samples'][] = ['pk' => $r->{$pk}, 'value' => mb_substr($r->{$column}, 0, 500)];
                }
            } catch (Exception $e) {
                // ignore sampling errors
            }
        } else {
            // no pk known; just sample full rows (may be heavy) but limit 3
            $sqlsample = "SELECT * FROM `{$CFG->dbname}`.`{$table}` WHERE `{$column}` LIKE ? LIMIT 3";
            try {
                $rows = $DB->get_records_sql($sqlsample, [$pattern]);
                foreach ($rows as $r) {
                    // try to capture something useful
                    $vals = [];
                    foreach ((array)$r as $k => $v) {
                        $vals[$k] = mb_substr($v, 0, 200);
                    }
                    $entry['samples'][] = $vals;
                }
            } catch (Exception $e) {
                // ignore
            }
        }
        $report['matches'][] = $entry;
    }
}

$outfile = sys_get_temp_dir() . '/local_xlate_mlang_all_' . time() . '.json';
file_put_contents($outfile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Wrote report to: {$outfile}\n";
echo "Found " . count($report['matches']) . " columns with matches.\n";

exit(0);
