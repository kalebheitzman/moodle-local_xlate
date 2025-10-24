<?php
$string['pluginname'] = 'Xlate (client-side translations)';
$string['enable'] = 'Enable Xlate';
$string['enable_desc'] = 'Turn on/off client-side translation injection.';
$string['languages'] = 'Languages';
$string['languages_desc'] = 'Comma-separated language codes to manage bundles for.';
$string['xlate:manage'] = 'Manage Xlate translations';
$string['xlate:viewui'] = 'View Xlate admin UI';

// Capture mode strings
$string['capturemode_intro'] = 'Capture mode allows you to assign translation keys to text elements directly on any page. Click "Start Capture Mode" below, then navigate to any page and click on text elements to assign keys.';
$string['capturemode_start'] = 'Start Capture Mode';
$string['capturemode_stop'] = 'Stop Capture Mode';
$string['nomanagepermission'] = 'You do not have permission to manage translations. Contact your administrator.';
$string['about'] = 'About Xlate';
$string['about_desc'] = 'Xlate provides client-side translation capabilities for Moodle 5+. Text elements marked with data-xlate attributes will be automatically translated based on the current user language.';

// Capture mode modal strings  
$string['assign_key_title'] = 'Assign Translation Key';
$string['translation_key'] = 'Translation Key';
$string['translation_key_help'] = 'Use dot notation (e.g., Dashboard.Title, Button.Save)';
$string['component'] = 'Component';
$string['component_help'] = 'Component identifier (core, mod_forum, theme_boost, etc.)';
$string['text_content'] = 'Text Content';
$string['placeholder_key'] = 'Placeholder Key (optional)';
$string['title_key'] = 'Title Key (optional)';
$string['alt_key'] = 'Alt Text Key (optional)';

// Success/error messages
$string['key_saved_success'] = 'Translation key(s) saved successfully';
$string['key_save_error'] = 'Error saving translation key';
$string['capture_activated'] = 'Capture mode activated. Click on text elements to assign translation keys.';
$string['capture_deactivated'] = 'Capture mode deactivated.';
$string['key_component_required'] = 'Key and component are required';
$string['bundles_rebuilt'] = 'Translation bundles rebuilt successfully';
$string['rebuild_bundles'] = 'Rebuild Bundles';
$string['rebuild_bundles_desc'] = 'Regenerate version hashes for all language bundles to clear caches.';
