# Developer Notes

## Contributing
See `CONTRIBUTING.md` for coding standards, pull request workflow, and how to file issues. All contributions and bug reports are welcome!

## 1. Purpose & Flow
`local_xlate` brings LocalizeJS-style client translations to Moodle 5+. The
plugin injects a bootloader, serves immutable JSON bundles, and runs an AMD
module that translates DOM nodes (and can capture new strings) without touching
core or theme code. Each translated element receives a stable, structure-based `data-xlate-key` attribute so minor source edits do not invalidate translations. Keys are 12-character hashes based on element structure, class, region, and text (see below).

### Structural Key Generation (Algorithm & Rationale)
Keys are generated as 12-character base36 hashes from a composite string of tag name, class names, region, type, and text. This ensures:
- **Resilience to text changes** (minor edits/typos do not break mapping)
- **Context-awareness** (same text in different locations gets different keys)
- **Compactness** (12 chars, always injected as `data-xlate-key-*`)
- **Determinism** (same structure always generates same key)

See the end of this section for a full example and testing steps.

### High-level runtime flow

```
DB (local_xlate_key + local_xlate_tr)
  → local_xlate\local\api::get_bundle()
  → /local/xlate/bundle.php
  → browser localStorage (xlate:<lang>:<version>)
  → AMD translator (translation + optional capture)
  → local_xlate_save_key web service (capture only)
  (If page is in edit mode, capture is always disabled)
```

## 2. Architecture Overview
- **Hooks** (`classes/hooks/output.php`) inject anti-FOUT CSS and a bootloader
  via Moodle’s hook system (`db/hooks.php`). Hooks are skipped on admin pages and in edit mode to preserve core behaviour and prevent unwanted capture.
- **Bundle endpoint** (`bundle.php`) wraps `local_xlate\local\api`, returning
  language bundles (`{translations, sourceMap}`) with `Cache-Control: public,
  max-age=31536000, immutable`. Supports POST with `{keys: [...]}` to return only translations for keys found on the page (used for efficient capture and translation).
- **Client runtime** (`amd/src/translator.js`) applies translations, stores state
  on `window.__XLATE__`, observes DOM mutations, and can capture untranslated
  text when enabled. If the page is in edit mode (`isEditing` flag from PHP), all capture and tagging logic is skipped and a console message is logged.
- **Server APIs** (`classes/local/api.php`, `classes/external.php`) provide CRUD,
  cache invalidation, and web services consumed by the AMD module and admin UI.

### Expanded system diagram

```
                                          +------------------------------+
                                          | Site admin settings          |
                                          | (enable, OpenAI creds, etc.) |
                                          +---------------+--------------+
                                                          |
                                                          v
+------------------------------+              +-----------+-------------+
| Course custom fields         |<-------------+ classes/customfield_    |
| (source select + targets)    |  provision   | helper::ensure_category()|
+----------------------+-------+              +--------------------------+
                       | resolve config via helper
                       v
+----------------------+--------------------+             +---------------------------+
| classes/hooks/output.php (render gating) |----config--->| AMD bootloader config JS  |
+----------------------+--------------------+             +---------------------------+
                       |                                          |
                  inject CSS/JS                                   v
                       v                          +---------------------------------+
            +----------+-----------+              | amd/src/translator.js + capture |
            | Moodle page request |--------------> applies translations, optional  |
            +----------+-----------+              | capture, DOM observer, tagging  |
                       |                          +-----------------+---------------+
                       | bundle fetch (GET/POST)                    |
                       v                                            | capture posts
            +-----------------------------+                         v
            | bundle.php + local\api      |<-------------------+ classes/external.php
            | (cache, sourceMap, version) |                    |  (save key, rebuild)
            +----------------------+------+
                                   |
                                   v
           +---------------------------------------------+
           | DB tables: local_xlate_key/tr/bundle/...    |
           | + cache stores + glossary/token logs        |
           +------------------+--------------------------+
                              |
                              v
+----------------------------+-------------------------------------------+
| Scheduled task (task/autotranslate_missing_task.php)                   |
| CLI tools (autotranslate_dryrun, queue_course_job, etc.)               |
| - call customfield_helper for gating                                   |
| - enqueue adhoc translate_course_task                                  |
| - inspect token usage / new translations                               |
+----------------------------+-------------------------------------------+
                              |
                              v
+-----------------------------+------------------------------------------+
| translation/backend.php (OpenAI-compatible HTTP calls, retries, logging)|
+-----------------------------------------------------------------------+

MLang migration tooling (CLI + adhoc task) operates in parallel against
legacy content, feeding updates back into the same DB tables and bundle
versioning flow.
```

