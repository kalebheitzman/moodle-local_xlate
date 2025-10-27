/* eslint-disable */
// Deprecated backup of translator.js retained temporarily for reference.
// Intentionally left blank to avoid lint/build errors.
// Original content removed.
function __xlate_translator_old_placeholder__() {
  return null;
}
/*
    return element.getAttribute(ATTR_KEY_PREFIX + attrType);
  }

  /**
   * Translate a single element when metadata is present.
   * @param {Element} node DOM node to translate.
   * @param {Object<string, string>} map Translation map.
   * @returns {void}
   */
function translateNode(node, map) {
  if (!node || node.nodeType !== 1) {
    return;
  }

  var key = getKeyFromAttributes(node, 'text');
  if (key && map[key]) {
    node.textContent = map[key];
    setKeyAttribute(node, 'text', key); // Ensure key is visible in DOM
  } else if (node.childNodes.length === 1 && node.childNodes[0].nodeType === 3) {
    var textContent = node.textContent.trim();
    var normalized = normalizeTextForKey(textContent);
    if (normalized && window.__XLATE__ && window.__XLATE__.sourceMap) {
      var translationKey = window.__XLATE__.sourceMap[normalized];
      if (translationKey && map[translationKey]) {
        setKeyAttribute(node, 'text', translationKey); // Inject key before translation
        node.textContent = map[translationKey];
      }
    }
  }

  ATTRIBUTE_TYPES.forEach(function (attr) {
    var attrKey = getKeyFromAttributes(node, attr);
    if (attrKey && map[attrKey]) {
      node.setAttribute(attr, map[attrKey]);
      setKeyAttribute(node, attr, attrKey); // Ensure key is visible in DOM
      return;
    }

    var value = node.getAttribute && node.getAttribute(attr);
    if (!value) {
      return;
    }

    var normalisedAttr = normalizeTextForKey(value);
    if (normalisedAttr && window.__XLATE__ && window.__XLATE__.sourceMap) {
      var attrTranslationKey = window.__XLATE__.sourceMap[normalisedAttr];
      if (attrTranslationKey && map[attrTranslationKey]) {
        node.setAttribute(attr, map[attrTranslationKey]);
        setKeyAttribute(node, attr, attrTranslationKey); // Inject key before translation
      }
    }
  });
}

/**
 * Check if element should be ignored for auto-detection
 * @param {Element} element - Element to check
 * @returns {boolean} True if should be ignored
 */
function shouldIgnoreElement(element) {
  if (!element || !element.tagName) {
    return true;
  }

  var tagName = element.tagName.toLowerCase();

  if (['script', 'style', 'meta', 'link', 'noscript', 'head'].indexOf(tagName) !== -1) {
    return true;
  }

  if (element.hasAttribute('data-xlate-ignore') || element.closest('[data-xlate-ignore]')) {
    return true;
  }

  // Check for content key (text content)
  if (element.hasAttribute(ATTR_KEY_PREFIX + 'content') ||
    element.hasAttribute(LEGACY_ATTR_PREFIX + 'content')) {
    return true;
  }

  // Check for attribute keys
  for (var i = 0; i < ATTRIBUTE_TYPES.length; i++) {
    if (element.hasAttribute(ATTR_KEY_PREFIX + ATTRIBUTE_TYPES[i]) ||
      element.hasAttribute(LEGACY_ATTR_PREFIX + ATTRIBUTE_TYPES[i])) {
      return true;
    }
  }

  var currentPath = window.location.pathname || '';
  var adminPaths = [
    '/admin/',
    '/local/xlate/',
    '/course/modedit.php',
    '/grade/edit/',
    '/backup/',
    '/restore/',
    '/user/editadvanced.php'
  ];

  for (var p = 0; p < adminPaths.length; p++) {
    if (currentPath.indexOf(adminPaths[p]) === 0) {
      return true;
    }
  }

  var adminSelectors = [
    '.navbar', '.navigation', '.breadcrumb', '.nav',
    '.admin-menu', '.settings-menu', '.user-menu',
    '.page-header-headings', '.page-context-header',
    '.activity-navigation', '.course-content-header',
    '.block_settings', '.block_navigation',
    '#page-navbar', '#nav-drawer', '.drawer',
    '.form-autocomplete-suggestions', '.popover',
    '.tooltip', '.dropdown-menu'
  ];

  for (var iSel = 0; iSel < adminSelectors.length; iSel++) {
    if (element.closest(adminSelectors[iSel])) {
      return true;
    }
  }

  var adminClasses = [
    'editing', 'editor', 'admin-only', 'teacher-only',
    'form-control', 'btn-secondary', 'btn-outline',
    'text-muted', 'small', 'sr-only', 'accesshide'
  ];

  var elementClasses = (element.className || '').split(' ');
  for (var j = 0; j < adminClasses.length; j++) {
    if (elementClasses.indexOf(adminClasses[j]) !== -1) {
      return true;
    }
  }

  var text = element.textContent ? element.textContent.trim() : '';
  if (text.length < 3) {
    return true;
  }

  var adminWords = [
    'edit', 'delete', 'save', 'cancel', 'ok', 'yes', 'no',
    'settings', 'config', 'admin', 'manage', 'update',
    'hide', 'show', 'move', 'copy', 'options', 'actions'
  ];

  if (adminWords.indexOf(text.toLowerCase()) !== -1) {
    return true;
  }

  return false;
}

