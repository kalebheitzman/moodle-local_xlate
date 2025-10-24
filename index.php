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

echo $OUTPUT->header();
echo $OUTPUT->heading('Xlate (Moodle 5+)');
echo html_writer::div('Minimal placeholder UI. Add CRUD for keys/translations and a "Rebuild bundles" action.');
echo $OUTPUT->footer();
