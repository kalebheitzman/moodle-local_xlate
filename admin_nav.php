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
 * Admin navigation tabs for the Local Xlate plugin.
 *
 * Builds a Bootstrap nav bar for the plugin’s administrative pages so the
 * active tab can be highlighted consistently across screens.
 *
 * @package    local_xlate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Build the local_xlate admin navigation tabs HTML.
 *
 * @param string $active One of: manage, glossary, usage, settings
 * @return string
 */
function local_xlate_admin_nav_html($active = ''): string {
    $tabs = [
        'manage' => [
            'url' => new moodle_url('/local/xlate/manage.php'),
            'label' => get_string('nav_manage', 'local_xlate'),
        ],
        'glossary' => [
            'url' => new moodle_url('/local/xlate/glossary.php'),
            'label' => get_string('nav_glossary', 'local_xlate'),
        ],
        'usage' => [
            'url' => new moodle_url('/local/xlate/usage.php'),
            'label' => get_string('nav_usage', 'local_xlate'),
        ],
        'settings' => [
            'url' => new moodle_url('/admin/settings.php', ['section' => 'local_xlate']),
            'label' => get_string('settings', 'core'),
        ],
    ];
    $output = html_writer::start_div('mb-4');
    $output .= html_writer::start_tag('ul', ['class' => 'nav nav-tabs']);
    foreach ($tabs as $key => $tab) {
        $isactive = ($active === $key);
        $output .= html_writer::tag('li',
            html_writer::link($tab['url'], $tab['label'], [
                'class' => 'nav-link' . ($isactive ? ' active' : '')
            ]),
            ['class' => 'nav-item']
        );
    }
    $output .= html_writer::end_tag('ul');
    $output .= html_writer::end_div();
    return $output;
}

/**
 * Echo the admin nav tabs directly (legacy helper).
 */
function local_xlate_render_admin_nav($active = ''): void {
    echo local_xlate_admin_nav_html($active);
}
