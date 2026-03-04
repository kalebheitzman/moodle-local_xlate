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
 * Translation queue dashboard for Local Xlate.
 *
 * Lists queued course autotranslation jobs with filters for administrators.
 *
 * @package    local_xlate
 * @copyright  2026 Kaleb Heitzman
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/admin_nav.php');

require_login();
$systemcontext = context_system::instance();
require_capability('local/xlate:manage', $systemcontext);

$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$statusfilter = optional_param('status', '', PARAM_ALPHAEXT);
$coursefilter = optional_param('courseid', 0, PARAM_INT);

$perpageoptions = [10, 20, 50, 100];
if (!in_array($perpage, $perpageoptions, true)) {
    $perpage = 20;
}

$pageparams = [
    'page' => $page,
    'perpage' => $perpage,
];
if ($search !== '') {
    $pageparams['search'] = $search;
}
if ($statusfilter !== '') {
    $pageparams['status'] = $statusfilter;
}
if ($coursefilter > 0) {
    $pageparams['courseid'] = $coursefilter;
}
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/xlate/queue.php', $pageparams));
$PAGE->set_title(get_string('queue_page_title', 'local_xlate'));
$PAGE->set_heading(get_string('queue_page_title', 'local_xlate'));
$PAGE->requires->css(new moodle_url('/local/xlate/styles.css'));

$conditions = [];
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $conditions[] = '(c.fullname LIKE ? OR c.shortname LIKE ? OR CAST(j.id AS CHAR) LIKE ? OR CAST(j.courseid AS CHAR) LIKE ?)';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($coursefilter > 0) {
    $conditions[] = 'j.courseid = ?';
    $params[] = $coursefilter;
}

switch ($statusfilter) {
    case 'queued':
        $conditions[] = "j.status = 'pending'";
        break;
    case 'running':
        $conditions[] = "j.status = 'running'";
        break;
    case 'complete':
        $conditions[] = "j.status = 'complete'";
        break;
    case 'complete_partial':
        $conditions[] = "j.status = 'complete_partial'";
        break;
}

$whereclause = '';
if (!empty($conditions)) {
    $whereclause = 'WHERE ' . implode(' AND ', $conditions);
}

$basefrom = "FROM {local_xlate_course_job} j
             LEFT JOIN {course} c ON c.id = j.courseid";

global $DB, $OUTPUT;

$summarysql = "SELECT
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS queued,
        COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS running,
        COALESCE(SUM(CASE WHEN status = 'complete' THEN 1 ELSE 0 END), 0) AS complete,
        COALESCE(SUM(CASE WHEN status NOT IN ('pending', 'running', 'complete') THEN 1 ELSE 0 END), 0) AS other,
        COUNT(1) AS total
    FROM {local_xlate_course_job}";
$queuesummary = $DB->get_record_sql($summarysql) ?: (object)[];
$queuesummary = (object)[
    'queued' => isset($queuesummary->queued) ? (int)$queuesummary->queued : 0,
    'running' => isset($queuesummary->running) ? (int)$queuesummary->running : 0,
    'complete' => isset($queuesummary->complete) ? (int)$queuesummary->complete : 0,
    'other' => isset($queuesummary->other) ? (int)$queuesummary->other : 0,
    'total' => isset($queuesummary->total) ? (int)$queuesummary->total : 0,
];

$totalcount = $DB->count_records_sql("SELECT COUNT(1) $basefrom $whereclause", $params);

$sql = "SELECT j.*,
               c.fullname AS coursename,
               c.shortname AS courseshortname
          $basefrom
          $whereclause
      ORDER BY j.ctime DESC";

$jobs = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

$statusoptions = [
    '' => get_string('queue_status_all', 'local_xlate'),
    'queued' => get_string('queue_status_queued', 'local_xlate'),
    'running' => get_string('queue_status_running', 'local_xlate'),
    'complete' => get_string('queue_status_complete', 'local_xlate'),
    'complete_partial' => get_string('queue_status_complete_partial', 'local_xlate'),
];

$resetparams = ['perpage' => $perpage];
$reseturl = new moodle_url('/local/xlate/queue.php', $resetparams);

