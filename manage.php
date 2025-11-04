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
function render_pagination_controls($baseurl, $page, $perpage, $total, $search, $status_filter, $courseid = 0) {
    $total_pages = ceil($total / $perpage);
    $pagination = '';
    
    if ($total_pages > 1) {
        $pagination .= html_writer::start_tag('nav', ['aria-label' => 'Translation keys pagination']);
        $pagination .= html_writer::start_tag('ul', ['class' => 'pagination justify-content-center mb-0']);
        
        // Previous
        if ($page > 0) {
            $prevurl = new moodle_url($baseurl, [
                'page' => $page - 1, 'perpage' => $perpage, 
                'search' => $search, 'status_filter' => $status_filter, 'courseid' => $courseid
            ]);
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
            $firsturl = new moodle_url($baseurl, [
                'page' => 0, 'perpage' => $perpage,
                'search' => $search, 'status_filter' => $status_filter, 'courseid' => $courseid
            ]);
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
            $pageurl = new moodle_url($baseurl, [
                'page' => $i, 'perpage' => $perpage,
                'search' => $search, 'status_filter' => $status_filter, 'courseid' => $courseid
            ]);
            
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
            
            $lasturl = new moodle_url($baseurl, [
                'page' => $total_pages - 1, 'perpage' => $perpage,
                'search' => $search, 'status_filter' => $status_filter, 'courseid' => $courseid
            ]);
            $pagination .= html_writer::tag('li',
                html_writer::link($lasturl, $total_pages, ['class' => 'page-link']),
                ['class' => 'page-item']
            );
        }
        
        // Next
        if ($page < $total_pages - 1) {
            $nexturl = new moodle_url($baseurl, [
                'page' => $page + 1, 'perpage' => $perpage,
                'search' => $search, 'status_filter' => $status_filter, 'courseid' => $courseid
            ]);
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

$PAGE->set_url(new moodle_url('/local/xlate/manage.php', [
    'page' => $page,
    'perpage' => $perpage,
    'search' => $search,
    'status_filter' => $status_filter,
    'courseid' => $filter_courseid
]));
$PAGE->set_context($pagecontext);
// $PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('admin_manage_translations', 'local_xlate'));
$PAGE->set_heading(get_string('admin_manage_translations', 'local_xlate'));

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

// Get enabled languages
$enabledlangs = get_config('local_xlate', 'enabled_languages');
$enabledlangsarray = empty($enabledlangs) ? ['en'] : explode(',', $enabledlangs);
$installedlangs = get_string_manager()->get_list_of_translations();

// Site language info (used for labels and to exclude from target options).
$sitelang = $CFG->lang;
$sitelangname = isset($installedlangs[$sitelang]) ? $installedlangs[$sitelang] : $sitelang;

// Default target: pick the first enabled language that is not the site language, if any.
$defaulttarget = '';
foreach ($enabledlangsarray as $candidate) {
    if ($candidate !== $sitelang) {
        $defaulttarget = $candidate;
        break;
    }
}

// Pre-select default target(s). Support either a single string or an array.
$selectedtargets = is_array($defaulttarget) ? $defaulttarget : ($defaulttarget ? [$defaulttarget] : []);
// Show the autotranslate card only when a course filter is active so that
// course-scoped autotranslate can run against the specified course.
if ($filter_courseid > 0) {
    echo html_writer::start_div('card mb-4');
    echo html_writer::div(get_string('autotranslate_heading', 'local_xlate'), 'card-header');
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('row align-items-center');

    echo html_writer::start_div('col-md-9');
    echo html_writer::tag('label', get_string('autotranslate_target', 'local_xlate'), ['class' => 'me-3 mb-2 d-block']);
    $options = [];
    foreach ($enabledlangsarray as $langcode) {
        if ($langcode === $sitelang) {
            continue;
        }
        $options[$langcode] = isset($installedlangs[$langcode]) ? $installedlangs[$langcode] . ' (' . $langcode . ')' : $langcode;
    }
    if (empty($options)) {
        // If no non-site enabled languages, include site language as fallback.
        $options[$sitelang] = $sitelangname . ' (' . $sitelang . ')';
    }

    // Render inline checkboxes
    echo html_writer::start_div('d-flex flex-wrap gap-2', ['id' => 'local_xlate_target_container']);
    foreach ($options as $langcode => $label) {
        $id = 'local_xlate_target_' . $langcode;
        $checked = in_array($langcode, $selectedtargets) ? 'checked' : null;
        // form-check form-check-inline for compact horizontal layout
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
    echo html_writer::end_div();

    // Inline Moodle-style progress indicator (hidden until a job is started).
    // Single job-level progress: progress bar + numeric counter below the language checkboxes.
    $progresshtml = '<div id="local_xlate_course_progress" style="display:none; margin-top:12px">'
        . '<div class="progress" role="progressbar" aria-label="Autotranslate progress">'
        . '<div id="local_xlate_course_progress_bar" class="progress-bar" style="width:0%" aria-valuemin="0" aria-valuemax="100">0%</div>'
        . '</div>'
        . '<div id="local_xlate_course_progress_text" style="margin-top:6px; font-size:90%">0 / 0</div>'
        . '</div>';
    echo $progresshtml;

    echo html_writer::end_div(); // col-md-9

    // Button column (only the course-level autotranslate button is shown here)
    echo html_writer::start_div('col-md-3 text-end');
    echo html_writer::tag('label', '&nbsp;');
    echo html_writer::tag('button', get_string('autotranslate_course', 'local_xlate'), [
        'type' => 'button',
        'id' => 'local_xlate_autotranslate_course',
        'class' => 'btn btn-secondary'
    ]);
    echo html_writer::end_div();

    echo html_writer::end_div(); // row
    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card
}

// Automatic key capture info removed to save vertical space.

// Search and filter form
echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('search_and_filter', 'local_xlate'), 'card-header');
echo html_writer::start_div('card-body');

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url]);
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
        echo html_writer::div(render_pagination_controls($PAGE->url, $page, $perpage, $total_count, $search, $status_filter, $filter_courseid), 'd-flex justify-content-center');
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
    // Show source text in a row styled like the translation fields
    $sitelang = $CFG->lang;
    $sitelangname = isset($installedlangs[$sitelang]) ? $installedlangs[$sitelang] : $sitelang;
    echo html_writer::start_div('row align-items-center mb-2');
    // Label column (same as translation label col-md-2)
    echo html_writer::start_div('col-md-2 d-flex align-items-center');
    echo html_writer::tag('label', 'Source (' . $sitelangname . ' ' . $sitelang . ')', ['class' => 'fw-bold mb-0']);
    echo html_writer::end_div();
    // Source text column (same as translation input col-md-6)
    echo html_writer::start_div('col-md-6 d-flex align-items-center');
    echo html_writer::div(s($key->source), 'form-control-plaintext mb-0');
    echo html_writer::end_div();
    // Empty columns for checkbox and button (col-md-2 each)
    echo html_writer::start_div('col-md-2');
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2');
    echo html_writer::end_div();
    echo html_writer::end_div();
        
        // Show translations for each enabled language except the site language
        foreach ($enabledlangsarray as $langcode) {
            if ($langcode === $sitelang) {
                continue;
            }
            if (isset($installedlangs[$langcode])) {
                $translation = $DB->get_record('local_xlate_tr', ['keyid' => $key->id, 'lang' => $langcode]);
                echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url, 'class' => 'mb-2']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_translation']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'keyid', 'value' => $key->id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'target_lang', 'value' => $langcode]);
                echo html_writer::start_div('row align-items-center');
                echo html_writer::start_div('col-md-2');
                echo html_writer::tag('label', $installedlangs[$langcode] . ' (' . $langcode . ')');
                echo html_writer::end_div();
                echo html_writer::start_div('col-md-6');
                $trval = $translation ? $translation->text : '';
                // Use textarea if the SOURCE is long or multiline
                $usesource = (strlen($key->source) > 80 || strpos($key->source, "\n") !== false);
                // List of standard RTL language codes.
                $rtl_langs = ['ar', 'he', 'fa', 'ur', 'ps', 'syr', 'dv', 'yi'];
                $rtl = in_array($langcode, $rtl_langs);
                $dir = $rtl ? 'rtl' : 'ltr';
                $align = $rtl ? 'right' : 'left';
                if ($usesource) {
                    // Guess rows: number of lines in source, or based on length, min 3, max 8
                    $sourcelines = max(1, substr_count($key->source, "\n") + 1);
                    $rows = max(3, min(8, $sourcelines, ceil(strlen($key->source)/80)));
                    echo html_writer::tag('textarea', s($trval), [
                        'name' => 'translation',
                        'class' => 'form-control',
                        'rows' => $rows,
                        'placeholder' => 'Enter translation...',
                        'dir' => $dir,
                        'style' => 'text-align:' . $align
                    ]);
                } else {
                    echo html_writer::empty_tag('input', [
                        'type' => 'text',
                        'name' => 'translation',
                        'value' => $trval,
                        'class' => 'form-control',
                        'placeholder' => 'Enter translation...',
                        'dir' => $dir,
                        'style' => 'text-align:' . $align
                    ]);
                }
                echo html_writer::end_div();
                // Active checkbox (compact)
                echo html_writer::start_div('col-md-1 d-flex align-items-center');
                $checked = $translation && $translation->status ? true : false;
                echo html_writer::tag('label', 
                    html_writer::empty_tag('input', [
                        'type' => 'checkbox',
                        'name' => 'status',
                        'value' => '1',
                        'checked' => $checked ? 'checked' : null,
                        'class' => 'me-1'
                    ]) . ' ' . get_string('active', 'local_xlate'),
                    ['class' => 'form-check-label mb-0 text-nowrap small']
                );
                echo html_writer::end_div();

                // Reviewed checkbox (compact)
                echo html_writer::start_div('col-md-1 d-flex align-items-center');
                $rchecked = $translation && isset($translation->reviewed) && $translation->reviewed ? true : false;
                echo html_writer::tag('label', 
                    html_writer::empty_tag('input', [
                        'type' => 'checkbox',
                        'name' => 'reviewed',
                        'value' => '1',
                        'checked' => $rchecked ? 'checked' : null,
                        'class' => 'me-1'
                    ]) . ' ' . get_string('reviewed', 'local_xlate'),
                    ['class' => 'form-check-label mb-0 text-nowrap small']
                );
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
        echo html_writer::div(render_pagination_controls($PAGE->url, $page, $perpage, $total_count, $search, $status_filter, $filter_courseid), 'd-flex justify-content-center');
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

// Provide a small JS config and initialize the autotranslate AMD module on the Manage page.
// (default target already computed earlier)

// Pass the selected targets (array) to the AMD module so it can queue one
// request per selected language. The frontend accepts either string or
// array; prefer array here since checkboxes allow multiple values.
$amdconfig = [
    'defaulttarget' => $selectedtargets,
    'courseid' => isset($course->id) ? $course->id : 0,
    'bundleurl' => (new moodle_url('/local/xlate/bundle.php'))->out(false),
    'lang' => $PAGE->course ? ($PAGE->course->lang ?? $CFG->lang) : $CFG->lang,
    'siteLang' => $CFG->lang
];

// If there's an active course autotranslate job for this course, pass its id
// to the AMD module so the frontend can resume polling after navigation.
try {
    if (!empty($amdconfig['courseid'])) {
        // If the current user has site-level manage capability, surface any
        // active job for the course (admin view). Otherwise only surface jobs
        // owned by the current user.
        if (has_capability('local/xlate:manage', $PAGE->context)) {
            $activejob = $DB->get_record_select(
                'local_xlate_course_job',
                'courseid = ? AND status IN (?,?,?)',
                [$amdconfig['courseid'], 'pending', 'running', 'processing'],
                '*', IGNORE_MULTIPLE
            );
        } else {
            global $USER;
            $activejob = $DB->get_record_select(
                'local_xlate_course_job',
                'courseid = ? AND userid = ? AND status IN (?,?,?)',
                [$amdconfig['courseid'], isset($USER->id) ? (int)$USER->id : 0, 'pending', 'running', 'processing'],
                '*', IGNORE_MULTIPLE
            );
        }
        if ($activejob && !empty($activejob->id)) {
            $amdconfig['currentjobid'] = (int)$activejob->id;
            // Add some lightweight job metadata to the AMD config so the UI
            // can show owner and status immediately without waiting for the
            // first poll response.
            $amdconfig['currentjobstatus'] = (string)$activejob->status;
            $amdconfig['currentjobprocessed'] = (int)$activejob->processed;
            $amdconfig['currentjobtotal'] = (int)$activejob->total;
            if (!empty($activejob->userid)) {
                try {
                    $jobuser = $DB->get_record('user', ['id' => (int)$activejob->userid]);
                    if ($jobuser) {
                        $amdconfig['currentjobowner'] = fullname($jobuser);
                    }
                } catch (Exception $e) {
                    // ignore failures fetching user info
                }
                // Expose job options (batchsize, targetlang, sourcelang) if present
                if (!empty($activejob->options)) {
                    try {
                        $opts = json_decode($activejob->options, true);
                        if (!empty($opts) && is_array($opts)) {
                            if (isset($opts['batchsize'])) {
                                $amdconfig['currentjobbatchsize'] = (int)$opts['batchsize'];
                            }
                            if (isset($opts['sourcelang'])) {
                                $amdconfig['currentjobsourcelang'] = (string)$opts['sourcelang'];
                            }
                            if (isset($opts['targetlang'])) {
                                $amdconfig['currentjobtargetlang'] = $opts['targetlang'];
                            }
                        }
                    } catch (Exception $e) {
                        // ignore JSON parse failures
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    // Non-fatal: if DB check fails, do not block page render. Frontend will
    // only start polling when a job is queued manually.
}

// Include a small client-side glossary payload for the default target so the
// AMD autotranslate module can pass it through to the backend. Keep it small
// (limited number of entries) to avoid bloating the client payload.
try {
    $gloss = [];
    if (!empty($defaulttarget)) {
        $entries = \local_xlate\glossary::get_by_target($defaulttarget, 200);
        foreach ($entries as $e) {
            $gloss[] = ['term' => $e->source_text, 'replacement' => $e->target_text];
        }
    }
    $amdconfig['glossary'] = $gloss;
} catch (\Exception $ex) {
    // Non-fatal: if glossary helpers fail, leave empty glossary to preserve behaviour.
    $amdconfig['glossary'] = [];
}

$PAGE->requires->js_call_amd('local_xlate/autotranslate', 'init', [$amdconfig]);

echo $OUTPUT->footer();