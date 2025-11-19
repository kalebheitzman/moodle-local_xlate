# local_xlate

## Features (At a Glance)
- FOUT-free client-side translation for Moodle 5+
- Automatic DOM translation and string capture
- Robust migration tool for legacy `{mlang}` and `<span class="multilang">` tags
- Scheduled task for ongoing cleanup of multilang tags
- Admin UI for managing translations and glossary
- Optional OpenAI autotranslation integration
- Provenance tracking for all migration changes

## Changelog (Recent Changes)

- **2025-11-03**: Added scheduled task for automated MLang cleanup (`mlang_cleanup_task.php`).
- Improved autodiscovery and safety in migration tooling.
- Enhanced documentation and developer guidance.


Client-side translation plugin for Moodle 5+ inspired by LocalizeJS. It injects
versioned translation bundles during page rendering, prevents flash-of-untranslated-text (FOUT), and translates the DOM in real time – including dynamically injected content. Keys are always structure-based, 12-character hashes, and capture is disabled in edit mode.

## Highlights
- **FOUT-free bootloader**: CSS gate plus inline loader fetch a versioned bundle
	and hydrate from `localStorage` when possible.
- **Automatic DOM translation**: Elements marked with `data-xlate` attributes –
	or captured automatically – are translated on load, on MutationObserver
	events, and after user interactions that add new DOM nodes. The translator uses a MutationObserver to handle dynamic content.
- **Inline markup-safe capture**: Text extraction now preserves inline markup
	such as `<a>`, `<strong>`, `<em>`, and `<span>` so translation prompts keep
	their original structure instead of flattening to plain text.
- **Optional auto-capture**: When browsing in the site’s default language with
	the `local/xlate:manage` capability, the plugin records new strings (text,
	placeholders, titles, alt text, and aria-label) through the Moodle web service API. **Capture is always disabled in edit mode** (see browser console for `[XLATE] Edit mode detected...`).
- **Caching stack**: Moodle application cache → browser `localStorage` → long-lived HTTP responses for efficient repeat visits. The bundle endpoint supports POST requests with a list of keys for efficient, page-specific translation.
- **Translation management UI**: Admins can review automatically captured keys,
- **Translation management UI**: Admins can review automatically captured keys,
  enter translations, and rebuild bundles from `Site administration → Plugins →
  Local plugins → Xlate`. Use the **Xlate: Manage Translations** page to manage captured keys and the **Xlate: Manage Glossary** page to maintain the language glossary.
- **Per-course language control**: Each course exposes an “Xlate source language” select plus per-language target checkboxes under Course custom fields. Translator assets, CLI jobs, and scheduled tasks only run when a course declares a source language, preventing accidental activation on unconfigured courses.

## System Diagram

```
		       Plugin settings / course custom fields
		       +-------------------------------------+
		       |  Xlate source select + target boxes |
		       +------------------+------------------+
					  |
					  v
+----------------+       +-------------------------------+       +-------------------------------+
 Moodle pages    +-----> | hooks/output.php (gates by    | ----> | AMD translator (DOM translate |
 (viewer/edit)   |       | enable + course config)       |       | + optional capture)           |
+----------------+       +-------------------+-----------+       +---------------+---------------+
	^                                   |                                   |
	|                                   v                                   |
	|                +--------------------------------------+               |
	|                | local_xlate\local\api (bundles,      |<--------------+
	|                | cache, web services)                 |
	|                +--------------------+-----------------+
	|                                     |
	|                                     v
	|                +--------------------------------------+          CLI / Tasks / Backend
	|                | DB tables: local_xlate_key/tr/bundle |<---------+---------------------+
	|                +--------------------------------------+          | autotranslate_missing|
	|                                                                   | translate_course     |
	|                                                                   | autotranslate_dryrun |
	+-------------------------------------------------------------------+ list_translatable... |
									    | queue_course_job ... |
									    +----------+----------+
										       |
										       v
									    translation/backend.php
									    (OpenAI-compatible API)
```

## Installation
1. Copy this folder to `moodle/local/xlate` (or extract the release archive
	 there).
2. Visit **Site administration → Notifications** to run the installation.
3. Purge caches: **Site administration → Development → Purge all caches** (or
	 run `php admin/cli/purge_caches.php`).
4. Verify the plugin appears under **Site administration → Plugins → Local
	 plugins → Xlate**.

## MLang cleanup (legacy multi-language blocks)


This plugin includes a robust migration tool to detect and (optionally) remove legacy `{mlang ...}` and `<span lang="xx" class="multilang">...</span>` blocks from your Moodle database content.

