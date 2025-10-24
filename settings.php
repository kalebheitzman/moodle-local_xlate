<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $page = new admin_settingpage('local_xlate', get_string('pluginname', 'local_xlate'));
    $page->add(new admin_setting_configcheckbox('local_xlate/enable',
        get_string('enable', 'local_xlate'),
        get_string('enable_desc', 'local_xlate'), 1));

    $page->add(new admin_setting_configtext('local_xlate/languages',
        get_string('languages', 'local_xlate'),
        get_string('languages_desc', 'local_xlate'), 'en,ar'));

    $settings->add($page);
}
