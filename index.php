<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/xlate:viewui', $context);

$PAGE->set_url(new moodle_url('/local/xlate/index.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'local_xlate'));
$PAGE->set_heading(get_string('pluginname', 'local_xlate'));

// Check if user can manage translations
$canmanage = has_capability('local/xlate:manage', $context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_xlate'));

if ($canmanage) {
    echo html_writer::div(
        html_writer::tag('p', get_string('autodetect_intro', 'local_xlate')) .
        html_writer::tag('button', get_string('autodetect_enable', 'local_xlate'), [
            'id' => 'xlate-autodetect-enable',
            'class' => 'btn btn-success',
            'data-action' => 'enable-autodetect'
        ]) .
        html_writer::tag('button', get_string('autodetect_disable', 'local_xlate'), [
            'id' => 'xlate-autodetect-disable',
            'class' => 'btn btn-warning ml-2',
            'data-action' => 'disable-autodetect'
        ]),
        'xlate-autodetect-controls mb-4'
    );
    
    echo html_writer::div(
        html_writer::tag('p', get_string('rebuild_bundles_desc', 'local_xlate')) .
        html_writer::tag('button', get_string('rebuild_bundles', 'local_xlate'), [
            'id' => 'xlate-rebuild-bundles',
            'class' => 'btn btn-warning',
            'data-action' => 'rebuild-bundles'
        ]),
        'xlate-rebuild-controls mb-4'
    );
    
    echo html_writer::div(
        html_writer::tag('h4', get_string('autodetect_status', 'local_xlate')) .
        html_writer::tag('p', get_string('autodetect_status_desc', 'local_xlate'), [
            'id' => 'xlate-status-text'
        ]),
        'xlate-status-display mb-4'
    );
    
    // Translation Management Section
    echo html_writer::div(
        html_writer::tag('h4', get_string('manage_translations', 'local_xlate')) .
        html_writer::tag('p', get_string('manage_translations_desc', 'local_xlate')) .
        html_writer::tag('a', get_string('view_manage_translations', 'local_xlate'), [
            'href' => new moodle_url('/local/xlate/manage.php'),
            'class' => 'btn btn-primary'
        ]),
        'xlate-manage-section mb-4'
    );
    
    echo html_writer::script("
        require(['jquery', 'local_xlate/translator', 'core/ajax', 'core/notification'], function($, Translator, Ajax, Notification) {
            
            // Check current auto-detect status
            function updateStatus() {
                $('#xlate-status-text').text('Auto-detection is currently active on this page.');
            }
            
            $('#xlate-autodetect-enable').on('click', function() {
                Translator.setAutoDetect(true);
                Notification.addNotification({
                    message: 'Automatic string detection enabled',
                    type: 'success'
                });
                updateStatus();
            });
            
            $('#xlate-autodetect-disable').on('click', function() {
                Translator.setAutoDetect(false);
                Notification.addNotification({
                    message: 'Automatic string detection disabled',
                    type: 'info'
                });
                $('#xlate-status-text').text('Auto-detection is currently disabled.');
            });
            
            $('#xlate-rebuild-bundles').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Rebuilding...');
                
                Ajax.call([{
                    methodname: 'local_xlate_rebuild_bundles',
                    args: {}
                }]).then(function(results) {
                    var result = results[0];
                    Notification.addNotification({
                        message: 'Rebuilt bundles for languages: ' + result.rebuilt.join(', '),
                        type: 'success'
                    });
                }).catch(function(error) {
                    Notification.addNotification({
                        message: 'Error rebuilding bundles: ' + error.message,
                        type: 'error'
                    });
                }).always(function() {
                    button.prop('disabled', false).text('" . get_string('rebuild_bundles', 'local_xlate') . "');
                });
            });
            
            // Initialize status
            updateStatus();
        });
    ");
} else {
    echo html_writer::div(
        html_writer::tag('p', get_string('nomanagepermission', 'local_xlate')),
        'alert alert-warning'
    );
}

echo html_writer::div(
    html_writer::tag('h3', get_string('about', 'local_xlate')) .
    html_writer::tag('p', get_string('about_desc', 'local_xlate'))
);

echo $OUTPUT->footer();