### Course language configuration & gating

- `classes/customfield_helper.php` provisions the **Xlate** course customfield category with a select (`xlate_source_lang`) and one checkbox per installed language (`xlate_target_<code>`). The helper also provides `get_course_config()` so every runtime component can resolve source/target languages consistently.
- CLI helpers:
  - `cli/recreate_customfields.php` recreates the category/fields when you need a clean slate (for example, after changing the default option order).
  - `cli/sync_source_language_indices.php` repairs existing data when the select option order changes by mapping stored integers back to real locale codes.
  - `cli/list_translatable_courses.php` and `cli/autotranslate_dryrun.php` surface per-course configuration so you can confirm which courses are ready for automation.
- Runtime gating happens in `classes/hooks/output.php` and every task/CLI that calls `customfield_helper::get_course_config()`: if a course has no source language configured, the translator hooks, scheduled task, adhoc jobs, and CLI utilities all skip it automatically.
- Administrative guardrails live in the same hook: if `$PAGE->pagelayout` is `admin/maintenance/report`, the user toggles editing, or the path matches the configurable `excluded_paths` list (newline-delimited prefixes under plugin settings), no CSS/JS is injected. The default deny list covers `/admin/`, edit forms (`/course/modedit.php`, `/course/edit.php`, etc.), grade edit screens, user profile editors, and other staff-only workflows.

## 3. Automatic Capture (AMD Module)
- Guard rails:
  - `currentLang === siteLang` (prevents recording already-translated strings).
  - **Edit mode disables capture**: If the page is in edit mode, capture/tagging is always skipped (see `isEditing` flag in JS config).
  - Capability check happens via `local_xlate_save_key`
    (`local/xlate:manage`).
  - The associate-only web service (`local_xlate_associate_keys`) intentionally skips capability checks and only calls `require_login()` so regular users can help populate source strings while browsing.
  - No manual or toggle: keys are always auto-assigned by the JS.
- Captured text includes text nodes plus `placeholder`, `title`, `alt`, and `aria-label`
  attributes. Stable keys combine detected component, element type, normalized
  text, and a short hash. See `KEY_GENERATION.md` for full details.
- Inline markup from the DOM (anchor tags, emphasis, spans, etc.) is preserved
  when creating the captured string so translation prompts keep semantic hints
  instead of reducing everything to plain text. When applying translations in
  non-source languages the AMD module sanitizes translator-supplied HTML
  against a curated inline-tag whitelist (links, emphasis, spans, code, etc.)
  and strips unknown tags/unsafe attributes before calling `innerHTML`, keeping
  the promise of markup fidelity without risking script injection.
- Successful capture updates Moodle DB, bumps the bundle version, and invalidates
  caches so subsequent requests fetch fresh bundles.

## 4. Bundle & Cache Layer (`classes/local/api.php`)
* Bundle endpoint supports both GET (full bundle) and POST (filtered by keys array) for efficient translation and capture.

| Method | Purpose |
| ------ | ------- |
| `get_bundle($lang)` | Returns `{translations, sourceMap}`. Cached in Moodle application cache (1-hour TTL). |
| `get_version($lang)` | Reads `local_xlate_bundle.version` (`'dev'` fallback). |
| `generate_version_hash($lang)` | `sha1("<lang>:<max mtime>")`; used for cache busting. |
| `update_bundle_version($lang)` | Stores new hash + timestamp. |
| `invalidate_bundle_cache($lang)` | Clears the cached bundle for the language. |
| `save_key_with_translation(...)` | Transactional helper for capture/admin UI. |
| `get_keys_paginated(...)` | Supports `manage.php` search and pagination. |

