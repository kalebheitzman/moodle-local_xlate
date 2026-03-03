// AMD module for the Manage UI (autotranslate controls + inline actions).
/**
 * Manage UI helper for the Admin page.
 *
 * Handles per-entry delete actions, course-level autotranslate submission,
 * and progress polling for in-flight jobs.
 */
define(['core/ajax', 'core/notification', 'core/str'], function (Ajax, notification, Str) {
    // Resolved UI strings — populated asynchronously by loadStrings().
    var M_XLATE_STRINGS = {};

    // Maps JS alias → Moodle {component, key} for bulk string pre-loading.
    var STRING_MAP = {
        confirmDelete:              {component: 'local_xlate', key: 'confirm_delete_translation'},
        confirmDeleteTitle:         {component: 'local_xlate', key: 'confirm_delete_title'},
        confirmDeleteAction:        {component: 'local_xlate', key: 'confirm_delete_action'},
        confirmDeleteCancel:        {component: 'moodle',      key: 'cancel'},
        deleteFailed:               {component: 'local_xlate', key: 'delete_failed'},
        deleteSuccess:              {component: 'local_xlate', key: 'translation_deleted'},
        autoTranslateFailed:        {component: 'local_xlate', key: 'autotranslate_inline_failed'},
        autoTranslateError:         {component: 'local_xlate', key: 'autotranslate_failed'},
        autoTranslateReady:         {component: 'local_xlate', key: 'autotranslate_inline_success'},
        autoTranslateSaved:         {component: 'local_xlate', key: 'auto_translate_saved'},
        autoTranslateCourseQueued:  {component: 'local_xlate', key: 'autotranslate_course_queued'},
        autoTranslateCourseQueueFailed: {component: 'local_xlate', key: 'autotranslate_course_queue_failed'},
        autoTranslateSelectTarget:  {component: 'local_xlate', key: 'autotranslate_select_target'},
        retranslateUnreviewedQueued:{component: 'local_xlate', key: 'retranslate_unreviewed_queued'},
        saveSuccess:                {component: 'local_xlate', key: 'translation_saved'},
        saveFailed:                 {component: 'local_xlate', key: 'translation_save_failed'},
        saveIndicator:              {component: 'local_xlate', key: 'translation_saved_short'}
    };

    /**
     * Return a UI string from the pre-loaded cache, or the fallback.
     *
     * Always returns a plain string (never a Promise) so results can be
     * safely concatenated or set as DOM text content.
     *
     * @param {Object} config Module configuration payload (unused, kept for API compat).
     * @param {string} key String alias from STRING_MAP.
     * @param {string} fallback English fallback used before strings are loaded.
     * @returns {string}
     */
    function getString(config, key, fallback) {
        if (M_XLATE_STRINGS[key] !== undefined) {
            return M_XLATE_STRINGS[key];
        }
        return typeof fallback === 'string' ? fallback : '';
    }

    /**
     * Bulk-load all UI strings from Moodle's string API into M_XLATE_STRINGS.
     * Called once during init(); handlers fall back to hardcoded English until complete.
     *
     * @returns {void}
     */
    function loadStrings() {
        var jsKeys = Object.keys(STRING_MAP);
        var requests = jsKeys.map(function(k) { return STRING_MAP[k]; });
        Str.get_strings(requests).then(function(results) {
            jsKeys.forEach(function(k, i) {
                if (typeof results[i] === 'string' && results[i] !== '') {
                    M_XLATE_STRINGS[k] = results[i];
                }
            });
        }).catch(function() {
            // Non-critical: callers fall back to hardcoded English strings.
        });
    }

    /**
     * Find the closest form ancestor for an element (with IE fallback).
     *
     * @param {HTMLElement} element Origin element.
     * @returns {HTMLFormElement|null} Resolved form element.
     */
    function findParentForm(element) {
        if (!element) {
            return null;
        }
        if (typeof element.closest === 'function') {
            return element.closest('form');
        }
        var node = element;
        while (node && node.tagName && node.tagName.toLowerCase() !== 'form') {
            node = node.parentNode;
        }
        if (node && node.tagName && node.tagName.toLowerCase() === 'form') {
            return node;
        }
        return null;
    }

    /**
     * Clear translation input/checkbox fields tied to a delete button.
     *
     * @param {HTMLElement} button Delete button element.
     * @returns {void}
     */
    function clearTranslationFields(button) {
        if (!button) {
            return;
        }
        var form = findParentForm(button);
        if (!form) {
            return;
        }
        var field = form.querySelector('[name="translation"]');
        if (field) {
            field.value = '';
        }
        var toggles = form.querySelectorAll('input[name="status"], input[name="reviewed"]');
        if (toggles && toggles.length) {
            Array.prototype.forEach.call(toggles, function (toggle) {
                toggle.checked = false;
            });
        }
    }

    /**
     * Serialize a translation form into an AJAX payload.
     *
     * @param {HTMLFormElement} form Translation form element.
     * @returns {{keyid:number,lang:string,translation:string,status:number,reviewed:number}|null}
     */
    function serializeTranslationForm(form) {
        if (!form) {
            return null;
        }
        var keyid = parseInt(form.getAttribute('data-keyid'), 10) || 0;
        var lang = form.getAttribute('data-lang') || '';
        if (!keyid || !lang) {
            return null;
        }
        var courseidAttr = form.getAttribute('data-courseid');
        var courseid = courseidAttr ? parseInt(courseidAttr, 10) || 0 : 0;
        var translationField = form.querySelector('[name="translation"]');
        var statusToggle = form.querySelector('input[name="status"]');
        var reviewedToggle = form.querySelector('input[name="reviewed"]');
        return {
            keyid: keyid,
            lang: lang,
            translation: translationField ? translationField.value : '',
            status: statusToggle && statusToggle.checked ? 1 : 0,
            reviewed: reviewedToggle && reviewedToggle.checked ? 1 : 0,
            courseid: courseid
        };
    }

    /**
     * Toggle disabled state for autosave checkboxes during network calls.
     *
     * @param {HTMLFormElement} form Translation form.
     * @param {boolean} disabled Whether to disable toggles.
     * @returns {void}
     */
    function setAutosaveTogglesDisabled(form, disabled) {
        if (!form) {
            return;
        }
        var toggles = form.querySelectorAll('.js-xlate-toggle');
        if (!toggles || !toggles.length) {
            return;
        }
        Array.prototype.forEach.call(toggles, function (toggle) {
            toggle.disabled = !!disabled;
        });
    }

    /**
     * Flash a small inline indicator next to the save controls.
     *
     * @param {HTMLFormElement} form Translation form.
     * @param {string} message Text to display.
     * @param {boolean} success Whether this is a success badge (default) or error badge.
     * @returns {void}
     */
    function flashSaveIndicator(form, message, success) {
        if (!form) {
            return;
        }
        var indicator = form.querySelector('.js-xlate-save-indicator');
        if (!indicator) {
            indicator = document.createElement('span');
            indicator.className = 'badge rounded-pill js-xlate-save-indicator';
            indicator.setAttribute('role', 'status');
            indicator.setAttribute('aria-live', 'polite');
            form.appendChild(indicator);
        }
        indicator.classList.remove('d-none');
        indicator.classList.remove('text-bg-success', 'text-bg-danger');
        indicator.classList.add(success === false ? 'text-bg-danger' : 'text-bg-success');
        indicator.textContent = message || '';
        clearTimeout(indicator._xlateTimer);
        indicator._xlateTimer = setTimeout(function () {
            indicator.classList.add('d-none');
        }, 2500);
    }

    /**
     * Briefly highlight the translation field to confirm a save.
     *
     * @param {HTMLFormElement} form Translation form element.
     * @returns {void}
     */
    function highlightTranslationField(form) {
        if (!form) {
            return;
        }
        var field = form.querySelector('.js-xlate-translation-field');
        if (!field) {
            return;
        }
        field.classList.add('xlate-field-saved');
        clearTimeout(field._xlateSavedTimer);
        field._xlateSavedTimer = setTimeout(function () {
            field.classList.remove('xlate-field-saved');
        }, 2000);
    }

    /**
     * Persist a translation form via AJAX and surface user feedback.
     *
     * @param {HTMLFormElement} form Translation form.
     * @param {Object} options Additional behaviour flags.
     * @param {HTMLElement} [options.source] Element initiating the save.
     * @param {boolean} [options.showToast] Whether to display a toast notification.
     * @param {Object} config Module configuration.
     * @returns {void}
     */
    function saveTranslationForm(form, options, config) {
        options = options || {};
        if (form) {
            var translationField = form.querySelector('[name="translation"]');
            var reviewedToggle = form.querySelector('input[name="reviewed"]');
            if (translationField && reviewedToggle) {
                var value = translationField.value || '';
                if (value.trim() !== '') {
                    reviewedToggle.checked = true;
                }
            }
        }
        var payload = serializeTranslationForm(form);
        if (!payload) {
            return;
        }
        if (form.dataset && form.dataset.saving === '1') {
            form.dataset.pendingSave = '1';
            form._xlatePendingOptions = options;
            return;
        }
        if (form.dataset) {
            form.dataset.saving = '1';
        }
        var source = options.source || null;
        var sourceIsButton = source && source.tagName && source.tagName.toLowerCase() === 'button';
        if (sourceIsButton) {
            toggleButtonLoading(source, true);
        }
        setAutosaveTogglesDisabled(form, true);
        var showToast = !!options.showToast;
        if (source && source.classList && source.classList.contains('js-xlate-toggle')) {
            showToast = false;
        }

        var request = Ajax.call([{
            methodname: 'local_xlate_save_translation',
            args: payload
        }])[0];

        var handleSuccess = function () {
            highlightTranslationField(form);
            if (showToast) {
                var toastMessage = getString(config, 'saveSuccess', 'Translation saved successfully.');
                notification.addNotification({
                    message: toastMessage,
                    type: 'success'
                });
            }
        };

        var handleFailure = function (err) {
            var title = getString(config, 'saveFailed', 'Unable to save translation.');
            var detail = (err && err.message) ? err.message : ((err && err.error) ? err.error : '');
            notification.alert(title, detail);
            flashSaveIndicator(form, title, false);
        };

        var finishSave = function () {
            if (form.dataset) {
                delete form.dataset.saving;
            }
            if (sourceIsButton) {
                toggleButtonLoading(source, false);
            }
            setAutosaveTogglesDisabled(form, false);
            if (form.dataset && form.dataset.pendingSave === '1') {
                delete form.dataset.pendingSave;
                var nextOptions = form._xlatePendingOptions || {};
                delete form._xlatePendingOptions;
                saveTranslationForm(form, nextOptions, config);
            }
        };

        var chain = request.then(handleSuccess).catch(function (err) {
            handleFailure(err);
        });

        if (typeof chain.finally === 'function') {
            chain.finally(finishSave);
        } else {
            chain.then(finishSave, finishSave);
        }
    }

    /**
     * Invoke the delete translation web service for a button context.
     *
     * @param {HTMLElement} button Button initiating the delete request.
     * @param {Object} config Module configuration.
     * @returns {void}
     */
    function deleteTranslation(button, config) {
        if (!button) {
            return;
        }
        var keyid = parseInt(button.getAttribute('data-keyid'), 10) || 0;
        var lang = button.getAttribute('data-lang') || '';
        if (!keyid || !lang) {
            return;
        }

        var failureMessage = getString(config, 'deleteFailed', 'Unable to delete translation.');
        var successMessage = getString(config, 'deleteSuccess', 'Translation deleted.');

        button.disabled = true;

        var request = Ajax.call([{
            methodname: 'local_xlate_delete_translation',
            args: {
                keyid: keyid,
                lang: lang
            }
        }])[0];

        request.then(function (res) {
            if (res && res.success) {
                clearTranslationFields(button);
                notification.addNotification({
                    message: successMessage,
                    type: 'success'
                });
            } else {
                notification.alert(failureMessage);
            }
            button.disabled = false;
        }).catch(function (err) {
            var msg = failureMessage;
            if (err && err.message) {
                msg += '\n' + err.message;
            } else if (err && err.error) {
                msg += '\n' + err.error;
            }
            notification.alert(msg);
            button.disabled = false;
        });
    }

    /**
     * Swap button text for a spinner while an action is running.
     *
     * @param {HTMLButtonElement} button Target button.
     * @param {boolean} loading Whether to show loading state.
     * @returns {void}
     */
    function toggleButtonLoading(button, loading) {
        if (!button) {
            return;
        }
        var isIconButton = button.classList && button.classList.contains('js-xlate-icon-button');
        if (loading) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
                if (!isIconButton) {
                    var labelText = '';
                    if (button.textContent) {
                        labelText = button.textContent.trim();
                    }
                    if (!labelText && button.getAttribute('aria-label')) {
                        labelText = button.getAttribute('aria-label');
                    }
                    button.dataset.originalLabel = labelText || 'Autotranslate';
                }
            }
            button.disabled = true;
            if (isIconButton) {
                button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
            } else {
                var label = button.dataset.originalLabel || '';
                var spinner = '' +
                    '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>';
                button.innerHTML = spinner + label;
            }
        } else {
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
            if (button.dataset.originalLabel) {
                delete button.dataset.originalLabel;
            }
            button.disabled = false;
        }
    }

    /**
     * Request an inline autotranslation for the button's key/language pair.
     *
     * @param {HTMLButtonElement} button Autotranslate button element.
     * @param {Object} config Module configuration.
     * @returns {void}
     */
    function requestAutotranslation(button, config) {
        if (!button) {
            return;
        }
        var keyid = parseInt(button.getAttribute('data-keyid'), 10) || 0;
        var targetlang = button.getAttribute('data-lang') || '';
        if (!keyid || !targetlang) {
            return;
        }
        var form = findParentForm(button);
        var textarea = form ? form.querySelector('[name="translation"]') : null;
        var statusToggle = form ? form.querySelector('input[name="status"]') : null;
        var reviewedToggle = form ? form.querySelector('input[name="reviewed"]') : null;

        var args = {
            keyid: keyid,
            targetlang: targetlang,
            autosave: true
        };
        var courseid = (config && config.courseid) ? parseInt(config.courseid, 10) || 0 : 0;
        if (courseid > 0) {
            args.courseid = courseid;
        }

        toggleButtonLoading(button, true);

        Ajax.call([{
            methodname: 'local_xlate_autotranslate_key',
            args: args
        }])[0].then(function (res) {
            toggleButtonLoading(button, false);
            if (!(res && res.success && typeof res.translation === 'string')) {
                var failBody = getString(config, 'autoTranslateFailed',
                    'Unable to autotranslate this key right now. Please try again.');
                if (res && res.error) {
                    failBody += '\n' + res.error;
                }
                notification.alert(getString(config, 'autoTranslateError', 'Autotranslate failed.'), failBody);
                return;
            }
            if (textarea) {
                textarea.value = res.translation;
                try {
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                } catch (e) {
                    var evt = document.createEvent('Event');
                    evt.initEvent('input', true, true);
                    textarea.dispatchEvent(evt);
                }
            }
            if (reviewedToggle) {
                reviewedToggle.checked = false;
            }
            if (statusToggle) {
                statusToggle.checked = true;
            }
            var successKey = res && res.saved ? 'autoTranslateSaved' : 'autoTranslateReady';
            var successFallback = successKey === 'autoTranslateSaved'
                ? 'Autotranslation saved and marked active.'
                : 'Autotranslation inserted. Review and save.';
            var successMessage = getString(config, successKey, successFallback);
            notification.addNotification({
                message: successMessage,
                type: 'success'
            });
        }).catch(function (err) {
            toggleButtonLoading(button, false);
            var errDetail = (err && err.message) ? err.message : ((err && err.error) ? err.error : '');
            var body = getString(config, 'autoTranslateFailed', 'Unable to autotranslate this key right now. Please try again.');
            if (errDetail) {
                body += '\n' + errDetail;
            }
            notification.alert(getString(config, 'autoTranslateError', 'Autotranslate failed.'), body);
        });
    }

    /**
     * Wire up delete buttons to the AJAX workflow.
     *
     * @param {Object} config Module configuration payload.
     * @returns {void}
     */
    function attachDeleteHandlers(config) {
        var buttons = document.querySelectorAll('.js-xlate-delete-translation');
        if (!buttons || !buttons.length) {
            return;
        }
        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                if (button.disabled) {
                    return;
                }
                notification.confirm(
                    getString(config, 'confirmDeleteTitle', 'Delete translation'),
                    getString(config, 'confirmDelete', 'Delete this translation?'),
                    getString(config, 'confirmDeleteAction', 'Confirm'),
                    getString(config, 'confirmDeleteCancel', 'Cancel'),
                    function () {
                        deleteTranslation(button, config);
                    }
                );
            });
        });
    }

    /**
     * Wire autotranslate buttons to inline translation requests.
     *
     * @param {Object} config Module configuration payload.
     * @returns {void}
     */
    function attachAutoTranslateHandlers(config) {
        var buttons = document.querySelectorAll('.js-xlate-auto-translate');
        if (!buttons || !buttons.length) {
            return;
        }
        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                if (button.disabled) {
                    return;
                }
                requestAutotranslation(button, config);
            });
        });
    }

    /**
     * Enable AJAX submit + autosave behaviour for translation forms.
     *
     * @param {Object} config Module configuration.
     * @returns {void}
     */
    function attachTranslationFormHandlers(config) {
        var forms = document.querySelectorAll('.js-xlate-translation-form');
        if (!forms || !forms.length) {
            return;
        }
        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
                if (event) {
                    event.preventDefault();
                }
                var saveButton = form.querySelector('.js-xlate-save-button');
                saveTranslationForm(form, { source: saveButton, showToast: true }, config);
            });
            var toggles = form.querySelectorAll('.js-xlate-toggle');
            if (!toggles || !toggles.length) {
                return;
            }
            Array.prototype.forEach.call(toggles, function (toggle) {
                toggle.addEventListener('change', function () {
                    saveTranslationForm(form, { source: toggle, showToast: false }, config);
                });
            });
        });
    }

    /**
     * Enable Bootstrap tooltips for icon-only action buttons (Boost themes).
     *
     * @returns {void}
     */
    function enableIconTooltips() {
        var buttons = document.querySelectorAll('.js-xlate-icon-button[data-bs-toggle="tooltip"]');
        if (!buttons || !buttons.length || typeof require !== 'function') {
            return;
        }
        require(['theme_boost/bootstrap'], function (Bootstrap) {
            if (!Bootstrap) {
                return;
            }
            var TooltipCtor = Bootstrap.Tooltip || (Bootstrap.default && Bootstrap.default.Tooltip);
            if (!TooltipCtor) {
                return;
            }
            Array.prototype.forEach.call(buttons, function (button) {
                if (!button) {
                    return;
                }
                try {
                    if (typeof TooltipCtor.getInstance === 'function') {
                        var instance = TooltipCtor.getInstance(button);
                        if (instance) {
                            instance.enable();
                            return;
                        }
                    }
                    new TooltipCtor(button);
                } catch (e) {
                    // Ignore tooltip instantiation errors to keep buttons usable.
                }
            });
        }, function () {
            // Non-Boost themes may not provide the Bootstrap AMD helper; fail silently.
        });
    }

    return {
        /**
         * Initialise the autotranslate UI bindings.
         *
         * @param {Object} config Server-provided settings for the Manage page.
         * @param {number} [config.courseid] Current course filter identifier.
         * @param {number} [config.currentjobid] Active job to resume polling.
         * @returns {void}
         */
        init: function (config) {
            loadStrings();
            attachTranslationFormHandlers(config);
            attachDeleteHandlers(config);
            attachAutoTranslateHandlers(config);
            enableIconTooltips();
            var courseButton = document.getElementById('local_xlate_autotranslate_course');
            var retranslateUnreviewedButton = document.getElementById('local_xlate_retranslate_unreviewed_course');

            // If the server passed an active job id but the Manage page card or
            // its elements are not present (for example because the course
            // filter was lost during navigation), create a small inline
            // progress container and resume polling so the user still sees
            // progress for their job.
            if (config && config.currentjobid && !document.getElementById('local_xlate_course_progress')) {
                try {
                    // Append the progress container to the main content area if available,
                    // otherwise append to document.body.
                    var parent = document.getElementById('region-main') || document.body;
                    var wrapper = document.createElement('div');
                    wrapper.id = 'local_xlate_course_progress_wrapper';
                    wrapper.style.margin = '12px 0';
                    wrapper.innerHTML = '' +
                        '<div id="local_xlate_course_progress" style="display:block;">' +
                        '<div style="font-size:90%; margin-bottom:6px">' +
                        '<span id="local_xlate_course_job_owner" style="font-weight:600"></span>' +
                        '<span id="local_xlate_course_job_status" style="margin-left:8px; color:#666"></span>' +
                        '<span id="local_xlate_course_job_langs" style="float:right; font-size:90%"></span>' +
                        '</div>' +
                        '<div class="progress" role="progressbar" aria-label="Autotranslate progress">' +
                        '<div id="local_xlate_course_progress_bar" class="progress-bar progress-bar-striped ' +
                        'progress-bar-animated bg-info" style="width:0%" aria-valuemin="0" aria-valuemax="100">0%</div>' +
                        '</div>' +
                        '<div id="local_xlate_course_progress_text" style="margin-top:6px; font-size:90%">0 / 0</div>' +
                        '</div>';
                    parent.insertBefore(wrapper, parent.firstChild);
                } catch (e) {
                    // ignore DOM insertion errors
                }
            }

            // If there's no course button (no card), keep going — we still may
            // need to resume polling for an active job.

            // per-item autotranslate removed — we only support course-level autotranslate now.

            var enqueueCourseJob = function (jobOptions, queuedMessage, queueFailedMessage) {
                var courseid = 0;
                if (typeof window !== 'undefined' && typeof window.XLATE_COURSEID !== 'undefined') {
                    courseid = window.XLATE_COURSEID || 0;
                } else if (config && config.courseid) {
                    courseid = config.courseid || 0;
                }

                if (!courseid) {
                    notification.alert('Please navigate to this page with a valid course filter to autotranslate the course.');
                    return;
                }

                var selectedTargets = [];
                var targetInputs = document.querySelectorAll('#local_xlate_target_container input[type="checkbox"]');
                if (targetInputs && targetInputs.length) {
                    selectedTargets = Array.prototype.filter.call(targetInputs, function (input) {
                        return !!input.checked;
                    }).map(function (input) {
                        return input.value;
                    });
                }
                if (!selectedTargets.length && config && Array.isArray(config.targetlangs)) {
                    selectedTargets = config.targetlangs;
                }

                if (!selectedTargets.length) {
                    notification.alert(
                        getString(
                            config,
                            'autoTranslateSelectTarget',
                            'Select at least one target language before enqueuing autotranslation.'
                        )
                    );
                    return;
                }

                var options = {
                    batchsize: (config && config.batchsize) ? config.batchsize : 50,
                    targetlang: selectedTargets
                };
                if (jobOptions && typeof jobOptions === 'object') {
                    Object.keys(jobOptions).forEach(function (key) {
                        options[key] = jobOptions[key];
                    });
                }

                var call = Ajax.call([{
                    methodname: 'local_xlate_autotranslate_course_enqueue',
                    args: {
                        courseid: courseid,
                        options: options
                    }
                }])[0];

                call.then(function (res) {
                    if (!(res && res.success)) {
                        notification.alert(
                            queueFailedMessage || getString(
                                config,
                                'autoTranslateCourseQueueFailed',
                                'Failed to enqueue course autotranslate job.'
                            )
                        );
                        return;
                    }
                    var messageTemplate = queuedMessage || getString(
                        config,
                        'autoTranslateCourseQueued',
                        'Course autotranslate job queued. Job id: {$a}'
                    );
                    var message = messageTemplate.replace('{$a}', String(res.jobid || 'n/a'));
                    notification.alert(message);
                    startCoursePolling(res.jobid);
                }).catch(function (err) {
                    var msg = 'Error enqueuing course job';
                    if (err && err.message) {
                        msg += ': ' + err.message;
                    } else if (err && err.error) {
                        msg += ': ' + err.error;
                    }
                    if (msg.toLowerCase().indexOf('language') !== -1 || msg.toLowerCase().indexOf('configuration') !== -1) {
                        msg += '\n\nPlease configure source and target languages in the course settings (Xlate section).';
                    }
                    notification.alert(msg);
                });
            };

            if (courseButton) {
                courseButton.addEventListener('click', function () {
                    enqueueCourseJob(
                        {},
                        getString(
                            config,
                            'autoTranslateCourseQueued',
                            'Course autotranslate job queued. Job id: {$a}'
                        ),
                        getString(
                            config,
                            'autoTranslateCourseQueueFailed',
                            'Failed to enqueue course autotranslate job.'
                        )
                    );
                });
            }

            if (retranslateUnreviewedButton) {
                retranslateUnreviewedButton.addEventListener('click', function () {
                    enqueueCourseJob(
                        { onlyunreviewed: true },
                        getString(
                            config,
                            'retranslateUnreviewedQueued',
                            'Unreviewed retranslation job queued. Job id: {$a}'
                        ),
                        getString(
                            config,
                            'autoTranslateCourseQueueFailed',
                            'Failed to enqueue course autotranslate job.'
                        )
                    );
                });
            }

            // If the server detected an active job for this course, resume polling
            if (config && config.currentjobid) {
                try {
                    startCoursePolling(config.currentjobid);
                } catch (e) {
                    // ignore any errors starting the resumed poll
                }
            }

            /**
             * Poll course job progress and update the inline progress UI.
             *
             * @param {number} jobid Identifies the queued `translate_course_task`.
             * @returns {void}
             */
            function startCoursePolling(jobid) {
                if (!jobid) { return; }

                var container = document.getElementById('local_xlate_course_progress');
                var bar = document.getElementById('local_xlate_course_progress_bar');
                var text = document.getElementById('local_xlate_course_progress_text');
                if (!container || !bar || !text) {
                    // Nothing to update on the page; bail out.
                    return;
                }

                // Show the inline progress container
                container.style.display = 'block';

                // Initialize owner/status from server-provided config when available.
                if (config) {
                    var ownerEl = document.getElementById('local_xlate_course_job_owner');
                    var statusEl = document.getElementById('local_xlate_course_job_status');
                    if (ownerEl && config.currentjobowner) {
                        ownerEl.textContent = 'Job by ' + config.currentjobowner;
                    }
                    if (statusEl && config.currentjobstatus) {
                        statusEl.textContent = '(' + config.currentjobstatus + ')';
                    }
                    // Show languages being translated (targetlang and sourcelang)
                    var langsEl = document.getElementById('local_xlate_course_job_langs');
                    if (langsEl) {
                        var parts = [];
                        if (config.currentjobsourcelang) {
                            parts.push(config.currentjobsourcelang + ' →');
                        }
                        if (config.currentjobtargetlang) {
                            if (Array.isArray(config.currentjobtargetlang)) {
                                parts.push(config.currentjobtargetlang.join(', '));
                            } else {
                                parts.push(config.currentjobtargetlang);
                            }
                        }
                        if (parts.length) {
                            langsEl.textContent = parts.join(' ');
                        }
                    }
                    if (config.currentjobprocessed !== undefined && config.currentjobtotal !== undefined) {
                        text.textContent = config.currentjobprocessed + ' / ' + config.currentjobtotal;
                        var pct = config.currentjobtotal > 0
                            ? Math.round((config.currentjobprocessed / config.currentjobtotal) * 100)
                            : 0;
                        bar.style.width = pct + '%';
                        bar.setAttribute('aria-valuenow', pct);
                        bar.textContent = pct + '%';
                    }
                }

                var tries = 0;
                var maxTries = 120;
                var pollInterval = 5000;

                var handle = setInterval(function () {
                    tries = tries + 1;
                    Ajax.call([{
                        methodname: 'local_xlate_autotranslate_course_progress',
                        args: { jobid: jobid }
                    }])[0].then(function (res) {
                        if (!(res && res.success && res.job)) { return; }
                        var job = res.job;
                        var total = job.total || 0;
                        var processed = job.processed || 0;
                        var percent = total > 0 ? Math.round((processed / total) * 100) : 0;

                        // Update progress bar and numeric text
                        bar.style.width = percent + '%';
                        bar.setAttribute('aria-valuenow', percent);
                        bar.textContent = percent + '%';
                        text.textContent = processed + ' / ' + total;

                        if (job.status === 'complete' || processed >= total) {
                            clearInterval(handle);
                            bar.style.width = '100%';
                            bar.setAttribute('aria-valuenow', 100);
                            bar.textContent = '100%';
                            text.textContent = (processed || total) + ' / ' + total + ' — complete';
                            notification.alert(
                                'Course autotranslate complete: ' + processed + ' / ' + total
                            );
                        } else if (job.status === 'complete_partial') {
                            clearInterval(handle);
                            var partialPercent = total > 0 ? Math.round((processed / total) * 100) : 0;
                            bar.style.width = partialPercent + '%';
                            bar.setAttribute('aria-valuenow', partialPercent);
                            bar.textContent = partialPercent + '%';
                            text.textContent = processed + ' / ' + total + ' — completed with missing items';
                            notification.alert(
                                'Course autotranslate completed with missing items: ' + processed + ' / ' + total
                            );
                        } else if (tries >= maxTries) {
                            clearInterval(handle);
                            notification.alert('Course autotranslate polling timed out: ' + processed + ' / ' + total);
                        }
                    }).catch(function () {
                        // ignore transient errors
                    });
                }, pollInterval);
            }
        }
    };
});