$activefilters = [];
if ($search !== '') {
    $activefilters[] = html_writer::tag('span',
        s(get_string('queue_filter_chip_search', 'local_xlate', $search)),
        ['class' => 'badge rounded-pill bg-secondary text-white px-3 py-2']
    );
}
if ($statusfilter !== '' && isset($statusoptions[$statusfilter])) {
    $activefilters[] = html_writer::tag('span',
        s(get_string('queue_filter_chip_status', 'local_xlate', $statusoptions[$statusfilter])),
        ['class' => 'badge rounded-pill bg-secondary text-white px-3 py-2']
    );
}
if ($coursefilter > 0) {
    $activefilters[] = html_writer::tag('span',
        s(get_string('queue_filter_chip_course', 'local_xlate', $coursefilter)),
        ['class' => 'badge rounded-pill bg-secondary text-white px-3 py-2']
    );
}

$perpagechoices = array_combine($perpageoptions, $perpageoptions);

// Output begins.
echo $OUTPUT->header();
local_xlate_render_admin_nav('queue');

$summarycards = [
    ['label' => get_string('queue_summary_total', 'local_xlate'), 'value' => $queuesummary->total],
    ['label' => get_string('queue_summary_queued', 'local_xlate'), 'value' => $queuesummary->queued],
    ['label' => get_string('queue_summary_running', 'local_xlate'), 'value' => $queuesummary->running],
    ['label' => get_string('queue_summary_complete', 'local_xlate'), 'value' => $queuesummary->complete],
    ['label' => get_string('queue_summary_other', 'local_xlate'), 'value' => $queuesummary->other],
];

echo html_writer::start_div('mb-4');
echo html_writer::div(get_string('queue_summary_title', 'local_xlate'), 'text-uppercase text-muted small fw-semibold mb-2');
echo html_writer::start_div('row g-3 queue-summary');
foreach ($summarycards as $card) {
    echo html_writer::start_div('col-6 col-xl-3 col-xxl-2');
    echo html_writer::start_div('card h-100 border text-center');
    echo html_writer::start_div('card-body py-3');
    $value = format_float($card['value'], 0, true, true);
    echo html_writer::div($value, 'h3 fw-semibold mb-1');
    echo html_writer::div($card['label'], 'text-muted small text-uppercase');
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('search_and_filter', 'local_xlate'), 'card-header');
echo html_writer::start_div('card-body');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => 0]);

echo html_writer::start_div('row g-3 align-items-end');
// Search field.
echo html_writer::start_div('col-12 col-xl-4');
echo html_writer::tag('label', get_string('queue_search_label', 'local_xlate'), ['for' => 'search']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'search',
    'id' => 'search',
    'value' => s($search),
    'class' => 'form-control',
    'placeholder' => get_string('queue_search_placeholder', 'local_xlate')
]);
echo html_writer::end_div();

// Status filter.
echo html_writer::start_div('col-6 col-xl-2');
echo html_writer::tag('label', get_string('queue_status_label', 'local_xlate'), ['for' => 'status']);
echo html_writer::select($statusoptions, 'status', $statusfilter, false, ['class' => 'form-control', 'id' => 'status']);
echo html_writer::end_div();

// Course filter.
echo html_writer::start_div('col-6 col-xl-3 col-xxl-2');
echo html_writer::tag('label', get_string('queue_course_filter', 'local_xlate'), ['for' => 'courseid']);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'courseid',
    'id' => 'courseid',
    'value' => $coursefilter ?: '',
    'class' => 'form-control',
    'min' => 0
]);
echo html_writer::end_div();

// Per page.
echo html_writer::start_div('col-6 col-xl-1');
echo html_writer::tag('label', get_string('queue_per_page', 'local_xlate'), ['for' => 'perpage']);
echo html_writer::select($perpagechoices, 'perpage', $perpage, false, ['class' => 'form-control', 'id' => 'perpage']);
echo html_writer::end_div();

