## 1a. Structural Key Generation (Algorithm & Rationale)

local_xlate uses **stable, structure-based translation keys** to ensure translations persist even when source text changes slightly (typos, punctuation, minor edits). Keys are always 12-character base36 hashes, generated from a composite string representing the element's structure and content.

### Key Generation Algorithm

For each translatable element, the key is built from:
1. **Tag name** (e.g., `h2`, `p`, `div`, `button`)
2. **Class names** (from element and parent, filtered for dynamic/utility classes)
3. **Region** (`data-region` attribute if present)
4. **Type** (e.g., `placeholder`, `title`, `alt`, `aria-label` — omitted for text content)
5. **Text content** (the actual translatable string)

These are joined with periods to form a composite string:
```
tagname.class1,class2.region.type.text-content
```

#### Example
```html
<div class="course-header main-content" data-region="course-info">
  <h2 class="title">Welcome to the Course</h2>
</div>
```
Composite: `h2.title,course-header,main-content.course-info.Welcome to the Course`

#### Encoding
1. Hash the composite string using a deterministic hash (FNV-1a + mix)
2. Convert to base36 (0-9, a-z)
3. Pad or truncate to 12 characters

**Result:** 12-character key like `a1b2c3d4e5f6`

#### Benefits

- **Resilient to text changes:** Minor edits/typos do not break translation mapping
- **Context-aware:** Same text in different locations gets different keys
- **Compact & visible:** 12 chars, always injected as `data-xlate-key-*` for debugging
- **Deterministic:** Same structure always generates same key

#### DOM Injection

The `data-xlate-key-*` attribute is always injected into elements, both during auto-detection (capture mode) and translation. Example:
```html
<h2 data-xlate-key-content="a1b2c3d4e5f6">Willkommen im Kurs</h2>
<input data-xlate-key-placeholder="x9y8z7w6v5u4" placeholder="Suchen...">
<img data-xlate-key-alt="m5n4o3p2q1r0" alt="Course banner">
```

#### Testing

1. Purge Moodle caches: `php admin/cli/purge_caches.php`
2. View a Moodle page in the default language
3. Inspect elements in browser DevTools for `data-xlate-key-*` attributes
4. Change source text slightly and refresh — key should remain the same
5. Check `local_xlate_key` table for 12-char keys

#### Next Steps
- Monitor for key collisions (very unlikely)
- Add collision detection/resolution if needed
- Update admin UI to display structural context when viewing keys
- Consider exposing the composite string in debug mode for troubleshooting
# Developer Reference – local_xlate

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
  - Runtime flag (`translator.setAutoDetect()`) lets us toggle capture; TODO to
    wire this to the `autodetect` config.
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
  `local/xlate:manage`).

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
- Enable page-level bundle filtering in `api::get_page_bundle()` for further optimization.
- Extend translation records with workflow metadata (reviewer, confidence, etc.)
  if the requirements evolve.
- Add collision detection/resolution for structural keys if needed.
- Expose composite string for each key in admin UI/debug mode.

Happy hacking!
