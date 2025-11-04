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
 * CLI utility to inspect stored translations for Local Xlate.
 *
 * Iterates a hard-coded list of xkeys and reports whether a translation exists
 * for a target language, printing results to stdout.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

$xkeys = [
    // Previously-checked keys
    '1bl2r7zngtgf','ct9vnl1b0v9x','e4ndme1lo133','xwupbrtur0pq','is43qlo6eih7',
    '469dmp12qr7h','1it7irbchyb5','neeii71uhkpn','h9h01lz28zle','1fcipcu17w18',
    // Keys from recent task 34
    'nhamd6k0acid','1fldych1alv5','410zr2jtaw9f','277gu47uvocl','61zfmb1lib7o',
    'j473nzl2mbt9','1hx3k471bv0j','4mnf9o1plg6k','8v05o915ye19','1cermio16ybw'
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

