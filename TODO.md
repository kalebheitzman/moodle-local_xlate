# Audit Findings

- [x] **High** `db/install.xml`: ensure only `local_xlate_token_batch` is defined (inside `<TABLES>`); remove unused `local_xlate_token_usage` table to keep clean installs aligned with current upgrade path.
- [x] **High** `db/services.php`, `classes/external.php`: service `local_xlate_associate_keys` advertises no capability yet only calls `require_login()`, letting any logged-in user associate keys for any course; privilege escalation risk. Decision: acceptable for now because capturing source text from general visits is desired, but revisit if broader write actions become a concern.
- [x] **High** `classes/local/api.php::invalidate_bundle_cache()` vs `get_page_bundle()`: caching uses composite keys including context and pagetype, but invalidation removes only by language, leaving stale bundles in cache.
- [x] **High** `classes/local/api.php::get_page_bundle()`: debug code disabled component filtering, causing every bundle request to fetch the entire translation table and expose unrelated data.
- [ ] **Med** `classes/local/api.php::save_key_with_translation()`: on exception the catch block rolls back and returns `null`, so callers continue as if the save succeeded; errors are silently swallowed.
- [ ] **Med** `classes/external.php`, `classes/translation/backend.php`, `classes/task/translate_course_task.php`: extensive `error_log()` usage dumps payloads and translations into web-server logs, conflicting with Moodle privacy guidelines; prefer `debugging()` or controlled logging.
- [ ] **Low** `admin_nav.php`: tab labels are hard-coded English strings instead of `get_string()` calls, so the admin navigation cannot be translated.

