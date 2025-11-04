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
 * Usage analytics page for Local Xlate.
 *
 * Displays token usage, cost summaries, and model breakdowns pulled from the
 * token batch log to help administrators monitor translation spending.
 *
 * @package    local_xlate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/xlate:manage', \context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/xlate/usage.php'));
$PAGE->set_title(get_string('admin_usage', 'local_xlate'));
$PAGE->set_heading(get_string('admin_usage', 'local_xlate'));

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

$total = $DB->count_records_select('local_xlate_token_batch', $where ? implode(' AND ', $where) : '', $params);
$totaltokens = $DB->get_field_sql('SELECT SUM(tokens) FROM {local_xlate_token_batch} ' . $wheresql, $params);
$totalprompt = $DB->get_field_sql('SELECT SUM(prompt_tokens) FROM {local_xlate_token_batch} ' . $wheresql, $params);
$totalcompletion = $DB->get_field_sql('SELECT SUM(completion_tokens) FROM {local_xlate_token_batch} ' . $wheresql, $params);
$totalcost = $DB->get_field_sql('SELECT SUM(cost) FROM {local_xlate_token_batch} ' . $wheresql, $params);

// Load pricing table
$pricing = include(__DIR__ . '/config/pricing.php');

// Determine model for pricing (if filtered, use that; else use most common or default)

$model = $modelfilter;
if (!$model) {
    $model = $DB->get_field_sql('SELECT model FROM {local_xlate_token_batch} WHERE model IS NOT NULL AND model != "" GROUP BY model ORDER BY COUNT(*) DESC LIMIT 1');
}
$model = $model ?: 'gpt-4.1';
$modelpricing = $pricing[$model] ?? $pricing['gpt-4.1'];

// Calculate estimated costs

// Use stored cost if available, else estimate
if ($totalcost !== null && $totalcost > 0) {
    $cost_total = $totalcost;
    $cost_prompt = $cost_completion = null;
} else {
    $cost_prompt = $totalprompt ? ($totalprompt / 1000.0) * $modelpricing['prompt'] : 0;
    $cost_completion = $totalcompletion ? ($totalcompletion / 1000.0) * $modelpricing['completion'] : 0;
    $cost_total = $cost_prompt + $cost_completion;
}


$usages = $DB->get_records_sql('SELECT * FROM {local_xlate_token_batch} ' . $wheresql . ' ORDER BY timecreated DESC', $params, $page * $perpage, $perpage);

// Get distinct languages and models for filter dropdowns.
$langs = $DB->get_fieldset_sql('SELECT DISTINCT lang FROM {local_xlate_token_batch} ORDER BY lang');
$models = $DB->get_fieldset_sql('SELECT DISTINCT model FROM {local_xlate_token_batch} WHERE model IS NOT NULL AND model != "" ORDER BY model');

echo $OUTPUT->header();
require_once(__DIR__ . '/admin_nav.php');
local_xlate_render_admin_nav('usage');
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', 'Token Usage', ['class' => 'mb-3']);
echo html_writer::tag('p', 'This page shows token usage for all autotranslation requests. Use the filters to narrow results by language or model.');
echo html_writer::start_tag('ul', ['class' => 'list-unstyled mb-3']);
echo html_writer::tag('li', '<strong>Total batches:</strong> ' . (int)$total);
echo html_writer::tag('li', '<strong>Total tokens:</strong> ' . (int)$totaltokens);
echo html_writer::tag('li', '<strong>Total prompt tokens:</strong> ' . (int)$totalprompt);
echo html_writer::tag('li', '<strong>Total completion tokens:</strong> ' . (int)$totalcompletion);
if ($cost_prompt !== null) {
    echo html_writer::tag('li', '<strong>Estimated prompt cost:</strong> $' . number_format($cost_prompt, 4));
    echo html_writer::tag('li', '<strong>Estimated completion cost:</strong> $' . number_format($cost_completion, 4));
}
echo html_writer::tag('li', '<strong>Total cost:</strong> $' . number_format($cost_total, 4));
echo html_writer::end_tag('ul');
// Show pricing table link/info
echo html_writer::div('Pricing is based on the selected model. <a href="pricing.php" target="_blank">View/edit pricing table</a>.', 'mb-3 text-muted');

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
echo html_writer::tag('th', 'Batch size');
echo html_writer::tag('th', 'Tokens');
echo html_writer::tag('th', 'Prompt');
echo html_writer::tag('th', 'Completion');
echo html_writer::tag('th', 'Model');
echo html_writer::tag('th', 'Cost');
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');
foreach ($usages as $row) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', userdate($row->timecreated));
    echo html_writer::tag('td', s($row->lang));
    echo html_writer::tag('td', (int)$row->batchsize);
    echo html_writer::tag('td', (int)$row->tokens);
    echo html_writer::tag('td', isset($row->prompt_tokens) ? (int)$row->prompt_tokens : '');
    echo html_writer::tag('td', isset($row->completion_tokens) ? (int)$row->completion_tokens : '');
    echo html_writer::tag('td', s($row->model));
    // Dynamically calculate cost if missing or zero
    $costval = (isset($row->cost) && is_numeric($row->cost) && $row->cost > 0) ? $row->cost : null;
    if ($costval === null) {
        $pricing = include(__DIR__ . '/config/pricing.php');
        $model = $row->model ?? 'gpt-4.1';
        $modelpricing = $pricing[$model] ?? $pricing['gpt-4.1'] ?? null;
        $prompt = isset($row->prompt_tokens) ? (int)$row->prompt_tokens : 0;
        $completion = isset($row->completion_tokens) ? (int)$row->completion_tokens : 0;
        if ($modelpricing) {
            $cost_prompt = ($prompt / 1000.0) * ($modelpricing['prompt'] ?? 0);
            $cost_completion = ($completion / 1000.0) * ($modelpricing['completion'] ?? 0);
            $costval = $cost_prompt + $cost_completion;
        } else {
            $costval = 0;
        }
    }
    echo html_writer::tag('td', '$' . number_format($costval, 5));
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