`bundle.php` serves the full bundle by default, but POST requests with a `keys` array return only those translations. Bundles include a `sourceMap` keyed by normalised source strings to support fuzzy matching during translation.

## 5. Database Structure (`db/install.xml`)
- `local_xlate_key`: canonical keys (`component`, `xkey`, `source`, `mtime`),
  unique `(component, xkey)`.
- `local_xlate_tr`: translations (`keyid`, `lang`, `text`, `status`, `mtime`),
  unique `(keyid, lang)` with index `(lang, status)` for bundle queries.
- `local_xlate_bundle`: per-language version hashes (`lang`, `version`, `mtime`).

## 6. Configuration (`settings.php`)
- `enable`: master switch controlling hook injection.
- `autodetect`: intended to toggle capture mode (needs JS wiring).
- `enabled_languages`: list used for coverage reporting within `manage.php`.
- `component_mapping`: newline-delimited hints that nudge component detection for
  captured strings.
- `excluded_paths`: newline-delimited path prefixes (e.g. `/admin/`, `/course/modedit.php`) that force the translator to stay disabled on those routes.
 - “Manage Translations” button links to `/local/xlate/manage.php` (requires
   `local/xlate:manage` or `local/xlate:managecourse` when scoped to a course). The admin area also exposes `/local/xlate/glossary.php` (labelled "Xlate: Manage Glossary") for managing glossary entries; it is protected by `local/xlate:manage` by default.

### Glossary implementation notes

- `local_xlate_glossary` stores mappings with `ctime` (creation time) and `mtime` (last modified). The column was renamed from `created_at` to `ctime` during recent updates to align with other timestamp columns.
- `classes/glossary.php` provides helpers such as `get_sources()`, `get_translations_for_source()` and `save_translation()`.
  - `get_sources()` groups by `source_lang, source_text` and returns results keyed by a unique `id` (MIN(id)) so Moodle's `$DB->get_records_sql()` does not raise duplicate-key exceptions when the first selected column would otherwise be non-unique.
  - SQL comparisons against `source_text` use `$DB->sql_compare_text('source_text')` to ensure compatibility with DB backends (for example PostgreSQL) that disallow direct equality comparisons on TEXT columns.

### OpenAI / Autotranslation (developer notes)

- The plugin now exposes site settings for an OpenAI-compatible endpoint and related options. Settings stored in `config_plugins` include:
  - `local_xlate/autotranslate_enabled` (bool)
  - `local_xlate/openai_endpoint` (URL)
  - `local_xlate/openai_api_key` (masked)
  - `local_xlate/openai_model` (text, default `gpt-5`)
  - `local_xlate/openai_prompt` (textarea, editable system prompt)

- For production use implement a translation backend wrapper (suggested path `classes/translation/backend.php`) that:
  - Reads the admin settings and constructs requests (model, temperature, timeout).
  - Implements retries with exponential backoff and maps provider errors to friendly messages.
  - Validates and sanitizes input (remove/exclude sensitive fields) and enforces per-request size limits.
  - Optionally injects glossary terms into the system prompt or post-processes model output to prefer glossary mappings.

Be careful with privacy and cost: sending user content externally may expose data; document and surface this to admins and provide opt-in toggles where appropriate.

Capabilities and course-level management
---------------------------------------

Two capabilities control access to the management UI and capture-related APIs:

- `local/xlate:manage` (system-level): grants full management rights across the site.
- `local/xlate:managecourse` (course-level): grants management rights for a specific course when assigned via role overrides. When a course-level manager visits a course they are granted the "Manage Translations" link in the More menu which opens `/local/xlate/manage.php?courseid=<id>`.

Code notes:
- The navigation hook is implemented in `lib.php` and checks for either capability before showing the link.
- `manage.php` now respects `courseid` and allows access when the viewer has `local/xlate:managecourse` in that course context (site managers with `local/xlate:manage` still have full access).

## Scheduled Autotranslation Task

