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

use local_xlate\local\activity_logger;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/admin_nav.php');

require_login();
$systemcontext = context_system::instance();
require_capability('local/xlate:manage', $systemcontext);

$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 25, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$actionfilter = optional_param('action', '', PARAM_ALPHANUMEXT);
$langfilter = optional_param('lang', '', PARAM_ALPHANUMEXT);
$fromraw = optional_param('from', '', PARAM_RAW_TRIMMED);
$toraw = optional_param('to', '', PARAM_RAW_TRIMMED);
$download = optional_param('download', '', PARAM_ALPHA);

$page = max(0, $page);

$perpageoptions = [10, 25, 50, 100];
if (!in_array($perpage, $perpageoptions, true)) {
    $perpage = 25;
}

$now = time();
$defaultfrom = $now - (30 * DAYSECS);
$defaultto = $now;

$fromts = $fromraw !== '' ? strtotime($fromraw) : $defaultfrom;
if ($fromts === false) {
    $fromts = $defaultfrom;
}
$fromts = max(0, $fromts);

$tots = $toraw !== '' ? strtotime($toraw . ' 23:59:59') : $defaultto;
if ($tots === false) {
    $tots = $defaultto;
}
if ($tots < $fromts) {
    $tots = $fromts;
}

$fromvalue = $fromraw !== '' ? $fromraw : userdate($fromts, '%Y-%m-%d');
$tovalue = $toraw !== '' ? $toraw : userdate($tots, '%Y-%m-%d');

$actionlabels = [
    '' => get_string('activity_filter_action_all', 'local_xlate'),
    activity_logger::ACTION_CREATE => get_string('activity_action_translation_create', 'local_xlate'),
    activity_logger::ACTION_UPDATE => get_string('activity_action_translation_update', 'local_xlate'),
    activity_logger::ACTION_AUTOTRANSLATE => get_string('activity_action_translation_autotranslate', 'local_xlate'),
    activity_logger::ACTION_REVIEW_MARK => get_string('activity_action_translation_review_mark', 'local_xlate'),
    activity_logger::ACTION_REVIEW_CLEAR => get_string('activity_action_translation_review_clear', 'local_xlate'),
    activity_logger::ACTION_STATUS_ACTIVE => get_string('activity_action_translation_status_active', 'local_xlate'),
    activity_logger::ACTION_STATUS_INACTIVE => get_string('activity_action_translation_status_inactive', 'local_xlate'),
];

$legacyactionmap = [
    'autotranslate' => activity_logger::ACTION_AUTOTRANSLATE,
];

$resolveactionlabel = static function(string $code) use ($actionlabels, $legacyactionmap): string {
    if (isset($actionlabels[$code])) {
        return $actionlabels[$code];
    }
    if (isset($legacyactionmap[$code])) {
        $canonical = $legacyactionmap[$code];
        if (isset($actionlabels[$canonical])) {
            return $actionlabels[$canonical];
        }
    }
    return $code;
};

if ($actionfilter !== '' && isset($legacyactionmap[$actionfilter])) {
    $actionfilter = $legacyactionmap[$actionfilter];
}

$namefields = 'u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename';
$translatorrecords = [];
foreach (['local/xlate:manage', 'local/xlate:managecourse'] as $capability) {
    $candidates = get_users_by_capability($systemcontext, $capability, 'u.id, ' . $namefields, 'u.lastname, u.firstname');
    foreach ($candidates as $candidate) {
        $translatorrecords[(int)$candidate->id] = $candidate;
    }
}

$activityusersql = "SELECT DISTINCT u.id, $namefields
                       FROM {local_xlate_activity} a
                       JOIN {user} u ON u.id = a.userid
                      WHERE a.userid > 0 AND u.deleted = 0
                   ORDER BY u.lastname, u.firstname";
$activityusers = $DB->get_records_sql($activityusersql);
foreach ($activityusers as $activityuser) {
    if (!isset($translatorrecords[$activityuser->id])) {
        $translatorrecords[(int)$activityuser->id] = $activityuser;
    }
}

if (!empty($translatorrecords)) {
    uasort($translatorrecords, static function($a, $b) {
        return strcasecmp(fullname($a), fullname($b));
    });
}

$translatoroptions = [0 => get_string('activity_filter_user_all', 'local_xlate')];
foreach ($translatorrecords as $translator) {
    $translatoroptions[(int)$translator->id] = fullname($translator);
}

$where = ['1=1'];
$params = [];

