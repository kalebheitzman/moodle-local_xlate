<?php
$string['autotranslate_missing_task'] = 'Scheduled autotranslation for missing keys';
$string['mlangcleanuptask'] = 'Scheduled MLang cleanup (legacy multilang tags)';
$string['pluginname'] = 'Xlate (client-side translations)';
$string['enable'] = 'Enable Xlate';
$string['enable_desc'] = 'Turn on/off client-side translation injection.';
$string['debughooks'] = 'Enable translator debug logging';
$string['debughooks_desc'] = 'When enabled (or when Moodle developer debugging is on) the plugin logs why translator.js was injected or skipped. Logs appear in the PHP error log.';
$string['xlate:manage'] = 'Manage Xlate translations';
$string['xlate:viewui'] = 'View Xlate admin UI';
$string['xlate:viewbundle'] = 'View page-level translation bundles';
$string['xlate:viewsystem'] = 'View site-wide translation bundles';
$string['autotranslate_task_enable'] = 'Enable scheduled autotranslation';
$string['autotranslate_task_enable_desc'] = 'If enabled, a scheduled task will automatically generate translations for all missing languages and keys using the autotranslation backend.';

// Auto-detection settings
$string['enabled_languages'] = 'Enabled languages';
$string['enabled_languages_desc'] = 'Select which installed languages should have translation bundles generated and managed.';
$string['component_mapping'] = 'Component mapping';
$string['component_mapping_desc'] = 'Define how page contexts map to components. One mapping per line in format: selector=component';
$string['openai_prompt_default'] = 'You are a professional translation assistant. Translate input from the source language to the target language. Content may include user interface text, instructional content, forum posts, and other types of communication. Preserve HTML tags, attributes, and entities exactly. Keep placeholders and variables (e.g. {$a}, {username}, %s) unchanged and in-place. Do NOT rewrite code, URLs, identifiers, or variable names. Preserve the original tone and sentiment of the text without changing its meaning. When applicable, prefer glossary terms provided in the glossary list. Output only valid JSON that matches the required schema; do not include extra text.';

// Bundle management
$string['bundles_rebuilt'] = 'Translation bundles rebuilt successfully';
$string['rebuild_bundles'] = 'Rebuild Bundles';
$string['rebuild_bundles_desc'] = 'Regenerate version hashes for all language bundles to clear caches.';

// Translation management
$string['manage_translations'] = 'Manage Translations';
$string['manage_translations_desc'] = 'Review automatically captured keys and manage translations across all enabled languages.';
$string['xlate:managecourse'] = 'Manage Xlate translations for a course';
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
$string['reviewed'] = 'Reviewed';
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

$string['translatecoursejobtask'] = 'Course autotranslate job';
$string['autotranslate_course'] = 'Autotranslate course';
$string['autotranslate_course_desc'] = 'Translate all captured keys associated with a course in batches and track progress.';
$string['nav_manage'] = 'Manage';
$string['nav_glossary'] = 'Glossary';
$string['nav_usage'] = 'Usage';

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
$string['admin_manage_translations'] = 'Xlate: Manage Translations';
$string['admin_manage_glossary'] = 'Xlate: Manage Glossary';
$string['admin_usage'] = 'Xlate: Usage';

// Course custom fields
$string['xlate_course_source_lang'] = 'Xlate: Source Language';
$string['xlate_course_source_lang_help'] = 'The primary language for content in this course.';
$string['xlate_course_target_langs'] = 'Xlate: Target Languages';
$string['xlate_course_target_langs_help'] = 'Select which languages this course should be translated into.';

// Settings UI strings
$string['settings_intro'] = 'Core configuration for the Xlate plugin.';
$string['autotranslate_heading'] = 'Autotranslation (OpenAI)';
$string['autotranslate_enable'] = 'Enable Autotranslation';
$string['autotranslate_enable_desc'] = 'If enabled, Xlate may call the configured OpenAI endpoint to propose translated text for capture and management flows.';
$string['autotranslate_target'] = 'Autotranslate target language';
$string['autotranslate_no_targets'] = 'No target languages are available. Update the course Xlate settings or enable additional languages.';
$string['autotranslate'] = 'Autotranslate';
$string['openai_endpoint'] = 'OpenAI API endpoint';
$string['openai_endpoint_desc'] = 'The HTTP endpoint to call for model completions. Use this to point to a proxy or self-hosted endpoint if needed.';
$string['openai_api_key'] = 'OpenAI API key';
$string['openai_api_key_desc'] = 'API key used when calling the OpenAI endpoint. Stored encrypted by Moodle config.';
$string['openai_model'] = 'Model / Deployment';
$string['openai_model_desc'] = 'Model name to request from the API (for example: gpt-4o-mini).';
$string['pricing_heading'] = 'Usage-based pricing (per 1M tokens)';
$string['pricing_input_per_million'] = 'Input token cost';
$string['pricing_input_per_million_desc'] = 'Cost charged per one million input tokens sent to the model.';
$string['pricing_cached_input_per_million'] = 'Cached input token cost';
$string['pricing_cached_input_per_million_desc'] = 'Cost per one million cached input tokens (used when the provider offers reduced pricing for cache hits).';
$string['pricing_output_per_million'] = 'Output token cost';
$string['pricing_output_per_million_desc'] = 'Cost charged per one million output tokens returned by the model.';
$string['pricing_value_invalid'] = 'Enter a numeric value (decimals supported).';
$string['openai_prompt'] = 'System prompt (translation instructions)';
$string['openai_prompt_desc'] = 'Instructions that will be passed as the system prompt to the model. Keep it concise; the default prompt preserves HTML, placeholders and UI tone.';
$string['language_heading'] = 'Languages & Capture';
$string['capture_selectors'] = 'Capture area selectors';
$string['capture_selectors_desc'] = 'Only text within elements matching these CSS selectors will be captured for translation. One selector per line. Leave blank to capture everything.';
$string['exclude_selectors'] = 'Exclude selectors';
$string['exclude_selectors_desc'] = 'Elements matching these CSS selectors will be excluded from capture, even if inside a capture area. One selector per line. Common defaults included.';
$string['excluded_paths'] = 'Exclude path prefixes';
$string['excluded_paths_desc'] = 'Requests whose paths begin with one of these prefixes will never load the translator (useful for administrative or edit-only pages). One prefix per line.';
