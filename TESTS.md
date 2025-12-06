# Testing Guide for local_xlate

This document outlines the recommended checks before releasing or deploying the plugin. It blends manual GUI walkthroughs (critical for translator UX) with ideas for future automated coverage (PHPUnit/Behat). Tweak it to fit your environment as new features land.

## 1. Environment Prep

1. **Fresh cache**: `php admin/cli/purge_caches.php`.
2. **Custom fields**: `sudo -u www-data php local/xlate/cli/recreate_customfields.php` (if you need a clean slate).
3. **Sample course**: ensure at least one course with editing access and another course left unconfigured for negative tests.
4. **Languages**: install multiple packs (e.g., `en`, `cs`, `ru`) and enable them via plugin settings.
5. **Autotranslate backend** (optional): point to a dev endpoint or mock server so cost logging can be exercised without touching production keys.

## 2. Manual Test Suites

### A. Admin UX + Settings

- [ ] Visit `Site administration → Plugins → Local plugins → Xlate` and confirm the shared nav tabs render (Manage / Glossary / Usage / Settings).
- [ ] Toggle **Enable Xlate** off/on and verify hooks stop/start injecting scripts (check course page source for `XLATE HEAD HOOK FIRED`).
- [ ] Update capture/exclude selectors, save, refresh a course page, and confirm `window.XLATE_*` arrays reflect the changes.
- [ ] Edit **Exclude path prefixes** and ensure newly added prefixes stop bootstrapping the translator.

### B. Course Configuration

- [ ] In a course, open **Course settings → Custom fields → Xlate**:
  - [ ] Select a source language and choose at least one target. Save.
  - [ ] Confirm `local_xlate_customfield_helper::get_course_config()` resolves the selection (CLI: `php local/xlate/cli/list_translatable_courses.php`).
- [ ] Remove the source language and reload the course page—translator assets must *not* load.
- [ ] Configure a second course with different targets to ensure per-course isolation.

### C. Translator Runtime

- [ ] On a configured course page (view mode):
  - [ ] Inspect console for `XLATE Initializing` debug log.
  - [ ] Toggle between languages using Moodle’s language picker; verify DOM updates and no FOUT.
  - [ ] Browse in the site default language with `local/xlate:manage` capability; confirm newly seen strings appear in `Manage Translations` after refresh.
  - [ ] Add inline markup (e.g., link + `<em>` text) to a block, capture it, and confirm the saved string plus rendered translation preserve the tags while stripping disallowed markup/URLs.
- [ ] Switch to **Edit mode**; translator and capture must remain disabled (no console init log, DOM untouched).
- [ ] Visit an admin page (`/admin/search.php`), `/course/modedit.php`, and `/course/edit.php`; verify `XLATE` scripts are absent (guardrail test).

### D. Manage Translations UI

- [ ] Open `/local/xlate/manage.php`:
  - [ ] Verify navigation tabs highlight “Manage”.
  - [ ] Search, filter by status, paginate, and ensure counts update.
  - [ ] Edit a translation, save, and confirm success toast + DB change.
  - [ ] Use the course filter (`courseid` query parameter) with a user who only has `local/xlate:managecourse` to confirm scoped access.

### E. Glossary

- [ ] `/local/xlate/glossary.php`:
  - [ ] Add a new glossary entry, edit it, delete it. Confirm DB rows and UI updates.
  - [ ] Verify guard rails: only users with `local/xlate:manage` should access the page.

### F. Usage Dashboard

- [ ] Run autotranslation or seed `local_xlate_token_batch` rows.
- [ ] Visit `/local/xlate/usage.php`:
  - [ ] Tabs highlight “Usage”.
  - [ ] Filters (month/year/lang/model) modify totals.
  - [ ] If cost data absent, verify fallback notice referencing plugin settings.

### G. CLI Tools

Run each command as `www-data` from Moodle root unless noted:

```bash
sudo -u www-data php local/xlate/cli/list_translatable_courses.php
sudo -u www-data php local/xlate/cli/autotranslate_dryrun.php --showmissing
sudo -u www-data php local/xlate/cli/queue_course_job.php --courseid=<id>
sudo -u www-data php local/xlate/cli/show_new_translations.php
sudo -u www-data php local/xlate/cli/sync_source_language_indices.php --dry-run
```

