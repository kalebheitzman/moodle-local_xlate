<!--
  Comprehensive TODO file for local_xlate feature work.
  This file is intentionally the single source of truth for todos related to
  the plugin. When tasks are completed, mark the checkbox and update the
  tracked todo list using the workspace tooling.
-->

# TODO: local_xlate work items

The project's todos live in this file. Items are grouped by logical area.
If a task exists elsewhere (README/DEVELOPER) it has been moved here and
expanded into concrete implementation steps.

## General

- [x] Create `TODO.md` with plan and initial todos (this file).

## Database & schema

- [x] Design & create DB table for course associations (`local_xlate_key_course`)
  - [x] Columns: `id`, `keyid`, `courseid`, `context`, `mtime`.
  - [x] UNIQUE(`keyid`, `courseid`) and necessary indexes.
  - [x] Table added to `db/install.xml` and migration added to `db/upgrade.php`.

  - [x] Core tables created: `local_xlate_key`, `local_xlate_tr`, `local_xlate_bundle` (with appropriate unique indexes for `(component,xkey)` and `(keyid,lang)`).

## Client (AMD) wiring

- [x] Client sends `courseid` and `context` in captured-key payloads
  - [x] `amd/src/translator.js` updated to detect page course id (prefers `window.XLATE_COURSEID` injected by hook, falling back to `M.cfg.courseid`).
  - [x] Payload includes `courseid` and `context` when calling `local_xlate_save_key`.
  - [x] Edit-mode guard respected (no capture in editing mode).

- [x] AMD runtime applies translations, observes DOM mutations, and captures untranslated strings when allowed (capture disabled in edit mode). See `amd/src/translator.js` and `build/translator.min.js`.

## Server-side save & storage

- [x] Server accepts and persists course associations
  - [x] `classes/external.php` / webservice updated to accept `courseid` and `context`.
  - [x] `classes/local/api.php` updated to insert into `local_xlate_key_course` using `keyid+courseid` dedupe and a try/catch re-check to handle races.
  - [x] Existing behaviour maintained (bundle version bump, cache invalidation).

- [x] Server APIs provide CRUD, cache invalidation, bundle generation, and capture handling (`classes/local/api.php`, `classes/external.php`).

## Bundle & cache layer

- [x] Bundle endpoint (`bundle.php`) implemented, returns `{translations, sourceMap}`, supports POST `{keys: [...]}` for filtered responses and uses immutable caching headers.
- [x] Bundle helpers implemented: `get_bundle`, `get_version`, `generate_version_hash`, `update_bundle_version`, `invalidate_bundle_cache` and `save_key_with_translation`.


## Manage UI & workflow

- [x] Manage page: course filter implemented
  - [x] `manage.php` accepts `courseid` and filters results when `courseid > 0` (use `0` to show all).
  - [x] Count and list queries both respect the course filter.
- [x] Add "Manage Translations" link under course "More" menu
  - [x] UI hook to append course-scoped link (implemented in `lib.php`).

- [x] Capabilities defined and enforced: `local/xlate:manage` (site) and `local/xlate:managecourse` (course-scoped). `manage.php` respects `courseid` and course-level capability checks.

- [ ] Add a "Human Reviewed/Translated" checkbox to the Manage page translation rows (UI control, per-translation flag in DB, filtering and bulk-mark actions).

## Configuration & settings

- [x] Core settings present in `settings.php`: `enable`, `autodetect`, `enabled_languages`, `component_mapping` (documented and wired into runtime where applicable).


## Migrations & versioning

- [x] Migrations & version bump
  - [x] `local_xlate_key_course` added to `db/install.xml` and `db/upgrade.php` updated.
  - [x] `version.php` bump applied as part of the upgrade.

  - [x] MLang migrations & cleanup executed: site discovery/dry-run, targeted executes for `course_sections.name` (20 rows), `forum.name`/`forum.intro` (40 rows), and `label.intro` (40 rows); provenance recorded in `local_xlate_mlang_migration`, temporary helper scripts removed, docs updated and caches purged. Reports were written to `/tmp` during runs.

## Language Glossary (source -> target)

Purpose: maintain a curated mapping of source-language phrases to preferred
target-language translations so automated/manual translation respects
project-specific terminology, acronyms, product names and style choices.

Completed work (scaffolding):

- [x] Schema & install: `local_xlate_glossary` table added to `db/install.xml` (fields: `id`, `source_lang`, `target_lang`, `source_text`, `target_text`, `mtime`, `created_by`, `ctime`).
- [x] Upgrade step: guarded upgrade savepoint added in `db/upgrade.php` (2025103002) to create the glossary table for existing installs.
- [x] Helper: `classes/glossary.php` added with `get_by_target()`, `add_entry()` and `lookup_glossary()` stubs.
- [x] UI: `glossary.php` listing/filtering page added (`?targetlang=`) for managers.
- [x] Language strings: glossary-related strings added to `lang/en/local_xlate.php`.
- [x] Naming cleanup: `created_at` renamed to `ctime` across install/upgrade and code.

Remaining work (high priority):