**Key features:**
- Autodiscovers all text-like columns (text, varchar, etc.), excluding tables with "xlate" in the name.
- Handles block configdata fields: decodes, unserializes, and cleans mlang tags from all string fields (e.g., block titles in custom HTML blocks).
- Defaults to your site's current language for replacements (if not set, falls back to `other`).
- Records provenance for every change in `local_xlate_mlang_migration`.
- Always run a dry-run first and back up your DB before executing real changes.

**Quick workflow:**
1. **Dry-run (safe):**
	```bash
	sudo -u www-data php local/xlate/cli/mlang_migrate.php
	```
	This produces a report in your system temp directory (e.g. `/tmp/local_xlate_mlang_migrate_<ts>.json`) listing matches and sample replacements.

2. **Review the report and samples.**
	- By default, the script uses your site's language for replacements. You can override with `--preferred=other` or a specific language code.

3. **Staged migration (test a few rows):**
	```bash
	sudo -u www-data php local/xlate/cli/mlang_migrate.php --execute --max=5
	```

4. **Full migration:**
	```bash
	sudo -u www-data php local/xlate/cli/mlang_migrate.php --execute
	```

**Safety notes:**
- Always back up your database before running with `--execute`.
- Review the dry-run report and samples to confirm expected replacements.
- The tool is designed to be safe and robust, but destructive changes cannot be undone without a backup.


See DEVELOPER.md for technical details, advanced options, and extension guidance.

## FAQ / Troubleshooting

**Q: Is it safe to run the migration or scheduled task?**
A: Always run a dry-run first and back up your database before executing real changes. The tool is robust, but destructive changes cannot be undone without a backup.

**Q: How do I check if the scheduled task ran?**
A: Check the Moodle scheduled tasks UI or run the task manually via CLI (see above).

**Q: What if I see DB errors after migration?**
A: Review the dry-run report, check for unserialization or DB update errors, and consult DEVELOPER.md for defensive coding and rollback strategies.

**Q: How do I contribute or report issues?**
A: See CONTRIBUTING.md or file issues/PRs on GitHub.

Notes and safety:
- The migration records provenance in table `local_xlate_mlang_migration` (created during plugin upgrade). Each row includes `old_value`, `new_value`, `migrated_at`, and `migrated_by`.
- The CLI runner accepts `--preferred` to choose which language variant to keep (default `other`). Use `--preferred=sitelang` to prefer your site's default language if available.
- Always take a DB backup before running `--execute` and run the migration during a maintenance window.
- The tool scans only text-like columns; it avoids binary/blob columns by default. Use `--tables` to pass a JSON file or comma-separated `table:column` pairs to target specific fields.

## Automated MLang cleanup (scheduled task)

A scheduled task (`Scheduled MLang cleanup (legacy multilang tags)`) runs automatically (default: nightly) to detect and remove legacy `{mlang ...}` and `<span class="multilang">` tags from new or imported content. No manual intervention is needed for ongoing hygiene.

- You can run the task manually with:
  ```bash
  sudo -u www-data php admin/cli/scheduled_task.php --execute='\\local_xlate\\task\\mlang_cleanup_task'
  ```
- See the admin scheduled tasks UI to adjust frequency or check last run status.

## Configuration
1. Open **Site administration → Plugins → Local plugins → Xlate**.
2. Ensure *Enable Xlate* is checked. When disabled no CSS or scripts are
	 injected and bundles are never requested.
3. (Optional) Toggle *Auto-detect strings*. Auto-detection only runs while the
	 current page language equals the site’s default language and the viewer has
	 the `local/xlate:manage` capability.
4. Select which installed languages should have bundles generated.
5. Adjust component mappings if you need to force specific component prefixes
	 for captured keys.
6. Use the **Manage Translations** button to review captured keys and provide
	translations.
6b. Use the **Xlate: Manage Glossary** button (under the same Local plugins → Xlate area) to add or edit glossary entries that influence automated and manual translations.
6c. Configure each course’s Xlate custom fields (source select + target checkboxes). When a course lacks a source language, the translator bootstrap, CLI scripts, and scheduled tasks automatically skip it.

7. Autotranslation (OpenAI)
	 - The plugin includes an admin area to configure an OpenAI-compatible endpoint for optional autotranslation suggestions. Settings are under **Site administration → Plugins → Local plugins → Xlate**. Options include:
		 - Enable Autotranslation (master toggle)
		 - API endpoint (use a proxy or self-hosted endpoint if required)
		 - API key (stored masked)
		 - Model (default: gpt-5)
		 - System prompt (editable textarea) — the default prompt preserves HTML, placeholders and UI tone; you can tweak it to enforce style choices or glossary terms.

	 - Autotranslation is opt-in: enable the feature in settings before using AI suggestions from the Manage UI. See DEVELOPER.md for details about the system prompt and integration points.

## Capturing Strings
- Browse the site in the site’s default language (e.g. English) while logged in
	with the `local/xlate:manage` capability.