if ($userid > 0) {
    $where[] = 'a.userid = :userid';
    $params['userid'] = $userid;
}
if ($courseid > 0) {
    $where[] = 'a.courseid = :courseid';
    $params['courseid'] = $courseid;
}
if ($actionfilter !== '' && isset($actionlabels[$actionfilter])) {
    if ($actionfilter === activity_logger::ACTION_AUTOTRANSLATE) {
        $where[] = '(a.action = :action OR a.action = :actionlegacy)';
        $params['action'] = $actionfilter;
        $params['actionlegacy'] = 'autotranslate';
    } else {
        $where[] = 'a.action = :action';
        $params['action'] = $actionfilter;
    }
}
if ($langfilter !== '') {
    $where[] = 'a.lang = :lang';
    $params['lang'] = $langfilter;
}
if ($fromts > 0) {
    $where[] = 'a.timecreated >= :fromtime';
    $params['fromtime'] = $fromts;
}
if ($tots > 0) {
    $where[] = 'a.timecreated <= :totime';
    $params['totime'] = $tots;
}

$whereclause = 'WHERE ' . implode(' AND ', $where);

$pageparams = [
    'page' => $page,
    'perpage' => $perpage,
];
if ($userid > 0) {
    $pageparams['userid'] = $userid;
}
if ($courseid > 0) {
    $pageparams['courseid'] = $courseid;
}
if ($actionfilter !== '') {
    $pageparams['action'] = $actionfilter;
}
if ($langfilter !== '') {
    $pageparams['lang'] = $langfilter;
}
if ($fromraw !== '') {
    $pageparams['from'] = $fromraw;
}
if ($toraw !== '') {
    $pageparams['to'] = $toraw;
}

$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/xlate/activity.php', $pageparams));
$PAGE->set_title(get_string('activity_page_title', 'local_xlate'));
$PAGE->set_heading(get_string('activity_page_title', 'local_xlate'));
$PAGE->requires->css(new moodle_url('/local/xlate/styles.css'));

$summary = (object) [
    'totalchars' => 0,
    'totalactions' => 0,
    'translators' => 0,
    'courses' => 0,
];
$totalcount = 0;

$selectsql = "SELECT a.*, $namefields,
                         u.email,
                   c.fullname AS coursename,
                   k.xkey
                FROM {local_xlate_activity} a
           LEFT JOIN {user} u ON u.id = a.userid
           LEFT JOIN {course} c ON c.id = a.courseid
           LEFT JOIN {local_xlate_key} k ON k.id = a.keyid
                $whereclause
            ORDER BY a.timecreated DESC";

$courseSourceCache = [];
$shouldHideSourceRow = static function(int $courseidvalue, string $lang) use (&$courseSourceCache): bool {
    if ($courseidvalue <= 0 || $lang === '') {
        return false;
    }

    if (!array_key_exists($courseidvalue, $courseSourceCache)) {
        try {
            $config = \local_xlate\customfield_helper::get_course_config($courseidvalue);
            $courseSourceCache[$courseidvalue] = (is_array($config) && !empty($config['source']))
                ? (string)$config['source']
                : null;
        } catch (\Throwable $e) {
            $courseSourceCache[$courseidvalue] = null;
        }
    }

    $source = $courseSourceCache[$courseidvalue];
    if ($source === null || $source === '') {
        return false;
    }

    return \core_text::strtolower($source) === \core_text::strtolower($lang);
};

$filteredrecords = [];
$translatorids = [];
$courseids = [];

$batchsize = 500;
$offset = 0;
while (true) {
    $batch = $DB->get_records_sql($selectsql, $params, $offset, $batchsize);
    if (empty($batch)) {
        break;
    }

    foreach ($batch as $record) {
        $lang = trim((string)($record->lang ?? ''));
        $courseidvalue = (int)($record->courseid ?? 0);
        if ($shouldHideSourceRow($courseidvalue, $lang)) {
            continue;
        }

        $filteredrecords[] = $record;
        $summary->totalchars += (int)$record->chars;
        if ($record->userid > 0) {
            $translatorids[(int)$record->userid] = true;
        }
        if ($courseidvalue > 0) {
            $courseids[$courseidvalue] = true;
        }
    }

    if (count($batch) < $batchsize) {
        break;
    }

    $offset += $batchsize;
}

$summary->totalactions = count($filteredrecords);
$summary->translators = count($translatorids);
$summary->courses = count($courseids);
$totalcount = $summary->totalactions;

if ($download === 'csv') {
    $filename = clean_filename(get_string('activity_download_filename', 'local_xlate'));
    $exporter = fopen('php://output', 'w');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    fputcsv($exporter, ['time', 'userid', 'user', 'action', 'courseid', 'lang', 'chars', 'keyid', 'translationid']);
    foreach ($filteredrecords as $record) {
        $username = $record->userid > 0 ? fullname($record) : get_string('activity_unknown_user', 'local_xlate');
        $actionname = $resolveactionlabel($record->action);
        fputcsv($exporter, [
            userdate($record->timecreated, '%Y-%m-%d %H:%M:%S'),
            $record->userid,
            $username,
            $actionname,
            $record->courseid,
            $record->lang,
            $record->chars,
            $record->keyid,
            $record->translationid,
        ]);
    }
    fclose($exporter);
    exit;
}

