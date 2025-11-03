<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/xlate:manage', \context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/xlate/usage.php'));
$PAGE->set_title('Xlate Token Usage');
$PAGE->set_heading('Xlate Token Usage');

global $DB, $OUTPUT;

// Filters and pagination.
$langfilter = optional_param('lang', '', PARAM_ALPHANUMEXT);
$modelfilter = optional_param('model', '', PARAM_RAW);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 25, PARAM_INT);

$params = [];
$where = [];
if ($langfilter !== '') {
    $where[] = 'lang = :lang';
    $params['lang'] = $langfilter;
}
if ($modelfilter !== '') {
    $where[] = 'model = :model';
    $params['model'] = $modelfilter;
}
$wheresql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = $DB->count_records_select('local_xlate_token_usage', $where ? implode(' AND ', $where) : '', $params);
$totaltokens = $DB->get_field_sql('SELECT SUM(tokens) FROM {local_xlate_token_usage} ' . $wheresql, $params);

$usages = $DB->get_records_sql('SELECT * FROM {local_xlate_token_usage} ' . $wheresql . ' ORDER BY timecreated DESC', $params, $page * $perpage, $perpage);

// Get distinct languages and models for filter dropdowns.
$langs = $DB->get_fieldset_sql('SELECT DISTINCT lang FROM {local_xlate_token_usage} ORDER BY lang');
$models = $DB->get_fieldset_sql('SELECT DISTINCT model FROM {local_xlate_token_usage} WHERE model IS NOT NULL AND model != "" ORDER BY model');

echo $OUTPUT->header();
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', 'Token Usage', ['class' => 'mb-3']);
echo html_writer::tag('p', 'This page shows token usage for all autotranslation requests. Use the filters to narrow results by language or model.');
echo html_writer::start_tag('ul', ['class' => 'list-unstyled mb-3']);
echo html_writer::tag('li', '<strong>Total requests:</strong> ' . (int)$total);
echo html_writer::tag('li', '<strong>Total tokens:</strong> ' . (int)$totaltokens);
echo html_writer::end_tag('ul');

// Filter form
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-3']);
echo html_writer::start_div('form-group mr-2');
echo html_writer::label('Language', 'lang', false, ['class' => 'mr-1']);
echo html_writer::select(array_merge([''=>'All'], array_combine($langs, $langs)), 'lang', $langfilter, null, ['class'=>'custom-select mr-2', 'id'=>'lang']);
echo html_writer::end_div();
echo html_writer::start_div('form-group mr-2');
echo html_writer::label('Model', 'model', false, ['class' => 'mr-1']);
echo html_writer::select(array_merge([''=>'All'], array_combine($models, $models)), 'model', $modelfilter, null, ['class'=>'custom-select mr-2', 'id'=>'model']);
echo html_writer::end_div();
echo html_writer::empty_tag('input', ['type'=>'submit', 'class'=>'btn btn-primary', 'value'=>'Filter']);
echo html_writer::end_tag('form');

// Table
echo html_writer::start_div('table-responsive');
echo html_writer::start_tag('table', ['class' => 'table table-striped table-hover']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag('th', 'Time');
echo html_writer::tag('th', 'Language');
echo html_writer::tag('th', 'Key');
echo html_writer::tag('th', 'Tokens');
echo html_writer::tag('th', 'Model');
echo html_writer::tag('th', 'Response ms');
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');
foreach ($usages as $row) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', userdate($row->timecreated));
    echo html_writer::tag('td', s($row->lang));
    echo html_writer::tag('td', s($row->xkey));
    echo html_writer::tag('td', (int)$row->tokens);
    echo html_writer::tag('td', s($row->model));
    echo html_writer::tag('td', (int)$row->response_ms);
    echo html_writer::end_tag('tr');
}
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

// Pagination
$baseurl = new moodle_url('/local/xlate/usage.php', ['lang'=>$langfilter, 'model'=>$modelfilter, 'perpage'=>$perpage]);
$totalpages = ceil($total / $perpage);
if ($totalpages > 1) {
    echo html_writer::start_div('d-flex justify-content-center my-3');
    echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
    echo html_writer::end_div();
}

echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // card
echo $OUTPUT->footer();
