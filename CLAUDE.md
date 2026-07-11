# CLAUDE.md — Local Xlate Plugin

This file is the authoritative reference for AI-assisted development on the `local_xlate` Moodle plugin. Read it in full before making any changes. Many files are tightly coupled; a change in one place will ripple elsewhere.

---

## 1. Project Overview

`local_xlate` is a client-side automatic translation plugin for Moodle 5+. It injects a JavaScript bootloader into every eligible page that:

1. **Captures** untranslated DOM text from the source language (when browsing as a manager in the source language).
2. **Serves** cached translation bundles to visitors browsing in a target language.
3. **Translates** page content in-browser without modifying Moodle's database content.

The AI translation pipeline uses an OpenAI-compatible endpoint. A human review workflow in `manage.php` lets admins inspect, edit, and approve AI-generated translations before they go live.

Plugin component name: `local_xlate`
Current version: `2026022500` (see [version.php](version.php))
Moodle requirement: 5.0+ (`requires = 2025000000`)
Maturity: ALPHA

---

## 2. Directory Structure

```
local/xlate/
├── amd/
│   ├── src/                         # Source AMD JS (ES5-compatible)
│   │   ├── translator.js            # Core DOM capture/translation engine (~1500 lines)
│   │   ├── manage.js                # Admin UI AJAX helpers
│   │   ├── edit.js                  # Translation inspector overlay
│   │   └── langswitcher.js          # Language selector widget
│   └── build/                       # Compiled/minified JS (auto-generated, commit these)
├── classes/
│   ├── hooks/
│   │   └── output.php               # Moodle output hooks (head/body injection)
│   ├── local/
│   │   ├── api.php                  # Core data layer (bundles, keys, cache)
│   │   ├── course_job_manager.php   # Course autotranslate job queuing
│   │   ├── activity_logger.php      # Audit log writer
│   │   └── translation_cleanup.php  # Stale-data helpers
│   ├── task/
│   │   ├── autotranslate_missing_task.php  # Scheduled task (*/5 min)
│   │   ├── translate_batch_task.php         # Adhoc batch AI translation
│   │   ├── translate_course_task.php        # Adhoc per-course translation
│   │   ├── mlang_cleanup_task.php           # Scheduled MLang cleanup (*/5 min — intentional, catches course imports)
│   │   ├── mlang_course_cleanup_task.php    # Adhoc course-scoped MLang cleanup (queued by observer on import)
│   │   ├── mlang_migrate.php                # Adhoc MLang migration wrapper
│   │   └── mlang_dryrun.php                 # Adhoc dry-run report
│   ├── translation/
│   │   └── backend.php              # OpenAI HTTP client, glossary injection, token logging
│   ├── admin/setting/
│   │   └── pricing.php              # Custom admin setting: token cost calculator
│   ├── external.php                 # Moodle external API endpoints (11 functions)
│   ├── customfield_helper.php       # Course custom field provisioning & config resolution
│   ├── glossary.php                 # Glossary CRUD
│   ├── observer.php                 # Event observers: course_restored/created → queue mlang_course_cleanup_task
│   └── mlang_migration.php          # MLang tag autodiscovery, migration, and translation harvesting
├── cli/                             # 14 CLI utilities (see Section 10)
├── db/
│   ├── install.xml                  # DB schema (8 tables)
│   ├── upgrade.php                  # Migration steps (bump version.php to trigger)
│   ├── access.php                   # 4 capabilities
│   ├── services.php                 # 11 web service function definitions
│   ├── tasks.php                    # Scheduled task registrations
│   ├── events.php                   # Event observer registrations (course import → mlang cleanup)
│   ├── hooks.php                    # Output hook registrations
│   └── caches.php                   # Application cache definitions
├── lang/en/local_xlate.php          # 297 English strings
├── spec/
│   ├── translate_batch_function.json        # OpenAI function-call schema
│   └── translate_batch_response_schema.json # OpenAI response validation schema
├── settings.php                     # Admin settings page
├── lib.php                          # Navigation hook (course More menu)
├── manage.php                       # Translation management UI
├── bundle.php                       # Translation bundle delivery endpoint (POST only)
├── glossary.php                     # Glossary management UI
├── queue.php                        # Job queue status UI
├── usage.php                        # Token usage analytics UI
├── activity.php                     # Translation audit log UI
├── version.php                      # Plugin version metadata
├── README.md                        # User-facing documentation
├── DEVELOPER.md                     # Expanded technical documentation
└── CONTRIBUTING.md                  # Contribution guidelines
```

---

