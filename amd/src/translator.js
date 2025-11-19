// Local/xlate/amd/src/translator.js
// Handles DOM translation and automatic key capture with structural-based keys.
//
// WORKFLOW:
// 1. Tag element with data-xlate-key-{type} FIRST (always)
// 2. If currentLang === sourceLang: Save to DB (capture mode)
// 3. If currentLang !== sourceLang: Translate using bundle
/**
 * AMD module that detects, captures, and renders Local Xlate translations.
 *
 * Responsibilities:
 *  - Capture mode: tag DOM nodes, check existing bundle entries, persist newly
 *    discovered strings, and link keys to the active course when permitted.
 *  - Translation mode: tag DOM nodes, request a filtered bundle, apply
 *    translations, and track subsequent DOM mutations to keep content synced.
 */
define(['core/ajax'], function (Ajax) {
  var ATTR_KEY_PREFIX = 'data-xlate-key-';
  var ATTRIBUTE_TYPES = [
    'placeholder', 'title', 'alt', 'aria-label'
  ];

  // Auto-detection is always enabled; keys are always auto-assigned.
  var detectedStrings = new Set();
  var processedElements = new WeakSet();
  var lastProcessTime = 0;
  var processThrottle = 250;
  var pendingTranslationKeys = new Set();
  var requestedTranslationKeys = new Set();
  var missingFetchTimer = null;
  var indicatorStylesAdded = false;
  var toggleButton = null;
  var ALLOWED_INLINE_TAGS = {
    'a': ['href', 'title', 'target', 'rel'],
    'abbr': ['title'],
    'b': [],
    'br': [],
    'cite': [],
    'code': [],
    'em': [],
    'i': [],
    'kbd': [],
    'mark': [],
    'q': [],
    's': [],
    'small': [],
    'span': ['class'],
    'strong': [],
    'sub': [],
    'sup': [],
    'u': []
  };
  /* Translator namespace to expose public API while keeping internal helpers private. */
  var Translator = {};
  Translator.utils = {};
  Translator.keys = {};
  Translator.attrs = {};
  Translator.capture = {};
  Translator.dom = {};
  Translator.api = {};

  /**
   * Determine if a URL value is safe for href/src attributes.
   * @param {string} url - Candidate URL.
   * @returns {boolean} True when URL uses an allowed scheme.
   */
  function isSafeUrl(url) {
    if (!url) {
      return false;
    }
    var trimmed = url.trim();
    if (!trimmed) {
      return false;
    }
    if (trimmed[0] === '#') {
      return true;
    }
    var lower = trimmed.toLowerCase();
    var unsafePattern = /^(?:\s*(?:javascript|data)\s*:)/;
    if (unsafePattern.test(lower)) {
      return false;
    }
    var allowedPattern = /^(https?:|mailto:|tel:)/;
    return allowedPattern.test(lower);
  }

  /**
   * Remove a node while keeping its children in place.
   * @param {Element} element - Node to unwrap.
   * @returns {void}
   */
  function unwrapElement(element) {
    if (!element || !element.parentNode) {
      return;
    }
    var parent = element.parentNode;
    while (element.firstChild) {
      parent.insertBefore(element.firstChild, element);
    }
    parent.removeChild(element);
  }

  /**
   * Sanitize attributes on an element to the allowed list for that tag.
   * @param {Element} element - Target element.
   * @param {Array<string>} allowedAttrs - Whitelisted attribute names.
   * @returns {void}
   */
  function sanitizeAttributes(element, allowedAttrs) {
    if (!element || !element.attributes) {
      return;
    }
    var attrs = Array.prototype.slice.call(element.attributes);
    attrs.forEach(function (attr) {
      var name = attr.name.toLowerCase();
      if (allowedAttrs.indexOf(name) === -1) {
        element.removeAttribute(attr.name);
        return;
      }
      if ((name === 'href' || name === 'src') && !isSafeUrl(attr.value || '')) {
        element.removeAttribute(attr.name);
        return;
      }
      if (name === 'target' && attr.value !== '_blank') {
        element.removeAttribute(attr.name);
        return;
      }
      if (name === 'target' && attr.value === '_blank') {
        element.setAttribute('rel', 'noopener noreferrer');
      }
    });
  }

  /**
   * Recursively sanitize HTML nodes to a safe inline subset.
   * @param {Node} root - Root node to sanitize.
   * @returns {void}
   */
  function sanitizeNode(root) {
    if (!root || !root.childNodes) {
      return;
    }
    var children = Array.prototype.slice.call(root.childNodes);
    children.forEach(function (child) {
      if (child.nodeType === 1) {
        var tag = child.tagName.toLowerCase();
        if (!Object.prototype.hasOwnProperty.call(ALLOWED_INLINE_TAGS, tag)) {
          sanitizeNode(child);
          unwrapElement(child);
          return;
        }
        sanitizeAttributes(child, ALLOWED_INLINE_TAGS[tag]);
        sanitizeNode(child);
        return;
      }
      if (child.nodeType !== 3) {
        child.parentNode.removeChild(child);
      }
    });
  }

  /**
   * Sanitize translated HTML before injecting into the DOM.
   * @param {string} value - Raw translation string.
   * @param {string} targetTag - Tag name of the host element.
   * @returns {string} Sanitized HTML safe for innerHTML.
   */
  function sanitizeTranslationHtml(value, targetTag) {
    if (!value) {
      return '';
    }
    var container = document.createElement('div');
    container.innerHTML = value;
    sanitizeNode(container);

    // When translators wrap the same tag (e.g. <p> inside <p>), unwrap it.
    if (targetTag && container.childNodes.length === 1) {
      var firstChild = container.childNodes[0];
      if (firstChild.nodeType === 1 && firstChild.tagName.toLowerCase() === targetTag && firstChild.childNodes.length) {
        var unwrap = document.createElement('div');
        while (firstChild.firstChild) {
          unwrap.appendChild(firstChild.firstChild);
        }
        return unwrap.innerHTML;
      }
    }

    return container.innerHTML;
  }

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
   * Collect relevant CSS classes to contribute to structural hash context.
   * @param {Element} element - Element whose classes will be analyzed.
   * @returns {string} Comma-separated class list used for hashing.
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
    Array.prototype.forEach.call(element.classList, function (cls) {
      if (cls && cls.length > 2 && blacklist.indexOf(cls) === -1 &&
        !/^[0-9]/.test(cls) && !/^[mp][tblr]?-[0-5]$/.test(cls)) {
        classes.push(cls);
      }
    });

    return classes.join(',');
  }

  /**
   * Collect all non-XLATE data-* attribute values for hashing context.
   * @param {Element} element - Element to inspect.
   * @returns {string} Comma-separated attribute values.
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
   * Determine whether a translation map already contains a key.
   * @param {Object<string,string>} map - Translation lookup table.
   * @param {string} key - Structural key to check.
   * @returns {boolean} True when the key exists in the map.
   */
  function hasTranslation(map, key) {
    return !!(map && Object.prototype.hasOwnProperty.call(map, key));
  }
  Translator.utils.hasTranslation = hasTranslation;

  /**
   * Obtain direct child text content (excluding descendants) from an element.
   * @param {Element} element - Target element.
   * @returns {string} Direct text content.
   */
  /**
   * Persist the original value for an element so toggles can restore it later.
   * @param {Element} element - Element being translated.
   * @param {string} type - Translation type (`text` or attribute name).
   * @returns {void}
   */
  function storeOriginalValue(element, type) {
    if (!element) {
      return;
    }
    var attrType = type === 'text' ? 'content' : type;
    var dataAttr = 'data-xlate-original-' + attrType;
    if (element.hasAttribute(dataAttr)) {
      return;
    }
    var original = '';
    if (type === 'text') {
      original = element.innerHTML;
    } else {
      original = element.getAttribute(type) || '';
    }
    element.setAttribute(dataAttr, original);
  }

  /**
   * Restore the previously stored original value for an element.
   * @param {Element} element - Element to restore.
   * @param {string} type - Translation type (`text` or attribute name).
   * @returns {void}
   */
  function restoreOriginalValue(element, type) {
    if (!element) {
      return;
    }
    var attrType = type === 'text' ? 'content' : type;
    var dataAttr = 'data-xlate-original-' + attrType;
    if (!element.hasAttribute(dataAttr)) {
      return;
    }
    var original = element.getAttribute(dataAttr) || '';
    if (type === 'text') {
      element.innerHTML = original;
    } else {
      element.setAttribute(type, original);
    }
  }

  /**
   * Determine whether translations should currently be displayed.
   * @returns {boolean} True when translation overlay is enabled.
   */
  function shouldShowTranslations() {
    if (!window.__XLATE__) {
      return true;
    }
    return window.__XLATE__.showTranslations !== false;
  }

  /**
   * Check if a translation key has been human-reviewed.
   * @param {string} key - Structural translation key.
   * @returns {boolean} True when the key is marked reviewed.
   */
  function isKeyReviewed(key) {
    if (!window.__XLATE__ || !window.__XLATE__.reviewMap) {
      return true;
    }
    var flag = window.__XLATE__.reviewMap[key];
    return flag === 1 || flag === '1' || flag === true;
  }

  /**
   * Toggle the inline auto-translation indicator for a given element.
   * @param {Element} element - Host element for the indicator.
   * @param {string} key - Translation key (used for state tracking).
   * @param {boolean} show - Whether to display the indicator.
   * @returns {void}
   */
  function toggleAutoIndicator(element, key, show) {
    if (!element || typeof element !== 'object') {
      return;
    }
    if (!show) {
      if (element.__xlateIndicator && element.__xlateIndicator.remove) {
        element.__xlateIndicator.remove();
      }
      element.__xlateIndicator = null;
      return;
    }

    if (element.__xlateIndicator) {
      return;
    }

    ensureIndicatorStyles();

    var indicator = document.createElement('span');
    indicator.className = 'xlate-auto-indicator icon fa fa-globe text-muted';
    indicator.setAttribute('role', 'img');
    indicator.setAttribute('aria-label', 'AI translated');
    indicator.setAttribute('title', 'AI translated');
    indicator.setAttribute('data-xlate-indicator', key || '');

    if (typeof element.appendChild === 'function') {
      element.appendChild(indicator);
    }

    element.__xlateIndicator = indicator;
  }

  /**
   * Inject the inline styles required for indicators and toggle control.
   * @returns {void}
   */
  function ensureIndicatorStyles() {
    if (indicatorStylesAdded) {
      return;
    }
    indicatorStylesAdded = true;
    var style = document.createElement('style');
    style.setAttribute('data-xlate-style', 'indicator');
    style.textContent = '' +
      '.xlate-auto-indicator {' +
      '  display:inline-flex;' +
      '  align-items:center;' +
      '  font-size:0.75em;' +
      '  margin-left:0.35em;' +
      '  opacity:0.75;' +
      '}' +
      '.xlate-auto-toggle {' +
      '  position:fixed;' +
      '  right:1rem;' +
      '  bottom:1rem;' +
      '  z-index:1040;' +
      '}' +
      '.xlate-auto-toggle .icon {' +
      '  margin-right:0.35em;' +
      '}';
    document.head.appendChild(style);
  }

  /**
   * Update the toggle button label to reflect current mode.
   * @returns {void}
   */
  function updateToggleButtonLabel() {
    if (!toggleButton) {
      return;
    }
    var enabled = shouldShowTranslations();
    var label = enabled ? 'Hide AI translations' : 'Show AI translations';
    toggleButton.innerHTML = '<span class="icon fa fa-language" aria-hidden="true"></span>' + label;
    toggleButton.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    toggleButton.setAttribute('title', label);
  }

  /**
   * Handle user toggling AI translation display.
   * @param {Event} e - Click event instance.
   * @returns {void}
   */
  function handleToggleClick(e) {
    if (e) {
      e.preventDefault();
    }
    if (!window.__XLATE__) {
      return;
    }
    window.__XLATE__.showTranslations = !shouldShowTranslations();
    updateToggleButtonLabel();
    processedElements = new WeakSet();
    walk(document.body, window.__XLATE__.map || {}, false);
  }

  /**
   * Ensure the floating toggle control is present for translation mode.
   * @returns {void}
   */
  function ensureToggleControl() {
    if (!window.__XLATE__ || window.__XLATE__.isCapture) {
      return;
    }
    ensureIndicatorStyles();
    if (toggleButton) {
      return;
    }
    toggleButton = document.createElement('button');
    toggleButton.type = 'button';
    toggleButton.className = 'btn btn-light btn-sm xlate-auto-toggle';
    toggleButton.addEventListener('click', handleToggleClick);
    document.body.appendChild(toggleButton);
    updateToggleButtonLabel();
  }

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
  // TRANSLATION (Step 3: currentLang !== sourceLang)
  // ============================================================================

  /**
   * Translate element using bundle
   * @param {Element} element - The element to translate
   * @param {string} type - The type (text, placeholder, etc)
   * @param {Object} map - The translation map
   */
  function translateElement(element, type, map) {
    var key = getKeyFromAttributes(element, type);
    if (!key) {
      return;
    }

    storeOriginalValue(element, type);

    if (!shouldShowTranslations()) {
      restoreOriginalValue(element, type);
      if (type === 'text') {
        toggleAutoIndicator(element, key, false);
      }
      return;
    }

    if (!map || !hasTranslation(map, key)) {
      if (type === 'text') {
        toggleAutoIndicator(element, key, false);
      }
      return;
    }

    var value = map[key];
    if (typeof value !== 'string') {
      return;
    }

    if (type === 'text') {
      var hostTag = element.tagName ? element.tagName.toLowerCase() : '';
      var sanitized = sanitizeTranslationHtml(value, hostTag);
      element.innerHTML = sanitized;
      toggleAutoIndicator(element, key, !isKeyReviewed(key));
    } else {
      element.setAttribute(type, value);
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
  // CAPTURE (Step 2: currentLang === sourceLang)
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

    var curLang = (window.__XLATE__ && window.__XLATE__.lang) || M.cfg.language || 'en';
    var sourceLang = (window.__XLATE__ && window.__XLATE__.sourceLang) ||
      (window.__XLATE__ && window.__XLATE__.captureSourceLang) || 'en';
    var reviewedFlag = (curLang === sourceLang) ? 1 : 0;

    Ajax.call([{
      methodname: 'local_xlate_save_key',
      args: {
        component: component,
        key: key,
        source: text,
        lang: curLang,
        translation: text,
        // If the current page language equals the configured source, mark
        // captured source strings as reviewed (human-authored content).
        reviewed: reviewedFlag,
        courseid: pageCourseId,
        context: component
      }
    }])[0].then(function () {
      if (window.__XLATE__) {
        if (!window.__XLATE__.map) {
          window.__XLATE__.map = {};
        }
        window.__XLATE__.map[key] = text;
        if (!window.__XLATE__.reviewMap) {
          window.__XLATE__.reviewMap = {};
        }
        window.__XLATE__.reviewMap[key] = 1;
      }
      return true;
    }).catch(function () {
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

    // Admin paths stay blocked to avoid capturing config screens where translators do not run.
    var currentPath = window.location.pathname || '';
    var adminPaths = ['/admin/', '/local/xlate/', '/course/modedit.php'];
    for (var p = 0; p < adminPaths.length; p++) {
      if (currentPath.indexOf(adminPaths[p]) === 0) {
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
   * Process a candidate value for translation or capture.
   * @param {Element} element - Element being processed.
   * @param {string} value - Source text or attribute value.
   * @param {string} attrName - Attribute name ('text' for text nodes).
   * @param {boolean} tagOnly - Whether we are in tag-only mode.
   * @param {boolean} isCapture - Whether capture mode is active.
   * @param {Object} map - Translation map.
   * @returns {void}
   */
  function processCandidateValue(element, value, attrName, tagOnly, isCapture, map) {
    if (!value || !isTranslatableText(value)) {
      return;
    }

    var key = generateKey(element, value, attrName);
    if (!key) {
      return;
    }

    setKeyAttribute(element, attrName, key);

    if (tagOnly) {
      return;
    }

    if (isCapture) {
      saveToDatabase(element, value, attrName, key, map);
      return;
    }

    if (!map) {
      return;
    }

    if (hasTranslation(map, key)) {
      translateElement(element, attrName, map);
      return;
    }

    queueMissingTranslation(key);
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
    var sourceLang = (window.__XLATE__ && window.__XLATE__.sourceLang) ||
      (window.__XLATE__ && window.__XLATE__.captureSourceLang) || 'en';
    var isCapture = (currentLang === sourceLang);

    // For block-level elements, capture innerHTML to preserve inline tags
    var blockTags = ['p', 'li', 'td', 'th', 'blockquote', 'dt', 'dd', 'figcaption'];
    var tag = element.tagName ? element.tagName.toLowerCase() : '';
    var sourceText;
    if (blockTags.indexOf(tag) !== -1) {
      sourceText = element.innerHTML.trim();
    } else {
      // Fallback: direct text only for inline/other elements
      sourceText = '';
      for (var i = 0; i < element.childNodes.length; i++) {
        var node = element.childNodes[i];
        if (node.nodeType === 3) { // TEXT_NODE
          sourceText += node.textContent;
        }
      }
      sourceText = sourceText.trim();
    }
    processCandidateValue(element, sourceText, 'text', tagOnly, isCapture, map);

    // Process attributes
    ATTRIBUTE_TYPES.forEach(function (attr) {
      if (!element.hasAttribute(attr)) {
        return;
      }
      var value = element.getAttribute(attr).trim();
      processCandidateValue(element, value, attr, tagOnly, isCapture, map);
    });
  }
  Translator.dom.collectKeysFromElement = collectKeysFromElement;

  /**
   * Debounced trigger that batches pending keys and requests translations.
   * @returns {void}
   */
  function scheduleMissingFetch() {
    if (missingFetchTimer !== null) {
      return;
    }
    missingFetchTimer = window.setTimeout(function () {
      missingFetchTimer = null;
      if (!pendingTranslationKeys || pendingTranslationKeys.size === 0) {
        return;
      }
      var keys = [];
      pendingTranslationKeys.forEach(function (k) {
        keys.push(k);
      });
      pendingTranslationKeys.clear();
      fetchMissingTranslations(keys);
    }, 200);
  }

  /**
   * Mark keys as requested to avoid duplicate fetching.
   * @param {Array<string>} keys - Keys to record.
   * @returns {void}
   */
  function markKeysAsRequested(keys) {
    keys.forEach(function (k) {
      requestedTranslationKeys.add(k);
    });
  }

  /**
   * Remove keys from requested set after a failed fetch.
   * @param {Array<string>} keys - Keys to remove.
   * @returns {void}
   */
  function unmarkRequestedKeys(keys) {
    keys.forEach(function (k) {
      requestedTranslationKeys.delete(k);
    });
  }

  /**
   * Merge new translations into the provided map.
   * @param {Object} map - Existing translation map.
   * @param {Object} translations - Incoming translations.
   * @returns {boolean} True when at least one entry changed.
   */
  function mergeTranslationsIntoMap(map, translations) {
    var updated = false;
    Object.keys(translations).forEach(function (k) {
      var value = translations[k];
      if (!Object.prototype.hasOwnProperty.call(map, k) || map[k] !== value) {
        map[k] = value;
        updated = true;
      }
    });
    return updated;
  }

  /**
   * Ensure the global review map exists and merge incoming flags.
   * @param {Object|null} reviewUpdates - Incoming review flags.
   * @returns {boolean} True when any review flag changed.
   */
  function applyReviewUpdates(reviewUpdates) {
    if (!reviewUpdates || typeof reviewUpdates !== 'object') {
      return false;
    }

    if (!window.__XLATE__.reviewMap) {
      window.__XLATE__.reviewMap = {};
    }

    var reviewChanged = false;
    Object.keys(reviewUpdates).forEach(function (k) {
      var incoming = reviewUpdates[k];
      if (window.__XLATE__.reviewMap[k] !== incoming) {
        reviewChanged = true;
      }
      window.__XLATE__.reviewMap[k] = incoming;
    });

    return reviewChanged;
  }

  /**
   * Persist the latest translations and review map to local storage cache.
   * @param {Object} translations - Newly received translations.
   * @returns {void}
   */
  function syncCacheWithLatest(translations) {
    if (!window.__XLATE__.cacheKey) {
      return;
    }

    try {
      var cached = localStorage.getItem(window.__XLATE__.cacheKey);
      var cachedPayload = cached ? JSON.parse(cached) : null;
      var cachedTranslations;
      var cachedReviewed;

      if (cachedPayload && typeof cachedPayload === 'object' && cachedPayload.translations) {
        cachedTranslations = cachedPayload.translations;
        cachedReviewed = cachedPayload.reviewed || {};
      } else if (cachedPayload && typeof cachedPayload === 'object' && !Array.isArray(cachedPayload)) {
        cachedTranslations = cachedPayload;
        cachedReviewed = {};
      } else {
        cachedTranslations = {};
        cachedReviewed = {};
      }

      Object.keys(translations).forEach(function (k) {
        cachedTranslations[k] = translations[k];
      });

      var reviewMap = window.__XLATE__.reviewMap || {};
      Object.keys(reviewMap).forEach(function (k) {
        cachedReviewed[k] = reviewMap[k];
      });

      localStorage.setItem(window.__XLATE__.cacheKey, JSON.stringify({
        translations: cachedTranslations,
        reviewed: cachedReviewed
      }));
    } catch (e) {
      // Ignore cache sync errors.
    }
  }

  /**
   * Request translations for keys discovered after the initial bundle load.
   * @param {Array<string>} keys - Structural keys requiring translations.
   * @returns {void}
   */
  function fetchMissingTranslations(keys) {
    if (!keys || !keys.length) {
      return;
    }
    if (!window.__XLATE__ || window.__XLATE__.isCapture) {
      return;
    }

    var bundleUrl = window.__XLATE__.bundleUrl || '';
    if (!bundleUrl) {
      return;
    }

    fetch(bundleUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ keys: keys })
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        var map = window.__XLATE__.map || {};
        var translations = (data && data.translations) ? data.translations : data;
        if (!translations || typeof translations !== 'object') {
          markKeysAsRequested(keys);
          return null;
        }

        var updated = mergeTranslationsIntoMap(map, translations);
        var reviewChanged = applyReviewUpdates(data && data.reviewed ? data.reviewed : null);

        if (updated || reviewChanged) {
          window.__XLATE__.map = map;
          syncCacheWithLatest(translations);

          processedElements = new WeakSet();
          walk(document.body, map, false);
        }

        markKeysAsRequested(keys);
        return null;
      })
      .catch(function (err) {
        unmarkRequestedKeys(keys);
        xlateDebug('[XLATE] Missing translation fetch failed', err);
        return null;
      });
  }

  /**
   * Queue a translation key for deferred fetching if it is not already pending.
   * @param {string} key - Structural key needing translation.
   * @returns {void}
   */
  function queueMissingTranslation(key) {
    if (!key || pendingTranslationKeys.has(key) || requestedTranslationKeys.has(key)) {
      return;
    }
    if (!window.__XLATE__ || window.__XLATE__.isCapture) {
      return;
    }
    pendingTranslationKeys.add(key);
    scheduleMissingFetch();
  }
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
      captureSelectors.forEach(function (sel) {
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

    roots.forEach(function (scanRoot) {
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
   * @param {Object<string,string>} map Translation map keyed by structural hash.
   * @returns {void}
   */
  function run(map) {
    try {
      walk(document.body, map || {});
      if (window.__XLATE__ && !window.__XLATE__.isCapture) {
        ensureToggleControl();
      }

      // Fallback: periodic refreshes to catch late-injected content
      setTimeout(function () {
        walk(document.body, map || {});
        if (window.__XLATE__ && !window.__XLATE__.isCapture) {
          ensureToggleControl();
        }
      }, 1000);
      setTimeout(function () {
        walk(document.body, map || {});
        if (window.__XLATE__ && !window.__XLATE__.isCapture) {
          ensureToggleControl();
        }
      }, 3000);
      setTimeout(function () {
        walk(document.body, map || {});
        if (window.__XLATE__ && !window.__XLATE__.isCapture) {
          ensureToggleControl();
        }
      }, 6000);

      var mo = new MutationObserver(function (muts) {
        muts.forEach(function (mutation) {
          Array.prototype.slice.call(mutation.addedNodes || []).forEach(function (node) {
            if (node.nodeType === 1) {
              walk(node, map || {});
            }
          });
        });
      });
      mo.observe(document.body, { childList: true, subtree: true });

      if (typeof window.addEventListener === 'function') {
        ['focus', 'click', 'scroll'].forEach(function (eventType) {
          document.addEventListener(eventType, function () {
            var now = Date.now();
            if (now - lastProcessTime > processThrottle) {
              lastProcessTime = now;
              setTimeout(function () {
                walk(document.body, map || {});
                if (window.__XLATE__ && !window.__XLATE__.isCapture) {
                  ensureToggleControl();
                }
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
   * Get source value for an element based on the key attribute type.
   * @param {Element} el - Element to inspect
   * @param {string} typename - Attribute type (content for text)
   * @returns {string} Source value or empty string
   */
  function getSourceForElementAttr(el, typename) {
    if (!el) {
      return '';
    }
    if (typename === 'content') {
      var dt = '';
      for (var dn = 0; dn < el.childNodes.length; dn++) {
        var node = el.childNodes[dn];
        if (node.nodeType === 3) {
          dt += node.textContent;
        }
      }
      return dt.trim();
    }
    try {
      return el.getAttribute(typename) || '';
    } catch (e) {
      return '';
    }
  }

  /**
   * Collect key set and first-seen details (component + source) for keys under root.
   * @param {Element} root - Root to scan
   * @returns {Object} {keySet: {}, keyDetails: {}}
   */
  function collectKeySetAndDetails(root) {
    var keySet = {};
    var keyDetails = {};
    var all = (root && root.querySelectorAll) ? root.querySelectorAll('*') : [];
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      collectKeysFromElement(el, keySet);
      var attrs = el && el.attributes;
      if (!attrs) {
        continue;
      }
      for (var j = 0; j < attrs.length; j++) {
        var attrname = attrs[j] && attrs[j].name;
        if (!attrname || attrname.indexOf(ATTR_KEY_PREFIX) !== 0) {
          continue;
        }
        var aval = attrs[j].value;
        if (!aval) {
          continue;
        }
        if (keyDetails[aval]) {
          continue;
        }
        var typename = attrname.substring(ATTR_KEY_PREFIX.length);
        var src = getSourceForElementAttr(el, typename);
        keyDetails[aval] = {
          component: detectComponent(el),
          source: src
        };
      }
    }
    return { keySet: keySet, keyDetails: keyDetails };
  }

  /**
   * Create the base XLATE state object from configuration.
   * @param {TranslatorConfig} config - Translator configuration.
   * @returns {Object} Base state object.
   */
  function createXlateState(config) {
    var resolvedSourceLang = config.sourceLang || config.captureSourceLang || config.lang || 'en';
    var targetLangs = Array.isArray(config.targetLangs) ? config.targetLangs : [];
    var enabledLangs = Array.isArray(config.enabledLangs) ? config.enabledLangs : [];

    var state = {
      lang: config.lang,
      sourceLang: resolvedSourceLang,
      captureSourceLang: config.captureSourceLang || resolvedSourceLang,
      targetLangs: targetLangs,
      enabledLangs: enabledLangs,
      map: {},
      sourceMap: {},
      reviewMap: {},
      bundleUrl: config.bundleurl || '',
      version: config.version || '',
      cacheKey: ''
    };

    state.isCapture = (config.lang === state.captureSourceLang);
    state.isTargetLang = !state.targetLangs.length || state.targetLangs.indexOf(config.lang) !== -1;
    return state;
  }

  /**
   * Resolve the active course id exposed to the page, if any.
   * @returns {number|null} Course id or null when unavailable.
   */
  function resolveCourseId() {
    if (typeof window !== 'undefined' && typeof window.XLATE_COURSEID !== 'undefined') {
      return window.XLATE_COURSEID;
    }

    if (typeof M !== 'undefined' && M.cfg && M.cfg.courseid) {
      return M.cfg.courseid;
    }

    return null;
  }

  /**
   * Associate keys with a course when the backend provides association metadata.
   * @param {Array<string>} keys - Keys collected from DOM.
   * @param {Object} keyDetails - Per-key detail map.
   * @param {Object} associations - Associations returned from bundle.
   * @param {number|null} courseId - Course id when available.
   * @returns {void}
   */
  function associateKeysWithCourse(keys, keyDetails, associations, courseId) {
    if (!courseId || !associations || typeof associations !== 'object') {
      return;
    }

    var toAssociate = [];
    for (var ti = 0; ti < keys.length; ti++) {
      var key = keys[ti];
      if (!associations[key]) {
        var detail = keyDetails[key] || null;
        if (detail) {
          toAssociate.push({
            component: detail.component,
            key: key,
            source: detail.source || ''
          });
        }
      }
    }

    if (!toAssociate.length) {
      return;
    }

    xlateDebug('[XLATE] Associating', toAssociate.length, 'keys with course', courseId);
    try {
      Ajax.call([{
        methodname: 'local_xlate_associate_keys',
        args: {
          keys: toAssociate,
          courseid: courseId,
          context: ''
        }
      }]);
    } catch (e) {
      xlateDebug('[XLATE] Bulk-associate exception', e);
    }
  }

  /**
   * Hydrate cached translations for translation mode.
   * @param {string} cacheKey - Cache key identifier.
   * @returns {{translations:Object, reviewed:Object}|null} Cached payload when available.
   */
  function readCachedBundle(cacheKey) {
    if (!cacheKey) {
      return null;
    }

    try {
      var cached = localStorage.getItem(cacheKey);
      if (!cached) {
        return null;
      }
      var payload = JSON.parse(cached);
      if (!payload || typeof payload !== 'object') {
        return null;
      }

      if (payload.translations && typeof payload.translations === 'object') {
        return {
          translations: payload.translations,
          reviewed: payload.reviewed || {}
        };
      }

      if (!Array.isArray(payload)) {
        return {
          translations: payload,
          reviewed: {}
        };
      }
    } catch (e) {
      return null;
    }

    return null;
  }

  /**
   * Handle capture-mode initialization logic.
   * @param {TranslatorConfig} config - Translator configuration.
   * @param {number|null} courseId - Active course id, if any.
   * @returns {void}
   */
  function initCaptureMode(config, courseId) {
    xlateDebug('[XLATE] Capture mode - starting tag-only pass');
    processedElements = new WeakSet();
    walk(document.body, {}, true);

    var collected = collectKeySetAndDetails(document);
    var keySetCap = collected.keySet;
    var keyDetails = collected.keyDetails;
    var keysCap = Object.keys(keySetCap);

    xlateDebug('[XLATE] Collected', keysCap.length, 'keys from DOM');

    if (!keysCap.length) {
      xlateDebug('[XLATE] No keys found, skipping bundle fetch');
      run({});
      return;
    }

    xlateDebug('[XLATE] Fetching bundle to check existing keys...');
    fetch(config.bundleurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ keys: keysCap })
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (map) {
        var translations = (map && map.translations) ? map.translations : map;
        var sourceMap = (map && map.sourceMap) ? map.sourceMap : {};
        var reviewMap = (map && map.reviewed && typeof map.reviewed === 'object') ? map.reviewed : {};
        var associations = (map && map.associations) ? map.associations : {};
        if (!translations || typeof translations !== 'object') {
          translations = {};
        }
        window.__XLATE__.map = translations;
        window.__XLATE__.sourceMap = sourceMap;
        window.__XLATE__.reviewMap = reviewMap;

        var existingCount = Object.keys(translations).length;
        xlateDebug('[XLATE] Bundle returned', existingCount, 'existing translations');

        associateKeysWithCourse(keysCap, keyDetails, associations, courseId);

        processedElements = new WeakSet();
        walk(document.body, translations, false);
        run(translations);
        return true;
      })
      .catch(function (err) {
        xlateDebug('[XLATE] Bundle fetch failed:', err);
        processedElements = new WeakSet();
        walk(document.body, {}, false);
        run({});
      });
  }

  /**
   * Handle translation-mode initialization logic.
   * @param {TranslatorConfig} config - Translator configuration.
   * @returns {void}
   */
  function initTranslationMode(config) {
    xlateDebug('[XLATE] Translation mode - starting tag-only pass');
    try {
      processedElements = new WeakSet();
      walk(document.body, {}, true);

      var keySet = {};
      var all = document.querySelectorAll('*');
      for (var i = 0; i < all.length; i++) {
        collectKeysFromElement(all[i], keySet);
      }
      var keys = Object.keys(keySet);

      if (!keys.length) {
        run({});
        return;
      }

      var cacheKey = 'xlate:' + config.lang + ':' + config.version + ':keys:' + keys.length;
      window.__XLATE__.cacheKey = cacheKey;

      var cachedPayload = readCachedBundle(cacheKey);
      if (cachedPayload) {
        window.__XLATE__.map = cachedPayload.translations;
        window.__XLATE__.reviewMap = cachedPayload.reviewed;
        processedElements = new WeakSet();
        run(cachedPayload.translations);
      }

      fetch(config.bundleurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ keys: keys })
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (map) {
          var translations = (map && map.translations) ? map.translations : map;
          var reviewMap = (map && map.reviewed && typeof map.reviewed === 'object') ? map.reviewed : {};
          if (!translations || typeof translations !== 'object') {
            translations = {};
          }
          try {
            localStorage.setItem(cacheKey, JSON.stringify({
              translations: translations,
              reviewed: reviewMap
            }));
          } catch (e) {
            // Ignore
          }
          window.__XLATE__.map = translations;
          window.__XLATE__.reviewMap = reviewMap;
          processedElements = new WeakSet();
          run(translations);
          return true;
        })
        .catch(function () {
          run({});
        });
    } catch (err) {
      run({});
    }
  }

  /**
   * @typedef {Object} TranslatorConfig
   * @property {string} lang Current page language code.
  * @property {string} sourceLang Base source language for the request (course or site default).
  * @property {Array<string>} [targetLangs] Target languages configured for this course.
  * @property {Array<string>} [enabledLangs] Enabled languages available site-wide.
  * @property {string} [captureSourceLang] Course-specific source language for capture (falls back to sourceLang if not set).
   * @property {string} bundleurl REST endpoint returning translation bundles.
   * @property {string} version Bundle version hash used for cache busting.
   * @property {boolean} isEditing True when Moodle editing mode is active.
   */

  /**
   * Initialize translator
   * @param {TranslatorConfig} config Configuration object injected server-side.
   */
  function init(config) {
    document.documentElement.classList.add('xlate-loading');

    // If editing mode is enabled, skip all capture/tagging logic
    if (config.isEditing) {
      xlateDebug('[XLATE] Edit mode detected (isEditing=true): skipping translation/capture logic.');
      document.documentElement.classList.remove('xlate-loading');
      return;
    }

    window.__XLATE__ = createXlateState(config);

    var courseId = resolveCourseId();
    xlateDebug('[XLATE] Initializing:', {
      currentLang: config.lang,
      sourceLang: window.__XLATE__.sourceLang,
      captureSourceLang: window.__XLATE__.captureSourceLang,
      targetLangs: window.__XLATE__.targetLangs,
      isCapture: window.__XLATE__.isCapture,
      isTargetLang: window.__XLATE__.isTargetLang,
      courseId: courseId
    });

    if (window.__XLATE__.isCapture) {
      initCaptureMode(config, courseId);
      return;
    }

    if (!window.__XLATE__.isTargetLang) {
      xlateDebug('[XLATE] Current language is not a configured target; skipping translation runtime.');
      document.documentElement.classList.remove('xlate-loading');
      return;
    }

    initTranslationMode(config);
  }
  Translator.api.init = init;

  Translator.run = run;
  Translator.init = init;

  return Translator;
});