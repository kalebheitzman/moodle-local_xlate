<?php
$string['pluginname'] = 'Xlate (client-side translations)';
$string['enable'] = 'Enable Xlate';
$string['enable_desc'] = 'Turn on/off client-side translation injection.';
$string['xlate:manage'] = 'Manage Xlate translations';
$string['xlate:viewui'] = 'View Xlate admin UI';

// Auto-detection settings
$string['enabled_languages'] = 'Enabled languages';
$string['enabled_languages_desc'] = 'Select which installed languages should have translation bundles generated and managed.';
$string['component_mapping'] = 'Component mapping';
$string['component_mapping_desc'] = 'Define how page contexts map to components. One mapping per line in format: selector=component';

// Bundle management
$string['bundles_rebuilt'] = 'Translation bundles rebuilt successfully';
$string['rebuild_bundles'] = 'Rebuild Bundles';
$string['rebuild_bundles_desc'] = 'Regenerate version hashes for all language bundles to clear caches.';

// Translation management
$string['manage_translations'] = 'Manage Translations';
$string['manage_translations_desc'] = 'Review automatically captured keys and manage translations across all enabled languages.';
$string['view_manage_translations'] = 'Manage Translations';
$string['edit_translation'] = 'Edit Translation';
$string['translation_key'] = 'Translation Key';
$string['component'] = 'Component';
$string['source_text'] = 'Source Text';
$string['translation_text'] = 'Translation';
$string['language'] = 'Language';
$string['status'] = 'Status';
$string['active'] = 'Active';
$string['inactive'] = 'Inactive';
$string['save_translation'] = 'Save Translation';
// Automatic key capture strings removed because the info card was removed from the UI.

// Success/error messages for management
$string['translation_saved'] = 'Translation saved successfully';
$string['translation_empty'] = 'Translation text cannot be empty';

// Search and filtering
$string['search_and_filter'] = 'Search and Filter';
$string['search'] = 'Search';
$string['search_placeholder'] = 'Search keys, components, or source text...';
$string['filter'] = 'Filter';
$string['all_components'] = 'All Components';
$string['all_statuses'] = 'All Statuses';
$string['fully_translated'] = 'Fully Translated';
$string['partially_translated'] = 'Partially Translated';
$string['untranslated'] = 'Untranslated';
$string['per_page'] = 'Per Page';
$string['translation_keys_pagination'] = 'Translation Keys {$a->start}-{$a->end} of {$a->total}';
$string['no_results_found'] = 'No translation keys match your search criteria. Try adjusting your filters.';
$string['no_keys_found'] = 'No translation keys found yet. Browse the site in the default language to trigger automatic capture.';

// Success/error messages

$string['courseid'] = 'Course ID';

// Glossary strings
$string['glossary'] = 'Glossary';
$string['glossary_targetlang_label'] = 'Target language';
$string['glossary_filter'] = 'Filter';
$string['glossary_specify_target'] = 'Specify ?targetlang=xx to view glossary entries.';
$string['glossary_no_entries'] = 'No glossary entries for {$a}';
$string['glossary_source'] = 'Source';
$string['glossary_target'] = 'Target';
$string['glossary_created'] = 'Created';
$string['glossary_saved'] = 'Glossary translation saved';
$string['glossary_translation_placeholder'] = 'Enter glossary translation...';
$string['glossary_add_new_header'] = 'Add glossary translations (single-row)';
$string['glossary_source_lang_label'] = 'Source language';
$string['glossary_source_text_label'] = 'Source text';
$string['glossary_source_text_placeholder'] = 'Enter source text here...';
$string['glossary_add_button'] = 'Add Translations';
$string['glossary_bulk_saved'] = '{$a} glossary translations added';