## 3. Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 7.4+ (Moodle 5.0 standard) |
| ORM / DB | Moodle `$DB` API — MySQL/MariaDB or PostgreSQL |
| Frontend JS | AMD modules (ES5, no build transpilation needed for logic changes) |
| CSS | Bootstrap 5 via Moodle theme; one inline style block injected in `<head>` |
| Caching | Moodle application cache (`local_xlate/bundles`) + browser `localStorage` |
| AI Backend | OpenAI-compatible HTTP API (configurable endpoint, default `gpt-4o-mini`) |
| Moodle integration | Hooks API, External API, Custom Fields API, Scheduled Tasks, Capabilities |

**Important:** AMD JavaScript must stay ES5-compatible. Do NOT introduce ES6+ syntax (arrow functions, const/let, template literals, etc.) in `amd/src/` files unless the project explicitly adds a transpilation step. Always rebuild with `grunt amd` after JS changes.

---

## 4. Database Schema

Eight tables — all prefixed with `{local_xlate_*}`:

| Table | Purpose | Key Indexes |
|---|---|---|
| `local_xlate_key` | Canonical translation keys — one row per captured string | UNIQUE `(component, xkey)` |
| `local_xlate_tr` | Translations per language — one row per `(keyid, lang)` pair | UNIQUE `(keyid, lang)`, `(lang, status)` |
| `local_xlate_bundle` | Per-language version hash for cache-busting | UNIQUE `(lang)` |
| `local_xlate_key_course` | Associates keys with course IDs | UNIQUE `(keyid, courseid)`, FK→`local_xlate_key.id` CASCADE DELETE |
| `local_xlate_activity` | Audit log for translation actions | `(userid, timecreated)`, `(courseid, timecreated)`, `(action)` |
| `local_xlate_glossary` | Language pair glossary terms | `(source_lang, target_lang)` |
| `local_xlate_mlang_migration` | Provenance log for destructive MLang tag migrations | `(tablename, recordid)` |
| `local_xlate_course_job` | Course-level autotranslation job tracking | `(courseid)`, `(status)` |
| `local_xlate_token_batch` | AI API token usage per batch request | `(timecreated)`, `(lang)` |

### Translation status values (`local_xlate_tr.status`)
- `0` — untranslated / placeholder
- `1` — translated (AI or manual)
- `reviewed` column (0/1) — human review flag; separate from status

### Key fields
- `local_xlate_key.xkey` — 12-character base36 hash; generated as `simpleHash(extractPlainText(trim(source)))` — plain text only, HTML tags stripped, entities decoded. Same visible text = same key regardless of markup or entity encoding.
- `local_xlate_key.component` — Moodle component string (e.g. `core`, `mod_forum`, `local_xlate`)
- `local_xlate_key.critical` — 0/1 flag; critical strings get priority in UI and autotranslation
- `local_xlate_key.source` — raw source text (may contain safe inline HTML); stored separately from the hash input

**IMPORTANT — xkey algorithm history:** The algorithm was migrated from a DOM-structural hash (included parent tag, classes, element type) to the current plain-text hash. If the algorithm ever needs to change again, run `cli/rehash_keys_dryrun.php` first, then `cli/rehash_keys.php`. Both scripts must be updated to match the new JS `normalizeKeyText()` logic exactly.

---

## 5. Core Class Relationships

```
hooks/output.php
  └─ calls customfield_helper::resolve_languages()   → reads course custom fields
  └─ calls local\api::get_version()                  → reads local_xlate_bundle
  └─ injects AMD bootloader with config JSON

translator.js (AMD, browser)
  └─ reads window.__XLATE__ config injected by output.php
  └─ fetches bundle via POST → bundle.php
  └─ calls local_xlate_save_key WS → external.php::save_key()
       └─ calls local\api::save_key_with_translation()  → writes local_xlate_key + local_xlate_tr
       └─ calls local\api::update_bundle_version()      → bumps local_xlate_bundle
       └─ calls local\api::invalidate_bundle_cache()    → clears Moodle app cache
  └─ calls local_xlate_associate_keys WS → external.php::associate_keys()
       └─ calls local\api::ensure_key_course_association()

manage.php (admin UI)
  └─ calls local\api::get_keys_paginated()
  └─ calls external.php via AJAX (manage.js)
       └─ save_translation, delete_translation, autotranslate, set_critical

autotranslate_missing_task.php (scheduled)
  └─ calls customfield_helper::get_course_config() for each enabled course
  └─ calls course_job_manager::enqueue_course_job()
       └─ creates local_xlate_course_job record
       └─ queues translate_course_task adhoc task
            └─ queues translate_batch_task adhoc tasks
                 └─ calls translation/backend.php::translate_batch()
                      └─ calls OpenAI API
                      └─ writes local_xlate_tr records
                      └─ logs to local_xlate_token_batch
                      └─ bumps bundle version + invalidates cache

mlang_cleanup_task.php (scheduled)
  └─ calls mlang_migration.php::migrate()
       └─ discovers text columns across all Moodle tables
       └─ strips/replaces {mlang}...{mlang} and <span class="multilang"> tags
       └─ logs provenance to local_xlate_mlang_migration
```