- Class: `local_xlate\task\autotranslate_missing_task`
- Registered in `db/tasks.php`, runs nightly by default.
- Iterates every course that has an Xlate source language configured, derives the effective target list (either the course-level checkboxes or the enabled-language list minus the source), and batches missing keys per course/target (default batch size: 20) to avoid DB/API overload.
- Only fills in missing translations; never overwrites existing ones.
- Controlled by the `autotranslate_task_enabled` setting.
- Logs progress and errors via `mtrace()`.
- Can be triggered manually via CLI or from the scheduled tasks UI.
- Debug/preview tooling:
  - `cli/autotranslate_dryrun.php` shows which courses/targets would run and how many keys remain (optionally printing sample keys with `--showmissing`).
  - `cli/list_translatable_courses.php` prints the ready-to-run course matrix.
  - `cli/queue_course_job.php`, `cli/inspect_job.php`, `cli/show_new_translations.php`, and `cli/run_adhoc_process.php` help enqueue, inspect, and replay course-specific adhoc jobs.

### Token Usage Logging
  Every autotranslation batch logs token usage to the `local_xlate_token_batch` table (see `db/install.xml`).
- Each record includes timestamp, language, key, token count, model, and response time.
- Admins can view usage at `/local/xlate/usage.php`.

### Batching and Efficiency
- The task queries only for keys/languages with missing translations, not all records.
- Batch size is set in the task class and can be adjusted for your environment.

## 7. Admin Interface (`manage.php`)
- Displays automatically captured keys with search, filters, pagination, and
  per-language translation inputs.
- Manual key creation/deletion is disabled; rely on the AMD auto-detect flow to
  capture new strings. The translator writes `data-xlate-key*` attributes as it
  captures content to ensure consistent reuse.
- Visible only to users with `local_xlate:manage`; read-only access uses
  `local/xlate:viewui`.
- Tracks coverage for each language listed in `enabled_languages`.

## 8. External Services
Defined in `classes/external.php` and `db/services.php`:
| Service | Capability | Description |
| ------- | ---------- | ----------- |
| `local_xlate_save_key` | `local/xlate:manage` | Save/update key + translation, bump bundle version, clear caches. |
| `local_xlate_get_key` | `local/xlate:viewui` | Retrieve key metadata for tooling. |
| `local_xlate_rebuild_bundles` | `local/xlate:manage` | Rebuild bundle versions for languages with active translations. |
| `local_xlate_associate_keys` | _(require_login only)_ | Ensure keys exist and associate them with a course/context so source strings are captured from general traffic. |

The AMD module uses `local_xlate_save_key`; the others enable admin tooling and
external integrations.

## 9. Development Workflow
* **Edit mode/capture debugging**: To verify that capture is disabled in edit mode, enable editing on a Moodle page and check the browser console for the `[XLATE] Edit mode detected (isEditing=true): skipping translation/capture logic.` message. No keys will be tagged or saved in this mode.
1. **Install/upgrade**: place under `local/xlate` then visit *Site administration →
   Notifications* or run `php admin/cli/upgrade.php`.
2. **Build AMD assets** after editing `amd/src/*.js`:
   ```bash
   grunt amd --root=local/xlate --force
   ```
3. **Purge caches** whenever PHP/AMD code changes:
   ```bash
   php admin/cli/purge_caches.php
   ```
4. **Reset captured data (dev/test only)** with the helper CLI script:
  ```bash
  sudo -u www-data php local/xlate/cli/truncate_xlate_tables.php --dry-run
  ```
  Review the dry-run output before removing the flag; the script truncates all
  `local_xlate_*` tables and is intended strictly for safe resets in
  non-production environments.
4. **Versioning**: bump `version.php` and add steps to `db/upgrade.php` whenever
   schema or behaviour changes demand it.
5. **Code style**: follow Moodle PHP guidelines; AMD modules stay ES5-compatible
   (no transpilation pipeline).

## MLang migration tooling (developer guide)

The plugin includes a robust CLI runner and helpers to discover, dry-run, and (optionally) apply destructive cleanup of legacy MLang blocks (`{mlang ...}` and `<span lang="xx" class="multilang">...</span>`). Use these tools carefully—always run a dry-run and inspect samples before applying changes, and take a DB backup prior to running any destructive migration.

