<?php
require_once(__DIR__ . '/../../config.php');
require_login();

use local_xlate\glossary as glossary_helper;

/**
 * Render pagination controls for glossary (page number style with ellipses)
 */
function glossary_render_pagination_controls($baseurl, $page, $perpage, $total, $search) {
    $total_pages = ceil($total / $perpage);
    $pagination = '';
    if ($total_pages > 1) {
        $pagination .= html_writer::start_tag('nav', ['aria-label' => 'Glossary pagination']);
        $pagination .= html_writer::start_tag('ul', ['class' => 'pagination justify-content-center mb-0']);

        // Previous
        if ($page > 0) {
            $prevurl = new moodle_url($baseurl, ['page' => $page - 1, 'perpage' => $perpage, 'search' => $search]);
            $pagination .= html_writer::tag('li', html_writer::link($prevurl, '‹ ' . get_string('previous'), ['class' => 'page-link']), ['class' => 'page-item']);
        } else {
            $pagination .= html_writer::tag('li', html_writer::span('‹ ' . get_string('previous'), 'page-link'), ['class' => 'page-item disabled']);
        }

        // First page if not in range
        if ($page > 2) {
            $firsturl = new moodle_url($baseurl, ['page' => 0, 'perpage' => $perpage, 'search' => $search]);
            $pagination .= html_writer::tag('li', html_writer::link($firsturl, '1', ['class' => 'page-link']), ['class' => 'page-item']);
            if ($page > 3) {
                $pagination .= html_writer::tag('li', html_writer::span('...', 'page-link'), ['class' => 'page-item disabled']);
            }
        }

        // Page numbers (current ±2)
        $start_page = max(0, $page - 2);
        $end_page = min($total_pages - 1, $page + 2);
        for ($i = $start_page; $i <= $end_page; $i++) {
            $pageurl = new moodle_url($baseurl, ['page' => $i, 'perpage' => $perpage, 'search' => $search]);
            if ($i == $page) {
                $pagination .= html_writer::tag('li', html_writer::span($i + 1, 'page-link'), ['class' => 'page-item active', 'aria-current' => 'page']);
            } else {
                $pagination .= html_writer::tag('li', html_writer::link($pageurl, $i + 1, ['class' => 'page-link']), ['class' => 'page-item']);
            }
        }

        // Last page if not in range
        if ($page < $total_pages - 3) {
            if ($page < $total_pages - 4) {
                $pagination .= html_writer::tag('li', html_writer::span('...', 'page-link'), ['class' => 'page-item disabled']);
            }
            $lasturl = new moodle_url($baseurl, ['page' => $total_pages - 1, 'perpage' => $perpage, 'search' => $search]);
            $pagination .= html_writer::tag('li', html_writer::link($lasturl, $total_pages, ['class' => 'page-link']), ['class' => 'page-item']);
        }

        // Next
        if ($page < $total_pages - 1) {
            $nexturl = new moodle_url($baseurl, ['page' => $page + 1, 'perpage' => $perpage, 'search' => $search]);
            $pagination .= html_writer::tag('li', html_writer::link($nexturl, get_string('next') . ' ›', ['class' => 'page-link']), ['class' => 'page-item']);
        } else {
            $pagination .= html_writer::tag('li', html_writer::span(get_string('next') . ' ›', 'page-link'), ['class' => 'page-item disabled']);
        }

        $pagination .= html_writer::end_tag('ul');
        $pagination .= html_writer::end_tag('nav');
    }
    return $pagination;
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 10, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);

$PAGE->set_url(new moodle_url('/local/xlate/glossary.php', ['page' => $page, 'perpage' => $perpage, 'search' => $search]));

$PAGE->set_context(context_system::instance());
// $PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('admin_manage_glossary', 'local_xlate'));
$PAGE->set_heading(get_string('admin_manage_glossary', 'local_xlate'));

require_capability('local/xlate:manage', $PAGE->context);

