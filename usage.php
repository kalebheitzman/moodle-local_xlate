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
$langfilter = optional_param('xlatelang', '', PARAM_ALPHANUMEXT);
$modelfilter = optional_param('model', '', PARAM_RAW);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 25, PARAM_INT);

$usernow = usergetdate(time());
$defaultmonth = (int)$usernow['mon'];
$defaultyear = (int)$usernow['year'];
$monthfilter = optional_param('xlatemonth', 0, PARAM_INT);
$yearfilter = optional_param('xlateyear', 0, PARAM_INT);
if ($monthfilter < 1 || $monthfilter > 12) {
    $monthfilter = $defaultmonth;
}
if ($yearfilter <= 0) {
    $yearfilter = $defaultyear;
}
$monthstart = make_timestamp($yearfilter, $monthfilter, 1, 0, 0, 0);
$nextmonth = ($monthfilter === 12) ? 1 : $monthfilter + 1;
$nextmonthyear = ($monthfilter === 12) ? $yearfilter + 1 : $yearfilter;
$monthend = make_timestamp($nextmonthyear, $nextmonth, 1, 0, 0, 0);

$params = [];
$where = [];
// Restrict results to the selected month/year window by default.
$where[] = 'timecreated >= :monthstart AND timecreated < :monthend';
$params['monthstart'] = $monthstart;
$params['monthend'] = $monthend;
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
$totals = $DB->get_record_sql('SELECT
        SUM(input_tokens) AS input_tokens,
        SUM(cached_input_tokens) AS cached_input_tokens,
        SUM(output_tokens) AS output_tokens,
        SUM(total_tokens) AS total_tokens,
        SUM(input_cost) AS input_cost,
        SUM(cached_input_cost) AS cached_input_cost,
        SUM(output_cost) AS output_cost,
        SUM(total_cost) AS total_cost
    FROM {local_xlate_token_batch} ' . $wheresql, $params) ?: (object)[];

$totalinputtokens = (int)($totals->input_tokens ?? 0);
$totalcachedtokens = (int)($totals->cached_input_tokens ?? 0);
$totaloutputtokens = (int)($totals->output_tokens ?? 0);
$totaltokens = (int)($totals->total_tokens ?? ($totalinputtokens + $totalcachedtokens + $totaloutputtokens));
$totalinputcost = (float)($totals->input_cost ?? 0.0);
$totalcachedcost = (float)($totals->cached_input_cost ?? 0.0);
$totaloutputcost = (float)($totals->output_cost ?? 0.0);
$totalcost = (float)($totals->total_cost ?? ($totalinputcost + $totalcachedcost + $totaloutputcost));


$usages = $DB->get_records_sql('SELECT * FROM {local_xlate_token_batch} ' . $wheresql . ' ORDER BY timecreated DESC', $params, $page * $perpage, $perpage);

// Pricing config used for fallback calculations when stored cost fields are empty.
$inputrate = (float)get_config('local_xlate', 'pricing_input_per_million');
$cachedrate = (float)get_config('local_xlate', 'pricing_cached_input_per_million');
$outputrate = (float)get_config('local_xlate', 'pricing_output_per_million');

$hasstoredcosts = ($totalcost > 0) || ($totalinputcost > 0) || ($totalcachedcost > 0) || ($totaloutputcost > 0);
if (!$hasstoredcosts && ($totalinputtokens || $totalcachedtokens || $totaloutputtokens)) {
    $totalinputcost = $totalinputtokens ? ($totalinputtokens / 1000000) * $inputrate : 0.0;
    $totalcachedcost = $totalcachedtokens ? ($totalcachedtokens / 1000000) * $cachedrate : 0.0;
    $totaloutputcost = $totaloutputtokens ? ($totaloutputtokens / 1000000) * $outputrate : 0.0;
    $totalcost = $totalinputcost + $totalcachedcost + $totaloutputcost;
}

// Get distinct languages and models for filter dropdowns.
$langs = $DB->get_fieldset_sql('SELECT DISTINCT lang FROM {local_xlate_token_batch} ORDER BY lang');
$models = $DB->get_fieldset_sql('SELECT DISTINCT model FROM {local_xlate_token_batch} WHERE model IS NOT NULL AND model != "" ORDER BY model');
$langoptions = $langs ? array_combine($langs, $langs) : [];
$modeloptions = $models ? array_combine($models, $models) : [];

// Build month and year selector options.
$monthoptions = [];
for ($m = 1; $m <= 12; $m++) {
    $monthoptions[$m] = userdate(make_timestamp(2000, $m, 1, 0, 0, 0), '%B');
}
$mindate = $DB->get_field_sql('SELECT MIN(timecreated) FROM {local_xlate_token_batch}');
$firstyear = $mindate ? (int)userdate((int)$mindate, '%Y') : $defaultyear;
if ($firstyear > $defaultyear) {
    $firstyear = $defaultyear;
}
$yearoptions = [];
for ($year = $defaultyear; $year >= $firstyear; $year--) {
    $yearoptions[$year] = (string)$year;
}
if (!isset($yearoptions[$yearfilter])) {
    $yearoptions[$yearfilter] = (string)$yearfilter;
    krsort($yearoptions, SORT_NUMERIC);
}

echo $OUTPUT->header();
require_once(__DIR__ . '/admin_nav.php');
local_xlate_render_admin_nav('usage');
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', 'Token Usage', ['class' => 'mb-3']);
echo html_writer::tag('p', 'This page shows token usage for all autotranslation requests. Use the filters to narrow results by language or model.');
if (!$hasstoredcosts && ($totalinputtokens || $totalcachedtokens || $totaloutputtokens)) {
    echo html_writer::div('Totals estimated using current pricing settings because stored cost data was missing.', 'mb-3');
} else {
    $settingslink = html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'local_xlate']), 'Settings');
    echo html_writer::div('Costs reflect the values stored with each batch. Update pricing under ' . $settingslink . '.', 'mb-3');
}

