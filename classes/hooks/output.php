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
 * Output hooks for Local Xlate.
 *
 * Injects the translator bootstrap assets into Moodle pages prior to head and
 * body rendering while respecting admin screens and edit mode.
 *
 * @package    local_xlate
 * @category   hooks
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\hooks;

defined('MOODLE_INTERNAL') || die();

use core\hook\output\before_standard_head_html_generation as Head;
use core\hook\output\before_standard_top_of_body_html_generation as Body;

/**
 * Registers frontend bootstrapping for the translator on Moodle page renders.
 *
 * Responds to core output hooks to inject minimal CSS/JS needed to lazy-load
 * the translator module. Respects admin pages, editing mode, and plugin
 * enablement settings to avoid interfering with core workflows.
 *
 * @package local_xlate\hooks
 */
class output {
    /**
     * Inject head-level assets before Moodle renders the standard head HTML.
     *
     * @param Head $hook Output hook instance used to append HTML.
     * @return void
     */
    public static function before_head(Head $hook): void {
        if (!get_config('local_xlate', 'enable')) { 
            return; 
        }
        
        // Don't inject on admin pages - they should use Moodle language strings
        if (self::is_admin_path()) {
            return;
        }
        
        $hook->add_html('<style>html.xlate-loading body{visibility:hidden}</style>');
        // Debug marker
        $hook->add_html('<!-- XLATE HEAD HOOK FIRED -->');
    }

    /**
     * Inject translator bootstrap script before the body HTML is emitted.
     *
     * Builds contextual metadata (course, pagetype, current language) and
     * publishes it as global variables and bootstrap arguments for the AMD
     * module initialisation.
     *
     * @param Body $hook Output hook instance used to append HTML.
     * @return void
     */
    public static function before_body(Body $hook): void {
        if (!get_config('local_xlate', 'enable')) { 
            return; 
        }
        
        // Don't inject on admin pages - they should use Moodle language strings
        if (self::is_admin_path()) {
            return;
        }
        
        // Get context information from the current page
        global $PAGE;
        $contextid = $PAGE->context->id;
        $pagetype = $PAGE->pagetype;
        $courseid = 0;

        // Extract course ID based on context
        if ($PAGE->context->contextlevel == CONTEXT_COURSE) {
            $courseid = $PAGE->context->instanceid;
        } else if ($PAGE->context->contextlevel == CONTEXT_MODULE) {
            // For activity contexts, get the course from the course module
            $cm = get_coursemodule_from_id('', $PAGE->context->instanceid);
            if ($cm) {
                $courseid = $cm->course;
            }
        } else if (isset($PAGE->course) && $PAGE->course->id > 1) {
            // Fallback to $PAGE->course if available and not site course
            $courseid = $PAGE->course->id;
        }

        $lang = current_language();
        if ($courseid > 0) {
            // Require an explicit course source language before running the translator.
            $courseconfig = \local_xlate\customfield_helper::get_course_config($courseid);
            if ($courseconfig === null || empty($courseconfig['source'])) {
                return;
            }
        }

        $langconfig = \local_xlate\customfield_helper::resolve_languages($courseid ?: null);
        $source_lang = $langconfig['source'];
        $target_langs = $langconfig['targets'];
        $enabled_langs = $langconfig['enabled'];
        $capture_source_lang = $source_lang;
        $version = \local_xlate\local\api::get_version($lang);
        $autodetect = (bool)get_config('local_xlate', 'autodetect');
        $isediting = (isset($PAGE) && method_exists($PAGE, 'user_is_editing') && $PAGE->user_is_editing());

        // Output capture/exclude selectors as global JS variables
        $capture_selectors = get_config('local_xlate', 'capture_selectors');
        $exclude_selectors = get_config('local_xlate', 'exclude_selectors');
        $debugflag = (defined('DEBUG_DEVELOPER') && (debugging() & DEBUG_DEVELOPER)) ? 'true' : 'false';

        $selectors_script = '<script>'
            . 'window.XLATE_CAPTURE_SELECTORS = ' . json_encode($capture_selectors ? preg_split('/\r?\n/', $capture_selectors, -1, PREG_SPLIT_NO_EMPTY) : []) . ";\n"
            . 'window.XLATE_EXCLUDE_SELECTORS = ' . json_encode($exclude_selectors ? preg_split('/\r?\n/', $exclude_selectors, -1, PREG_SPLIT_NO_EMPTY) : []) . ";\n"
            . 'window.XLATE_COURSEID = ' . json_encode($courseid) . ";\n"
            . 'window.XLATE_DEBUG = ' . $debugflag . ";\n"
            . '</script>';
        $hook->add_html($selectors_script);

        $debugcontext = [
            'lang' => $lang,
            'sourceLang' => $source_lang,
            'targetLangs' => $target_langs,
            'captureSourceLang' => $capture_source_lang,
            'version' => $version,
            'autodetect' => $autodetect,
            'isEditing' => $isediting,
            'courseid' => $courseid,
        ];

        $bundleurl = new \moodle_url('/local/xlate/bundle.php', [
            'lang' => $lang,
            'contextid' => $contextid,
            'pagetype' => $pagetype,
            'courseid' => $courseid,
        ]);

        $initconfig = [
            'lang' => $lang,
            'sourceLang' => $source_lang,
            'captureSourceLang' => $capture_source_lang,
            'targetLangs' => $target_langs,
            'enabledLangs' => $enabled_langs,
            'version' => $version,
            'autodetect' => $autodetect,
            'isEditing' => $isediting,
            'bundleurl' => $bundleurl->out(false),
        ];

        $script = '<script>'
            . '(function(){'
            . 'function initTranslator() {'
            . 'if (typeof console !== "undefined" && typeof console.debug === "function") {'
            . 'console.debug(' . json_encode('XLATE Initializing') . ', ' . json_encode($debugcontext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');'
            . '}'
            . 'if (typeof require !== "undefined" && typeof M !== "undefined" && M.cfg) {'
            . 'require(["local_xlate/translator"], function(translator) {'
            . 'translator.init(' . json_encode($initconfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');'
            . '});'
            . '} else {'
            . 'setTimeout(initTranslator, 100);'
            . '}'
            . '}'
            . 'if (document.readyState === "loading") {'
            . 'document.addEventListener("DOMContentLoaded", initTranslator);'
            . '} else {'
            . 'initTranslator();'
            . '}'
            . '})();'
            . '</script>';
        $hook->add_html($script);
        // Debug marker
        $hook->add_html('<!-- XLATE BODY HOOK FIRED -->');
    }

    /**
     * Determine whether the current request targets an admin or editing path.
     *
     * @return bool True when translation injection should be skipped.
     */
    private static function is_admin_path(): bool {
        global $PAGE;
        
        $url = $PAGE->url->get_path();
        
        // Admin paths that should use Moodle language strings
        $admin_paths = [
            '/admin/',
            '/local/xlate/',
            '/course/modedit.php',
            '/grade/edit/',
            '/backup/',
            '/restore/',
            '/user/editadvanced.php',
            '/user/preferences.php',
            '/my/indexsys.php',
            '/badges/edit.php',
            '/cohort/edit.php',
            '/question/edit.php'
        ];
        
        foreach ($admin_paths as $path) {
            if (strpos($url, $path) === 0) {
                return true;
            }
        }
        
        // Check for editing mode
        if ($PAGE->user_is_editing()) {
            return true;
        }
        
        // Check page context for admin areas
        $context = $PAGE->context;
        if ($context->contextlevel == CONTEXT_SYSTEM && $PAGE->pagetype !== 'site-index') {
            return true;
        }
        
        return false;
    }
}