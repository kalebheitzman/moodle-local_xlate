// local/xlate/amd/src/translator.js
define([], function() {
  function translateNode(node, map) {
    if (node.nodeType !== 1) return;
    var key = node.getAttribute && node.getAttribute('data-xlate');
    if (key && map[key]) node.textContent = map[key];
    ['placeholder','title','alt','aria-label'].forEach(function(attr){
      var akey = node.getAttribute && node.getAttribute('data-xlate-'+attr);
      if (akey && map[akey]) node.setAttribute(attr, map[akey]);
    });
  }

  function walk(root, map) {
    var stack = [root];
    while (stack.length) {
      var el = stack.pop();
      if (el.nodeType === 1) {
        if (el.hasAttribute && el.hasAttribute('data-xlate-ignore')) continue;
        translateNode(el, map);
        var children = el.children || [];
        for (var i=0;i<children.length;i++) stack.push(children[i]);
      }
    }
  }

  function run(map) {
    try {
      walk(document.body, map || {});
      var mo = new MutationObserver(function(muts){
        muts.forEach(function(m){
          (m.addedNodes||[]).forEach(function(n){
            if (n.nodeType === 1) walk(n, map||{});
          });
        });
      });
      mo.observe(document.body, {childList:true, subtree:true});
    } finally {
      document.documentElement.classList.remove('xlate-loading');
    }
  }

  return { run: run };
});
