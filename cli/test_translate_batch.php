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
 * CLI tester for the Local Xlate translate_batch backend.
 *
 * Executes the backend with a sample payload to verify API responses.
 *
 * @package    local_xlate
 * @category   cli
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
// Adjust path from local/xlate/cli -> Moodle root
require_once(__DIR__ . '/../../../config.php');
// Ensure Moodle's curl wrapper is available when running from CLI.
require_once($CFG->libdir . '/filelib.php');

// Sample payload (replace or modify as needed). This matches the data you
// pasted from the adhoc task log.
$payloadjson = '{"requestid":"rb_69029b699bcda","sourcelang":"en","targetlang":"de","items":[{"id":"region_mainpage:1bl2r7zngtgf","component":"region_mainpage","key":"1bl2r7zngtgf","source_text":"CourseEditor2","placeholders":[]},{"id":"region_mainpage:ct9vnl1b0v9x","component":"region_mainpage","key":"ct9vnl1b0v9x","source_text":"ProcessMonitor","placeholders":[]},{"id":"region_footer-content-popover:e4ndme1lo133","component":"region_footer-content-popover","key":"e4ndme1lo133","source_text":"Reset user tour on this page","placeholders":[]},{"id":"region_mainpage:xwupbrtur0pq","component":"region_mainpage","key":"xwupbrtur0pq","source_text":"CourseEditor3","placeholders":[]},{"id":"region_mainpage:is43qlo6eih7","component":"region_mainpage","key":"is43qlo6eih7","source_text":"Import or export calendars","placeholders":[]},{"id":"region_day:469dmp12qr7h","component":"region_day","key":"469dmp12qr7h","source_text":"Today","placeholders":[]},{"id":"region_mainpage:1it7irbchyb5","component":"region_mainpage","key":"1it7irbchyb5","source_text":"Full calendar","placeholders":[]},{"id":"region_calendar:neeii71uhkpn","component":"region_calendar","key":"neeii71uhkpn","source_text":"Sun","placeholders":[]},{"id":"region_calendar:h9h01lz28zle","component":"region_calendar","key":"h9h01lz28zle","source_text":"Sat","placeholders":[]},{"id":"region_calendar:1fcipcu17w18","component":"region_calendar","key":"1fcipcu17w18","source_text":"Fri","placeholders":[]}],"glossary":[],"options":[]}';

$data = json_decode($payloadjson, true);
if (!$data) {
    fwrite(STDERR, "Failed to decode payload JSON\n");
    exit(1);
}

// Call the backend. This will perform the same operations that the adhoc task
// executes. Note: this will call your configured OpenAI endpoint and consume
// API quota if configured.
try {
    $result = \local_xlate\translation\backend::translate_batch(
        $data['requestid'] ?? uniqid('rb_'),
        $data['sourcelang'] ?? 'en',
        $data['targetlang'] ?? '',
        $data['items'] ?? [],
        $data['glossary'] ?? [],
        $data['options'] ?? []
    );

    // Pretty-print the returned result
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (\Exception $e) {
    fwrite(STDERR, "Exception running translate_batch: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(2);
}