- With auto-detect enabled the translator captures user-facing strings,
	generates stable, structure-based 12-character keys, and stores them
	via the `local_xlate_save_key` web service. Keys are based on element structure, class, region, type, and text (see DEVELOPER.md for details).
- Inline HTML (links, emphasis, etc.) is preserved when capture runs so prompts
	sent to translators or the autotranslate backend always reflect the source DOM
	structure.
- For lightweight source collection the plugin also exposes `local_xlate_associate_keys`, which only requires the user to be logged in. This allows ordinary users browsing the site to populate source strings while keeping write access to translations restricted to site managers.

Role and capabilities
---------------------

There are two relevant capabilities:

- `local/xlate:manage` — site-level management (typically assigned to site managers). Grants full access to the Manage Translations UI and capture-related webservices.
- `local/xlate:managecourse` — course-level management (assignable per-course, typically to editing teachers). Grants access to the Manage Translations UI for a specific course (the UI can be opened at `/local/xlate/manage.php?courseid=<id>`).

The plugin adds a "Manage Translations" link to a course's More menu for users who have `local/xlate:managecourse` (or site managers who have `local/xlate:manage`).
- Dynamic content (drawer menus, modals, lazy-loaded blocks, etc.) is processed
	automatically; the MutationObserver re-runs detection as nodes are added.
- Mark markup you never want translated with `data-xlate-ignore`.

Notes about glossary and ordering
---------------------------------
- The Glossary (`/local/xlate/glossary.php`) is now available for site managers and provides bulk-add and paginated listing. Sources are grouped and listed alphabetically (case-insensitive) to make browsing easier.
- Database compatibility: glossary lookups and comparisons use Moodle's `$DB->sql_compare_text()` helper to avoid TEXT-comparison errors on some DB backends (for example PostgreSQL).

## Verifying the Plugin
- View page source: you should see the `html.xlate-loading` CSS in `<head>` and
	an inline bootloader near the top of `<body>`.
- Inspect `/local/xlate/bundle.php?lang=en` to confirm bundles return JSON
	(empty object when no keys exist for the language). You can POST a list of keys to this endpoint for page-specific bundles.
- In the browser console check `window.__XLATE__` to see the active language,
	site default language, and in-memory translation map.

## CLI Utilities

- **List translatable courses**: `cli/list_translatable_courses.php` prints every course that has both a source language and at least one target selected, making it easy to spot classes that still need configuration.
- **Scheduled autotranslation dry run**: `cli/autotranslate_dryrun.php` mirrors the scheduled task logic, showing each course’s source language, target set, and count of missing keys (add `--showmissing` for samples, `--courseid=<id>` or `--limit=<n>` to narrow the output).
- **Repair course custom fields**: `cli/sync_source_language_indices.php` realigns stored select indices with the current option order, and `cli/recreate_customfields.php` drops/rebuilds the entire Xlate category if you need a clean slate.
- **Queue and inspect course jobs**: `cli/queue_course_job.php` seeds `local_xlate_course_job` + its adhoc task for a specific course. Pair it with `cli/inspect_job.php`, `cli/show_new_translations.php`, `cli/list_adhoc.php`, or `cli/run_adhoc_process.php` to debug queued work.
- **Reset captured data safely**: `cli/truncate_xlate_tables.php` accepts `--dry-run` to preview which `local_xlate_*` tables would be truncated before wiping data in a dev/test environment.

## Scheduled Autotranslation (new)

This plugin now includes a scheduled task to automatically generate translations for all missing keys and enabled languages using the autotranslation backend (e.g., OpenAI). The task runs in batches to avoid overloading the database or API, and will never overwrite existing translations.

- Enable or disable the task in the plugin settings ("Enable scheduled autotranslation").
- The task runs nightly by default, but can be triggered manually:
	```bash
	sudo -u www-data php admin/cli/scheduled_task.php --execute='\\local_xlate\\task\\autotranslate_missing_task'
	```
- Progress and errors are logged to the scheduled task log.
- Every execution resolves each course’s configured source language and prunes target languages to those explicitly selected (or inferred from the enabled-language list minus the source). Courses with no source language are skipped automatically, matching the runtime gating behavior.
- Preview what would run without calling the backend using the CLI dry run:
	```bash
	sudo -u www-data php local/xlate/cli/autotranslate_dryrun.php --showmissing
	```
	Add `--courseid=<id>` to focus on a single course or `--limit=<n>` to reduce output.

## Token Usage Tracking

Batch translation calls record token usage in `local_xlate_token_batch`. You can view aggregate and recent usage in the admin UI at `/local/xlate/usage.php` (requires `local/xlate:manage`).


Need help? File issues or submit PRs on GitHub.