---

## 6. Capability Model

Four capabilities — always check before assuming access:

| Capability | Context | Who Needs It | Grants |
|---|---|---|---|
| `local/xlate:manage` | System | Site admins, managers | Full access: manage UI, glossary, bundle rebuild, capture writes, inspector |
| `local/xlate:managecourse` | Course | Course-level managers | Manage translations for one course; "Manage Translations" link in course More menu |
| `local/xlate:viewbundle` | Course | All enrolled users (default) | Receive translation bundles for course pages |
| `local/xlate:viewsystem` | System | Site managers, front-page roles | Receive translation bundles for system-context pages |

**Ripple effect:** Revoking `viewbundle` from a role disables translation delivery for that role's members — translations simply won't load.

---

## 7. Page Injection Logic (Critical — Read Before Changing `output.php`)

The `before_head()` and `before_body()` hooks in [classes/hooks/output.php](classes/hooks/output.php) guard injection with multiple layers. Injection is **skipped** when ANY of the following is true:

1. Plugin setting `enable` is false
2. Page layout is one of: `admin`, `maintenance`, `popup`, `report`, `coursecategory`
3. URL path matches any entry in the `excluded_paths` setting (or the built-in default list below)
4. User has editing mode active (`$page->user_is_editing()` or `?edit=1`)
5. Page context is `CONTEXT_SYSTEM` and pagetype is not `site-index`
6. Course has `xlate_enable` custom field set to false

**Default excluded paths** (hardcoded fallback when config is empty):
```
/admin/, /local/xlate/, /course/edit.php, /course/editsection.php,
/course/modedit.php, /course/mod.php, /course/modsection.php,
/grade/edit/, /backup/, /restore/, /report/, /user/edit.php,
/user/editadvanced.php, /user/preferences.php, /question/edit.php,
/cohort/edit.php, /badges/edit.php, /enrol/
```

**Ripple:** Any change to skip conditions in `output.php` affects whether the translator loads at all on that page type. The inspector overlay has an additional capability gate (`can_use_inspector()`).

---

## 8. Bundle System & Caching

The bundle is the JSON payload delivered to the browser containing all translations for a given language.

**Flow:**
1. `bundle.php` handles POST requests. Requires `sesskey` + capability check.
2. Calls `local\api::get_keys_bundle()` — queries `local_xlate_key` JOIN `local_xlate_tr`.
3. Result is cached in Moodle application cache `local_xlate/bundles` (1-hour TTL).
4. Browser caches bundle in `localStorage` keyed as `xlate:<lang>:<version>`.
5. When any translation is saved/deleted, `api::update_bundle_version()` bumps the SHA1 hash in `local_xlate_bundle` AND `api::invalidate_bundle_cache()` clears the Moodle cache — forcing fresh fetch on next page load.

**Key methods in `classes/local/api.php`:**

| Method | Purpose |
|---|---|
| `get_keys_bundle($lang, $keys, $context, $pagetype, $courseid)` | Primary bundle fetch — returns `{translations, sources, reviewed, critical, keyids}` |
| `save_key_with_translation(...)` | Transactional upsert for key + translation; auto-bumps bundle version |
| `update_bundle_version($lang)` | Stores new SHA1 hash + timestamp in `local_xlate_bundle` |
| `invalidate_bundle_cache($lang)` | Purges Moodle application cache entry for language |
| `get_version($lang)` | Returns current version hash (or `'dev'` if none exists) |
| `get_keys_paginated(...)` | Powers `manage.php` search + pagination |
| `ensure_key_course_association($keyid, $courseid)` | Upserts `local_xlate_key_course` row |

**Ripple:** Any change that writes to `local_xlate_tr` or `local_xlate_key` **must** call `update_bundle_version()` and `invalidate_bundle_cache()` for the affected language(s), otherwise stale translations will be served from cache.

---

## 9. JavaScript AMD Modules

All JS lives in `amd/src/`. Must be rebuilt after any change.

### `translator.js` (main engine)