/**
 * Check if text content is worth translating
 * @param {string} text - Text to analyze
 * @returns {boolean} True if should be translated
 */
function isTranslatableText(text) {
  if (!text || text.length < 3) {
    return false;
  }

  var normalized = normalizeTextForKey(text);
  if (!normalized) {
    return false;
  }

  var alphaCount = (normalized.match(/[a-zA-Z\p{L}]/gu) || []).length;
  if (alphaCount < normalized.length * 0.5) {
    return false;
  }

  var commonWords = ['ok', 'id', 'url', 'api', 'css', 'js', 'html', 'php'];
  if (commonWords.indexOf(normalized) !== -1) {
    return false;
  }

  return true;
}

/**
 * Extract clean text from element, handling simple HTML
 * @param {Element} element - Element to extract text from
 * @returns {string}
 */
function extractCleanText(element) {
  if (!element) {
    return '';
  }

  if (element.children.length === 0) {
    return element.textContent.trim();
  }

  var simpleFormatting = true;
  var children = element.children;
  for (var i = 0; i < children.length; i++) {
    var tagName = children[i].tagName.toLowerCase();
    if (['b', 'i', 'em', 'strong', 'span', 'small'].indexOf(tagName) === -1) {
      simpleFormatting = false;
      break;
    }
  }

  if (simpleFormatting) {
    return element.textContent.trim();
  }

  return '';
}

/**
 * Generate component name from element context
 * @param {Element} element - Element to analyze
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

  container = element.closest('.activity');
  if (container) {
    var activityClass = container.className.match(/modtype_(\w+)/);
    if (activityClass) {
      return 'mod_' + activityClass[1];
    }
  }

  if (document.body.classList.contains('path-admin')) {
    return 'admin';
  }

  return 'core';
}

/**
 * Collect meaningful class names from element (filtering dynamic/utility classes)
 * @param {Element} element - Element to extract classes from
 * @returns {string} Comma-separated class names
 */
function collectContextClasses(element) {
  if (!element) {
    return '';
  }

  var blacklist = ['active', 'show', 'hide', 'hidden', 'collapsed', 'expanded', 'selected', 'current',
    'focus', 'open', 'close', 'tooltip', 'dropdown', 'modal', 'd-flex', 'd-none', 'd-block',
    'mt-1', 'mt-2', 'mt-3', 'mt-4', 'mt-5', 'mb-1', 'mb-2', 'mb-3', 'mb-4', 'mb-5',
    'ml-1', 'ml-2', 'ml-3', 'ml-4', 'ml-5', 'mr-1', 'mr-2', 'mr-3', 'mr-4', 'mr-5',
    'p-1', 'p-2', 'p-3', 'p-4', 'p-5', 'sr-only', 'visually-hidden'];

  var classes = [];
  if (element.classList) {
    var classList = Array.prototype.slice.call(element.classList);
    classList.forEach(function (cls) {
      if (cls && cls.length > 2 && blacklist.indexOf(cls) === -1 &&
        !/^[0-9]/.test(cls) && classes.indexOf(cls) === -1) {
        classes.push(cls);
      }
    });
  }

  return classes.join(',');
}