// Handle save action
if ($action === 'save_glossary' && confirm_sesskey()) {
    $source_lang = required_param('source_lang', PARAM_ALPHANUMEXT);
    $source_text = required_param('source_text', PARAM_RAW);
    $target_lang = required_param('target_lang', PARAM_ALPHANUMEXT);
    $target_text = required_param('target_text', PARAM_RAW);

    try {
        glossary_helper::save_translation($source_lang, $source_text, $target_lang, $target_text, $USER->id);
        redirect(new moodle_url('/local/xlate/glossary.php', ['page' => $page, 'perpage' => $perpage, 'search' => $search]), get_string('glossary_saved', 'local_xlate'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (Exception $e) {
        redirect(new moodle_url('/local/xlate/glossary.php', ['page' => $page, 'perpage' => $perpage, 'search' => $search]), 'Save failed: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Handle bulk add action (single-row multi-language form)
if ($action === 'bulk_add' && confirm_sesskey()) {
    $source_lang = required_param('source_lang', PARAM_ALPHANUMEXT);
    $source_text = required_param('source_text', PARAM_RAW_TRIMMED);

    // iterate all installed languages so the form matches processing
    $installedlangs = get_string_manager()->get_list_of_translations();

    $added = 0;
    foreach ($installedlangs as $langcode => $name) {
        // Skip if same as source_lang
        if ($langcode === $source_lang) {
            continue;
        }
        $field = 'target_' . $langcode;
        $val = optional_param($field, '', PARAM_RAW_TRIMMED);
        if ($val !== '') {
            try {
                glossary_helper::save_translation($source_lang, $source_text, $langcode, $val, $USER->id);
                $added++;
            } catch (Exception $e) {
                // ignore individual failures but continue
                debugging('[local_xlate] glossary bulk_add failed for ' . $langcode . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    redirect(new moodle_url('/local/xlate/glossary.php', ['page' => $page, 'perpage' => $perpage, 'search' => $search]), get_string('glossary_bulk_saved', 'local_xlate', $added), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Page header
echo $OUTPUT->header();
require_once(__DIR__ . '/admin_nav.php');
local_xlate_render_admin_nav('glossary');

// Search and pagination form (top)
echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('search_and_filter', 'local_xlate'), 'card-header');
echo html_writer::start_div('card-body');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url]);
echo html_writer::start_div('row');

// Search box
echo html_writer::start_div('col-md-6');
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

// Per page
echo html_writer::start_div('col-md-2');
echo html_writer::tag('label', get_string('per_page', 'local_xlate'), ['for' => 'perpage']);
$perpage_options = [5 => '5', 10 => '10', 25 => '25', 50 => '50'];
echo html_writer::select($perpage_options, 'perpage', $perpage, false, ['class' => 'form-control']);
echo html_writer::end_div();

// Search button
echo html_writer::start_div('col-md-4');
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
// Single-row multi-language add form (below filters)
echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('glossary_add_new_header', 'local_xlate'), 'card-header');
echo html_writer::start_div('card-body');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'bulk_add']);

// Source language is fixed to site language (hidden) and displayed read-only
$installedlangs = get_string_manager()->get_list_of_translations();
echo html_writer::start_div('row mb-2');
echo html_writer::start_div('col-md-12');
echo html_writer::tag('label', get_string('glossary_source_lang_label', 'local_xlate'));
echo html_writer::div((isset($installedlangs[$CFG->lang]) ? $installedlangs[$CFG->lang] : $CFG->lang) . ' (' . $CFG->lang . ')', 'form-control-plaintext mb-2');
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'source_lang', 'value' => $CFG->lang]);
echo html_writer::end_div();

// Source text full-width (single-line input)
echo html_writer::start_div('col-md-12');
echo html_writer::tag('label', get_string('glossary_source_text_label', 'local_xlate'));
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'source_text', 'class' => 'form-control', 'value' => '', 'placeholder' => get_string('glossary_source_text_placeholder', 'local_xlate')]);
echo html_writer::end_div();
echo html_writer::end_div();

// Target language inputs: one full-width input per installed language (skip site language)
echo html_writer::start_div('row mb-2');
foreach ($installedlangs as $langcode => $langname) {
    if ($langcode === $CFG->lang) {
        continue;
    }
    echo html_writer::start_div('col-md-12 mb-2');
    echo html_writer::tag('label', $langname . ' (' . $langcode . ')');
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'target_' . $langcode, 'class' => 'form-control', 'placeholder' => get_string('glossary_translation_placeholder', 'local_xlate')]);
    echo html_writer::end_div();
}
echo html_writer::end_div();