**Key features and technical details:**

- **Autodiscovery:**
  - Candidate columns are discovered by DB type (`text`, `varchar`, etc.), not by name pattern.
  - All tables with "xlate" in the name are excluded by default.
  - No hardcoded table lists; you can override with `--tables` if needed.

- **Block configdata handling:**
  - For `block_instances.configdata`, the script base64-decodes and unserializes the value.
  - All string fields (e.g., `title`) are recursively scanned for mlang tags and cleaned.
  - If any changes are made, the structure is re-serialized and base64-encoded before saving.

- **Language selection:**
  - By default, the migration uses the current site language (`sitelang`) for replacements.
  - If `sitelang` is not set, it falls back to `other`.
  - You can override with `--preferred=other` or a specific language code.

- **Provenance and logging:**
  - Every change is recorded in `local_xlate_mlang_migration` (table created on install/upgrade).
  - Each row includes `old_value`, `new_value`, `migrated_at`, and `migrated_by`.
  - Summary logs are output after each table, and a sample of changes is included in the report.

- **Error handling:**
  - Defensive checks are in place for DB errors and unserialization failures.
  - If a DB update fails, the script logs the error and continues.

- **Extending/customizing:**
  - To add more complex recursive scanning (e.g., nested arrays in configdata), extend the configdata handling logic in `mlang_migration.php`.
  - To target additional or custom fields, use the `--tables` option with a JSON file or comma-separated list.

- **CLI options:**
  - `--execute`: actually perform changes (otherwise dry-run only).
  - `--preferred=<lang>`: set preferred language for replacements.
  - `--max=<n>`: limit number of changes for staged testing.
  - `--tables=<file or list>`: override autodiscovery with specific tables/columns.

**Key files and APIs:**
- `classes/mlang_migration.php`: main migration logic, autodiscovery, configdata handling, provenance.
- `cli/mlang_migrate.php`: CLI runner, parses options and invokes migration.

**Workflow summary:**
1. Run a dry-run to discover and report all candidate columns and matches.
2. Review the JSON report and sample replacements.
3. Run a staged migration with `--max` to test a few changes.
4. Run a full migration when ready.

**Best practices:**
- Always back up your database before running with `--execute`.
- Always review the dry-run report and samples.
- Run during a maintenance window if possible.

See the code for further extension points and error handling details.
  - `discover_candidate_columns($DB, $opts)` — enumerate text-like columns.
  - `dryrun($DB, $options)` — read-only scan that writes a JSON report to
    `sys_get_temp_dir()`.
  - `migrate($DB, $options)` — destructive migration. By default it is a dry-run
    unless `execute => true` is passed. Supports `max_changes` for staged runs.
- `classes/task/mlang_migrate.php` — an adhoc task wrapper for `migrate()`.
- `cli/mlang_migrate.php` — CLI runner. Flags:
  - `--execute` apply changes (no flag = dry-run)
  - `--max=N` stop after N changes (staged run)
  - `--preferred={other|sitelang|<code>}` preferred source variant
  - `--chunk=N`, `--sample=N` performance tuning
  - `--tables=path.json` or `--tables=table:col,table2:col2` to target specific columns

Example workflow:

1. Dry-run (default):

```bash
sudo -u www-data php local/xlate/cli/mlang_migrate.php
```

2. Staged run (apply up to 5 changes):

```bash
sudo -u www-data php local/xlate/cli/mlang_migrate.php --execute --max=5
```

3. Full run (after review and backups):

```bash
sudo -u www-data php local/xlate/cli/mlang_migrate.php --execute
```

Provenance:
- On execute, each change is recorded in `local_xlate_mlang_migration` with
  `old_value`, `new_value`, `migrated_at`, and `migrated_by` for
  auditability.

Testing and CI:
- Consider adding a small integration test that seeds test rows with
  `{mlang}` content and runs a sample `migrate()` to verify provenance.


## 10. Debugging Checklist
- View page source to confirm anti-FOUT CSS (`<head>`) and bootloader script
  (`<body>`).