- Ensure commands respect guarding (skip courses with no source language, etc.).
- Confirm `autotranslate_dryrun` matches UI data for counts.

### H. Bundle Endpoint & Web Service Security

- [ ] `bundle.php` rejects GET traffic: `curl -i 'https://example.com/local/xlate/bundle.php?lang=en'` should return `405 Method Not Allowed`.
- [ ] POST requests without a `sesskey` or Moodle session cookie must be rejected (expect `403` or `invalidsesskey`).
- [ ] Valid POST requests require a JSON body with `keys`. Use the example from `README.md` and confirm only the requested keys are returned.
- [ ] Temporarily remove `local/xlate:viewbundle` from a test role in the target course and confirm bundle calls fail until the capability is restored. Repeat for `local/xlate:viewsystem` on the front page/system context.
- [ ] Supply a mismatched `courseid`/`contextid` combo and confirm the endpoint returns `invalidcoursecontext`.
- [ ] Exercise `local_xlate_associate_keys` via core web services (e.g., REST or WS testing client):
  - [ ] Call without `local/xlate:manage`/`local/xlate:managecourse` and ensure access is denied.
  - [ ] Call as a course-level manager who is not enrolled—verify the enrolment guard blocks the request.
  - [ ] Send more than 200 keys and confirm the `Too many keys requested` error.
  - [ ] Inspect DB to ensure sanitised `component`/`source` values are stored and associations respect the provided course.

### I. Scheduled / Adhoc Tasks

- Trigger `local_xlate\task\autotranslate_missing_task` via `scheduled_task.php`.
- Monitor logs for per-course gating, batch sizes, token usage recording.
- Run `translate_course_task` adhoc jobs and verify results appear in `Manage Translations` + `usage.php`.

### J. Guardrail Regression (Critical)

- [ ] For each path listed under **Exclude path prefixes**, ensure the translator never loads.
- [ ] Add a custom prefix (e.g., `/local/customreport/`), save, and confirm no translator assets load there.
- [ ] Remove the prefix and ensure translator resumes on normal course pages.

### K. MLang Migration Tooling (if relevant)

- Run `cli/mlang_migrate.php` in dry-run mode on a staging DB.
- Inspect the JSON report, confirm sample replacements look correct.
- (Optional) Run with `--execute --max=5` on a test DB to ensure provenance rows populate.

## 3. Automated Testing Ideas

While most of the plugin is UI/observer heavy (best validated manually or via Behat), some layers lend themselves to automation:

- **PHPUnit** (place under `tests/`):
  - `customfield_helper_test.php`: ensure `get_course_config()` handles missing data, index syncing, and caching.
  - `local_xlate\local\api_test.php`: cover bundle generation, cache invalidation, and version hashing.
  - `translation/backend_test.php`: mock the HTTP client to verify request payloads, retry logic, and error mapping.

- **Behat** (Moodle acceptance tests):
  - Scenario: enable plugin, visit course in default language, verify translator JavaScript injects and capture occurs for a known element.
  - Scenario: ensure admin pages with given URL prefixes do not contain `window.XLATE_*` globals.
  - Scenario: course-level manager can access `/local/xlate/manage.php?courseid=<id>` but not other courses.

Getting started:
1. Enable dev testing in `config.php` (`$CFG->behat_*` / `$CFG->phpunit_*`).
2. Place PHPUnit tests under `local/xlate/tests/` following Moodle naming conventions (`xxx_test.php`).
3. For Behat, add feature files under `local/xlate/tests/behat/` and leverage Moodle’s provided steps (e.g., navigation, page inspection). See [Moodle Dev Docs](https://moodledev.io/docs/testing). 

## 4. Release Checklist

- [ ] Manual suites A–K pass.
- [ ] CLI tools run without warnings.
- [ ] Scheduled task exercised or dry-run logs reviewed.
- [ ] README/DEVELOPER/TESTS reflect any new features.
- [ ] (Optional) PHPUnit + Behat suites green in CI.

Keep this file evolving—add new flows (e.g., future UI screens) or automate sections once they stabilize.
