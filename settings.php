<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    global $ADMIN;

    $settings = new admin_settingpage('local_xlate', get_string('pluginname', 'local_xlate'));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox('local_xlate/enable',
            get_string('enable', 'local_xlate'),
            get_string('enable_desc', 'local_xlate'), 1));

        $settings->add(new admin_setting_configcheckbox('local_xlate/autodetect',
            get_string('autodetect', 'local_xlate'),
            get_string('autodetect_desc', 'local_xlate'), 1));

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

        $settings->add(new admin_setting_configtextarea('local_xlate/component_mapping',
            get_string('component_mapping', 'local_xlate'),
            get_string('component_mapping_desc', 'local_xlate'),
            "path-admin=admin\nblock_=block_\nmod_=mod_\nregion_=region_", PARAM_TEXT));

        // Add link to translation management
        $manage_url = new moodle_url('/local/xlate/manage.php');
        $settings->add(new admin_setting_heading('local_xlate_manage_heading',
            get_string('manage_translations', 'local_xlate'),
            html_writer::tag('p', get_string('manage_translations_desc', 'local_xlate')) .
            html_writer::link($manage_url, get_string('view_manage_translations', 'local_xlate'), [
                'class' => 'btn btn-primary'
            ])
        ));
    }

    $ADMIN->add('localplugins', $settings);

    // Add the translation management page to the admin navigation
    if ($hassiteconfig) {
        $ADMIN->add('localplugins', new admin_externalpage(
            'local_xlate_manage',
            get_string('manage_translations', 'local_xlate'),
            new moodle_url('/local/xlate/manage.php'),
            'local/xlate:manage'
        ));
    }
}
