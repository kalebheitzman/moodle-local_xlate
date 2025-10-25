<?php
namespace local_xlate\hooks;

defined('MOODLE_INTERNAL') || die();

use core\hook\output\before_standard_head_html_generation as Head;
use core\hook\output\before_standard_top_of_body_html_generation as Body;

class output {
    public static function before_head(Head $hook): void {
        if (!get_config('local_xlate', 'enable')) { 
            return; 
        }
        
        // Don't inject on admin pages - they should use Moodle language strings
        if (self::is_admin_path()) {
            return;
        }
        
        $hook->add_html('<style>html.xlate-loading body{visibility:hidden}</style>');
    }

    public static function before_body(Body $hook): void {
        if (!get_config('local_xlate', 'enable')) { 
            return; 
        }
        
        // Don't inject on admin pages - they should use Moodle language strings
        if (self::is_admin_path()) {
            return;
        }
        $lang = current_language();
        $site_lang = get_config('core', 'lang') ?: 'en'; // Site's default language
        $version = \local_xlate\local\api::get_version($lang);
        $bundleurl = (new \moodle_url('/local/xlate/bundle.php', ['lang' => $lang, 'v' => $version]))->out(false);
        $autodetect = get_config('local_xlate', 'autodetect') ? 'true' : 'false';
        
        $script = sprintf("
<script>
(function(){
  var lang = %s;
  var siteLang = %s;
  var ver  = %s;
  var bundleURL = %s;
  var autoDetect = %s;
  document.documentElement.classList.add('xlate-loading');
  var k = 'xlate:' + lang + ':' + ver;
  function run(b){ 
    window.__XLATE__={lang:lang,siteLang:siteLang,map:b}; 
    require(['local_xlate/translator'], function(t){ 
      if (!autoDetect) t.setAutoDetect(false);
      t.run(b); 
    }); 
  }
  try{
    var s = localStorage.getItem(k);
    if (s) { run(JSON.parse(s)); }
    fetch(bundleURL, {credentials:'same-origin'}).then(function(r){return r.json();}).then(function(b){
      try{ localStorage.setItem(k, JSON.stringify(b)); }catch(e){}
      if (!s) run(b);
    }).catch(function(){ if(!window.__XLATE__) run({}); });
  }catch(e){
    fetch(bundleURL).then(function(r){return r.json();}).then(run).catch(function(){run({});});
  }
})();
</script>
", json_encode($lang), json_encode($site_lang), json_encode($version), json_encode($bundleurl), $autodetect);
        $hook->add_html($script);
    }
    
    /**
     * Check if current page is an admin/management path that shouldn't be translated
     * @return bool True if this is an admin path
     */
    private static function is_admin_path(): bool {
        global $PAGE;
        
        $url = $PAGE->url->get_path();
        
        // Admin paths that should use Moodle language strings
        $admin_paths = [
            '/admin/',
            '/local/xlate/',
            '/course/modedit.php',
            '/grade/edit/',
            '/backup/',
            '/restore/',
            '/user/editadvanced.php',
            '/user/preferences.php',
            '/my/indexsys.php',
            '/badges/edit.php',
            '/cohort/edit.php',
            '/question/edit.php'
        ];
        
        foreach ($admin_paths as $path) {
            if (strpos($url, $path) === 0) {
                return true;
            }
        }
        
        // Check for editing mode
        if ($PAGE->user_is_editing()) {
            return true;
        }
        
        // Check page context for admin areas
        $context = $PAGE->context;
        if ($context->contextlevel == CONTEXT_SYSTEM && $PAGE->pagetype !== 'site-index') {
            return true;
        }
        
        return false;
    }
}
