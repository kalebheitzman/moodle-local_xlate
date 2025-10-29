<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    global $ADMIN;

    $settings = new admin_settingpage('local_xlate', get_string('pluginname', 'local_xlate'));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox('local_xlate/enable',
            get_string('enable', 'local_xlate'),
            get_string('enable_desc', 'local_xlate'), 1));

        // Autodetect config removed: always enabled

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
            'Capture area selectors',
            'Only text within elements matching these CSS selectors will be captured for translation. One selector per line. Leave blank to capture everything.',
            "#region-main\n#page-content\n.main-content\n#page-wrapper\n.format-topics", PARAM_TEXT));

        // Exclude selectors (exclude patterns)
        $settings->add(new admin_setting_configtextarea('local_xlate/exclude_selectors',
            'Exclude selectors',
            'Elements matching these CSS selectors will be excluded from capture, even if inside a capture area. One selector per line. Common defaults included.',
            ".accesshide\n.visually-hidden\n.hidden\n.sr-only", PARAM_TEXT));
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
    }
}
