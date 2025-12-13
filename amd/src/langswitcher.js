// Local Xlate floating language switcher.
// Renders a bottom-left launcher listing available languages.

define([], function () {
    var container = null;
    var stylesInjected = false;
    var outsideClickHandler = null;
    var keydownHandler = null;

    /**
     * Persist debug information for troubleshooting the switcher state.
     * @param {Object} state - Key/value pairs describing current status.
     * @returns {void}
     */
    function setDebugState(state) {
        if (typeof window === 'undefined') {
            return;
        }
        var current = window.XLATE_LANG_SWITCHER_STATE || {};
        window.XLATE_LANG_SWITCHER_STATE = Object.assign({}, current, state);
    }

    /**
     * Remove the currently rendered switcher and event listeners.
     * @returns {void}
     */
    function destroy() {
        if (outsideClickHandler) {
            document.removeEventListener('click', outsideClickHandler, true);
            outsideClickHandler = null;
        }
        if (keydownHandler) {
            document.removeEventListener('keydown', keydownHandler, true);
            keydownHandler = null;
        }
        if (container && container.parentNode) {
            container.parentNode.removeChild(container);
        }
        container = null;
        setDebugState({ stage: 'destroyed' });
    }

    /**
     * Inject the floating switcher styles once per page.
     * @returns {void}
     */
    function injectStyles() {
        if (stylesInjected) {
            return;
        }
        stylesInjected = true;
        var style = document.createElement('style');
        style.setAttribute('data-xlate-style', 'lang-switcher');
        style.textContent = '' +
            '.xlate-lang-switcher {' +
            '  position:fixed;' +
            '  left:1rem;' +
            '  bottom:1rem;' +
            '  z-index:2147483647;' +
            '  font-family:inherit;' +
            '}' +
            '.xlate-lang-switcher__toggle {' +
            '  background:#0f172a;' +
            '  color:#fff;' +
            '  border:none;' +
            '  border-radius:999px;' +
            '  min-width:56px;' +
            '  height:52px;' +
            '  display:inline-flex;' +
            '  align-items:center;' +
            '  justify-content:center;' +
            '  gap:0.4rem;' +
            '  padding:0 1rem;' +
            '  cursor:pointer;' +
            '  box-shadow:0 14px 30px rgba(15,23,42,0.35);' +
            '}' +
            '.xlate-lang-switcher__toggle:focus {' +
            '  outline:2px solid #fff;' +
            '  outline-offset:2px;' +
            '}' +
            '.xlate-lang-switcher__toggle-code {' +
            '  text-transform:uppercase;' +
            '  font-weight:600;' +
            '  font-size:0.85rem;' +
            '  letter-spacing:0.05em;' +
            '}' +
            '.xlate-lang-switcher__caret {' +
            '  width:8px;' +
            '  height:8px;' +
            '  border:solid #fff;' +
            '  border-width:0 2px 2px 0;' +
            '  transform:rotate(-135deg);' +
            '  transition:transform 0.2s ease;' +
            '}' +
            '.xlate-lang-switcher.xlate-open .xlate-lang-switcher__caret,' +
            '.xlate-lang-switcher:hover .xlate-lang-switcher__caret,' +
            '.xlate-lang-switcher:focus-within .xlate-lang-switcher__caret {' +
            '  transform:rotate(45deg);' +
            '}' +
            '.xlate-lang-switcher__list {' +
            '  list-style:none;' +
            '  margin:0;' +
            '  padding:0.4rem 0;' +
            '  background:#fff;' +
            '  color:#1f2933;' +
            '  border-radius:0.75rem;' +
            '  min-width:190px;' +
            '  box-shadow:0 25px 40px rgba(15,23,42,0.25);' +
            '  opacity:0;' +
            '  transform:translateY(10px);' +
            '  pointer-events:none;' +
            '  transition:opacity 0.2s ease, transform 0.2s ease;' +
            '  position:absolute;' +
            '  bottom:calc(100% + 0.5rem);' +
            '  left:0;' +
            '}' +
            '.xlate-lang-switcher.xlate-open .xlate-lang-switcher__list,' +
            '.xlate-lang-switcher:hover .xlate-lang-switcher__list,' +
            '.xlate-lang-switcher:focus-within .xlate-lang-switcher__list {' +
            '  opacity:1;' +
            '  transform:translateY(0);' +
            '  pointer-events:auto;' +
            '}' +
            '.xlate-lang-switcher__list li {' +
            '  margin:0;' +
            '}' +
            '.xlate-lang-switcher__link {' +
            '  display:flex;' +
            '  align-items:center;' +
            '  justify-content:space-between;' +
            '  padding:0.35rem 0.85rem;' +
            '  text-decoration:none;' +
            '  color:inherit;' +
            '  font-size:0.85rem;' +
            '}' +
            '.xlate-lang-switcher__link:hover,' +
            '.xlate-lang-switcher__link:focus {' +
            '  background:#f3f4f6;' +
            '}' +
            '.xlate-lang-switcher__code {' +
            '  font-size:0.75rem;' +
            '  color:#4b5563;' +
            '}' +
            '.xlate-lang-switcher__link[aria-current="true"] {' +
            '  font-weight:600;' +
            '}';
        document.head.appendChild(style);
    }

    /**
     * Toggle the open state of the switcher dropdown.
     * @param {HTMLButtonElement} button - Toggle button reference.
     * @param {boolean} [open] - Optional forced state.
     * @param {boolean} [silent] - When true, skips aria-expanded updates.
     * @returns {void}
     */
    function toggleOpen(button, open, silent) {
        if (!container) {
            return;
        }
        var shouldOpen = typeof open === 'boolean' ? open : !container.classList.contains('xlate-open');
        if (shouldOpen) {
            container.classList.add('xlate-open');
            if (button && !silent) {
                button.setAttribute('aria-expanded', 'true');
            }
        } else {
            container.classList.remove('xlate-open');
            if (button && !silent) {
                button.setAttribute('aria-expanded', 'false');
            }
        }
    }

    /**
     * Build the current page URL with the provided lang parameter.
     * @param {string} code - Language code to inject.
     * @returns {string} URL pointing to the same page with lang updated.
     */
    function computeLangUrl(code) {
        if (!code) {
            return window.location.href;
        }
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('lang', code);
            return url.toString();
        } catch (e) {
            var base = window.location.href.split('#')[0];
            var cleaned = base.replace(/([?&])lang=[^&#]*(&|$)/, function (match, prefix, suffix) {
                if (prefix === '?') {
                    return prefix;
                }
                return suffix || '';
            }).replace(/[?&]$/, '');
            var sep = cleaned.indexOf('?') === -1 ? '?' : '&';
            return cleaned + sep + 'lang=' + encodeURIComponent(code);
        }
    }

    /**
     * Initialise the floating language switcher with provided config.
    * @param {{
    *   enabled:boolean,
    *   current:string,
    *   languages:Array<{code:string,label:string,url:string}>
    * }} config - Switcher config payload.
     * @returns {void}
     */
    function init(config) {
        if (!config || !config.enabled || !Array.isArray(config.languages) || config.languages.length < 2) {
            destroy();
            setDebugState({ stage: 'disabled', reason: 'invalid_config' });
            return;
        }

        if (!document.body) {
            document.addEventListener('DOMContentLoaded', function handleReady() {
                document.removeEventListener('DOMContentLoaded', handleReady);
                init(config);
            });
            setDebugState({ stage: 'waiting_body' });
            return;
        }

        injectStyles();
        destroy();

        container = document.createElement('div');
        container.className = 'xlate-lang-switcher';
        container.setAttribute('aria-label', 'Language selector');

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'xlate-lang-switcher__toggle';
        toggle.setAttribute('aria-haspopup', 'listbox');
        toggle.setAttribute('aria-expanded', 'false');

        var currentEntry = config.languages.find(function (lang) {
            return lang.code === config.current;
        });
        var toggleCode = document.createElement('span');
        toggleCode.className = 'xlate-lang-switcher__toggle-code';
        toggleCode.textContent = (currentEntry ? currentEntry.code : config.current).toUpperCase();
        toggle.appendChild(toggleCode);
        toggle.setAttribute('aria-label', 'Change language (current ' + toggleCode.textContent + ')');

        var caret = document.createElement('span');
        caret.className = 'xlate-lang-switcher__caret';
        caret.setAttribute('aria-hidden', 'true');
        toggle.appendChild(caret);

        var list = document.createElement('ul');
        list.className = 'xlate-lang-switcher__list';
        list.setAttribute('role', 'listbox');

        config.languages.forEach(function (lang) {
            var item = document.createElement('li');
            var link = document.createElement('a');
            link.className = 'xlate-lang-switcher__link';
            link.href = computeLangUrl(lang.code);
            link.textContent = lang.label;
            link.setAttribute('role', 'option');
            link.setAttribute('data-lang-code', lang.code);
            if (lang.code === config.current) {
                link.setAttribute('aria-current', 'true');
            }
            var codeBadge = document.createElement('span');
            codeBadge.className = 'xlate-lang-switcher__code';
            codeBadge.textContent = lang.code.toUpperCase();
            link.appendChild(codeBadge);
            item.appendChild(link);
            list.appendChild(item);
        });

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            toggleOpen(toggle);
            if (container.classList.contains('xlate-open')) {
                var firstLink = list.querySelector('a');
                if (firstLink) {
                    firstLink.focus();
                }
            }
        });

        toggle.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                toggleOpen(toggle, true);
                var firstLink = list.querySelector('a');
                if (firstLink) {
                    firstLink.focus();
                }
            }
        });

        list.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                toggle.focus();
                toggleOpen(toggle, false);
            }
        });

        container.appendChild(toggle);
        container.appendChild(list);
        document.body.appendChild(container);
        setDebugState({ stage: 'rendered', languages: config.languages.length });

        outsideClickHandler = function (event) {
            if (!container) {
                return;
            }
            if (!container.contains(event.target)) {
                toggleOpen(toggle, false);
            }
        };
        document.addEventListener('click', outsideClickHandler, true);

        keydownHandler = function (event) {
            if (event.key === 'Escape') {
                toggleOpen(toggle, false);
            }
        };
        document.addEventListener('keydown', keydownHandler, true);
    }

    return {
        init: init
    };
});