Responsibilities:
- Reads `window.__XLATE__` config (injected by `output.php`)
- Scans DOM for text nodes + `placeholder`, `title`, `alt`, `aria-label` attributes
- Generates 12-char base36 keys: `simpleHash(extractPlainText(getElementSourcePayload(element)))` — plain text only, no DOM structural context
- **Capture mode** (source lang + has manage capability): tags DOM elements, calls `local_xlate_save_key` WS
- **Translation mode** (target lang): replaces DOM content with bundle translations; uses `setElementHtml()` which preserves `.accesshide` children
- `MutationObserver` watches for dynamic content changes (SPAs, AJAX-loaded blocks)
- `sanitizeTranslationHtml()` whitelist: `a, em, strong, span, code, br, sub, sup, mark, abbr, i, b, u, s, small, ins, del`
- Edit mode: completely disabled when `isEditing=true` (no capture, no tagging)
- localStorage bundle caching with version-based cache busting

**Key generation details — critical gotchas:**
- `getElementSourcePayload()` clones the element and strips `.accesshide` descendants before extracting source text. Moodle injects `.accesshide` spans with the browsing-language activity-type name (e.g. "Book" in EN, "Книга" in RU) — including them would produce different keys per language. **Never remove this stripping.**
- `normalizeKeyText()` calls `extractPlainText(text)` which uses `container.textContent` — this gives plain decoded text, making the hash immune to HTML entity encoding inconsistencies (`&amp;` vs `&` in `innerHTML` varies by browser/DOM context).
- `simpleHash()` processes supplementary Unicode characters (emoji, U+10000+) as UTF-16 surrogate pairs to match PHP's `xlate_simple_hash()`. **Both functions must stay in sync.**

**Key globals set on `window.__XLATE__`:**
- `lang` — current user language
- `sourceLang` — source language for capture
- `targetLangs` — array of configured target languages
- `version` — current bundle version hash
- `isEditing` — boolean, disables all capture/tagging
- `bundleurl` — URL for bundle fetch (includes sesskey)

### `manage.js` (admin UI)

- AJAX calls for save, delete, autotranslate, set_critical actions on translation rows
- Progress polling for autotranslation jobs
- Toast notifications
- Relies on data attributes on form elements to find parameters (key, lang, courseid, etc.)

### `edit.js` (inspector overlay)

- Highlights elements with `data-xlate-key-*` attributes on hover
- Shows attribute chips (text/placeholder/title/alt/aria-label)
- "Copy key" and "Open in manage.php" actions
- Only loads when `can_use_inspector()` is true (via `output.php`)
- Reads `data-xlate-key-*` metadata — no extra AJAX required

### `langswitcher.js` (language selector)

- Renders language switcher dropdown from `window.XLATE_LANG_SWITCHER` config
- Translation toggle (show original vs translated)
- Inspector enable/disable button

**Ripple:** Changes to the config shape passed from `output.php` to the AMD bootloader will break `translator.js`, `langswitcher.js`, and `edit.js`. The config objects must match exactly.

---

## 10. AI Translation Pipeline

### OpenAI settings (stored in `config_plugins` table)

| Setting key | Description | Default |
|---|---|---|
| `local_xlate/autotranslate_enabled` | Enable AI translation features | 0 |
| `local_xlate/autotranslate_task_enabled` | Enable nightly scheduled task | 0 |
| `local_xlate/openai_endpoint` | API endpoint URL | `https://api.openai.com/v1/chat/completions` |
| `local_xlate/openai_api_key` | API key (masked in UI) | empty |
| `local_xlate/openai_model` | Model identifier | `gpt-4o-mini` |
| `local_xlate/openai_prompt` | Editable system prompt | (default text) |

### `classes/translation/backend.php`

- Builds OpenAI function-calling payloads using schema from `spec/translate_batch_function.json`
- Injects glossary terms into system prompt via `classes/glossary.php::get_pairs_for_prompt()`
- Validates response: JSON parsing, control character detection, placeholder preservation
- Logs token usage to `local_xlate_token_batch`
- Returns array of `{id, translated, applied_glossary_terms, warnings, confidence}`

### Scheduled Autotranslation (`task/autotranslate_missing_task.php`)

- Runs nightly (configurable in Moodle admin)
- Only enabled when `autotranslate_task_enabled = 1`
- For each course with Xlate enabled: derives target language list, finds keys with missing translations, enqueues `translate_course_task` adhoc tasks (batch size: 20 default)
- Never overwrites existing translations

### Course Job Manager (`classes/local/course_job_manager.php`)

