<?php
// Small helper script to search for literal '{mlang ' in common section/title/summary fields.
// Run as the webserver user: php find_mlang_sections.php

define('CLI_SCRIPT', true);
require_once('/var/www/moodle/config.php');

$prefix = isset($CFG->prefix) ? $CFG->prefix : '';
$host = isset($CFG->dbhost) ? $CFG->dbhost : 'localhost';
$user = isset($CFG->dbuser) ? $CFG->dbuser : null;
$pass = isset($CFG->dbpass) ? $CFG->dbpass : null;
$dbname = isset($CFG->dbname) ? $CFG->dbname : null;
$port = 3306;

$mysqli = new mysqli($host, $user, $pass, $dbname, $port);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "DB connect failed: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$results = [];
$checks = [
    [ 'table' => $prefix . 'course_sections', 'idcol' => 'id', 'cols' => ['summary', 'name'] ],
    [ 'table' => $prefix . 'course', 'idcol' => 'id', 'cols' => ['fullname', 'summary'] ],
];

foreach ($checks as $check) {
    $table = $check['table'];
    foreach ($check['cols'] as $col) {
        // Skip if column doesn't exist.
        $colesc = $mysqli->real_escape_string($col);
        $tble = $mysqli->real_escape_string($table);
        $existsq = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . $mysqli->real_escape_string($dbname) . "' AND TABLE_NAME = '" . $tble . "' AND COLUMN_NAME = '" . $colesc . "' LIMIT 1";
        $exres = $mysqli->query($existsq);
        if (!$exres || $exres->num_rows == 0) {
            continue;
        }
        $q = "SELECT " . $check['idcol'] . ", " . $col . " FROM `" . $table . "` WHERE " . $col . " LIKE '%{mlang %' LIMIT 200";
        $res = $mysqli->query($q);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $results[] = [
                    'table' => $table,
                    'id' => $row[$check['idcol']],
                    'column' => $col,
                    'value_snippet' => mb_substr($row[$col], 0, 200),
                ];
            }
            $res->free();
        }
    }
}

$outpath = '/tmp/mlang_section_matches.json';
file_put_contents($outpath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Wrote " . count($results) . " matches to: $outpath\n";
foreach ($results as $r) {
    echo "- {$r['table']}#{$r['id']}.{$r['column']}: {$r['value_snippet']}\n";
}

$mysqli->close();
