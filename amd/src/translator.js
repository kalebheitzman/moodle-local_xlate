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

    // Skip admin paths entirely - these should use Moodle language strings
    var currentPath = window.location.pathname;
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
      if (currentPath.includes(adminPaths[p])) {
        return true;
      }
    }

    // Skip admin/navigation elements that shouldn't be translated
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

    for (var i = 0; i < adminSelectors.length; i++) {
      if (element.closest(adminSelectors[i])) {
        return true;
      }
    }

    // Skip if element or parent has admin-related classes
    var adminClasses = [
      'editing', 'editor', 'admin-only', 'teacher-only',
      'form-control', 'btn-secondary', 'btn-outline',
      'text-muted', 'small', 'sr-only', 'accesshide'
    ];

    var elementClasses = (element.className || '').split(' ');
    for (var j = 0; j < adminClasses.length; j++) {
      if (elementClasses.includes(adminClasses[j])) {
        return true;
      }
    }

    // Skip elements with very short or common admin text
    var text = element.textContent.trim();
    if (text.length < 3) {
      return true;
    }

    var adminWords = [
      'edit', 'delete', 'save', 'cancel', 'ok', 'yes', 'no',
      'settings', 'config', 'admin', 'manage', 'update',
      'hide', 'show', 'move', 'copy', 'options', 'actions'
    ];

    var lowerText = text.toLowerCase();
    if (adminWords.includes(lowerText)) {
      return true;
    }

    return false;
  }  /**
   * Check if text content is worth translating
   * @param {string} text - Text to analyze
   * @returns {boolean} True if should be translated
   */
  function isTranslatableText(text) {
    if (!text || text.length < 3) {
      return false;
    }

    // Skip if mostly numbers, symbols, or code
    var alphaCount = (text.match(/[a-zA-Z]/g) || []).length;
    if (alphaCount < text.length * 0.5) {
      return false;
    }

    // Skip common non-translatable patterns
    var skipPatterns = [
      /^\d+[\s\d]*$/, // Just numbers
      /^[A-Z]{2,}$/, // All caps abbreviations
      /^[a-z_]+$/, // Variable names
      /^\w+\.\w+/, // File extensions or dot notation
      /^https?:/, // URLs
      /^\/\w+/, // Paths
      /^\{[^}]+\}$/, // Template variables
      /^\[[^\]]+\]$/, // Bracketed content
      /^<[^>]+>$/ // HTML tags
    ];

    for (var i = 0; i < skipPatterns.length; i++) {
      if (skipPatterns[i].test(text.trim())) {
        return false;
      }
    }

    // Skip single common words that don't need translation
    var commonWords = ['ok', 'id', 'url', 'api', 'css', 'js', 'html', 'php'];
    if (commonWords.includes(text.toLowerCase().trim())) {
      return false;
    }

    return true;
  }

  /**
   * Extract clean text from element, handling HTML
   * @param {Element} element - Element to extract text from
   * @returns {string} Clean text content
   */
  function extractCleanText(element) {
    var text = '';

    // If element has only text nodes and simple formatting
    if (element.children.length === 0) {
      text = element.textContent.trim();
    } else {
      // Check if element has only simple formatting (b, i, em, strong, span)
      var simpleFormatting = true;
      var children = element.children;

      for (var i = 0; i < children.length; i++) {
        var tagName = children[i].tagName.toLowerCase();
        if (!['b', 'i', 'em', 'strong', 'span', 'small'].includes(tagName)) {
          simpleFormatting = false;
          break;
        }
      }

      if (simpleFormatting) {
        text = element.textContent.trim();
      }
    }

    // Clean up the text
    return text
      .replace(/\s+/g, ' ') // Normalize spaces
      .replace(/^\s+|\s+$/g, '') // Trim
      .replace(/['"]/g, "'"); // Normalize quotes
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

    // Check text content with smart extraction
    var textContent = extractCleanText(element);
    if (textContent && isTranslatableText(textContent)) {
      autoDetectString(element, textContent, 'text');
    }

    // Check attributes
    if (element.hasAttribute('placeholder')) {
      var placeholder = element.getAttribute('placeholder').trim();
      if (placeholder && isTranslatableText(placeholder)) {
        autoDetectString(element, placeholder, 'placeholder');
      }
    }

    if (element.hasAttribute('title')) {
      var title = element.getAttribute('title').trim();
      if (title && isTranslatableText(title)) {
        autoDetectString(element, title, 'title');
      }
    }

    if (element.hasAttribute('alt')) {
      var alt = element.getAttribute('alt').trim();
      if (alt && isTranslatableText(alt)) {
        autoDetectString(element, alt, 'alt');
      }
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