if ($totalcount > 0) {
    $maxpage = (int)floor(($totalcount - 1) / $perpage);
    if ($page > $maxpage) {
        $page = $maxpage;
        $pageparams['page'] = $page;
        $PAGE->set_url(new moodle_url('/local/xlate/activity.php', $pageparams));
    }
}

$startindex = $page * $perpage;
$activities = array_slice($filteredrecords, $startindex, $perpage);

$reseturl = new moodle_url('/local/xlate/activity.php', ['perpage' => $perpage]);

$activefilters = [];
if ($userid > 0) {
    $username = $translatoroptions[$userid] ?? get_string('activity_filter_user_unknown', 'local_xlate', $userid);
    $activefilters[] = get_string('activity_filter_user', 'local_xlate') . ': ' . $username;
}
if ($courseid > 0) {
    $activefilters[] = get_string('activity_filter_course', 'local_xlate') . ': ' . $courseid;
}
if ($actionfilter !== '' && isset($actionlabels[$actionfilter])) {
    $activefilters[] = get_string('activity_filter_action', 'local_xlate') . ': ' . $resolveactionlabel($actionfilter);
}
if ($langfilter !== '') {
    $activefilters[] = get_string('activity_filter_lang', 'local_xlate') . ': ' . $langfilter;
}
if ($fromts > 0 || $tots > 0) {
    $range = userdate($fromts, get_string('strftimedaydate', 'langconfig')) . ' -> ' . userdate($tots, get_string('strftimedaydate', 'langconfig'));
    $activefilters[] = $range;
}

echo $OUTPUT->header();
local_xlate_render_admin_nav('activity');

$summarycards = [
    ['label' => get_string('activity_summary_characters', 'local_xlate'), 'value' => $summary->totalchars],
    ['label' => get_string('activity_summary_actions', 'local_xlate'), 'value' => $summary->totalactions],
    ['label' => get_string('activity_summary_translators', 'local_xlate'), 'value' => $summary->translators],
    ['label' => get_string('activity_summary_courses', 'local_xlate'), 'value' => $summary->courses],
];

echo html_writer::start_div('mb-4');
echo html_writer::div(get_string('activity_summary_title', 'local_xlate'), 'text-uppercase text-muted small fw-semibold mb-2');
echo html_writer::start_div('row g-3');
foreach ($summarycards as $card) {
    echo html_writer::start_div('col-6 col-xl-3 col-xxl-2');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body text-center');
    echo html_writer::div(format_float($card['value'], 0, true, true), 'h3 fw-semibold mb-1');
    echo html_writer::div($card['label'], 'text-muted text-uppercase small');
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('activity_filters_subtitle', 'local_xlate'), 'card-header');
echo html_writer::start_div('card-body');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => new moodle_url('/local/xlate/activity.php')]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => 0]);

echo html_writer::start_div('row g-3');

echo html_writer::start_div('col-6 col-xl-3');
echo html_writer::tag('label', get_string('activity_filter_user', 'local_xlate'), ['for' => 'userid']);
echo html_writer::select($translatoroptions, 'userid', $userid, false, ['class' => 'form-control', 'id' => 'userid']);
echo html_writer::end_div();

echo html_writer::start_div('col-6 col-xl-3');
echo html_writer::tag('label', get_string('activity_filter_course', 'local_xlate'), ['for' => 'courseid']);
echo html_writer::empty_tag('input', ['type' => 'number', 'class' => 'form-control', 'name' => 'courseid', 'id' => 'courseid', 'value' => $courseid ?: '', 'min' => 0]);
echo html_writer::end_div();

echo html_writer::start_div('col-6 col-xl-3');
echo html_writer::tag('label', get_string('activity_filter_action', 'local_xlate'), ['for' => 'action']);
echo html_writer::select($actionlabels, 'action', $actionfilter, false, ['class' => 'form-control', 'id' => 'action']);
echo html_writer::end_div();

echo html_writer::start_div('col-6 col-xl-3');
echo html_writer::tag('label', get_string('activity_filter_lang', 'local_xlate'), ['for' => 'lang']);
echo html_writer::empty_tag('input', ['type' => 'text', 'class' => 'form-control', 'name' => 'lang', 'id' => 'lang', 'value' => $langfilter]);
echo html_writer::end_div();

echo html_writer::start_div('col-6 col-xl-3');
echo html_writer::tag('label', get_string('activity_filter_from', 'local_xlate'), ['for' => 'from']);
echo html_writer::empty_tag('input', ['type' => 'date', 'class' => 'form-control', 'name' => 'from', 'id' => 'from', 'value' => $fromvalue]);
echo html_writer::end_div();

