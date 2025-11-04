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

// Require authentication
require_login();

// Get parameters
$lang = optional_param('lang', current_language(), PARAM_ALPHANUMEXT);
$contextid = optional_param('contextid', SYSCONTEXTID, PARAM_INT);
$pagetype = optional_param('pagetype', '', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);

// Clean pagetype to ensure it's safe
$pagetype = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $pagetype);

// Validate context and permissions
try {
    $context = context::instance_by_id($contextid);
    
    // Check basic view permission for the context
    if ($context->contextlevel == CONTEXT_COURSE) {
        require_capability('moodle/course:view', $context);
    } else if ($context->contextlevel == CONTEXT_MODULE) {
        require_capability('moodle/course:view', $context);
    }
} catch (Exception $e) {
    // Fall back to system context for invalid context IDs
    $context = context_system::instance();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=3600');

try {
    // If POST with JSON body of keys is provided, return a flat map of only those keys
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (is_array($data) && !empty($data['keys']) && is_array($data['keys'])) {
            $map = \local_xlate\local\api::get_keys_bundle($lang, $data['keys'], $context, $pagetype, $courseid);
            // Shorter cache for key-specific bundles
            header('Cache-Control: private, max-age=120');
            echo json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    // Fallback: page bundle (legacy/full)
    $bundle = \local_xlate\local\api::get_page_bundle($lang, $pagetype, $context, $USER, $courseid);
    echo json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    // If there's an error, return empty bundle instead of fatal error
    http_response_code(200);
    echo json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
