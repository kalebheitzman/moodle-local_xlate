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

- [ ] Design DB table for course associations (`local_xlate_key_course`)
  - Create columns: `id`, `keyid`, `courseid`, `source_hash`, `userid`, `context`, `mtime`.
  - Add UNIQUE(`keyid`, `courseid`, `source_hash`), indexes on `courseid` and `keyid`, and FK `keyid` → `local_xlate_key(id)`.
  - Add table to `db/install.xml` for new installs.
  - Add migration in `db/upgrade.php` for existing installs.

## Client (AMD) wiring

- [ ] Wire client to send course id
  - Update `amd/src/translator.js` to detect the page's course id (e.g. `M.cfg.courseid`, `document.body.dataset.courseid`, or other page metadata).
  - Include `courseid` and an optional `context` field in the payload to `local_xlate_save_key` when saving a captured key.
  - Respect edit-mode guard and capability checks (no capture during edit mode).

## Server-side save & storage

- [ ] Server-side: accept and persist course associations
  - Update `classes/external.php` and `db/services.php` to accept an optional `courseid` parameter on `local_xlate_save_key`.
  - Modify `classes/local/api.php` (`save_key_with_translation` or helper) to insert into `local_xlate_key_course` (dedupe using `source_hash`), inside a DB transaction.
  - Keep existing behaviour: bump bundle version, invalidate caches, and return the same API responses.

## Manage UI & workflow

- [ ] Manage UI & course link
  - Add course filter to `manage.php` (accept `courseid` GET param).
  - When `courseid` is present, prefilter results and show a small badge/notice.
  - Add "Manage Translations" link under the course "More" menu that opens `/local/xlate/manage.php?courseid=<id>`.
    - Prefer appending via a plugin callback/hook that respects capabilities and themes.

## Migrations & versioning

- [ ] DB migrations & version bump
  - Add `local_xlate_key_course` to `db/install.xml`.
  - Add upgrade step in `db/upgrade.php` to create the table for existing installs.
  - Bump `version.php` to reflect schema changes.

## Tests, build & validation

- [ ] Tests, build, and validation
  - Add unit/integration tests for web service save flow (including `courseid`).
  - Add UI test for `manage.php` filter (PHPUnit/Behat where appropriate).
  - Build AMD assets (`grunt amd --root=local/xlate --force`), run `php admin/cli/upgrade.php`, and purge caches.

## Developer suggestions (from DEVELOPER.md)

- [ ] Enable page-level bundle filtering in `api::get_page_bundle()` for further optimization.
- [ ] Add collision detection/resolution for structural keys if needed.
- [ ] Expose composite/source string for each key in admin UI / debug mode.

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

If you want, I can now draft the `db/install.xml` snippet and `db/upgrade.php` migration, or search for the exact web service helper to prepare a minimal patch.