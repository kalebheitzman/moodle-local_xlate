# Developer Reference – local_xlate

## 1. Purpose & Flow
`local_xlate` brings LocalizeJS-style client translations to Moodle 5+. The
plugin injects a bootloader, serves immutable JSON bundles, and runs an AMD
module that translates DOM nodes (and can capture new strings) without touching
core or theme code.

```
DB (local_xlate_key + local_xlate_tr)
  → local_xlate\local\api::get_bundle()
  → /local/xlate/bundle.php
  → browser localStorage (xlate:<lang>:<version>)
  → AMD translator (translation + optional capture)
  → local_xlate_save_key web service (capture only)
```

## 2. Architecture Overview
- **Hooks** (`classes/hooks/output.php`) inject anti-FOUT CSS and a bootloader
  via Moodle’s hook system (`db/hooks.php`). Hooks are skipped on admin pages to
  preserve core behaviour.
- **Bundle endpoint** (`bundle.php`) wraps `local_xlate\local\api`, returning
  language bundles with `Cache-Control: public, max-age=31536000, immutable`.
- **Client runtime** (`amd/src/translator.js`) applies translations, stores state
  on `window.__XLATE__`, observes DOM mutations, and can capture untranslated
  text when enabled.
- **Server APIs** (`classes/local/api.php`, `classes/external.php`) provide CRUD,
  cache invalidation, and web services consumed by the AMD module and admin UI.

## 3. Automatic Capture (AMD Module)
- Guard rails:
  - `currentLang === siteLang` (prevents recording already-translated strings).
  - Capability check happens via `local_xlate_save_key`
    (`local/xlate:manage`).
  - Runtime flag (`translator.setAutoDetect()`) lets us toggle capture; TODO to
    wire this to the `autodetect` config.
- Captured text includes text nodes plus `placeholder`, `title`, and `alt`
  attributes. Stable keys combine detected component, element type, normalized
  text, and a short hash.
- Successful capture updates Moodle DB, bumps the bundle version, and invalidates
  caches so subsequent requests fetch fresh bundles.

## 4. Bundle & Cache Layer (`classes/local/api.php`)
| Method | Purpose |
| ------ | ------- |
| `get_bundle($lang)` | Returns `{key => text}`. Cached in Moodle application cache (1-hour TTL). |
| `get_version($lang)` | Reads `local_xlate_bundle.version` (`'dev'` fallback). |
| `generate_version_hash($lang)` | `sha1("<lang>:<max mtime>")`; used for cache busting. |
| `update_bundle_version($lang)` | Stores new hash + timestamp. |
| `invalidate_bundle_cache($lang)` | Clears the cached bundle for the language. |
| `save_key_with_translation(...)` | Transactional helper for capture/admin UI. |
| `get_keys_paginated(...)` | Supports `manage.php` search and pagination. |

`bundle.php` still serves the full bundle even though `get_page_bundle()` can
filter by component; that behaviour is disabled until requirements are finalised.

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
  `local/xlate:manage`).

## 7. Admin Interface (`manage.php`)
- Displays automatically captured keys with search, filters, pagination, and
  per-language translation inputs.
- Manual key creation/deletion is disabled; rely on the AMD auto-detect flow to
  capture new strings.
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

## 12. TODOs & Future Enhancements
- Wire `autodetect` setting into `translator.setAutoDetect()` for runtime
  control without editing JS.
- Revisit component filtering in `api::get_page_bundle()` once page-level
  bundles are needed.
- Extend translation records with workflow metadata (reviewer, confidence, etc.)
  if the requirements evolve.

Happy hacking!
