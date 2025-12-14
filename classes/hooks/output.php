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
use moodle_page;

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
            self::debug('Skipping head injection: plugin disabled');
            return;
        }

        $page = self::resolve_page($hook->renderer->get_page() ?? null);
        $reason = null;
        if (self::should_skip_page($page, $reason)) {
            self::debug('Skipping head injection', [
                'reason' => $reason,
                'pagetype' => $page?->pagetype,
                'url' => $page?->url ? $page->url->out(false) : null,
            ]);
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
            self::debug('Skipping body injection: plugin disabled');
            return;
        }

        $page = self::resolve_page($hook->renderer->get_page() ?? null);
        $reason = null;
        if (self::should_skip_page($page, $reason)) {
            self::debug('Skipping body injection', [
                'reason' => $reason,
                'pagetype' => $page?->pagetype,
                'url' => $page?->url ? $page->url->out(false) : null,
            ]);
            return;
        }

        if (!$page || !$page->context) {
            self::debug('Skipping body injection: missing page context', [
                'reason' => $reason,
            ]);
            return;
        }

        // Get context information from the current page
        $contextid = $page->context->id;
        $pagetype = $page->pagetype;
        $courseid = 0;

        // Extract course ID based on context
        if ($page->context->contextlevel == CONTEXT_COURSE) {
            $courseid = $page->context->instanceid;
        } else if ($page->context->contextlevel == CONTEXT_MODULE) {
            // For activity contexts, get the course from the course module
            $cm = get_coursemodule_from_id('', $page->context->instanceid);
            if ($cm) {
                $courseid = $cm->course;
            }
        } else if (isset($page->course) && $page->course->id > 1) {
            // Fallback to $page->course if available and not site course
            $courseid = $page->course->id;
        }

        $lang = current_language();

        $langconfig = \local_xlate\customfield_helper::resolve_languages($courseid ?: null);
        $source_lang = $langconfig['source'];
        $target_langs = $langconfig['targets'];
        $enabled_langs = $langconfig['enabled'];
        $capture_source_lang = $source_lang;
        $version = \local_xlate\local\api::get_version($lang);
        $autodetect = (bool)get_config('local_xlate', 'autodetect');
        $isediting = (isset($page) && method_exists($page, 'user_is_editing') && $page->user_is_editing());
        $istargetlang = ($lang !== $capture_source_lang) && (empty($target_langs) || in_array($lang, $target_langs, true));

        $stringmanager = get_string_manager();
        $languageoptions = $stringmanager->get_list_of_languages();
        if (empty($languageoptions)) {
            $languageoptions = $stringmanager->get_list_of_translations(true);
        }

        $languagecodes = array_keys($languageoptions);
        if (!empty($enabled_langs)) {
            $languagecodes = array_values(array_unique(array_merge([$lang], array_values(array_intersect($languagecodes, $enabled_langs)))));
        }
        $languageentries = [];
        if (!empty($languagecodes)) {
            $baseurl = ($page && $page->url) ? $page->url : new \moodle_url('/');
            foreach ($languagecodes as $code) {
                $langurl = clone $baseurl;
                $langurl->param('lang', $code);
                $label = self::format_language_label($code, (string)($languageoptions[$code] ?? $code), $stringmanager);
                $languageentries[] = [
                    'code' => $code,
                    'label' => $label,
                    'url' => $langurl->out(false)
                ];
            }
        }
        $currentlanguage = null;
        foreach ($languageentries as $entry) {
            if ($entry['code'] === $lang) {
                $currentlanguage = $entry['label'];
                break;
            }
        }
        if ($currentlanguage === null) {
            $currentlanguage = self::format_language_label($lang, (string)($languageoptions[$lang] ?? $lang), $stringmanager);
        }
        $language_switcher = [
            'enabled' => count($languageentries) > 1,
            'current' => $lang,
            'languages' => $languageentries,
            'ariaLabel' => get_string('langswitcher_aria_label', 'local_xlate'),
            'toggleAriaLabel' => get_string('langswitcher_toggle_aria_label', 'local_xlate', (object)['language' => $currentlanguage]),
            'translationToggle' => [
                'enabled' => $istargetlang,
                'label' => get_string('langswitcher_notice_label', 'local_xlate'),
                'originalLabel' => get_string('langswitcher_notice_original', 'local_xlate'),
                'hoverShowOriginal' => get_string('langswitcher_notice_hover_show_original', 'local_xlate'),
                'hoverShowTranslated' => get_string('langswitcher_notice_hover_show_translated', 'local_xlate'),
                'tooltip' => get_string('langswitcher_notice_tooltip', 'local_xlate'),
                'help' => get_string('langswitcher_notice_help', 'local_xlate')
            ]
        ];

        // Output capture/exclude selectors as global JS variables
        $capture_selectors = get_config('local_xlate', 'capture_selectors');
        $exclude_selectors = get_config('local_xlate', 'exclude_selectors');
        $debugflag = (defined('DEBUG_DEVELOPER') && (debugging() & DEBUG_DEVELOPER)) ? 'true' : 'false';

        $selectors_script = '<script>'
            . 'window.XLATE_CAPTURE_SELECTORS = ' . json_encode($capture_selectors ? preg_split('/\r?\n/', $capture_selectors, -1, PREG_SPLIT_NO_EMPTY) : []) . "\n"
            . 'window.XLATE_EXCLUDE_SELECTORS = ' . json_encode($exclude_selectors ? preg_split('/\r?\n/', $exclude_selectors, -1, PREG_SPLIT_NO_EMPTY) : []) . "\n"
            . 'window.XLATE_COURSEID = ' . json_encode($courseid) . "\n"
            . 'window.XLATE_DEBUG = ' . $debugflag . "\n"
            . 'window.XLATE_LANG_SWITCHER = ' . json_encode($language_switcher, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
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
            'sesskey' => sesskey(),
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
            'languageSwitcher' => $language_switcher,
            'showInlineIndicators' => false,
        ];

        $script = '<script>'
            . '(function(){'
            . 'function initTranslator() {'
            . 'if (typeof console !== "undefined" && typeof console.debug === "function") {'
            . 'console.debug(' . json_encode('XLATE Initializing') . ', ' . json_encode($debugcontext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');'
            . '}'
            . 'if (typeof require !== "undefined" && typeof M !== "undefined" && M.cfg) {'
            . 'require(["local_xlate/langswitcher","local_xlate/translator"], function(langSwitcher, translator) {'
            . 'langSwitcher.init(window.XLATE_LANG_SWITCHER || null);'
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

        self::debug('Translator bootstrap injected', [
            'contextid' => $contextid,
            'courseid' => $courseid,
            'pagetype' => $pagetype,
            'lang' => $lang,
            'isediting' => $isediting,
        ]);
    }

    /**
     * Determine whether the current request targets an admin/editing context.
     *
     * @return bool True when translation injection should be skipped.
     */
    private static function should_skip_page(?moodle_page $page = null, ?string &$reason = null): bool {
        global $PAGE;

        $page = $page ?? ($PAGE ?? null);

        if (!$page) {
            $reason = 'no_page_context';
            return true;
        }

        // Skip obvious admin/maintenance layouts before any path checks.
        $blockedlayouts = ['admin', 'maintenance', 'popup', 'report', 'coursecategory'];
        if (!empty($page->pagelayout) && in_array($page->pagelayout, $blockedlayouts, true)) {
            $reason = 'blocked_layout_' . $page->pagelayout;
            return true;
        }

        $path = null;
        if (!empty($page->url)) {
            $path = $page->url->get_path();
        } else {
            $path = self::detect_request_path();
        }

        if ($path === null) {
            $path = '';
        }

        $blockedpaths = self::get_excluded_paths();
        foreach ($blockedpaths as $prefix) {
            if ($prefix !== '' && strpos($path, $prefix) === 0) {
                $reason = 'blocked_path_' . $prefix;
                return true;
            }
        }

        // Skip when the viewer is in editing mode or explicitly toggles editing.
        if (method_exists($page, 'user_is_editing') && $page->user_is_editing()) {
            $reason = 'editing_mode';
            return true;
        }
        if (optional_param('edit', 0, PARAM_BOOL)) {
            $reason = 'edit_param';
            return true;
        }

        // Avoid translating non-front-page system context screens.
        $context = $page->context ?? null;
        if ($context && $context->contextlevel == CONTEXT_SYSTEM && $page->pagetype !== 'site-index') {
            $reason = 'system_context_' . $page->pagetype;
            return true;
        }

        $reason = 'ok';
        return false;
    }

    /**
     * Emit debug output when Moodle developer debugging (or plugin flag) is enabled.
     */
    private static function debug(string $message, array $context = []): void {
        $enabled = debugging('', DEBUG_DEVELOPER) || (bool)get_config('local_xlate', 'debughooks');
        if (!$enabled) {
            return;
        }
        $payload = $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        error_log('[local_xlate] ' . $message . ($payload ? ' ' . $payload : ''));
    }

    /**
     * Resolve the configured URL prefixes that should skip translator injection.
     */
    private static function get_excluded_paths(): array {
        $configured = get_config('local_xlate', 'excluded_paths');
        $paths = [];
        if (!empty($configured)) {
            $paths = preg_split('/\r?\n/', $configured, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (empty($paths)) {
            $paths = self::default_excluded_paths();
        }
        $normalized = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . ltrim($path, '/');
            }
            $normalized[] = rtrim($path);
        }
        return array_unique($normalized);
    }

    /**
     * Return the most user-friendly label for a language code.
     *
     * Prefers the language's autonym from langconfig ("thislanguage") and
     * falls back to the provided default label with any trailing aliases
     * (separated by semicolons) stripped.
     *
     * @param string $code Moodle language code (e.g. en, ro)
     * @param string $fallback Default label to use when no autonym is found
     * @param \core_string_manager $manager Moodle string manager instance
     * @return string
     */
    private static function format_language_label(string $code, string $fallback, \core_string_manager $manager): string {
        $label = trim($fallback);
        if ($label === '') {
            $label = $code;
        }

        if (strpos($label, ';') !== false) {
            $parts = explode(';', $label);
            $label = trim($parts[0]);
        }

        try {
            $autonym = $manager->get_string('thislanguage', 'langconfig', null, $code);
            $autonym = trim((string)$autonym);
            if ($autonym !== '') {
                return $autonym;
            }
        } catch (\Throwable $e) {
            // Ignore failures and fall back to the derived label.
        }

        return $label;
    }

    /**
     * Built-in safety list applied when the admin config is empty.
     */
    private static function default_excluded_paths(): array {
        return [
            '/admin/',
            '/local/xlate/',
            '/course/edit.php',
            '/course/editsection.php',
            '/course/modedit.php',
            '/course/mod.php',
            '/course/modsection.php',
            '/grade/edit/',
            '/backup/',
            '/restore/',
            '/report/',
            '/user/edit.php',
            '/user/editadvanced.php',
            '/user/preferences.php',
            '/question/edit.php',
            '/cohort/edit.php',
            '/badges/edit.php',
            '/enrol/',
        ];
    }

    /**
     * Prefer the renderer's moodle_page when the global is not yet initialised.
     */
    private static function resolve_page(?moodle_page $candidate): ?moodle_page {
        global $PAGE;

        if ($candidate) {
            return $candidate;
        }

        return $PAGE ?? null;
    }

    /**
     * Best-effort request path detection when $PAGE->url is not yet available.
     */
    private static function detect_request_path(): ?string {
        if (!empty($_SERVER['REQUEST_URI'])) {
            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (is_string($uri)) {
                return $uri;
            }
        }

        if (!empty($_SERVER['SCRIPT_NAME'])) {
            return $_SERVER['SCRIPT_NAME'];
        }

        return null;
    }
}