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
 * Translation management UI for Local Xlate.
 *
 * Provides searching, filtering, and inline editing of captured translation
 * keys so administrators can curate localized content.
 *
 * @package    local_xlate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

/**
 * Render pagination controls
 */
function render_pagination_controls($baseurl, $page, $perpage, $total, $search, $status_filter, $courseid = 0, $langfilter = [], $langfiltersubmitted = 0) {
    $total_pages = ceil($total / $perpage);
    $pagination = '';
    $langfilter = is_array($langfilter) ? $langfilter : [];
    $langfiltersubmitted = (int)!empty($langfiltersubmitted);
    $sharedparams = [
        'perpage' => $perpage,
        'search' => $search,
        'status_filter' => $status_filter,
        'courseid' => $courseid
    ];
    if ($langfiltersubmitted) {
        $sharedparams['langfiltersubmitted'] = 1;
        if (!empty($langfilter)) {
            $sharedparams['langfilter'] = $langfilter;
        }
    }
    
    if ($total_pages > 1) {
        $pagination .= html_writer::start_tag('nav', ['aria-label' => 'Translation keys pagination']);
        $pagination .= html_writer::start_tag('ul', ['class' => 'pagination justify-content-center mb-0']);
        
        // Previous
        if ($page > 0) {
            $prevurl = new moodle_url($baseurl, [
                'page' => $page - 1
            ]);
            $prevurl->params($sharedparams);
            $pagination .= html_writer::tag('li', 
                html_writer::link($prevurl, '‹ ' . get_string('previous'), ['class' => 'page-link']), 
                ['class' => 'page-item']
            );
        } else {
            $pagination .= html_writer::tag('li', 
                html_writer::span('‹ ' . get_string('previous'), 'page-link'), 
                ['class' => 'page-item disabled']
            );
        }
        
        // First page if not in range
        if ($page > 2) {
            $firsturl = new moodle_url($baseurl, ['page' => 0]);
            $firsturl->params($sharedparams);
            $pagination .= html_writer::tag('li',
                html_writer::link($firsturl, '1', ['class' => 'page-link']),
                ['class' => 'page-item']
            );
            
            if ($page > 3) {
                $pagination .= html_writer::tag('li',
                    html_writer::span('...', 'page-link'),
                    ['class' => 'page-item disabled']
                );
            }
        }
        
        // Page numbers (current ±2)
        $start_page = max(0, $page - 2);
        $end_page = min($total_pages - 1, $page + 2);
        
        for ($i = $start_page; $i <= $end_page; $i++) {
            $pageurl = new moodle_url($baseurl, ['page' => $i]);
            $pageurl->params($sharedparams);
            
            if ($i == $page) {
                $pagination .= html_writer::tag('li',
                    html_writer::span($i + 1, 'page-link'),
                    ['class' => 'page-item active', 'aria-current' => 'page']
                );
            } else {
                $pagination .= html_writer::tag('li',
                    html_writer::link($pageurl, $i + 1, ['class' => 'page-link']),
                    ['class' => 'page-item']
                );
            }
        }
        
        // Last page if not in range
        if ($page < $total_pages - 3) {
            if ($page < $total_pages - 4) {
                $pagination .= html_writer::tag('li',
                    html_writer::span('...', 'page-link'),
                    ['class' => 'page-item disabled']
                );
            }
            
            $lasturl = new moodle_url($baseurl, ['page' => $total_pages - 1]);
            $lasturl->params($sharedparams);
            $pagination .= html_writer::tag('li',
                html_writer::link($lasturl, $total_pages, ['class' => 'page-link']),
                ['class' => 'page-item']
            );
        }
        
        // Next
        if ($page < $total_pages - 1) {
            $nexturl = new moodle_url($baseurl, ['page' => $page + 1]);
            $nexturl->params($sharedparams);
            $pagination .= html_writer::tag('li',
                html_writer::link($nexturl, get_string('next') . ' ›', ['class' => 'page-link']),
                ['class' => 'page-item']
            );
        } else {
            $pagination .= html_writer::tag('li',
                html_writer::span(get_string('next') . ' ›', 'page-link'),
                ['class' => 'page-item disabled']
            );
        }
        
        $pagination .= html_writer::end_tag('ul');
        $pagination .= html_writer::end_tag('nav');
    }
    
    return $pagination;
}

