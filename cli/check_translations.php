<?php
// CLI tool to check persisted translations for given xkeys and language.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

$xkeys = [
    '1bl2r7zngtgf','ct9vnl1b0v9x','e4ndme1lo133','xwupbrtur0pq','is43qlo6eih7',
    '469dmp12qr7h','1it7irbchyb5','neeii71uhkpn','h9h01lz28zle','1fcipcu17w18'
];
$target = 'de';

global $DB;
foreach ($xkeys as $xkey) {
    $keydata = $DB->get_record('local_xlate_key', ['xkey' => $xkey]);
    if (!$keydata) {
        echo "NO KEY RECORD for xkey={$xkey}\n";
        continue;
    }
    $tr = $DB->get_record('local_xlate_tr', ['keyid' => $keydata->id, 'lang' => $target]);
    if ($tr) {
        echo "TRANSLATED: {$xkey} -> " . $tr->text . "\n";
    } else {
        echo "NOT TRANSLATED: {$xkey}\n";
    }
}

