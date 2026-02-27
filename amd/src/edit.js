// AMD module providing an inline translation inspector overlay for captured keys.
// Displays a dashed highlight plus controls to jump to Manage UI while browsing.
/* eslint-disable jsdoc/require-jsdoc */
define(['core/ajax'], function (Ajax) {
    var ATTR_KEY_PREFIX = 'data-xlate-key-';
    var ATTRIBUTE_TYPES = ['content', 'placeholder', 'title', 'alt', 'aria-label'];
    var SELECTOR = ATTRIBUTE_TYPES.map(function (attr) {
        return '[' + ATTR_KEY_PREFIX + attr + ']';
    }).join(',');
    var CRITICAL_SERVICE = 'local_xlate_set_critical';
    var STATE = {
        config: {
            enabled: false,
            manageUrl: '',
            courseid: 0,
            strings: {},
            inlineToggle: false
        },
        active: false,
        currentElement: null,
        currentAttribute: null,
        attributes: [],
        ready: false,
        hasExternalToggle: false,
        toggleRenderer: null,
        pendingCritical: false,
        hideTimeout: null
    };
    var NODES = {
        toggle: null,
        highlight: null,
        toolbar: null,
        callout: null
    };
    var STICKY_BASE_PADDING = 16;
    var STICKY_OVERLAY_PADDING = 8;
    var INSPECTOR_STORAGE_KEY = 'local_xlate_inspector_state';

    function readInspectorPreference() {
        if (typeof window === 'undefined') {
            return null;
        }
        try {
            var storage = window.localStorage;
            if (!storage) {
                return null;
            }
            var value = storage.getItem(INSPECTOR_STORAGE_KEY);
            if (value === 'on') {
                return true;
            }
            if (value === 'off') {
                return false;
            }
        } catch (e) {
            return null;
        }
        return null;
    }

    function persistInspectorPreference(active) {
        if (typeof window === 'undefined') {
            return;
        }
        try {
            var storage = window.localStorage;
            if (!storage) {
                return;
            }
            storage.setItem(INSPECTOR_STORAGE_KEY, active ? 'on' : 'off');
        } catch (e) {
            // Ignore storage failures
        }
    }

    function restoreInspectorPreference() {
        if (STATE.active) {
            return;
        }
        var stored = readInspectorPreference();
        if (stored === true) {
            activateInspector({ silent: true });
        }
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
            return;
        }
        fn();
    }

    function init(userConfig) {
        if (typeof window === 'undefined' || typeof document === 'undefined') {
            return;
        }
        STATE.config = merge(STATE.config, userConfig || {});
        if (!STATE.config.enabled) {
            return;
        }
        ready(function () {
            injectStyles();
            if (!STATE.hasExternalToggle && !STATE.config.inlineToggle) {
                buildToggle();
            }
            STATE.ready = true;
            updateToggleVisuals();
            restoreInspectorPreference();
        });
    }

    function merge(base, overrides) {
        var result = {};
        var key;
        for (key in base) {
            if (Object.prototype.hasOwnProperty.call(base, key)) {
                if (typeof base[key] === 'object' && base[key] !== null && !Array.isArray(base[key])) {
                    result[key] = merge(base[key], {});
                } else {
                    result[key] = base[key];
                }
            }
        }
        for (key in overrides) {
            if (!Object.prototype.hasOwnProperty.call(overrides, key)) {
                continue;
            }
            var val = overrides[key];
            if (val && typeof val === 'object' && !Array.isArray(val)) {
                result[key] = merge(result[key] || {}, val);
            } else {
                result[key] = val;
            }
        }
        return result;
    }

    function injectStyles() {
        if (document.querySelector('style[data-xlate-style="inspector"]')) {
            return;
        }
        var style = document.createElement('style');
        style.setAttribute('data-xlate-style', 'inspector');
        style.textContent = '' +
            '.xlate-inspector-toggle{' +
            'position:fixed;' +
            'bottom:1.5rem;' +
            'right:1.5rem;' +
            'z-index:2147482000;' +
            'padding:0.65rem 1.1rem;' +
            'border-radius:999px;' +
            'box-shadow:0 10px 30px rgba(13,110,253,.25);' +
            '}' +
            '.xlate-inspector-toggle.is-active{' +
            'background:#0b5ed7;' +
            '}' +
            '.xlate-inspector-highlight{' +
            'position:fixed;' +
            'border:2px dashed #0d6efd;' +
            'border-radius:0.75rem;' +
            'pointer-events:none;' +
            'z-index:2147481000;' +
            'box-shadow:0 0 0 2px rgba(13,110,253,.2);' +
            'display:none;' +
            '}' +
            '.xlate-inspector-highlight.is-visible{' +
            'display:block;' +
            '}' +
            '.xlate-inspector-toolbar,' +
            '.xlate-inspector-callout{' +
            'position:fixed;' +
            'z-index:2147481200;' +
            'background:#0d1117;' +
            'color:#f0f6ff;' +
            'border-radius:0.6rem;' +
            'padding:0.75rem;' +
            'box-shadow:0 12px 45px rgba(15,23,42,.35);' +
            'display:none;' +
            'max-width:420px;' +
            'font-size:0.875rem;' +
            '}' +
            '.xlate-inspector-toolbar{' +
            'flex-wrap:wrap;' +
            'gap:0.5rem;' +
            'align-items:center;' +
            '}' +
            '.xlate-inspector-toolbar.is-visible,' +
            '.xlate-inspector-callout.is-visible{' +
            'display:flex;' +
            'flex-direction:column;' +
            '}' +
            '.xlate-inspector-attributes{' +
            'display:flex;' +
            'gap:0.25rem;' +
            'flex-wrap:wrap;' +
            '}' +
            '.xlate-inspector-attr{' +
            'background:rgba(255,255,255,.08);' +
            'border:none;' +
            'color:inherit;' +
            'padding:0.2rem 0.75rem;' +
            'border-radius:999px;' +
            'font-size:0.75rem;' +
            'cursor:pointer;' +
            '}' +
            '.xlate-inspector-attr.is-active{' +
            'background:#1f6feb;' +
            '}' +
            '.xlate-inspector-meta{' +
            'display:flex;' +
            'gap:0.4rem;' +
            'flex-wrap:wrap;' +
            'align-items:center;' +
            'margin-top:0.35rem;' +
            '}' +
            '.xlate-inspector-key{' +
            'font-family:monospace;' +
            'background:rgba(255,255,255,.08);' +
            'padding:0.1rem 0.4rem;' +
            'border-radius:0.35rem;' +
            '}' +
            '.xlate-inspector-action{' +
            'background:rgba(255,255,255,.08);' +
            'border:none;' +
            'color:inherit;' +
            'padding:0.35rem 0.75rem;' +
            'border-radius:0.45rem;' +
            'font-size:0.75rem;' +
            'cursor:pointer;' +
            'text-decoration:none;' +
            '}' +
            '.xlate-inspector-action:hover{' +
            'background:rgba(255,255,255,.18);' +
            '}' +
            '.xlate-inspector-critical{' +
            'display:flex;' +
            'gap:0.35rem;' +
            'flex-wrap:wrap;' +
            'align-items:center;' +
            '}' +
            '.xlate-inspector-critical-badge{' +
            'font-size:0.7rem;' +
            'text-transform:uppercase;' +
            'letter-spacing:0.05em;' +
            'padding:0.15rem 0.6rem;' +
            'border-radius:999px;' +
            'background:rgba(220,53,69,.15);' +
            'color:#fca5a5;' +
            '}' +
            '.xlate-inspector-critical-badge.is-inactive{' +
            'background:rgba(255,255,255,.08);' +
            'color:#e2e8f0;' +
            '}' +
            '.xlate-inspector-critical-toggle.is-loading{' +
            'opacity:0.6;' +
            'pointer-events:none;' +
            '}' +
            '.xlate-inspector-callout{' +
            'font-size:0.85rem;' +
            'gap:0.35rem;' +
            '}' +
            '.xlate-inspector-callout-label{' +
            'font-weight:600;' +
            'text-transform:uppercase;' +
            'letter-spacing:0.04em;' +
            'font-size:0.7rem;' +
            'color:#93c5fd;' +
            '}' +
            '.xlate-inspector-callout-text{' +
            'max-height:none;' +
            'overflow:visible;' +
            'white-space:pre-wrap;' +
            '}' +
            '.xlate-inspector-toast{' +
            'position:fixed;' +
            'bottom:4.75rem;' +
            'right:1.5rem;' +
            'background:#0d6efd;' +
            'color:#fff;' +
            'padding:0.55rem 1rem;' +
            'border-radius:0.45rem;' +
            'box-shadow:0 10px 30px rgba(13,110,253,.35);' +
            'opacity:0;' +
            'pointer-events:none;' +
            'transition:opacity .2s ease;' +
            'z-index:2147483000;' +
            '}' +
            '.xlate-inspector-toast.is-visible{' +
            'opacity:1;' +
            '}' +
            '.xlate-inspector-toggle:focus-visible,' +
            '.xlate-inspector-attr:focus-visible,' +
            '.xlate-inspector-action:focus-visible{' +
            'outline:2px solid #facc15;' +
            'outline-offset:2px;' +
            '}' +
            '.xlate-inspector-empty{' +
            'opacity:0.6;' +
            '}';
        document.head.appendChild(style);
    }

    function buildToggle() {
        if (STATE.hasExternalToggle || NODES.toggle) {
            return;
        }
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-primary xlate-inspector-toggle';
        button.textContent = STATE.config.strings.toggleInactive || 'Enable inspector';
        button.setAttribute('aria-pressed', 'false');
        button.setAttribute('aria-label', STATE.config.strings.toggleLabel || 'Translation inspector');
        button.title = STATE.config.strings.toggleHint || '';
        button.setAttribute('data-xlate-inspector-toggle', 'true');
        attachToggleButton(button);
        STATE.toggleRenderer = function (active) {
            var activeText = STATE.config.strings.toggleActive || 'Inspector enabled';
            var inactiveText = STATE.config.strings.toggleInactive || 'Enable inspector';
            button.textContent = active ? activeText : inactiveText;
        };
        NODES.toggle = button;
        document.body.appendChild(button);
        updateToggleVisuals();
    }

    function attachToggleButton(button) {
        if (!button) {
            return;
        }
        button.addEventListener('click', handleToggleClick);
    }

    function detachToggleButton(button) {
        if (!button) {
            return;
        }
        button.removeEventListener('click', handleToggleClick);
    }

    function handleToggleClick(event) {
        event.preventDefault();
        if (!STATE.ready || !STATE.config.enabled) {
            return;
        }
        if (STATE.active) {
            deactivateInspector();
        } else {
            activateInspector();
        }
    }

    function activateInspector(options) {
        if (STATE.active) {
            return;
        }
        STATE.active = true;
        document.addEventListener('pointermove', handlePointerMove, true);
        document.addEventListener('pointerleave', handlePointerLeave, true);
        document.addEventListener('pointerover', handlePointerOver, true);
        window.addEventListener('scroll', handleViewportChange, true);
        window.addEventListener('resize', handleViewportChange, true);
        document.addEventListener('keydown', handleKeydown, true);
        document.documentElement.classList.add('xlate-inspector-active');
        updateToggleVisuals();
        persistInspectorPreference(true);
        if (!options || !options.silent) {
            showToast(STATE.config.strings.toggleActive || 'Inspector enabled');
        }
    }

    function deactivateInspector() {
        if (!STATE.active) {
            return;
        }
        STATE.active = false;
        document.removeEventListener('pointermove', handlePointerMove, true);
        document.removeEventListener('pointerleave', handlePointerLeave, true);
        document.removeEventListener('pointerover', handlePointerOver, true);
        window.removeEventListener('scroll', handleViewportChange, true);
        window.removeEventListener('resize', handleViewportChange, true);
        document.removeEventListener('keydown', handleKeydown, true);
        document.documentElement.classList.remove('xlate-inspector-active');
        updateToggleVisuals();
        persistInspectorPreference(false);
        clearCurrent();
    }

    function updateToggleVisuals() {
        if (!NODES.toggle) {
            return;
        }
        var button = NODES.toggle;
        if (STATE.active) {
            button.classList.add('is-active');
            button.setAttribute('aria-pressed', 'true');
        } else {
            button.classList.remove('is-active');
            button.setAttribute('aria-pressed', 'false');
        }
        if (button.classList) {
            if (!STATE.ready) {
                button.classList.add('is-disabled');
            } else {
                button.classList.remove('is-disabled');
            }
        }
        if (typeof button.disabled !== 'undefined') {
            button.disabled = !STATE.ready;
        }
        button.setAttribute('aria-disabled', STATE.ready ? 'false' : 'true');
        if (STATE.toggleRenderer) {
            STATE.toggleRenderer(STATE.active);
        } else {
            var activeText = STATE.config.strings.toggleActive || 'Inspector enabled';
            var inactiveText = STATE.config.strings.toggleInactive || 'Enable inspector';
            button.textContent = STATE.active ? activeText : inactiveText;
        }
    }

    function handlePointerMove(event) {
        if (!STATE.active) {
            return;
        }
        if (isOverlayNode(event.target)) {
            cancelPendingClear();
            return;
        }
        cancelPendingClear();
        var candidate = event.target.closest ? event.target.closest(SELECTOR) : null;
        if (!candidate) {
            if (isWithinStickyZone(event)) {
                return;
            }
            clearCurrent();
            return;
        }
        if (STATE.currentElement === candidate) {
            return;
        }
        setCurrentElement(candidate);
    }

    function handlePointerLeave(event) {
        if (!STATE.active) {
            return;
        }
        var source = event.target && event.target.closest ? event.target.closest(SELECTOR) : null;
        if (!source || source !== STATE.currentElement) {
            return;
        }
        var nextTarget = event.relatedTarget || null;
        if (isOverlayNode(nextTarget)) {
            return;
        }
        if (nextTarget && STATE.currentElement && STATE.currentElement.contains(nextTarget)) {
            return;
        }
        scheduleClear();
    }

    function handlePointerOver(event) {
        if (!STATE.active) {
            return;
        }
        var target = event.target;
        if (isOverlayNode(target)) {
            cancelPendingClear();
            return;
        }
        cancelPendingClear();
        var candidate = target && target.closest ? target.closest(SELECTOR) : null;
        if (candidate === STATE.currentElement) {
            return;
        }
        if (!candidate) {
            if (isWithinStickyZone(event)) {
                return;
            }
            clearCurrent();
            return;
        }
        setCurrentElement(candidate);
    }

    function handleViewportChange() {
        if (!STATE.active || !STATE.currentElement) {
            return;
        }
        refreshOverlay();
    }

    function handleKeydown(event) {
        if (!STATE.active) {
            return;
        }
        if (event.key === 'Escape' || event.key === 'Esc') {
            event.preventDefault();
            deactivateInspector();
        }
    }

    function isOverlayNode(node) {
        if (!node) {
            return false;
        }
        if (node === NODES.toolbar || node === NODES.callout) {
            return true;
        }
        if (!node.closest) {
            return false;
        }
        return !!(
            node.closest('.xlate-inspector-toolbar') ||
            node.closest('.xlate-inspector-callout')
        );
    }

    function isWithinStickyZone(event) {
        if (!STATE.currentElement || !event) {
            return false;
        }
        var rect = STATE.currentElement.getBoundingClientRect();
        var zone = {
            top: rect.top - STICKY_BASE_PADDING,
            bottom: rect.bottom + STICKY_BASE_PADDING,
            left: rect.left - STICKY_BASE_PADDING,
            right: rect.right + STICKY_BASE_PADDING
        };

        expandStickyZone(zone, getVisibleRect(NODES.toolbar), STICKY_OVERLAY_PADDING);
        expandStickyZone(zone, getVisibleRect(NODES.callout), STICKY_OVERLAY_PADDING);

        var pointerX = typeof event.clientX === 'number'
            ? event.clientX
            : (typeof event.pageX === 'number' ? event.pageX : 0);
        var pointerY = typeof event.clientY === 'number'
            ? event.clientY
            : (typeof event.pageY === 'number' ? event.pageY : 0);

        return (
            pointerX >= zone.left &&
            pointerX <= zone.right &&
            pointerY >= zone.top &&
            pointerY <= zone.bottom
        );
    }

    function expandStickyZone(zone, rect, padding) {
        if (!zone || !rect) {
            return;
        }
        var buffer = typeof padding === 'number' ? padding : 0;
        zone.top = Math.min(zone.top, rect.top - buffer);
        zone.bottom = Math.max(zone.bottom, rect.bottom + buffer);
        zone.left = Math.min(zone.left, rect.left - buffer);
        zone.right = Math.max(zone.right, rect.right + buffer);
    }

    function getVisibleRect(node) {
        if (!node || !node.classList || !node.classList.contains('is-visible')) {
            return null;
        }
        return node.getBoundingClientRect();
    }

    function setCurrentElement(element) {
        STATE.currentElement = element;
        STATE.attributes = collectAttributes(element);
        if (!STATE.attributes.length) {
            clearCurrent();
            return;
        }
        if (!STATE.currentAttribute) {
            STATE.currentAttribute = STATE.attributes[0].type;
        }
        if (!attributeExists(STATE.currentAttribute)) {
            STATE.currentAttribute = STATE.attributes[0].type;
        }
        refreshOverlay();
    }

    function attributeExists(type) {
        for (var i = 0; i < STATE.attributes.length; i++) {
            if (STATE.attributes[i].type === type) {
                return true;
            }
        }
        return false;
    }

    function collectAttributes(element) {
        var attrs = [];
        for (var i = 0; i < ATTRIBUTE_TYPES.length; i++) {
            var attrType = ATTRIBUTE_TYPES[i];
            var keyAttr = ATTR_KEY_PREFIX + attrType;
            var keyValue = element.getAttribute(keyAttr);
            if (!keyValue) {
                continue;
            }
            var preview = extractSourcePreview(element, attrType, keyValue);
            if (!preview) {
                preview = extractValue(element, attrType);
            }
            attrs.push({
                type: attrType,
                key: keyValue,
                preview: preview
            });
        }
        return attrs;
    }

    function extractValue(element, attrType) {
        var value = '';
        if (attrType === 'content') {
            value = (element.innerText || element.textContent || '').trim();
        } else {
            value = (element.getAttribute(attrType) || '').trim();
        }
        return value;
    }

    function extractSourcePreview(element, attrType, keyValue) {
        var attrName = 'data-xlate-original-' + attrType;
        var value = '';
        if (element && element.hasAttribute(attrName)) {
            value = element.getAttribute(attrName) || '';
        }
        if (!value && keyValue && window.__XLATE__ && window.__XLATE__.sourceStrings) {
            if (Object.prototype.hasOwnProperty.call(window.__XLATE__.sourceStrings, keyValue)) {
                value = window.__XLATE__.sourceStrings[keyValue] || '';
            }
        }
        if (!value) {
            return '';
        }
        if (attrType === 'content') {
            value = stripHtml(value);
        }
        value = value.trim();
        return value;
    }

    function stripHtml(value) {
        if (!value || value.indexOf('<') === -1) {
            return value || '';
        }
        var div = document.createElement('div');
        div.innerHTML = value;
        return (div.textContent || div.innerText || '').trim();
    }

    function getCriticalMap() {
        if (typeof window === 'undefined') {
            return {};
        }
        if (!window.__XLATE__) {
            window.__XLATE__ = {};
        }
        if (!window.__XLATE__.criticalMap || typeof window.__XLATE__.criticalMap !== 'object') {
            window.__XLATE__.criticalMap = {};
        }
        return window.__XLATE__.criticalMap;
    }

    function getKeyIdMap() {
        if (typeof window === 'undefined') {
            return {};
        }
        if (!window.__XLATE__) {
            window.__XLATE__ = {};
        }
        if (!window.__XLATE__.keyIdMap || typeof window.__XLATE__.keyIdMap !== 'object') {
            window.__XLATE__.keyIdMap = {};
        }
        return window.__XLATE__.keyIdMap;
    }

    function getKeyId(key) {
        if (!key) {
            return 0;
        }
        var map = getKeyIdMap();
        if (!map || typeof map !== 'object') {
            return 0;
        }
        var value = map[key];
        if (!value) {
            return 0;
        }
        return parseInt(value, 10) || 0;
    }

    function isKeyCritical(key) {
        if (!key) {
            return false;
        }
        var map = getCriticalMap();
        if (!map) {
            return false;
        }
        if (Object.prototype.hasOwnProperty.call(map, key)) {
            return !!map[key];
        }
        return false;
    }

    function setCriticalMapValue(key, enabled) {
        if (!key) {
            return;
        }
        var map = getCriticalMap();
        map[key] = enabled ? 1 : 0;
        if (typeof window !== 'undefined' && window.__XLATE__) {
            window.__XLATE__.criticalMap = map;
        }
    }

    function refreshOverlay() {
        if (!STATE.currentElement) {
            clearCurrent();
            return;
        }
        var rect = STATE.currentElement.getBoundingClientRect();
        var highlight = ensureNode('highlight');
        highlight.style.left = rect.left + 'px';
        highlight.style.top = rect.top + 'px';
        highlight.style.width = Math.max(rect.width, 1) + 'px';
        highlight.style.height = Math.max(rect.height, 1) + 'px';
        highlight.classList.add('is-visible');
        renderToolbar(rect);
        renderCallout(rect);
    }

    function ensureNode(key) {
        if (NODES[key]) {
            return NODES[key];
        }
        var node;
        if (key === 'highlight') {
            node = document.createElement('div');
            node.className = 'xlate-inspector-highlight';
        } else if (key === 'toolbar') {
            node = document.createElement('div');
            node.className = 'xlate-inspector-toolbar';
        } else if (key === 'callout') {
            node = document.createElement('div');
            node.className = 'xlate-inspector-callout';
        }
        document.body.appendChild(node);
        NODES[key] = node;
        return node;
    }

    function renderToolbar(rect) {
        var toolbar = ensureNode('toolbar');
        toolbar.innerHTML = '';
        var attributes = document.createElement('div');
        attributes.className = 'xlate-inspector-attributes';
        STATE.attributes.forEach(function (attr) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'xlate-inspector-attr';
            if (attr.type === STATE.currentAttribute) {
                btn.classList.add('is-active');
            }
            btn.textContent = getAttributeLabel(attr.type);
            btn.dataset.xlateAttr = attr.type;
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                STATE.currentAttribute = attr.type;
                refreshOverlay();
            });
            attributes.appendChild(btn);
        });
        toolbar.appendChild(attributes);

        var meta = document.createElement('div');
        meta.className = 'xlate-inspector-meta';
        var current = getCurrentAttribute();
        var keyLabel = document.createElement('span');
        keyLabel.className = 'xlate-inspector-key';
        keyLabel.textContent = current ? current.key : STATE.config.strings.noKey || '';
        meta.appendChild(keyLabel);

        var copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'xlate-inspector-action';
        copyBtn.textContent = STATE.config.strings.copyKey || 'Copy key';
        copyBtn.addEventListener('click', function (event) {
            event.preventDefault();
            if (current && current.key) {
                copyToClipboard(current.key);
            }
        });
        meta.appendChild(copyBtn);

        var manageLink = document.createElement('a');
        manageLink.className = 'xlate-inspector-action';
        manageLink.textContent = STATE.config.strings.openManage || 'Open manage UI';
        manageLink.href = buildManageUrl(current ? current.key : '');
        manageLink.target = '_blank';
        manageLink.rel = 'noopener noreferrer';
        meta.appendChild(manageLink);

        if (current && current.key) {
            var keyValue = current.key;
            var currentlyCritical = isKeyCritical(keyValue);
            var badge = document.createElement('span');
            badge.className = 'xlate-inspector-critical-badge';
            if (!currentlyCritical) {
                badge.classList.add('is-inactive');
            }
            badge.textContent = STATE.config.strings.criticalBadge || 'Critical';

            var criticalWrap = document.createElement('div');
            criticalWrap.className = 'xlate-inspector-critical';
            criticalWrap.appendChild(badge);

            var desiredState = !currentlyCritical;
            var criticalBtn = document.createElement('button');
            criticalBtn.type = 'button';
            criticalBtn.className = 'xlate-inspector-action xlate-inspector-critical-toggle';
            criticalBtn.textContent = desiredState
                ? (STATE.config.strings.markCritical || 'Mark critical')
                : (STATE.config.strings.unmarkCritical || 'Clear critical');
            criticalBtn.addEventListener('click', function (event) {
                event.preventDefault();
                toggleCriticalState(keyValue, desiredState, event.currentTarget);
            });
            criticalWrap.appendChild(criticalBtn);
            meta.appendChild(criticalWrap);
        }
        toolbar.appendChild(meta);

        toolbar.classList.add('is-visible');
        var top = rect.top - toolbar.offsetHeight - 12;
        if (top < 8) {
            top = rect.bottom + 12;
        }
        var left = rect.left;
        var maxLeft = window.innerWidth - toolbar.offsetWidth - 12;
        if (left > maxLeft) {
            left = Math.max(maxLeft, 8);
        }
        if (left < 8) {
            left = 8;
        }
        toolbar.style.left = left + 'px';
        toolbar.style.top = top + 'px';
    }

    function renderCallout(rect) {
        var callout = ensureNode('callout');
        callout.innerHTML = '';
        var current = getCurrentAttribute();
        if (!current) {
            callout.classList.remove('is-visible');
            return;
        }
        var label = document.createElement('div');
        label.className = 'xlate-inspector-callout-label';
        label.textContent = getAttributeLabel(current.type);
        callout.appendChild(label);
        var text = document.createElement('div');
        text.className = 'xlate-inspector-callout-text';
        var preview = current.preview || STATE.config.strings.emptyValue || 'No value captured';
        if (!current.preview) {
            text.classList.add('xlate-inspector-empty');
        }
        text.textContent = preview;
        callout.appendChild(text);
        callout.classList.add('is-visible');
        var top = rect.bottom + 12;
        var left = rect.left;
        var maxLeft = window.innerWidth - callout.offsetWidth - 12;
        if (left > maxLeft) {
            left = Math.max(maxLeft, 8);
        }
        if (left < 8) {
            left = 8;
        }
        callout.style.left = left + 'px';
        callout.style.top = top + 'px';
    }

    function getCurrentAttribute() {
        for (var i = 0; i < STATE.attributes.length; i++) {
            if (STATE.attributes[i].type === STATE.currentAttribute) {
                return STATE.attributes[i];
            }
        }
        return STATE.attributes[0] || null;
    }

    function getAttributeLabel(type) {
        if (!STATE.config.strings) {
            return type;
        }
        if (type === 'content') {
            return STATE.config.strings.attributeContent || 'Text';
        }
        if (type === 'placeholder') {
            return STATE.config.strings.attributePlaceholder || 'Placeholder';
        }
        if (type === 'title') {
            return STATE.config.strings.attributeTitle || 'Title';
        }
        if (type === 'alt') {
            return STATE.config.strings.attributeAlt || 'Alt text';
        }
        if (type === 'aria-label') {
            return STATE.config.strings.attributeAria || 'Aria label';
        }
        return type;
    }

    function buildManageUrl(key) {
        var base = STATE.config.manageUrl || '/local/xlate/manage.php';
        var resolved;
        try {
            resolved = new URL(base, window.location.origin);
        } catch (e) {
            resolved = document.createElement('a');
            resolved.href = base;
            return resolved.href;
        }
        if (STATE.config.courseid) {
            resolved.searchParams.set('courseid', STATE.config.courseid);
        }
        if (key) {
            resolved.searchParams.set('search', key);
        }
        return resolved.toString();
    }

    function getCourseId() {
        if (STATE.config && STATE.config.courseid) {
            return STATE.config.courseid;
        }
        if (typeof window !== 'undefined' && typeof window.XLATE_COURSEID !== 'undefined') {
            return parseInt(window.XLATE_COURSEID, 10) || 0;
        }
        return 0;
    }

    function toggleCriticalState(key, shouldBeCritical, button) {
        if (!key || !Ajax || typeof Ajax.call !== 'function') {
            showToast(STATE.config.strings.criticalError || 'Unable to update critical flag');
            return;
        }
        if (STATE.pendingCritical) {
            return;
        }
        STATE.pendingCritical = true;
        if (button) {
            button.classList.add('is-loading');
            button.disabled = true;
        }
        var keyId = getKeyId(key);
        var request = Ajax.call([{
            methodname: CRITICAL_SERVICE,
            args: {
                key: key,
                critical: shouldBeCritical ? 1 : 0,
                courseid: getCourseId(),
                keyid: keyId
            }
        }])[0];

        var reset = function () {
            STATE.pendingCritical = false;
            if (button) {
                button.classList.remove('is-loading');
                button.disabled = false;
            }
        };

        request.done(function (response) {
            reset();
            if (!response || !response.success) {
                showToast(STATE.config.strings.criticalError || 'Unable to update critical flag');
                return;
            }
            setCriticalMapValue(key, !!shouldBeCritical);
            var message = shouldBeCritical
                ? (STATE.config.strings.criticalMarked || 'Marked as critical')
                : (STATE.config.strings.criticalCleared || 'Critical flag cleared');
            showToast(message);
            refreshOverlay();
        }).fail(function () {
            reset();
            showToast(STATE.config.strings.criticalError || 'Unable to update critical flag');
        });
    }

    function copyToClipboard(value) {
        if (!value) {
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                showToast(STATE.config.strings.copied || 'Key copied');
            }).catch(function () {
                fallbackCopy(value);
            });
            return;
        }
        fallbackCopy(value);
    }

    function fallbackCopy(value) {
        var temp = document.createElement('textarea');
        temp.value = value;
        temp.setAttribute('readonly', 'readonly');
        temp.style.position = 'absolute';
        temp.style.left = '-9999px';
        document.body.appendChild(temp);
        temp.select();
        try {
            document.execCommand('copy');
            showToast(STATE.config.strings.copied || 'Key copied');
        } catch (e) {
            console.warn('[local_xlate] Unable to copy key', e); // eslint-disable-line no-console
        }
        document.body.removeChild(temp);
    }

    function showToast(message) {
        var toast = document.querySelector('.xlate-inspector-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'xlate-inspector-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(showToast.timeout);
        showToast.timeout = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 2000);
    }

    function clearCurrent() {
        cancelPendingClear();
        STATE.currentElement = null;
        STATE.attributes = [];
        STATE.currentAttribute = null;
        hideNode('highlight');
        hideNode('toolbar');
        hideNode('callout');
    }

    function scheduleClear() {
        cancelPendingClear();
        if (typeof window === 'undefined') {
            clearCurrent();
            return;
        }
        STATE.hideTimeout = window.setTimeout(function () {
            STATE.hideTimeout = null;
            clearCurrent();
        }, 1500);
    }

    function cancelPendingClear() {
        if (STATE.hideTimeout && typeof window !== 'undefined') {
            window.clearTimeout(STATE.hideTimeout);
            STATE.hideTimeout = null;
        }
    }

    function hideNode(key) {
        var node = NODES[key];
        if (!node) {
            return;
        }
        node.classList.remove('is-visible');
    }

    function registerToggle(button, options) {
        if (!button) {
            return;
        }
        STATE.hasExternalToggle = true;
        if (NODES.toggle && NODES.toggle !== button) {
            if (NODES.toggle.parentNode) {
                NODES.toggle.parentNode.removeChild(NODES.toggle);
            }
            detachToggleButton(NODES.toggle);
        }
        NODES.toggle = button;
        button.setAttribute('data-xlate-inspector-toggle', 'true');
        if (options && typeof options.render === 'function') {
            STATE.toggleRenderer = options.render;
        } else {
            STATE.toggleRenderer = null;
        }
        attachToggleButton(button);
        updateToggleVisuals();
    }

    return {
        init: init,
        registerToggle: registerToggle
    };
});
