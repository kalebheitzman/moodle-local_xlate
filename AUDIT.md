# Audit – local_xlate (2025-11-19)

## Overall readiness
- The plugin is not production-ready. The bundle endpoint leaks course content to any authenticated user, the public web service allows arbitrary data writes, and the plugin lacks a Moodle privacy provider despite storing user identifiers. These need to be resolved before deployment.

## Blocking issues

1. **Bundle endpoint exposes translations for arbitrary courses (Security, Critical)**  
   `bundle.php` only calls `require_login()` and guards capabilities *only* when the provided `contextid` resolves to a course/module context. Anyone can hit `/local/xlate/bundle.php?lang=<code>&courseid=<victim>&contextid=1` (system context) and receive `translations`, `reviewed`, and `sourceMap` entries for that course. This discloses both translated text and normalized source strings from private courses. Enforce a capability for every request (e.g. `local/xlate:viewui`) and, when `courseid` is set, validate against `context_course::instance($courseid)` regardless of the incoming `contextid`. Also bail out entirely when `get_config('local_xlate', 'enable')` is off.

2. **`local_xlate_associate_keys` allows any logged-in user to write arbitrary keys (Security, High)**  
   `classes/external.php::associate_keys()` calls `require_login()` only. The service is exported to the official mobile service and, via `local_xlate\local\api::associate_keys_with_course()`, will create new rows in `local_xlate_key` using attacker-controlled `component` and `source` values, and link them to any `courseid`. This enables DB bloat, bogus capture data, and amplification of bundle leakage above. Require at least `local/xlate:managecourse` in the relevant course context, bound `courseid` to the caller's enrolments, and rate-limit/size-limit the payload.

3. **No privacy API provider (Compliance, High)**  
   Tables `local_xlate_course_job` (records `userid`), `local_xlate_glossary` (`created_by`), token logs, and translation tables retain user-related data, yet the plugin does not declare `\core_privacy\local\metadata\provider` / `\core_privacy\local\request\userlist`. Moodle core will flag the plugin as non-compliant and it fails GDPR export/delete flows. Add a full privacy provider describing stored data, supporting export/delete, or clearly declare `_null_provider` if you drop the user identifiers.

4. **Inline-markup capture claims are false (Functional, High)**  
   _Resolved 2025-12-05_: `amd/src/translator.js` now sanitises inline HTML with the shared whitelist during both capture (`getElementSourcePayload()`) and rendering (`translateElement()`), preserving links/emphasis while blocking unsafe tags/URLs. README/DEVELOPER were updated to describe the sanitised workflow and AMD assets rebuilt (`npx grunt amd --root=local/xlate`).

## Other notable issues

- **Capability mismatch for course managers (Functional, Medium)** – `manage.php` grants access to users with `local/xlate:managecourse`, but the AMD autotranslate button calls `local_xlate_autotranslate_course_enqueue` / `local_xlate_autotranslate_course_progress`, both of which require `local/xlate:manage`. Course managers therefore see a UI they cannot use (the service throws `require_capability` exceptions). Align the capabilities or hide the controls.
- **Severe N+1 queries & unbounded pagination (Performance, Medium)** – `manage.php` runs `$DB->get_record('local_xlate_tr', …)` for every language *per key* and lets users set `perpage` to any integer. On a site with thousands of keys the page will execute hundreds of queries and can be taken down by requesting a huge `perpage`. Clamp the `perpage` parameter (e.g. 5–200) and fetch all translations in a single query keyed by `(keyid, lang)`.
- **Missing creation timestamp when inserting keys (Data integrity, Medium)** – `local\api::create_or_update_key()` never sets `ctime` for new rows even though `manage.php` tries to order by `ctime` when the field exists. Newly captured keys therefore keep `ctime=0`, breaking ordering and any future reporting that relies on creation time. Populate `ctime` alongside `mtime` when inserting.
- **Frontend repeatedly rescans the entire DOM (Performance, Medium)** – `translator.js::run()` schedules throttle-less re-walks on `focus`, `click`, `scroll`, and via a `MutationObserver` that never disconnects. On complex courses this continuously traverses the full DOM, causing jank. Narrow the observers to the capture selectors, debounce the handlers, and stop the observer when the page is unloaded.
- **Autotranslation tasks have no deduplication or throttling (Operational, Medium)** – `local_xlate\task\autotranslate_missing_task` iterates *every* course id from `local_xlate_key_course` and immediately calls the backend synchronously with no rate limiting, while `autotranslate_course_enqueue` inserts jobs without checking whether another job for the same course is pending. It's easy to enqueue multiple overlapping jobs and overwhelm the LLM endpoint.
- **Docs claim capture is limited to managers, but AMD still sends AJAX for everyone (Compliance, Low)** – Users without `local/xlate:manage` hit `local_xlate_save_key` and only see errors, but the request still sends raw page content to the server. Consider short-circuiting the AMD module client-side when the session has no manage capability, or expose clearer messaging about the data path.

## Documentation and testing gaps

- There are no automated tests (`tests/` is empty); `TESTS.md` only describes manual runs. Key behaviours (privacy provider, bundle ACLs, translator capture) lack regression coverage.
- README and DEVELOPER.md promise inline-markup preservation and "regular users can capture safely", but neither caveat the current limitations (markup lost, unbounded data submission). Update the docs once code is fixed.
- No mention of data-processing agreements or how to vet text sent to OpenAI-compatible endpoints, even though `translation/backend.php` logs entire payloads/responses via `debugging()`, which may capture sensitive student data in logs.

## Suggested next steps

1. Lock down `bundle.php` by enforcing capabilities for every request, validating `courseid`/`contextid` pairs, and returning 403 on unauthorized combinations.
2. Require explicit capabilities in `local_xlate_associate_keys`, add payload size limits, and consider removing it from the mobile service unless the data path is audited.
3. Implement a full GDPR privacy provider covering every table that stores user identifiers or learner content.
4. Fix the translator runtime to either genuinely support inline markup (with sanitisation) or adjust capture/application to operate on plain text consistently.
5. Address Manage UI scalability (bounded pagination, batched queries) and align exposed UI controls with service capabilities.
6. Add at least smoke-level PHPUnit/Behat coverage for the bundle ACLs, custom field helper, and translator AMD configuration so regressions are caught automatically.