- Creates `local_xlate_course_job` record (status: pending → running → complete)
- Enqueues `translate_course_task` adhoc task
- Job progress queryable via `local_xlate_autotranslate_course_progress` WS

---

## 11. Course Language Configuration

Course-level language settings live in Moodle custom fields provisioned by `classes/customfield_helper.php`. The custom field category is named "Xlate".

| Custom field | Type | Purpose |
|---|---|---|
| `xlate_enable` | checkbox | Master on/off toggle for the course |
| `xlate_source_lang` | select | Source language for capture |
| `xlate_target_<code>` | checkbox (one per enabled language) | Target languages for this course |

**`customfield_helper` key methods:**

| Method | Purpose |
|---|---|
| `ensure_category()` | Creates the Xlate custom field category and fields if missing |
| `is_course_enabled($courseid)` | Returns bool — used as gating condition everywhere |
| `get_course_config($courseid)` | Returns `{source, targets, enabled}` |
| `resolve_languages($courseid)` | Returns effective `{source, targets, enabled}` merged with site settings |

**Ripple:** Every runtime component — `output.php` hooks, scheduled tasks, CLI tools, and `bundle.php` — calls `customfield_helper::is_course_enabled()` or `get_course_config()`. If the custom field category/fields are missing (e.g. after a `recreate_customfields.php` run), all courses will appear disabled.

---

## 12. Web Services (`classes/external.php`)

Defined in `db/services.php`. All require sesskey validation + capability checks.

| Function name | Capability | Description |
|---|---|---|
| `local_xlate_save_key` | `local/xlate:manage` | Capture/upsert key + translation; bumps bundle version |
| `local_xlate_save_translation` | `local/xlate:manage` | Save translation from admin UI |
| `local_xlate_delete_translation` | `local/xlate:manage` | Delete translation for key/lang |
| `local_xlate_set_critical` | `manage` or `managecourse` | Toggle critical flag on key |
| `local_xlate_get_key` | `local/xlate:viewui` | Retrieve key metadata |
| `local_xlate_rebuild_bundles` | `local/xlate:manage` | Rebuild all bundle version hashes |
| `local_xlate_associate_keys` | `manage` or `managecourse` | Associate keys with course; max 200 per batch |
| `local_xlate_autotranslate` | `local/xlate:manage` | Queue batch AI autotranslation |
| `local_xlate_autotranslate_key` | none | Inline autotranslate single key |
| `local_xlate_autotranslate_progress` | `local/xlate:viewui` | Poll batch job progress |
| `local_xlate_autotranslate_course_enqueue` | `manage` or `managecourse` | Enqueue course-level translation job |
| `local_xlate_autotranslate_course_progress` | `manage` or `managecourse` | Poll course job progress |

**Ripple:** If you rename or change the signature of any external function, you must update: `db/services.php` (metadata), `classes/external.php` (implementation), and any JS caller in `amd/src/`.

---

## 13. MLang Migration

Legacy Moodle content uses `{mlang xx}...{mlang}` tags and `<span lang="xx" class="multilang">` tags (any attribute order/quoting tolerated). The migration tooling strips these IN PLACE — each tag is replaced at its own position by the preferred language's content — and **harvests** the non-source-language content into `local_xlate_tr` as reviewed translations (source `mlang`) keyed by the same plain-text xkey the JS capture pipeline generates. Harvesting never overwrites an existing `(key, lang)` translation row.

**Why cleanup is recurring, not one-time:** courses carrying legacy mlang tags are imported into this site continuously. Cleanup is event-driven (course import/creation queues a course-scoped adhoc task) with the `*/5` scheduled task as a site-wide safety net. Do NOT "fix" the recurring schedule by disabling it.

**Key files:**
- [classes/mlang_migration.php](classes/mlang_migration.php) — core logic: autodiscovery, dry-run, migrate, `harvest_translations()`, tolerant span parsing (`SPAN_PATTERN` + `parse_multilang_span()`)
- [classes/observer.php](classes/observer.php) + [db/events.php](db/events.php) — `course_restored`/`course_created` → queue adhoc cleanup for that course
- [classes/task/mlang_course_cleanup_task.php](classes/task/mlang_course_cleanup_task.php) — adhoc course-scoped cleanup (re-checks `is_course_enabled()` at run time)
- [classes/task/mlang_cleanup_task.php](classes/task/mlang_cleanup_task.php) — scheduled site-wide cleanup (`*/5`, capped by `mlang_cleanup_batch_size`)
- [cli/mlang_migrate.php](cli/mlang_migrate.php) — CLI runner

