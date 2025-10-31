// AMD module to queue autotranslate for visible keys in Manage UI.
define(['core/ajax', 'core/notification'], function (Ajax, notification) {
    return {
        init: function (config) {
            var button = document.getElementById('local_xlate_autotranslate');
            if (!button) {
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

                var pollInterval = 3000;
                var maxTries = 40;
                var tries = 0;

                var pollHandle = setInterval(function () {
                    tries = tries + 1;
                    // Poll using plain key/hashes only (not component:key).
                    var ids = plainids.slice();

                    var pcall = Ajax.call([{
                        methodname: 'local_xlate_autotranslate_progress',
                        args: {
                            items: ids,
                            targetlang: targetlang
                        }
                    }])[0];

                    pcall.then(function (progress) {
                        if (!(progress && progress.success && Array.isArray(progress.results))) {
                            return null;
                        }

                        translatedCount = 0;
                        for (var k = 0; k < progress.results.length; k++) {
                            if (progress.results[k].translated) {
                                translatedCount = translatedCount + 1;
                            }
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

            button.addEventListener('click', function () {
                // Collect visible key cards (component.xkey in strong inside card-header)
                var cards = Array.prototype.slice.call(document.querySelectorAll('.card.mb-3'));
                var items = [];
                var pageCourseId = 0;
                if (typeof window !== 'undefined' && typeof window.XLATE_COURSEID !== 'undefined') {
                    pageCourseId = window.XLATE_COURSEID;
                } else if (config && config.courseid) {
                    pageCourseId = config.courseid;
                }

                cards.forEach(function (card) {
                    try {
                        var header = card.querySelector('.card-header strong');
                        if (!header) {
                            return;
                        }
                        var compkey = header.textContent.trim();
                        if (!compkey || compkey.indexOf('.') === -1) {
                            return;
                        }
                        var parts = compkey.split('.');
                        var component = parts.slice(0, parts.length - 1).join('.');
                        var xkey = parts[parts.length - 1];

                        var sourceText = '';
                        var srcel = card.querySelector('.form-control-plaintext');
                        if (srcel) {
                            sourceText = srcel.textContent.trim();
                        }

                        items.push({
                            id: component + ':' + xkey,
                            component: component,
                            key: xkey,
                            source_text: sourceText
                        });
                    } catch (e) {
                        // Ignore parsing errors per-card
                    }
                });

                if (!items.length) {
                    notification.alert('No visible translation keys found on this page.');
                    return;
                }

                // Determine target languages. Prefer a user-selected value from the
                // Manage page select (`#local_xlate_target`) when present; otherwise
                // fall back to the AMD `config.defaulttarget` value which may be a
                // string or array.
                var targets = [];
                // First, check for checkbox inputs named local_xlate_target[] (our new UI).
                var checked = document.querySelectorAll('input[name="local_xlate_target[]"]:checked');
                if (checked && checked.length) {
                    for (var ci = 0; ci < checked.length; ci++) {
                        var val = checked[ci].value || '';
                        if (val) {
                            targets.push(val.toString().trim());
                        }
                    }
                } else {
                    // Fallback to the legacy select element with id local_xlate_target.
                    var targetEl = document.getElementById('local_xlate_target');
                    if (targetEl) {
                        // Support both single-select and multi-select. Collect selected values.
                        if (targetEl.multiple) {
                            for (var si = 0; si < targetEl.options.length; si++) {
                                if (targetEl.options[si].selected) {
                                    targets.push((targetEl.options[si].value || '').toString().trim());
                                }
                            }
                        } else {
                            var v = targetEl.value || '';
                            if (v) {
                                targets.push(v.toString().trim());
                            }
                        }
                    } else {
                        var targetcfg = (config && config.defaulttarget) ? config.defaulttarget : '';
                        targets = Array.isArray(targetcfg) ? targetcfg : [targetcfg];
                    }
                }
                // Trim/normalize and filter empty values
                targets = targets.map(function (t) {
                    return (t || '').toString().trim();
                }).filter(function (t) {
                    return t !== '';
                });

                if (!targets.length) {
                    notification.alert('No default target language configured. Please specify a target language in the Manage UI.');
                    return;
                }

                // Queue one task per target language.
                targets.forEach(function (target) {
                    var call = Ajax.call([{
                        methodname: 'local_xlate_autotranslate',
                        args: {
                            sourcelang: config.sourcelang || 'en',
                            // The server accepts an array of target languages; send as an array
                            // even when queuing a single language so the external API validation
                            // matches the webservice signature.
                            targetlang: [target],
                            items: items,
                            glossary: config.glossary || [],
                            options: {}
                        }
                    }])[0];

                    /**
                     * Extract a readable error message from various error shapes.
                     * @param {*} err
                     * @return {string}
                     */
                    function extractError(err) {
                        try {
                            if (!err) {
                                return 'Unknown error';
                            }
                            if (typeof err === 'string') {
                                return err;
                            }
                            if (err.error) {
                                return (typeof err.error === 'string') ? err.error : JSON.stringify(err.error);
                            }
                            if (err.exception) {
                                return err.exception + (err.message ? (': ' + err.message) : '');
                            }
                            if (err.message) {
                                return err.message;
                            }
                            return JSON.stringify(err);
                        } catch (e) {
                            return String(err);
                        }
                    }

                    call.then(function (res) {
                        if (!(res && res.success)) {
                            var reason = (res && (res.error || res.message)) ? (res.error || res.message) : null;
                            var msg = 'Failed to queue autotranslate task for ' + target + (reason ? (': ' + reason) : '.');
                            notification.alert(msg);
                            return null;
                        }

                        notification.alert('Autotranslate task queued for ' + target + '. Task id: ' + (res.taskid || 'n/a'));
                        // Start polling for persisted translations for this language.
                        startPolling(target, items);

                        return null;
                    }).catch(function (err) {
                        var detail = extractError(err);
                        notification.alert('Error queuing autotranslate task for ' + target + ': ' + detail);
                        return null;
                    });
                });
            });
        }
    };
});
