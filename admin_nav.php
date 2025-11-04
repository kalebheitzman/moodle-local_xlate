<?php
/**
 * Renders the local_xlate admin navigation tabs in Moodle admin style.
 * Usage: include_once(__DIR__ . '/admin_nav.php'); local_xlate_render_admin_nav('usage');
 * @param string $active One of: manage, glossary, usage, settings
 */
function local_xlate_render_admin_nav($active = '') {
    global $CFG;
    $tabs = [
        'manage' => [
            'url' => new moodle_url('/local/xlate/manage.php'),
            'label' => 'Manage',
        ],
        'glossary' => [
            'url' => new moodle_url('/local/xlate/glossary.php'),
            'label' => 'Glossary',
        ],
        'usage' => [
            'url' => new moodle_url('/local/xlate/usage.php'),
            'label' => 'Usage',
        ],
        'settings' => [
            'url' => new moodle_url('/admin/settings.php', ['section' => 'local_xlate']),
            'label' => get_string('settings', 'core'),
        ],
    ];
    echo html_writer::start_div('mb-4');
    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs']);
    foreach ($tabs as $key => $tab) {
        $isactive = ($active === $key);
        echo html_writer::tag('li',
            html_writer::link($tab['url'], $tab['label'], [
                'class' => 'nav-link' . ($isactive ? ' active' : '')
            ]),
            ['class' => 'nav-item']
        );
    }
    echo html_writer::end_tag('ul');
    echo html_writer::end_div();
}