When the `courseids` option is set, the course filter is pushed into SQL (`course`/`courseid IN (...)`) and tables without a course column are skipped. Chunking uses `$DB->get_records_sql(..., 0, $chunk)` (no raw `LIMIT`).

**Known limitation:** the span matcher's non-greedy body stops at the first `</span>`, so multilang spans containing nested spans are truncated. Multilang spans conventionally wrap inline text only.

**`block_instances.configdata`** is special: the script base64-decodes + unserializes, recursively scans all string fields, re-serializes + base64-encodes before saving. (No harvesting on this path.)

Every migration change is logged to `local_xlate_mlang_migration` (old_value, new_value, migrated_at, migrated_by).

**DANGER:** `--execute` flag performs destructive irreversible DB changes. Always take a DB backup first. Always dry-run first.

---

## 14. Admin UI Pages

| URL | File | Capability | Purpose |
|---|---|---|---|
| `/local/xlate/manage.php` | [manage.php](manage.php) | `manage` or `managecourse` | Search, filter, edit, review translations |
| `/local/xlate/glossary.php` | [glossary.php](glossary.php) | `local/xlate:manage` | Manage glossary term pairs |
| `/local/xlate/queue.php` | [queue.php](queue.php) | `local/xlate:manage` | View autotranslation job queue |
| `/local/xlate/usage.php` | [usage.php](usage.php) | `local/xlate:manage` | Token usage and cost analytics |
| `/local/xlate/activity.php` | [activity.php](activity.php) | `local/xlate:manage` | Translation audit log |
| `/local/xlate/bundle.php` | [bundle.php](bundle.php) | `viewbundle`/`viewsystem` | Translation bundle endpoint (POST only) |

---

## 15. CLI Tools

All CLI tools run as `sudo -u www-data php local/xlate/cli/<script>.php`.

| Script | Purpose |
|---|---|
| `mlang_migrate.php` | Dry-run or execute MLang tag migration (`--execute`, `--max=N`, `--preferred=<lang>`) |
| `autotranslate_dryrun.php` | Preview which courses/languages would autotranslate |
| `list_translatable_courses.php` | Show courses ready for translation with their config |
| `queue_course_job.php` | Manually enqueue a course autotranslate job |
| `inspect_job.php` | Show status + progress of a course job |
| `show_new_translations.php` | Display recently generated translations |
| `list_adhoc.php` | List queued adhoc tasks |
| `run_adhoc_process.php` | Manually trigger an adhoc task |
| `sync_source_language_indices.php` | Repair custom field integer→locale mapping after option order change |
| `recreate_customfields.php` | Drop and rebuild Xlate custom field category (dev only) |
| `truncate_xlate_tables.php` | Reset all `local_xlate_*` tables (`--dry-run` first!) |
| `cleanup_translation_tags.php` | Clean stale HTML tags in translation records |
| `analyze_html_selectors.php` | Report on CSS selectors used in capture |
| `find_key.php` | Search for keys by source text |
| `rehash_keys_dryrun.php` | Preview xkey migration — shows counts, merges, conflicts. No DB changes. |
| `rehash_keys.php` | Execute xkey migration — recomputes all xkeys, merges duplicates, preserves reviewed translations. **Take DB backup first.** |
| `repair_course_associations.php` | Create missing `local_xlate_key_course` rows for a course. Fixes keys captured on system-context pages (courseid=0) that are invisible to autotranslation and manage.php courseid filters. `--global-only` restricts to keys with zero existing associations. `--key=XKEY` targets one key. `--enqueue` queues autotranslation after repair. |

---

## 16. Common Commands

