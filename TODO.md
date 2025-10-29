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

## Client (AMD) wiring

- [x] Client sends `courseid` and `context` in captured-key payloads
  - [x] `amd/src/translator.js` updated to detect page course id (prefers `window.XLATE_COURSEID` injected by hook, falling back to `M.cfg.courseid`).
  - [x] Payload includes `courseid` and `context` when calling `local_xlate_save_key`.
  - [x] Edit-mode guard respected (no capture in editing mode).

## Server-side save & storage

- [x] Server accepts and persists course associations
  - [x] `classes/external.php` / webservice updated to accept `courseid` and `context`.
  - [x] `classes/local/api.php` updated to insert into `local_xlate_key_course` using `keyid+courseid` dedupe and a try/catch re-check to handle races.
  - [x] Existing behaviour maintained (bundle version bump, cache invalidation).

## Manage UI & workflow

- [x] Manage page: course filter implemented
  - [x] `manage.php` accepts `courseid` and filters results when `courseid > 0` (use `0` to show all).
  - [x] Count and list queries both respect the course filter.
- [x] Add "Manage Translations" link under course "More" menu
  - [x] UI hook to append course-scoped link (implemented in `lib.php`).

## Migrations & versioning

- [x] Migrations & version bump
  - [x] `local_xlate_key_course` added to `db/install.xml` and `db/upgrade.php` updated.
  - [x] `version.php` bump applied as part of the upgrade.

  ## MLang migrations & cleanup (completed)

  Purpose: remove legacy `{mlang ...}` blocks from the database content, record
  provenance for each change, and tidy up migration tooling and documentation.

  Completed subtasks:
  - [x] Site-wide discovery/dry-run to identify candidate columns and rows containing `{mlang`.
  - [x] Targeted execute: cleaned `course_sections.name` entries (20 rows changed).
  - [x] Targeted execute: cleaned `forum.name` and `forum.intro` entries (40 rows changed).
  - [x] Targeted execute: cleaned `label.intro` entries (40 rows changed).
  - [x] Recorded provenance rows in `local_xlate_mlang_migration` for executed changes.
  - [x] Removed temporary helper CLI scripts (`find_mlang_*`, `mlang_dryrun`) used during discovery.
  - [x] Updated `README.md` and `DEVELOPER.md` to point to canonical `cli/mlang_migrate.php` and document `--tables` usage.
  - [x] Purged Moodle caches after execute runs so UI reflects cleaned content.
  - [x] Archived JSON reports to `/tmp` during runs (consider moving to `build/reports/` if desired).


## Language Glossary (source -> target)

Purpose: maintain a curated mapping of source-language phrases to preferred
target-language translations so automated/manual translation respects
project-specific terminology, acronyms, product names and style choices.

Subtasks:
- [ ] Schema: create a `local_xlate_glossary` table with: `id`, `source_lang`,
  `target_lang`, `source_text` (normalized), `target_text`, `context` (opt),
  `priority` (int), `mtime`, `created_by`, `created_at`.
- [ ] Admin UI: Glossary management page reachable from Manage Translations: list,
  filter, add, edit, delete entries.
- [ ] Glossary UI endpoint: add `glossary.php` which accepts `?targetlang=[targetlang]`
  and shows the glossary for that target language with filtering and quick edits.
- [ ] Import/Export: CSV/TSV/JSON import + export with preview and validation; example templates.
- [ ] API: webservice endpoints (CRUD) protected by `local/xlate:manage`.
- [ ] Matching engine: `lookup_glossary(source, source_lang, target_lang)` with
  normalized exact and fuzzy matching, ordered by `priority`.
- [ ] Integration: prefer glossary matches in `mlang_migrate` and auto-translate
  flows; record provenance `applied_via='glossary'` when applied automatically.
- [ ] Cache & invalidation: cache lookups per lang-pair; invalidate on edits.
- [ ] Permissions & audit: record `created_by`/`mtime`; only managers may edit.
- [ ] Tests & docs: unit tests for matching, UI tests, docs for import format.

Acceptance criteria:
- [ ] Glossary CRUD UI exists and is capability-protected.
- [ ] Import validates rows and reports errors clearly.
- [ ] `lookup_glossary()` is used by migration/auto-translate flows.

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