- Inspect `window.__XLATE__` in the browser console to verify `lang`, `siteLang`,
  and current translation map.
- Watch Network tab for bundle fetches and `local_xlate_save_key` requests.
- Hit `/local/xlate/bundle.php?lang=<code>` directly to inspect bundle output
  and headers.
- Query `local_xlate_key` / `local_xlate_tr` to ensure capture only runs in the
  default language.
- Use `example_html_output.html` for regression testing capture heuristics.

## 11. File Map
```
local/xlate/
├── amd/
│   ├── src/autotranslate.js
│   ├── src/translator.js
│   └── build/*.js, *.js.map
├── bundle.php
├── classes/
│   ├── external.php
│   ├── glossary.php
│   ├── mlang_migration.php
│   ├── translation/backend.php
│   ├── local/api.php
│   ├── hooks/output.php
│   └── task/
│       ├── mlang_cleanup_task.php   # Scheduled MLang cleanup
│       ├── mlang_dryrun.php
│       ├── mlang_migrate.php
│       ├── translate_batch_task.php
│       └── translate_course_task.php
├── cli/
│   ├── autotranslate_dryrun.php     # Preview scheduled-task inputs
│   ├── inspect_job.php
│   ├── list_adhoc.php
│   ├── list_translatable_courses.php
│   ├── mlang_migrate.php            # CLI migration runner
│   ├── queue_course_job.php
│   ├── recreate_customfields.php
│   ├── run_adhoc_process.php
│   ├── show_new_translations.php
│   ├── sync_source_language_indices.php
│   └── truncate_xlate_tables.php
├── db/
│   ├── access.php
│   ├── caches.php
│   ├── hooks.php
│   ├── install.xml
│   ├── services.php
│   ├── tasks.php
│   └── upgrade.php
├── lang/en/local_xlate.php
├── manage.php
├── settings.php
├── version.php
├── README.md
├── DEVELOPER.md (this document)
├── CONTRIBUTING.md
├── phpcs.xml.dist
├── .editorconfig, .eslintignore, .gitignore
├── spec/
│   ├── translate_batch_function.json
│   └── translate_batch_response_schema.json
└── ...
```
## Troubleshooting / FAQ

**Q: How do I debug migration or scheduled task issues?**
A: Check CLI output, review logs (via `mtrace()`), and inspect the dry-run JSON report. Defensive checks are in place for DB and unserialization errors.

**Q: How do I roll back a migration?**
A: Restore your database from backup. All destructive changes are logged, but cannot be automatically undone.

**Q: Where do I add new migration or translation logic?**
A: Extend `mlang_migration.php` for migration logic, or add new AMD modules for client-side features. The scheduled task and CLI runner will use updated logic automatically.

**Q: How do I run integration or regression tests?**
A: Seed test data with `{mlang}` content, run the CLI migration in dry-run mode, and verify provenance and output. Consider adding automated tests for new features.

**Q: Where can I get help or contribute?**
A: See `CONTRIBUTING.md` or file issues/PRs on GitHub.


- (No manual or toggle: keys are always auto-assigned by the JS.)
- Enable page-level bundle filtering in `api::get_page_bundle()` for further optimization.
- Add collision detection/resolution for structural keys if needed.
- Expose composite string for each key in admin UI/debug mode.

Happy hacking!

## Scheduled MLang cleanup task

- The scheduled task class is `local_xlate\task\mlang_cleanup_task` (see `classes/task/mlang_cleanup_task.php`).
- It is registered in `db/tasks.php` to run nightly by default, but can be configured in the Moodle admin UI.
- The task reuses the migration logic in `mlang_migration.php` and will autodiscover all candidate columns, including block configdata, and clean up legacy multilang tags.
- Logs are output via `mtrace()` and can be viewed in the CLI or scheduled task logs.
- You can trigger the task manually with:
  ```bash
  sudo -u www-data php admin/cli/scheduled_task.php --execute='\\local_xlate\\task\\mlang_cleanup_task'
  ```
- If you extend the migration logic, the scheduled task will automatically use the new behavior.
