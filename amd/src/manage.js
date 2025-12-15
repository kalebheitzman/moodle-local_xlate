// AMD module for the Manage UI (autotranslate controls + inline actions).
/**
 * Manage UI helper for the Admin page.
 *
 * Handles per-entry delete actions, course-level autotranslate submission,
 * and progress polling for in-flight jobs.
 */
define(['core/ajax', 'core/notification'], function (Ajax, notification) {
    /**
     * Resolve a UI string from the AMD config or return a fallback.
     *
     * @param {Object} config Module configuration payload.
     * @param {string} key String key under config.strings.
     * @param {string} fallback Default text when missing.
     * @returns {string} Localised string.
     */
    function getString(config, key, fallback) {
        if (config && config.strings && config.strings[key]) {
            return config.strings[key];
        }
        return fallback;
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
        var form = button.closest ? button.closest('form') : null;
        if (!form) {
            var node = button.parentNode;
            while (node && node.tagName && node.tagName.toLowerCase() !== 'form') {
                node = node.parentNode;
            }
            form = node;
        }
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
        var confirmMessage = getString(config, 'confirmDelete', 'Delete this translation?');
        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                notification.confirm(confirmMessage, function () {
                    deleteTranslation(button, config);
                });
            });
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
            attachDeleteHandlers(config);
            var courseButton = document.getElementById('local_xlate_autotranslate_course');

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
                        // Use Bootstrap striped + animated classes for nicer effect
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

            // per-item autotranslate removed — we only support course-level autotranslate now.

            // Course-level autotranslate: enqueue a job for the current course
            if (courseButton) {
                courseButton.addEventListener('click', function () {
                    // Determine course id from config or global window variable
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

                    if (!selectedTargets.length) {
                        notification.alert('Select at least one target language before enqueuing autotranslation.');
                        return;
                    }

                    // Options for the job (backend will validate course custom fields)
                    var options = {
                        batchsize: (config && config.batchsize) ? config.batchsize : 50,
                        targetlang: selectedTargets
                    };

                    var call = Ajax.call([{
                        methodname: 'local_xlate_autotranslate_course_enqueue',
                        args: {
                            courseid: courseid,
                            options: options
                        }
                    }])[0];

                    call.then(function (res) {
                        if (!(res && res.success)) {
                            notification.alert('Failed to enqueue course autotranslate job.');
                            return;
                        }
                        notification.alert('Course autotranslate job queued. Job id: ' + (res.jobid || 'n/a'));
                        // Start polling job progress
                        startCoursePolling(res.jobid);
                    }).catch(function (err) {
                        var msg = 'Error enqueuing course job';
                        if (err && err.message) {
                            msg += ': ' + err.message;
                        } else if (err && err.error) {
                            msg += ': ' + err.error;
                        }
                        // Add hint about course settings if it looks like a config error
                        if (msg.toLowerCase().indexOf('language') !== -1 || msg.toLowerCase().indexOf('configuration') !== -1) {
                            msg += '\n\nPlease configure source and target languages in the course settings (Xlate section).';
                        }
                        notification.alert(msg);
                    });
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