// Submit.
echo html_writer::start_div('col-6 col-xl-1 d-flex align-items-end');
echo html_writer::tag('button', get_string('filter', 'local_xlate'), [
    'type' => 'submit',
    'class' => 'btn btn-primary w-100'
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

if (!empty($activefilters)) {
    echo html_writer::start_div('card mb-4');
    echo html_writer::div(get_string('filter_summary_active', 'local_xlate'), 'card-header py-2 small text-uppercase text-muted');
    echo html_writer::start_div('card-body d-flex flex-wrap align-items-center gap-2');
    foreach ($activefilters as $chip) {
        echo $chip;
    }
    echo html_writer::link($reseturl, get_string('queue_filter_clear', 'local_xlate'), ['class' => 'btn btn-sm btn-link ms-auto text-decoration-none']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($totalcount > $perpage) {
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
}

if (!empty($jobs)) {
    foreach ($jobs as $job) {
        $courseurl = new moodle_url('/course/view.php', ['id' => $job->courseid]);
        $courselabel = $job->coursename ? format_string($job->coursename) : get_string('queue_course_unknown', 'local_xlate', $job->courseid);
        $courseshort = $job->courseshortname ? format_string($job->courseshortname) : '';

        $options = [];
        if (!empty($job->options)) {
            $decoded = json_decode((string)$job->options, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }
        $targets = [];
        if (!empty($options['targetlangs'])) {
            $targets = (array)$options['targetlangs'];
        } else if (!empty($options['targetlang'])) {
            $targets = (array)$options['targetlang'];
        }
        $targets = array_values(array_unique(array_filter(array_map('trim', $targets))));
        $targetlabel = !empty($targets) ? s(implode(', ', $targets)) : get_string('queue_targets_none', 'local_xlate');

        $total = (int)$job->total;
        $processed = (int)$job->processed;
        if ($total < 0) {
            $total = 0;
        }
        if ($processed < 0) {
            $processed = 0;
        }
        $percent = 0;
        if ($total > 0) {
            $percent = (int)round(($processed / max($total, 1)) * 100);
        } else if ($processed > 0) {
            $percent = 100;
        }
        $percent = max(0, min(100, $percent));
        $progressvalue = $total > 0
            ? get_string('queue_progress_value', 'local_xlate', (object)['processed' => $processed, 'total' => $total])
            : get_string('queue_progress_unknown', 'local_xlate', $processed);

        $statusclass = 'bg-secondary';
        $statuslabel = get_string('queue_status_queued', 'local_xlate');
        if ($job->status === 'complete') {
            $statusclass = 'bg-success';
            $statuslabel = get_string('queue_status_complete', 'local_xlate');
        } else if ($job->status === 'complete_partial') {
            $statusclass = 'bg-warning text-dark';
            $statuslabel = get_string('queue_status_complete_partial', 'local_xlate');
        } else if ($job->status === 'running') {
            $statusclass = 'bg-info text-dark';
            $statuslabel = get_string('queue_status_running', 'local_xlate');
        }

        echo html_writer::start_div('card mb-3 shadow-sm');
        echo html_writer::start_div('card-header d-flex justify-content-between align-items-center flex-wrap gap-2');
        echo html_writer::start_div('d-flex flex-column');
        echo html_writer::link($courseurl, $courselabel, ['class' => 'fw-semibold text-decoration-none']);
        if ($courseshort) {
            echo html_writer::tag('span', $courseshort, ['class' => 'text-muted small']);
        }
        echo html_writer::end_div();

        echo html_writer::start_div('d-flex align-items-center gap-2');
        echo html_writer::tag('span', get_string('queue_job_badge', 'local_xlate', $job->id), ['class' => 'badge text-bg-light']);
        echo html_writer::tag('span', $statuslabel, ['class' => 'badge ' . $statusclass]);
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::start_div('card-body');
        echo html_writer::start_div('mb-3');
        echo html_writer::div(get_string('queue_progress_label', 'local_xlate'), 'text-muted small');
        echo html_writer::start_div('progress', ['style' => 'height:1.25rem']);
        echo html_writer::tag('div', $percent . '%', [
            'class' => 'progress-bar',
            'role' => 'progressbar',
            'style' => 'width:' . $percent . '%',
            'aria-valuemin' => 0,
            'aria-valuemax' => 100,
            'aria-valuenow' => $percent
        ]);
        echo html_writer::end_div();
        echo html_writer::div($progressvalue . ' · ' . $percent . '%', 'small mt-2');
        echo html_writer::end_div();

        echo html_writer::start_tag('dl', ['class' => 'row row-cols-1 row-cols-md-2 g-3 small mb-0']);
        $details = [
            get_string('courseid', 'local_xlate') => s($job->courseid),
            get_string('queue_targets_label', 'local_xlate') => $targetlabel,
            get_string('queue_batchsize_label', 'local_xlate') => s((string)$job->batchsize),
            get_string('queue_created_label', 'local_xlate') => $job->ctime ? userdate($job->ctime) : '—',
            get_string('queue_updated_label', 'local_xlate') => $job->mtime ? userdate($job->mtime) : '—',
        ];
        foreach ($details as $label => $value) {
            echo html_writer::tag('dt', $label, ['class' => 'col-md-3 text-muted mb-0']);
            echo html_writer::tag('dd', $value, ['class' => 'col-md-9 mb-0']);
        }
        echo html_writer::end_tag('dl');
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
} else {
    echo html_writer::div(get_string('queue_no_jobs', 'local_xlate'), 'alert alert-info');
}

if ($totalcount > $perpage) {
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
}

echo $OUTPUT->footer();
