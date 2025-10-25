<?php
$string['pluginname'] = 'Xlate (client-side translations)';
$string['enable'] = 'Enable Xlate';
$string['enable_desc'] = 'Turn on/off client-side translation injection.';
$string['xlate:manage'] = 'Manage Xlate translations';
$string['xlate:viewui'] = 'View Xlate admin UI';

// Auto-detection settings
$string['autodetect'] = 'Auto-detect strings';
$string['autodetect_desc'] = 'Automatically detect and create translation keys for text content on pages.';
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
$string['automatic_keys_heading'] = 'Automatic Key Capture';
$string['automatic_keys_description'] = 'Translation keys are created by the auto-detection engine while you browse the site in its default language. Manual key creation is disabled.';
$string['automatic_keys_hint'] = 'Browse pages in the default language with the manage capability to capture new strings, then enter translations below.';

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
$string['autodetect_enabled'] = 'Automatic string detection enabled';
$string['autodetect_disabled'] = 'Automatic string detection disabled';
$string['detection_active'] = 'Auto-detection is currently active on this page.';
$string['detection_inactive'] = 'Auto-detection is currently disabled.';