// Submit
echo html_writer::start_div('row');
echo html_writer::start_div('col-md-12 d-flex justify-content-end');
echo html_writer::tag('button', get_string('glossary_add_button', 'local_xlate'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

// Fetch data
$total = glossary_helper::count_sources($search);
$sources = glossary_helper::get_sources($search, $page * $perpage, $perpage);

if (empty($sources)) {
    echo html_writer::div(get_string('glossary_no_entries', 'local_xlate'), 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

// Pagination controls (top)
if ($total > $perpage) {
    echo html_writer::start_div('card-body pb-2 border-bottom');
    echo html_writer::div(glossary_render_pagination_controls($PAGE->url, $page, $perpage, $total, $search), 'd-flex justify-content-center');
    echo html_writer::end_div();
}

// Enabled languages
$enabledlangs = get_config('local_xlate', 'enabled_languages');
$enabledlangsarray = empty($enabledlangs) ? ['en'] : explode(',', $enabledlangs);
$installedlangs = get_string_manager()->get_list_of_translations();

// Render each source with translation forms per enabled language
foreach ($sources as $src) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-header d-flex justify-content-between');
    echo html_writer::tag('strong', s($src->source_text));
    echo html_writer::tag('small', 'Translations: ' . $src->translations_count);
    echo html_writer::end_div();

    echo html_writer::start_div('card-body');
    // Show source info row
    echo html_writer::start_div('row align-items-center mb-2');
    echo html_writer::start_div('col-md-2 d-flex align-items-center');
    echo html_writer::tag('label', get_string('glossary_source', 'local_xlate'));
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-8 d-flex align-items-center');
    echo html_writer::div(s($src->source_text), 'form-control-plaintext mb-0');
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Load translations map for this source
    $translations = glossary_helper::get_translations_for_source($src->source_text, $src->source_lang);
    $transmap = [];
    foreach ($translations as $t) {
        $transmap[$t->target_lang] = $t;
    }

    foreach ($enabledlangsarray as $langcode) {
        if ($langcode === $src->source_lang) {
            continue;
        }
        if (!isset($installedlangs[$langcode])) {
            continue;
        }
        $existing = isset($transmap[$langcode]) ? $transmap[$langcode] : null;

        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url, 'class' => 'mb-2']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_glossary']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => $page]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'perpage', 'value' => $perpage]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'search', 'value' => $search]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'source_lang', 'value' => s($src->source_lang)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'source_text', 'value' => $src->source_text]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'target_lang', 'value' => s($langcode)]);

        echo html_writer::start_div('row align-items-center');
        echo html_writer::start_div('col-md-2');
        echo html_writer::tag('label', $installedlangs[$langcode] . ' (' . $langcode . ')');
        echo html_writer::end_div();

        echo html_writer::start_div('col-md-7');
        $trval = $existing ? $existing->target_text : '';
        $usesource = (strlen($src->source_text) > 80 || strpos($src->source_text, "\n") !== false);
        if ($usesource) {
            $rows = max(3, min(8, ceil(strlen($src->source_text)/80)));
            echo html_writer::tag('textarea', s($trval), [
                'name' => 'target_text',
                'class' => 'form-control',
                'rows' => $rows,
                'placeholder' => get_string('glossary_translation_placeholder', 'local_xlate')
            ]);
        } else {
            echo html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'target_text',
                'value' => s($trval),
                'class' => 'form-control',
                'placeholder' => get_string('glossary_translation_placeholder', 'local_xlate')
            ]);
        }
        echo html_writer::end_div();

        echo html_writer::start_div('col-md-3 d-flex justify-content-end');
        echo html_writer::tag('button', get_string('save_translation', 'local_xlate'), [
            'type' => 'submit',
            'class' => 'btn btn-sm btn-success'
        ]);
        echo html_writer::end_div();

        echo html_writer::end_div();
        echo html_writer::end_tag('form');
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();