<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    global $ADMIN;

    $settings = new admin_settingpage('local_xlate', get_string('pluginname', 'local_xlate'));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox('local_xlate/enable',
            get_string('enable', 'local_xlate'),
            get_string('enable_desc', 'local_xlate'), 1));

        $settings->add(new admin_setting_configtext('local_xlate/languages',
            get_string('languages', 'local_xlate'),
            get_string('languages_desc', 'local_xlate'), 'en,ar'));
    }

    $ADMIN->add('localplugins', $settings);
}
