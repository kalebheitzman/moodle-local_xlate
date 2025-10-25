<?php
require_once(__DIR__ . '/../../config.php');
defined('MOODLE_INTERNAL') || die();

// Require authentication
require_login();

// Get parameters
$lang = optional_param('lang', current_language(), PARAM_ALPHANUMEXT);
$contextid = optional_param('contextid', SYSCONTEXTID, PARAM_INT);
$pagetype = optional_param('pagetype', '', PARAM_ALPHANUMEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);

// Validate context and permissions
try {
    $context = context::instance_by_id($contextid);
    $PAGE->set_context($context);
    
    // Check basic view permission for the context
    if ($context->contextlevel == CONTEXT_COURSE) {
        require_capability('moodle/course:view', $context);
    } else if ($context->contextlevel == CONTEXT_MODULE) {
        require_capability('moodle/course:view', $context);
    }
} catch (Exception $e) {
    // Fall back to system context for invalid context IDs
    $context = context_system::instance();
    $PAGE->set_context($context);
}

header('Content-Type: application/json; charset=utf-8');
// Shorter cache for authenticated content
header('Cache-Control: private, max-age=3600');

$bundle = \local_xlate\local\api::get_page_bundle($lang, $pagetype, $context, $USER, $courseid);
echo json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
