// AMD module to queue autotranslate for visible keys in Manage UI.
define(['core/ajax', 'core/notification'], function (Ajax, notification) {
    return {
        init: function (config) {
            var courseButton = document.getElementById('local_xlate_autotranslate_course');
            if (!courseButton) {
                return;
            }

            /**
             * Start polling the server for persisted translations for items.
             * This helper creates a small floating status panel and updates it.
             * @param {string} targetlang
             * @param {Array} items
             */
            function startPolling(targetlang, items) {
                var statusId = 'local_xlate_autotranslate_status';
                var statusEl = document.getElementById(statusId);
                if (!statusEl) {
                    statusEl = document.createElement('div');
                    statusEl.id = statusId;
                    statusEl.style.position = 'fixed';
                    statusEl.style.right = '16px';
                    statusEl.style.bottom = '16px';
                    statusEl.style.zIndex = 1050;
                    statusEl.style.maxWidth = '320px';
                    statusEl.style.background = 'white';
                    statusEl.style.border = '1px solid #ccc';
                    statusEl.style.boxShadow = '0 2px 6px rgba(0,0,0,0.1)';
                    statusEl.style.padding = '12px';
                    statusEl.style.borderRadius = '6px';
                    document.body.appendChild(statusEl);
                }

                // Build an array of plain keys (hashes) for polling and display.
                var plainids = [];
                for (var pi = 0; pi < items.length; pi++) {
                    plainids.push(items[pi].key || items[pi].id || '');
                }
                var total = plainids.length;
                var translatedCount = 0;

                /**
                 * Render the status panel.
                 * @param {Array} list
                 */
                function renderStatus(list) {
                    var header = '<strong>Autotranslate</strong><br/>';
                    var progressLine = '<div style="margin-top:8px">' + translatedCount + ' / ' + total + ' translated</div>';
                    var listContainer = '<div id="local_xlate_progress_list" ' +
                        'style="margin-top:8px; max-height:200px; overflow:auto; font-size:90%"></div>';
                    var closeButton = '<div style="margin-top:8px; text-align:right">' +
                        '<button id="local_xlate_progress_close" class="btn btn-sm btn-secondary">Close</button>' +
                        '</div>';

                    statusEl.innerHTML = header + progressLine + listContainer + closeButton;

                    var listEl = document.getElementById('local_xlate_progress_list');
                    listEl.innerHTML = '';
                    for (var i = 0; i < list.length; i++) {
                        var item = list[i];
                        var row = document.createElement('div');
                        row.style.padding = '2px 0';
                        // Server now returns only the plain key (hash) as id; display that.
                        row.textContent = (item.id || '') + ' — ' + (item.translated ? '✓' : '...');
                        listEl.appendChild(row);
                    }

                    var closeBtn = document.getElementById('local_xlate_progress_close');
                    if (closeBtn) {
                        closeBtn.addEventListener('click', function () {
                            if (statusEl && statusEl.parentNode) {
                                statusEl.parentNode.removeChild(statusEl);
                            }
                        });
                    }
                }

                // Poll every 5s by default and allow longer total wait for slow cron
                var pollInterval = 5000;
                // Allow up to 120 tries -> ~10 minutes max (configurable)
                var maxTries = 120;
                // Per-request timeout (ms) to avoid a single stalled XHR blocking progress
                var perRequestTimeout = 120000; // 2 minutes
                var tries = 0;

                var pollHandle = setInterval(function () {
                    tries = tries + 1;
                    // Poll using plain key/hashes only (not component:key).
                    var ids = plainids.slice();

                    // Wrap Ajax.call with a per-request timeout so a single slow request
                    // doesn't block the whole polling loop.
                    var ajaxPromise = Ajax.call([{
                        methodname: 'local_xlate_autotranslate_progress',
                        args: {
                            items: ids,
                            targetlang: targetlang
                        }
                    }])[0];

                    var timeoutPromise = new Promise(function (resolve, reject) {
                        setTimeout(function () {
                            reject(new Error('progress request timed out'));
                        }, perRequestTimeout);
                    });

                    Promise.race([ajaxPromise, timeoutPromise]).then(function (progress) {
                        if (!(progress && progress.success && Array.isArray(progress.results))) {
                            return null;
                        }

                        translatedCount = 0;
                        for (var k = 0; k < progress.results.length; k++) {
                            if (progress.results[k].translated) {
                                translatedCount = translatedCount + 1;
                            }
                        }

                        // Update Manage page inputs/textareas with any newly-persisted translations.
                        try {
                            // Build a quick map from item id -> component so we can find the
                            // corresponding card on the Manage page. The original `items`
                            // array is available in the closure.
                            var keyToComponent = {};
                            for (var pi = 0; pi < items.length; pi++) {
                                var ik = items[pi].key || (items[pi].id || '');
                                if (!ik) { continue; }
                                keyToComponent[ik] = items[pi].component || '';
                            }

                            for (var r = 0; r < progress.results.length; r++) {
                                var pres = progress.results[r];
                                if (pres && pres.translated && pres.translation) {
                                    var xkey = pres.id;
                                    var comp = keyToComponent[xkey] || '';
                                    // Compose the header text that Manage page uses: component.xkey
                                    var headerText = (comp ? (comp + '.' + xkey) : xkey).trim();
                                    // Find the card with that header
                                    var cardEls = document.querySelectorAll('.card.mb-3');
                                    for (var ci = 0; ci < cardEls.length; ci++) {
                                        try {
                                            var header = cardEls[ci].querySelector('.card-header strong');
                                            if (!header) { continue; }
                                            if (header.textContent.trim() !== headerText) { continue; }
                                            // Within this card, find the form for targetlang and set its translation value
                                            var form = cardEls[ci].querySelector('form input[name="lang"][value="' + targetlang + '"]');
                                            if (form) {
                                                var parentForm = form.closest('form');
                                                if (parentForm) {
                                                    var txt = parentForm.querySelector('[name="translation"]');
                                                    if (txt) {
                                                        if (txt.tagName && txt.tagName.toLowerCase() === 'textarea') {
                                                            txt.value = pres.translation;
                                                        } else {
                                                            txt.value = pres.translation;
                                                        }
                                                        // dispatch input/change events so any listeners update
                                                        txt.dispatchEvent(new Event('input', { bubbles: true }));
                                                        txt.dispatchEvent(new Event('change', { bubbles: true }));
                                                    }
                                                }
                                            }
                                        } catch (e) {
                                            // ignore per-card errors
                                        }
                                    }
                                }
                            }
                        } catch (e) {
                            // don't let UI update errors break polling
                        }

                        renderStatus(progress.results);

                        if (translatedCount >= total) {
                            clearInterval(pollHandle);
                            notification.alert('Autotranslate finished: ' + translatedCount + ' / ' + total + ' translated.');
                        } else if (tries >= maxTries) {
                            clearInterval(pollHandle);
                            notification.alert('Autotranslate polling timed out: ' + translatedCount + ' / ' + total + '.');
                        }

                        return null;
                    }).catch(function () {
                        return null;
                    });
                }, pollInterval);
            }

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

                    // Determine targets same as the key-based autotranslate flow
                    var targets = [];
                    var checked = document.querySelectorAll('input[name="local_xlate_target[]"]:checked');
                    if (checked && checked.length) {
                        for (var ci = 0; ci < checked.length; ci++) {
                            var val = checked[ci].value || '';
                            if (val) {
                                targets.push(val.toString().trim());
                            }
                        }
                    } else {
                        var targetEl = document.getElementById('local_xlate_target');
                        if (targetEl) {
                            if (targetEl.multiple) {
                                for (var si = 0; si < targetEl.options.length; si++) {
                                    if (targetEl.options[si].selected) {
                                        targets.push((targetEl.options[si].value || '').toString().trim());
                                    }
                                }
                            } else {
                                var v = targetEl.value || '';
                                if (v) { targets.push(v.toString().trim()); }
                            }
                        } else {
                            var targetcfg = (config && config.defaulttarget) ? config.defaulttarget : '';
                            targets = Array.isArray(targetcfg) ? targetcfg : [targetcfg];
                        }
                    }

                    if (!targets.length) {
                        notification.alert('No target languages selected for course autotranslate.');
                        return;
                    }

                    var options = {
                        batchsize: (config && config.batchsize) ? config.batchsize : 50,
                        targetlang: targets,
                        sourcelang: config && config.sourcelang ? config.sourcelang : 'en'
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
                        notification.alert('Error enqueuing course job: ' + (err && err.message ? err.message : String(err)));
                    });
                });
            }

            function startCoursePolling(jobid) {
                if (!jobid) { return; }
                var statusId = 'local_xlate_course_autotranslate_status';
                var statusEl = document.getElementById(statusId);
                if (!statusEl) {
                    statusEl = document.createElement('div');
                    statusEl.id = statusId;
                    statusEl.style.position = 'fixed';
                    statusEl.style.left = '16px';
                    statusEl.style.bottom = '16px';
                    statusEl.style.zIndex = 1050;
                    statusEl.style.maxWidth = '420px';
                    statusEl.style.background = 'white';
                    statusEl.style.border = '1px solid #ccc';
                    statusEl.style.boxShadow = '0 2px 6px rgba(0,0,0,0.1)';
                    statusEl.style.padding = '12px';
                    statusEl.style.borderRadius = '6px';
                    document.body.appendChild(statusEl);
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
                        statusEl.innerHTML = '<strong>Course Autotranslate</strong><br/>' +
                            'Status: ' + job.status + '<br/>' +
                            'Processed: ' + job.processed + ' / ' + job.total + '<br/>' +
                            '<div style="margin-top:8px;text-align:right"><button id="local_xlate_course_status_close" class="btn btn-sm btn-secondary">Close</button></div>';

                        var closeBtn = document.getElementById('local_xlate_course_status_close');
                        if (closeBtn) {
                            closeBtn.addEventListener('click', function () {
                                if (statusEl && statusEl.parentNode) { statusEl.parentNode.removeChild(statusEl); }
                            });
                        }

                        if (job.status === 'complete' || job.processed >= job.total) {
                            clearInterval(handle);
                            notification.alert('Course autotranslate complete: ' + job.processed + ' / ' + job.total);
                        } else if (tries >= maxTries) {
                            clearInterval(handle);
                            notification.alert('Course autotranslate polling timed out: ' + job.processed + ' / ' + job.total);
                        }
                    }).catch(function () {
                        // ignore transient errors
                    });
                }, pollInterval);
            }
        }
    };
});