```bash
# Install / upgrade the plugin
php admin/cli/upgrade.php

# Purge all Moodle caches (run after any PHP or AMD JS change)
php admin/cli/purge_caches.php

# Rebuild AMD JS bundles (run after any amd/src/*.js change)
# Must run from Moodle root (where Gruntfile.js lives)
grunt amd --root=local/xlate --force

# Run the nightly autotranslation task manually
sudo -u www-data php admin/cli/scheduled_task.php \
  --execute='\local_xlate\task\autotranslate_missing_task'

# Run the MLang cleanup task manually
sudo -u www-data php admin/cli/scheduled_task.php \
  --execute='\local_xlate\task\mlang_cleanup_task'

# Preview MLang migration (dry-run, always do this first)
sudo -u www-data php local/xlate/cli/mlang_migrate.php

# Apply MLang migration (DESTRUCTIVE — take DB backup first)
sudo -u www-data php local/xlate/cli/mlang_migrate.php --execute

# Enqueue autotranslation for a specific course
sudo -u www-data php local/xlate/cli/queue_course_job.php --courseid=42

# Preview which courses would autotranslate
sudo -u www-data php local/xlate/cli/autotranslate_dryrun.php

# Reset all Xlate data (DEV ONLY — dry-run first)
sudo -u www-data php local/xlate/cli/truncate_xlate_tables.php --dry-run
sudo -u www-data php local/xlate/cli/truncate_xlate_tables.php

# Recreate course custom fields (DEV ONLY)
sudo -u www-data php local/xlate/cli/recreate_customfields.php

# Preview xkey hash migration (always run first)
sudo -u www-data php local/xlate/cli/rehash_keys_dryrun.php
sudo -u www-data php local/xlate/cli/rehash_keys_dryrun.php --verbose

# Run xkey hash migration (TAKE DB BACKUP FIRST)
sudo -u www-data php local/xlate/cli/rehash_keys.php

# Investigate a specific translation key (shows source, component, course associations, translations)
sudo -u www-data php local/xlate/cli/find_key.php --key=81sjhr1ym7ht

# Preview missing course associations for a course
sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=42 --dry-run

# Repair only global keys (no existing associations) — safest
sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=42 --global-only

# Repair all missing associations and enqueue autotranslation in one step
sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=42 --enqueue

# Repair a single specific key for a course
sudo -u www-data php local/xlate/cli/repair_course_associations.php --courseid=42 --key=81sjhr1ym7ht --enqueue
```

---

## 17. Ripple Effect Map (Change Impact Reference)

This section documents which files depend on which others. Before changing any file, consult this map.

### Changing `db/install.xml`
- Must add a corresponding step in `db/upgrade.php`
- Must bump `$plugin->version` in `version.php`
- Run `php admin/cli/upgrade.php` after deploying

### Changing `classes/local/api.php`
- Affects: `classes/external.php`, `bundle.php`, `manage.php`, all scheduled tasks, all CLI tools
- If changing `save_key_with_translation()`: verify bundle version bump + cache invalidation still happens
- If changing `get_keys_bundle()` return shape: update all callers (especially `bundle.php`)

### Changing `classes/hooks/output.php`
- Affects: what pages get translation injected — test all page types
- The config JSON passed to `translator.init()` must match the shape expected by `amd/src/translator.js`
- The `languageSwitcher` config must match `amd/src/langswitcher.js`
- The `inspectorconfig` object must match `amd/src/edit.js`
- `should_skip_page()` changes affect every page in the site

### Changing `amd/src/translator.js`
- Must rebuild: `grunt amd --root=local/xlate --force`
- Key generation algorithm changes will **invalidate all existing captured keys** in the DB — requires running `cli/rehash_keys.php` after deployment
- If changing `normalizeKeyText()` or `simpleHash()`: the PHP equivalents in `cli/rehash_keys.php` and `cli/rehash_keys_dryrun.php` (`xlate_simple_hash` + normalization) **must be updated to match exactly**
- If changing `getElementSourcePayload()`: verify that `.accesshide` stripping is preserved — removing it causes hash mismatches between source and target language pages
- Changes to `sanitizeTranslationHtml()` whitelist affect both capture storage and translation rendering
- Config property name changes must be mirrored in `output.php::before_body()`

### Changing `classes/external.php`
- Must update `db/services.php` if function signature changes
- Any JS caller in `amd/src/` must be updated
- `manage.js` uses several of these functions directly

### Changing `classes/customfield_helper.php`
- Affects: `output.php`, all tasks, all CLI tools, `manage.php`, `bundle.php`
- `is_course_enabled()` is the primary gating condition — changing it affects the whole plugin
- Custom field option order changes require running `cli/sync_source_language_indices.php`

### Changing `classes/translation/backend.php`
- Affects: `translate_batch_task.php`, `translate_course_task.php`
- Response parsing changes must align with `spec/translate_batch_response_schema.json`
- Token logging changes affect `usage.php` display

### Changing `db/services.php`
- Run `php admin/cli/purge_caches.php` after changes
- If adding new services, also add the implementation in `classes/external.php`

### Changing `settings.php`
- New settings need corresponding `get_config('local_xlate', 'setting_name')` calls
- Settings used in JS need to be passed through `output.php::before_body()`

### Changing `lang/en/local_xlate.php`
- String keys used in PHP must match exactly
- String keys used in JS (via `output.php` passing them as JSON) must match

---

## 18. Debugging Checklist

