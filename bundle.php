<?php
require_once(__DIR__ . '/../../config.php');
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/classes/local/api.php');

$PAGE->set_context(context_system::instance());
$lang = optional_param('lang', current_language(), PARAM_ALPHANUMEXT);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');

$bundle = \local_xlate\local\api::get_bundle($lang);
echo json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
