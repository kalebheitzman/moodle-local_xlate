// Local/xlate/amd/src/translator.js
// Handles DOM translation and automatic key capture with structural-based keys.
//
// WORKFLOW:
// 1. Tag element with data-xlate-key-{type} FIRST (always)
// 2. If currentLang === siteLang: Save to DB (capture mode)
// 3. If currentLang !== siteLang: Translate using bundle
/**
 * Translator AMD module for local_xlate.
 *
 * Exports:
 * - init(config): initialize translator with config (lang, siteLang, bundleurl, version, isEditing)
 * - run(map): run translation pass using provided map
 */
define(['core/ajax'], function(Ajax) {
  var ATTR_KEY_PREFIX = 'data-xlate-key-';
  var ATTRIBUTE_TYPES = [
    'placeholder', 'title', 'alt', 'aria-label'
  ];

  // Auto-detection is always enabled; keys are always auto-assigned.
  var detectedStrings = new Set();
  var processedElements = new WeakSet();
  var lastProcessTime = 0;
  var processThrottle = 250;
  /* Translator namespace to expose public API while keeping internal helpers private. */
  var Translator = {};
  Translator.utils = {};
  Translator.keys = {};
  Translator.attrs = {};
  Translator.capture = {};
  Translator.dom = {};
  Translator.api = {};

  /**
   * Wrapper for debug logging which only emits when server-side debug is enabled.
   * The hook in PHP will expose `window.XLATE_DEBUG` as true when Moodle debugging
   * is set to DEVELOPER. This keeps noisy logs out of production.
   */
  /* eslint-disable no-console */
  /**
   * Debug logging helper. Emits messages only when `window.XLATE_DEBUG` is truthy.
   * @returns {void}
   */
  function xlateDebug() {
    if (typeof window !== 'undefined' && window.XLATE_DEBUG) {
      if (typeof console !== 'undefined' && typeof console.debug === 'function') {
        console.debug.apply(console, arguments);
      } else if (typeof console !== 'undefined' && typeof console.log === 'function') {
        console.log.apply(console, arguments);
      }
    }
  }
  /* eslint-enable no-console */

  // ============================================================================
  // KEY GENERATION - Create structural 12-character hash keys
  // ============================================================================

  /**
   * Collect meaningful class names from element
   * @param {Element} element - The element to collect classes from
   * @returns {string} Comma-separated class names
   */
  function collectContextClasses(element) {
    if (!element || !element.classList) {
      return '';
    }

    var blacklist = [
      'active', 'show', 'hide', 'hidden', 'collapsed', 'expanded',
      'd-flex', 'd-none', 'd-block', 'sr-only', 'visually-hidden'
    ];

    var classes = [];
  Array.prototype.forEach.call(element.classList, function(cls) {
      if (cls && cls.length > 2 && blacklist.indexOf(cls) === -1 &&
        !/^[0-9]/.test(cls) && !/^[mp][tblr]?-[0-5]$/.test(cls)) {
        classes.push(cls);
      }
    });

    return classes.join(',');
  }

  /**
   * Collect all data-* attributes from element (excluding data-xlate- attributes).
   * @param {Element} element - The element to collect attributes from
   * @returns {string} Comma-separated attribute values
   */
  function collectDataAttributes(element) {
    if (!element || !element.attributes) {
      return '';
    }

    var dataAttrs = [];
    for (var i = 0; i < element.attributes.length; i++) {
      var attr = element.attributes[i];
      if (attr.name.indexOf('data-') === 0 && attr.name.indexOf('data-xlate') !== 0 && attr.value) {
        dataAttrs.push(attr.value);
      }
    }
    return dataAttrs.join(',');
  }
  Translator.utils.collectContextClasses = collectContextClasses;
  Translator.utils.collectDataAttributes = collectDataAttributes;

  /**
   * Simple deterministic 12-char hash using two 32-bit accumulators (FNV-1a style + mix)
   * Avoids constant zero padding by combining two hashes and truncating.
   * @param {string} str - The string to hash
   * @returns {string} 12-character base36 hash
   */
  function simpleHash(str) {
    // eslint-disable-next-line no-bitwise
    var h1 = 2166136261 >>> 0; // FNV-1a offset basis
    // eslint-disable-next-line no-bitwise
    var h2 = 0x9e3779b1 >>> 0; // Golden ratio constant

    for (var i = 0; i < str.length; i++) {
      var c = str.charCodeAt(i);
      // FNV-1a step on h1
      // eslint-disable-next-line no-bitwise
      h1 ^= c;
      h1 = Math.imul(h1, 16777619);

      // Mix on h2 (inspired by Murmur3 avalanching)
      h2 = (h2 + c) >>> 0; // eslint-disable-line no-bitwise
      // eslint-disable-next-line no-bitwise
      var k = h2 ^ (h2 >>> 16);
      h2 = Math.imul(k, 2246822507);
      // eslint-disable-next-line no-bitwise
      k = h2 ^ (h2 >>> 13);
      h2 = Math.imul(k, 3266489909);
      // eslint-disable-next-line no-bitwise
      h2 = (h2 ^ (h2 >>> 16)) >>> 0;
    }

    // Combine and encode base36, then truncate to 12 chars
    // eslint-disable-next-line no-bitwise
    var s = (h1 >>> 0).toString(36) + (h2 >>> 0).toString(36);
    if (s.length < 12) {
      s = (s + 'qwertyuiopasdfghjklz').substring(0, 12);
    } else if (s.length > 12) {
      s = s.substring(0, 12);
    }
    return s;
  }
  /**
   * Determine whether text should be considered for translation.
   * @param {string} text - Candidate text
   * @returns {boolean} True when text looks translatable
   */
  function isTranslatableText(text) {
    if (!text || text.length < 3) {
      return false;
    }

    var alphaCount = (text.match(/[a-zA-Z]/g) || []).length;
    if (alphaCount < text.length * 0.3) {
      return false;
    }

    var commonWords = ['ok', 'id', 'url', 'api'];
    if (commonWords.indexOf(text.toLowerCase()) !== -1) {
      return false;
    }

    return true;
  }
  Translator.utils.isTranslatableText = isTranslatableText;
  Translator.utils.simpleHash = simpleHash;

  /**
   * Generate translation key from element structure + direct text (ignoring children)
   * @param {Element} element - The element to generate key for
   * @param {string} text - The text content
   * @param {string} type - The type (text, placeholder, etc)
   * @returns {string} 12-character hash key
   */
  function generateKey(element, text, type) {
    if (!element || !text) {
      return '';
    }

    var parts = [];

    // Parent context
    var parent = element.parentElement;
    if (parent && parent.tagName) {
      parts.push(parent.tagName.toLowerCase());
      var parentClasses = collectContextClasses(parent);
      if (parentClasses) {
        parts.push(parentClasses);
      }
      var parentData = collectDataAttributes(parent);
      if (parentData) {
        parts.push(parentData);
      }
    }

    // Current element context
    if (element.tagName) {
      parts.push(element.tagName.toLowerCase());
    }
    var classes = collectContextClasses(element);
    if (classes) {
      parts.push(classes);
    }
    var dataAttrs = collectDataAttributes(element);
    if (dataAttrs) {
      parts.push(dataAttrs);
    }

    // Type and direct text only (ignore children)
    if (type && type !== 'text') {
      parts.push(type);
    }
    // Get only direct text nodes (ignore children)
    var directText = '';
    for (var i = 0; i < element.childNodes.length; i++) {
      var node = element.childNodes[i];
      if (node.nodeType === 3) { // TEXT_NODE
        directText += node.textContent;
      }
    }
    directText = directText.trim();
    parts.push(directText);

    return simpleHash(parts.join('.'));
  }
  Translator.keys.generateKey = generateKey;
  Translator.keys.generateKey = generateKey;

  // ============================================================================
  // KEY ATTRIBUTE MANAGEMENT
  // ============================================================================

  /**
   * Set data-xlate-key-{type} attribute
   * @param {Element} element - The element to set attribute on
   * @param {string} type - The type (text, placeholder, etc)
   * @param {string} key - The key value
   */
  function setKeyAttribute(element, type, key) {
    if (!element || !key) {
      return;
    }
    var attrType = type === 'text' ? 'content' : type;
    element.setAttribute(ATTR_KEY_PREFIX + attrType, key);
  }
  Translator.attrs.setKeyAttribute = setKeyAttribute;
  Translator.attrs.setKeyAttribute = setKeyAttribute;

  /**
   * Get data-xlate-key-{type} attribute
   * @param {Element} element - The element to get attribute from
   * @param {string} type - The type (text, placeholder, etc)
   * @returns {string|null} The key value
   */
  function getKeyFromAttributes(element, type) {
    if (!element) {
      return null;
    }
    var attrType = type === 'text' ? 'content' : type;
    return element.getAttribute(ATTR_KEY_PREFIX + attrType);
  }
  Translator.attrs.getKeyFromAttributes = getKeyFromAttributes;
  Translator.attrs.getKeyFromAttributes = getKeyFromAttributes;

  // ============================================================================
  // TRANSLATION (Step 3: currentLang !== siteLang)
  // ============================================================================

  /**
   * Translate element using bundle
   * @param {Element} element - The element to translate
   * @param {string} type - The type (text, placeholder, etc)
   * @param {Object} map - The translation map
   */
  function translateElement(element, type, map) {
    var key = getKeyFromAttributes(element, type);
    if (!key || !map[key]) {
      return;
    }

    if (type === 'text') {
      element.textContent = map[key];
    } else {
      element.setAttribute(type, map[key]);
    }
  }
  /**
   * Translate a single element using the provided translation map.
   * @param {Element} element - Element to translate
   * @param {string} type - Type of translation (text, placeholder, etc)
   * @param {Object} map - Translation map keyed by generated keys
   */
  Translator.capture.translateElement = translateElement;
  Translator.capture.translateElement = translateElement;

  // ============================================================================
  // CAPTURE (Step 2: currentLang === siteLang)
  // ============================================================================

  /**
   * Detect component from element context
   * @param {Element} element - The element to detect component for
   * @returns {string} Component name
   */
  function detectComponent(element) {
    if (!element) {
      return 'core';
    }

    var container = element.closest('[data-region]');
    if (container) {
      var region = container.getAttribute('data-region');
      if (region) {
        return 'region_' + region;
      }
    }

    container = element.closest('.block');
    if (container) {
      var blockClass = container.className.match(/block_(\w+)/);
      if (blockClass) {
        return 'block_' + blockClass[1];
      }
    }

    if (document.body.classList.contains('path-admin')) {
      return 'admin';
    }

    return 'core';
  }
  /**
   * Save new key and source text to the backend via Ajax.
   * @param {Element} element - Source element
   * @param {string} text - Source text to save
   * @param {string} type - The attribute/type (text, placeholder, ...)
   * @param {string} key - Generated structural key
   * @param {Object} existingMap - Optional map to avoid saving existing keys
   */
  Translator.capture.detectComponent = detectComponent;
  Translator.capture.detectComponent = detectComponent;

  /**
   * Save translatable string to database
   * @param {Element} element - The element being saved
   * @param {string} text - The text content
   * @param {string} type - The type (text, placeholder, etc)
   * @param {string} key - The generated key
   * @param {Object} existingMap - Optional bundle map to check before saving
   */
  function saveToDatabase(element, text, type, key, existingMap) {
    // If key already exists in the bundle, skip saving
    if (existingMap && existingMap[key]) {
      xlateDebug('[XLATE] Skipping save - key exists:', key);
      return;
    }

    var component = detectComponent(element);
    var dedupeKey = component + ':' + key + ':' + type;

    if (detectedStrings.has(dedupeKey)) {
      return;
    }
    detectedStrings.add(dedupeKey);

    xlateDebug('[XLATE] Saving new key:', key, 'component:', component, 'text:', text.substring(0, 50));

    // Determine page-level course id (prefer server-injected XLATE_COURSEID when present)
    var pageCourseId = 0;
    if (typeof window !== 'undefined' && typeof window.XLATE_COURSEID !== 'undefined') {
      pageCourseId = window.XLATE_COURSEID;
    } else if (typeof M !== 'undefined' && M.cfg && M.cfg.courseid) {
      pageCourseId = M.cfg.courseid;
    }

    Ajax.call([{
      methodname: 'local_xlate_save_key',
      args: {
        component: component,
        key: key,
        source: text,
        lang: (window.__XLATE__ && window.__XLATE__.lang) || M.cfg.language || 'en',
        translation: text,
        courseid: pageCourseId,
        context: component
      }
    }])[0].then(function() {
      if (window.__XLATE__) {
        if (!window.__XLATE__.map) {
          window.__XLATE__.map = {};
        }
        window.__XLATE__.map[key] = text;
      }
      return true;
    }).catch(function() {
      detectedStrings.delete(dedupeKey);
    });
  }
  Translator.capture.saveToDatabase = saveToDatabase;
  Translator.capture.saveToDatabase = saveToDatabase;

  // ============================================================================
  // ELEMENT PROCESSING (Step 1: Tag FIRST)
  // ============================================================================

  /**
   * Check if element should be ignored
   * @param {Element} element - The element to check
   * @returns {boolean} True if should be ignored
   */
  function shouldIgnoreElement(element) {
    if (!element || !element.tagName) {
      return true;
    }

    var tagName = element.tagName.toLowerCase();
    if ([
      'script', 'style', 'meta', 'link', 'noscript', 'head'
    ].indexOf(tagName) !== -1) {
      return true;
    }

    // Exclude selectors from settings (window.XLATE_EXCLUDE_SELECTORS)
    if (window.XLATE_EXCLUDE_SELECTORS && Array.isArray(window.XLATE_EXCLUDE_SELECTORS)) {
      for (var i = 0; i < window.XLATE_EXCLUDE_SELECTORS.length; i++) {
        var sel = window.XLATE_EXCLUDE_SELECTORS[i];
        if (!sel) {
          continue;
        }
        try {
          if (element.matches(sel) || (element.closest && element.closest(sel))) {
            return true;
          }
        } catch (e) {
          xlateDebug('[XLATE][DEBUG] Invalid selector:', sel, e);
        }
      }
    }

    if (element.hasAttribute('data-xlate-ignore') || element.closest('[data-xlate-ignore]')) {
      return true;
    }

    // Do not skip elements just because they already have key attributes;
    // we rely on processedElements to prevent duplicate work.

    // Admin paths
    var currentPath = window.location.pathname || '';
    var adminPaths = ['/admin/', '/local/xlate/', '/course/modedit.php'];
    for (var p = 0; p < adminPaths.length; p++) {
      if (currentPath.indexOf(adminPaths[p]) === 0) {
        return true;
      }
    }

    // Admin selectors
    var adminSelectors = ['.navbar', '.navigation', '.breadcrumb', '.drawer', '.tooltip'];
    for (var s = 0; s < adminSelectors.length; s++) {
      if (element.closest(adminSelectors[s])) {
        return true;
      }
    }

    return false;
  }
  Translator.dom.shouldIgnoreElement = shouldIgnoreElement;


  /**
   * Collect any data-xlate-key-* attribute values from an element into a set-like object
   * @param {Element} el - Element to inspect
   * @param {Object} keySet - Object used as a set to store keys
   */
  function collectKeysFromElement(el, keySet) {
    var attrs = el && el.attributes;
    if (!attrs) {
      return;
    }
    for (var j = 0; j < attrs.length; j++) {
      var a = attrs[j];
      var name = a && a.name;
      if (name && name.indexOf(ATTR_KEY_PREFIX) === 0) {
        var val = a.value;
        if (val) {
          keySet[val] = true;
        }
      }
    }
  }
  /**
   * Process a single DOM element: tag, then optionally save or translate.
   * @param {Element} element - Element to process
   * @param {Object} map - Translation map
   * @param {boolean} tagOnly - When true, only add key attributes
   */
  function processElement(element, map, tagOnly) {
    if (shouldIgnoreElement(element) || processedElements.has(element)) {
      return;
    }
    processedElements.add(element);

    var currentLang = (window.__XLATE__ && window.__XLATE__.lang) || M.cfg.language || 'en';
    var siteLang = (window.__XLATE__ && window.__XLATE__.siteLang) || 'en';
    var isCapture = (currentLang === siteLang);

    // Process text content: only if direct text (excluding children) is non-empty
    var directText = '';
    for (var i = 0; i < element.childNodes.length; i++) {
      var node = element.childNodes[i];
      if (node.nodeType === 3) { // TEXT_NODE
        directText += node.textContent;
      }
    }
    directText = directText.trim();
    if (directText && isTranslatableText(directText)) {
      var textKey = generateKey(element, directText, 'text');
      if (textKey) {
        setKeyAttribute(element, 'text', textKey); // Step 1: TAG
        if (!tagOnly) {
          if (isCapture) {
            saveToDatabase(element, directText, 'text', textKey, map); // Step 2: SAVE (skip if in map)
          } else if (map) {
            translateElement(element, 'text', map); // Step 3: TRANSLATE
          }
        }
      }
    }

    // Process attributes
  ATTRIBUTE_TYPES.forEach(function(attr) {
      if (!element.hasAttribute(attr)) {
        return;
      }
      var value = element.getAttribute(attr).trim();
      if (value && isTranslatableText(value)) {
        var attrKey = generateKey(element, value, attr);
        if (attrKey) {
          setKeyAttribute(element, attr, attrKey); // Step 1: TAG
          if (!tagOnly) {
            if (isCapture) {
              saveToDatabase(element, value, attr, attrKey, map); // Step 2: SAVE (skip if in map)
            } else if (map) {
              translateElement(element, attr, map); // Step 3: TRANSLATE
            }
          }
        }
      }
    });
  }
  Translator.dom.collectKeysFromElement = collectKeysFromElement;
  Translator.dom.processElement = processElement;

  // ============================================================================
  // DOM WALKING
  // ============================================================================

  /**
   * Walk DOM and process elements
   * @param {Element} root - Root element to start from
   * @param {Object} map - The translation map
   * @param {boolean} tagOnly - When true, only tag keys without saving/translating
   */
  function walk(root, map, tagOnly) {
    if (!root) {
      return;
    }

    // If capture selectors are set, only walk those areas
    var captureSelectors = (window.XLATE_CAPTURE_SELECTORS &&
      Array.isArray(window.XLATE_CAPTURE_SELECTORS) &&
      window.XLATE_CAPTURE_SELECTORS.length)
      ? window.XLATE_CAPTURE_SELECTORS : null;

    var roots = [];
    if (captureSelectors) {
  captureSelectors.forEach(function(sel) {
        try {
          var found = document.querySelectorAll(sel);
          for (var i = 0; i < found.length; i++) {
            roots.push(found[i]);
          }
  } catch (e) { /* Ignore invalid selectors */ }
      });
      if (!roots.length) {
        roots = [root]; // Fallback to body
      }
    } else {
      roots = [root];
    }

  roots.forEach(function(scanRoot) {
      var stack = [scanRoot];
      while (stack.length) {
        var el = stack.pop();
        if (el.nodeType === 1) {
          // If this element should be ignored (exclusion zone), skip its subtree
          if (shouldIgnoreElement(el)) {
            continue;
          }
          processElement(el, map, tagOnly);
          var children = el.children || [];
          for (var i = 0; i < children.length; i++) {
            stack.push(children[i]);
          }
        }
      }
    });
  }
  Translator.dom.walk = walk;
  Translator.dom.walk = walk;

  /**
   * Run translator
   * @param {Object} map - The translation map
   */
  function run(map) {
    try {
      walk(document.body, map || {});

      // Fallback: periodic refreshes to catch late-injected content
  setTimeout(function() {
        walk(document.body, map || {});
      }, 1000);
  setTimeout(function() {
        walk(document.body, map || {});
      }, 3000);
  setTimeout(function() {
        walk(document.body, map || {});
      }, 6000);

      var mo = new MutationObserver(function(muts) {
        muts.forEach(function(mutation) {
          Array.prototype.slice.call(mutation.addedNodes || []).forEach(function(node) {
            if (node.nodeType === 1) {
              walk(node, map || {});
            }
          });
        });
      });
    mo.observe(document.body, {childList: true, subtree: true});

      if (typeof window.addEventListener === 'function') {
        ['focus', 'click', 'scroll'].forEach(function(eventType) {
          document.addEventListener(eventType, function() {
            var now = Date.now();
            if (now - lastProcessTime > processThrottle) {
              lastProcessTime = now;
              setTimeout(function() {
                walk(document.body, map || {});
              }, 100);
            }
          }, true);
        });
      }
    } finally {
      document.documentElement.classList.remove('xlate-loading');
    }
  }
  Translator.api.run = run;

  /**
   * Initialize translator
   * @param {Object} config - Configuration object
   */
  function init(config) {
    document.documentElement.classList.add('xlate-loading');

    // If editing mode is enabled, skip all capture/tagging logic
    if (config.isEditing) {
      xlateDebug('[XLATE] Edit mode detected (isEditing=true): skipping translation/capture logic.');
      document.documentElement.classList.remove('xlate-loading');
      return;
    }

    // No autodetect config: auto-detection is always enabled.

    window.__XLATE__ = {
      lang: config.lang,
      siteLang: config.siteLang,
      map: {},
      sourceMap: {}
    };

    /**
     * Process bundle data
     * @param {Object} bundleData - The bundle data
     */
    var currentLang = config.lang;
    var siteLang = config.siteLang;
    var isCapture = (currentLang === siteLang);

    // Detect course id exposed by server-side hook or fallback to M.cfg
    var courseId = null;
    if (typeof window !== 'undefined' && typeof window.XLATE_COURSEID !== 'undefined') {
      courseId = window.XLATE_COURSEID;
    } else if (typeof M !== 'undefined' && M.cfg && M.cfg.courseid) {
      courseId = M.cfg.courseid;
    }

    xlateDebug('[XLATE] Initializing:', {
      currentLang: currentLang,
      siteLang: siteLang,
      isCapture: isCapture,
      courseId: courseId
    });
    // Auto-detect enabled by default (autoDetectEnabled removed)

    // In capture mode: fetch bundle first to check existing keys, then tag + save only new ones
    if (isCapture) {
      xlateDebug('[XLATE] Capture mode - starting tag-only pass');
      processedElements = new WeakSet();
      // Tag-only first pass to generate keys
      walk(document.body, {}, true);

      // Collect all tagged keys
      var keySetCap = {};
      var allCap = document.querySelectorAll('*');
      for (var ci = 0; ci < allCap.length; ci++) {
        collectKeysFromElement(allCap[ci], keySetCap);
      }
      var keysCap = Object.keys(keySetCap);

      xlateDebug('[XLATE] Collected', keysCap.length, 'keys from DOM');

      if (keysCap.length === 0) {
        xlateDebug('[XLATE] No keys found, skipping bundle fetch');
        run({});
        return;
      }

      xlateDebug('[XLATE] Fetching bundle to check existing keys...');
      // Fetch existing translations for these keys
      fetch(config.bundleurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({keys: keysCap})
      })
        .then(function(response) {
          return response.json();
        })
        .then(function(map) {
          var translations = (map && map.translations) ? map.translations : map;
          if (!translations || typeof translations !== 'object') {
            translations = {};
          }
          window.__XLATE__.map = translations;

          var existingCount = Object.keys(translations).length;
          xlateDebug('[XLATE] Bundle returned', existingCount, 'existing translations');

          // Now walk again to save only keys NOT in the bundle
          processedElements = new WeakSet();
          walk(document.body, translations, false);
          run(translations);
          return true;
        })
        .catch(function(err) {
          xlateDebug('[XLATE] Bundle fetch failed:', err);
          // If bundle fetch fails, save everything
          processedElements = new WeakSet();
          walk(document.body, {}, false);
          run({});
        });
      return;
    }

    xlateDebug('[XLATE] Translation mode - starting tag-only pass');
    // Translation mode: pre-tag, collect keys, request filtered bundle, then translate
    try {
      // Pre-tag only
      processedElements = new WeakSet();
      walk(document.body, {}, true);

      // Collect tagged keys from DOM
      var keySet = {};
      var all = document.querySelectorAll('*');
      for (var i = 0; i < all.length; i++) {
        collectKeysFromElement(all[i], keySet);
      }
      var keys = Object.keys(keySet);

      // Short-circuit if no keys
      if (keys.length === 0) {
        run({});
        return;
      }

      var k = 'xlate:' + config.lang + ':' + config.version + ':keys:' + keys.length;
      var cached = null;
      try {
        cached = localStorage.getItem(k);
  } catch (e) {
        // Ignore
      }
      if (cached) {
        try {
          var cachedMap = JSON.parse(cached);
          if (cachedMap && typeof cachedMap === 'object') {
            window.__XLATE__.map = cachedMap;
            processedElements = new WeakSet();
            run(cachedMap);
          }
  } catch (e) {
          // Ignore
        }
      }

      fetch(config.bundleurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({keys: keys})
      })
        .then(function(response) {
          return response.json();
        })
        .then(function(map) {
          // Accept either flat map or legacy wrapper
          var translations = (map && map.translations) ? map.translations : map;
          if (!translations || typeof translations !== 'object') {
            translations = {};
          }
          try {
            localStorage.setItem(k, JSON.stringify(translations));
          } catch (e) {
            // Ignore
          }
          window.__XLATE__.map = translations;
          processedElements = new WeakSet();
          run(translations);
          return true;
        })
        .catch(function() {
          run({});
        });
  } catch (err) {
      run({});
    }
  }
  Translator.api.init = init;

  /**
   * Enable or disable auto-detect
   * @param {boolean} enabled - Whether to enable auto-detect
   */


  Translator.run = run;
  Translator.init = init;

  return Translator;
});