1. **Is the plugin enabled?** Check: Site admin → Plugins → Local plugins → Xlate → `enable` setting
2. **Is translation loading on the page?**
   - View page source: look for `<!-- XLATE HEAD HOOK FIRED -->` in `<head>` and `<!-- XLATE BODY HOOK FIRED -->` near `<body>`
   - If missing, check `should_skip_page()` conditions in `output.php`
3. **Is the course enabled?**
   - Check course custom fields (course settings → "Xlate" section)
   - Run `cli/list_translatable_courses.php` to see all enabled courses
4. **Browser console:**
   - `window.__XLATE__` — inspect full config
   - Look for `[XLATE]` prefixed log messages
   - Edit mode: `[XLATE] Edit mode detected (isEditing=true): skipping translation/capture logic.`
5. **Network tab:**
   - Bundle fetch: POST to `/local/xlate/bundle.php`
   - Capture: POST to Moodle WS endpoint with `wsfunction=local_xlate_save_key`
6. **Database:**
   - `SELECT * FROM {local_xlate_key} LIMIT 20;` — check captured keys
   - `SELECT * FROM {local_xlate_tr} WHERE lang='es' LIMIT 20;` — check translations
   - `SELECT * FROM {local_xlate_bundle};` — check version hashes
7. **Cache issues:** Run `php admin/cli/purge_caches.php` then hard-refresh browser

---

## 19. Security Model

- `bundle.php`: POST-only; requires `sesskey`; capability-gated per context (`viewbundle` for courses, `viewsystem` for system)
- All external API functions use `require_capability()` before acting
- `associate_keys`: rejects batches > 200 keys; requires enrolment for course-scoped calls
- HTML in translations: sanitized through `sanitizeTranslationHtml()` whitelist in `translator.js` (both on capture and on render)
- URL attributes (`href`, `src`): validated/sanitized
- Database queries: all use parameterized queries via Moodle `$DB` API
- API keys: stored via Moodle's `admin_setting_configpasswordunmask` (masked in UI)
- **Never log or expose `openai_api_key` in output, logs, or error messages**

---

## 20. Development Workflow

1. **Make PHP changes** → Run `php admin/cli/purge_caches.php`
2. **Make JS changes** (`amd/src/*.js`) → Run `grunt amd --root=local/xlate --force` → Run `php admin/cli/purge_caches.php`
3. **Make DB schema changes** → Update `db/install.xml` + `db/upgrade.php` + bump `version.php` → Run `php admin/cli/upgrade.php`
4. **Add new settings** → Update `settings.php` + add `get_config()` calls + pass to JS in `output.php` if needed
5. **Add new language strings** → Update `lang/en/local_xlate.php`
6. **Add new web service** → Update `db/services.php` + `classes/external.php` + purge caches

---

## 21. Key File Quick Reference

| What you want to change | File |
|---|---|
| When/whether translation loads on a page | [classes/hooks/output.php](classes/hooks/output.php) |
| DOM capture / translation rendering logic | [amd/src/translator.js](amd/src/translator.js) |
| Admin translation management UI | [manage.php](manage.php) + [amd/src/manage.js](amd/src/manage.js) |
| Translation inspector overlay | [amd/src/edit.js](amd/src/edit.js) |
| Language switcher widget | [amd/src/langswitcher.js](amd/src/langswitcher.js) |
| Core data layer (bundles, keys, cache) | [classes/local/api.php](classes/local/api.php) |
| Web service endpoints | [classes/external.php](classes/external.php) + [db/services.php](db/services.php) |
| AI translation HTTP client | [classes/translation/backend.php](classes/translation/backend.php) |
| Course language config / custom fields | [classes/customfield_helper.php](classes/customfield_helper.php) |
| Glossary management | [classes/glossary.php](classes/glossary.php) + [glossary.php](glossary.php) |
| MLang tag migration | [classes/mlang_migration.php](classes/mlang_migration.php) |
| Nightly autotranslation task | [classes/task/autotranslate_missing_task.php](classes/task/autotranslate_missing_task.php) |
| Course-level job queueing | [classes/local/course_job_manager.php](classes/local/course_job_manager.php) |
| Plugin admin settings | [settings.php](settings.php) |
| Navigation (course More menu link) | [lib.php](lib.php) |
| DB schema | [db/install.xml](db/install.xml) + [db/upgrade.php](db/upgrade.php) |
| Capabilities | [db/access.php](db/access.php) |
| Hook registrations | [db/hooks.php](db/hooks.php) |
| Scheduled task registrations | [db/tasks.php](db/tasks.php) |
| Language strings | [lang/en/local_xlate.php](lang/en/local_xlate.php) |
| OpenAI function-call schema | [spec/translate_batch_function.json](spec/translate_batch_function.json) |