/**
 * Collect all data-* attributes from an element
 * @param {Element} element - Element to extract data attributes from
 * @returns {string} Concatenated data attribute values
 */
function collectDataAttributes(element) {
  if (!element || !element.attributes) {
    return '';
  }

  var dataAttrs = [];
  for (var i = 0; i < element.attributes.length; i++) {
    var attr = element.attributes[i];
    if (attr.name.indexOf('data-') === 0 && attr.value) {
      // Skip data-xlate-* attributes to avoid circular references
      if (attr.name.indexOf('data-xlate') !== 0) {
        dataAttrs.push(attr.value);
      }
    }
  }

  return dataAttrs.join(',');
}

/**
 * Simple hash function to create consistent 12-character keys
 * @param {string} str - String to hash
 * @returns {string} 12-character hash
 */
function simpleHash(str) {
  var hash = 0;
  for (var i = 0; i < str.length; i++) {
    var char = str.charCodeAt(i);
    hash = ((hash << 5) - hash) + char;
    hash = hash & hash; // Convert to 32-bit integer
  }

  // Convert to base36 and pad/truncate to 12 chars
  var hashStr = Math.abs(hash).toString(36);
  if (hashStr.length < 12) {
    hashStr = (hashStr + '000000000000').substring(0, 12);
  } else {
    hashStr = hashStr.substring(0, 12);
  }

  return hashStr;
}

/**
 * Generate translation key from element content and context
 * @param {Element} element - Element to generate key for
 * @param {string} text - Text content
 * @param {string} type - Type of content (text, placeholder, title, alt)
 * @returns {string} Generated key (12-character hash)
 */
function generateKey(element, text, type) {
  if (!element || !text) {
    return '';
  }

  var parts = [];

  // Get parent context (one level up)
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

  // Get current element context
  var tagName = element.tagName ? element.tagName.toLowerCase() : '';
  if (tagName) {
    parts.push(tagName);
  }

  var classes = collectContextClasses(element);
  if (classes) {
    parts.push(classes);
  }

  var dataAttrs = collectDataAttributes(element);
  if (dataAttrs) {
    parts.push(dataAttrs);
  }

  // Add type if not text
  if (type && type !== 'text') {
    parts.push(type);
  }

  // Add the text content
  parts.push(text);

  // Create composite string and hash it
  var composite = parts.join('.');
  return simpleHash(composite);
}

/**
 * Auto-detect and save translatable string
 * @param {Element} element - Element containing the string
 * @param {string} text - Text content to save
 * @param {string} type - Type of content (text, placeholder, title, alt)
 */
function autoDetectString(element, text, type) {
  if (!autoDetectEnabled || !text) {
    return;
  }

  var fingerprint = createTextFingerprint(text);
  if (!fingerprint.normalized) {
    return;
  }

  var component = detectComponent(element);
  var dedupeKey = component + ':' + fingerprint.normalized + ':' + type;
  if (detectedStrings.has(dedupeKey)) {
    return;
  }
  detectedStrings.add(dedupeKey);

  var key = generateKey(element, text, type);
  if (!key) {
    return;
  }

  Ajax.call([{
    methodname: 'local_xlate_save_key',
    args: {
      component: component,
      key: key,
      source: text,
      lang: (window.__XLATE__ && window.__XLATE__.lang) || M.cfg.language || 'en',
      translation: text
    }
  }])[0].then(function () {
    setKeyAttribute(element, type, key);

    if (window.__XLATE__) {
      if (!window.__XLATE__.map) {
        window.__XLATE__.map = {};
      }
      if (!window.__XLATE__.sourceMap) {
        window.__XLATE__.sourceMap = {};
      }
      window.__XLATE__.map[key] = text;
      window.__XLATE__.sourceMap[fingerprint.normalized] = key;
    }

    return true;
  }).catch(function () {
    detectedStrings.delete(dedupeKey);
  });
}

/**
 * Auto-detect translatable content in an element
 * @param {Element} element - Element to analyze
 */
