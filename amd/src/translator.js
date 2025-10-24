// Local/xlate/amd/src/translator.js
define(['core/ajax'], function (Ajax) {
  var autoDetectEnabled = true;
  var detectedStrings = new Set();
  var processedElements = new WeakSet();

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
   * Check if element should be ignored for auto-detection
   * @param {Element} element - Element to check
   * @returns {boolean} True if should be ignored
   */
  function shouldIgnoreElement(element) {
    var tagName = element.tagName.toLowerCase();

    // Skip script, style, meta tags
    if (['script', 'style', 'meta', 'link', 'noscript', 'head'].includes(tagName)) {
      return true;
    }

    // Skip if marked to ignore
    if (element.hasAttribute('data-xlate-ignore') ||
      element.closest('[data-xlate-ignore]')) {
      return true;
    }

    // Skip if already has xlate attributes
    if (element.hasAttribute('data-xlate') ||
      element.hasAttribute('data-xlate-placeholder') ||
      element.hasAttribute('data-xlate-title') ||
      element.hasAttribute('data-xlate-alt')) {
      return true;
    }

    return false;
  }

  /**
   * Generate component name from element context
   * @param {Element} element - Element to analyze
   * @returns {string} Component name
   */
  function detectComponent(element) {
    // Check for Moodle-specific containers
    var container = element.closest('[data-region]');
    if (container) {
      var region = container.getAttribute('data-region');
      if (region) {
        return 'region_' + region;
      }
    }

    // Check for block containers
    container = element.closest('.block');
    if (container) {
      var blockClass = container.className.match(/block_(\w+)/);
      if (blockClass) {
        return 'block_' + blockClass[1];
      }
    }

    // Check for course module containers
    container = element.closest('.activity');
    if (container) {
      var activityClass = container.className.match(/modtype_(\w+)/);
      if (activityClass) {
        return 'mod_' + activityClass[1];
      }
    }

    // Check for admin pages
    if (document.body.classList.contains('path-admin')) {
      return 'admin';
    }

    // Default to core
    return 'core';
  }

  /**
   * Generate translation key from element content and context
   * @param {Element} element - Element to generate key for
   * @param {string} text - Text content
   * @param {string} type - Type of content (text, placeholder, title, alt)
   * @returns {string} Generated key
   */
  function generateKey(element, text, type) {
    var tagName = element.tagName.toLowerCase();
    var keyParts = [];

    // Add context based on element type
    if (type === 'placeholder') {
      keyParts.push('Input');
    } else if (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(tagName)) {
      keyParts.push('Heading');
    } else if (tagName === 'button' || (tagName === 'input' && element.type === 'submit')) {
      keyParts.push('Button');
    } else if (tagName === 'a') {
      keyParts.push('Link');
    } else if (tagName === 'label') {
      keyParts.push('Label');
    } else if (type === 'alt') {
      keyParts.push('Image');
    } else if (type === 'title') {
      keyParts.push('Title');
    }

    // Clean and format text as key part
    var cleanText = text
      .replace(/[^\w\s]/g, '') // Remove special chars
      .replace(/\s+/g, ' ') // Normalize spaces
      .trim()
      .split(' ')
      .map(function (word) {
        return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
      })
      .join('');

    if (cleanText) {
      keyParts.push(cleanText);
    } else {
      keyParts.push('Text');
    }

    // Add type suffix if not text content
    if (type && type !== 'text') {
      keyParts.push(type.charAt(0).toUpperCase() + type.slice(1));
    }

    return keyParts.join('.');
  }

  /**
   * Auto-detect and save translatable string
   * @param {Element} element - Element containing the string
   * @param {string} text - Text content to save
   * @param {string} type - Type of content (text, placeholder, title, alt)
   */
  function autoDetectString(element, text, type) {
    if (!autoDetectEnabled || !text || text.length < 2) {
      return;
    }

    // Skip if already processed
    var uniqueId = text + ':' + type + ':' + element.tagName;
    if (detectedStrings.has(uniqueId)) {
      return;
    }

    detectedStrings.add(uniqueId);

    var component = detectComponent(element);
    var key = generateKey(element, text, type);

    // Save via AJAX
    Ajax.call([{
      methodname: 'local_xlate_save_key',
      args: {
        component: component,
        key: key,
        source: text,
        lang: M.cfg.language || 'en',
        translation: text
      }
    }])[0].then(function () {
      // Apply the attribute to the element
      var attrName = 'data-xlate' + (type !== 'text' ? '-' + type : '');
      element.setAttribute(attrName, key);

      // Update the global map
      if (window.__XLATE__ && window.__XLATE__.map) {
        window.__XLATE__.map[key] = text;
      }

      return true;
    }).catch(function () {
      // Silently fail - don't break the page if auto-detection fails
      detectedStrings.delete(uniqueId);
    });
  }

  /**
   * Auto-detect translatable content in an element
   * @param {Element} element - Element to analyze
   */
  function autoDetectElement(element) {
    if (shouldIgnoreElement(element) || processedElements.has(element)) {
      return;
    }

    processedElements.add(element);

    // Check text content
    if (element.childNodes.length === 1 &&
      element.childNodes[0].nodeType === 3 && // Text node
      element.textContent.trim()) {
      autoDetectString(element, element.textContent.trim(), 'text');
    }

    // Check attributes
    if (element.hasAttribute('placeholder') && element.getAttribute('placeholder').trim()) {
      autoDetectString(element, element.getAttribute('placeholder').trim(), 'placeholder');
    }

    if (element.hasAttribute('title') && element.getAttribute('title').trim()) {
      autoDetectString(element, element.getAttribute('title').trim(), 'title');
    }

    if (element.hasAttribute('alt') && element.getAttribute('alt').trim()) {
      autoDetectString(element, element.getAttribute('alt').trim(), 'alt');
    }
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

        // Translate existing keys
        translateNode(el, map);

        // Auto-detect new strings if enabled
        if (autoDetectEnabled) {
          autoDetectElement(el);
        }

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

      // Start auto-detection after initial translation
      if (autoDetectEnabled) {
        setTimeout(function () {
          walk(document.body, map || {});
        }, 1000); // Delay to let page fully load
      }

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

          return true;
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

          return true;
        })
        .catch(function () {
          run({});
        });
    }
  }

  /**
   * Enable or disable automatic string detection
   * @param {boolean} enabled - Whether to enable auto-detection
   */
  function setAutoDetect(enabled) {
    autoDetectEnabled = enabled;
  }

  return {
    run: run,
    init: init,
    setAutoDetect: setAutoDetect
  };
});