require_login();
$systemcontext = context_system::instance();

// Capability check: if a course filter is requested, allow either site-level
// `local/xlate:manage` or course-level `local/xlate:managecourse` for that
// specific course. If no course is requested, require the site-level manage
// capability.

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$keyid = optional_param('keyid', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 10, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$status_filter = optional_param('status_filter', '', PARAM_ALPHA);
$filter_courseid = optional_param('courseid', 0, PARAM_INT);
$langfilterraw = optional_param_array('langfilter', [], PARAM_ALPHANUMEXT);
$langfiltersubmitted = optional_param('langfiltersubmitted', 0, PARAM_BOOL);

// Determine the page context based on optional course filter so the page
// context and capability checks are correct for course-level managers.
$pagecontext = $systemcontext;
if (!empty($filter_courseid) && $filter_courseid > 0) {
    try {
        $course = get_course($filter_courseid);
        $pagecontext = context_course::instance($course->id);
        // Allow if system manager or course manager
        if (!has_capability('local/xlate:manage', $systemcontext) && !has_capability('local/xlate:managecourse', $pagecontext)) {
            // Neither capability present — deny access.
            require_capability('local/xlate:manage', $systemcontext);
        }
    } catch (dml_missing_record_exception $e) {
        // Invalid course id — treat as no access
        require_capability('local/xlate:manage', $systemcontext);
    }
} else {
    // No course filter: require site-level manage capability.
    require_capability('local/xlate:manage', $systemcontext);
}

$PAGE->set_context($pagecontext);
// $PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('admin_manage_translations', 'local_xlate'));
$PAGE->set_heading(get_string('admin_manage_translations', 'local_xlate'));

// Determine enabled/source/target languages using the custom field helper and
// sanitize any submitted language filter selections before the page URL is set
// (the URL is used by redirects further down).
$installedlangs = get_string_manager()->get_list_of_translations();
$langconfig = \local_xlate\customfield_helper::resolve_languages($filter_courseid > 0 ? $filter_courseid : null);
$enabledlangsarray = $langconfig['enabled'];
$displaysourcelang = $langconfig['source'];
$selectedtargets = $langconfig['targets'];
$langfilterselection = [];
$enabledlookup = array_flip($enabledlangsarray);
if ($langfiltersubmitted) {
    $langfilterraw = is_array($langfilterraw) ? $langfilterraw : [];
    foreach ($langfilterraw as $langcode) {
        if (isset($enabledlookup[$langcode])) {
            $langfilterselection[] = $langcode;
        }
    }
} else {
    if (!empty($filter_courseid) && !empty($selectedtargets)) {
        foreach ($selectedtargets as $langcode) {
            if (isset($enabledlookup[$langcode])) {
                $langfilterselection[] = $langcode;
            }
        }
    }
    if (empty($langfilterselection)) {
        $langfilterselection = $enabledlangsarray;
    }
}
$langfilterlookup = array_flip($langfilterselection);

$pageparams = [
    'page' => $page,
    'perpage' => $perpage,
    'search' => $search,
    'status_filter' => $status_filter,
    'courseid' => $filter_courseid
];
if ($langfiltersubmitted) {
    $pageparams['langfiltersubmitted'] = 1;
    if (!empty($langfilterselection)) {
        $pageparams['langfilter'] = $langfilterselection;
    }
}
$PAGE->set_url(new moodle_url('/local/xlate/manage.php', $pageparams));

if (($action === 'save_translation' || $action === 'savetranslation') && confirm_sesskey()) {
    $keyid = required_param('keyid', PARAM_INT);
    $targetlang = required_param('target_lang', PARAM_ALPHA);
    $translation = required_param('translation', PARAM_RAW);
    $status = optional_param('status', 0, PARAM_INT);
    $reviewed = optional_param('reviewed', 0, PARAM_INT);
    
    // Only save if there's actual translation text
    if (empty(trim($translation))) {
        redirect($PAGE->url, get_string('translation_empty', 'local_xlate'), null, \core\output\notification::NOTIFY_ERROR);
    }
    
    // Check if translation exists
    $existing = $DB->get_record('local_xlate_tr', ['keyid' => $keyid, 'lang' => $targetlang]);
    
    try {
        if ($existing) {
            $existing->text = $translation;
            $existing->status = $status;
            $existing->reviewed = $reviewed;
            $existing->mtime = time();
            $DB->update_record('local_xlate_tr', $existing);
        } else {
            $trrecord = new stdClass();
            $trrecord->keyid = $keyid;
            $trrecord->lang = $targetlang;
            $trrecord->text = $translation;
            $trrecord->status = $status;
            $trrecord->reviewed = $reviewed;
            $trrecord->ctime = time();
            $trrecord->mtime = time();
            $DB->insert_record('local_xlate_tr', $trrecord);
        }
        
        redirect($PAGE->url, get_string('translation_saved', 'local_xlate'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (Exception $e) {
        redirect($PAGE->url, 'Database error: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
require_once(__DIR__ . '/admin_nav.php');
local_xlate_render_admin_nav('manage');
// echo $OUTPUT->heading(get_string('manage_translations', 'local_xlate'));

// (Autotranslate controls are rendered below inside a styled card.)

// Show the autotranslate card only when a course filter is active so that
// course-scoped autotranslate can run against the specified course.
/* Autotranslate card intentionally disabled; preserve code for future use.
if ($filter_courseid > 0) {
    echo html_writer::start_div('card mb-4');
    echo html_writer::div(get_string('autotranslate_heading', 'local_xlate'), 'card-header');
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('row align-items-center');

    echo html_writer::start_div('col-md-9');
    echo html_writer::tag('label', get_string('autotranslate_target', 'local_xlate'), ['class' => 'me-3 mb-2 d-block']);
    $options = [];
    foreach ($enabledlangsarray as $langcode) {
        if ($langcode === $displaysourcelang) {
            continue;
        }
        $options[$langcode] = isset($installedlangs[$langcode]) ? $installedlangs[$langcode] . ' (' . $langcode . ')' : $langcode;
    }
    echo html_writer::start_div('d-flex flex-wrap gap-2', ['id' => 'local_xlate_target_container']);
    if (empty($options)) {
        echo html_writer::div(get_string('autotranslate_no_targets', 'local_xlate'), 'text-muted small');
    } else {
        foreach ($options as $langcode => $label) {
            $id = 'local_xlate_target_' . $langcode;
            $checked = in_array($langcode, $selectedtargets) ? 'checked' : null;
            echo html_writer::start_div('form-check form-check-inline');
            echo html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'local_xlate_target[]',
                'id' => $id,
                'value' => $langcode,
                'class' => 'form-check-input',
                'checked' => $checked
            ]);
            echo html_writer::tag('label', $label, ['for' => $id, 'class' => 'form-check-label']);
            echo html_writer::end_div();
        }
    }
    echo html_writer::end_div();

    $progresshtml = '<div id="local_xlate_course_progress" style="display:none; margin-top:12px">'
        . '<div class="progress" role="progressbar" aria-label="Autotranslate progress">'
        . '<div id="local_xlate_course_progress_bar" class="progress-bar" style="width:0%" aria-valuemin="0" aria-valuemax="100">0%</div>'
        . '</div>'
        . '<div id="local_xlate_course_progress_text" style="margin-top:6px; font-size:90%">0 / 0</div>'
        . '</div>';
    echo $progresshtml;

    echo html_writer::end_div();

    echo html_writer::start_div('col-md-3 text-end');
    echo html_writer::tag('label', '&nbsp;');
    echo html_writer::tag('button', get_string('autotranslate_course', 'local_xlate'), [
        'type' => 'button',
        'id' => 'local_xlate_autotranslate_course',
        'class' => 'btn btn-secondary'
    ]);
    echo html_writer::end_div();

    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
*/

// Automatic key capture info removed to save vertical space.

// Search and filter form
echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('search_and_filter', 'local_xlate'), 'card-header');
echo html_writer::start_div('card-body');

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'langfiltersubmitted',
    'value' => '1'
]);
echo html_writer::start_div('row');

// Search box
echo html_writer::start_div('col-md-3');
echo html_writer::tag('label', get_string('search', 'local_xlate'), ['for' => 'search']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'search',
    'name' => 'search',
    'value' => s($search),
    'class' => 'form-control',
    'placeholder' => get_string('search_placeholder', 'local_xlate')
]);
echo html_writer::end_div();

// (component filter removed)

// Status filter
echo html_writer::start_div('col-md-2');
echo html_writer::tag('label', get_string('status', 'local_xlate'), ['for' => 'status_filter']);
$status_options = [
    '' => get_string('all_statuses', 'local_xlate'),
    'translated' => get_string('fully_translated', 'local_xlate'),
    'partial' => get_string('partially_translated', 'local_xlate'),
    'untranslated' => get_string('untranslated', 'local_xlate')
];
echo html_writer::select($status_options, 'status_filter', $status_filter, false, ['class' => 'form-control']);
echo html_writer::end_div();

// Per page
echo html_writer::start_div('col-md-2');
echo html_writer::tag('label', get_string('per_page', 'local_xlate'), ['for' => 'perpage']);
$perpage_options = [5 => '5', 10 => '10', 25 => '25', 50 => '50', 100 => '100'];
echo html_writer::select($perpage_options, 'perpage', $perpage, false, ['class' => 'form-control']);
echo html_writer::end_div();

// Course filter
echo html_writer::start_div('col-md-2');
echo html_writer::tag('label', get_string('courseid', 'local_xlate'), ['for' => 'courseid']);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'courseid',
    'name' => 'courseid',
    'value' => $filter_courseid,
    'class' => 'form-control',
    'placeholder' => 'Course ID'
]);
echo html_writer::end_div();

// Search button
echo html_writer::start_div('col-md-3');
echo html_writer::tag('label', '&nbsp;');
echo html_writer::tag('button', get_string('filter', 'local_xlate'), [
    'type' => 'submit',
    'class' => 'btn btn-primary form-control'
]);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('row mt-3');
echo html_writer::start_div('col-12');
echo html_writer::tag('label', get_string('language_filter', 'local_xlate'), ['class' => 'form-label d-block']);
echo html_writer::tag('div', get_string('language_filter_hint', 'local_xlate'), ['class' => 'text-muted small mb-2']);
$filteroptionsrendered = false;
echo html_writer::start_div('d-flex flex-wrap gap-2', ['id' => 'local_xlate_langfilter']);
foreach ($enabledlangsarray as $langcode) {
    if ($langcode === $displaysourcelang || !isset($installedlangs[$langcode])) {
        continue;
    }
    $filteroptionsrendered = true;
    $id = 'langfilter_' . $langcode;
    $checked = in_array($langcode, $langfilterselection, true) ? 'checked' : null;
    $label = $installedlangs[$langcode];
    if (strpos($label, '(' . $langcode . ')') === false) {
        $label .= ' (' . $langcode . ')';
    }
    echo html_writer::start_div('form-check form-check-inline mb-1');
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'class' => 'form-check-input',
        'name' => 'langfilter[]',
        'id' => $id,
        'value' => $langcode,
        'checked' => $checked
    ]);
    echo html_writer::tag('label', $label, ['for' => $id, 'class' => 'form-check-label']);
    echo html_writer::end_div();
}
if (!$filteroptionsrendered) {
    echo html_writer::div(get_string('language_filter_empty', 'local_xlate'), 'text-muted fst-italic');
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo html_writer::end_div();
echo html_writer::end_div();

// Build the main query with filters
$where_conditions = [];
$params = [];

// Search filter
if (!empty($search)) {
    $where_conditions[] = "(k.component LIKE ? OR k.xkey LIKE ? OR k.source LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// (component filter removed)

$where_clause = '';

// If course filter is present and > 0, add EXISTS condition to where conditions so
// it is included in both the count and main queries. A courseid of 0 means "all".
if (!empty($filter_courseid) && $filter_courseid > 0) {
    $where_conditions[] = "EXISTS (SELECT 1 FROM {local_xlate_key_course} kc WHERE kc.keyid = k.id AND kc.courseid = ?)";
    $params[] = $filter_courseid;
}

if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Prefer ordering by creation time (ctime) when available in the schema; fall back
// to modification time (mtime) otherwise. Use the DB manager to detect the
// presence of the ctime column so we don't hard-fail on older schemas.
$timename = 'mtime';
try {
    $dbmanager = $DB->get_manager();
    if ($dbmanager->field_exists('local_xlate_key', 'ctime')) {
        $timename = 'ctime';
    }
} catch (\dml_exception $e) {
    // If we can't check the schema for some reason, keep using mtime.
}

// Count total records for pagination
$count_sql = "SELECT COUNT(DISTINCT k.id)
              FROM {local_xlate_key} k
              LEFT JOIN {local_xlate_tr} t ON k.id = t.keyid AND t.status = 1
              $where_clause";
              
$total_count = $DB->count_records_sql($count_sql, $params);

// Main query with pagination

$sql = "SELECT k.*, COUNT(DISTINCT t.lang) as translation_count
    FROM {local_xlate_key} k
    LEFT JOIN {local_xlate_tr} t ON k.id = t.keyid AND t.status = 1
    $where_clause
    GROUP BY k.id, k.component, k.xkey, k.source, k." . $timename . "
    ORDER BY k." . $timename . " DESC";

$keys = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

// Apply status filter after getting results (easier with aggregated data)
if (!empty($status_filter)) {
    $enabled_lang_count = count($enabledlangsarray);
    $filtered_keys = [];
    
    foreach ($keys as $key) {
        $include = false;
        switch ($status_filter) {
            case 'translated':
                $include = ($key->translation_count == $enabled_lang_count);
                break;
            case 'partial':
                $include = ($key->translation_count > 0 && $key->translation_count < $enabled_lang_count);
                break;
            case 'untranslated':
                $include = ($key->translation_count == 0);
                break;
        }
        if ($include) {
            $filtered_keys[] = $key;
        }
    }
    $keys = $filtered_keys;
}

if (!empty($keys)) {
    // Pagination info
    $start_record = $page * $perpage + 1;
    $end_record = min(($page + 1) * $perpage, $total_count);
    
    echo html_writer::start_div('card');
    echo html_writer::div(
        get_string('translation_keys_pagination', 'local_xlate', [
            'start' => $start_record,
            'end' => $end_record,
            'total' => $total_count
        ]), 
        'card-header d-flex justify-content-between'
    );
    
    // Pagination controls (top)
    if ($total_count > $perpage) {
        echo html_writer::start_div('card-body pb-2 border-bottom');
        echo html_writer::div(
            render_pagination_controls(
                $PAGE->url,
                $page,
                $perpage,
                $total_count,
                $search,
                $status_filter,
                $filter_courseid,
                $langfilterselection,
                $langfiltersubmitted
            ),
            'd-flex justify-content-center'
        );
        echo html_writer::end_div();
    }
    
    echo html_writer::start_div('card-body');
    
    foreach ($keys as $key) {
        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-header d-flex justify-content-between');
        echo html_writer::tag('strong', $key->component . '.' . $key->xkey);
        echo html_writer::tag('small', 'Translated: ' . $key->translation_count . '/' . count($enabledlangsarray));
        echo html_writer::end_div();
        
        echo html_writer::start_div('card-body');
        // Show source text in a row styled like the translation fields.
        $orderedlangs = $enabledlangsarray;
        if (!in_array($displaysourcelang, $orderedlangs, true)) {
            $orderedlangs[] = $displaysourcelang;
        }
        $displaylangs = [];
        foreach ($orderedlangs as $langcode) {
            if ($langcode === $displaysourcelang || isset($langfilterlookup[$langcode])) {
                $displaylangs[] = $langcode;
            }
        }
        if (empty($displaylangs)) {
            $displaylangs[] = $displaysourcelang;
        }
        
        // Show translations for selected languages (source row appears first and is disabled)
        foreach ($displaylangs as $langcode) {
            if (!isset($installedlangs[$langcode])) {
                continue;
            }

            $is_source = ($langcode === $displaysourcelang);
            $translation = $DB->get_record('local_xlate_tr', ['keyid' => $key->id, 'lang' => $langcode]);
            $label = $installedlangs[$langcode];
            if (strpos($label, '(' . $langcode . ')') === false) {
                $label .= ' (' . $langcode . ')';
            }

            $usesource = (strlen($key->source) > 80 || strpos($key->source, "\n") !== false);
            $rtl_langs = ['ar', 'he', 'fa', 'ur', 'ps', 'syr', 'dv', 'yi'];
            $rtl = in_array($langcode, $rtl_langs);
            $dir = $rtl ? 'rtl' : 'ltr';
            $align = $rtl ? 'right' : 'left';

            if ($is_source) {
                echo html_writer::start_div('row align-items-center mb-2');
            } else {
                echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url, 'class' => 'mb-2']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_translation']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'keyid', 'value' => $key->id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'target_lang', 'value' => $langcode]);
                echo html_writer::start_div('row align-items-center');
            }

            echo html_writer::start_div('col-md-2');
            $labelformat = $is_source ? html_writer::tag('strong', $label) : $label;
            echo html_writer::tag('label', $labelformat);
            echo html_writer::end_div();

            echo html_writer::start_div('col-md-6');
            $displayval = $is_source ? $key->source : ($translation ? $translation->text : '');
            if ($usesource) {
                $sourcelines = max(1, substr_count($key->source, "\n") + 1);
                $rows = max(3, min(8, $sourcelines, ceil(strlen($key->source)/80)));
                $textarea_attrs = [
                    'name' => 'translation',
                    'class' => 'form-control',
                    'rows' => $rows,
                    'dir' => $dir,
                    'style' => 'text-align:' . $align
                ];
                if ($is_source) {
                    $textarea_attrs['readonly'] = 'readonly';
                    $textarea_attrs['disabled'] = 'disabled';
                    $textarea_attrs['style'] .= '; background-color: #e9ecef;';
                } else {
                    $textarea_attrs['placeholder'] = 'Enter translation...';
                }
                echo html_writer::tag('textarea', s($displayval), $textarea_attrs);
            } else {
                $input_attrs = [
                    'name' => 'translation',
                    'type' => 'text',
                    'class' => 'form-control',
                    'dir' => $dir,
                    'style' => 'text-align:' . $align
                ];
                if ($is_source) {
                    $input_attrs['value'] = $displayval;
                    $input_attrs['readonly'] = 'readonly';
                    $input_attrs['disabled'] = 'disabled';
                    // $input_attrs['style'] .= '; background-color: #e9ecef;';
                } else {
                    $input_attrs['value'] = $displayval;
                    $input_attrs['placeholder'] = 'Enter translation...';
                }
                echo html_writer::empty_tag('input', $input_attrs);
            }
            echo html_writer::end_div();

            if ($is_source) {
                echo html_writer::div('', 'col-md-1 d-flex align-items-center text-muted small');
                echo html_writer::div('', 'col-md-1 d-flex align-items-center text-muted small');
                echo html_writer::div('', 'col-md-2 d-flex align-items-center text-muted small');
                echo html_writer::end_div();
            } else {
                echo html_writer::start_div('col-md-1 d-flex align-items-center');
                $checked = $translation && $translation->status ? true : false;
                $active_input = html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'status',
                    'value' => '1',
                    'checked' => $checked ? 'checked' : null,
                    'class' => 'me-1'
                ]);
                echo html_writer::tag('label', $active_input . ' ' . get_string('active', 'local_xlate'), ['class' => 'form-check-label mb-0 text-nowrap small']);
                echo html_writer::end_div();

                echo html_writer::start_div('col-md-1 d-flex align-items-center');
                $rchecked = $translation && isset($translation->reviewed) && $translation->reviewed ? true : false;
                $review_input = html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'reviewed',
                    'value' => '1',
                    'checked' => $rchecked ? 'checked' : null,
                    'class' => 'me-1'
                ]);
                echo html_writer::tag('label', $review_input . ' ' . get_string('reviewed', 'local_xlate'), ['class' => 'form-check-label mb-0 text-nowrap small']);
                echo html_writer::end_div();
                echo html_writer::start_div('col-md-2');
                echo html_writer::tag('button', get_string('save_translation', 'local_xlate'), [
                    'type' => 'submit',
                    'class' => 'btn btn-sm btn-success'
                ]);
                echo html_writer::end_div();
                echo html_writer::end_div();
                echo html_writer::end_tag('form');
            }
        }
        
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    
    echo html_writer::end_div();
    
    // Pagination controls (bottom)
    if ($total_count > $perpage) {
        echo html_writer::start_div('card-body pt-2 border-top');
        echo html_writer::div(
            render_pagination_controls(
                $PAGE->url,
                $page,
                $perpage,
                $total_count,
                $search,
                $status_filter,
                $filter_courseid,
                $langfilterselection,
                $langfiltersubmitted
            ),
            'd-flex justify-content-center'
        );
        echo html_writer::end_div();
    }
    
    echo html_writer::end_div();
} else {
    $message = 'No translation keys found.';
    if (!empty($search) || !empty($status_filter) || !empty($filter_courseid)) {
        $message = get_string('no_results_found', 'local_xlate');
    } else {
        $message = get_string('no_keys_found', 'local_xlate');
    }
    
    echo html_writer::div(
        html_writer::tag('p', $message),
        'alert alert-info'
    );
}

// Output capture/exclude selectors to JS for admin UI as well
$capture_selectors = get_config('local_xlate', 'capture_selectors');
$exclude_selectors = get_config('local_xlate', 'exclude_selectors');
echo html_writer::script('window.XLATE_CAPTURE_SELECTORS = ' . json_encode($capture_selectors ? preg_split('/\r?\n/', $capture_selectors, -1, PREG_SPLIT_NO_EMPTY) : []) . ";\n" .
    'window.XLATE_EXCLUDE_SELECTORS = ' . json_encode($exclude_selectors ? preg_split('/\r?\n/', $exclude_selectors, -1, PREG_SPLIT_NO_EMPTY) : []) . ";");

/* Autotranslate AMD init disabled with UI card.
$amdconfig = [...];
try {
    // Resume pending jobs for AMD module.
} catch (Exception $e) {
    // ignore
}
try {
    $gloss = [];
    $amdconfig['glossary'] = $gloss;
} catch (\Exception $ex) {
    $amdconfig['glossary'] = [];
}
$PAGE->requires->js_call_amd('local_xlate/autotranslate', 'init', [$amdconfig]);
*/

echo $OUTPUT->footer();