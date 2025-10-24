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

        $settings->add(new admin_setting_configtext('local_xlate/languages',
            get_string('languages', 'local_xlate'),
            get_string('languages_desc', 'local_xlate'), 'en,ar'));
            
        $settings->add(new admin_setting_configtextarea('local_xlate/component_mapping',
            get_string('component_mapping', 'local_xlate'),
            get_string('component_mapping_desc', 'local_xlate'), 
            "path-admin=admin\nblock_=block_\nmod_=mod_\nregion_=region_", PARAM_TEXT));
    }

    $ADMIN->add('localplugins', $settings);
}