echo html_writer::start_div('col-6 col-xl-3');
echo html_writer::tag('label', get_string('activity_filter_to', 'local_xlate'), ['for' => 'to']);
echo html_writer::empty_tag('input', ['type' => 'date', 'class' => 'form-control', 'name' => 'to', 'id' => 'to', 'value' => $tovalue]);
echo html_writer::end_div();

echo html_writer::start_div('col-6 col-xl-2');
echo html_writer::tag('label', get_string('queue_per_page', 'local_xlate'), ['for' => 'perpage']);
$perpagechoices = array_combine($perpageoptions, $perpageoptions);
echo html_writer::select($perpagechoices, 'perpage', $perpage, false, ['class' => 'form-control', 'id' => 'perpage']);
echo html_writer::end_div();

echo html_writer::start_div('col-6 col-xl-2 d-flex align-items-end gap-2');
echo html_writer::tag('button', get_string('activity_filter_submit', 'local_xlate'), ['type' => 'submit', 'class' => 'btn btn-primary w-100']);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

if (!empty($activefilters)) {
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body d-flex flex-wrap gap-2 align-items-center');
    foreach ($activefilters as $chip) {
        echo html_writer::tag('span', $chip, ['class' => 'badge text-bg-secondary']);
    }
    echo html_writer::link($reseturl, get_string('activity_filter_clear', 'local_xlate'), ['class' => 'btn btn-sm btn-link ms-auto text-decoration-none']);
    $csvparams = $pageparams + ['download' => 'csv'];
    echo html_writer::link(new moodle_url('/local/xlate/activity.php', $csvparams), get_string('activity_export_csv', 'local_xlate'), ['class' => 'btn btn-sm btn-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_div();
} else {
    $csvparams = $pageparams + ['download' => 'csv'];
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/xlate/activity.php', $csvparams), get_string('activity_export_csv', 'local_xlate'), ['class' => 'btn btn-sm btn-secondary']),
        'd-flex justify-content-end mb-3'
    );
}

if ($totalcount > $perpage) {
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
}

if (!empty($activities)) {
    echo html_writer::start_tag('table', ['class' => 'table table-striped table-hover']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    $headers = [
        get_string('activity_table_time', 'local_xlate'),
        get_string('activity_table_user', 'local_xlate'),
        get_string('activity_table_action', 'local_xlate'),
        get_string('activity_table_course', 'local_xlate'),
        get_string('activity_table_lang', 'local_xlate'),
        get_string('activity_table_chars', 'local_xlate'),
        get_string('activity_table_key', 'local_xlate'),
        get_string('activity_table_translation', 'local_xlate'),
        get_string('activity_table_notes', 'local_xlate'),
    ];
    foreach ($headers as $header) {
        echo html_writer::tag('th', $header);
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');
    foreach ($activities as $activity) {
        $userlabel = $activity->userid > 0 ? fullname($activity) : get_string('activity_unknown_user', 'local_xlate');
        $courselabel = $activity->courseid > 0
            ? ($activity->coursename ? html_writer::link(new moodle_url('/course/view.php', ['id' => $activity->courseid]), format_string($activity->coursename), ['class' => 'text-decoration-none']) : (string)$activity->courseid)
            : get_string('activity_course_unknown', 'local_xlate');
        $notes = '';
        if (!empty($activity->notes)) {
            $decoded = json_decode($activity->notes, true);
            if (is_array($decoded)) {
                $pairs = [];
                foreach ($decoded as $key => $value) {
                    if (is_scalar($value)) {
                        $pairs[] = clean_param((string)$key, PARAM_NOTAGS) . ': ' . clean_param((string)$value, PARAM_NOTAGS);
                    }
                }
                $notes = implode(', ', $pairs);
            } else {
                $notes = clean_param($activity->notes, PARAM_NOTAGS);
            }
        }
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', userdate($activity->timecreated, '%Y-%m-%d %H:%M'));
        echo html_writer::tag('td', $userlabel);
        echo html_writer::tag('td', $resolveactionlabel($activity->action));
        echo html_writer::tag('td', $courselabel);
        echo html_writer::tag('td', $activity->lang);
        echo html_writer::tag('td', format_float($activity->chars, 0, true, true));
        echo html_writer::tag('td', (string)$activity->keyid);
        echo html_writer::tag('td', (string)$activity->translationid);
        echo html_writer::tag('td', $notes);
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo html_writer::div(get_string('activity_no_results', 'local_xlate'), 'alert alert-info');
}

if ($totalcount > $perpage) {
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
}

echo $OUTPUT->footer();
