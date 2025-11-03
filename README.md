# local_xlate

Client-side translation plugin for Moodle 5+ inspired by LocalizeJS. It injects
versioned translation bundles during page rendering, prevents flash-of-untranslated-text (FOUT), and translates the DOM in real time – including dynamically injected content. Keys are always structure-based, 12-character hashes, and capture is disabled in edit mode.

## Highlights
- **FOUT-free bootloader**: CSS gate plus inline loader fetch a versioned bundle
	and hydrate from `localStorage` when possible.
- **Automatic DOM translation**: Elements marked with `data-xlate` attributes –
	or captured automatically – are translated on load, on MutationObserver
	events, and after user interactions that add new DOM nodes. The translator uses a MutationObserver to handle dynamic content.
- **Optional auto-capture**: When browsing in the site’s default language with
	the `local/xlate:manage` capability, the plugin records new strings (text,
	placeholders, titles, alt text, and aria-label) through the Moodle web service API. **Capture is always disabled in edit mode** (see browser console for `[XLATE] Edit mode detected...`).
- **Caching stack**: Moodle application cache → browser `localStorage` → long-lived HTTP responses for efficient repeat visits. The bundle endpoint supports POST requests with a list of keys for efficient, page-specific translation.
- **Translation management UI**: Admins can review automatically captured keys,
- **Translation management UI**: Admins can review automatically captured keys,
  enter translations, and rebuild bundles from `Site administration → Plugins →
  Local plugins → Xlate`. Use the **Xlate: Manage Translations** page to manage captured keys and the **Xlate: Manage Glossary** page to maintain the language glossary.

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

Role and capabilities
---------------------

There are two relevant capabilities:

- `local/xlate:manage` — site-level management (typically assigned to site managers). Grants full access to the Manage Translations UI and capture-related webservices.
- `local/xlate:managecourse` — course-level management (assignable per-course, typically to editing teachers). Grants access to the Manage Translations UI for a specific course (the UI can be opened at `/local/xlate/manage.php?courseid=<id>`).

The plugin adds a "Manage Translations" link to a course's More menu for users who have `local/xlate:managecourse` (or site managers who have `local/xlate:manage`).
- Dynamic content (drawer menus, modals, lazy-loaded blocks, etc.) is processed
	automatically; the MutationObserver re-runs detection as nodes are added.
- Mark markup you never want translated with `data-xlate-ignore`.

## Using Translations Manually
```html
<h2 data-xlate-key="Heading.DashboardTitle.ABC12345"></h2>
<input data-xlate-key-placeholder="Input.SearchPlaceholder.ABC12345" placeholder="">
<div data-xlate-ignore>Do not translate this subtree</div>
```

- Preferred attributes: `data-xlate-key`, `data-xlate-key-placeholder`, `data-xlate-key-title`, `data-xlate-key-alt`, `data-xlate-key-aria-label`.
- Legacy support remains for `data-xlate*` attributes; the translator keeps both
	in sync for backward compatibility.
- When a translation bundle contains a matching key, the translator replaces
	text content or attributes immediately.

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

Need help? File issues or submit PRs on GitHub.
