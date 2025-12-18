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
 * Translation bundle endpoint for Local Xlate.
 *
 * Serves JSON bundles keyed to page context or explicit key lists so the
 * front-end translator can render localized strings without exposing
 * unrelated data.
 *
 * @package    local_xlate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
defined('MOODLE_INTERNAL') || die();

if (!get_config('local_xlate', 'enable')) {
    http_response_code(403);
    echo json_encode(['error' => 'disabled']);
    exit;
}

// Allow guests to obtain bundles when permitted.
require_login(null, false);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'methodnotallowed']);
    exit;
}

require_sesskey();

// Parameters passed via query string for context/lang hints.
$lang = optional_param('lang', current_language(), PARAM_ALPHANUMEXT);
$contextid = optional_param('contextid', SYSCONTEXTID, PARAM_INT);
$pagetype = optional_param('pagetype', '', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$pagetype = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $pagetype);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalidjson']);
    exit;
}

$keys = [];
if (!empty($payload['keys']) && is_array($payload['keys'])) {
    $keys = $payload['keys'];
}

if (empty($keys)) {
    http_response_code(200);
    echo json_encode(['translations' => [], 'sources' => [], 'reviewed' => []]);
    exit;
}

$context = null;
try {
    $context = context::instance_by_id($contextid, IGNORE_MISSING);
} catch (Exception $e) {
    $context = null;
}
if (!$context) {
    $context = context_system::instance();
}

$coursecontext = null;
$resolvedcourseid = 0;

// Prefer explicit course id when provided.
if ($courseid > 0) {
    try {
        $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
        if ($coursecontext) {
            $resolvedcourseid = $coursecontext->instanceid;
        }
    } catch (Exception $e) {
        $coursecontext = null;
    }
}

if (!$coursecontext) {
    if ($context->contextlevel === CONTEXT_COURSE) {
        $coursecontext = $context;
        $resolvedcourseid = $context->instanceid;
    } else if ($context->contextlevel === CONTEXT_MODULE && method_exists($context, 'get_course_context')) {
        $coursecontext = $context->get_course_context(false) ?: null;
        if ($coursecontext) {
            $resolvedcourseid = $coursecontext->instanceid;
        }
    }
}

// Reject mismatched course identifiers.
if ($courseid > 0 && $resolvedcourseid > 0 && (int)$courseid !== (int)$resolvedcourseid) {
    http_response_code(400);
    echo json_encode(['error' => 'invalidcoursecontext']);
    exit;
}

if ($coursecontext) {
    require_capability('local/xlate:viewbundle', $coursecontext);
} else {
    require_capability('local/xlate:viewsystem', context_system::instance());
}

$courseparam = $coursecontext ? $coursecontext->instanceid : 0;

if ($courseparam > 0 && !\local_xlate\customfield_helper::is_course_enabled($courseparam)) {
    http_response_code(403);
    echo json_encode(['error' => 'course_disabled']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=120');

try {
    $bundle = \local_xlate\local\api::get_keys_bundle($lang, $keys, $context, $pagetype, $courseparam);
    echo json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(200);
    echo json_encode(['translations' => [], 'sources' => [], 'reviewed' => []]);
}
