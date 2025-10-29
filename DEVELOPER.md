
# Developer Notes

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

## 1. Purpose & Flow
`local_xlate` brings LocalizeJS-style client translations to Moodle 5+. The
plugin injects a bootloader, serves immutable JSON bundles, and runs an AMD
module that translates DOM nodes (and can capture new strings) without touching
core or theme code. Each translated element receives a stable `data-xlate-key`
attribute so minor source edits do not invalidate translations. Keys are 12-character hashes based on element structure, class, region, and text (see `KEY_GENERATION.md`).

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

## 3. Automatic Capture (AMD Module)
- Guard rails:
  - `currentLang === siteLang` (prevents recording already-translated strings).
  - **Edit mode disables capture**: If the page is in edit mode, capture/tagging is always skipped (see `isEditing` flag in JS config).
  - Capability check happens via `local_xlate_save_key`
    (`local/xlate:manage`).
  - No manual or toggle: keys are always auto-assigned by the JS.
- Captured text includes text nodes plus `placeholder`, `title`, `alt`, and `aria-label`
  attributes. Stable keys combine detected component, element type, normalized
  text, and a short hash. See `KEY_GENERATION.md` for full details.
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
4. **Versioning**: bump `version.php` and add steps to `db/upgrade.php` whenever
   schema or behaviour changes demand it.
5. **Code style**: follow Moodle PHP guidelines; AMD modules stay ES5-compatible
   (no transpilation pipeline).

## MLang migration tooling (developer guide)

The plugin includes helpers and a CLI runner to discover, dry-run and (optionally)
apply destructive cleanup of legacy MLang blocks (`{mlang ...}` and
`<span lang="xx" class="multilang">...</span>`). Use these tools carefully —
always run a dry-run and inspect samples before applying changes, and take a
DB backup prior to running any destructive migration.

Note: some temporary scanner scripts that were used during early development
(for example `cli/find_mlang_all.php`, `cli/find_mlang_sections.php`, and the
separate `cli/mlang_dryrun.php`) have been removed from the repository. The
supported and maintained CLI entrypoint is `cli/mlang_migrate.php` described
below.

Key files and APIs:
- `classes/mlang_migration.php`
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
│   ├── src/translator.js
│   └── build/translator.min.js
├── bundle.php
├── classes/
│   ├── hooks/output.php
│   ├── local/api.php
│   └── external.php
├── db/
│   ├── access.php
│   ├── caches.php
│   ├── hooks.php
│   ├── install.xml
│   ├── services.php
│   └── upgrade.php
├── example_html_output.html
├── lang/en/local_xlate.php
├── manage.php
├── settings.php
├── version.php
├── README.md
└── DEVELOPER.md (this document)
```


- (No manual or toggle: keys are always auto-assigned by the JS.)
- Enable page-level bundle filtering in `api::get_page_bundle()` for further optimization.
- Add collision detection/resolution for structural keys if needed.
- Expose composite string for each key in admin UI/debug mode.

Happy hacking!
