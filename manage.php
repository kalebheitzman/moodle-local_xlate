<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

/**
 * Render pagination controls
 */
function render_pagination_controls($baseurl, $page, $perpage, $total, $search, $component, $status_filter) {
    $total_pages = ceil($total / $perpage);
    $pagination = '';
    
    if ($total_pages > 1) {
        $pagination .= html_writer::start_tag('nav', ['aria-label' => 'Translation keys pagination']);
        $pagination .= html_writer::start_tag('ul', ['class' => 'pagination justify-content-center mb-0']);
        
        // Previous
        if ($page > 0) {
            $prevurl = new moodle_url($baseurl, [
                'page' => $page - 1, 'perpage' => $perpage, 
                'search' => $search, 'component_filter' => $component, 'status_filter' => $status_filter
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
                'search' => $search, 'component_filter' => $component, 'status_filter' => $status_filter
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
                'search' => $search, 'component_filter' => $component, 'status_filter' => $status_filter
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
                'search' => $search, 'component_filter' => $component, 'status_filter' => $status_filter
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
                'search' => $search, 'component_filter' => $component, 'status_filter' => $status_filter
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
$context = context_system::instance();
require_capability('local/xlate:manage', $context);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$keyid = optional_param('keyid', 0, PARAM_INT);
$lang = optional_param('lang', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 10, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$component = optional_param('component_filter', '', PARAM_ALPHANUMEXT);
$status_filter = optional_param('status_filter', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/xlate/manage.php', [
    'page' => $page,
    'perpage' => $perpage,
    'search' => $search,
    'component_filter' => $component,
    'status_filter' => $status_filter
]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('manage_translations', 'local_xlate'));
$PAGE->set_heading(get_string('manage_translations', 'local_xlate'));

if (($action === 'save_translation' || $action === 'savetranslation') && confirm_sesskey()) {
    $keyid = required_param('keyid', PARAM_INT);
    $lang = required_param('lang', PARAM_ALPHA);
    $translation = required_param('translation', PARAM_RAW);
    $status = optional_param('status', 0, PARAM_INT);
    
    // Only save if there's actual translation text
    if (empty(trim($translation))) {
        redirect($PAGE->url, get_string('translation_empty', 'local_xlate'), null, \core\output\notification::NOTIFY_ERROR);
    }
    
    // Check if translation exists
    $existing = $DB->get_record('local_xlate_tr', ['keyid' => $keyid, 'lang' => $lang]);
    
    try {
        if ($existing) {
            $existing->text = $translation;
            $existing->status = $status;
            $existing->mtime = time();
            $DB->update_record('local_xlate_tr', $existing);
        } else {
            $trrecord = new stdClass();
            $trrecord->keyid = $keyid;
            $trrecord->lang = $lang;
            $trrecord->text = $translation;
            $trrecord->status = $status;
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
echo $OUTPUT->heading(get_string('manage_translations', 'local_xlate'));

// Get enabled languages
$enabledlangs = get_config('local_xlate', 'enabled_languages');
$enabledlangsarray = empty($enabledlangs) ? ['en'] : explode(',', $enabledlangs);
$installedlangs = get_string_manager()->get_list_of_translations();

// Information card describing automatic key capture
echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('automatic_keys_heading', 'local_xlate'), 'card-header');
echo html_writer::start_div('card-body');
echo html_writer::tag('p', get_string('automatic_keys_description', 'local_xlate'));
echo html_writer::tag('p', get_string('automatic_keys_hint', 'local_xlate'), ['class' => 'mb-0 text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();

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

// Component filter
echo html_writer::start_div('col-md-2');
echo html_writer::tag('label', get_string('component', 'local_xlate'), ['for' => 'component_filter']);
$components = $DB->get_records_sql("SELECT DISTINCT component FROM {local_xlate_key} ORDER BY component");
$component_options = ['' => get_string('all_components', 'local_xlate')];
foreach ($components as $comp) {
    $component_options[$comp->component] = $comp->component;
}
echo html_writer::select($component_options, 'component_filter', $component, false, ['class' => 'form-control']);
echo html_writer::end_div();

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

// Component filter
if (!empty($component)) {
    $where_conditions[] = "k.component = ?";
    $params[] = $component;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
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
        GROUP BY k.id, k.component, k.xkey, k.source, k.mtime
        ORDER BY k.component, k.xkey";

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
        echo html_writer::div(render_pagination_controls($PAGE->url, $page, $perpage, $total_count, $search, $component, $status_filter), 'd-flex justify-content-center');
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
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lang', 'value' => $langcode]);
                echo html_writer::start_div('row align-items-center');
                echo html_writer::start_div('col-md-2');
                echo html_writer::tag('label', $installedlangs[$langcode] . ' (' . $langcode . ')');
                echo html_writer::end_div();
                echo html_writer::start_div('col-md-6');
                $trval = $translation ? $translation->text : '';
                // Use textarea if the SOURCE is long or multiline
                $usesource = (strlen($key->source) > 80 || strpos($key->source, "\n") !== false);
                if ($usesource) {
                    // Guess rows: number of lines in source, or based on length, min 3, max 8
                    $sourcelines = max(1, substr_count($key->source, "\n") + 1);
                    $rows = max(3, min(8, $sourcelines, ceil(strlen($key->source)/80)));
                    echo html_writer::tag('textarea', s($trval), [
                        'name' => 'translation',
                        'class' => 'form-control',
                        'rows' => $rows,
                        'placeholder' => 'Enter translation...'
                    ]);
                } else {
                    echo html_writer::empty_tag('input', [
                        'type' => 'text',
                        'name' => 'translation',
                        'value' => $trval,
                        'class' => 'form-control',
                        'placeholder' => 'Enter translation...'
                    ]);
                }
                echo html_writer::end_div();
                echo html_writer::start_div('col-md-2');
                $checked = $translation && $translation->status ? true : false;
                echo html_writer::tag('label', 
                    html_writer::empty_tag('input', [
                        'type' => 'checkbox',
                        'name' => 'status',
                        'value' => '1',
                        'checked' => $checked ? 'checked' : null
                    ]) . ' ' . get_string('active', 'local_xlate'),
                    ['class' => 'form-check-label']
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
        echo html_writer::div(render_pagination_controls($PAGE->url, $page, $perpage, $total_count, $search, $component, $status_filter), 'd-flex justify-content-center');
        echo html_writer::end_div();
    }
    
    echo html_writer::end_div();
} else {
    $message = 'No translation keys found.';
    if (!empty($search) || !empty($component) || !empty($status_filter)) {
        $message = get_string('no_results_found', 'local_xlate');
    } else {
        $message = get_string('no_keys_found', 'local_xlate');
    }
    
    echo html_writer::div(
        html_writer::tag('p', $message),
        'alert alert-info'
    );
}

echo $OUTPUT->footer();