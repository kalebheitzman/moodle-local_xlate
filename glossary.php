<?php
require_once(__DIR__ . '/../../config.php');
require_login();

use local_xlate\glossary as glossary_helper;

$targetlang = optional_param('targetlang', '', PARAM_ALPHANUMEXT);
$PAGE->set_url(new moodle_url('/local/xlate/glossary.php', ['targetlang' => $targetlang]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('glossary', 'local_xlate'));

require_capability('local/xlate:manage', $PAGE->context);

$entries = [];
if ($targetlang) {
    $entries = glossary_helper::get_by_target($targetlang);
}

echo $OUTPUT->header();

echo html_writer::tag('h2', get_string('glossary', 'local_xlate'));

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url]);
echo html_writer::label(get_string('glossary_targetlang_label', 'local_xlate') . ':', 'targetlang');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'targetlang', 'value' => $targetlang]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('glossary_filter', 'local_xlate')]);
echo html_writer::end_tag('form');

if (!$targetlang) {
    echo html_writer::tag('p', get_string('glossary_specify_target', 'local_xlate'));
} else {
    if (empty($entries)) {
        echo html_writer::tag('p', get_string('glossary_no_entries', 'local_xlate', s($targetlang)));
    } else {
        $table = new html_table();
        $table->head = [get_string('glossary_source', 'local_xlate'), get_string('glossary_target', 'local_xlate'), get_string('glossary_created', 'local_xlate')];
        foreach ($entries as $e) {
            $table->data[] = [s($e->source_text), s($e->target_text), userdate($e->ctime)];
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
