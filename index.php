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
        html_writer::tag('p', get_string('capturemode_intro', 'local_xlate')) .
        html_writer::tag('button', get_string('capturemode_start', 'local_xlate'), [
            'id' => 'xlate-capture-toggle',
            'class' => 'btn btn-primary',
            'data-action' => 'toggle-capture'
        ]) .
        html_writer::tag('button', get_string('capturemode_stop', 'local_xlate'), [
            'id' => 'xlate-capture-stop',
            'class' => 'btn btn-secondary ml-2',
            'data-action' => 'stop-capture',
            'style' => 'display: none;'
        ]),
        'xlate-capture-controls mb-4'
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
    
    // Include capture mode JavaScript
    $PAGE->requires->js_call_amd('local_xlate/capture', 'init');
    
    echo html_writer::script("
        require(['jquery', 'local_xlate/capture', 'core/ajax', 'core/notification'], function($, Capture, Ajax, Notification) {
            $('#xlate-capture-toggle').on('click', function() {
                Capture.enter();
                $(this).hide();
                $('#xlate-capture-stop').show();
            });
            
            $('#xlate-capture-stop').on('click', function() {
                Capture.exit();
                $(this).hide();
                $('#xlate-capture-toggle').show();
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
