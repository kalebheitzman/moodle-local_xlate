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
 * Glossary management UI for Local Xlate.
 *
 * Language-filtered view: select a target language and see all source terms
 * with their translations (or blank inputs for missing ones).
 *
 * @package    local_xlate
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

use local_xlate\glossary as glossary_helper;

/**
 * Render pagination controls for glossary (page number style with ellipses).
 */
function glossary_render_pagination_controls($baseurl, $page, $perpage, $total, $search, $targetlang) {
    $total_pages = ceil($total / $perpage);
    $pagination = '';
    if ($total_pages > 1) {
        $sharedparams = [
            'perpage' => $perpage,
            'search' => $search,
            'targetlang' => $targetlang,
        ];

        $pagination .= html_writer::start_tag('nav', ['aria-label' => 'Glossary pagination']);
        $pagination .= html_writer::start_tag('ul', ['class' => 'pagination justify-content-center mb-0']);

        // Previous
        if ($page > 0) {
            $prevurl = new moodle_url($baseurl, array_merge($sharedparams, ['page' => $page - 1]));
            $pagination .= html_writer::tag('li', html_writer::link($prevurl, '‹ ' . get_string('previous'), ['class' => 'page-link']), ['class' => 'page-item']);
        } else {
            $pagination .= html_writer::tag('li', html_writer::span('‹ ' . get_string('previous'), 'page-link'), ['class' => 'page-item disabled']);
        }

        // First page if not in range
        if ($page > 2) {
            $firsturl = new moodle_url($baseurl, array_merge($sharedparams, ['page' => 0]));
            $pagination .= html_writer::tag('li', html_writer::link($firsturl, '1', ['class' => 'page-link']), ['class' => 'page-item']);
            if ($page > 3) {
                $pagination .= html_writer::tag('li', html_writer::span('...', 'page-link'), ['class' => 'page-item disabled']);
            }
        }

        // Page numbers (current ±2)
        $start_page = max(0, $page - 2);
        $end_page = min($total_pages - 1, $page + 2);
        for ($i = $start_page; $i <= $end_page; $i++) {
            $pageurl = new moodle_url($baseurl, array_merge($sharedparams, ['page' => $i]));
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
            $lasturl = new moodle_url($baseurl, array_merge($sharedparams, ['page' => $total_pages - 1]));
            $pagination .= html_writer::tag('li', html_writer::link($lasturl, $total_pages, ['class' => 'page-link']), ['class' => 'page-item']);
        }

        // Next
        if ($page < $total_pages - 1) {
            $nexturl = new moodle_url($baseurl, array_merge($sharedparams, ['page' => $page + 1]));
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
$targetlang = optional_param('targetlang', '', PARAM_ALPHANUMEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 10, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);

// Resolve language config (same pattern as manage.php).
$installedlangs = get_string_manager()->get_list_of_translations();
$langconfig = \local_xlate\customfield_helper::resolve_languages(null);
$enabledlangsarray = $langconfig['enabled'];
$sourcelang = $langconfig['source'];

// Build target language options: enabled langs minus source, installed only.
$targetlangoptions = [];
foreach ($enabledlangsarray as $langcode) {
    if ($langcode === $sourcelang || !isset($installedlangs[$langcode])) {
        continue;
    }
    $label = $installedlangs[$langcode];
    if (strpos($label, '(' . $langcode . ')') === false) {
        $label .= ' (' . $langcode . ')';
    }
    $targetlangoptions[$langcode] = $label;
}

// Auto-select first available target language.
if ($targetlang === '' && !empty($targetlangoptions)) {
    reset($targetlangoptions);
    $targetlang = key($targetlangoptions);
}
// Validate: reject unknown target langs.
if ($targetlang !== '' && !isset($targetlangoptions[$targetlang])) {
    $targetlang = '';
}

$PAGE->set_url(new moodle_url('/local/xlate/glossary.php', [
    'targetlang' => $targetlang,
    'page' => $page,
    'perpage' => $perpage,
    'search' => $search,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('admin_manage_glossary', 'local_xlate'));
$PAGE->set_heading(get_string('admin_manage_glossary', 'local_xlate'));

require_capability('local/xlate:manage', $PAGE->context);

// Handle combined row save (translation + notes in one submit).
if ($action === 'save_row' && confirm_sesskey()) {
    $source_lang = required_param('source_lang', PARAM_ALPHANUMEXT);
    $source_text = required_param('source_text', PARAM_RAW_TRIMMED);
    $target_lang = required_param('target_lang', PARAM_ALPHANUMEXT);
    $target_text = optional_param('target_text', '', PARAM_RAW_TRIMMED);
    $notes = optional_param('notes', '', PARAM_RAW_TRIMMED);
    $critical = optional_param('critical', 0, PARAM_INT) ? 1 : 0;

    try {
        if ($source_text !== '' && $target_text !== '') {
            glossary_helper::save_translation($source_lang, $source_text, $target_lang, $target_text, $USER->id);
        }
        glossary_helper::save_term($source_lang, $source_text, $notes, $critical, $USER->id);
        redirect($PAGE->url, get_string('glossary_saved', 'local_xlate'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (Exception $e) {
        redirect($PAGE->url, 'Save failed: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Handle save term notes action.
if ($action === 'save_term' && confirm_sesskey()) {
    $source_lang = required_param('source_lang', PARAM_ALPHANUMEXT);
    $source_text = required_param('source_text', PARAM_RAW_TRIMMED);
    $notes = optional_param('notes', '', PARAM_RAW_TRIMMED);
    $critical = optional_param('critical', 0, PARAM_INT) ? 1 : 0;

    try {
        glossary_helper::save_term($source_lang, $source_text, $notes, $critical, $USER->id);
        redirect($PAGE->url, get_string('glossary_notes_saved', 'local_xlate'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (Exception $e) {
        redirect($PAGE->url, 'Save failed: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Handle save action (upsert a glossary translation).
if ($action === 'save_glossary' && confirm_sesskey()) {
    $source_lang = required_param('source_lang', PARAM_ALPHANUMEXT);
    $source_text = required_param('source_text', PARAM_RAW_TRIMMED);
    $target_lang = required_param('target_lang', PARAM_ALPHANUMEXT);
    $target_text = required_param('target_text', PARAM_RAW_TRIMMED);

    if ($source_text === '' || $target_text === '') {
        redirect($PAGE->url, get_string('translation_empty', 'local_xlate'), null, \core\output\notification::NOTIFY_ERROR);
    }

    try {
        glossary_helper::save_translation($source_lang, $source_text, $target_lang, $target_text, $USER->id);
        redirect($PAGE->url, get_string('glossary_saved', 'local_xlate'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (Exception $e) {
        redirect($PAGE->url, 'Save failed: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Handle delete action (remove a single glossary entry by id).
if ($action === 'delete_glossary' && confirm_sesskey()) {
    $entryid = required_param('entryid', PARAM_INT);
    try {
        $DB->delete_records('local_xlate_glossary', ['id' => $entryid]);
        redirect($PAGE->url, get_string('glossary_deleted', 'local_xlate'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (Exception $e) {
        redirect($PAGE->url, get_string('glossary_delete_failed', 'local_xlate'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
require_once(__DIR__ . '/admin_nav.php');
local_xlate_render_admin_nav('glossary');

// === Filter card (language selector + search + per-page) ===
echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('search_and_filter', 'local_xlate'), 'card-header');
echo html_writer::start_div('card-body');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url]);
echo html_writer::start_div('row g-3 align-items-end');

// Target language dropdown
echo html_writer::start_div('col-12 col-md-4');
echo html_writer::tag('label', get_string('glossary_targetlang_label', 'local_xlate'), ['for' => 'targetlang', 'class' => 'form-label']);
if (!empty($targetlangoptions)) {
    echo html_writer::select($targetlangoptions, 'targetlang', $targetlang, false, ['class' => 'form-select', 'id' => 'targetlang']);
} else {
    echo html_writer::div(get_string('language_filter_empty', 'local_xlate'), 'text-muted fst-italic');
}
echo html_writer::end_div();

// Search
echo html_writer::start_div('col-12 col-md-4');
echo html_writer::tag('label', get_string('search', 'local_xlate'), ['for' => 'search', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'search',
    'name' => 'search',
    'value' => s($search),
    'class' => 'form-control',
    'placeholder' => get_string('search_placeholder', 'local_xlate'),
]);
echo html_writer::end_div();

// Per page
echo html_writer::start_div('col-6 col-md-2');
echo html_writer::tag('label', get_string('per_page', 'local_xlate'), ['for' => 'perpage', 'class' => 'form-label']);
echo html_writer::select([5 => '5', 10 => '10', 25 => '25', 50 => '50'], 'perpage', $perpage, false, ['class' => 'form-control', 'id' => 'perpage']);
echo html_writer::end_div();

// Filter button
echo html_writer::start_div('col-6 col-md-2 d-flex align-items-end');
echo html_writer::tag('button', get_string('filter', 'local_xlate'), ['type' => 'submit', 'class' => 'btn btn-primary w-100']);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

// No target language available.
if ($targetlang === '') {
    echo html_writer::div(get_string('glossary_specify_target', 'local_xlate'), 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

$targetlangname = isset($installedlangs[$targetlang]) ? $installedlangs[$targetlang] : $targetlang;
$sourcelangname = isset($installedlangs[$sourcelang]) ? $installedlangs[$sourcelang] : $sourcelang;

// === Add new entry card ===
echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('glossary_add_new_header', 'local_xlate'), 'card-header');
echo html_writer::start_div('card-body');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_glossary']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'source_lang', 'value' => $sourcelang]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'target_lang', 'value' => $targetlang]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => $page]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'perpage', 'value' => $perpage]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'search', 'value' => $search]);

echo html_writer::start_div('row g-3 align-items-end');

echo html_writer::start_div('col-12 col-md-5');
echo html_writer::tag('label', $sourcelangname . ' (' . $sourcelang . ')', ['class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'source_text',
    'class' => 'form-control',
    'placeholder' => get_string('glossary_source_text_placeholder', 'local_xlate'),
]);
echo html_writer::end_div();

echo html_writer::start_div('col-12 col-md-5');
echo html_writer::tag('label', $targetlangname . ' (' . $targetlang . ')', ['class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'target_text',
    'class' => 'form-control',
    'placeholder' => get_string('glossary_translation_placeholder', 'local_xlate'),
]);
echo html_writer::end_div();

echo html_writer::start_div('col-12 col-md-2 d-flex align-items-end');
echo html_writer::tag('button', get_string('glossary_add_button', 'local_xlate'), ['type' => 'submit', 'class' => 'btn btn-primary w-100']);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

// === Glossary entries table ===

// Count distinct source terms.
$where = 'WHERE source_lang = ?';
$count_params = [$sourcelang];
if (!empty($search)) {
    $where .= ' AND source_text LIKE ?';
    $count_params[] = '%' . $search . '%';
}
$count_sql = "SELECT COUNT(DISTINCT source_text) FROM {local_xlate_glossary} $where";
$total = (int)$DB->count_records_sql($count_sql, $count_params);

if ($total === 0) {
    echo html_writer::div(get_string('glossary_no_entries', 'local_xlate', $targetlangname), 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

// Get paginated source terms.
$sources_sql = "SELECT MIN(id) as id, source_text
                FROM {local_xlate_glossary}
                $where
                GROUP BY source_text
                ORDER BY LOWER(source_text) ASC";
$source_rows = $DB->get_records_sql($sources_sql, $count_params, $page * $perpage, $perpage);

// Load all translations for the selected target lang (all at once — fast lookup).
$transmap = [];
$all_trans = $DB->get_records('local_xlate_glossary', ['source_lang' => $sourcelang, 'target_lang' => $targetlang]);
foreach ($all_trans as $tr) {
    $transmap[$tr->source_text] = $tr;
}

// Load term metadata (notes + critical) for all source terms.
$termmap = [];
$all_terms = $DB->get_records('local_xlate_glossary_term', ['source_lang' => $sourcelang]);
foreach ($all_terms as $term) {
    $termmap[$term->source_text] = $term;
}

// Pagination top.
if ($total > $perpage) {
    echo html_writer::start_div('d-flex justify-content-center mb-3');
    echo glossary_render_pagination_controls($PAGE->url, $page, $perpage, $total, $search, $targetlang);
    echo html_writer::end_div();
}

// Entries card.
echo html_writer::start_div('card mb-4');

echo html_writer::start_div('card-header d-flex justify-content-between align-items-center');
echo html_writer::tag('span', s($sourcelangname) . ' → ' . s($targetlangname));
echo html_writer::tag('small', $total . ' ' . get_string('glossary_source', 'local_xlate') . ' terms', ['class' => 'text-muted']);
echo html_writer::end_div();

echo html_writer::start_div('card-body');

// Hidden forms — one save form + one delete form per row, referenced via HTML5 form= attribute.
echo html_writer::start_div('', ['style' => 'display:none;']);
foreach ($source_rows as $src) {
    $rowformid = 'glossary_row_' . md5($src->source_text);
    echo html_writer::start_tag('form', ['id' => $rowformid, 'method' => 'post', 'action' => $PAGE->url]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save_row']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'source_lang', 'value' => $sourcelang]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'source_text', 'value' => $src->source_text]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'target_lang', 'value' => $targetlang]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => $page]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'perpage', 'value' => $perpage]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'search', 'value' => $search]);
    echo html_writer::end_tag('form');

    $existing_for_form = isset($transmap[$src->source_text]) ? $transmap[$src->source_text] : null;
    if ($existing_for_form) {
        $delformid = 'glossary_del_' . md5($src->source_text);
        echo html_writer::start_tag('form', ['id' => $delformid, 'method' => 'post', 'action' => $PAGE->url]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'delete_glossary']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'entryid', 'value' => $existing_for_form->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => $page]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'perpage', 'value' => $perpage]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'search', 'value' => $search]);
        echo html_writer::end_tag('form');
    }
}
echo html_writer::end_div();

// One card per source term, styled like manage.php entries.
$saveLabel = get_string('save_translation', 'local_xlate');
$deleteLabel = get_string('delete_translation', 'local_xlate');
$confirmMsg = get_string('confirm_delete_translation', 'local_xlate');

foreach ($source_rows as $src) {
    $source_text = $src->source_text;
    $existing = isset($transmap[$source_text]) ? $transmap[$source_text] : null;
    $trval = $existing ? $existing->target_text : '';
    $termrec = isset($termmap[$source_text]) ? $termmap[$source_text] : null;
    $existing_notes = $termrec ? (string)$termrec->notes : '';
    $is_critical = $termrec && (int)$termrec->critical === 1;
    $rowformid = 'glossary_row_' . md5($source_text);
    $delformid = 'glossary_del_' . md5($source_text);
    $checkboxid = 'critical_' . md5($source_text);

    echo html_writer::start_div('card mb-3');

    // Card header: source text + critical badge.
    echo html_writer::start_div('card-header d-flex justify-content-between align-items-center flex-wrap gap-2');
    echo html_writer::tag('strong', s($source_text));
    if ($is_critical) {
        echo html_writer::tag('span', s(get_string('glossary_priority_term', 'local_xlate')), ['class' => 'badge bg-danger text-uppercase']);
    }
    echo html_writer::end_div();

    echo html_writer::start_div('card-body');

    // Single row: labels | inputs | notes textarea | action buttons + priority checkbox.
    echo html_writer::start_div('row align-items-stretch');

    // Labels column.
    echo html_writer::start_div('col-md-2 d-flex flex-column justify-content-between');
    echo html_writer::tag('div', html_writer::tag('strong', s($sourcelangname) . ' (' . s($sourcelang) . ')'), ['class' => 'mb-2 small']);
    echo html_writer::tag('div', s($targetlangname) . ' (' . s($targetlang) . ')', ['class' => 'small']);
    echo html_writer::end_div();

    // Inputs column: source (disabled) stacked above target.
    echo html_writer::start_div('col-md-4 d-flex flex-column gap-2');
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'class' => 'form-control',
        'value' => s($source_text),
        'readonly' => 'readonly',
        'disabled' => 'disabled',
        'style' => 'background-color: #e9ecef;',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'target_text',
        'form' => $rowformid,
        'value' => s($trval),
        'class' => 'form-control',
        'placeholder' => get_string('glossary_translation_placeholder', 'local_xlate'),
    ]);
    echo html_writer::end_div();

    // Notes column: textarea spanning the full height of the two inputs.
    echo html_writer::start_div('col-md-4');
    echo html_writer::tag('textarea', s($existing_notes), [
        'name' => 'notes',
        'form' => $rowformid,
        'id' => $checkboxid . '_notes',
        'class' => 'form-control h-100',
        'placeholder' => get_string('glossary_notes_placeholder', 'local_xlate'),
        'style' => 'min-height: 76px; resize: vertical;',
    ]);
    echo html_writer::end_div();

    // Actions column: save + delete buttons, priority checkbox below.
    echo html_writer::start_div('col-md-2 d-flex flex-column gap-2');
    echo html_writer::start_div('d-flex gap-2');
    // Save icon button.
    echo html_writer::tag('button',
        html_writer::tag('span', '', ['class' => 'icon fa fa-floppy-o fa-fw', 'aria-hidden' => 'true']),
        [
            'type' => 'submit',
            'form' => $rowformid,
            'class' => 'btn btn-success btn-sm p-0 js-xlate-icon-button',
            'title' => $saveLabel,
            'data-bs-title' => $saveLabel,
            'aria-label' => $saveLabel,
            'data-bs-toggle' => 'tooltip',
            'data-bs-placement' => 'top',
        ]
    );
    // Delete icon button (only if a translation exists).
    if ($existing) {
        echo html_writer::tag('button',
            html_writer::tag('span', '', ['class' => 'icon fa fa-trash fa-fw', 'aria-hidden' => 'true']),
            [
                'type' => 'submit',
                'form' => $delformid,
                'class' => 'btn btn-danger btn-sm p-0 js-xlate-icon-button',
                'title' => $deleteLabel,
                'data-bs-title' => $deleteLabel,
                'aria-label' => $deleteLabel,
                'data-bs-toggle' => 'tooltip',
                'data-bs-placement' => 'top',
                'onclick' => 'return confirm(' . json_encode($confirmMsg) . ');',
            ]
        );
    }
    echo html_writer::end_div();
    // Priority checkbox below buttons.
    $checkboxattrs = [
        'type' => 'checkbox',
        'name' => 'critical',
        'value' => '1',
        'form' => $rowformid,
        'class' => 'form-check-input me-1',
        'id' => $checkboxid,
    ];
    if ($is_critical) {
        $checkboxattrs['checked'] = 'checked';
    }
    echo html_writer::tag('div',
        html_writer::tag('label',
            html_writer::empty_tag('input', $checkboxattrs) . s(get_string('glossary_priority_term', 'local_xlate')),
            ['class' => 'form-check-label small mb-0', 'for' => $checkboxid]
        ),
        ['class' => 'form-check mb-0']
    );
    echo html_writer::end_div();

    echo html_writer::end_div(); // row

    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card
}

echo html_writer::end_div(); // outer card-body
echo html_writer::end_div(); // outer card

// Pagination bottom.
if ($total > $perpage) {
    echo html_writer::start_div('d-flex justify-content-center mb-3');
    echo glossary_render_pagination_controls($PAGE->url, $page, $perpage, $total, $search, $targetlang);
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