// Condensed summary metrics for quick scanning.
$metrics = [
    ['label' => 'Total batches', 'value' => number_format((int)$total)],
    ['label' => 'Total tokens', 'value' => number_format((int)$totaltokens)],
    ['label' => 'Total cost', 'value' => '$' . number_format($totalcost, 4)],
];

echo html_writer::start_div('row text-center align-items-stretch mb-2');
foreach ($metrics as $metric) {
    $content = html_writer::tag('div', $metric['value'], ['class' => 'h4 mb-1']) .
        html_writer::tag('div', $metric['label'], ['class' => 'text-muted small text-uppercase']);
    echo html_writer::div($content, 'col-12 col-sm-4 mb-3');
}
echo html_writer::end_div();

echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // card

// Filter and table card
echo html_writer::start_div('card');
echo html_writer::start_div('card-body');

// Filter form
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-3']);
echo html_writer::start_div('form-group mr-2');
echo html_writer::label('Month', 'xlatemonth', false, ['class' => 'mr-1']);
echo html_writer::select($monthoptions, 'xlatemonth', $monthfilter, null, ['class'=>'custom-select mr-2', 'id'=>'xlatemonth']);
echo html_writer::end_div();
echo html_writer::start_div('form-group mr-2');
echo html_writer::label('Year', 'xlateyear', false, ['class' => 'mr-1']);
echo html_writer::select($yearoptions, 'xlateyear', $yearfilter, null, ['class'=>'custom-select mr-2', 'id'=>'xlateyear']);
echo html_writer::end_div();
echo html_writer::start_div('form-group mr-2');
echo html_writer::label('Language', 'xlatelang', false, ['class' => 'mr-1']);
echo html_writer::select(array_merge([''=>'All'], $langoptions), 'xlatelang', $langfilter, null, ['class'=>'custom-select mr-2', 'id'=>'xlatelang']);
echo html_writer::end_div();
echo html_writer::start_div('form-group mr-2');
echo html_writer::label('Model', 'model', false, ['class' => 'mr-1']);
echo html_writer::select(array_merge([''=>'All'], $modeloptions), 'model', $modelfilter, null, ['class'=>'custom-select mr-2', 'id'=>'model']);
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
echo html_writer::tag('th', 'Input tokens');
echo html_writer::tag('th', 'Cached tokens');
echo html_writer::tag('th', 'Output tokens');
echo html_writer::tag('th', 'Total tokens');
echo html_writer::tag('th', 'Model');
echo html_writer::tag('th', 'Total cost');
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');
foreach ($usages as $row) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', userdate($row->timecreated));
    echo html_writer::tag('td', s($row->lang));
    echo html_writer::tag('td', (int)$row->batchsize);
    $inputtokens = isset($row->input_tokens) ? (int)$row->input_tokens : (int)($row->prompt_tokens ?? 0);
    $cachedtokens = isset($row->cached_input_tokens) ? (int)$row->cached_input_tokens : 0;
    $outputtokens = isset($row->output_tokens) ? (int)$row->output_tokens : (int)($row->completion_tokens ?? 0);
    $totaltokensrow = isset($row->total_tokens) ? (int)$row->total_tokens : (int)($row->tokens ?? ($inputtokens + $cachedtokens + $outputtokens));
    $inputcost = isset($row->input_cost) ? (float)$row->input_cost : 0.0;
    $cachedcost = isset($row->cached_input_cost) ? (float)$row->cached_input_cost : 0.0;
    $outputcost = isset($row->output_cost) ? (float)$row->output_cost : 0.0;
    $totalcostrow = isset($row->total_cost) ? (float)$row->total_cost : null;
    if ($totalcostrow === null || $totalcostrow <= 0) {
        $storedcost = isset($row->cost) ? (float)$row->cost : 0.0;
        if ($storedcost > 0) {
            $totalcostrow = $storedcost;
        } else {
            $inputcost = $inputtokens ? ($inputtokens / 1000000) * $inputrate : 0.0;
            $cachedcost = $cachedtokens ? ($cachedtokens / 1000000) * $cachedrate : 0.0;
            $outputcost = $outputtokens ? ($outputtokens / 1000000) * $outputrate : 0.0;
            $totalcostrow = $inputcost + $cachedcost + $outputcost;
        }
    }

    echo html_writer::tag('td', $inputtokens);
    echo html_writer::tag('td', $cachedtokens);
    echo html_writer::tag('td', $outputtokens);
    echo html_writer::tag('td', $totaltokensrow);
    echo html_writer::tag('td', s($row->model));
    echo html_writer::tag('td', '$' . number_format($totalcostrow, 5));
    echo html_writer::end_tag('tr');
}
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

// Pagination
$baseurl = new moodle_url('/local/xlate/usage.php', [
    'xlatemonth' => $monthfilter,
    'xlateyear' => $yearfilter,
    'xlatelang' => $langfilter,
    'model' => $modelfilter,
    'perpage' => $perpage
]);
$totalpages = ceil($total / $perpage);
if ($totalpages > 1) {
    echo html_writer::start_div('d-flex justify-content-center my-3');
    echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
    echo html_writer::end_div();
}

echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // card
echo $OUTPUT->footer();