function autoDetectElement(element) {
  if (!autoDetectEnabled || !element) {
    return;
  }

  if (shouldIgnoreElement(element)) {
    return;
  }

  var currentLang = (window.__XLATE__ && window.__XLATE__.lang) || M.cfg.language || 'en';
  var siteLang = (window.__XLATE__ && window.__XLATE__.siteLang) || 'en';

  if (currentLang !== siteLang) {
    return;
  }

  if (processedElements.has(element)) {
    return;
  }
  processedElements.add(element);

  var textContent = extractCleanText(element);
  if (textContent && isTranslatableText(textContent)) {
    autoDetectString(element, textContent, 'text');
  }

  ATTRIBUTE_TYPES.forEach(function (attr) {
    if (!element.hasAttribute(attr)) {
      return;
    }
    var value = element.getAttribute(attr).trim();
    if (value && isTranslatableText(value)) {
      autoDetectString(element, value, attr);
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
  if (!root) {
    return;
  }

  var stack = [root];
  while (stack.length) {
    var el = stack.pop();
    if (el.nodeType === 1) {
      if (el.hasAttribute && el.hasAttribute('data-xlate-ignore')) {
        continue;
      }

      translateNode(el, map);

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

    if (autoDetectEnabled) {
      setTimeout(function () {
        walk(document.body, map || {});
      }, 1000);

      setTimeout(function () {
        walk(document.body, map || {});
      }, 3000);
    }

    var mo = new MutationObserver(function (muts) {
      muts.forEach(function (mutation) {
        Array.prototype.slice.call(mutation.addedNodes || []).forEach(function (node) {
          if (node.nodeType === 1) {
            walk(node, map || {});
            if (node.querySelectorAll) {
              Array.prototype.forEach.call(node.querySelectorAll('*'), function (child) {
                walk(child, map || {});
              });
            }
          }
        });
      });
    });
    mo.observe(document.body, { childList: true, subtree: true });

    if (typeof window.addEventListener === 'function') {
      document.addEventListener('DOMContentLoaded', function () {
        setTimeout(function () {
          walk(document.body, map || {});
        }, 2000);
      });

      ['focus', 'click', 'scroll'].forEach(function (eventType) {
        document.addEventListener(eventType, function () {
          var now = Date.now();
          if (now - lastProcessTime > processThrottle) {
            lastProcessTime = now;
            setTimeout(function () {
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

/**
 * Initialize the translator with config from Moodle.
 * @param {Object} config Configuration object with lang, version, bundleurl.
 * @returns {void}
 */
function init(config) {
  document.documentElement.classList.add('xlate-loading');

  if (typeof config.autodetect !== 'undefined') {
    setAutoDetect(!(config.autodetect === false || config.autodetect === 'false'));
  }

  var k = 'xlate:' + config.lang + ':' + config.version;

  window.__XLATE__ = {
    lang: config.lang,
    siteLang: config.siteLang,
    map: {},
    sourceMap: {}
  };

  /**
   * Apply bundle data (cached or fresh) to the runtime, then translate if needed.
   * @param {Object} bundleData Translation payload from the server/cache.
   * @param {boolean} cached Indicates whether the payload came from localStorage.
   * @returns {void}
   */
  function processBundle(bundleData, cached) {
    if (!bundleData) {
      return;
    }

    if (bundleData.translations) {
      window.__XLATE__.map = bundleData.translations;
      window.__XLATE__.sourceMap = bundleData.sourceMap || {};
      if (!cached) {
        run(bundleData.translations);
      }
    } else {
      window.__XLATE__.map = bundleData;
      window.__XLATE__.sourceMap = bundleData.sourceMap || {};
      if (!cached) {
        run(bundleData);
      }
    }
  }

  try {
    var cached = localStorage.getItem(k);
    if (cached) {
      processBundle(JSON.parse(cached), true);
    }

    fetch(config.bundleurl, { credentials: 'same-origin' })
      .then(function (response) {
        return response.json();
      })
      .then(function (bundleData) {
        try {
          localStorage.setItem(k, JSON.stringify(bundleData));
        } catch (err) {
          // Ignore storage errors (quota, etc.)
        }

        processBundle(bundleData, false);
        return true;
      })
      .catch(function () {
        if (!cached) {
          run({});
        }
      });
  } catch (err) {
    fetch(config.bundleurl)
      .then(function (response) {
        return response.json();
      })
      .then(function (bundle) {
        processBundle(bundle, false);
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
  autoDetectEnabled = !!enabled;
}

return {
  run: run,
  init: init,
  setAutoDetect: setAutoDetect
};
});
*/