- [ ] CRUD UI: add capability-protected add/edit/delete forms with CSRF and validation.
- [ ] Import/Export: CSV/JSON import with preview/validation and export endpoint.
- [ ] API: webservice CRUD endpoints protected by `local/xlate:manage`.
- [ ] Matching & integration: improve `lookup_glossary()` with fuzzy/token matching and integrate it into migration/auto-translate flows (record provenance when applied).
- [ ] Caching: add lookup caching per lang-pair with proper invalidation on edits.
- [ ] Tests & docs: PHPUnit/Behat tests for matching and UI, and documentation for import format and usage.

Acceptance criteria (updated):

- [ ] CRUD UI exists and is capability-protected.
- [ ] Import validates rows and reports errors clearly.
- [ ] `lookup_glossary()` is used by migration/auto-translate flows and recorded in provenance when applied.

## Automatic translation (OpenAI endpoint) usable from Manage page

Purpose: provide a controlled, auditable AI translation feature that produces
candidate translations for keys or DB content using an OpenAI-compatible
endpoint. Integrates with glossary; allows preview, accept/reject, and bulk
apply.

Subtasks:
- [ ] Add `settings.php` section: provide a dedicated admin settings section to
  enable/disable autotranslate and to store provider credentials (explicit
  settings for `baseurl`, `model`, `api_key`). Also include `enabled`,
  `provider`, `temperature`, `max_tokens`, `system_prompt_template`,
  `use_glossary`, `batch_size_limit`, `rate_limit_opts`, `log_level`. Restrict
  settings to admins; store API key per Moodle config practices.
- [ ] Security & privacy: make explicit that sending content externally may expose
  data; provide opt-in toggles and defaults to exclude sensitive content.
- [ ] Service layer: implement `local_xlate\translation\backend` wrapper for
  HTTP calls with retries, backoff, error mapping and structured responses.
- [ ] Glossary integration: inject glossary into system prompt or post-process
  responses to enforce preferred terms.
- [ ] Manage UI: add "Auto-translate" control in `manage.php` with preview modal
  (original, glossary suggestion, AI candidate), per-key accept/reject and
  bulk-apply.
- [ ] Background processing: use adhoc tasks or scheduled tasks for large batches
  with rate limiting and retry support; provide queue progress reporting.
- [ ] Provenance & audit: record AI-applied translations with `applied_via='ai'`,
  `model`, `system_prompt_hash`, `response_summary`, `applied_by`, `migrated_at`;
  make applied changes reversible via provenance data.
- [ ] Error handling & fallbacks: on provider failure fall back to glossary (when
  enabled) or leave original unchanged and record reason.
- [ ] Cost controls: enforce per-run/global caps, provide usage metrics.
- [ ] Tests & docs: example system prompts, unit/integration tests, and ADR for
  privacy/cost tradeoffs.

Acceptance criteria:
- [ ] Admins can configure AI backend and request translations from Manage UI.
- [ ] AI translations are previewable, auditable, reversible, and respect glossary
  where possible.
- [ ] Large batches run in background with retries/rate limiting.

## Tests, build & validation

- [ ] Tests and CI coverage (pending)
  - [ ] Add PHPUnit tests for `save_key_with_translation()` persistence, dedupe and race protection.
  - [ ] Add Behat or integration tests for `manage.php?courseid=<id>` filter and UI behaviour.
  - [x] AMD assets built; ESLint cleaned and `grunt amd` ran successfully under `www-data`.
  - [x] Moodle caches purged after rebuild for dev verification.

## Developer suggestions & remaining action items

This section combines implementation suggestions from `DEVELOPER.md` with
the remaining actionable work items. Suggestions are short-term notes for
developers; the checklist below contains prioritized, trackable tasks.

- [ ] Enable page-level bundle filtering in `api::get_page_bundle()` for further optimization.
- [ ] Add collision detection/resolution for structural keys if needed.
- [ ] Expose composite/source string for each key in admin UI / debug mode.
- [ ] Decide final approach for `source_hash` (keep vs remove) and implement safe DB upgrade if removing.
- [ ] Consider/implement index change for `local_xlate_key_course` if `source_hash` is removed (dedupe + unique index change to `(keyid,courseid)`).
- [ ] Migrate remaining translation sources (targeted): `local_xlate_tr.text`, `local_xlate_key.source`, `assign.activity` — each: dry-run, review samples, staged execute, then full execute.
- [ ] Verify provenance table (`mdl_local_xlate_mlang_migration`) contains expected rows for recent runs (labels, forums, course_sections, etc.) and sample audit.
- [ ] Implement PHPUnit tests for API persistence, dedupe and race protection.
- [ ] Add Behat/integration test for `manage.php?courseid=<id>` filter and UI behaviour.
- [ ] Add CI workflow to lint and build AMD on PRs.
- [ ] Optional: move important JSON reports from `/tmp` into `build/reports/` and archive reports used for auditing.
- [ ] Commit & push docs + small cleanup (README/DEVELOPER edits and removal of temporary CLI helpers).

## Notes & references

- Files to inspect/modify:
  - `amd/src/translator.js`
  - `classes/external.php`
  - `classes/local/api.php`
  - `db/install.xml`, `db/upgrade.php`, `version.php`
  - `db/services.php`
  - `manage.php`

- Edge cases:
  - Not all pages have a course id — omit `courseid` when absent.
  - Respect edit mode and capability checks; do not record in edit mode.
  - Use transactions and unique constraints to avoid duplicate rows.

