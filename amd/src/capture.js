// Local/xlate/amd/src/capture.js
define(['jquery', 'core/ajax', 'core/notification', 'core/modal_factory', 'core/modal_events'],
    function ($, Ajax, Notification, ModalFactory, ModalEvents) {

        var captureMode = false;
        var captureOverlay = null;

        /**
         * CSS styles for capture mode overlay and highlighting
         */
        var captureCSS = `
        .xlate-capture-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.3);
            z-index: 10000;
            pointer-events: none;
        }
        .xlate-capture-notice {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #0f6cbf;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            z-index: 10001;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .xlate-highlighted {
            outline: 3px solid #ff6b35 !important;
            outline-offset: 2px !important;
            cursor: crosshair !important;
            position: relative;
        }
        .xlate-capture-tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            z-index: 10002;
            pointer-events: none;
            white-space: nowrap;
        }
    `;

        /**
         * Inject capture mode CSS into the page
         */
        function injectCSS() {
            if (!document.getElementById('xlate-capture-css')) {
                var style = document.createElement('style');
                style.id = 'xlate-capture-css';
                style.textContent = captureCSS;
                document.head.appendChild(style);
            }
        }

        /**
         * Create and show the capture mode overlay
         */
        function showCaptureOverlay() {
            if (captureOverlay) {
                return;
            }

            captureOverlay = $('<div class="xlate-capture-overlay"></div>');
            var notice = $('<div class="xlate-capture-notice">Capture Mode Active - ' +
                'Click any text element to assign a translation key (ESC to exit)</div>');

            $('body').append(captureOverlay).append(notice);

            // Handle ESC key to exit capture mode
            $(document).on('keydown.xlate-capture', function (e) {
                if (e.key === 'Escape') {
                    exitCaptureMode();
                }
            });
        }

        /**
         * Remove capture mode overlay
         */
        function hideCaptureOverlay() {
            if (captureOverlay) {
                captureOverlay.remove();
                $('.xlate-capture-notice').remove();
                captureOverlay = null;
            }
            $(document).off('keydown.xlate-capture');
        }

        /**
         * Check if element is suitable for translation
         * @param {Element} element - The DOM element to check
         * @return {boolean} True if element can be translated
         */
        function isTranslatableElement(element) {
            var $el = $(element);
            var tagName = element.tagName.toLowerCase();

            // Skip if already has xlate attributes
            if ($el.attr('data-xlate') || $el.attr('data-xlate-placeholder') ||
                $el.attr('data-xlate-title') || $el.attr('data-xlate-alt')) {
                return false;
            }

            // Skip if marked to ignore
            if ($el.attr('data-xlate-ignore') || $el.closest('[data-xlate-ignore]').length) {
                return false;
            }

            // Skip script, style, meta tags
            if (['script', 'style', 'meta', 'link', 'noscript'].includes(tagName)) {
                return false;
            }

            // Must have text content or relevant attributes
            var hasText = element.textContent && element.textContent.trim().length > 0;
            var hasPlaceholder = $el.attr('placeholder') && $el.attr('placeholder').trim().length > 0;
            var hasTitle = $el.attr('title') && $el.attr('title').trim().length > 0;
            var hasAlt = $el.attr('alt') && $el.attr('alt').trim().length > 0;

            return hasText || hasPlaceholder || hasTitle || hasAlt;
        }

        /**
         * Generate suggested translation key based on element
         * @param {Element} element - The DOM element to generate key for
         * @return {string} Suggested translation key
         */
        function generateSuggestedKey(element) {
            var $el = $(element);
            var text = element.textContent ? element.textContent.trim() : '';
            var placeholder = $el.attr('placeholder') || '';
            var title = $el.attr('title') || '';
            var alt = $el.attr('alt') || '';

            // Use the most relevant text
            var sourceText = text || placeholder || title || alt;

            // Clean and format as key
            var key = sourceText
                .replace(/[^\w\s]/g, '') // Remove special chars
                .replace(/\s+/g, '.') // Replace spaces with dots
                .replace(/\.+/g, '.') // Collapse multiple dots
                .replace(/^\.+|\.+$/g, ''); // Trim leading/trailing dots

            // Limit length and ensure it's reasonable
            if (key.length > 50) {
                key = key.substring(0, 50).replace(/\.[^.]*$/, '');
            }

            // Add context based on element type
            var tagName = element.tagName.toLowerCase();
            if (placeholder) {
                key = 'Input.' + key;
            } else if (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(tagName)) {
                key = 'Heading.' + key;
            } else if (tagName === 'button') {
                key = 'Button.' + key;
            } else if (tagName === 'a') {
                key = 'Link.' + key;
            } else if (alt) {
                key = 'Image.' + key;
            }

            return key || 'Untitled';
        }

        /**
         * Show key assignment modal
         * @param {Element} element - The DOM element to assign keys to
         */
        function showKeyAssignmentModal(element) {
            var $el = $(element);
            var suggestedKey = generateSuggestedKey(element);

            // Get all possible translatable content
            var textContent = element.textContent ? element.textContent.trim() : '';
            var placeholder = $el.attr('placeholder') || '';
            var title = $el.attr('title') || '';
            var alt = $el.attr('alt') || '';

            var modalBody = buildModalBody(suggestedKey, textContent, placeholder, title, alt);

            ModalFactory.create({
                type: ModalFactory.types.SAVE_CANCEL,
                title: 'Assign Translation Key',
                body: modalBody,
                large: true
            }).then(function (modal) {
                modal.show();

                modal.getRoot().on(ModalEvents.save, function () {
                    saveTranslationKey(element, modal);
                });

                return modal;
            }).catch(function (error) {
                Notification.addNotification({
                    message: 'Error showing modal: ' + error.message,
                    type: 'error'
                });
            });
        }

        /**
         * Build modal body HTML
         * @param {string} suggestedKey - Suggested translation key
         * @param {string} textContent - Element text content
         * @param {string} placeholder - Element placeholder attribute
         * @param {string} title - Element title attribute
         * @param {string} alt - Element alt attribute
         * @return {string} Modal body HTML
         */
        function buildModalBody(suggestedKey, textContent, placeholder, title, alt) {
            var modalBody = `
            <div class="form-group">
                <label for="xlate-key">Translation Key:</label>
                <input type="text" id="xlate-key" class="form-control" value="${suggestedKey}" required>
                <small class="form-text text-muted">Use dot notation (e.g., Dashboard.Title, Button.Save)</small>
            </div>
            <div class="form-group">
                <label for="xlate-component">Component:</label>
                <input type="text" id="xlate-component" class="form-control" value="core" required>
                <small class="form-text text-muted">Component identifier (core, mod_forum, theme_boost, etc.)</small>
            </div>
        `;

            // Add fields for each translatable attribute
            if (textContent) {
                modalBody += `
                <div class="form-group">
                    <label>Text Content:</label>
                    <div class="form-control-static bg-light p-2 border rounded">${textContent}</div>
                    <input type="hidden" id="xlate-text-content" value="${textContent.replace(/"/g, '&quot;')}">
                </div>
            `;
            }

            if (placeholder) {
                modalBody += buildAttributeField('placeholder', suggestedKey + '.Placeholder', placeholder);
            }

            if (title) {
                modalBody += buildAttributeField('title', suggestedKey + '.Title', title);
            }

            if (alt) {
                modalBody += buildAttributeField('alt', suggestedKey + '.Alt', alt);
            }

            return modalBody;
        }

        /**
         * Build form field for attribute
         * @param {string} attr - Attribute name
         * @param {string} suggestedKey - Suggested key for attribute
         * @param {string} content - Attribute content
         * @return {string} Form field HTML
         */
        function buildAttributeField(attr, suggestedKey, content) {
            var capitalizedAttr = attr.charAt(0).toUpperCase() + attr.slice(1);
            return `
            <div class="form-group">
                <label for="xlate-${attr}-key">${capitalizedAttr} Key (optional):</label>
                <input type="text" id="xlate-${attr}-key" class="form-control" value="${suggestedKey}">
                <div class="form-control-static bg-light p-2 border rounded mt-1">${content}</div>
                <input type="hidden" id="xlate-${attr}-content" value="${content.replace(/"/g, '&quot;')}">
            </div>
        `;
        }

        /**
         * Save the translation key assignment
         * @param {Element} element - The DOM element to save keys for
         * @param {Object} modal - The modal instance
         */
        function saveTranslationKey(element, modal) {
            var $modal = modal.getRoot();
            var key = $modal.find('#xlate-key').val().trim();
            var component = $modal.find('#xlate-component').val().trim();

            if (!key || !component) {
                Notification.addNotification({
                    message: 'Key and component are required',
                    type: 'error'
                });
                return;
            }

            var $el = $(element);
            var ajaxRequests = buildTranslationRequests($modal, component, key);

            Ajax.call(ajaxRequests).then(function () {
                applyDataAttributes($el, $modal, key);
                $el.removeClass('xlate-highlighted');

                Notification.addNotification({
                    message: 'Translation key(s) saved successfully',
                    type: 'success'
                });

                modal.hide();

                return true;
            }).catch(function (error) {
                Notification.addNotification({
                    message: 'Error saving translation key: ' + (error.message || 'Unknown error'),
                    type: 'error'
                });
            });
        }

        /**
         * Build AJAX requests for saving translations
         * @param {Object} $modal - jQuery modal element
         * @param {string} component - Component name
         * @param {string} key - Base translation key
         * @return {Array} Array of AJAX request objects
         */
        function buildTranslationRequests($modal, component, key) {
            var ajaxRequests = [];

            // Main text content key
            var textContent = $modal.find('#xlate-text-content').val();
            if (textContent) {
                ajaxRequests.push({
                    methodname: 'local_xlate_save_key',
                    args: {
                        component: component,
                        key: key,
                        source: textContent,
                        lang: M.cfg.language || 'en',
                        translation: textContent
                    }
                });
            }

            // Additional attribute keys
            ['placeholder', 'title', 'alt'].forEach(function (attr) {
                var attrKey = $modal.find('#xlate-' + attr + '-key').val();
                var attrContent = $modal.find('#xlate-' + attr + '-content').val();
                if (attrKey && attrContent) {
                    ajaxRequests.push({
                        methodname: 'local_xlate_save_key',
                        args: {
                            component: component,
                            key: attrKey,
                            source: attrContent,
                            lang: M.cfg.language || 'en',
                            translation: attrContent
                        }
                    });
                }
            });

            return ajaxRequests;
        }

        /**
         * Apply data attributes to element
         * @param {Object} $el - jQuery element
         * @param {Object} $modal - jQuery modal element
         * @param {string} key - Base translation key
         */
        function applyDataAttributes($el, $modal, key) {
            var textContent = $modal.find('#xlate-text-content').val();
            if (textContent) {
                $el.attr('data-xlate', key);
            }

            ['placeholder', 'title', 'alt'].forEach(function (attr) {
                var attrKey = $modal.find('#xlate-' + attr + '-key').val();
                var attrContent = $modal.find('#xlate-' + attr + '-content').val();
                if (attrKey && attrContent) {
                    $el.attr('data-xlate-' + attr, attrKey);
                }
            });
        }

        /**
         * Handle mouse over events in capture mode
         * @param {Event} e - The mouseover event
         */
        function handleMouseOver(e) {
            if (!captureMode) {
                return;
            }

            var element = e.target;
            if (!isTranslatableElement(element)) {
                return;
            }

            // Remove previous highlight
            $('.xlate-highlighted').removeClass('xlate-highlighted');
            $('.xlate-capture-tooltip').remove();

            // Highlight current element
            $(element).addClass('xlate-highlighted');

            // Show tooltip
            var tooltip = $('<div class="xlate-capture-tooltip">Click to assign translation key</div>');
            tooltip.css({
                top: e.pageY + 10,
                left: e.pageX + 10
            });
            $('body').append(tooltip);
        }

        /**
         * Handle click events in capture mode
         * @param {Event} e - The click event
         */
        function handleClick(e) {
            if (!captureMode) {
                return;
            }

            var element = e.target;
            if (!isTranslatableElement(element)) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            showKeyAssignmentModal(element);
        }

        /**
         * Enter capture mode
         */
        function enterCaptureMode() {
            if (captureMode) {
                return;
            }

            captureMode = true;
            injectCSS();
            showCaptureOverlay();

            // Bind event handlers
            $(document).on('mouseover.xlate-capture', handleMouseOver);
            $(document).on('click.xlate-capture', handleClick);

            Notification.addNotification({
                message: 'Capture mode activated. Click on text elements to assign translation keys.',
                type: 'info'
            });
        }

        /**
         * Exit capture mode
         */
        function exitCaptureMode() {
            if (!captureMode) {
                return;
            }

            captureMode = false;
            hideCaptureOverlay();

            // Remove highlights and tooltips
            $('.xlate-highlighted').removeClass('xlate-highlighted');
            $('.xlate-capture-tooltip').remove();

            // Unbind event handlers
            $(document).off('mouseover.xlate-capture');
            $(document).off('click.xlate-capture');

            Notification.addNotification({
                message: 'Capture mode deactivated.',
                type: 'info'
            });
        }

        /**
         * Toggle capture mode
         */
        function toggleCaptureMode() {
            if (captureMode) {
                exitCaptureMode();
            } else {
                enterCaptureMode();
            }
        }

        // Public API
        return {
            enter: enterCaptureMode,
            exit: exitCaptureMode,
            toggle: toggleCaptureMode,
            isActive: function () {
                return captureMode;
            }
        };
    });