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
 * Admin settings for the Local Xlate plugin.
 *
 * Registers configuration controls that let administrators tune capture,
 * autotranslation, and glossary behaviour via Moodle’s settings tree.
 *
 * @package    local_xlate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    global $ADMIN;

    $settings = new admin_settingpage('local_xlate', get_string('pluginname', 'local_xlate'));

    if ($ADMIN->fulltree) {
        // --- Scheduled Autotranslate Task --------------------------------
        $settings->add(new admin_setting_configcheckbox('local_xlate/autotranslate_task_enabled',
            get_string('autotranslate_task_enable', 'local_xlate'),
            get_string('autotranslate_task_enable_desc', 'local_xlate'), 0));
        // --- General settings ------------------------------------------------
        $settings->add(new admin_setting_heading('local_xlate/generalheading', '', get_string('settings_intro', 'local_xlate')));

        $settings->add(new admin_setting_configcheckbox('local_xlate/enable',
            get_string('enable', 'local_xlate'),
            get_string('enable_desc', 'local_xlate'), 1));

        // --- OpenAI / Autotranslation ---------------------------------------
        $settings->add(new admin_setting_heading('local_xlate/autotranslateheading', '', get_string('autotranslate_heading', 'local_xlate')));

        // Checkbox to enable/disable automatic translations via AI
        $settings->add(new admin_setting_configcheckbox('local_xlate/autotranslate_enabled',
            get_string('autotranslate_enable', 'local_xlate'),
            get_string('autotranslate_enable_desc', 'local_xlate'), 0));

        // OpenAI endpoint (allows self-hosted or proxied endpoints)
        $settings->add(new admin_setting_configtext('local_xlate/openai_endpoint',
            get_string('openai_endpoint', 'local_xlate'),
            get_string('openai_endpoint_desc', 'local_xlate'),
            'https://api.openai.com/v1/chat/completions', PARAM_URL));

        // API key (masked)
        $settings->add(new admin_setting_configpasswordunmask('local_xlate/openai_api_key',
            get_string('openai_api_key', 'local_xlate'),
            get_string('openai_api_key_desc', 'local_xlate'), ''));

        // Model selection
        $settings->add(new admin_setting_configtext('local_xlate/openai_model',
            get_string('openai_model', 'local_xlate'),
            get_string('openai_model_desc', 'local_xlate'), 'gpt-4.1', PARAM_RAW));

        // Pricing for usage estimation
        $settings->add(new admin_setting_heading('local_xlate/pricingheading', '',
            get_string('pricing_heading', 'local_xlate')));

        $settings->add(new \local_xlate\admin\setting\pricing('local_xlate/pricing_input_per_million',
            get_string('pricing_input_per_million', 'local_xlate'),
            get_string('pricing_input_per_million_desc', 'local_xlate'), '2.00'));

        $settings->add(new \local_xlate\admin\setting\pricing('local_xlate/pricing_cached_input_per_million',
            get_string('pricing_cached_input_per_million', 'local_xlate'),
            get_string('pricing_cached_input_per_million_desc', 'local_xlate'), '0.50'));

        $settings->add(new \local_xlate\admin\setting\pricing('local_xlate/pricing_output_per_million',
            get_string('pricing_output_per_million', 'local_xlate'),
            get_string('pricing_output_per_million_desc', 'local_xlate'), '8.00'));

        // System prompt / translation instructions
        // Escape $ in example placeholders so PHP does not attempt to interpolate an undefined variable.
    $defaultprompt = get_string('openai_prompt_default', 'local_xlate');

        $settings->add(new admin_setting_configtextarea('local_xlate/openai_prompt',
            get_string('openai_prompt', 'local_xlate'),
            get_string('openai_prompt_desc', 'local_xlate'),
            $defaultprompt, PARAM_TEXT));

        // --- Language and capture settings ----------------------------------
        $settings->add(new admin_setting_heading('local_xlate/langheading', '', get_string('language_heading', 'local_xlate')));

        // Get installed languages and create checkboxes for each
        $installedlangs = get_string_manager()->get_list_of_translations();
        $enabledlangs = get_config('local_xlate', 'enabled_languages');
        $enabledlangsarray = empty($enabledlangs) ? ['en'] : explode(',', $enabledlangs);

        $langchoices = [];
        foreach ($installedlangs as $langcode => $langname) {
            $langchoices[$langcode] = $langname . ' (' . $langcode . ')';
        }

        $settings->add(new admin_setting_configmulticheckbox('local_xlate/enabled_languages',
            get_string('enabled_languages', 'local_xlate'),
            get_string('enabled_languages_desc', 'local_xlate'),
            array_fill_keys($enabledlangsarray, 1),
            $langchoices));


        // Capture area selectors (include patterns)
        $settings->add(new admin_setting_configtextarea('local_xlate/capture_selectors',
            get_string('capture_selectors', 'local_xlate'),
            get_string('capture_selectors_desc', 'local_xlate'),
            ".drawer\n#page", PARAM_TEXT));

        // Exclude selectors (exclude patterns)
        $settings->add(new admin_setting_configtextarea('local_xlate/exclude_selectors',
            get_string('exclude_selectors', 'local_xlate'),
            get_string('exclude_selectors_desc', 'local_xlate'),
            "#page-header\n.secondary-navigation\n.nav-drawer\n.navbar\n.page-context-header\n.page-footer\n.breadcrumb\n.discussion-list\n.discussionname\n.forum-post-container\n.journaluserentrywrapper\n.path-mod-forum\n.path-admin\n.usermenu\n._jswarning\n.toast\n.alert\n.badge", PARAM_TEXT));
    }

    $ADMIN->add('localplugins', $settings);

    // Add the translation management and glossary pages to the admin navigation
    if ($hassiteconfig) {
        $ADMIN->add('localplugins', new admin_externalpage(
            'local_xlate_manage',
            get_string('admin_manage_translations', 'local_xlate'),
            new moodle_url('/local/xlate/manage.php'),
            'local/xlate:manage'
        ));

        $ADMIN->add('localplugins', new admin_externalpage(
            'local_xlate_glossary',
            get_string('admin_manage_glossary', 'local_xlate'),
            new moodle_url('/local/xlate/glossary.php'),
            'local/xlate:manage'
        ));

        $ADMIN->add('localplugins', new admin_externalpage(
            'local_xlate_usage',
            get_string('admin_usage', 'local_xlate'),
            new moodle_url('/local/xlate/usage.php'),
            'local/xlate:manage'
        ));
    }
}
