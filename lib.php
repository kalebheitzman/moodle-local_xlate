<?php
// lib.php - navigation hook for local_xlate
// Adds a "Manage Translations" link to the course navigation (More menu)

defined('MOODLE_INTERNAL') || die();

/**
 * Extend the course navigation with a Manage Translations link.
 *
 * This will appear in the course More menu for users who have the
 * 'local/xlate:manage' capability at system or course context.
 *
 * @param navigation_node $navigation The navigation node for the course
 * @param stdClass $course The course object
 * @param context $context The course context
 */
function local_xlate_extend_navigation_course($navigation, $course, $context) {
    if (!
        get_config('local_xlate', 'enable')
    ) {
        return;
    }

    // Only show to users with the site manage capability or the course-level manage capability.
    $systemcontext = context_system::instance();
    if (!has_capability('local/xlate:manage', $systemcontext) && !has_capability('local/xlate:managecourse', $context)) {
        return;
    }

    // Add the navigation node. It will show under course navigation (More menu).
    $url = new moodle_url('/local/xlate/manage.php', ['courseid' => $course->id]);
    $text = get_string('manage_translations', 'local_xlate');
    // Use TYPE_SETTING to have it appear as a course setting link.
    $navigation->add($text, $url, navigation_node::TYPE_SETTING);
}
