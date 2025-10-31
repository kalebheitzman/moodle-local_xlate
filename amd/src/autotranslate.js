// AMD module to queue autotranslate for visible keys in Manage UI.
define(['core/ajax', 'core/notification'], function (Ajax, notification) {
    return {
        init: function (config) {
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
                                        '<div id="local_xlate_course_progress_bar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" style="width:0%" aria-valuemin="0" aria-valuemax="100">0%</div>' +
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

            // If the server detected an active job for this course, resume polling
            if (config && config.currentjobid) {
                try {
                    startCoursePolling(config.currentjobid);
                } catch (e) {
                    // ignore any errors starting the resumed poll
                }
            }

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
                        var pct = config.currentjobtotal > 0 ? Math.round((config.currentjobprocessed / config.currentjobtotal) * 100) : 0;
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
                            notification.alert('Course autotranslate complete: ' + processed + ' / ' + total);
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
