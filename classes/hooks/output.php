<?php
namespace local_xlate\hooks;

defined('MOODLE_INTERNAL') || die();

use core\hook\output\before_standard_head_html_generation as Head;
use core\hook\output\before_standard_top_of_body_html_generation as Body;

class output {
    public static function before_head(Head $hook): void {
        if (!get_config('local_xlate', 'enable')) { return; }
        $hook->add_html('<style>html.xlate-loading body{visibility:hidden}</style>');
    }

    public static function before_body(Body $hook): void {
        if (!get_config('local_xlate', 'enable')) { return; }
        $lang = current_language();
        $version = \local_xlate\local\api::get_version($lang);
        $bundleurl = (new \moodle_url('/local/xlate/bundle.php', ['lang' => $lang, 'v' => $version]))->out(false);
        $script = sprintf("
<script>
(function(){
  var lang = %s;
  var ver  = %s;
  var bundleURL = %s;
  document.documentElement.classList.add('xlate-loading');
  var k = 'xlate:' + lang + ':' + ver;
  function run(b){ window.__XLATE__={lang:lang,map:b}; require(['local_xlate/translator'], function(t){ t.run(b); }); }
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
", json_encode($lang), json_encode($version), json_encode($bundleurl));
        $hook->add_html($script);
    }
}
