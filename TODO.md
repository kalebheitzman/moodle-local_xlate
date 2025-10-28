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
  - [x] Columns: `id`, `keyid`, `courseid`, `source_hash`, `userid`, `context`, `mtime`.
  - [x] UNIQUE(`keyid`, `courseid`, `source_hash`) and necessary indexes.
  - [x] Table added to `db/install.xml` and migration added to `db/upgrade.php`.

## Client (AMD) wiring

- [x] Client sends `courseid` and `context` in captured-key payloads
  - [x] `amd/src/translator.js` updated to detect page course id (prefers `window.XLATE_COURSEID` injected by hook, falling back to `M.cfg.courseid`).
  - [x] Payload includes `courseid` and `context` when calling `local_xlate_save_key`.
  - [x] Edit-mode guard respected (no capture in editing mode).

## Server-side save & storage

- [x] Server accepts and persists course associations
  - [x] `classes/external.php` / webservice updated to accept `courseid` and `context`.
  - [x] `classes/local/api.php` updated to insert into `local_xlate_key_course` using `source_hash` dedupe and a try/catch re-check to handle races.
  - [x] Existing behaviour maintained (bundle version bump, cache invalidation).

## Manage UI & workflow

- [x] Manage page: course filter implemented
  - [x] `manage.php` accepts `courseid` and filters results when `courseid > 0` (use `0` to show all).
  - [x] Count and list queries both respect the course filter.
- [ ] Add "Manage Translations" link under course "More" menu
  - [ ] UI hook to append course-scoped link (not implemented yet).

## Migrations & versioning

- [x] Migrations & version bump
  - [x] `local_xlate_key_course` added to `db/install.xml` and `db/upgrade.php` updated.
  - [x] `version.php` bump applied as part of the upgrade.

## Tests, build & validation

- [ ] Tests and CI coverage (pending)
  - [ ] Add PHPUnit tests for `save_key_with_translation()` persistence, dedupe and race protection.
  - [ ] Add Behat or integration tests for `manage.php?courseid=<id>` filter and UI behaviour.
  - [x] AMD assets built; ESLint cleaned and `grunt amd` ran successfully under `www-data`.
  - [x] Moodle caches purged after rebuild for dev verification.

## Developer suggestions (from DEVELOPER.md)

- [ ] Enable page-level bundle filtering in `api::get_page_bundle()` for further optimization.
- [ ] Add collision detection/resolution for structural keys if needed.
- [ ] Expose composite/source string for each key in admin UI / debug mode.

## Remaining action items (short list)

- Secure `.eslintignore` file permissions (owner `www-data`, mode `644`).
- Implement PHPUnit tests for API persistence and dedupe behavior.
- Add Behat/integration test for manage page course filtering.
- Add course "Manage Translations" more-menu link (UI hook).
- Add CI workflow to lint and build AMD on PRs.
- Update `README.md` / `DEVELOPER.md` and changelog with these changes and build instructions.

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
