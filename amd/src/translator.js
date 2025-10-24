// local/xlate/amd/src/translator.js
define([], function () {
  /**
   * Translate a single element when metadata is present.
   * @param {Element} node DOM node to translate.
   * @param {Object<string, string>} map Translation map.
   * @returns {void}
   */
  function translateNode(node, map) {
    if (node.nodeType !== 1) {
      return;
    }
    var key = node.getAttribute && node.getAttribute('data-xlate');
    if (key && map[key]) {
      node.textContent = map[key];
    }
    ['placeholder', 'title', 'alt', 'aria-label'].forEach(function (attr) {
      var akey = node.getAttribute && node.getAttribute('data-xlate-' + attr);
      if (akey && map[akey]) {
        node.setAttribute(attr, map[akey]);
      }
    });
  }

  /**
   * Walk the DOM depth-first and translate every eligible child.
   * @param {Element} root Root element to process.
   * @param {Object<string, string>} map Translation map.
   * @returns {void}
   */
  function walk(root, map) {
    var stack = [root];
    while (stack.length) {
      var el = stack.pop();
      if (el.nodeType === 1) {
        if (el.hasAttribute && el.hasAttribute('data-xlate-ignore')) {
          continue;
        }
        translateNode(el, map);
        var children = el.children || [];
        for (var i = 0; i < children.length; i++) {
          stack.push(children[i]);
        }
      }
    }
  }

  /**
   * Entry point: translates current DOM and observes future updates.
   * @param {Object<string, string>} map Translation map.
   * @returns {void}
   */
  function run(map) {
    try {
      walk(document.body, map || {});
      var mo = new MutationObserver(function (muts) {
        muts.forEach(function (m) {
          (m.addedNodes || []).forEach(function (n) {
            if (n.nodeType === 1) {
              walk(n, map || {});
            }
          });
        });
      });
      mo.observe(document.body, { childList: true, subtree: true });
    } finally {
      document.documentElement.classList.remove('xlate-loading');
    }
  }

  /**
   * Initialize the translator with config from Moodle.
   * @param {Object} config Configuration object with lang, version, bundleurl.
   * @returns {void}
   */
  function init(config) {
    document.documentElement.classList.add('xlate-loading');
    var k = 'xlate:' + config.lang + ':' + config.version;

    window.__XLATE__ = { lang: config.lang, map: {} };

    try {
      var cached = localStorage.getItem(k);
      if (cached) {
        var bundle = JSON.parse(cached);
        window.__XLATE__.map = bundle;
        run(bundle);
      }

      fetch(config.bundleurl, { credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (bundle) {
          try {
            localStorage.setItem(k, JSON.stringify(bundle));
          } catch (e) {
            // Storage quota exceeded, ignore
          }
          if (!cached) {
            window.__XLATE__.map = bundle;
            run(bundle);
          }
        })
        .catch(function () {
          if (!cached) {
            run({});
          }
        });
    } catch (e) {
      fetch(config.bundleurl)
        .then(function (r) {
          return r.json();
        })
        .then(function (bundle) {
          window.__XLATE__.map = bundle;
          run(bundle);
        })
        .catch(function () {
          run({});
        });
    }
  }

  return {
    run: run,
    init: init
  };
});